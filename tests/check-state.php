<?php
/** Offline presentation-boundary checks. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\GitHub {
	function time(): int { return $GLOBALS['check_state_time']; }
}
namespace {
	use TenT\GitHubUpdater\GitHub\Check_State;
	define( 'ABSPATH', '/unused/' );
	$GLOBALS['check_state_time'] = 1800000000;
	class WP_Error {
		public function __construct( private $code = '', private $message = '', private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
	function __( $text, $domain ) { return $text; }
	function check_state( bool $condition, string $description ): void {
		if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $description ); }
	}
	set_error_handler( function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
	require dirname( __DIR__ ) . '/includes/github/class-check-state.php';

	$groups = array(
		'unavailable' => 'api_timeout api_request_failed api_unavailable',
		'cooldown' => 'api_rate_limited',
		'busy' => 'release_refresh_busy',
		'incomplete' => 'release_scan_changed release_scan_incomplete',
		'blocked' => 'release_version_ambiguous plugin_not_installed unsupported_plugin_basename invalid_repository_url repository_not_public repository_identity_mismatch api_not_found api_access_denied api_resource_gone api_redirect_refused api_http_error missing_update_uri disabled_update_uri invalid_update_uri update_uri_mismatch update_uri_hostname_mismatch unsupported_release_tag unsupported_installed_version release_asset_missing release_asset_ambiguous api_invalid_response api_response_too_large invalid_release_metadata invalid_asset_metadata release_asset_url_mismatch release_cache_clear_failed release_cache_lock_unavailable release_cache_lock_release_failed release_cooldown_write_failed invalid_api_pagination',
	);
	$count = 0;
	foreach ( $groups as $status => $suffixes ) {
		foreach ( explode( ' ', $suffixes ) as $suffix ) {
			$code = 'github_updater_' . $suffix;
			$state = Check_State::from_error( new WP_Error( $code, '<script>REMOTE_SECRET</script>', array( 'package' => 'https://evil.example/REMOTE_SECRET', 'retryable' => true ) ) );
			check_state( $state->status === $status && $state->code === $code && $state->is_error(), $code . ' remains a distinct failed check' );
			check_state( '' !== $state->title && '' !== $state->message && '' !== $state->guidance, 'Actionable fixed copy' );
			check_state( ! str_contains( json_encode( $state->to_array() ), 'REMOTE_SECRET' ), 'Untrusted remote data excluded' );
			++$count;
		}
	}
	foreach ( array( '', '<script>UNKNOWN</script>', 123 ) as $code ) {
		$state = Check_State::from_error( new WP_Error( $code, 'unsafe', array( 'retryable' => true, 'retry_at' => 1800000060 ) ) );
		check_state( 'unknown_error' === $state->status && 'github_updater_unknown_error' === $state->code && ! $state->retry_suggested_now() && null === $state->retry_at, 'Unknown errors fail conservatively' );
	}
	foreach ( array( null, '1800000060', true, -1, 0, 1800086401, array() ) as $deadline ) {
		$state = Check_State::from_error( new WP_Error( 'github_updater_api_rate_limited', '', array( 'retry_at' => $deadline ) ) );
		check_state( 1800000060 === $state->retry_at && ! $state->retry_suggested_now(), 'Invalid rate deadline uses bounded fallback' );
	}
	$state = Check_State::from_error( new WP_Error( 'github_updater_api_rate_limited', '', array( 'retry_at' => 1800086400 ) ) );
	check_state( 1800086400 === $state->retry_at, 'Maximum supported deadline retained' );
	$state = Check_State::from_error( new WP_Error( 'github_updater_api_unavailable', '', array( 'retry_at' => 1800000120 ) ) );
	check_state( 'cooldown' === $state->status && ! $state->retry_suggested_now(), 'Service retry deadline observed' );
	$GLOBALS['check_state_time'] += 120;
	check_state( $state->retry_suggested_now() && $state->is_error(), 'Expired hint never becomes a successful result' );
	$expired = Check_State::from_error( new WP_Error( 'github_updater_api_rate_limited', '', array( 'retry_at' => 1800000060 ) ) );
	check_state( 'unavailable' === $expired->status && $expired->is_error() && $expired->retry_suggested_now(), 'Expired stored rate error suggests a new check' );
	check_state( Check_State::from_error( new WP_Error( 'github_updater_release_scan_changed' ) )->retry_suggested_now(), 'Changed scan can be retried' );
	check_state( ! Check_State::from_error( new WP_Error( 'github_updater_release_scan_incomplete' ) )->retry_suggested_now(), 'Bound exhaustion requires review' );
	foreach ( array( Check_State::no_releases(), Check_State::no_update() ) as $healthy ) {
		check_state( ! $healthy->is_error() && ! $healthy->retry_suggested_now(), 'Explicit successful empty outcomes' );
		check_state( array( 'status', 'code', 'severity', 'title', 'message', 'guidance', 'retryable', 'retry_at' ) === array_keys( $healthy->to_array() ), 'View contract contains no package or action fields' );
	}
	check_state( Check_State::no_releases()->status !== Check_State::no_update()->status, 'No releases differs from no newer release' );
	try {
		$state->status = 'no_update';
		throw new \RuntimeException( 'Mutable state' );
	} catch ( \Error $expected ) {}
	echo 'PASS: check states (' . $count . " known errors), retry hints, untrusted-data exclusion and healthy outcomes.\n";
}
