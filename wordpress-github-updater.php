<?php
/**
 * Plugin Name: 10T GitHub Updater
 * Description: Centralized management of WordPress plugins from public GitHub releases.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Network: true
 * Update URI: false
 * Text Domain: wordpress-github-updater
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep this file and Environment compatible with older PHP for safe diagnostics.
require_once __DIR__ . '/includes/class-environment.php';

register_activation_hook( __FILE__, array( Environment::class, 'activate' ) );

if ( '' !== Environment::requirements_error( PHP_VERSION, $GLOBALS['wp_version'] ) ) {
	add_action( 'admin_notices', array( Environment::class, 'render_notice' ) );
	add_action( 'network_admin_notices', array( Environment::class, 'render_notice' ) );
	return;
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! Environment::is_network_enabled( plugin_basename( __FILE__ ) ) ) {
			add_action( 'network_admin_notices', array( Environment::class, 'render_notice' ) );
			return;
		}

		require_once __DIR__ . '/includes/class-plugin.php';
		$plugin = new Plugin();
		$plugin->register_hooks();
	}
);
