<?php
/**
 * Network-aware complete-release metadata cache and explicit refresh controls.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache public metadata, never installed state or package-validation results.
 */
final class Cached_Releases {
	public const SCHEMA_VERSION = 1;
	public const SUCCESS_TTL    = 43200;
	public const FAILURE_TTL    = 300;
	private const MAX_BACKOFF   = 86400;

	/** @var array<string, bool> Guard nested refresh/invalidation calls. */
	private static array $active = array();

	/** @param Stable_Releases $reader Complete uncached retrieval. */
	public function __construct( private readonly Stable_Releases $reader = new Stable_Releases() ) {}

	/**
	 * Get revalidated metadata, or bypass both success and failure cache explicitly.
	 *
	 * A forced refresh never returns stale data after a failed request. Callers
	 * exposing force/invalidate through HTTP must enforce capabilities and nonces.
	 *
	 * @param Repository $repository Canonical repository identity.
	 * @param bool       $force      Explicit manual refresh, not automatic retry.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get( Repository $repository, bool $force = false ): array|WP_Error {
		$key = $this->key( $repository );
		if ( ! $force ) {
			$cached = $this->read( $key, $repository );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		return $this->locked(
			$key,
			function () use ( $key, $repository, $force ) {
				// Another request may have finished while this one was acquiring.
				$this->refresh_local_cache( $key );
				if ( ! $force ) {
					$cached = $this->read( $key, $repository );
					if ( null !== $cached ) {
						return $cached;
					}
				}
				$cleared = $this->clear( $key );
				if ( $cleared instanceof WP_Error ) {
					return $cleared;
				}
				$result = $this->reader->get( $repository );
				$record = array(
					'schema_version' => self::SCHEMA_VERSION,
					'repository_url' => $repository->url,
					'created_at'     => time(),
				);
				$ttl = self::SUCCESS_TTL;
				if ( $result instanceof WP_Error ) {
					$data = $result->get_error_data();
					$safe = array();
					foreach ( array( 'http_status', 'retry_after', 'transport_code' ) as $field ) {
						if ( is_array( $data ) && isset( $data[ $field ] ) && ( is_int( $data[ $field ] ) || is_string( $data[ $field ] ) ) ) {
							$safe[ $field ] = $data[ $field ];
						}
					}
					$record['kind']  = 'error';
					$record['error'] = array( 'code' => (string) $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $safe );
					$ttl             = $this->failure_ttl( $safe );
				} else {
					$record['kind']     = 'success';
					$record['releases'] = $result;
				}
				$record['expires_at'] = $record['created_at'] + $ttl;
				// A failed cache write must not turn a successful fetch into a failure.
				set_site_transient( $key, $record, $ttl );
				return $result;
			}
		);
	}

	/**
	 * Remove only this repository's success/error entry in the current scope.
	 *
	 * Uses the refresh lock so an in-flight fetch cannot repopulate after success.
	 *
	 * @param Repository $repository Canonical identity.
	 * @return true|WP_Error
	 */
	public function invalidate( Repository $repository ): true|WP_Error {
		$key = $this->key( $repository );
		return $this->locked(
			$key,
			function () use ( $key ) {
				$this->refresh_local_cache( $key );
				return $this->clear( $key );
			}
		);
	}

	/** @param Repository $repository Identity. @return string */
	private function key( Repository $repository ): string {
		// site-transient is a global object-cache group: include network explicitly.
		$scope = is_multisite() ? 'network:' . get_current_network_id() : 'site:' . get_current_blog_id();
		return '10t_gu_releases_v' . self::SCHEMA_VERSION . '_' . hash( 'sha256', $scope . ':' . Api_Client::API_VERSION . ':' . Stable_Releases::MAX_PAGES . ':' . Stable_Releases::PER_PAGE . ':' . $repository->url );
	}

