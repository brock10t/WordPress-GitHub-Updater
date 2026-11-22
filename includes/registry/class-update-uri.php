<?php
/**
 * Update URI diagnostics for an explicitly selected repository.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use TenT\GitHubUpdater\GitHub\Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks repository identity and native hook routing without changing files.
 */
final class Update_URI {
	/**
	 * Diagnose raw installed-plugin headers against a validated repository.
	 *
	 * True means header compatibility only, not permission or update eligibility.
	 * Every error supplies the expected header value as unescaped diagnostic data.
	 *
	 * @param array<string, mixed> $plugin_data Installed plugin headers.
	 * @param Repository          $repository Explicitly selected repository.
	 * @return true|WP_Error
	 */
	public static function check( array $plugin_data, Repository $repository ): true|WP_Error {
		$expected = array( 'expected_update_uri' => $repository->url );
		if ( ! array_key_exists( 'UpdateURI', $plugin_data ) || ( is_string( $plugin_data['UpdateURI'] ) && '' === trim( $plugin_data['UpdateURI'], ' ' ) ) ) {
			return new WP_Error(
				'github_updater_missing_update_uri',
				__( 'This plugin has no Update URI header. Ask the plugin author to add the registered repository URL as its Update URI before enabling GitHub updates.', 'wordpress-github-updater' ),
				$expected
			);
		}

		$uri = $plugin_data['UpdateURI'];
		if ( is_string( $uri ) && 'false' === strtolower( trim( $uri, ' ' ) ) ) {
			return new WP_Error(
				'github_updater_disabled_update_uri',
				__( 'This plugin declares Update URI: false. GitHub updates require the plugin author to replace it with the registered repository URL.', 'wordpress-github-updater' ),
				$expected
			);
		}

		$actual = is_string( $uri ) ? Repository::from_url( $uri ) : null;
		if ( ! $actual instanceof Repository ) {
			return new WP_Error(
				'github_updater_invalid_update_uri',
				__( 'This plugin has an unsupported Update URI. Ask the plugin author to use the registered HTTPS GitHub repository root URL.', 'wordpress-github-updater' ),
				$expected
			);
		}

		if ( $actual->url !== $repository->url ) {
			return new WP_Error(
				'github_updater_update_uri_mismatch',
				__( 'This plugin identifies a different GitHub repository in its Update URI. Verify the selected repository with the plugin author before proceeding.', 'wordpress-github-updater' ),
				$expected
			);
		}

		// Match the expression used by wp_update_plugins(), including host case.
		$hostname = wp_parse_url( sanitize_url( $uri ), PHP_URL_HOST );
		if ( 'github.com' !== $hostname ) {
			return new WP_Error(
				'github_updater_update_uri_hostname_mismatch',
				__( 'The Update URI must use lowercase github.com so WordPress selects the GitHub update hook. Ask the plugin author to use the registered repository URL.', 'wordpress-github-updater' ),
				$expected
			);
		}

		return true;
	}
}
