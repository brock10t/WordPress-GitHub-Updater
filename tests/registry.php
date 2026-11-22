<?php
/**
 * Registry workflow checks with WordPress discovery/option/capability doubles.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Registry;

define( 'ABSPATH', '/unused/' );
foreach ( array( 'github/class-repository.php', 'registry/class-registration.php', 'registry/class-option-storage.php', 'registry/class-installed-plugins.php', 'registry/class-update-uri.php', 'registry/class-registry.php' ) as $file ) {
	require dirname( __DIR__ ) . '/includes/' . $file;
}
class WP_Error {
	public function __construct( public string $code, public string $message, public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function is_multisite() { return $GLOBALS['multisite']; }
function current_user_can( $capability ) { return $GLOBALS['permissions'][ $capability ] ?? false; }
function get_plugins() { return $GLOBALS['plugins']; }
function sanitize_url( $url ) { return trim( $url, ' ' ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function get_option( $name, $default = false ) {
	check_registry( ! is_multisite(), 'No ordinary registry reads on Multisite' );
	return $GLOBALS['options'][ $name ] ?? $default;
}
function get_site_option( $name, $default = false ) {
	check_registry( is_multisite(), 'No network registry reads on single-site' );
	return $GLOBALS['networks'][ $GLOBALS['network'] ][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	check_registry( ! is_multisite() && false === $autoload, 'Ordinary non-autoloaded save' );
	return write_registry( $GLOBALS['options'], $name, $value );
}
function update_site_option( $name, $value ) {
	check_registry( is_multisite(), 'Network save' );
	return write_registry( $GLOBALS['networks'][ $GLOBALS['network'] ], $name, $value );
}
function write_registry( &$bucket, $name, $value ) {
	++$GLOBALS['writes'];
	if ( $GLOBALS['fail_write'] || ( isset( $bucket[ $name ] ) && $bucket[ $name ] === $value ) ) {
		return false;
	}
	$bucket[ $name ] = $value;
	return true;
}
function check_registry( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}
function expect_registry_error( $result, $code ) {
	check_registry( $result instanceof WP_Error && $result->get_error_code() === $code, 'Expected error: ' . $code );
}

foreach ( array( false, true ) as $multisite ) {
	$options     = array();
	$networks    = array();
	$network     = 1;
	$writes      = 0;
	$fail_write  = false;
	$permissions = array( 'update_plugins' => true, 'manage_network_plugins' => true );
	$plugins     = array(
		'fixture/main.php' => array( 'Name' => 'Fixture', 'UpdateURI' => 'https://github.com/owner/repository' ),
		'other/main.php'   => array( 'Name' => 'Other', 'UpdateURI' => 'https://github.com/owner/other' ),
		'pending/main.php' => array( 'Name' => 'Pending', 'UpdateURI' => '' ),
		'legacy.php'       => array( 'Name' => 'Standalone', 'UpdateURI' => '' ),
	);
	$registry = new Registry();
	check_registry( array() === $registry->all() && 0 === $writes, 'Empty read does not initialize storage' );
	expect_registry_error( $registry->get( 'fixture/main.php' ), 'github_updater_plugin_not_registered' );
	$record = new Registration( 'fixture/main.php', 'owner', 'repository' );
	check_registry( true === ( new Option_Storage() )->save( array( $record->plugin_basename => $record ) ), 'Seed persisted fixture' );
	check_registry( $record->to_array() === ( new Registry() )->get( 'fixture/main.php' )->to_array(), 'Exact persisted lookup' );
	expect_registry_error( $registry->get( 'FIXTURE/main.php' ), 'github_updater_plugin_not_registered' );
	check_registry( true === $registry->check_update_uri( 'fixture/main.php' ), 'Current matching header' );
	$plugins['fixture/main.php']['UpdateURI'] = 'https://github.com/owner/different';
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_update_uri_mismatch' );
	$plugins['fixture/main.php']['UpdateURI'] = '';
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_missing_update_uri' );

	$permissions['update_plugins'] = false;
	$before = $writes;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_forbidden' );
	check_registry( $before === $writes, 'Unauthorized removal does not write' );
	$permissions['update_plugins'] = true;
	if ( $multisite ) {
		$permissions['manage_network_plugins'] = false;
		expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_forbidden' );
		$permissions['manage_network_plugins'] = true;
	}
	$fail_write = true;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_write_failed' );
	check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Failed removal preserves registration' );
	$fail_write = false;
	unset( $plugins['fixture/main.php'] );
	check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Orphaned record remains inspectable' );
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_plugin_not_installed' );
	check_registry( true === $registry->remove( 'fixture/main.php' ), 'Orphaned record can be removed' );
	$before = $writes;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_plugin_not_registered' );
	check_registry( $before === $writes, 'Absent removal does not write' );
	$plugins['fixture/main.php'] = array( 'Name' => 'Fixture', 'UpdateURI' => 'https://github.com/owner/repository' );
	$created = $registry->register( 'fixture/main.php', 'HTTPS://GITHUB.COM/OWNER/Repository.git/' );
	check_registry( $created instanceof Registration && 'https://github.com/owner/repository' === $created->repository_url, 'Register canonical identity from normalized URL' );
	check_registry( true === $registry->check_update_uri( 'fixture/main.php' ), 'Compatible registered plugin passes fresh check' );
	$before = $writes;
	expect_registry_error( $registry->register( 'fixture/main.php', 'https://github.com/owner/repository' ), 'github_updater_plugin_already_registered' );
	check_registry( $before === $writes, 'Existing registration is never silently replaced' );
	$pending = $registry->register( 'pending/main.php', 'https://github.com/owner/pending' );
	check_registry( $pending instanceof Registration, 'Missing header permits pending registration' );
	expect_registry_error( $registry->check_update_uri( 'pending/main.php' ), 'github_updater_missing_update_uri' );
	$plugins['pending/main.php']['UpdateURI'] = 'https://github.com/OWNER/Pending.git/';
	check_registry( true === $registry->check_update_uri( 'pending/main.php' ), 'Corrected header enables compatibility without registry rewrite' );
	$before = $writes;
	foreach ( array(
		array( 'false', 'github_updater_disabled_update_uri' ),
		array( 'not a URL', 'github_updater_invalid_update_uri' ),
		array( 'https://github.com/owner/different', 'github_updater_update_uri_mismatch' ),
		array( 'https://GITHUB.COM/owner/other', 'github_updater_update_uri_hostname_mismatch' ),
	) as list( $uri, $code ) ) {
		$plugins['other/main.php']['UpdateURI'] = $uri;
		expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), $code );
	}
	$plugins['other/main.php']['UpdateURI'] = 'https://github.com/owner/other';
	expect_registry_error( $registry->register( 'other/main.php', 'http://evil.test/owner/other' ), 'github_updater_invalid_repository_url' );
	expect_registry_error( $registry->register( 'missing/main.php', 'https://github.com/owner/missing' ), 'github_updater_plugin_not_installed' );
	expect_registry_error( $registry->register( 'legacy.php', 'https://github.com/owner/legacy' ), 'github_updater_unsupported_plugin_basename' );
	expect_registry_error( $registry->register( 'wordpress-github-updater/wordpress-github-updater.php', 'https://github.com/owner/fixture' ), 'github_updater_self_registration_reserved' );
	check_registry( $before === $writes, 'Rejected registration attempts never write' );
	$permissions['update_plugins'] = false;
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_forbidden' );
	$permissions['update_plugins'] = true;
	if ( $multisite ) {
		$permissions['manage_network_plugins'] = false;
		expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_forbidden' );
		$permissions['manage_network_plugins'] = true;
		$network = 2;
		check_registry( array() === $registry->all(), 'Separate networks retain separate registries' );
		$network = 1;
		check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Shared network record persists' );
	}
	$fail_write = true;
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_write_failed' );
	expect_registry_error( $registry->get( 'other/main.php' ), 'github_updater_plugin_not_registered' );
	$fail_write = false;
	$before_plugins = $plugins;
	check_registry( true === $registry->remove( 'fixture/main.php' ), 'Remove a registration for a still-installed plugin' );
	check_registry( $before_plugins === $plugins, 'Removal does not change installed plugins or headers' );
	check_registry( $registry->get( 'pending/main.php' ) instanceof Registration, 'Removal preserves other registrations' );
	$key = Option_Storage::OPTION_NAME;
	if ( $multisite ) {
		$networks[ $network ][ $key ] = array( 'schema_version' => 2, 'registrations' => array() );
	} else {
		$options[ $key ] = array( 'schema_version' => 2, 'registrations' => array() );
	}
	$before = $writes;
	expect_registry_error( $registry->all(), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->get( 'pending/main.php' ), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->remove( 'pending/main.php' ), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_unsupported_registry_schema' );
	check_registry( $before === $writes, 'Registry operations preserve unreadable storage' );
	echo 'PASS: ' . ( $multisite ? 'network' : 'single-site' ) . " registration, lookup, removal, permissions and fresh diagnostics\n";
}
