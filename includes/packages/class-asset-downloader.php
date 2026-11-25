<?php
/** Bounded public release download with explicit redirect policy. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\GitHub\Api_Client;
use TenT\GitHubUpdater\GitHub\Release_Asset;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns a temporary file for one synchronous consumer, never a reusable approval. */
final class Asset_Downloader {
	public const MAX_BYTES     = 52428800;
	public const MAX_REDIRECTS = 3;
	public const HOP_TIMEOUT   = 20;
	public const TOTAL_TIMEOUT = 60;

	/**
	 * Download selected metadata, pass a temporary path to a consumer, then delete.
	 * Caller owns current registration/eligibility, capability and nonce checks.
	 * The callback must validate the same bytes, never retain the path or treat
	 * download success as package approval. Exceptions propagate after cleanup.
	 * Signed redirect URLs and raw transport errors are never returned.
	 *
	 * @param Release_Asset $asset   Validated uploaded asset pointer.
	 * @param callable      $consume Synchronous consumer accepting one local path.
	 * @return mixed Callback result, or WP_Error on download/cleanup failure.
	 */
	public function with_file( Release_Asset $asset, callable $consume ): mixed {
		if ( $asset->size > self::MAX_BYTES || $asset->size < 1 || ! preg_match( '~\Ahttps://api\.github\.com/repos/[A-Za-z0-9-]+/[A-Za-z0-9_.-]+/releases/assets/' . $asset->id . '\z~', $asset->api_url ) ) {
			return $this->error( 'metadata', __( 'The selected package exceeds the download limit or has an unsupported asset endpoint.', 'wordpress-github-updater' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( '10t-gu-package' );
		if ( ! is_string( $path ) || '' === $path ) {
			return $this->error( 'temporary_file', __( 'A package temporary file could not be created.', 'wordpress-github-updater' ) );
		}
		try {
			if ( is_link( $path ) || ! is_file( $path ) || ! @chmod( $path, 0600 ) ) {
				$result = $this->error( 'temporary_file', __( 'The package temporary file could not be secured.', 'wordpress-github-updater' ) );
			} else {
				$result = $this->transfer( $asset, $path );
				if ( true === $result ) {
					$result = $consume( $path );
				}
			}
		} finally {
			wp_delete_file( $path );
			clearstatcache( true, $path );
		}
		if ( file_exists( $path ) || is_link( $path ) ) {
			return $this->error( 'cleanup', __( 'The package temporary file could not be removed. Ask the site administrator to review temporary storage.', 'wordpress-github-updater' ) );
		}
		return $result;
	}

	/** @return true|WP_Error Stream every hop separately; never follow HTTP redirects implicitly. */
	private function transfer( Release_Asset $asset, string $path ): true|WP_Error {
		$url      = $asset->api_url;
		$seen     = array();
		$cdn_path = null;
		$deadline = ( hrtime( true ) / 1000000000 ) + self::TOTAL_TIMEOUT;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop ) {
			$remaining = $deadline - ( hrtime( true ) / 1000000000 );
			if ( $remaining <= 0 ) {
				return $this->error( 'timeout', __( 'The package download exceeded its time budget.', 'wordpress-github-updater' ) );
			}
			if ( isset( $seen[ $url ] ) || ! $this->allowed( $url, $asset, $cdn_path ) ) {
				return $this->error( 'redirect', __( 'The package download redirected to an unsupported or repeated endpoint.', 'wordpress-github-updater' ) );
			}
			$seen[ $url ] = true;
			// Remove any previous redirect body; never append multiple responses.
			if ( false === file_put_contents( $path, '' ) ) {
				return $this->error( 'temporary_file', __( 'The package temporary file could not be prepared.', 'wordpress-github-updater' ) );
			}
			$headers = array( 'Accept' => 'application/octet-stream', 'Accept-Encoding' => 'identity' );
			if ( $url === $asset->api_url ) {
				$headers['X-GitHub-Api-Version'] = Api_Client::API_VERSION;
			}
			$response = wp_safe_remote_get( $url, array(
				'timeout' => min( self::HOP_TIMEOUT, $remaining ), 'redirection' => 0,
				'sslverify' => true, 'stream' => true, 'filename' => $path,
				'limit_response_size' => $asset->size + 1, 'decompress' => false,
				'user-agent' => '10T-GitHub-Updater', 'headers' => $headers, 'cookies' => array(),
			) );
			if ( $response instanceof WP_Error ) {
				return $this->error( 'request', __( 'The package download request failed. Check connectivity and try again later.', 'wordpress-github-updater' ) );
			}
			if ( ( hrtime( true ) / 1000000000 ) > $deadline ) {
				return $this->error( 'timeout', __( 'The package download exceeded its time budget.', 'wordpress-github-updater' ) );
			}
			if ( ! is_array( $response ) ) {
				return $this->error( 'response', __( 'The package server returned an invalid response.', 'wordpress-github-updater' ) );
			}
			clearstatcache( true, $path );
			$size = is_link( $path ) || ! is_file( $path ) ? false : filesize( $path );
			if ( false === $size || $size > $asset->size || $size > self::MAX_BYTES ) {
				return $this->error( 'size', __( 'The package download exceeded its declared size or supported limit.', 'wordpress-github-updater' ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( ! is_int( $status ) && ! ( is_string( $status ) && preg_match( '/\A[0-9]{3}\z/', $status ) ) ) {
				return $this->error( 'response', __( 'The package server returned an invalid response.', 'wordpress-github-updater' ) );
			}
			$status = (int) $status;
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$next = wp_remote_retrieve_header( $response, 'location' );
				if ( $hop === self::MAX_REDIRECTS || ! is_string( $next ) || ! $this->allowed( $next, $asset, $cdn_path ) ) {
					return $this->error( 'redirect', __( 'The package redirect chain exceeds the supported endpoint policy or hop limit.', 'wordpress-github-updater' ) );
				}
				$url = $next;
				continue;
			}
			if ( 200 !== $status ) {
				return $this->error( 'http', __( 'GitHub did not return a complete package download. Try again later or review the release.', 'wordpress-github-updater' ) );
			}
			$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );
			$length   = wp_remote_retrieve_header( $response, 'content-length' );
			if ( ! is_string( $encoding ) || ! in_array( strtolower( trim( $encoding ) ), array( '', 'identity' ), true ) || ( '' !== $length && ( ! is_string( $length ) || ! preg_match( '/\A[0-9]{1,10}\z/', $length ) || (int) $length !== $size ) ) ) {
				return $this->error( 'response', __( 'The package response encoding or length is inconsistent.', 'wordpress-github-updater' ) );
			}
			if ( $size !== $asset->size ) {
				return $this->error( 'size', __( 'The package byte count no longer matches the selected release asset. Refresh release information before trying again.', 'wordpress-github-updater' ) );
			}
			return true;
		}
		return $this->error( 'redirect', __( 'The package redirect chain exceeded its hop limit.', 'wordpress-github-updater' ) );
	}

	/**
	 * HTTPS literal hosts only. CDN location is trusted solely as GitHub's redirect;
	 * pin its first raw host/path for subsequent hops, preserving signed queries.
	 * No credentials/ports/fragments/relative URLs, percent paths or backslashes.
	 */
	private function allowed( string $url, Release_Asset $asset, ?string &$cdn_path ): bool {
		if ( strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f-\xff\\\\#]/', $url ) ) {
			return false;
		}
		if ( null === $cdn_path && ( $url === $asset->api_url || $url === $asset->download_url ) ) {
			return true;
		}
		if ( ! preg_match( '~\A(https://(?:release-assets\.githubusercontent\.com/github-production-release-asset|objects\.githubusercontent\.com/github-production-release-asset(?:-2e65be)?)/[0-9]+/[A-Za-z0-9-]+)(?:\?[^#]+)?\z~', $url, $match ) ) {
			return false;
		}
		if ( null !== $cdn_path && $cdn_path !== $match[1] ) {
			return false;
		}
		$cdn_path = $match[1];
		return true;
	}

	/** @param string $code Fixed suffix. @param string $message Fixed translated text. @return WP_Error */
	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( 'github_updater_download_' . $code, $message );
	}
}