	/** @param string $key Cache key. @param Repository $repository Identity. @return array<mixed>|WP_Error|null */
	private function read( string $key, Repository $repository ): array|WP_Error|null {
		$record = get_site_transient( $key );
		if (
			! is_array( $record ) || ( $record['schema_version'] ?? null ) !== self::SCHEMA_VERSION
			|| ( $record['repository_url'] ?? null ) !== $repository->url
			|| ! isset( $record['created_at'], $record['expires_at'] )
			|| ! is_int( $record['created_at'] ) || ! is_int( $record['expires_at'] )
			|| $record['created_at'] > time() || $record['expires_at'] <= time()
		) {
			return null;
		}
		$duration = $record['expires_at'] - $record['created_at'];
		if ( 'success' === ( $record['kind'] ?? null ) && self::SUCCESS_TTL === $duration && isset( $record['releases'] ) && is_array( $record['releases'] ) ) {
			$validated = Stable_Releases::validate_candidates( $record['releases'] );
			return $validated instanceof WP_Error ? null : $validated;
		}
		$error = $record['error'] ?? null;
		if (
			'error' === ( $record['kind'] ?? null ) && is_array( $error )
			&& isset( $error['code'], $error['message'], $error['data'] )
			&& is_string( $error['code'] ) && '' !== $error['code'] && is_string( $error['message'] ) && is_array( $error['data'] )
			&& $duration === $this->failure_ttl( $error['data'] )
		) {
			return new WP_Error( $error['code'], $error['message'], $error['data'] );
		}
		return null;
	}

	/** @param array<mixed> $data Safe error hints. @return int */
	private function failure_ttl( array $data ): int {
		$retry = $data['retry_after'] ?? 0;
		return max( self::FAILURE_TTL, is_int( $retry ) ? min( max( 0, $retry ), self::MAX_BACKOFF ) : 0 );
	}

	/** @param string $key Cache key. @return true|WP_Error */
	private function clear( string $key ): true|WP_Error {
		delete_site_transient( $key );
		return false === get_site_transient( $key )
			? true
			: new WP_Error( 'github_updater_release_cache_clear_failed', __( 'The GitHub release cache could not be cleared. Please try again later.', 'wordpress-github-updater' ) );
	}

	/** @param string $key Cache key. Refresh local snapshots after lock acquisition. */
	private function refresh_local_cache( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_get( $key, 'site-transient', true );
			return;
		}
		foreach ( array( '_site_transient_' . $key, '_site_transient_timeout_' . $key ) as $option ) {
			if ( is_multisite() ) {
				wp_cache_delete( get_current_network_id() . ':' . $option, 'site-options' );
			} else {
				wp_cache_delete( $option, 'options' );
			}
		}
		wp_cache_delete( is_multisite() ? get_current_network_id() . ':notoptions' : 'notoptions', is_multisite() ? 'site-options' : 'options' );
		if ( ! is_multisite() ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/** @param string $key Cache key. @param callable $operation Coordinated work. @return mixed */
	private function locked( string $key, callable $operation ): mixed {
		global $wpdb;
		$table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$name  = '10t-gu-cache:' . substr( hash( 'sha256', DB_NAME . ':' . $table . ':' . $key ), 0, 50 );
		if ( isset( self::$active[ $name ] ) ) {
			return $this->busy();
		}
		$acquired = $this->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $acquired ) {
			return '0' === (string) $acquired ? $this->busy() : new WP_Error( 'github_updater_release_cache_lock_unavailable', __( 'The database cannot coordinate a GitHub release refresh. Please try again later.', 'wordpress-github-updater' ) );
		}
		self::$active[ $name ] = true;
		try {
			$result = $operation();
		} finally {
			try {
				$released = $this->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			} finally {
				unset( self::$active[ $name ] );
			}
		}
		if ( '1' !== (string) $released ) {
			return new WP_Error( 'github_updater_release_cache_lock_release_failed', __( 'The release cache lock could not be released normally. The cache may have changed; check again later.', 'wordpress-github-updater' ) );
		}
		return $result;
	}

	/** @param string $sql Prepared lock query. @return mixed */
	private function query( string $sql ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $wpdb->get_var( $sql );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** @return WP_Error */
	private function busy(): WP_Error {
		return new WP_Error( 'github_updater_release_refresh_busy', __( 'A GitHub release refresh is already in progress. Please try again shortly.', 'wordpress-github-updater' ) );
	}
}
