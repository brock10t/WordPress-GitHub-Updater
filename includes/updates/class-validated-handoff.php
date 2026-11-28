<?php
/** Scoped validated ZIP lifetime for subsequent native integration. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Updates;

use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Check;
use TenT\GitHubUpdater\Packages\Asset_Downloader;
use TenT\GitHubUpdater\Packages\Replacement_Check;
use TenT\GitHubUpdater\Packages\Staged_Files;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Registry;
use TenT\GitHubUpdater\Registry\Registry_Lock;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** No native approval or reusable path; consumer must finish within this scope. */
final class Validated_Handoff {
	private bool $request_scope = false;
	private bool $item_active = false;
	public function __construct(
		private readonly Registry $registry = new Registry(),
		private readonly Registry_Lock $registry_lock = new Registry_Lock(),
		private readonly Upgrade_Lock $upgrade_lock = new Upgrade_Lock(),
		private readonly Release_Check $releases = new Release_Check(),
		private readonly Asset_Downloader $downloader = new Asset_Downloader(),
		private readonly Replacement_Check $replacement = new Replacement_Check(),
		private readonly Staged_Files $staged = new Staged_Files()
	) {}

	/**
	 * Hold physical-tree and registry scopes throughout fresh selection, bounded
	 * download, package checks and synchronous consumer. Consumer receives path
	 * and a recheck closure (optional extracted working root), invalid after return; invoke immediately
	 * before staged/native mutation. This API does NOT bypass Upgrade_Guard.
	 * Caller owns capabilities/nonces and eventual native rollback coordination.
	 *
	 * @return mixed Consumer result or WP_Error; exceptions propagate after cleanup.
	 */
	public function run( Registration $selected, Release_Candidate $expected, callable $consume ): mixed {
		if ( ! $this->request_scope ) {
			return $this->in_request( fn() => $this->run( $selected, $expected, $consume ) );
		}
		if ( $this->item_active ) { return $this->error( 'busy' ); }
		$this->item_active = true;
		try {
			return $this->prepare( $selected, $expected, $consume );
		} finally { $this->item_active = false; }
	}

	/** Retain both scopes across a native request, including core shutdown backups. */
	public function in_request( callable $consume ): mixed {
		if ( $this->request_scope ) { return $this->error( 'busy' ); }
		return $this->upgrade_lock->run( fn() => $this->registry_lock->run( function () use ( $consume ) {
			$this->request_scope = true;
			try { return $this->owned() ? $consume() : $this->error( 'lock' ); }
			finally { $this->request_scope = false; }
		} ) );
	}
	public function owns_scope(): bool { return $this->request_scope && $this->owned(); }
	private function prepare( Registration $selected, Release_Candidate $expected, callable $consume ): mixed {

			if ( ! $this->owned() ) {
				return $this->error( 'lock' );
			}
			$current = $this->registry->get( $selected->plugin_basename );
			if ( $current instanceof WP_Error ) {
				return $current;
			}
			if ( $current->to_array() !== $selected->to_array() ) {
				return $this->error( 'registration' );
			}
			$ready = $this->registry->check_update_uri( $current->plugin_basename );
			if ( $ready instanceof WP_Error ) {
				return $ready;
			}
			// Force re-selection, preserving server cooldowns, before any package bytes.
			$fresh = $this->releases->get( $current, true );
			if ( $fresh instanceof WP_Error ) {
				return $fresh;
			}
			if ( ! $fresh instanceof Release_Candidate || ! $this->same_selection( $fresh, $expected ) || ! $this->owned() ) {
				return $this->error( 'selection' );
			}
			return $this->downloader->with_file( $fresh->asset, function ( string $path ) use ( $current, $fresh, $consume ) {
				$headers = $this->replacement->check( $path, $current, $fresh->tag_name );
				if ( $headers instanceof WP_Error ) {
					return $headers;
				}
				$hash = hash_file( 'sha256', $path );
				if ( false === $hash || ! $this->owned() ) {
					return $this->error( 'lock' );
				}
				$active = true;
				$recheck = function ( ?string $working = null ) use ( &$active, $path, $hash, $current, $fresh ) {
					clearstatcache( true, $path );
					if ( ! $active || ! $this->owned() || is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== $fresh->asset->size || hash_file( 'sha256', $path ) !== $hash ) {
						return $this->error( 'changed' );
					}
					$result = $this->replacement->check( $path, $current, $fresh->tag_name );
					if ( ! $result instanceof WP_Error && null !== $working ) {
						$result = $this->staged->check( $path, $working, $current );
					}
					return $this->owned() ? $result : $this->error( 'lock' );
				};
				try {
					$ownership = function () use ( &$active ): bool { return $active && $this->owned(); };
					$result = $consume( $path, $recheck, $ownership );
					return $this->owned() ? $result : $this->error( 'lock' );
				} finally {
					$active = false;
				}
			} );
	}

	private function same_selection( Release_Candidate $fresh, Release_Candidate $expected ): bool {
		$a = get_object_vars( $fresh );
		$b = get_object_vars( $expected );
		$a['asset'] = get_object_vars( $fresh->asset );
		$b['asset'] = get_object_vars( $expected->asset );
		return $a === $b;
	}

	private function owned(): bool {
		return $this->upgrade_lock->is_owned() && $this->registry_lock->is_owned();
	}
	private function error( string $code ): WP_Error {
		return new WP_Error( 'github_updater_handoff_' . $code, __( 'The selected package, registration or cooperative lock is no longer valid. Refresh before retrying; no reusable package approval was issued.', 'wordpress-github-updater' ) );
	}
}
