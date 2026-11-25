<?php
/** Numeric release selection from a completed scan. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\GitHub;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure metadata selection; callers own registry and installed-header checks. */
final class Release_Selector {
	/**
	 * Choose the highest numeric version; never fall back from its invalid ZIP.
	 * Unsupported stable tags fail the whole check. Equivalent highest versions
	 * on distinct releases are ambiguous, independent of publication order.
	 *
	 * @param array<mixed> $releases          Completed stable candidate scan.
	 * @param Registration $registration     Exact registered identity.
	 * @param string       $installed_version Current installed Version header.
	 * @return Release_Candidate|Check_State|WP_Error
	 */
	public static function select( array $releases, Registration $registration, string $installed_version ): Release_Candidate|Check_State|WP_Error {
		$installed = Version_Comparison::compare( '0.0', $installed_version );
		if ( $installed instanceof WP_Error ) {
			return $installed;
		}
		$validated = Stable_Releases::validate_candidates( $releases );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}
		if ( array() === $validated ) {
			return Check_State::no_releases();
		}
		$best      = null;
		$ambiguous = false;
		foreach ( $validated as $release ) {
			$newer = Version_Comparison::compare( $release['tag_name'], $installed_version );
			if ( $newer instanceof WP_Error ) {
				return $newer;
			}
			if ( $newer <= 0 ) {
				continue;
			}
			$comparison = null === $best ? 1 : Version_Comparison::compare( $release['tag_name'], $best['tag_name'] );
			if ( $comparison instanceof WP_Error ) {
				return $comparison;
			}
			if ( $comparison > 0 ) {
				$best      = $release;
				$ambiguous = false;
			} elseif ( 0 === $comparison ) {
				$ambiguous = true;
			}
		}
		if ( null === $best ) {
			return Check_State::no_update();
		}
		if ( $ambiguous ) {
			return new WP_Error( 'github_updater_release_version_ambiguous', __( 'Multiple releases represent the highest newer numeric version. Ask the publisher to resolve the ambiguity; no older release was selected.', 'wordpress-github-updater' ) );
		}
		$asset = Release_Asset::select( $best, $registration );
		if ( $asset instanceof WP_Error ) {
			return $asset;
		}
		$version = Release_Tag::normalize( $best['tag_name'] );
		if ( $version instanceof WP_Error ) {
			return $version;
		}
		$repository = Repository::from_url( $registration->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		return new Release_Candidate( $registration->plugin_basename, $repository->url, $best['id'], $best['tag_name'], $version, $asset );
	}
}
