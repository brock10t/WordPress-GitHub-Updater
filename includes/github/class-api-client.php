<?php
/**
 * Bounded public GitHub REST requests through the WordPress HTTP API.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use JsonException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves metadata only; does not select releases, download ZIPs or cache.
 */
final class Api_Client {
	public const API_VERSION        = '2022-11-28';
	public const MAX_RESPONSE_BYTES = 1048576;
	public const REQUEST_TIMEOUT    = 10;

	/**
	 * Retrieve a matching public repository's metadata.
	 *
	 * @param Repository $repository Validated repository identity.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_repository( Repository $repository ): array|WP_Error {
		$data = $this->request( $repository, '', false );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( ! isset( $data['full_name'], $data['private'] ) || ! is_string( $data['full_name'] ) || ! is_bool( $data['private'] ) ) {
			return $this->invalid_response();
		}
		if ( $data['private'] || ( isset( $data['visibility'] ) && 'public' !== $data['visibility'] ) ) {
			return new WP_Error( 'github_updater_repository_not_public', __( 'Only public GitHub repositories are supported.', 'wordpress-github-updater' ) );
		}
		if ( strtolower( $data['full_name'] ) !== $repository->owner . '/' . $repository->name ) {
			return new WP_Error( 'github_updater_repository_identity_mismatch', __( 'GitHub returned a different repository identity. Verify the registered repository URL.', 'wordpress-github-updater' ) );
		}

		return $data;
	}

	/**
	 * Retrieve one raw releases page; entries remain untrusted external metadata.
	 *
	 * No latest-release assumptions, eligibility filtering or pagination loop.
	 *
	 * @param Repository $repository Validated repository identity.
	 * @param int        $page       Positive page number.
	 * @param int        $per_page   Entries requested, from 1 to 100.
	 * @return array<int, mixed>|WP_Error
	 */
	public function get_releases_page( Repository $repository, int $page = 1, int $per_page = 100 ): array|WP_Error {
		if ( $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return new WP_Error( 'github_updater_invalid_api_pagination', __( 'GitHub release requests require a positive page number and between 1 and 100 entries per page.', 'wordpress-github-updater' ) );
		}

		return $this->request( $repository, '/releases?per_page=' . $per_page . '&page=' . $page, true );
	}

	/**
	 * Request only internally constructed endpoints on the fixed GitHub API host.
	 *
	 * @param Repository $repository Validated identity.
	 * @param string     $suffix     Internal endpoint suffix, never user input.
	 * @param bool       $list       Whether a JSON list is expected.
	 * @return array<mixed>|WP_Error
	 */
	private function request( Repository $repository, string $suffix, bool $list ): array|WP_Error {
		$url      = 'https://api.github.com/repos/' . rawurlencode( $repository->owner ) . '/' . rawurlencode( $repository->name ) . $suffix;
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::REQUEST_TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
				'user-agent'          => '10T-GitHub-Updater',
				'headers'             => array(
					'Accept'               => 'application/vnd.github+json',
					'Accept-Encoding'      => 'identity',
					'X-GitHub-Api-Version' => self::API_VERSION,
				),
			)
		);
		if ( $response instanceof WP_Error ) {
			return new WP_Error( 'github_updater_api_request_failed', __( 'The GitHub API request failed. Check connectivity and try again later.', 'wordpress-github-updater' ), array( 'transport_code' => $response->get_error_code() ) );
		}
		if ( ! is_array( $response ) ) {
			return $this->invalid_response();
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( ! is_int( $status ) && ! ( is_string( $status ) && preg_match( '/\A[0-9]{3}\z/', $status ) ) ) {
			return $this->invalid_response();
		}
		$status = (int) $status;
		if ( $status < 100 || $status > 599 ) {
			return $this->invalid_response();
		}
		if ( $status >= 300 && $status < 400 ) {
			return new WP_Error( 'github_updater_api_redirect_refused', __( 'GitHub redirected the metadata request. Review the repository URL; redirects are not followed automatically.', 'wordpress-github-updater' ), array( 'http_status' => $status ) );
		}
		if ( 200 !== $status ) {
			return $this->http_error( $response, $status );
		}

		$type = wp_remote_retrieve_header( $response, 'content-type' );
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || ! is_string( $type ) || ! preg_match( '/\Aapplication\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|\z)/i', $type ) ) {
			return $this->invalid_response();
		}
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error( 'github_updater_api_response_too_large', __( 'The GitHub metadata response exceeded the supported size limit.', 'wordpress-github-updater' ) );
		}
		try {
			$data = json_decode( $body, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			return $this->invalid_response();
		}
		$root = ltrim( $body )[0] ?? '';
		if ( ! is_array( $data ) || ( $list ? '[' : '{' ) !== $root ) {
			return $this->invalid_response();
		}

		return $data;
	}

	/**
	 * Provide basic transport errors; retry/backoff policy belongs to caching.
	 *
	 * @param array<mixed> $response WordPress HTTP response.
	 * @param int          $status   HTTP status.
	 * @return WP_Error
	 */
	private function http_error( array $response, int $status ): WP_Error {
		$data      = array( 'http_status' => $status );
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$retry     = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( 429 === $status || ( 403 === $status && ( ( is_scalar( $remaining ) && '0' === (string) $remaining ) || ( is_string( $retry ) && '' !== $retry ) ) ) ) {
			if ( is_string( $retry ) && preg_match( '/\A[0-9]{1,5}\z/', $retry ) ) {
				$data['retry_after'] = min( (int) $retry, 86400 );
			}
			return new WP_Error( 'github_updater_api_rate_limited', __( 'GitHub has limited API requests. Wait before checking again.', 'wordpress-github-updater' ), $data );
		}
		if ( 404 === $status ) {
			return new WP_Error( 'github_updater_api_not_found', __( 'GitHub could not find a publicly accessible resource at this repository URL.', 'wordpress-github-updater' ), $data );
		}

		return new WP_Error( 'github_updater_api_http_error', __( 'GitHub returned an unsuccessful response. Please try again later.', 'wordpress-github-updater' ), $data );
	}

	/** @return WP_Error Malformed-response guidance without untrusted body text. */
	private function invalid_response(): WP_Error {
		return new WP_Error( 'github_updater_api_invalid_response', __( 'GitHub returned invalid or unexpected metadata.', 'wordpress-github-updater' ) );
	}
}
