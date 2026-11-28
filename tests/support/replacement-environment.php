<?php
/** Real disposable destination tree + registry/package/activation preflight. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Replacement_Check;
use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;

// Reuse real ZIP and header-gate fixtures, then add registry doubles explicitly.
require __DIR__ . '/zip-environment.php';
define( 'DB_NAME', 'replacement-check-tests' );
require __DIR__ . '/class-lock-database-double.php';
foreach ( array( 'github/class-repository.php', 'github/class-release-tag.php', 'github/class-version-comparison.php', 'registry/class-option-storage.php', 'registry/class-installed-plugins.php', 'registry/class-update-uri.php', 'registry/class-registry-lock.php', 'registry/class-registry.php', 'packages/class-zip-contents.php', 'packages/class-plugin-headers.php', 'packages/class-packaged-plugin.php', 'packages/class-replacement-check.php' ) as $file ) { require dirname( __DIR__, 2 ) . '/includes/' . $file; }
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
