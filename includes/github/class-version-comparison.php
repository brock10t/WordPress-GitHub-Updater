<?php
/**
 * Comparison of supported release and installed plugin versions.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares numeric versions without implying package or update eligibility.
 */
final class Version_Comparison {
	/**
	 * Compare a release tag to the installed Version header.
	 *
	 * Both inputs use Release_Tag's stable numeric grammar, including optional v.
	 * Missing trailing components count as zero, so 1.2 equals 1.2.0. Preserve
	 * original strings; padding applies only to the internal comparison inputs.
	 *
	 * @param string $release_tag       Original GitHub tag_name.
	 * @param string $installed_version Installed plugin's raw Version header.
	 * @return int|WP_Error -1 for older, 0 for equivalent, 1 for newer, or error.
	 */
	public static function compare( string $release_tag, string $installed_version ): int|WP_Error {
		$release = Release_Tag::normalize( $release_tag );
		if ( $release instanceof WP_Error ) {
			return $release;
		}
		$installed = Release_Tag::normalize( $installed_version );
		if ( $installed instanceof WP_Error ) {
			return new WP_Error(
				'github_updater_unsupported_installed_version',
				__( 'The installed plugin has an unsupported Version header. Use a stable dotted numeric version such as 1.2.3 before comparing GitHub releases.', 'wordpress-github-updater' )
			);
		}

		$release_parts   = explode( '.', $release );
		$installed_parts = explode( '.', $installed );
		$count           = max( count( $release_parts ), count( $installed_parts ) );

		return version_compare(
			implode( '.', array_pad( $release_parts, $count, '0' ) ),
			implode( '.', array_pad( $installed_parts, $count, '0' ) )
		);
	}
}
