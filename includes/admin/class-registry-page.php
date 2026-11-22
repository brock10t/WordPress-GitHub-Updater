<?php
/**
 * Initial native administration controls for explicit registrations.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Admin;

use TenT\GitHubUpdater\Registry\Installed_Plugins;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registry screen and POST handlers; no installation or update actions. */
final class Registry_Page {
	public const SLUG = 'wordpress-github-updater';

	/**
	 * @param Registry          $registry  Explicit registry operations.
	 * @param Installed_Plugins $installed Installed plugin listing.
	 */
	public function __construct(
		private readonly Registry $registry = new Registry(),
		private readonly Installed_Plugins $installed = new Installed_Plugins()
	) {}

	/** Register only the correct administration context and authenticated routes. */
	public function register_hooks(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_menu' ) );
		$prefix = is_multisite() ? 'network_admin_edit_' : 'admin_post_';
		add_action( $prefix . '10t_gu_register', array( $this, 'handle_register' ) );
		add_action( $prefix . '10t_gu_remove', array( $this, 'handle_remove' ) );
	}

	/** Add a native submenu only for users able to perform registry mutations. */
	public function add_menu(): void {
		if ( $this->can_manage() ) {
			add_plugins_page( __( 'GitHub Updater', 'wordpress-github-updater' ), __( 'GitHub Updater', 'wordpress-github-updater' ), 'update_plugins', self::SLUG, array( $this, 'render' ) );
		}
	}

	/** Process a registration POST and redirect to the fixed management page. */
	public function handle_register(): void {
		$this->handle( 'register' );
	}

	/** Process a record-removal POST and redirect to the fixed management page. */
	public function handle_remove(): void {
		$this->handle( 'remove' );
	}

