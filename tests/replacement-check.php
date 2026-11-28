<?php
/** Real disposable destination tree + registry/package/activation preflight. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Replacement_Check;
use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;

// Reuse real ZIP and header-gate fixtures, then add registry doubles explicitly.
require __DIR__ . '/support/zip-environment.php';
define( 'DB_NAME', 'replacement-check-tests' );
require __DIR__ . '/support/class-lock-database-double.php';
foreach ( array( 'github/class-repository.php', 'github/class-release-tag.php', 'github/class-version-comparison.php', 'registry/class-option-storage.php', 'registry/class-installed-plugins.php', 'registry/class-update-uri.php', 'registry/class-registry-lock.php', 'registry/class-registry.php', 'packages/class-zip-contents.php', 'packages/class-plugin-headers.php', 'packages/class-packaged-plugin.php', 'packages/class-replacement-check.php' ) as $file ) { require dirname( __DIR__ ) . '/includes/' . $file; }
$root = sys_get_temp_dir() . '/10t-replacement-' . bin2hex( random_bytes( 6 ) );
mkdir( $root ); define( 'WP_PLUGIN_DIR', $root ); mkdir( $root . '/fixture' );
file_put_contents( $root . '/fixture/main.php', '<?php /* installed fixture */' );
$zip = tempnam( sys_get_temp_dir(), '10t-replace-zip-' );
class WP_Filesystem_Direct { public string $method = 'direct'; }
$wpdb = new Lock_Database_Double();
$wp_filesystem = new WP_Filesystem_Direct();
$record = new Registration( 'fixture/main.php', 'owner', 'repository' );
$source = "<?php\n/*\nPlugin Name: Fixture\nVersion: 1.2\nUpdate URI: https://github.com/owner/repository\n*/\n";
file_put_contents( $zip, zip_bytes( array( array( 'body' => $source ) ) ) );
$options = array(); $multisite = false; $clean_calls = array();
$plugins = array( 'fixture/main.php' => array( 'Version' => '1.0', 'UpdateURI' => $record->repository_url, 'Network' => false ) );
function sanitize_url( $url ) { return trim( $url, ' ' ); }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function is_wp_version_compatible( $required ) { return true; }
function is_php_version_compatible( $required ) { return true; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_plugins() { return $GLOBALS['plugins']; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function get_site_option( $name, $default = false ) { return get_option( $name, $default ); }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function update_site_option( $name, $value ) { return update_option( $name, $value ); }
function wp_clean_plugins_cache( $clear_updates = true ) { $GLOBALS['clean_calls'][] = $clear_updates; }
function replacement_error( $result, string $code ): void { assert_zip( $result instanceof WP_Error && $result->code === $code, 'Expected ' . $code . ', got ' . ( $result instanceof WP_Error ? $result->code : 'success' ) ); }
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$storage = new Option_Storage(); $storage->save( array( $record->plugin_basename => $record ) );
$checker = new Replacement_Check();
function run_replacement() {
	global $checker, $zip, $record, $options;
	$before = serialize( $options );
	$result = $checker->check( $zip, $record, 'v1.2' );
	assert_zip( $before === serialize( $options ), 'Registry/activation/auto-update choices never written by preflight' );
	return $result;
}
try {
	foreach ( array( array(), array( 'fixture/main.php' ) ) as $active ) {
		$options['active_plugins'] = $active; $options['auto_update_plugins'] = array( 'fixture/main.php' );
		assert_zip( is_array( run_replacement() ), 'Active and inactive single-site choices are allowed unchanged' );
	}
	$plugins['fixture/main.php']['Version'] = '1.2.0'; replacement_error( run_replacement(), 'github_updater_replacement_version' );
	$plugins['fixture/main.php']['Version'] = 'unknown'; replacement_error( run_replacement(), 'github_updater_unsupported_installed_version' );
	$plugins['fixture/main.php']['Version'] = '1.0';
	$plugins['fixture/other.php'] = array(); replacement_error( run_replacement(), 'github_updater_plugin_directory_conflict' ); unset( $plugins['fixture/other.php'] );
	$plugins['Fixture/other.php'] = array(); replacement_error( run_replacement(), 'github_updater_plugin_directory_conflict' ); unset( $plugins['Fixture/other.php'] );
	$plugins['fixture/main.php']['UpdateURI'] = 'false'; replacement_error( run_replacement(), 'github_updater_disabled_update_uri' ); $plugins['fixture/main.php']['UpdateURI'] = $record->repository_url;
	$changed = new Registration( 'fixture/main.php', 'other', 'repository' ); $storage->save( array( $changed->plugin_basename => $changed ) );
	replacement_error( run_replacement(), 'github_updater_replacement_registration' );
	$storage->save( array() ); replacement_error( run_replacement(), 'github_updater_plugin_not_registered' );
	$storage->save( array( $record->plugin_basename => $record ) );
	$another = new Registration( 'elsewhere/main.php', 'owner', 'repository' ); $storage->save( array( $record->plugin_basename => $record, $another->plugin_basename => $another ) );
	replacement_error( run_replacement(), 'github_updater_repository_already_registered' ); $storage->save( array( $record->plugin_basename => $record ) );
	foreach ( array( null, (object) array( 'method' => 'ftpext' ) ) as $unsupported ) { $wp_filesystem = $unsupported; replacement_error( run_replacement(), 'github_updater_replacement_filesystem' ); }
	$wp_filesystem = new WP_Filesystem_Direct();
	mkdir( $root . '/Fixture' ); replacement_error( run_replacement(), 'github_updater_replacement_destination' ); rmdir( $root . '/Fixture' );
	mkdir( $root . '/fixture/sub' ); symlink( $root . '/fixture/main.php', $root . '/fixture/sub/link' );
	replacement_error( run_replacement(), 'github_updater_replacement_destination' ); unlink( $root . '/fixture/sub/link' ); rmdir( $root . '/fixture/sub' );
	link( $root . '/fixture/main.php', $root . '/fixture/hardlink' ); replacement_error( run_replacement(), 'github_updater_replacement_destination' ); unlink( $root . '/fixture/hardlink' );
	file_put_contents( $root . '/fixture/case', '' ); file_put_contents( $root . '/fixture/Case', '' ); replacement_error( run_replacement(), 'github_updater_replacement_destination' ); unlink( $root . '/fixture/case' ); unlink( $root . '/fixture/Case' );
	if ( function_exists( 'posix_mkfifo' ) ) { posix_mkfifo( $root . '/fixture/pipe', 0600 ); replacement_error( run_replacement(), 'github_updater_replacement_destination' ); unlink( $root . '/fixture/pipe' ); }
	rename( $root . '/fixture', $root . '/original' ); symlink( $root . '/original', $root . '/fixture' ); replacement_error( run_replacement(), 'github_updater_replacement_destination' ); unlink( $root . '/fixture' ); rename( $root . '/original', $root . '/fixture' );
	$path = $root . '/fixture'; for ( $i = 0; $i < 20; ++$i ) { $path .= '/d'; mkdir( $path ); }
	replacement_error( run_replacement(), 'github_updater_replacement_destination' ); for ( $i = 0; $i < 20; ++$i ) { rmdir( $path ); $path = dirname( $path ); }
	for ( $i = 0; $i < 5000; ++$i ) { file_put_contents( $root . '/fixture/f' . $i, '' ); }
	replacement_error( run_replacement(), 'github_updater_replacement_destination' ); for ( $i = 0; $i < 5000; ++$i ) { unlink( $root . '/fixture/f' . $i ); }
	$multisite = true;
	foreach ( array( array(), array( 'fixture/main.php' => 123 ) ) as $network_active ) {
		$options['active_sitewide_plugins'] = $network_active;
		foreach ( array( false, true ) as $network_only ) {
			$plugins['fixture/main.php']['Network'] = $network_only;
			file_put_contents( $zip, zip_bytes( array( array( 'body' => $source . 'Network: ' . ( $network_only ? 'true' : 'false' ) . "\n" ) ) ) );
			assert_zip( is_array( run_replacement() ), 'Same network-only requirement preserves inactive/site/network choices' );
			file_put_contents( $zip, zip_bytes( array( array( 'body' => $source . 'Network: ' . ( $network_only ? 'false' : 'true' ) . "\n" ) ) ) );
			replacement_error( run_replacement(), 'github_updater_replacement_network' );
		}
	}
	$plugins['fixture/main.php']['Network'] = true;
	file_put_contents( $zip, zip_bytes( array( array( 'body' => $source . "Site Wide Only: TRUE\n" ) ) ) ); assert_zip( is_array( run_replacement() ), 'Deprecated native network-only fallback preserved' );
	file_put_contents( $zip, zip_bytes( array( array( 'body' => $source . "Network: false\nSite Wide Only: true\n" ) ) ) ); replacement_error( run_replacement(), 'github_updater_replacement_network' );
	$multisite = false; assert_zip( is_array( run_replacement() ), 'Network header has no single-site activation restriction' );
	assert_zip( ! in_array( true, $clean_calls, true ), 'Discovery refresh never clears native update transient' );
	echo "PASS: registry/version/readiness conflicts, bounded real disk/link/alias checks and activation-requirement policy (single-site/network doubles); no replacement.\n";
} finally {
	unlink( $zip ); unlink( $root . '/fixture/main.php' ); rmdir( $root . '/fixture' ); rmdir( $root );
}
