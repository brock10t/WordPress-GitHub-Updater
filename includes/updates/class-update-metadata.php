<?php
/**
 * Native third-party plugin update metadata.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Updates;

use TenT\GitHubUpdater\GitHub\Check_State;
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Check;
use TenT\GitHubUpdater\Registry\Installed_Plugins;
use TenT\GitHubUpdater\Registry\Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Offers metadata only; native replacement must independently validate the package. */
final class Update_Metadata {
	/** Compose existing registry and selection components without fetching at bootstrap. */
	public function __construct(
		private readonly Registry $registry = new Registry(),
		private readonly Installed_Plugins $installed = new Installed_Plugins()
	) {}

	/** Register outside admin too, for native cron and CLI update checks. */
	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'update' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Return the hook's version field; core supplies plugin/id/new_version.
	 * Never return WP_Error here: core treats any truthy value as update data.
	 * No force flag, native transient rewriting or auto-update opt-in override.
	 *
	 * @return mixed Unregistered input unchanged; registered metadata or false.
	 */
	public function update( mixed $update, mixed $plugin_data, mixed $plugin_file, mixed $locales ): mixed {
		if ( ! is_string( $plugin_file ) ) {
			return false;
		}
		$records = $this->registry->all();
		if ( $records instanceof WP_Error ) {
			return $this->failed( $plugin_file, $records );
		}
		if ( ! isset( $records[ $plugin_file ] ) ) {
			return $update;
		}
		$record = $records[ $plugin_file ];
		$ready  = $this->registry->check_update_uri( $plugin_file );
		if ( $ready instanceof WP_Error ) {
			return $this->failed( $plugin_file, $ready );
		}
		$headers = $this->installed->get( $plugin_file );
		if ( $headers instanceof WP_Error ) {
			return $this->failed( $plugin_file, $headers );
		}
		if ( ! $this->same_headers( $headers, $plugin_data ) ) {
			return $this->failed( $plugin_file, $this->changed() );
		}

		$this->load();
		$result = ( new Release_Check() )->get( $record );
		if ( $result instanceof WP_Error ) {
			return $this->failed( $plugin_file, $result );
		}

		// HTTP callbacks or another request may have changed registration/headers.
		$current = $this->registry->get( $plugin_file );
		$fresh   = $this->installed->get( $plugin_file );
		if ( $current instanceof WP_Error || $fresh instanceof WP_Error || $current->to_array() !== $record->to_array() || ! $this->same_headers( $headers, $fresh ) ) {
			return $this->failed( $plugin_file, $this->changed() );
		}
		$ready = $this->registry->check_update_uri( $plugin_file );
		if ( $ready instanceof WP_Error ) {
			return $this->failed( $plugin_file, $ready );
		}
		if ( $result instanceof Release_Candidate ) {
			return array(
				'slug'    => $record->plugin_slug,
				'version' => $result->version,
				'url'     => $record->repository_url,
				'package' => $result->asset->api_url,
			);
		}
		// Only a completed successful comparison can produce a native no_update row.
		if ( 'no_update' === $result->status ) {
			return array(
				'slug'    => $record->plugin_slug,
				'version' => $headers['Version'],
				'url'     => $record->repository_url,
			);
		}
		return false; // A completed empty release scan is not an available version.
	}

	/** Critical hook snapshot must match exact fresh installed values, without coercion. */
	private function same_headers( array $expected, mixed $actual ): bool {
		return is_array( $actual )
			&& is_string( $expected['Version'] ?? null )
			&& is_string( $expected['UpdateURI'] ?? null )
			&& ( $actual['Version'] ?? null ) === $expected['Version']
			&& ( $actual['UpdateURI'] ?? null ) === $expected['UpdateURI'];
	}

	/** No raw remote error text is exposed or persisted; future views can consume this. */
	private function failed( string $basename, WP_Error $error ): false {
		$this->load();
		do_action( 'wordpress_github_updater_update_check_failed', $basename, Check_State::from_error( $error ) );
		return false;
	}

	private function changed(): WP_Error {
		return new WP_Error( 'github_updater_update_snapshot_changed', __( 'The installed plugin or registration changed during the update check. Refresh before retrying.', 'wordpress-github-updater' ) );
	}

	/** Load concrete metadata dependencies lazily; never load package code for an offer. */
	private function load(): void {
		foreach ( array(
			'github/class-api-client.php',
			'github/class-stable-releases.php',
			'github/class-cached-releases.php',
			'github/class-release-tag.php',
			'github/class-version-comparison.php',
			'github/class-release-asset.php',
			'github/class-release-candidate.php',
			'github/class-release-selector.php',
			'github/class-check-state.php',
			'github/class-release-check.php',
		) as $file ) {
			require_once dirname( __DIR__ ) . '/' . $file;
		}
	}
}
