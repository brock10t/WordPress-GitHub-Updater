<?php
/**
 * Exact uploaded release-asset identity, without downloading a package.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validated metadata pointer; does not prove ZIP safety or installability.
 */
final readonly class Release_Asset {
	/**
	 * @param int    $id           GitHub uploaded asset ID.
	 * @param string $name         Exact required ZIP filename.
	 * @param int    $size         Declared positive size; not a trusted byte count.
	 * @param string $api_url      Repository-bound metadata endpoint.
	 * @param string $download_url Repository/tag/name-bound browser download URL.
	 */
	private function __construct(
		public int $id,
		public string $name,
		public int $size,
		public string $api_url,
		public string $download_url
	) {}

	/**
	 * Select exactly one uploaded ZIP named for the registered installed slug.
	 *
	 * Publication, version comparison and package validation remain separate.
	 * Do not use zipball_url/tarball_url or silently select a similarly named ZIP.
	 *
	 * @param array<string, mixed> $release      Raw release metadata.
	 * @param Registration         $registration Registered installed identity.
	 * @return self|WP_Error
	 */
	public static function select( array $release, Registration $registration ): self|WP_Error {
		$repository = Repository::from_url( $registration->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		if ( ! isset( $release['tag_name'], $release['assets'] ) || ! is_string( $release['tag_name'] ) || ! is_array( $release['assets'] ) || ! array_is_list( $release['assets'] ) ) {
			return self::invalid_metadata();
		}
		$version = Release_Tag::normalize( $release['tag_name'] );
		if ( $version instanceof WP_Error ) {
			return $version;
		}

		$matches = array();
		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'] ) || ! is_string( $asset['name'] ) ) {
				return self::invalid_metadata();
			}
			if ( $registration->asset_name === $asset['name'] ) {
				$matches[] = $asset;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return new WP_Error(
				array() === $matches ? 'github_updater_release_asset_missing' : 'github_updater_release_asset_ambiguous',
				array() === $matches
					? __( 'The release is missing the required uploaded plugin ZIP. Automatically generated source archives are not supported.', 'wordpress-github-updater' )
					: __( 'The release contains multiple assets with the required filename. The plugin ZIP cannot be selected safely.', 'wordpress-github-updater' ),
				array( 'expected_asset_name' => $registration->asset_name )
			);
		}
		$asset = $matches[0];
		if (
			! isset( $asset['id'], $asset['state'], $asset['size'], $asset['url'], $asset['browser_download_url'] )
			|| ! is_int( $asset['id'] ) || $asset['id'] <= 0
			|| 'uploaded' !== $asset['state']
			|| ! is_int( $asset['size'] ) || $asset['size'] <= 0
			|| ! is_string( $asset['url'] ) || ! is_string( $asset['browser_download_url'] )
		) {
			return self::invalid_metadata();
		}

		$download_path = '/releases/download/' . rawurlencode( $release['tag_name'] ) . '/' . rawurlencode( $registration->asset_name );
		if (
			! self::matches_url( $asset['url'], 'api.github.com', $repository, '/releases/assets/' . $asset['id'] )
			|| ! self::matches_url( $asset['browser_download_url'], 'github.com', $repository, $download_path )
		) {
			return new WP_Error( 'github_updater_release_asset_url_mismatch', __( 'The ZIP asset URLs do not match the registered repository and release. No package was downloaded.', 'wordpress-github-updater' ) );
		}

		return new self( $asset['id'], $asset['name'], $asset['size'], $asset['url'], $asset['browser_download_url'] );
	}

	/**
	 * Pin host/path exactly, allowing only owner/repository letter-case variation.
	 *
	 * @param string     $url        External metadata URL.
	 * @param string     $host       Required literal HTTPS host.
	 * @param Repository $repository Registered repository identity.
	 * @param string     $tail       Required encoded path after repository name.
	 * @return bool
	 */
	private static function matches_url( string $url, string $host, Repository $repository, string $tail ): bool {
		$prefix  = 'https://' . $host . ( 'api.github.com' === $host ? '/repos/' : '/' );
		$pattern = '~\A' . preg_quote( $prefix, '~' ) . '([a-zA-Z0-9-]+)/([a-zA-Z0-9_.-]+)' . preg_quote( $tail, '~' ) . '\z~';
		return 1 === preg_match( $pattern, $url, $matches )
			&& strtolower( $matches[1] ) === $repository->owner
			&& strtolower( $matches[2] ) === $repository->name;
	}

	/** @return WP_Error No untrusted asset contents in the diagnostic. */
	private static function invalid_metadata(): WP_Error {
		return new WP_Error( 'github_updater_invalid_asset_metadata', __( 'GitHub returned incomplete or invalid uploaded ZIP asset metadata.', 'wordpress-github-updater' ) );
	}
}