	/**
	 * Validate the transport/security boundary before invoking registry operations.
	 *
	 * Kept separate from redirects so integration checks can inspect results.
	 * Values are unslashed and validated without rewriting canonical basenames.
	 *
	 * @param string               $operation Register or remove.
	 * @param array<string, mixed> $post      Slashed WordPress POST values.
	 * @param string               $method    HTTP request method.
	 * @return Registration|true|WP_Error
	 */
	public function process_request( string $operation, array $post, string $method ): Registration|true|WP_Error {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'github_updater_admin_forbidden', __( 'You cannot manage GitHub registrations in this administration context.', 'wordpress-github-updater' ) );
		}
		if ( 'POST' !== $method || ! in_array( $operation, array( 'register', 'remove' ), true ) ) {
			return new WP_Error( 'github_updater_invalid_request_method', __( 'Submit a registration form using POST.', 'wordpress-github-updater' ) );
		}
		if ( ! isset( $post['_wpnonce'] ) || ! is_string( $post['_wpnonce'] ) ) {
			return new WP_Error( 'github_updater_invalid_nonce', __( 'The form could not be verified. Refresh the page and try again.', 'wordpress-github-updater' ) );
		}
		foreach ( array( 'plugin_basename' ) as $field ) {
			if ( ! isset( $post[ $field ] ) || ! is_string( $post[ $field ] ) ) {
				return $this->invalid_input();
			}
		}
		$basename = wp_unslash( $post['plugin_basename'] );
		$nonce    = wp_unslash( $post['_wpnonce'] );
		$action   = '10t_gu_' . $operation . ( 'remove' === $operation ? '_' . $basename : '' );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return new WP_Error( 'github_updater_invalid_nonce', __( 'The form expired or could not be verified. Refresh the page and try again.', 'wordpress-github-updater' ) );
		}
		if ( 'remove' === $operation ) {
			return $this->registry->remove( $basename );
		}
		if ( ! isset( $post['repository_url'] ) || ! is_string( $post['repository_url'] ) || ( isset( $post['allow_pending'] ) && ! is_string( $post['allow_pending'] ) ) ) {
			return $this->invalid_input();
		}

		return $this->registry->register( $basename, wp_unslash( $post['repository_url'] ), isset( $post['allow_pending'] ) && '1' === $post['allow_pending'] );
	}

	/** Render the registered list and a small native HTML registration form. */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You cannot manage GitHub registrations here.', 'wordpress-github-updater' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'GitHub Updater', 'wordpress-github-updater' ) . '</h1>';
		$notice = get_site_transient( $this->notice_key() );
		delete_site_transient( $this->notice_key() );
		if ( is_array( $notice ) && isset( $notice['message'] ) && is_string( $notice['message'] ) ) {
			$this->notice( $notice['message'], isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error' );
		}
		echo '<p>' . esc_html__( 'Associate installed plugins with public GitHub repositories. Repository availability and release checks will be added later.', 'wordpress-github-updater' ) . '</p>';
		$records = $this->registry->all();
		if ( $records instanceof WP_Error ) {
			$this->notice( $records->get_error_message(), 'error' );
			echo '</div>';
			return;
		}
		$plugins = $this->installed->all();
		echo '<h2>' . esc_html__( 'Registered plugins', 'wordpress-github-updater' ) . '</h2><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Plugin', 'wordpress-github-updater' ), __( 'Installed version', 'wordpress-github-updater' ), __( 'Repository', 'wordpress-github-updater' ), __( 'Header status', 'wordpress-github-updater' ), __( 'Action', 'wordpress-github-updater' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $records as $basename => $record ) {
			$headers = $plugins[ $basename ] ?? array();
			$status  = $this->registry->check_update_uri( $basename );
			echo '<tr><td><strong>' . esc_html( $headers['Name'] ?? $basename ) . '</strong><br><code>' . esc_html( $basename ) . '</code></td><td>' . esc_html( $headers['Version'] ?? '—' ) . '</td>';
			echo '<td><a href="' . esc_url( $record->repository_url ) . '">' . esc_html( $record->repository_url ) . '</a></td><td>';
			if ( $status instanceof WP_Error ) {
				echo esc_html( $status->get_error_message() );
				$data = $status->get_error_data();
				if ( is_array( $data ) && isset( $data['expected_update_uri'] ) && is_string( $data['expected_update_uri'] ) ) {
					echo '<br><code>' . esc_html( 'Update URI: ' . $data['expected_update_uri'] ) . '</code>';
				}
			} else {
				echo esc_html__( 'Compatible header; release checks are not available yet.', 'wordpress-github-updater' );
			}
			echo '</td><td><form method="post" action="' . esc_url( $this->action_url( 'remove' ) ) . '">';
			$this->action_fields( 'remove', $basename );
			echo '<input type="hidden" name="plugin_basename" value="' . esc_attr( $basename ) . '">';
			submit_button( __( 'Remove registration', 'wordpress-github-updater' ), 'secondary', 'submit', false );
			echo '</form></td></tr>';
		}
		if ( array() === $records ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No plugins are registered.', 'wordpress-github-updater' ) . '</td></tr>';
		}
		echo '</tbody></table><p>' . esc_html__( 'Removing a registration does not uninstall or deactivate the plugin.', 'wordpress-github-updater' ) . '</p>';
		$this->registration_form( $plugins, $records );
		echo '</div>';
	}

	/** @return bool Whether the current admin context and capabilities allow management. */
	private function can_manage(): bool {
		return is_admin() && current_user_can( 'update_plugins' )
			&& ( is_multisite() ? is_network_admin() && current_user_can( 'manage_network_plugins' ) : ! is_network_admin() );
	}

	/** @param string $operation Register or remove. */
	private function handle( string $operation ): void {
		$result = $this->process_request( $operation, $_POST, $_SERVER['REQUEST_METHOD'] ?? '' );
		if ( $result instanceof WP_Error && in_array( $result->get_error_code(), array( 'github_updater_admin_forbidden', 'github_updater_invalid_nonce', 'github_updater_invalid_request_method' ), true ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}
		$message = $result instanceof WP_Error ? $result->get_error_message() : ( 'remove' === $operation ? __( 'Registration removed. The installed plugin was not changed.', 'wordpress-github-updater' ) : __( 'Registration saved. Review its header status below.', 'wordpress-github-updater' ) );
		set_site_transient( $this->notice_key(), array( 'message' => $message, 'type' => $result instanceof WP_Error ? 'error' : 'success' ), MINUTE_IN_SECONDS );
		$url = is_multisite() ? network_admin_url( 'plugins.php?page=' . self::SLUG ) : admin_url( 'plugins.php?page=' . self::SLUG );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins Installed headers.
	 * @param array<string, Registration>        $records Registered records.
	 */
	private function registration_form( array $plugins, array $records ): void {
		echo '<h2>' . esc_html__( 'Register an installed plugin', 'wordpress-github-updater' ) . '</h2><form method="post" action="' . esc_url( $this->action_url( 'register' ) ) . '">';
		$this->action_fields( 'register', '' );
		echo '<p><label for="10t-gu-plugin">' . esc_html__( 'Installed plugin', 'wordpress-github-updater' ) . '</label><br><select id="10t-gu-plugin" name="plugin_basename" required><option value="">' . esc_html__( 'Select a plugin', 'wordpress-github-updater' ) . '</option>';
		$updater = plugin_basename( dirname( __DIR__, 2 ) . '/wordpress-github-updater.php' );
		foreach ( $plugins as $basename => $headers ) {
			if ( isset( $records[ $basename ] ) || strtolower( dirname( $basename ) ) === strtolower( dirname( $updater ) ) ) {
				continue;
			}
			$supported = Registration::is_supported_basename( $basename );
			$label     = ( $headers['Name'] ?? $basename ) . ' (' . $basename . ')' . ( $supported ? '' : ' — ' . __( 'unsupported standalone file or path', 'wordpress-github-updater' ) );
			echo '<option value="' . esc_attr( $basename ) . '"' . ( $supported ? '' : ' disabled' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p><label for="10t-gu-repository">' . esc_html__( 'GitHub repository URL', 'wordpress-github-updater' ) . '</label><br><input class="regular-text" id="10t-gu-repository" type="url" name="repository_url" placeholder="https://github.com/owner/repository" required></p>';
		echo '<p><label><input type="checkbox" name="allow_pending" value="1"> ' . esc_html__( 'If the Update URI header is missing, save a pending registration. GitHub updates remain blocked until the header is corrected.', 'wordpress-github-updater' ) . '</label></p>';
		echo '<p>' . esc_html__( 'Plugins must use their own directory. Explicitly disabled or conflicting Update URI headers must be corrected by the plugin author before registration.', 'wordpress-github-updater' ) . '</p>';
		submit_button( __( 'Register plugin', 'wordpress-github-updater' ) );
		echo '</form>';
	}

	/** @param string $operation Register or remove. @return string */
	private function action_url( string $operation ): string {
		return is_multisite() ? network_admin_url( 'edit.php?action=10t_gu_' . $operation ) : admin_url( 'admin-post.php' );
	}

	/** @param string $operation Register or remove. @param string $basename Removal identity. */
	private function action_fields( string $operation, string $basename ): void {
		if ( ! is_multisite() ) {
			echo '<input type="hidden" name="action" value="10t_gu_' . esc_attr( $operation ) . '">';
		}
		wp_nonce_field( '10t_gu_' . $operation . ( 'remove' === $operation ? '_' . $basename : '' ) );
	}

	/** @return string Per-user notice key, network-scoped on Multisite. */
	private function notice_key(): string {
		return '10t_gu_notice_' . get_current_user_id();
	}

	/** @param string $message Plain diagnostic. @param string $type Success or error. */
	private function notice( string $message, string $type ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** @return WP_Error */
	private function invalid_input(): WP_Error {
		return new WP_Error( 'github_updater_invalid_admin_input', __( 'The form contains invalid fields. Refresh the page and select the plugin again.', 'wordpress-github-updater' ) );
	}
}
