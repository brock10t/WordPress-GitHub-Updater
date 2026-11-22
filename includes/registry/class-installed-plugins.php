<?php
/**
 * Installed plugin discovery using WordPress's canonical identities.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads ordinary installed plugins without registering or executing their code.
 */
final class Installed_Plugins {
	/**
	 * Return all ordinary plugins with WordPress's exact basename keys and headers.
	 *
	 * Includes inactive and standalone plugins for useful administration guidance.
	 * Does not include must-use plugins or drop-ins. Header output needs escaping.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Resolve an exact installed basename eligible for the directory-only model.
	 *
	 * No basename normalization: an altered path must not select another plugin.
	 *
	 * @param string $basename Canonical basename selected from all().
	 * @return array<string, mixed>|WP_Error Raw WordPress plugin headers or error.
	 */
	public function get( string $basename ): array|WP_Error {
		$plugins = $this->all();
		if ( ! isset( $plugins[ $basename ] ) ) {
			return new WP_Error( 'github_updater_plugin_not_installed', __( 'The selected plugin is not installed under that exact basename. Refresh the plugin list and select it again.', 'wordpress-github-updater' ) );
		}

		if ( ! Registration::is_supported_basename( $basename ) ) {
			return new WP_Error( 'github_updater_unsupported_plugin_basename', __( 'GitHub Updater requires a plugin in its own directory with a supported main PHP filename. Standalone plugin files are not supported and will not be relocated.', 'wordpress-github-updater' ) );
		}

		return $plugins[ $basename ];
	}
}
