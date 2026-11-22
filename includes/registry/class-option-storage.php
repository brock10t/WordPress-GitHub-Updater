<?php
/**
 * Single-site and network registry persistence.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use InvalidArgumentException;
use stdClass;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists complete registry snapshots using the current installation context.
 *
 * Callers own authorization, repository validation and registration operations.
 */
final class Option_Storage {
	public const OPTION_NAME    = 'wordpress_github_updater_registry';
	public const SCHEMA_VERSION = 1;

	/**
	 * Read the registry without creating an option or discarding invalid records.
	 *
	 * @return array<string, Registration>|WP_Error Records keyed by exact basename.
	 */
	public function load(): array|WP_Error {
		$missing = new stdClass();
		$data    = $this->read_option( $missing );
		if ( $data === $missing ) {
			return array();
		}
		if ( ! is_array( $data ) || ! isset( $data['schema_version'] ) || ! is_int( $data['schema_version'] ) ) {
			return new WP_Error( 'github_updater_invalid_registry', __( 'The GitHub Updater registry has an invalid storage format. Existing data was preserved.', 'wordpress-github-updater' ) );
		}
		if ( self::SCHEMA_VERSION !== $data['schema_version'] ) {
			return new WP_Error( 'github_updater_unsupported_registry_schema', __( 'The GitHub Updater registry uses an unsupported schema version. Existing data was preserved.', 'wordpress-github-updater' ) );
		}
		if ( ! isset( $data['registrations'] ) || ! is_array( $data['registrations'] ) ) {
			return new WP_Error( 'github_updater_invalid_registry', __( 'The GitHub Updater registry is missing its registration list. Existing data was preserved.', 'wordpress-github-updater' ) );
		}

		$registrations = array();
		foreach ( $data['registrations'] as $basename => $record ) {
			if ( ! is_string( $basename ) || ! is_array( $record ) ) {
				return $this->invalid_record();
			}
			try {
				$registration = Registration::from_array( $record );
			} catch ( InvalidArgumentException $error ) {
				return $this->invalid_record();
			}
			if ( $basename !== $registration->plugin_basename ) {
				return $this->invalid_record();
			}
			$registrations[ $basename ] = $registration;
		}

		return $registrations;
	}

	/**
	 * Save a complete validated snapshot; refuse to replace unreadable storage.
	 *
	 * This is not an atomic read-modify-write or a registration service.
	 *
	 * @param array<string, Registration> $registrations Records keyed by basename.
	 * @return true|WP_Error
	 */
	public function save( array $registrations ): true|WP_Error {
		$current = $this->load();
		if ( $current instanceof WP_Error ) {
			return $current;
		}

		$records = array();
		foreach ( $registrations as $basename => $registration ) {
			if ( ! $registration instanceof Registration || $basename !== $registration->plugin_basename ) {
				return $this->invalid_record();
			}
			$records[ $basename ] = $registration->to_array();
		}
		$data = array(
			'schema_version' => self::SCHEMA_VERSION,
			'registrations'  => $records,
		);

		$updated = is_multisite()
			? update_site_option( self::OPTION_NAME, $data )
			: update_option( self::OPTION_NAME, $data, false );

		// Both APIs return false for an unchanged value as well as failed writes.
		if ( $updated || $this->read_option( null ) === $data ) {
			return true;
		}

		return new WP_Error( 'github_updater_registry_write_failed', __( 'The GitHub Updater registry could not be saved. Please try again.', 'wordpress-github-updater' ) );
	}

	/**
	 * Use one shared option per network rather than individual blog options.
	 *
	 * @param mixed $default Value returned when the option is absent.
	 * @return mixed
	 */
	private function read_option( mixed $default ): mixed {
		return is_multisite()
			? get_site_option( self::OPTION_NAME, $default )
			: get_option( self::OPTION_NAME, $default );
	}

	/**
	 * Report malformed records without exposing untrusted stored contents.
	 *
	 * @return WP_Error
	 */
	private function invalid_record(): WP_Error {
		return new WP_Error( 'github_updater_invalid_registration_record', __( 'The GitHub Updater registry contains an invalid or inconsistent plugin identity. Existing data was preserved.', 'wordpress-github-updater' ) );
	}
}
