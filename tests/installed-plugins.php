<?php
/**
 * Exact installed-plugin identity checks using a WordPress discovery double.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\Registry\Installed_Plugins;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';
require dirname( __DIR__ ) . '/includes/registry/class-installed-plugins.php';

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function get_plugins() { return $GLOBALS['fixtures']; }
function check_discovery( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}
function expect_discovery_error( $result, $code ) {
	check_discovery( $result instanceof WP_Error && $result->get_error_code() === $code, 'Expected error: ' . $code );
}

$fixtures = array(
	'Installed-Directory/custom-main.php' => array( 'Name' => 'Same Display Name', 'Version' => '1.2.3', 'UpdateURI' => 'https://github.com/owner/different-repository' ),
	'inactive/main.php'                  => array( 'Name' => 'Same Display Name', 'Version' => '2.0.0', 'UpdateURI' => '' ),
	'inactive/another-main.php'          => array( 'Name' => 'Another Entry Point', 'Version' => '3.0.0', 'UpdateURI' => '' ),
	'legacy.php'                        => array( 'Name' => 'Standalone', 'Version' => '1.0.0', 'UpdateURI' => '' ),
);
$discovery = new Installed_Plugins();
check_discovery( $fixtures === $discovery->all(), 'Preserve all exact keys and raw metadata, including standalone and inactive plugins' );
check_discovery( $fixtures['Installed-Directory/custom-main.php'] === $discovery->get( 'Installed-Directory/custom-main.php' ), 'Resolve custom main file with different repository name' );
check_discovery( $fixtures['inactive/main.php'] === $discovery->get( 'inactive/main.php' ), 'Inactive plugin is selectable' );
check_discovery( $fixtures['inactive/another-main.php'] === $discovery->get( 'inactive/another-main.php' ), 'Distinct main files are not collapsed by directory' );
expect_discovery_error( $discovery->get( 'legacy.php' ), 'github_updater_unsupported_plugin_basename' );
foreach ( array( '', 'Same Display Name', 'different-repository/custom-main.php', 'installed-directory/custom-main.php', '/Installed-Directory/custom-main.php', 'Installed-Directory/../inactive/main.php', 'Installed-Directory\\custom-main.php', 'Installed-Directory%2fcustom-main.php', 'Installed-Directory/custom-main.php ' ) as $candidate ) {
	expect_discovery_error( $discovery->get( $candidate ), 'github_updater_plugin_not_installed' );
}
unset( $fixtures['inactive/main.php'] );
expect_discovery_error( $discovery->get( 'inactive/main.php' ), 'github_updater_plugin_not_installed' );
check_discovery( $fixtures === $discovery->all(), 'No additional persistent discovery cache' );

// Even malformed entries returned by another component cannot bypass model policy.
$fixtures['bad/../main.php'] = array( 'Name' => 'Malformed' );
expect_discovery_error( $discovery->get( 'bad/../main.php' ), 'github_updater_unsupported_plugin_basename' );
$fixtures = array();
check_discovery( array() === $discovery->all(), 'Empty installation' );
echo "PASS: canonical installed-plugin discovery and exact lookup\n";
