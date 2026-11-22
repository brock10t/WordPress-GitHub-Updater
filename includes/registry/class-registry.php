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
	 * @param Registry_Lock     $lock      Mutation coordination.
	 */
	public function __construct(
		private readonly Option_Storage $storage = new Option_Storage(),
		private readonly Installed_Plugins $installed = new Installed_Plugins(),
		private readonly Registry_Lock $lock = new Registry_Lock()
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
	 * @param bool   $allow_pending  Permit a missing-header pending registration.
	 * @return Registration|WP_Error
	 */
	public function register( string $basename, string $repository_url, bool $allow_pending = true ): Registration|WP_Error {
		$permission = $this->authorize_mutation();
		if ( $permission instanceof WP_Error ) {
			return $permission;
		}
		return $this->lock->run( fn() => $this->register_locked( $basename, $repository_url, $allow_pending ) );
	}

	/**
	 * Validate against the latest snapshot while holding the mutation lock.
	 *
	 * @param string $basename       Exact installed basename.
	 * @param string $repository_url Selected GitHub URL.
	 * @param bool   $allow_pending  Permit a missing-header pending registration.
	 * @return Registration|WP_Error
	 */
	private function register_locked( string $basename, string $repository_url, bool $allow_pending ): Registration|WP_Error {
		$updater = plugin_basename( dirname( __DIR__, 2 ) . '/wordpress-github-updater.php' );
		if ( strtolower( dirname( $basename ) ) === strtolower( dirname( $updater ) ) ) {
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
		if ( $compatibility instanceof WP_Error && ( ! $allow_pending || 'github_updater_missing_update_uri' !== $compatibility->get_error_code() ) ) {
			return $compatibility;
		}
		$records = $this->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		if ( isset( $records[ $basename ] ) ) {
			return new WP_Error( 'github_updater_plugin_already_registered', __( 'This plugin is already registered. Remove its registration explicitly before associating it again.', 'wordpress-github-updater' ) );
		}

		$record   = new Registration( $basename, $repository->owner, $repository->name );
		$conflict = $this->find_conflict( $record, $records );
		if ( $conflict instanceof WP_Error ) {
			return $conflict;
		}
		$conflict = $this->check_installed_directory( $record );
		if ( $conflict instanceof WP_Error ) {
			return $conflict;
		}
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

		$records = $this->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		$conflict = $this->find_conflict( $record, $records, true );
		if ( $conflict instanceof WP_Error ) {
			return $conflict;
		}
		$conflict = $this->check_installed_directory( $record );
		if ( $conflict instanceof WP_Error ) {
			return $conflict;
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
		return $this->lock->run(
			function () use ( $basename ) {
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
		);
	}

	/**
	 * Enforce one plugin identity and directory per normalized repository.
	 *
	 * @param Registration                $candidate Candidate or existing record.
	 * @param array<string, Registration> $records   Current persisted snapshot.
	 * @param bool                        $skip_self Ignore exact self for readiness.
	 * @return true|WP_Error
	 */
	private function find_conflict( Registration $candidate, array $records, bool $skip_self = false ): true|WP_Error {
		$repository = Repository::from_url( $candidate->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		foreach ( $records as $existing ) {
			if ( $skip_self && $existing->plugin_basename === $candidate->plugin_basename ) {
				continue;
			}
			if ( strtolower( $existing->plugin_basename ) === strtolower( $candidate->plugin_basename ) ) {
				return new WP_Error( 'github_updater_plugin_identity_conflict', __( 'A registration already uses this plugin basename with different letter case. Remove the conflicting registration explicitly.', 'wordpress-github-updater' ) );
			}
			if ( strtolower( $existing->plugin_slug ) === strtolower( $candidate->plugin_slug ) ) {
				return $this->directory_conflict();
			}
			$existing_repository = Repository::from_url( $existing->repository_url );
			if ( $existing_repository instanceof WP_Error ) {
				return $existing_repository;
			}
			if ( $existing_repository->url === $repository->url ) {
				return new WP_Error( 'github_updater_repository_already_registered', __( 'This GitHub repository is already assigned to another plugin. Version 1 supports one managed plugin per repository.', 'wordpress-github-updater' ) );
			}
		}

		return true;
	}

	/**
	 * Do not replace a directory containing another ordinary plugin entry point.
	 *
	 * @param Registration $candidate Managed directory identity.
	 * @return true|WP_Error
	 */
	private function check_installed_directory( Registration $candidate ): true|WP_Error {
		foreach ( $this->installed->all() as $basename => $headers ) {
			if ( $basename !== $candidate->plugin_basename && strtolower( dirname( $basename ) ) === strtolower( $candidate->plugin_slug ) ) {
				return $this->directory_conflict();
			}
		}

		return true;
	}

	/**
	 * @return WP_Error
	 */
	private function directory_conflict(): WP_Error {
		return new WP_Error( 'github_updater_plugin_directory_conflict', __( 'This directory is shared by multiple plugin identities. Version 1 cannot manage it safely; no registration was changed.', 'wordpress-github-updater' ) );
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
