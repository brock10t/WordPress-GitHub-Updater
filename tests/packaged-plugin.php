<?php
/** Real ZIP integrity plus packaged-header identity/runtime checks. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Packaged_Plugin;
use TenT\GitHubUpdater\Packages\Plugin_Headers;
use TenT\GitHubUpdater\Packages\Zip_Contents;
use TenT\GitHubUpdater\Packages\Zip_Inspector;
use TenT\GitHubUpdater\Registry\Registration;
require __DIR__ . '/support/zip-environment.php';
foreach ( array( 'github/class-repository.php', 'github/class-release-tag.php', 'github/class-version-comparison.php', 'registry/class-update-uri.php', 'packages/class-zip-contents.php', 'packages/class-plugin-headers.php', 'packages/class-packaged-plugin.php' ) as $file ) { require dirname( __DIR__ ) . '/includes/' . $file; }
function sanitize_url( $url ) { return trim( $url, ' ' ); }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
// Source-aligned compatibility doubles; minimum-version runtimes are not booted.
function is_wp_version_compatible( $required ) {
	$version = explode( '-', $GLOBALS['runtime_wp'] )[0];
	if ( substr_count( $required, '.' ) > 1 && str_ends_with( $required, '.0' ) ) { $required = substr( $required, 0, -2 ); }
	return empty( $required ) || version_compare( $version, $required, '>=' );
}
function is_php_version_compatible( $required ) { return empty( $required ) || version_compare( $GLOBALS['runtime_php'], $required, '>=' ); }
function plugin_source( array $changes = array() ): string {
	$headers = $changes + array( 'Plugin Name' => 'Fixture', 'Version' => '1.2.0', 'Update URI' => 'https://github.com/owner/repository', 'Requires at least' => '6.6', 'Requires PHP' => '8.2', 'Requires Plugins' => '', 'Network' => '', 'Site Wide Only' => '' );
	$source = "<?php\n/*\n";
	foreach ( $headers as $name => $value ) { if ( null !== $value ) { $source .= $name . ': ' . $value . "\n"; } }
	return $source . "*/\nthrow new RuntimeException('PACKAGE_CODE_MUST_NOT_EXECUTE');\n";
}
function packaged( string $source, array $extra = array(), string $tag = 'v1.2', array $entry_changes = array() ) {
	global $temporary, $registration, $gate;
	$entries = array_merge( array( array_replace( array( 'body' => $source ), $entry_changes ) ), $extra );
	file_put_contents( $temporary, zip_bytes( $entries ) ); clearstatcache( true, $temporary );
	$hash = hash_file( 'sha256', $temporary );
	$result = $gate->check( $temporary, $registration, $tag );
	assert_zip( $hash === hash_file( 'sha256', $temporary ), 'Package bytes are never rewritten' );
	return $result;
}
function package_error( $result, string $code ): void { assert_zip( $result instanceof WP_Error && $result->code === $code, 'Expected ' . $code . ', got ' . ( $result instanceof WP_Error ? $result->code : 'success' ) ); }
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$temporary = tempnam( sys_get_temp_dir(), '10t-headers-' );
$registration = new Registration( 'fixture/main.php', 'owner', 'repository' );
$gate = new Packaged_Plugin(); $runtime_wp = '6.6'; $runtime_php = '8.2.0';
try {
	foreach ( array( 0, 8 ) as $method ) {
		$result = packaged( plugin_source(), array(), 'v1.2', array( 'method' => $method ) );
		assert_zip( is_array( $result ) && '1.2.0' === $result['Version'], 'Stored/deflated header version equivalence and exact main identity' );
	}
	foreach ( array( "\r\n", "\r" ) as $newline ) { assert_zip( is_array( packaged( str_replace( "\n", $newline, plugin_source() ) ) ), 'Native newline handling' ); }
	assert_zip( is_array( packaged( plugin_source( array( 'Requires at least' => null, 'Requires PHP' => null ) ) ) ), 'Absent minimum requirements follow optional native semantics' );
	assert_zip( is_array( packaged( plugin_source( array( 'Requires at least' => '6.6.0', 'Requires PHP' => '8' ) ) ) ), 'Native WP trailing-zero minimum and single component PHP minimum' );
	$runtime_wp = '6.6-beta1'; assert_zip( is_array( packaged( plugin_source() ) ), 'Native current WP prerelease suffix handling' ); $runtime_wp = '6.6';
	foreach ( array( array( 'Plugin Name' => '' ), array( 'Plugin Name' => null ), array( 'Plugin Name' => '0' ) ) as $changes ) { package_error( packaged( plugin_source( $changes ) ), 'github_updater_package_headers' ); }
	foreach ( array( '', 'unknown', '1.3', 'v1.2-beta' ) as $version ) { package_error( packaged( plugin_source( array( 'Version' => $version ) ) ), 'github_updater_package_version' ); }
	foreach ( array( '' => 'missing_update_uri', 'false' => 'disabled_update_uri', 'https://github.com/other/repo' => 'update_uri_mismatch', 'https://GITHUB.COM/owner/repository' => 'update_uri_hostname_mismatch', 'http://github.com/owner/repository' => 'invalid_update_uri' ) as $uri => $code ) { package_error( packaged( plugin_source( array( 'Update URI' => $uri ) ) ), 'github_updater_' . $code ); }
	foreach ( array( 'Plugin Name', 'Version', 'Update URI', 'Requires PHP', 'Requires at least', 'Requires Plugins', 'Network', 'Site Wide Only' ) as $field ) {
		package_error( packaged( plugin_source() . "\n" . $field . ": duplicated\n" ), 'github_updater_package_headers' );
	}
	package_error( packaged( plugin_source( array( 'Plugin Name' => "REMOTE_SECRET\0invalid" ) ) ), 'github_updater_package_headers' );
	foreach ( array( 'v8.2', '8.2-beta', '08.2', '>=8.2', '8.2 REMOTE_SECRET', str_repeat( '9', 41 ) ) as $required ) { package_error( packaged( plugin_source( array( 'Requires PHP' => $required ) ) ), 'github_updater_package_requirements' ); }
	package_error( packaged( plugin_source( array( 'Requires PHP' => '8.3' ) ) ), 'github_updater_package_php' );
	package_error( packaged( plugin_source( array( 'Requires at least' => '6.7' ) ) ), 'github_updater_package_wordpress' );
	package_error( packaged( plugin_source( array( 'Requires Plugins' => 'dependency' ) ) ), 'github_updater_package_dependencies' );
	package_error( packaged( plugin_source(), array( array( 'name' => 'fixture/second.php', 'body' => plugin_source() ) ) ), 'github_updater_package_entry_points' );
	assert_zip( is_array( packaged( plugin_source(), array( array( 'name' => 'fixture/library.php', 'body' => '<?php // no plugin headers' ), array( 'name' => 'fixture/includes/example.php', 'body' => plugin_source() ) ) ) ), 'Normal root PHP and nested plugin-like examples do not create native entry points' );
	$extra = array(); for ( $i = 0; $i < 64; ++$i ) { $extra[] = array( 'name' => 'fixture/lib' . $i . '.php', 'body' => '<?php // library' ); }
	assert_zip( is_array( packaged( plugin_source(), array_slice( $extra, 0, 63 ) ) ), '64 captured PHP prefixes are accepted at the boundary' );
	package_error( packaged( plugin_source(), $extra ), 'github_updater_zip_content_prefix_limit' );
	$prefixes = ( new Zip_Contents() )->plugin_prefixes( $temporary, $registration );
	assert_zip( $prefixes instanceof WP_Error, 'Prefix capture is bounded' );
	// A good prefix never bypasses a corrupt later payload or main CRC.
	package_error( packaged( plugin_source(), array( array( 'name' => 'fixture/readme.txt', 'body' => 'bad crc', 'local' => array( 'crc' => 1 ), 'central' => array( 'crc' => 1 ) ) ) ), 'github_updater_zip_content_crc' );
	package_error( packaged( plugin_source(), array(), 'v1.2', array( 'local' => array( 'crc' => 1 ), 'central' => array( 'crc' => 1 ) ) ), 'github_updater_zip_content_crc' );
	package_error( packaged( str_repeat( ' ', 8192 ) . plugin_source(), array(), 'v1.2', array( 'method' => 0 ) ), 'github_updater_package_headers' );
	$long = plugin_source() . str_repeat( 'x', 9000 );
	packaged( $long, array(), 'v1.2', array( 'method' => 0 ) );
	$prefixes = ( new Zip_Contents() )->plugin_prefixes( $temporary, $registration );
	assert_zip( 8192 === strlen( $prefixes['fixture/main.php'] ), 'Exact 8 KiB prefix cap despite larger body' );
	$parsed = Plugin_Headers::parse( "<?php /* Plugin Name: Inline */\n# Version: 1.2 ?> ignored\n@ Update URI: https://github.com/owner/repository\n" );
	assert_zip( 'Inline' === $parsed['Name'] && '1.2' === $parsed['Version'], 'Native optional PHP marker/comment cleanup syntax' );
	assert_zip( '' === Plugin_Headers::parse( "Requires PHP:0\n" )['RequiresPHP'], 'Native falsey header capture semantics preserved' );
	echo "PASS: full ZIP integrity + exact packaged headers/version/repository/runtime policy, bounded prefixes, ambiguity and dependency rejection (runtime doubles).\n";
} finally { unlink( $temporary ); }
