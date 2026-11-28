<?php
/** Fail-closed native upgrader boundary with scoped session exceptions. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Updates;

use TenT\GitHubUpdater\Registry\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Denies ordinary managed upgrades; permits only an active bound native session. */
final class Upgrade_Guard {
	/** @param Registry $registry Read-only current managed identities. */
	public function __construct( private readonly Registry $registry = new Registry(), private readonly ?Native_Router $router = null ) {}

	/** Register in every supported request context, including cron/CLI. */
	public function register_hooks(): void {
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'pre_install' ), -1000, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), PHP_INT_MAX, 4 );
	}

	/** @return mixed Existing errors/unrelated values preserved; managed download denied outside a bound session. */
	public function pre_download( mixed $reply, mixed $package, mixed $upgrader, mixed $extra ): mixed {
		if ( ! $reply instanceof WP_Error && null !== ( $session = $this->session( $extra ) ) ) {
			return $session->download( $reply, $package, $upgrader, $extra );
		}
		$guarded = $reply instanceof WP_Error ? $reply : $this->guard( $reply, $extra );
		if ( $guarded instanceof WP_Error && 'github_updater_upgrade_validation_pending' === $guarded->get_error_code() && null !== $this->router ) {
			return $this->router->download( $reply, $package, $upgrader, $extra );
		}
		return $guarded;
	}

	/** @return mixed Runs before native deactivation/maintenance pre-install handlers. */
	public function pre_install( mixed $response, mixed $extra ): mixed {
		if ( ! $response instanceof WP_Error && null !== ( $session = $this->session( $extra ) ) ) {
			return $session->pre_install( $response, $extra );
		}
		if ( ! $response instanceof WP_Error && null !== $this->router && $this->router->matches( $extra ) ) { return $this->router->pre_install( $response, $extra ); }
		return $response instanceof WP_Error ? $response : $this->guard( $response, $extra );
	}

	/**
	 * Normal source selection also cannot copy a matching managed root.
	 * Upload installs have no basename before extraction: this additional guard
	 * protects replacement only, not their earlier untrusted extraction (M5).
	 *
	 * @return mixed Existing WP_Error/source or a fixed denial.
	 */
	public function source_selection( mixed $source, mixed $remote, mixed $upgrader, mixed $extra ): mixed {
		if ( $source instanceof WP_Error ) {
			return $source;
		}
		if ( null !== ( $session = $this->session( $extra ) ) ) {
			return $session->source( $source, $remote, $upgrader, $extra );
		}
		if ( null !== $this->router && $this->router->matches( $extra ) ) { return $this->router->source( $source, $remote, $upgrader, $extra ); }
		$result = $this->guard( $source, $extra );
		if ( $result instanceof WP_Error || ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? null ) ) {
			return $result;
		}
		$records = $this->registry->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		if ( ! is_string( $source ) ) {
			return $this->context_error();
		}
		$root = basename( rtrim( str_replace( '\\', '/', $source ), '/' ) );
		foreach ( $records as $record ) {
			if ( strtolower( $root ) === strtolower( $record->plugin_slug ) ) {
				return $this->pending();
			}
		}
		return $source;
	}

	private function session( mixed $extra ): ?Native_Session {
		if ( ! class_exists( Native_Session::class, false ) ) {
			return null;
		}
		$session = Native_Session::current();
		return null !== $session && $session->matches( $extra ) ? $session : null;
	}

	/** @return mixed No registry query for clearly unrelated themes/core/languages. */
	private function guard( mixed $value, mixed $extra ): mixed {
		if ( ! is_array( $extra ) ) {
			return $this->context_error();
		}
		$plugin = $extra['plugin'] ?? null;
		$type   = $extra['type'] ?? null;
		$action = $extra['action'] ?? null;
		if ( 'plugin' !== $type && null === $plugin ) {
			return $value;
		}
		$records = $this->registry->all();
		if ( $records instanceof WP_Error ) {
			return $records;
		}
		if ( array() === $records ) {
			return $value;
		}
		if ( null !== $plugin && ! is_string( $plugin ) ) {
			return $this->context_error();
		}
		if ( is_string( $plugin ) ) {
			foreach ( $records as $record ) {
				if ( strtolower( $plugin ) === strtolower( $record->plugin_basename ) || strtolower( dirname( $plugin ) ) === strtolower( $record->plugin_slug ) ) {
					return $this->pending();
				}
			}
		}
		if ( 'plugin' === $type && 'update' === $action && ( null === $plugin || '' === $plugin ) ) {
			return $this->context_error();
		}
		return $value;
	}

	/** @return WP_Error Fixed message with no package URLs/paths. */
	private function pending(): WP_Error {
		return new WP_Error( 'github_updater_upgrade_validation_pending', __( 'This registered plugin cannot be replaced until coordinated package validation is available. No update package was approved.', 'wordpress-github-updater' ) );
	}
	/** @return WP_Error Unknown plugin update identity must not bypass the boundary. */
	private function context_error(): WP_Error {
		return new WP_Error( 'github_updater_upgrade_context', __( 'The upgrader did not provide a supported plugin identity. The operation cannot be validated safely.', 'wordpress-github-updater' ) );
	}
}
