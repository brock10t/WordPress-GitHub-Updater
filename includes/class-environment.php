<?php
/**
 * Runtime requirements and activation guard.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses older PHP syntax so unsupported environments can display a useful error.
 */
final class Environment {
	const MINIMUM_PHP = '8.2';
	const MINIMUM_WP  = '6.6';

	/**
	 * Return an actionable error, or an empty string for supported versions.
	 *
	 * @param string $php_version PHP version.
	 * @param string $wp_version WordPress version.
	 * @return string
	 */
	public static function requirements_error( $php_version, $wp_version ) {
		if ( version_compare( $php_version, self::MINIMUM_PHP, '<' ) ) {
			return __( '10T GitHub Updater requires PHP 8.2 or newer. Please upgrade PHP.', 'wordpress-github-updater' );
		}

		if ( version_compare( $wp_version, self::MINIMUM_WP, '<' ) ) {
			return __( '10T GitHub Updater requires WordPress 6.6 or newer. Please upgrade WordPress.', 'wordpress-github-updater' );
		}

		return '';
	}

	/**
	 * Check shared activation without loading administration-only helpers.
	 *
	 * @param string $basename Canonical plugin basename.
	 * @return bool
	 */
	public static function is_network_enabled( $basename ) {
		if ( ! is_multisite() ) {
			return true;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ $basename ] );
	}

	/**
	 * Refuse unsupported activation, including programmatic site-only activation.
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		$error = self::requirements_error( PHP_VERSION, $GLOBALS['wp_version'] );

		if ( '' === $error && is_multisite() && ! $network_wide ) {
			$error = self::network_error();
		}

		if ( '' !== $error ) {
			wp_die( esc_html( $error ), esc_html__( 'Plugin activation failed', 'wordpress-github-updater' ), array( 'back_link' => true ) );
		}
	}

	/**
	 * Show runtime diagnostics only to users who can manage activation.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$capability = is_multisite() ? 'manage_network_plugins' : 'activate_plugins';
		if ( ! current_user_can( $capability ) ) {
			return;
		}

		$error = self::requirements_error( PHP_VERSION, $GLOBALS['wp_version'] );
		if ( '' === $error && ! self::is_network_enabled( plugin_basename( dirname( __DIR__ ) . '/wordpress-github-updater.php' ) ) ) {
			$error = self::network_error();
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/**
	 * Explain how to correct a site-only activation.
	 *
	 * @return string
	 */
	private static function network_error() {
		return __( '10T GitHub Updater requires network activation. Activate it in Network Admin under Plugins.', 'wordpress-github-updater' );
	}
}
