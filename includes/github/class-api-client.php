<?php
/**
 * Bounded public GitHub REST requests through the WordPress HTTP API.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use DateTimeImmutable;
use DateTimeZone;
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
	public const MAX_RETRY_DELAY    = 86400;

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
			return $this->transport_error( $response );
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
	 * WordPress collapses Requests exceptions into http_request_failed.
	 * Recognize known timeout messages, but never expose transport text.
	 *
	 * @param WP_Error $error Local transport error.
	 * @return WP_Error
	 */
	private function transport_error( WP_Error $error ): WP_Error {
		$message = $error->get_error_message();
		$timeout = is_string( $message ) && preg_match( '/\b(?:cURL error 28|timed out|timeout)\b/i', $message );
		return new WP_Error(
			$timeout ? 'github_updater_api_timeout' : 'github_updater_api_request_failed',
			$timeout
				? __( 'The GitHub API request timed out. Try again later.', 'wordpress-github-updater' )
				: __( 'The GitHub API request failed. Check connectivity and try again later.', 'wordpress-github-updater' ),
			array( 'transport_code' => $error->get_error_code(), 'retryable' => true )
		);
	}

	/**
	 * Classify HTTP errors and bounded server retry guidance without retrying.
	 *
	 * @param array<mixed> $response WordPress HTTP response.
	 * @param int          $status   HTTP status.
	 * @return WP_Error
	 */
	private function http_error( array $response, int $status ): WP_Error {
		$now       = time();
		$retry     = $this->retry_delay( wp_remote_retrieve_header( $response, 'retry-after' ), $now );
		$remaining = $this->header_integer( wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) );
		$reset     = $this->header_integer( wp_remote_retrieve_header( $response, 'x-ratelimit-reset' ) );
		$limited   = 429 === $status || ( 403 === $status && ( 0 === $remaining || null !== $retry || $this->secondary_limit( $response ) ) );
		$data      = array( 'http_status' => $status, 'retryable' => $limited || $status >= 500 || 408 === $status );
		if ( $limited ) {
			$delay = max( 60, $retry ?? 0, 0 === $remaining && null !== $reset ? min( self::MAX_RETRY_DELAY, max( 0, $reset - $now ) ) : 0 );
			$data['retry_after'] = $delay;
			$data['retry_at']    = $now + $delay;
			return new WP_Error( 'github_updater_api_rate_limited', __( 'GitHub has limited API requests. Wait for the cooldown before checking again.', 'wordpress-github-updater' ), $data );
		}
		if ( null !== $retry && $retry > 0 && $status >= 500 ) {
			$data['retry_after'] = $retry;
			$data['retry_at']    = $now + $retry;
		}
		if ( 404 === $status ) {
			return new WP_Error( 'github_updater_api_not_found', __( 'GitHub could not find a publicly accessible resource at this repository URL.', 'wordpress-github-updater' ), $data );
		}
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'github_updater_api_access_denied', __( 'GitHub denied the public API request. Check the repository URL, public access and server policy.', 'wordpress-github-updater' ), $data );
		}
		if ( 408 === $status ) {
			return new WP_Error( 'github_updater_api_timeout', __( 'The GitHub API request timed out. Try again later.', 'wordpress-github-updater' ), $data );
		}
		if ( 410 === $status ) {
			return new WP_Error( 'github_updater_api_resource_gone', __( 'The GitHub resource or requested API version is no longer available. Review the repository and updater configuration.', 'wordpress-github-updater' ), $data );
		}
		if ( $status >= 500 ) {
			return new WP_Error( 'github_updater_api_unavailable', __( 'GitHub is temporarily unavailable. Try again later.', 'wordpress-github-updater' ), $data );
		}
		return new WP_Error( 'github_updater_api_http_error', __( 'GitHub rejected the metadata request. Review the repository configuration before trying again.', 'wordpress-github-updater' ), $data );
	}

	/**
	 * Parse only bounded integer header values, not arrays/floats/exponents.
	 *
	 * @param mixed $value Header value.
	 * @return int|null
	 */
	private function header_integer( mixed $value ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = trim( (string) $value, " \t" );
		if ( ! preg_match( '/\A[0-9]{1,10}\z/', $value ) ) {
			return null;
		}
		$value = ltrim( $value, '0' );
		$max   = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return null;
		}
		return (int) $value;
	}

	/**
	 * @param mixed $value Retry-After header.
	 * @param int   $now   Local UTC epoch, never the admin display timezone.
	 * @return int|null
	 */
	private function retry_delay( mixed $value, int $now ): ?int {
		$seconds = $this->header_integer( $value );
		if ( null !== $seconds ) {
			return min( $seconds, self::MAX_RETRY_DELAY );
		}
		if ( ! is_string( $value ) || ! preg_match( '/\A[A-Za-z]{3}, [0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/', $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'D, d M Y H:i:s \G\M\T' ) !== $value ) {
			return null;
		}
		return min( self::MAX_RETRY_DELAY, max( 0, $date->getTimestamp() - $now ) );
	}

	/**
	 * Recognize GitHub's known secondary-limit wording in a small JSON message.
	 * This message is classification input only, never returned to the UI.
	 *
	 * @param array<mixed> $response HTTP error response.
	 * @return bool
	 */
	private function secondary_limit( array $response ): bool {
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > 16384 ) {
			return false;
		}
		try {
			$data = json_decode( $body, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			return false;
		}
		return is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] )
			&& 1 === preg_match( '/\b(?:API rate limit exceeded|secondary rate limit|abuse detection)\b/i', $data['message'] );
	}

	/** @return WP_Error Malformed-response guidance without untrusted body text. */
	private function invalid_response(): WP_Error {
		return new WP_Error( 'github_updater_api_invalid_response', __( 'GitHub returned invalid or unexpected metadata.', 'wordpress-github-updater' ) );
	}
}
