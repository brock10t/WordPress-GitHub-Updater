<?php
/**
 * Stable numeric release-tag normalization.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the supported v1 tag grammar without making update decisions.
 */
final class Release_Tag {
	public const MAX_VERSION_LENGTH = 128;

	/**
	 * Remove one optional lowercase v from an otherwise stable numeric tag.
	 *
	 * Require at least two dot-separated components. Each component is zero or
	 * a non-zero-leading integer of at most nine digits, fitting 32-bit PHP.
	 * Preserve component count: comparison policy is a separate responsibility.
	 * Do not repair malformed tags or strip prerelease/build qualifiers.
	 *
	 * @param string $tag Original GitHub tag_name, kept separately by consumers.
	 * @return string|WP_Error Normalized numeric version or diagnostic.
	 */
	public static function normalize( string $tag ): string|WP_Error {
		if ( strlen( $tag ) <= self::MAX_VERSION_LENGTH + 1 ) {
			$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;
			if (
				strlen( $version ) <= self::MAX_VERSION_LENGTH
				&& preg_match( '/\A(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8}))+\z/', $version )
			) {
				return $version;
			}
		}

		return new WP_Error(
			'github_updater_unsupported_release_tag',
			__( 'Use a stable dotted numeric release tag such as 1.2.3 or v1.2.3, without leading zeros, whitespace or qualifiers. Versions support up to 128 characters and nine digits per component.', 'wordpress-github-updater' )
		);
	}
}
