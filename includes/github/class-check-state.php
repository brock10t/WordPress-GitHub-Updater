<?php
/**
 * Safe presentation contract for GitHub metadata-check outcomes.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\GitHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes outcomes only; never authorizes updates or carries package URLs.
 */
final readonly class Check_State {
	/**
	 * @param string   $status    Stable outcome category.
	 * @param string   $code      Known machine diagnostic code.
	 * @param string   $severity  Native notice type.
	 * @param string   $title     Fixed translated heading.
	 * @param string   $message   Fixed translated description.
	 * @param string   $guidance  Fixed next-step guidance, never executable action.
	 * @param bool     $retryable Whether another check may resolve the failure.
	 * @param int|null $retry_at  UTC epoch deadline, not a scheduled retry.
	 */
	private function __construct(
		public string $status,
		public string $code,
		public string $severity,
		public string $title,
		public string $message,
		public string $guidance,
		public bool $retryable,
		public ?int $retry_at = null
	) {}

	/**
	 * Classify known errors without trusting remote messages or retryability.
	 *
	 * The original WP_Error remains the caller's diagnostic source. Arbitrary
	 * error text/data is not copied into this presentation object.
	 *
	 * @param WP_Error $error A failed check or prerequisite.
	 * @return self
	 */
	public static function from_error( WP_Error $error ): self {
		$code    = $error->get_error_code();
		$catalog = array(
			'github_updater_api_timeout'                       => array( 'connection', __( 'GitHub request timed out', 'wordpress-github-updater' ) ),
			'github_updater_api_request_failed'                => array( 'connection', __( 'Cannot reach GitHub', 'wordpress-github-updater' ) ),
			'github_updater_api_unavailable'                   => array( 'connection', __( 'GitHub temporarily unavailable', 'wordpress-github-updater' ) ),
			'github_updater_api_rate_limited'                  => array( 'cooldown', __( 'GitHub request limit reached', 'wordpress-github-updater' ) ),
			'github_updater_release_refresh_busy'              => array( 'busy', __( 'Check already in progress', 'wordpress-github-updater' ) ),
			'github_updater_invalid_repository_url'            => array( 'repository', __( 'Invalid GitHub repository URL', 'wordpress-github-updater' ) ),
			'github_updater_repository_not_public'             => array( 'repository', __( 'Public repository required', 'wordpress-github-updater' ) ),
			'github_updater_repository_identity_mismatch'      => array( 'repository', __( 'Repository identity mismatch', 'wordpress-github-updater' ) ),
			'github_updater_api_not_found'                     => array( 'repository', __( 'Public repository not found', 'wordpress-github-updater' ) ),
			'github_updater_api_access_denied'                 => array( 'repository', __( 'GitHub access denied', 'wordpress-github-updater' ) ),
			'github_updater_api_resource_gone'                 => array( 'repository', __( 'GitHub resource no longer available', 'wordpress-github-updater' ) ),
			'github_updater_api_redirect_refused'              => array( 'repository', __( 'Repository URL needs review', 'wordpress-github-updater' ) ),
			'github_updater_api_http_error'                    => array( 'repository', __( 'GitHub request rejected', 'wordpress-github-updater' ) ),
			'github_updater_missing_update_uri'                => array( 'header', __( 'Pending Update URI header', 'wordpress-github-updater' ) ),
			'github_updater_disabled_update_uri'               => array( 'header', __( 'Updates disabled by plugin header', 'wordpress-github-updater' ) ),
			'github_updater_invalid_update_uri'                => array( 'header', __( 'Unsupported Update URI', 'wordpress-github-updater' ) ),
			'github_updater_update_uri_mismatch'               => array( 'header', __( 'Update URI repository conflict', 'wordpress-github-updater' ) ),
			'github_updater_update_uri_hostname_mismatch'      => array( 'header', __( 'Update URI routing mismatch', 'wordpress-github-updater' ) ),
			'github_updater_release_version_ambiguous'          => array( 'version', __( 'Ambiguous newer release version', 'wordpress-github-updater' ) ),
			'github_updater_plugin_not_installed'               => array( 'installed', __( 'Registered plugin is not installed', 'wordpress-github-updater' ) ),
			'github_updater_unsupported_plugin_basename'         => array( 'installed', __( 'Unsupported installed plugin identity', 'wordpress-github-updater' ) ),
			'github_updater_unsupported_release_tag'           => array( 'version', __( 'Unsupported release tag', 'wordpress-github-updater' ) ),
			'github_updater_unsupported_installed_version'     => array( 'version', __( 'Unsupported installed version', 'wordpress-github-updater' ) ),
			'github_updater_release_asset_missing'             => array( 'asset', __( 'Required plugin ZIP missing', 'wordpress-github-updater' ) ),
			'github_updater_release_asset_ambiguous'           => array( 'asset', __( 'Ambiguous plugin ZIP assets', 'wordpress-github-updater' ) ),
			'github_updater_api_invalid_response'              => array( 'metadata', __( 'Invalid GitHub response', 'wordpress-github-updater' ) ),
			'github_updater_api_response_too_large'            => array( 'metadata', __( 'GitHub response exceeds limit', 'wordpress-github-updater' ) ),
			'github_updater_invalid_release_metadata'          => array( 'metadata', __( 'Invalid release metadata', 'wordpress-github-updater' ) ),
			'github_updater_invalid_asset_metadata'            => array( 'metadata', __( 'Invalid ZIP asset metadata', 'wordpress-github-updater' ) ),
			'github_updater_release_asset_url_mismatch'        => array( 'metadata', __( 'ZIP asset identity mismatch', 'wordpress-github-updater' ) ),
			'github_updater_release_scan_changed'              => array( 'incomplete', __( 'Release scan changed', 'wordpress-github-updater' ) ),
			'github_updater_release_scan_incomplete'           => array( 'incomplete', __( 'Release scan incomplete', 'wordpress-github-updater' ) ),
			'github_updater_release_cache_clear_failed'        => array( 'storage', __( 'Cache could not be cleared', 'wordpress-github-updater' ) ),
			'github_updater_release_cache_lock_unavailable'    => array( 'storage', __( 'Refresh coordination unavailable', 'wordpress-github-updater' ) ),
			'github_updater_release_cache_lock_release_failed' => array( 'storage', __( 'Refresh completion needs review', 'wordpress-github-updater' ) ),
			'github_updater_release_cooldown_write_failed'     => array( 'storage', __( 'Cooldown could not be preserved', 'wordpress-github-updater' ) ),
			'github_updater_invalid_api_pagination'            => array( 'configuration', __( 'Invalid GitHub request configuration', 'wordpress-github-updater' ) ),
		);
		$definition = is_string( $code ) ? ( $catalog[ $code ] ?? null ) : null;
		$group      = $definition[0] ?? null;
		$title      = $definition[1] ?? '';
		$data     = $error->get_error_data();
		$retry_at = is_array( $data ) ? ( $data['retry_at'] ?? null ) : null;
		if ( ! is_int( $retry_at ) || $retry_at <= 0 || $retry_at > time() + 86400 ) {
			$retry_at = null;
		}
		if ( 'cooldown' === $group || ( 'github_updater_api_unavailable' === $code && null !== $retry_at && $retry_at > time() ) ) {
			// Malformed/missing rate hints must not invite immediate repeated checks.
			if ( null === $retry_at ) {
				$retry_at = time() + 60;
			}
			if ( $retry_at <= time() ) {
				return new self( 'unavailable', $code, 'warning', $title, __( 'The last check was paused by GitHub.', 'wordpress-github-updater' ), __( 'The recorded wait has ended. Check again to retrieve current information; the earlier failure is not an up-to-date result.', 'wordpress-github-updater' ), true, $retry_at );
			}
			return new self( 'cooldown', $code, 'warning', __( 'GitHub cooldown', 'wordpress-github-updater' ), __( 'GitHub has requested a pause before another check.', 'wordpress-github-updater' ), __( 'Wait until the retry deadline. Manual refresh does not override a recorded server cooldown.', 'wordpress-github-updater' ), true, $retry_at );
		}
		if ( 'connection' === $group ) {
			return new self( 'unavailable', $code, 'warning', $title, __( 'The check could not retrieve current GitHub release information.', 'wordpress-github-updater' ), __( 'Check server connectivity or wait for GitHub to recover, then check again. This does not mean the plugin is up to date.', 'wordpress-github-updater' ), true );
		}
		if ( 'busy' === $group ) {
			return new self( 'busy', $code, 'info', __( 'Check already in progress', 'wordpress-github-updater' ), __( 'Another request is refreshing this repository.', 'wordpress-github-updater' ), __( 'Allow the current check to finish, then refresh the status.', 'wordpress-github-updater' ), true );
		}
		$guidance = match ( $group ) {
			'repository' => __( 'Verify the registered public repository URL and access. Repository moves require review; do not substitute another plugin identity.', 'wordpress-github-updater' ),
			'header' => __( 'Review the installed plugin Update URI with its author. Missing headers may remain pending; do not edit plugin files automatically.', 'wordpress-github-updater' ),
			'version' => __( 'Ask the plugin author to use supported stable dotted numeric tags and Version headers. Unsupported versions are not treated as zero.', 'wordpress-github-updater' ),
			'asset' => __( 'Ask the release publisher to upload exactly one ZIP with the registered plugin directory name. Source archives are not substitutes.', 'wordpress-github-updater' ),
			'metadata' => __( 'Review the release metadata with the publisher. No package can be offered from malformed or mismatched metadata.', 'wordpress-github-updater' ),
			'incomplete' => __( 'The release scan did not complete consistently. Check again later; repositories beyond the supported scan bound need review.', 'wordpress-github-updater' ),
			'storage' => __( 'Ask the site administrator to review cache storage and database locking. Refresh the status before retrying; a prior cache change may have completed.', 'wordpress-github-updater' ),
			'installed' => __( 'Refresh the installed plugin list and review the exact registered basename. Plugins must use their own directory; no files will be moved automatically.', 'wordpress-github-updater' ),
			'configuration' => __( 'Ask the site administrator to review the updater request configuration.', 'wordpress-github-updater' ),
			default => __( 'The check failed unexpectedly. Review diagnostics with the site administrator before taking an update action.', 'wordpress-github-updater' ),
		};
		if ( null === $group ) {
			return new self( 'unknown_error', 'github_updater_unknown_error', 'error', __( 'Check failed', 'wordpress-github-updater' ), __( 'The result of this check is unknown.', 'wordpress-github-updater' ), $guidance, false );
		}
		return new self( 'incomplete' === $group ? 'incomplete' : 'blocked', $code, 'warning', $title, __( 'Release eligibility could not be established.', 'wordpress-github-updater' ), $guidance, 'github_updater_release_scan_changed' === $code );
	}

	/**
	 * Use only after a completed validated scan returned no stable candidates.
	 *
	 * @return self
	 */
	public static function no_releases(): self {
		return new self( 'no_releases', 'github_updater_no_stable_releases', 'info', __( 'No stable releases', 'wordpress-github-updater' ), __( 'The completed check found no stable published releases.', 'wordpress-github-updater' ), __( 'Publish a stable GitHub Release with the required uploaded plugin ZIP.', 'wordpress-github-updater' ), false );
	}

	/**
	 * Use only after a successful completed comparison found no newer release.
	 *
	 * @return self
	 */
	public static function no_update(): self {
		return new self( 'no_update', 'github_updater_no_newer_release', 'info', __( 'No newer stable release', 'wordpress-github-updater' ), __( 'The checked release data does not identify a newer stable version.', 'wordpress-github-updater' ), __( 'No update action is needed for the checked release data.', 'wordpress-github-updater' ), false );
	}

	/** @return bool True for a failed/incomplete check, never a healthy empty result. */
	public function is_error(): bool {
		return ! in_array( $this->status, array( 'no_releases', 'no_update' ), true );
	}

	/** @return bool Retry suggestion only, not an authorization gate; no request is scheduled. */
	public function retry_suggested_now(): bool {
		return $this->retryable && ( null === $this->retry_at || $this->retry_at <= time() );
	}

	/** @return array<string, string|bool|int|null> Plain view data; escape text on output. */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
