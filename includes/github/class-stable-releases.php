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
				return self::sort_candidates( $candidates );
			}
			if ( count( $releases ) > self::PER_PAGE ) {
				return self::invalid_release();
			}

			foreach ( $releases as $release ) {
				$parsed = self::parse_release( $release );
				if ( $parsed instanceof WP_Error ) {
					return $parsed;
				}
				if ( null === $parsed ) {
					continue;
				}
				if ( isset( $seen[ $release['id'] ] ) ) {
					return new WP_Error( 'github_updater_release_scan_changed', __( 'GitHub repeated a release during pagination. Check again later; no partial release list was selected.', 'wordpress-github-updater' ) );
				}
				$seen[ $release['id'] ] = true;
				$candidates[]         = $parsed;
			}
		}

		return new WP_Error( 'github_updater_release_scan_incomplete', __( 'GitHub release retrieval reached its page limit. No partial release list was selected.', 'wordpress-github-updater' ) );
	}

	/**
	 * Revalidate a stored completed candidate list using the retrieval rules.
	 *
	 * Cache records do not establish trust in flags, timestamps or identities.
	 * Tags/assets still require their own downstream validators.
	 *
	 * @param array<mixed> $releases Stored candidate list.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function validate_candidates( array $releases ): array|WP_Error {
		if ( ! array_is_list( $releases ) || count( $releases ) > ( self::MAX_PAGES - 1 ) * self::PER_PAGE ) {
			return self::invalid_release();
		}
		$candidates = array();
		$seen       = array();
		foreach ( $releases as $release ) {
			$parsed = self::parse_release( $release );
			if ( null === $parsed || $parsed instanceof WP_Error ) {
				return self::invalid_release();
			}
			if ( isset( $seen[ $release['id'] ] ) ) {
				return self::invalid_release();
			}
			$seen[ $release['id'] ] = true;
			$candidates[]         = $parsed;
		}
		return self::sort_candidates( $candidates );
	}

	/**
	 * @param mixed $release External metadata entry.
	 * @return array<string, mixed>|WP_Error|null Parsed candidate or excluded entry.
	 */
	private static function parse_release( mixed $release ): array|WP_Error|null {
		if ( ! is_array( $release ) || ! isset( $release['draft'], $release['prerelease'] ) || ! is_bool( $release['draft'] ) || ! is_bool( $release['prerelease'] ) ) {
			return self::invalid_release();
		}
		if ( $release['draft'] || $release['prerelease'] ) {
			return null;
		}
		if ( ! array_key_exists( 'published_at', $release ) ) {
			return self::invalid_release();
		}
		if ( null === $release['published_at'] ) {
			return null;
		}
		if (
			! isset( $release['id'], $release['tag_name'] )
			|| ! is_int( $release['id'] ) || $release['id'] <= 0
			|| ! is_string( $release['tag_name'] ) || '' === $release['tag_name']
			|| preg_match( '/[\x00-\x1f\x7f]/', $release['tag_name'] )
			|| ! is_string( $release['published_at'] )
			|| ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $release['published_at'] )
		) {
			return self::invalid_release();
		}
		$published = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $release['published_at'], new DateTimeZone( 'UTC' ) );
		if ( false === $published || $published->format( 'Y-m-d\TH:i:s\Z' ) !== $release['published_at'] ) {
			return self::invalid_release();
		}
		return array( 'release' => $release, 'published' => $published->getTimestamp() );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates Parsed candidates.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sort_candidates( array $candidates ): array {
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				return ( $right['published'] <=> $left['published'] ) ?: ( $right['release']['id'] <=> $left['release']['id'] );
			}
		);
		return array_column( $candidates, 'release' );
	}

	/** @return WP_Error Safe malformed-metadata diagnostic. */
	private static function invalid_release(): WP_Error {
		return new WP_Error( 'github_updater_invalid_release_metadata', __( 'GitHub returned malformed release metadata. No partial release list was selected.', 'wordpress-github-updater' ) );
	}
}
