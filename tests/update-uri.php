<?php
/**
 * Update URI diagnostics with lightweight WordPress URL/error doubles.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\GitHub\Repository;
use TenT\GitHubUpdater\Registry\Update_URI;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/github/class-repository.php';
require dirname( __DIR__ ) . '/includes/registry/class-update-uri.php';

class WP_Error {
	public function __construct( public string $code, public string $message, public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
function __( $text, $domain ) { return $text; }
function sanitize_url( $url ) { return trim( $url, ' ' ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function check_update_uri( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

$repository = Repository::from_url( 'https://github.com/owner/repository' );
foreach ( array(
	'https://github.com/owner/repository',
	'https://github.com/OWNER/Repository',
	'https://github.com/Owner/Repository.git/',
	' HTTPS://github.com/owner/repository/ ',
) as $uri ) {
	$headers = array( 'UpdateURI' => $uri, 'Name' => '<unsafe display name>', 'Version' => '1.0.0' );
	$before  = $headers;
	check_update_uri( true === Update_URI::check( $headers, $repository ), 'Compatible identity and native hook: ' . $uri );
	check_update_uri( $headers === $before, 'Headers unchanged' );
}

$cases = array(
	array( array(), 'missing_update_uri' ),
	array( array( 'UpdateURI' => '' ), 'missing_update_uri' ),
	array( array( 'UpdateURI' => '   ' ), 'missing_update_uri' ),
	array( array( 'UpdateURI' => 'false' ), 'disabled_update_uri' ),
	array( array( 'UpdateURI' => ' FALSE ' ), 'disabled_update_uri' ),
	array( array( 'UpdateURI' => null ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => false ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 0 ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => array() ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => '0' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => '<script>alert(1)</script>' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'https://wordpress.org/plugins/repository' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'http://github.com/owner/repository' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'https://github.com/owner/repository/releases' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'https://github.com/owner/repository?x=1' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'https://github.com/owner/repository%2fother' ), 'invalid_update_uri' ),
	array( array( 'UpdateURI' => 'https://github.com/other/repository' ), 'update_uri_mismatch' ),
	array( array( 'UpdateURI' => 'https://github.com/owner/other' ), 'update_uri_mismatch' ),
	array( array( 'UpdateURI' => 'https://GITHUB.COM/owner/repository' ), 'update_uri_hostname_mismatch' ),
	array( array( 'UpdateURI' => 'https://GitHub.com/OWNER/Repository.git/' ), 'update_uri_hostname_mismatch' ),
);
foreach ( $cases as list( $headers, $code ) ) {
	$before = $headers;
	$result = Update_URI::check( $headers, $repository );
	check_update_uri( $result instanceof WP_Error && 'github_updater_' . $code === $result->get_error_code(), 'Diagnostic: ' . $code );
	check_update_uri( array( 'expected_update_uri' => $repository->url ) === $result->get_error_data(), 'Provide canonical correction, not unsafe original header' );
	check_update_uri( ! str_contains( $result->message, '<script>' ), 'Do not reflect unsafe header text' );
	check_update_uri( $headers === $before, 'Diagnostic leaves headers unchanged' );
}
echo 'PASS: four compatible headers and ' . count( $cases ) . " Update URI diagnostics; no header mutations\n";
