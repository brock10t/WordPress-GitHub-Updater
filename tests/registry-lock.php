<?php
/** Isolated advisory-lock lifecycle and cache-refresh checks. */
use TenT\GitHubUpdater\Registry\Registry_Lock;

define( 'ABSPATH', '/unused/' );
define( 'DB_NAME', 'registry-lock-tests' );
require __DIR__ . '/support/class-lock-database-double.php';
require dirname( __DIR__ ) . '/includes/registry/class-option-storage.php';
require dirname( __DIR__ ) . '/includes/registry/class-registry-lock.php';
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code() { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_current_network_id() { return $GLOBALS['network']; }
function wp_cache_delete( $key, $group ) { $GLOBALS['cleared'][] = array( $key, $group ); }
function check_lock( $condition, $description ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $description ); }
}
function lock_error( $result, $code ) {
	check_lock( $result instanceof WP_Error && $code === $result->get_error_code(), 'Expected lock error: ' . $code );
}
$multisite = false;
$network = 1;
$cleared = array();
$wpdb = new Lock_Database_Double();
$lock = new Registry_Lock();
$result = $lock->run(function () use ($lock, $wpdb) {
	check_lock( $wpdb->locked, 'Callback runs while locked' );
	lock_error( (new Registry_Lock())->run(fn() => 'must not run'), 'github_updater_registry_busy' );
	return 'saved';
});
check_lock( 'saved' === $result && ! $wpdb->locked, 'Result returned and lock released' );
check_lock( false === $wpdb->suppressed, 'Database error-output setting restored' );
check_lock( array( array('wordpress_github_updater_registry', 'options'), array('notoptions', 'options'), array('alloptions', 'options') ) === $cleared, 'Single-site caches refreshed before mutation' );
$site_name = explode('|', $wpdb->queries[0])[1];
check_lock( strlen($site_name) <= 64, 'Lock name fits MySQL limit' );
try {
	$lock->run(function () { throw new LogicException('fixture failure'); });
	throw new RuntimeException('Exception was swallowed');
} catch (LogicException $error) {
	check_lock( 'fixture failure' === $error->getMessage() && ! $wpdb->locked, 'Exception propagates after release' );
}
check_lock( true === $lock->run(fn() => true), 'Subsequent operation can acquire after exception' );
foreach (array('0', null) as $acquire) {
	$wpdb->acquire = $acquire;
	$ran = false;
	$before = count($cleared);
	$result = $lock->run(function () use (&$ran) { $ran = true; });
	lock_error($result, '0' === $acquire ? 'github_updater_registry_busy' : 'github_updater_registry_lock_unavailable');
	check_lock(!$ran && $before === count($cleared), 'Failed acquisition runs no callback or cache clearing');
}
$wpdb->acquire = '1';
$wpdb->release = null;
lock_error($lock->run(fn() => true), 'github_updater_registry_lock_release_failed');
$wpdb->release = '1';
$multisite = true;
$cleared = array();
$wpdb->queries = array();
check_lock(true === $lock->run(fn() => true), 'Network lock succeeds');
$network_one = explode('|', $wpdb->queries[0])[1];
check_lock($site_name !== $network_one, 'Network and ordinary option lock scopes differ');
check_lock(array(array('1:wordpress_github_updater_registry', 'site-options'), array('1:notoptions', 'site-options')) === $cleared, 'Network option and missing-option caches refreshed');
$network = 2;
$wpdb->queries = array();
$lock->run(fn() => true);
check_lock($network_one !== explode('|', $wpdb->queries[0])[1], 'Separate networks use different locks');
echo "PASS: advisory lock success, contention, unavailable/release errors, nesting, exception cleanup and cache scopes\n";
