<?php
/**
 * Offline numeric version ordering and unsupported-input checks.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\GitHub\Version_Comparison;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/github/class-release-tag.php';
require dirname( __DIR__ ) . '/includes/github/class-version-comparison.php';

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function check_version( bool $condition, string $description ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

$cases = array(
	array( 'v1.0.0', '1.0.0', 0 ),
	array( '1.2', '1.2.0.0', 0 ),
	array( 'v1.2.0.0', 'v1.2', 0 ),
	array( '0.0.0', '0.0', 0 ),
	array( 'v1.0.1', '1.0.0', 1 ),
	array( '1.10', '1.9', 1 ),
	array( '2.0.0', '1.999999999', 1 ),
	array( '1.2.0.1', '1.2', 1 ),
	array( '1.2.1', '1.2.0.1', 1 ),
	array( '1.2.3.4.6', '1.2.3.4.5', 1 ),
	array( '999999999.1', '999999998.999999999', 1 ),
	array( '2026.11.22', '2026.11.21', 1 ),
	array( 'v0.9.9', '1.0.0', -1 ),
	array( '1.2', '1.2.0.1', -1 ),
	array( '1.2' . str_repeat( '.0', 60 ), '1.2', 0 ),
);
foreach ( $cases as list( $release, $installed, $expected ) ) {
	check_version( $expected === Version_Comparison::compare( $release, $installed ), 'Correct order for ' . $release . ' against ' . $installed );
	check_version( -$expected === Version_Comparison::compare( $installed, $release ), 'Reversing supported inputs reverses ordering' );
}
foreach ( array( '', 'unknown', '1', '01.2', '1.0-beta', '1.2.3+build', '1.2.3 ', "1.2\n", '1000000000.0', '<script>1.0</script>' ) as $unsupported ) {
	$result = Version_Comparison::compare( '2.0.0', $unsupported );
	check_version( $result instanceof WP_Error && 'github_updater_unsupported_installed_version' === $result->get_error_code(), 'Unsupported installed version cannot be treated as zero' );
	check_version( ! str_contains( $result->message, '<script>' ), 'Installed error does not reflect raw input' );
	$result = Version_Comparison::compare( $unsupported, '1.0.0' );
	check_version( $result instanceof WP_Error && 'github_updater_unsupported_release_tag' === $result->get_error_code(), 'Unsupported release cannot be compared' );
}
check_version( Version_Comparison::compare( 'v2.0.0-rc.1', '1.0.0' ) instanceof WP_Error, 'RC-shaped tag is never a newer stable result' );
echo "PASS: 15 ordering/equivalence cases, reverse comparisons and unsupported-version diagnostics\n";
