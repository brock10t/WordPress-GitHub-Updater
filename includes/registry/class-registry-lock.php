<?php
/**
 * Cooperative registry mutation serialization on the WordPress database.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requires one stable MySQL/MariaDB connection to the primary database.
 */
final class Registry_Lock {
	/** @var array<string, bool> Prevent reentrant mutations on this connection. */
	private static array $active = array();

	/**
	 * Run one complete read/validate/write operation without waiting or retrying.
	 *
	 * @template T
	 * @param callable():T $operation Registry mutation.
	 * @return T|WP_Error
	 */
	public function run( callable $operation ): mixed {
		global $wpdb;

		$network_id = is_multisite() ? get_current_network_id() : 0;
		$table      = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$name       = '10t-gu:' . substr( hash( 'sha256', DB_NAME . ':' . $table . ':' . $network_id . ':' . Option_Storage::OPTION_NAME ), 0, 56 );
		if ( isset( self::$active[ $name ] ) ) {
			return $this->busy();
		}

		$acquired = $this->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $acquired ) {
			return '0' === (string) $acquired
				? $this->busy()
				: new WP_Error( 'github_updater_registry_lock_unavailable', __( 'The database cannot provide a GitHub registry write lock. No registry changes were made.', 'wordpress-github-updater' ) );
		}

		self::$active[ $name ] = true;
		try {
			// A snapshot cached before acquisition may predate another writer.
			if ( is_multisite() ) {
				wp_cache_delete( $network_id . ':' . Option_Storage::OPTION_NAME, 'site-options' );
				wp_cache_delete( $network_id . ':notoptions', 'site-options' );
			} else {
				wp_cache_delete( Option_Storage::OPTION_NAME, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
			$result = $operation();
		} finally {
			try {
				$released = $this->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			} finally {
				unset( self::$active[ $name ] );
			}
		}

		if ( '1' !== (string) $released ) {
			return new WP_Error( 'github_updater_registry_lock_release_failed', __( 'The registry write lock could not be released normally. The change may have been saved; refresh the registry before retrying.', 'wordpress-github-updater' ) );
		}

		return $result;
	}

	/**
	 * Avoid exposing database errors in administrative output.
	 *
	 * @param string $sql Prepared locking query.
	 * @return mixed
	 */
	private function query( string $sql ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $wpdb->get_var( $sql );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * @return WP_Error
	 */
	private function busy(): WP_Error {
		return new WP_Error( 'github_updater_registry_busy', __( 'Another GitHub registry change is in progress. Please try again shortly.', 'wordpress-github-updater' ) );
	}
}
