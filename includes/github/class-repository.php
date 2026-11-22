<?php
/**
 * Public GitHub repository URL identity.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical repository identity, without any existence or visibility assertion.
 */
final readonly class Repository {
	/** @var string HTTPS repository root with lowercase identity components. */
	public string $url;

	/**
	 * Only validated URLs may create an identity.
	 *
	 * @param string $owner Normalized GitHub owner.
	 * @param string $name  Normalized repository name.
	 */
	private function __construct( public string $owner, public string $name ) {
		$this->url = 'https://github.com/' . $owner . '/' . $name;
	}

	/**
	 * Accept only an unambiguous HTTPS GitHub repository root.
	 *
	 * This intentionally accepts a smaller grammar than a general URL parser:
	 * no decoding, host rewriting, arbitrary endpoints or redirect resolution.
	 *
	 * @param string $url Repository URL supplied by an administrator or header.
	 * @return self|WP_Error
	 */
	public static function from_url( string $url ): self|WP_Error {
		// Ignore surrounding ordinary spaces, but never strip control characters.
		$url = trim( $url, ' ' );
		if ( ! preg_match( '/\Ahttps:\/\/github\.com\/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)\/([a-z0-9_.-]{1,104})\/?\z/i', $url, $matches ) ) {
			return self::invalid_url();
		}

		$owner = strtolower( $matches[1] );
		$name  = strtolower( $matches[2] );
		if ( str_ends_with( $name, '.git' ) ) {
			$name = substr( $name, 0, -4 );
		}

		if (
			str_contains( $owner, '--' )
			|| '' === $name
			|| strlen( $name ) > 100
			|| in_array( $name, array( '.', '..' ), true )
			|| str_ends_with( $name, '.git' )
		) {
			return self::invalid_url();
		}

		return new self( $owner, $name );
	}

	/**
	 * Return guidance without reflecting untrusted URL input.
	 *
	 * @return WP_Error
	 */
	private static function invalid_url(): WP_Error {
		return new WP_Error(
			'github_updater_invalid_repository_url',
			__( 'Enter an HTTPS GitHub repository URL such as https://github.com/owner/repository, without credentials, ports, queries, fragments or extra path segments.', 'wordpress-github-updater' )
		);
	}
}
