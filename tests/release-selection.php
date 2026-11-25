<?php
/** Production release selection, composed from real metadata components. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\GitHub\Check_State;
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Check;
use TenT\GitHubUpdater\GitHub\Release_Selector;
use TenT\GitHubUpdater\Registry\Registration;

require __DIR__ . '/support/metadata-environment.php';
foreach ( array( 'release-candidate', 'release-selector', 'release-check' ) as $component ) {
	require dirname( __DIR__ ) . '/includes/github/class-' . $component . '.php';
}
require dirname( __DIR__ ) . '/includes/registry/class-installed-plugins.php';
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$wpdb = new Lock_Database_Double();
$registration = new Registration( 'installed-plugin/main.php', 'owner', 'repository' );
$checker = new Release_Check();
$high = matrix_release( 1, 'v9.0' );
$low = array_replace( matrix_release( 2, 'v1.1' ), array( 'published_at' => '2026-11-23T12:00:00Z' ) );
matrix_reset( matrix_complete( array( $low, $high ) ) );
$result = $checker->get( $registration );
matrix_assert( $result instanceof Release_Candidate && 1 === $result->release_id && '9.0' === $result->version && 'v9.0' === $result->tag_name && 'installed-plugin/main.php' === $result->plugin_basename, 'Highest numeric version selected despite later lower publication' );
matrix_assert( str_contains( $result->asset->download_url, '/v9.0/installed-plugin.zip' ), 'Original tag and registered ZIP retained' );
try { $result->version = '99.0'; throw new RuntimeException( 'Mutable candidate' ); } catch ( Error $expected ) {}
$matrix_plugins['installed-plugin/main.php']['Version'] = '9.0.0';
matrix_assert( 'no_update' === $checker->get( $registration )->status, 'Equal version after installed-header change gives no update using same cache' );
$matrix_plugins['installed-plugin/main.php']['Version'] = '10.0';
matrix_assert( 'no_update' === $checker->get( $registration )->status && 3 === count( $matrix_requests ), 'Newly installed higher version prevents downgrade without HTTP' );
foreach ( array( '', 'unknown', '1.0-beta', null, array() ) as $version ) {
	$matrix_plugins['installed-plugin/main.php']['Version'] = $version;
	matrix_error( $checker->get( $registration ), 'unsupported_installed_version', 'blocked' );
}
$matrix_plugins['installed-plugin/main.php']['Version'] = '1.0';
foreach ( array( '' => 'missing_update_uri', 'false' => 'disabled_update_uri', 'https://github.com/other/repo' => 'update_uri_mismatch', 'https://GITHUB.COM/owner/repository' => 'update_uri_hostname_mismatch' ) as $uri => $error ) {
	$matrix_plugins['installed-plugin/main.php']['UpdateURI'] = $uri;
	matrix_error( $checker->get( $registration ), $error, 'blocked' );
}
unset( $matrix_plugins['installed-plugin/main.php'] );
matrix_error( $checker->get( $registration ), 'plugin_not_installed', 'blocked' );
matrix_assert( 3 === count( $matrix_requests ), 'Invalid installed prerequisites never fetch even with warm cache' );

foreach ( array( array(), array( matrix_release( 1, 'v1.0' ) ), array( matrix_release( 1, 'v0.9' ) ) ) as $releases ) {
	matrix_reset( matrix_complete( $releases ) );
	$result = $checker->get( $registration );
	matrix_assert( $result instanceof Check_State && ! $result->is_error() && ( array() === $releases ? 'no_releases' : 'no_update' ) === $result->status, 'Successful empty/equal/older outcomes from production composition' );
}
// Highest release failures never silently fall back to a valid lower upgrade.
foreach ( array( 'missing', 'duplicate', 'wrong_url', 'state' ) as $failure ) {
	$bad = $high;
	if ( 'missing' === $failure ) { $bad['assets'] = array(); }
	if ( 'duplicate' === $failure ) { $bad['assets'][] = $bad['assets'][0]; }
	if ( 'wrong_url' === $failure ) { $bad['assets'][0]['browser_download_url'] .= '?unsafe=1'; }
	if ( 'state' === $failure ) { $bad['assets'][0]['state'] = 'new'; }
	matrix_reset( matrix_complete( array( $bad, $low ) ) );
	$code = array( 'missing' => 'release_asset_missing', 'duplicate' => 'release_asset_ambiguous', 'wrong_url' => 'release_asset_url_mismatch', 'state' => 'invalid_asset_metadata' )[ $failure ];
	matrix_error( $checker->get( $registration ), $code, 'blocked' );
	matrix_error( $checker->get( $registration ), $code, 'blocked' );
	matrix_assert( 3 === count( $matrix_requests ), 'Warm metadata repeats downstream checks without caching eligibility' );
}
foreach ( array( 'v1.1-beta', 'latest', '01.0' ) as $tag ) {
	matrix_reset( matrix_complete( array( $high, matrix_release( 2, $tag ) ) ) );
	matrix_error( $checker->get( $registration ), 'unsupported_release_tag', 'blocked' );
}
// Numeric equivalence includes optional v and trailing zero padding.
foreach ( array( '9.0', 'v9.0.0', '9.0.0.0' ) as $tag ) {
	matrix_reset( matrix_complete( array( $high, matrix_release( 2, $tag ) ) ) );
	matrix_error( $checker->get( $registration ), 'release_version_ambiguous', 'blocked' );
}
matrix_reset( matrix_complete( array( matrix_release( 5, '1.1' ), matrix_release( 6, 'v1.1.0' ), $high ) ) );
matrix_assert( '9.0' === $checker->get( $registration )->version, 'Lower numeric ties do not make unique highest version ambiguous' );
$old = matrix_release( 2, 'v1.1' ); $old['assets'] = array();
matrix_assert( Release_Selector::select( array( $old, $high ), $registration, '1.0' ) instanceof Release_Candidate, 'Older asset failure cannot block a valid unique highest release' );
matrix_error( Release_Selector::select( array( $high, $high ), $registration, '1.0' ), 'invalid_release_metadata', 'blocked' );
$draft = $high; $draft['draft'] = true;
matrix_error( Release_Selector::select( array( $draft ), $registration, '1.0' ), 'invalid_release_metadata', 'blocked' );

$draft = matrix_release( 3, 'v99.0' ); $draft['draft'] = true;
$preview = matrix_release( 4, 'v99.1' ); $preview['prerelease'] = true;
matrix_reset( matrix_complete( array( $draft, $preview, $low ) ) );
matrix_assert( '1.1' === $checker->get( $registration )->version, 'Production composition excludes previews and drafts' );
$matrix_responses = matrix_complete( array( $high ) );
$matrix_responses[2][1] = new WP_Error( 'http_request_failed', 'REMOTE_SECRET timeout' );
matrix_error( $checker->get( $registration, true ), 'api_timeout', 'unavailable' );
matrix_error( $checker->get( $registration ), 'api_timeout', 'unavailable' );
matrix_assert( 6 === count( $matrix_requests ), 'Forced partial failure never selects cached or partial newer release' );
$matrix_now += 300;
$matrix_responses = matrix_complete( array( $high ) );
matrix_assert( '9.0' === $checker->get( $registration )->version, 'Complete scan recovers after backoff' );
// Recorded cooldown remains authoritative through production force checks.
matrix_reset( array( array( 'https://api.github.com/repos/owner/repository', matrix_response( array(), 429, array( 'retry-after' => '120' ) ) ) ) );
matrix_error( $checker->get( $registration ), 'api_rate_limited', 'cooldown' );
matrix_error( $checker->get( $registration, true ), 'api_rate_limited', 'cooldown' );
matrix_assert( 1 === count( $matrix_requests ), 'Production force cannot bypass recorded rate wait' );
$matrix_now += 120;
$matrix_responses = matrix_complete( array( $high ) );
matrix_assert( '9.0' === $checker->get( $registration, true )->version, 'Explicit force recovers after cooldown ends' );
// Bad local prerequisites also prevent a cold-cache request.
matrix_reset( array() );
$matrix_plugins['installed-plugin/main.php']['Version'] = '';
matrix_error( $checker->get( $registration ), 'unsupported_installed_version', 'blocked' );
matrix_assert( array() === $matrix_requests, 'Unknown installed version is never zero and sends no HTTP' );
echo "PASS: production numeric selection, ambiguity/no-fallback policy, installed prerequisites, cache and recovery (metadata only).\n";
