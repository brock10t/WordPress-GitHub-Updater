<?php
/**
 * Plugin component coordinator.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns component hook registration as subsequent milestones add functionality.
 */
final class Plugin {
	/**
	 * Register the implemented components with WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		foreach ( array(
			'github/class-repository.php',
			'registry/class-registration.php',
			'registry/class-option-storage.php',
			'registry/class-installed-plugins.php',
			'registry/class-update-uri.php',
			'registry/class-registry-lock.php',
			'registry/class-registry.php',
			'updates/class-upgrade-guard.php',
			'updates/class-native-router.php',
			'updates/class-update-metadata.php',
		) as $file ) {
			require_once __DIR__ . '/' . $file;
		}

		$router = new Updates\Native_Router();
		$guard = new Updates\Upgrade_Guard( router: $router );
		$guard->register_hooks();
		$router->register_hooks();
		$metadata = new Updates\Update_Metadata();
		$metadata->register_hooks();

		if ( ! is_admin() ) {
			return;
		}
		require_once __DIR__ . '/admin/class-registry-page.php';
		$page = new Admin\Registry_Page();
		$page->register_hooks();
	}
}
