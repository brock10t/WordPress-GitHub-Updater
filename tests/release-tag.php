<?php
/**
 * Offline stable release-tag grammar and normalization checks.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\GitHub\Release_Tag;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/github/class-release-tag.php';

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function check_tag( bool $condition, string $description ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$valid = array(
	'v1.0.0'           => '1.0.0',
	'1.2.3'            => '1.2.3',
	'v1.2'             => '1.2',
	'0.0'              => '0.0',
	'v0.1.0'           => '0.1.0',
	'1.2.0.0'          => '1.2.0.0',
	'1.2.3.4.5'        => '1.2.3.4.5',
	'2026.11.22'       => '2026.11.22',
	'999999999.0'      => '999999999.0',
);
$boundary                        = '11' . str_repeat( '.1', 63 );
$valid[ $boundary ]              = $boundary;
$valid[ 'v' . $boundary ]        = $boundary;
foreach ( $valid as $input => $expected ) {
	$version  = Release_Tag::normalize( $input );
	check_tag( $expected === $version, 'Normalize accepted tag: ' . $input );
	check_tag( $version === Release_Tag::normalize( $version ), 'Normalization is idempotent' );
}

$invalid = array(
	'', 'v', '1', 'v1', 'V1.2.3', 'vv1.2.3', 'release-1.2.3',
	' 1.2.3', '1.2.3 ', "1.2.3\n", "\t1.2.3", "1.2.\0" . '3',
	'01.2.3', '1.02.3', '1.2.00', '-1.2.3', '+1.2.3',
	'1..2', '1.2.', '.1.2', '1/2/3', '1_2_3', '１.２.３',
	'1.2.3-alpha', '1.2.3-beta.1', '1.2.3-rc.1', '1.2.3RC1', '1.2.3-dev',
	'v1.2.3-preview', '1.2.3+build.4', '1.2.3.4foo', 'main', 'stable',
	'abc123def', 'refs/tags/v1.2.3', 'https://github.com/owner/repo/releases/tag/v1.2.3',
	'1000000000.1', '1.9999999999', $boundary . '1',
	'<script>1.2.3</script>',
);
foreach ( $invalid as $input ) {
	$result = Release_Tag::normalize( $input );
	check_tag( $result instanceof WP_Error && 'github_updater_unsupported_release_tag' === $result->get_error_code(), 'Reject unsupported tag' );
	check_tag( ! str_contains( $result->message, '<script>' ), 'Do not reflect untrusted tag content' );
}

// A repository author's stable flag cannot turn an RC tag into a stable version.
$release = array( 'draft' => false, 'prerelease' => false, 'tag_name' => 'v2.0.0-rc.1' );
check_tag( Release_Tag::normalize( $release['tag_name'] ) instanceof WP_Error, 'Reject prerelease-shaped tag despite stable metadata flags' );
echo 'PASS: ' . count( $valid ) . ' normalized tags, ' . count( $invalid ) . " rejected tags, boundaries and stable-flag defense\n";
