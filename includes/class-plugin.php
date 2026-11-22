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
		// Registry, administration and update hooks arrive in later milestones.
	}
}
