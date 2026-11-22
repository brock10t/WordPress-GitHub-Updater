<?php
/**
 * Storage contract checks with in-memory WordPress option doubles.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';
require dirname( __DIR__ ) . '/includes/registry/class-option-storage.php';

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_option( $name, $default = false ) {
	verify_storage( ! is_multisite(), 'No per-blog registry reads on Multisite' );
	return $GLOBALS['options'][ $GLOBALS['blog'] ][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	verify_storage( ! is_multisite(), 'No per-blog registry writes on Multisite' );
	verify_storage( false === $autoload, 'Single-site option is not autoloaded' );
	return write_storage( $GLOBALS['options'][ $GLOBALS['blog'] ], $name, $value );
}
function get_site_option( $name, $default = false ) {
	verify_storage( is_multisite(), 'No network-option reads on single-site' );
	return $GLOBALS['networks'][ $GLOBALS['network'] ][ $name ] ?? $default;
}
function update_site_option( $name, $value ) {
	verify_storage( is_multisite(), 'No network-option writes on single-site' );
	return write_storage( $GLOBALS['networks'][ $GLOBALS['network'] ], $name, $value );
}
function write_storage( &$bucket, $name, $value ) {
	++$GLOBALS['writes'];
	if ( $GLOBALS['fail_write'] || ( isset( $bucket[ $name ] ) && $bucket[ $name ] === $value ) ) {
		return false;
	}
	$bucket[ $name ] = $value;
	return true;
}
function verify_storage( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}
function expect_storage_error( $result, $code ) {
	verify_storage( $result instanceof WP_Error && $result->get_error_code() === $code, 'Expected error: ' . $code );
}

$options    = array();
$networks   = array();
$blog       = 1;
$network    = 1;
$writes     = 0;
$fail_write = false;
$record     = new Registration( 'fixture/main.php', 'example', 'different-repository' );
$records    = array( $record->plugin_basename => $record );
$key        = Option_Storage::OPTION_NAME;

foreach ( array( false, true ) as $multisite ) {
	$options  = array();
	$networks = array();
	$blog     = 1;
	$network  = 1;
	$storage  = new Option_Storage();
	$before   = $writes;
	verify_storage( array() === $storage->load(), 'Absent registry is empty' );
	verify_storage( $before === $writes, 'Reads never create options' );
	$fail_write = true;
	expect_storage_error( $storage->save( array() ), 'github_updater_registry_write_failed' );
	$fail_write = false;
	verify_storage( true === $storage->save( $records ), 'Save records' );
	$loaded = ( new Option_Storage() )->load();
	verify_storage( $record->to_array() === $loaded['fixture/main.php']->to_array(), 'Reconstruct stored record' );
	verify_storage( true === $storage->save( $records ), 'Unchanged save succeeds despite API false' );

	if ( $multisite ) {
		$blog = 2;
		verify_storage( isset( $storage->load()['fixture/main.php'] ), 'Second blog sees shared network registry' );
		$network = 2;
		verify_storage( array() === $storage->load(), 'Separate networks do not share registries' );
		$network = 1;
		verify_storage( array() === $options, 'No per-blog option created on Multisite' );
	}

	$fail_write = true;
	expect_storage_error( $storage->save( array() ), 'github_updater_registry_write_failed' );
	verify_storage( isset( $storage->load()['fixture/main.php'] ), 'Failed write leaves stored record intact' );
	$fail_write = false;
	expect_storage_error( $storage->save( array( 'other/main.php' => $record ) ), 'github_updater_invalid_registration_record' );
	expect_storage_error( $storage->save( array( 'fixture/main.php' => $record->to_array() ) ), 'github_updater_invalid_registration_record' );

	$valid = array( 'schema_version' => 1, 'registrations' => array( 'fixture/main.php' => $record->to_array() ) );
	$bad_record = $valid;
	$bad_record['registrations']['fixture/main.php']['asset_name'] = 'unrelated.zip';
	$bad_key = array( 'schema_version' => 1, 'registrations' => array( 'unrelated/main.php' => $record->to_array() ) );
	foreach ( array(
		array( false, 'github_updater_invalid_registry' ),
		array( new stdClass(), 'github_updater_invalid_registry' ),
		array( array(), 'github_updater_invalid_registry' ),
		array( array( 'schema_version' => '1', 'registrations' => array() ), 'github_updater_invalid_registry' ),
		array( array( 'schema_version' => 2, 'registrations' => array() ), 'github_updater_unsupported_registry_schema' ),
		array( array( 'schema_version' => 1 ), 'github_updater_invalid_registry' ),
		array( array( 'schema_version' => 1, 'registrations' => false ), 'github_updater_invalid_registry' ),
		array( $bad_record, 'github_updater_invalid_registration_record' ),
		array( $bad_key, 'github_updater_invalid_registration_record' ),
		array( array( 'schema_version' => 1, 'registrations' => array( 'fixture/main.php' => false ) ), 'github_updater_invalid_registration_record' ),
	) as list( $corrupt, $code ) ) {
		if ( $multisite ) {
			$networks[ $network ][ $key ] = $corrupt;
		} else {
			$options[ $blog ][ $key ] = $corrupt;
		}
		$before = $writes;
		expect_storage_error( $storage->load(), $code );
		expect_storage_error( $storage->save( $records ), $code );
		verify_storage( $before === $writes, 'Unreadable storage is never overwritten' );
	}
	echo 'PASS: ' . ( $multisite ? 'network' : 'single-site' ) . " option-storage contract\n";
}
