<?php
/** Cooperative physical-plugin-tree coordination. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Updates;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One primary DB server and stable connection required across participating writers. */
final class Upgrade_Lock {
	private static array $active = array();
	private ?string $name = null;

	/** @return mixed Zero-wait scope, always released on return/exception. */
	public function run( callable $operation ): mixed {
		global $wpdb;
		$root = realpath( WP_PLUGIN_DIR );
		if ( false === $root ) {
			return $this->error( 'unavailable' );
		}
		$name = '10t-gu-upgrade:' . substr( hash( 'sha256', $root ), 0, 48 );
		if ( null !== $this->name || isset( self::$active[ $name ] ) ) {
			return $this->error( 'busy' );
		}
		$got = $this->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $got ) {
			return $this->error( '0' === (string) $got ? 'busy' : 'unavailable' );
		}
		$this->name = $name;
		self::$active[ $name ] = true;
		try {
			$result = $this->is_owned() ? $operation() : $this->error( 'lost' );
			if ( ! $this->is_owned() ) {
				$result = $this->error( 'lost' );
			}
		} finally {
			try {
				$released = $this->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			} finally {
				$this->name = null;
				unset( self::$active[ $name ] );
			}
		}
		return '1' === (string) $released ? $result : $this->error( 'release' );
	}

	/** Fail closed after reconnect/failover or implicit release by another GET_LOCK. */
	public function is_owned(): bool {
		global $wpdb;
		return null !== $this->name && '1' === (string) $this->query( $wpdb->prepare( 'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), 1, 0)', $this->name ) );
	}
	private function query( string $sql ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $wpdb->get_var( $sql );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
	private function error( string $code ): WP_Error {
		return new WP_Error( 'github_updater_upgrade_lock_' . $code, __( 'The updater could not retain exclusive cooperative access to the plugin tree. Refresh before retrying; prior work may have completed.', 'wordpress-github-updater' ) );
	}
}
