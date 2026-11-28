<?php
/** Current destination and activation-requirement replacement checks. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\GitHub\Version_Comparison;
use TenT\GitHubUpdater\Registry\Installed_Plugins;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only preflight, not an atomic replacement lock or permission grant. */
final class Replacement_Check {
	public const MAX_NODES = 5000;
	public const MAX_DEPTH = 20;

	/** Compose explicitly loaded concrete components. */
	public function __construct(
		private readonly Registry $registry = new Registry(),
		private readonly Installed_Plugins $installed = new Installed_Plugins(),
		private readonly Packaged_Plugin $package = new Packaged_Plugin()
	) {}

	/**
	 * Recheck a protected ZIP against current registry, installed state and disk.
	 * Caller must use the same destination and recheck under future upgrader
	 * coordination immediately before replacement. This check writes no source,
	 * registry, activation or auto-update options; cron need not impersonate a user.
	 *
	 * @param string $zip Protected local ZIP. @param Registration $expected Selected identity.
	 * @param string $tag Selected release tag. @return array<string, string>|WP_Error
	 */
	public function check( string $zip, Registration $expected, string $tag ): array|WP_Error {
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		$current = $this->registry->get( $expected->plugin_basename );
		if ( $current instanceof WP_Error ) {
			return $current;
		}
		if ( $current->to_array() !== $expected->to_array() ) {
			return $this->error( 'registration', __( 'The plugin registration changed after release selection. Refresh before updating.', 'wordpress-github-updater' ) );
		}
		$ready = $this->registry->check_update_uri( $current->plugin_basename );
		if ( $ready instanceof WP_Error ) {
			return $ready;
		}
		$headers = $this->installed->get( $current->plugin_basename );
		if ( $headers instanceof WP_Error ) {
			return $headers;
		}
		$version = $headers['Version'] ?? '';
		$newer   = Version_Comparison::compare( $tag, is_string( $version ) ? $version : '' );
		if ( $newer instanceof WP_Error ) {
			return $newer;
		}
		if ( $newer <= 0 ) {
			return $this->error( 'version', __( 'The selected release is no longer newer than the installed plugin. Refresh update information.', 'wordpress-github-updater' ) );
		}
		$disk = $this->destination( $current );
		if ( $disk instanceof WP_Error ) {
			return $disk;
		}
		$packaged = $this->package->check( $zip, $current, $tag );
		if ( $packaged instanceof WP_Error ) {
			return $packaged;
		}
		// A requirement change can affect inactive or site-active copies on any blog.
		// Reject both directions without scanning only the current site's activation.
		if ( is_multisite() && Plugin_Headers::network_only( $headers ) !== Plugin_Headers::network_only( $packaged ) ) {
			return $this->error( 'network', __( 'The package changes the plugin network-only requirement. Automatic replacement cannot preserve all site and network activation choices safely.', 'wordpress-github-updater' ) );
		}
		return $packaged;
	}

	/** @return true|WP_Error Inspect only the fixed registered destination, never ZIP paths. */
	private function destination( Registration $record ): true|WP_Error {
		global $wp_filesystem;
		// Native direct dirlist follows links; PHP lstat is needed to reject them.
		// Other transport views cannot yet be proven equivalent to local disk.
		if ( ! ( $wp_filesystem instanceof \WP_Filesystem_Direct ) || ! isset( $wp_filesystem->method ) || 'direct' !== $wp_filesystem->method ) {
			return $this->error( 'filesystem', __( 'Replacement validation requires an initialized direct WordPress filesystem connection.', 'wordpress-github-updater' ) );
		}
		$base = rtrim( WP_PLUGIN_DIR, '/' );
		clearstatcache();
		if ( is_link( $base ) || false === ( $real = realpath( $base ) ) || ! is_dir( $base ) || $real !== $base ) {
			return $this->error( 'destination', __( 'The plugin root is linked, aliased or not directly inspectable.', 'wordpress-github-updater' ) );
		}
		$listing = @opendir( $base );
		if ( false === $listing ) {
			return $this->unsafe();
		}
		$count = 0;
		$found = false;
		try {
			while ( false !== ( $name = readdir( $listing ) ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ++$count > self::MAX_NODES ) {
					return $this->unsafe();
				}
				if ( strtolower( $name ) === strtolower( $record->plugin_slug ) ) {
					if ( $name !== $record->plugin_slug || $found ) {
						return $this->unsafe();
					}
					$found = true;
				}
			}
		} finally {
			closedir( $listing );
		}
		$target = $base . '/' . $record->plugin_slug;
		if ( ! $found || is_link( $target ) || ! is_dir( $target ) || realpath( $target ) !== $target || ! is_file( $base . '/' . $record->plugin_basename ) ) {
			return $this->unsafe();
		}
		$count = 0;
		return $this->tree( $target, 1, $count );
	}

	/** @return true|WP_Error Bounded no-follow walk catches links even in nested directories. */
	private function tree( string $path, int $depth, int &$count ): true|WP_Error {
		if ( $depth > self::MAX_DEPTH ) {
			return $this->unsafe();
		}
		$dir = @opendir( $path );
		if ( false === $dir ) {
			return $this->unsafe();
		}
		$names = array();
		try {
			while ( false !== ( $name = readdir( $dir ) ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$key = strtolower( $name );
				if ( ++$count > self::MAX_NODES || isset( $names[ $key ] ) ) {
					return $this->unsafe();
				}
				$names[ $key ] = true;
				$child = $path . '/' . $name;
				$stat  = @lstat( $child );
				if ( false === $stat ) {
					return $this->unsafe();
				}
				$type = $stat['mode'] & 0170000;
				if ( 0040000 === $type ) {
					$check = $this->tree( $child, $depth + 1, $count );
					if ( $check instanceof WP_Error ) {
						return $check;
					}
				} elseif ( 0100000 !== $type || $stat['nlink'] > 1 ) {
					return $this->unsafe();
				}
			}
		} finally {
			closedir( $dir );
		}
		return true;
	}

	/** @return WP_Error No local paths reflected. */
	private function unsafe(): WP_Error {
		return $this->error( 'destination', __( 'The replacement destination is missing, ambiguous, linked, special or beyond the supported inspection limits.', 'wordpress-github-updater' ) );
	}
	/** @param string $code Fixed suffix. @param string $message Fixed text. @return WP_Error */
	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( 'github_updater_replacement_' . $code, $message );
	}
}
