<?php
/** Packaged plugin identity and runtime gates after full ZIP integrity. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\GitHub\Repository;
use TenT\GitHubUpdater\GitHub\Release_Tag;
use TenT\GitHubUpdater\GitHub\Version_Comparison;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Update_URI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** No extraction, source edits or native update offers. */
final class Packaged_Plugin {
	/** @param Zip_Contents $contents Complete record/manifest/payload gates. */
	public function __construct( private readonly Zip_Contents $contents = new Zip_Contents() ) {}

	/**
	 * Validate exact packaged main headers against current explicit identity/tag.
	 * Caller owns release selection, registration readiness, protected temp bytes
	 * and later destination conflict/activation checks. Returned headers are raw
	 * display data to escape, not a reusable installability token.
	 *
	 * @param string $path Local ZIP. @param Registration $registration Identity.
	 * @param string $release_tag Selected original stable release tag.
	 * @return array<string, string>|WP_Error
	 */
	public function check( string $path, Registration $registration, string $release_tag ): array|WP_Error {
		$tag = Release_Tag::normalize( $release_tag );
		if ( $tag instanceof WP_Error ) {
			return $tag;
		}
		$repository = Repository::from_url( $registration->repository_url );
		if ( $repository instanceof WP_Error ) {
			return $repository;
		}
		$prefixes = $this->contents->plugin_prefixes( $path, $registration );
		if ( $prefixes instanceof WP_Error ) {
			return $prefixes;
		}
		$main = null;
		foreach ( $prefixes as $basename => $prefix ) {
			$headers = Plugin_Headers::parse( $prefix );
			if ( $headers instanceof WP_Error ) {
				return $headers;
			}
			if ( $basename === $registration->plugin_basename ) {
				$main = $headers;
			} elseif ( ! empty( $headers['Name'] ) ) {
				return $this->error( 'entry_points', __( 'The package contains another top-level plugin entry point. Its installed identity would be ambiguous.', 'wordpress-github-updater' ) );
			}
		}
		if ( null === $main || empty( $main['Name'] ) ) {
			return $this->error( 'headers', __( 'The exact registered main file must declare a nonempty Plugin Name header in its first 8 KiB.', 'wordpress-github-updater' ) );
		}
		$version = Release_Tag::normalize( $main['Version'] );
		if ( $version instanceof WP_Error ) {
			return $this->error( 'version', __( 'The packaged plugin Version header is missing or unsupported.', 'wordpress-github-updater' ) );
		}
		$comparison = Version_Comparison::compare( $release_tag, $main['Version'] );
		if ( $comparison instanceof WP_Error || 0 !== $comparison ) {
			return $this->error( 'version', __( 'The packaged plugin Version does not match the selected release tag.', 'wordpress-github-updater' ) );
		}
		$uri = Update_URI::check( $main, $repository );
		if ( $uri instanceof WP_Error ) {
			return $uri;
		}
		foreach ( array( 'RequiresWP', 'RequiresPHP' ) as $key ) {
			$required = $main[ $key ];
			if ( '' !== $required && ( strlen( $required ) > 40 || ! preg_match( '/\A(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8})){0,3}\z/', $required ) ) ) {
				return $this->error( 'requirements', __( 'The packaged plugin declares an unsupported minimum runtime version.', 'wordpress-github-updater' ) );
			}
		}
		if ( '' !== $main['RequiresPlugins'] ) {
			return $this->error( 'dependencies', __( 'Packages declaring Requires Plugins need dependency validation, which is not supported by this package gate yet.', 'wordpress-github-updater' ) );
		}
		if ( ! is_wp_version_compatible( $main['RequiresWP'] ) ) {
			return $this->error( 'wordpress', __( 'The package requires a newer WordPress version than this installation provides.', 'wordpress-github-updater' ) );
		}
		if ( ! is_php_version_compatible( $main['RequiresPHP'] ) ) {
			return $this->error( 'php', __( 'The package requires a newer PHP version than the current runtime provides.', 'wordpress-github-updater' ) );
		}
		return $main;
	}

	/** @param string $code Fixed suffix. @param string $message Fixed text. @return WP_Error */
	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( 'github_updater_package_' . $code, $message );
	}
}
