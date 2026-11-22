<?php
/** In-memory WordPress environment for registry and administration checks. */
define( 'ABSPATH', '/unused/' );
define( 'DB_NAME', 'registry-tests' );
require __DIR__ . '/class-lock-database-double.php';
$wpdb = new Lock_Database_Double();
$cache_deletes = array();
foreach ( array( 'github/class-repository.php', 'registry/class-registration.php', 'registry/class-option-storage.php', 'registry/class-installed-plugins.php', 'registry/class-update-uri.php', 'registry/class-registry-lock.php', 'registry/class-registry.php' ) as $file ) {
	require dirname( __DIR__, 2 ) . '/includes/' . $file;
}
class WP_Error {
	public function __construct( public string $code, public string $message, public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function __( $text, $domain ) { return $text; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_current_network_id() { return $GLOBALS['network']; }
function wp_cache_delete( $key, $group ) { $GLOBALS['cache_deletes'][] = array( $key, $group ); }
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

