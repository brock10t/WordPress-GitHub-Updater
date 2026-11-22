<?php
/**
 * Managed-plugin registration data.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Registry;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable identity record; does not establish installation or repository trust.
 */
final readonly class Registration {
	/** @var string Installed directory name, independent of the repository name. */
	public string $plugin_slug;

	/** @var string Main PHP filename within the installed plugin directory. */
	public string $main_file;

	/** @var string Canonical HTTPS repository URL derived from owner/name. */
	public string $repository_url;

	/** @var string Required uploaded release ZIP filename. */
	public string $asset_name;

	/**
	 * Build a record from canonical identity components, not an arbitrary URL.
	 *
	 * @param string $plugin_basename  Installed plugin basename, preserving case.
	 * @param string $repository_owner GitHub repository owner.
	 * @param string $repository_name  GitHub repository name.
	 * @throws InvalidArgumentException When identity components are malformed.
	 */
	public function __construct(
		public string $plugin_basename,
		public string $repository_owner,
		public string $repository_name
	) {
		if ( ! self::is_supported_basename( $plugin_basename ) ) {
			throw new InvalidArgumentException( 'A registration requires a directory-based plugin basename such as plugin-slug/main.php.' );
		}
		$parts = explode( '/', $plugin_basename );

		foreach ( array( $repository_owner, $repository_name ) as $component ) {
			if ( ! preg_match( '/\A[A-Za-z0-9_.-]+\z/', $component ) || in_array( $component, array( '.', '..' ), true ) ) {
				throw new InvalidArgumentException( 'Repository owner and name must be nonempty GitHub identity components, not URLs or paths.' );
			}
		}

		$this->plugin_slug    = $parts[0];
		$this->main_file      = $parts[1];
		$this->repository_url = 'https://github.com/' . $repository_owner . '/' . $repository_name;
		$this->asset_name     = $this->plugin_slug . '.zip';
	}

	/**
	 * Export plain data suitable for WordPress option storage.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'plugin_basename'  => $this->plugin_basename,
			'plugin_slug'      => $this->plugin_slug,
			'main_file'        => $this->main_file,
			'repository_owner' => $this->repository_owner,
			'repository_name'  => $this->repository_name,
			'repository_url'   => $this->repository_url,
			'asset_name'       => $this->asset_name,
		);
	}

	/**
	 * Restore stored data without accepting contradictory package identities.
	 *
	 * Unknown fields are ignored; schema-version handling belongs to storage.
	 *
	 * @param array<string, mixed> $data Stored record.
	 * @return self
	 * @throws InvalidArgumentException When required data is missing or inconsistent.
	 */
	public static function from_array( array $data ): self {
		$fields = array( 'plugin_basename', 'plugin_slug', 'main_file', 'repository_owner', 'repository_name', 'repository_url', 'asset_name' );
		foreach ( $fields as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				throw new InvalidArgumentException( 'Registration field must be a string: ' . $field );
			}
		}

		$registration = new self( $data['plugin_basename'], $data['repository_owner'], $data['repository_name'] );
		foreach ( $registration->to_array() as $field => $expected ) {
			if ( $data[ $field ] !== $expected ) {
				throw new InvalidArgumentException( 'Registration field conflicts with its identity: ' . $field );
			}
		}

		return $registration;
	}

	/**
	 * Check the v1 directory-based identity format, not installation existence.
	 *
	 * @param string $basename Canonical installed basename to inspect.
	 * @return bool
	 */
	public static function is_supported_basename( string $basename ): bool {
		$parts = explode( '/', $basename );
		return 2 === count( $parts )
			&& self::is_path_segment( $parts[0] )
			&& self::is_path_segment( $parts[1] )
			&& str_ends_with( $parts[1], '.php' )
			&& '.php' !== $parts[1];
	}

	/**
	 * Reject ambiguous or unsafe path components without silently rewriting them.
	 *
	 * @param string $segment One literal installed path component.
	 * @return bool
	 */
	private static function is_path_segment( string $segment ): bool {
		return '' !== $segment
			&& ! in_array( $segment, array( '.', '..' ), true )
			&& ! str_contains( $segment, '\\' )
			&& ! str_contains( $segment, ':' )
			&& ! str_contains( $segment, '%' )
			&& ! preg_match( '/[\x00-\x1f\x7f]/', $segment );
	}
}
