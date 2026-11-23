<?php
/**
 * Bounded retrieval of stable published GitHub release metadata.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns published candidates, never installable packages or version decisions.
 */
final class Stable_Releases {
	public const MAX_PAGES = 5;
	public const PER_PAGE  = 100;

	/** @param Api_Client $client Public GitHub metadata transport. */
	public function __construct( private readonly Api_Client $client = new Api_Client() ) {}

	/**
	 * Return all candidates in a completed scan, newest publication first.
	 *
	 * Scan to an empty page, not merely a short page. No partial success is
	 * returned when a later request fails or the request bound is exhausted.
	 * Tags, assets and remaining metadata still require their own validation.
	 *
	 * @param Repository $repository Explicit repository identity.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get( Repository $repository ): array|WP_Error {
		$metadata = $this->client->get_repository( $repository );
		if ( $metadata instanceof WP_Error ) {
			return $metadata;
		}

		$candidates = array();
		$seen       = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$releases = $this->client->get_releases_page( $repository, $page, self::PER_PAGE );
			if ( $releases instanceof WP_Error ) {
				return $releases;
			}
			if ( array() === $releases ) {
				usort(
					$candidates,
					static function ( array $left, array $right ): int {
						return ( $right['published'] <=> $left['published'] ) ?: ( $right['release']['id'] <=> $left['release']['id'] );
					}
				);
				return array_column( $candidates, 'release' );
			}
			if ( count( $releases ) > self::PER_PAGE ) {
				return $this->invalid_release();
			}

			foreach ( $releases as $release ) {
				if ( ! is_array( $release ) || ! isset( $release['draft'], $release['prerelease'] ) || ! is_bool( $release['draft'] ) || ! is_bool( $release['prerelease'] ) ) {
					return $this->invalid_release();
				}
				if ( $release['draft'] || $release['prerelease'] ) {
					continue;
				}
				if ( ! array_key_exists( 'published_at', $release ) ) {
					return $this->invalid_release();
				}
				if ( null === $release['published_at'] ) {
					continue;
				}
				if (
					! isset( $release['id'], $release['tag_name'] )
					|| ! is_int( $release['id'] ) || $release['id'] <= 0
					|| ! is_string( $release['tag_name'] ) || '' === $release['tag_name']
					|| preg_match( '/[\x00-\x1f\x7f]/', $release['tag_name'] )
					|| ! is_string( $release['published_at'] )
					|| ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $release['published_at'] )
				) {
					return $this->invalid_release();
				}
				$published = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $release['published_at'], new DateTimeZone( 'UTC' ) );
				if ( false === $published || $published->format( 'Y-m-d\TH:i:s\Z' ) !== $release['published_at'] ) {
					return $this->invalid_release();
				}
				if ( isset( $seen[ $release['id'] ] ) ) {
					return new WP_Error( 'github_updater_release_scan_changed', __( 'GitHub repeated a release during pagination. Check again later; no partial release list was selected.', 'wordpress-github-updater' ) );
				}
				$seen[ $release['id'] ] = true;
				$candidates[]         = array( 'release' => $release, 'published' => $published->getTimestamp() );
			}
		}

		return new WP_Error( 'github_updater_release_scan_incomplete', __( 'GitHub release retrieval reached its page limit. No partial release list was selected.', 'wordpress-github-updater' ) );
	}

	/** @return WP_Error Safe malformed-metadata diagnostic. */
	private function invalid_release(): WP_Error {
		return new WP_Error( 'github_updater_invalid_release_metadata', __( 'GitHub returned malformed release metadata. No partial release list was selected.', 'wordpress-github-updater' ) );
	}
}
