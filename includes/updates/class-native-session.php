<?php
/** Bound single-item native execution within validated ownership. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Updates;
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicit entry only; no public offer or automatic dispatcher is registered. */
final class Native_Session {
	private static ?self $active = null;
	private Registration $record;
	private string $zip;
	private string $token;
	private \Closure $recheck;
	private ?Session_Upgrader $upgrader = null;
	private ?string $working = null;
	private ?array $working_stat = null;
	private string $phase = 'closed';
	private array $backup = array();

	public function __construct( private readonly Validated_Handoff $handoff = new Validated_Handoff() ) {}
	public static function current(): ?self { return self::$active; }

	/**
	 * Caller owns authorization. All scopes span the fresh native item, backup
	 * completion and staging cleanup. Never call from an already-running upgrade.
	 * @return array|WP_Error Native install result; no full-site atomicity promise.
	 */
	public function run( Registration $record, Release_Candidate $candidate, ?\WP_Upgrader_Skin $skin = null ): array|WP_Error {
		if ( null !== self::$active ) {
			return $this->error( 'busy' );
		}
		if ( ! class_exists( '\Plugin_Upgrader' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; }
		// An outer Plugin_Upgrader::upgrade/bulk_upgrade has already installed
		// activation callbacks. Do not borrow that in-progress lifecycle.
		$hook = $GLOBALS['wp_filter']['upgrader_pre_install'] ?? null;
		foreach ( is_object( $hook ) ? ( $hook->callbacks ?? array() ) : array() as $callbacks ) {
			foreach ( $callbacks as $item ) {
				$callback = $item['function'] ?? null;
				if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof \Plugin_Upgrader && in_array( $callback[1] ?? '', array( 'deactivate_plugin_before_upgrade', 'active_before' ), true ) ) {
					return $this->error( 'nested' );
				}
			}
		}

		require_once __DIR__ . '/class-session-upgrader.php';
		return $this->handoff->run( $record, $candidate, function ( string $zip, \Closure $recheck, \Closure $ownership ) use ( $record, $skin ) {
			$this->record = $record;
			$this->zip = $zip;
			$this->recheck = $recheck;
			$this->token = bin2hex( random_bytes( 32 ) );
			$this->phase = 'prepared';
			$this->working = null;
			$this->working_stat = null;
			$this->backup = array( 'slug' => $record->plugin_slug, 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins' );
			$base = rtrim( WP_CONTENT_DIR, '/' );
			if ( realpath( $base ) !== $base || is_link( $base ) ) {
				return $this->error( 'destination' );
			}
			foreach ( array( $base . '/upgrade-temp-backup', $base . '/upgrade-temp-backup/plugins' ) as $parent ) {
				if ( is_link( $parent ) || ( file_exists( $parent ) && ( ! is_dir( $parent ) || realpath( $parent ) !== $parent ) ) ) {
					return $this->error( 'backup' );
				}
			}
			$backup_path = $base . '/upgrade-temp-backup/plugins/' . $record->plugin_slug;
			if ( file_exists( $backup_path ) || is_link( $backup_path ) ) {
				return $this->error( 'backup' );
			}
			$this->upgrader = new Session_Upgrader( $skin, $this );
			self::$active = $this;
			add_filter( 'upgrader_source_selection', array( $this, 'final_source' ), PHP_INT_MAX, 4 );
			$result = $this->error( 'incomplete' );
			try {
				$this->upgrader->init();
				$this->upgrader->upgrade_strings();
				$result = $this->upgrader->run( array(
					'package' => $zip, 'destination' => WP_PLUGIN_DIR, 'clear_destination' => true,
					'clear_working' => true, 'hook_extra' => array( 'type' => 'plugin', 'action' => 'update',
						'plugin' => $record->plugin_basename, 'temp_backup' => $this->backup, '_10t_session' => $this->token ),
				) );
				if ( ! is_array( $result ) || 'installed' !== $this->phase ) { $result = $result instanceof WP_Error ? $result : $this->error( 'incomplete' ); }
			} catch ( \Throwable $exception ) {
				$result = $this->error( 'exception' );
			} finally {
				try {
					if ( ! $ownership() ) {
						$result = $this->error( 'ownership_lost' );
					} elseif ( $result instanceof WP_Error ) {
						$restored = $this->upgrader->restore_temp_backup( array( $this->backup ) );
						if ( true !== $restored ) { $result = $this->error( 'rollback' ); }
					}
					// Never delete a backup after a failed restoration.
					if ( ! $result instanceof WP_Error || ! in_array( $result->get_error_code(), array( 'github_updater_session_rollback', 'github_updater_session_ownership_lost' ), true ) ) {
						$deleted = $this->upgrader->delete_temp_backup( array( $this->backup ) );
						if ( true !== $deleted ) { $result = $this->error( 'backup_cleanup' ); }
					}
				} catch ( \Throwable $exception ) { $result = $this->error( 'rollback' ); }
				finally {
					remove_action( 'shutdown', array( $this->upgrader, 'restore_temp_backup' ), 10 );
					remove_action( 'shutdown', array( $this->upgrader, 'delete_temp_backup' ), 100 );
					remove_filter( 'upgrader_source_selection', array( $this, 'final_source' ), PHP_INT_MAX );
					wp_clean_plugins_cache( false );
					$this->phase = 'closed';
					self::$active = null;
					if ( null !== $this->working ) {
						$count = 0;
						if ( ! $this->remove_tree( $this->working, 0, $count ) ) { $result = $this->error( 'staging_cleanup' ); }
					}
				}
			}
			return $result;
		} );
	}

	public function matches( mixed $extra ): bool {
		return self::$active === $this && is_array( $extra ) && ( $extra['_10t_session'] ?? null ) === $this->token
			&& ( $extra['plugin'] ?? null ) === $this->record->plugin_basename && ( $extra['type'] ?? null ) === 'plugin' && ( $extra['action'] ?? null ) === 'update';
	}
	public function download( mixed $reply, mixed $package, mixed $upgrader, mixed $extra ): mixed {
		if ( $upgrader !== $this->upgrader || ! $this->matches( $extra ) || false !== $reply || $package !== $this->zip || 'prepared' !== $this->phase ) {
			return $this->error( 'context' );
		}
		$valid = ( $this->recheck )();
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}
		$this->phase = 'downloaded'; return $this->zip;
	}
	public function pre_install( mixed $value, mixed $extra ): mixed {
		if ( ! $this->matches( $extra ) || 'staged' !== $this->phase || null === $this->working ) {
			return $this->error( 'context' );
		}
		$valid = ( $this->recheck )( $this->working );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}
		$this->phase = 'preinstalled'; return $value;
	}
	public function source( mixed $source, mixed $remote, mixed $upgrader, mixed $extra ): mixed {
		if ( $upgrader !== $this->upgrader || ! $this->matches( $extra ) || 'preinstalled' !== $this->phase || $remote !== $this->working || ! is_string( $source ) || rtrim( $source, '/' ) !== $this->working . '/' . $this->record->plugin_slug ) {
			return $this->error( 'context' );
		}
		$valid = ( $this->recheck )( $this->working );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}
		$this->phase = 'copy_ready'; return $source;
	}

	/** Recheck after pre-existing source filters, before native backup/deletion. */
	public function final_source( mixed $source, mixed $remote, mixed $upgrader, mixed $extra ): mixed {
		if ( $source instanceof WP_Error ) {
			return $source;
		}
		if ( ! $this->matches( $extra ) || $upgrader !== $this->upgrader || 'copy_ready' !== $this->phase || $remote !== $this->working || ! is_string( $source ) || rtrim( $source, '/' ) !== $this->working . '/' . $this->record->plugin_slug ) {
			return $this->error( 'context' );
		}
		$valid = ( $this->recheck )( $this->working );
		return $valid instanceof WP_Error ? $valid : $source;
	}
	public function download_args( mixed $package, mixed $extra, Session_Upgrader $upgrader ): true|WP_Error {
		return $this->upgrader === $upgrader && $package === $this->zip && $this->matches( $extra ) && 'prepared' === $this->phase ? true : $this->error( 'context' );
	}

	/** Private staging replaces core's shared upgrade-directory purge. */
	public function unpack( mixed $package, Session_Upgrader $upgrader ): mixed {
		if ( $upgrader !== $this->upgrader || $package !== $this->zip || 'downloaded' !== $this->phase ) {
			return $this->error( 'context' );
		}
		$valid = ( $this->recheck )(); if ( $valid instanceof WP_Error ) { return $valid; }
		$temp = wp_tempnam( '10t-gu-stage' );
		if ( ! is_string( $temp ) || ! is_file( $temp ) || is_link( $temp ) || ! unlink( $temp ) || ! mkdir( $temp, 0700 ) ) {
			return $this->error( 'staging' );
		}
		$this->working = $temp; $this->working_stat = lstat( $temp );
		if ( realpath( $temp ) !== $temp || ! chmod( $temp, 0700 ) ) {
			return $this->error( 'staging' );
		}
		$unzipped = unzip_file( $this->zip, $temp ); if ( true !== $unzipped ) { return $unzipped instanceof WP_Error ? $unzipped : $this->error( 'staging' ); }
		$valid = ( $this->recheck )( $temp ); if ( $valid instanceof WP_Error ) { return $valid; }
		$this->phase = 'staged'; return $temp;
	}
	public function install_args( array $args, Session_Upgrader $upgrader ): true|WP_Error {
		if ( $upgrader !== $this->upgrader || ! $this->matches( $args['hook_extra'] ?? null ) || ( $args['source'] ?? null ) !== $this->working || ( $args['destination'] ?? null ) !== WP_PLUGIN_DIR || true !== ( $args['clear_destination'] ?? null ) || true !== ( $args['clear_working'] ?? null ) || ( $args['hook_extra']['temp_backup'] ?? null ) !== $this->backup ) {
			return $this->error( 'context' );
		}
		return true;
	}
	public function installed( mixed $result ): void { if ( is_array( $result ) && 'copy_ready' === $this->phase ) { $this->phase = 'installed'; } }

	/** Bounded no-follow cleanup of this scope's private staging tree only. */
	private function remove_tree( string $path, int $depth, int &$count ): bool {
		clearstatcache( true, $path );
		$stat = @lstat( $path ); if ( false === $stat ) { return ! file_exists( $path ); }
		if ( 0 === $depth && 0040000 === ( $stat['mode'] & 0170000 ) && ( null === $this->working_stat || $stat['dev'] !== $this->working_stat['dev'] || $stat['ino'] !== $this->working_stat['ino'] ) ) {
			return false;
		}
		if ( ++$count > 5001 || $depth > 20 ) {
			return false;
		}
		if ( 0040000 !== ( $stat['mode'] & 0170000 ) ) {
			return @unlink( $path );
		}
		$dir = @opendir( $path ); if ( false === $dir ) { return false; }
		$ok = true;
		try { while ( false !== ( $name = readdir( $dir ) ) ) { if ( '.' !== $name && '..' !== $name && ! $this->remove_tree( $path . '/' . $name, $depth + 1, $count ) ) { $ok = false; } } } finally { closedir( $dir ); }
		return $ok && @rmdir( $path );
	}
	private function error( string $code ): WP_Error { return new WP_Error( 'github_updater_session_' . $code, __( 'The native plugin update session could not complete safely. Refresh before retrying; inspect any reported restoration or cleanup failure.', 'wordpress-github-updater' ) ); }
}
