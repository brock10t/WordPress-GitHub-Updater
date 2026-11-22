<?php
/**
 * Explicit managed-plugin registry operations.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use TenT\GitHubUpdater\GitHub\Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates persistence, installed identity and repository/header checks.
 *
 * HTTP action handlers must additionally enforce context and verify nonces.
 */
final class Registry {
	/**
	 * Compose the concrete registry components without a dependency container.
	 *
	 * @param Option_Storage    $storage   Registry persistence.
	 * @param Installed_Plugins $installed Installed-plugin discovery.
	 */
	public function __construct(
		private readonly Option_Storage $storage = new Option_Storage(),
		private readonly Installed_Plugins $installed = new Installed_Plugins()
	) {}

	/**
	 * Read explicit records, including records whose plugins have been removed.
	 *
	 * @return array<string, Registration>|WP_Error
	 */
	public function all(): array|WP_Error {
		return $this->storage->load();
	}

	/**
	 * Look up an exact registered basename; this does not authorize an update.
	 *
	 * @param string $basename Canonical registered basename.
	 * @return Registration|WP_Error
	 */
	public function get( string $basename ): Registration|WP_Error {
		$records = $this->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}

		return $records[ $basename ] ?? $this->not_registered();
	}

	/**
	 * Explicitly associate an installed plugin with a validated repository URL.
	 *
	 * Missing headers permit a pending record; all other incompatibilities fail.
	 * Call check_update_uri() after success to display pending-header guidance.
	 *
	 * @param string $basename       Exact installed plugin basename.
	 * @param string $repository_url Administrator-supplied GitHub repository URL.
	 * @return Registration|WP_Error
	 */
	public function register( string $basename, string $repository_url ): Registration|WP_Error {
		$permission = $this->authorize_mutation();
		if ( $permission instanceof WP_Error ) {
			return $permission;
		}
		$updater = plugin_basename( dirname( __DIR__, 2 ) . '/wordpress-github-updater.php' );
		if ( $basename === $updater ) {
			return new WP_Error( 'github_updater_self_registration_reserved', __( 'GitHub Updater uses separate built-in self-update registration when its repository is configured.', 'wordpress-github-updater' ) );
		}
		$headers = $this->installed->get( $basename );
		if ( $headers instanceof WP_Error ) {
			return $headers;
		}
		$repository = Repository::from_url( $repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		$compatibility = Update_URI::check( $headers, $repository );
		if ( $compatibility instanceof WP_Error && 'github_updater_missing_update_uri' !== $compatibility->get_error_code() ) {
			return $compatibility;
		}
		$records = $this->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		if ( isset( $records[ $basename ] ) ) {
			return new WP_Error( 'github_updater_plugin_already_registered', __( 'This plugin is already registered. Remove its registration explicitly before associating it again.', 'wordpress-github-updater' ) );
		}

		$record               = new Registration( $basename, $repository->owner, $repository->name );
		$records[ $basename ] = $record;
		$saved                = $this->storage->save( $records );

		return $saved instanceof WP_Error ? $saved : $record;
	}

	/**
	 * Recompute current header compatibility; never persist readiness flags.
	 *
	 * @param string $basename Canonical registered basename.
	 * @return true|WP_Error
	 */
	public function check_update_uri( string $basename ): true|WP_Error {
		$record = $this->get( $basename );
		if ( $record instanceof WP_Error ) {
			return $record;
		}
		$headers = $this->installed->get( $basename );
		if ( $headers instanceof WP_Error ) {
			return $headers;
		}
		$repository = Repository::from_url( $record->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}

		return Update_URI::check( $headers, $repository );
	}

	/**
	 * Remove only a registry record, even if its plugin is no longer installed.
	 *
	 * @param string $basename Exact registered basename.
	 * @return true|WP_Error
	 */
	public function remove( string $basename ): true|WP_Error {
		$permission = $this->authorize_mutation();
		if ( $permission instanceof WP_Error ) {
			return $permission;
		}
		$records = $this->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		if ( ! isset( $records[ $basename ] ) ) {
			return $this->not_registered();
		}

		unset( $records[ $basename ] );
		return $this->storage->save( $records );
	}

	/**
	 * Protect internal mutations as well as future administrative action routes.
	 *
	 * @return true|WP_Error
	 */
	private function authorize_mutation(): true|WP_Error {
		if ( ! current_user_can( 'update_plugins' ) || ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) ) {
			return new WP_Error( 'github_updater_registry_forbidden', __( 'You do not have permission to manage GitHub plugin registrations.', 'wordpress-github-updater' ) );
		}

		return true;
	}

	/**
	 * Report failed exact lookups without reflecting untrusted identifiers.
	 *
	 * @return WP_Error
	 */
	private function not_registered(): WP_Error {
		return new WP_Error( 'github_updater_plugin_not_registered', __( 'No GitHub registration exists for that exact plugin basename.', 'wordpress-github-updater' ) );
	}
}
