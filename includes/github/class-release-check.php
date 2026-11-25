<?php
/** Compose installed prerequisites, cached retrieval and metadata selection. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\GitHub;

use TenT\GitHubUpdater\Registry\Installed_Plugins;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Update_URI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicitly loaded and unhooked; never downloads or authorizes installation. */
final class Release_Check {
	/**
	 * @param Cached_Releases   $releases  Complete cached metadata reader.
	 * @param Installed_Plugins $installed Current exact installed-plugin discovery.
	 */
	public function __construct(
		private readonly Cached_Releases $releases = new Cached_Releases(),
		private readonly Installed_Plugins $installed = new Installed_Plugins()
	) {}

	/**
	 * Caller supplies a current registry record, enforcing registry readiness.
	 * Force/invalidation HTTP callers still own capabilities and nonces. Headers
	 * are read on every call, never from repository metadata cache. A candidate
	 * is not an update offer and must pass future package/compatibility gates.
	 *
	 * @param Registration $registration Current explicit registration.
	 * @param bool         $force        Bypass ordinary cache, preserving cooldowns.
	 * @return Release_Candidate|Check_State|WP_Error
	 */
	public function get( Registration $registration, bool $force = false ): Release_Candidate|Check_State|WP_Error {
		$repository = Repository::from_url( $registration->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		$headers = $this->installed->get( $registration->plugin_basename );
		if ( $headers instanceof WP_Error ) {
			return $headers;
		}
		$compatible = Update_URI::check( $headers, $repository );
		if ( $compatible instanceof WP_Error ) {
			return $compatible;
		}
		$version = $headers['Version'] ?? null;
		if ( ! is_string( $version ) ) {
			$version = '';
		}
		$valid = Version_Comparison::compare( '0.0', $version );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}
		$releases = $this->releases->get( $repository, $force );
		if ( $releases instanceof WP_Error ) {
			return $releases;
		}
		return Release_Selector::select( $releases, $registration, $version );
	}
}
