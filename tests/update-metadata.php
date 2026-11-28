<?php
/** Native metadata contract over real selection/cache/registry components. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Updates\Update_Metadata;
require __DIR__ . '/support/handoff-environment.php';
require dirname( __DIR__ ) . '/includes/updates/class-update-metadata.php';
$hooks = array(); $failures = array();
function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['hooks'][$hook] = array( $callback, $priority, $accepted ); }
function do_action( $hook, ...$args ) { $GLOBALS['failures'][] = array( $hook, $args ); }
reset_handoff();
$metadata = new Update_Metadata();
function metadata_check( $incoming = false, $headers = null, $basename = 'fixture/main.php' ) {
	return $GLOBALS['metadata']->update( $incoming, $headers ?? $GLOBALS['plugins']['fixture/main.php'], $basename, array( 'en_US' ) );
}
try {
	$metadata->register_hooks();
	assert_zip( isset( $hooks['update_plugins_github.com'] ) && 4 === $hooks['update_plugins_github.com'][2], 'Native hostname hook registers four arguments' );
	$prior = (object) array( 'version' => '9.0', 'package' => 'https://other.example/unrelated.zip' );
	assert_zip( $prior === metadata_check( $prior, array(), 'other/main.php' ) && array() === $http, 'Unregistered exact identity preserves prior object without HTTP' );
	assert_zip( $prior === metadata_check( $prior, array(), 'Fixture/main.php' ), 'Case variant is not an explicit registration' );
	$before = serialize( $options );
	$offer = metadata_check( $prior );
	assert_zip( $offer === array( 'slug' => 'fixture', 'version' => '1.2', 'url' => $record->repository_url, 'package' => $expected->asset->api_url ), 'Exact registered candidate replaces other metadata with native version/selected asset fields' );
	assert_zip( ! isset( $offer['autoupdate'], $offer['requires_php'], $offer['tested'] ) && array() === $temps, 'No opt-in/compatibility claims or package download' );
	$key = array_key_first( $cache ); $saved = $cache[$key];
	$cache[$key]['releases'] = array();
	assert_zip( false === metadata_check(), 'Completed empty scan does not invent an available or installed release version' );
	$cache[$key] = $saved;
	$cache[$key]['kind'] = 'error'; unset( $cache[$key]['releases'] );
	$cache[$key]['error'] = array( 'code' => 'github_updater_api_rate_limited', 'message' => '<script>untrusted</script>', 'data' => array( 'retry_at' => time() + 600, 'retry_after' => 600 ) );
	$cache[$key]['created_at'] = time(); $cache[$key]['expires_at'] = $cache[$key]['created_at'] + 600;
	$calls = count( $http );
	assert_zip( false === metadata_check( $prior ) && count( $http ) === $calls, 'Native routine check honors cached cooldown without HTTP or fallback offer' );
	$failure = end( $failures );
	assert_zip( 'cooldown' === $failure[1][1]->status && ! str_contains( $failure[1][1]->message, 'untrusted' ), 'Cooldown diagnostics use fixed safe copy' );
	$cache[$key] = $saved;
	$calls = count( $http ); metadata_check(); assert_zip( $calls === count( $http ), 'Routine native checks reuse bounded release cache' );
	$plugins['fixture/main.php']['Version'] = '1.2.0';
	$equal = metadata_check();
	assert_zip( '1.2.0' === $equal['version'] && ! isset( $equal['package'] ), 'Successful equivalent-version comparison supplies native no_update metadata without package' );
	$plugins['fixture/main.php']['Version'] = '1.3'; assert_zip( '1.3' === metadata_check()['version'], 'Older remote release never becomes a downgrade offer' );
	$plugins['fixture/main.php']['Version'] = '1.0';
	$stale = $plugins['fixture/main.php']; $stale['Version'] = '0.9';
	assert_zip( false === metadata_check( $prior, $stale ) && count( $http ) === $calls, 'Stale core headers cannot compare or offer another installed version' );
	foreach ( array( '', 'false', 'https://github.com/owner/other', 'https://GitHub.com/owner/repository' ) as $uri ) {
		$plugins['fixture/main.php']['UpdateURI'] = $uri;
		assert_zip( false === metadata_check( $prior ) && count( $http ) === $calls, 'Fresh incompatible headers withhold prior managed offer before HTTP' );
	}
	$plugins['fixture/main.php']['UpdateURI'] = $record->repository_url;
	$plugins['fixture/main.php']['Version'] = 'unknown'; assert_zip( false === metadata_check() && count( $http ) === $calls, 'Unknown installed version is not up to date' );
	$plugins['fixture/main.php']['Version'] = '1.0';
	$plugins['fixture/second.php'] = array( 'Version' => '1.0', 'UpdateURI' => $record->repository_url );
	assert_zip( false === metadata_check() && count( $http ) === $calls, 'Current ambiguous managed entry points block before HTTP' ); unset( $plugins['fixture/second.php'] );
	assert_zip( serialize( $options ) === $before, 'Metadata checks never edit registry/activation preferences' );
	reset_handoff(); $release['tag_name'] = 'stable';
	assert_zip( false === metadata_check( $prior ), 'Selection failure cannot become native no_update or retain prior managed package' );
	$failure = end( $failures );
	assert_zip( 'wordpress_github_updater_update_check_failed' === $failure[0] && 'fixture/main.php' === $failure[1][0] && $failure[1][1]->is_error(), 'Failure action carries safe Check_State, not raw remote error' );
	assert_zip( ! property_exists( $failure[1][1], 'package' ), 'Diagnostics carry no package URL' );
	$options['wordpress_github_updater_registry'] = array( 'schema_version' => 99 );
	assert_zip( false === metadata_check( $prior, array(), 'other/main.php' ), 'Unreadable registry fails closed for this hostname' );
	echo "PASS: exact native metadata identity, version/no-update contract, readiness, cached retrieval, failure withholding and unrelated source preservation; no downloads or option writes.\n";
} finally {
	unlink( $zip ); unlink( $root . '/fixture/main.php' ); rmdir( $root . '/fixture' ); rmdir( $root );
}
