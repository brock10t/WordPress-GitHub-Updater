<?php
/**
 * Shared deterministic HTTP, transient and installed-plugin environment.
 *
 * @package TenTGitHubUpdater
 */
namespace TenT\GitHubUpdater\GitHub {
	function time(): int { return $GLOBALS['matrix_now']; }
}
namespace {
	use TenT\GitHubUpdater\GitHub\Api_Client;
	use TenT\GitHubUpdater\GitHub\Cached_Releases;
	use TenT\GitHubUpdater\GitHub\Check_State;
	use TenT\GitHubUpdater\GitHub\Release_Asset;
	use TenT\GitHubUpdater\GitHub\Release_Tag;
	use TenT\GitHubUpdater\GitHub\Repository;
	use TenT\GitHubUpdater\GitHub\Version_Comparison;
	use TenT\GitHubUpdater\Registry\Registration;
	use TenT\GitHubUpdater\Registry\Update_URI;

	define( 'ABSPATH', '/unused/' );
	define( 'DB_NAME', 'api-response-matrix' );
	require __DIR__ . '/class-lock-database-double.php';
	foreach ( array( 'repository', 'api-client', 'stable-releases', 'cached-releases', 'release-tag', 'version-comparison', 'release-asset', 'check-state' ) as $component ) {
		require dirname( __DIR__, 2 ) . '/includes/github/class-' . $component . '.php';
	}
	require dirname( __DIR__, 2 ) . '/includes/registry/class-registration.php';
	require dirname( __DIR__, 2 ) . '/includes/registry/class-update-uri.php';

	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function __( $text, $domain ) { return $text; }
	// Fixed valid HTTPS fixture only; sanitizer edge cases live in update-uri.php.
	function get_plugins() { return $GLOBALS['matrix_plugins']; }
	function sanitize_url( $url ) { return $url; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function is_multisite() { return false; }
	function get_current_blog_id() { return 1; }
	function wp_using_ext_object_cache() { return false; }
	function wp_cache_delete( $key, $group ) { return true; }
	function get_site_transient( $key ) { return $GLOBALS['matrix_cache'][ $key ] ?? false; }
	function set_site_transient( $key, $value, $ttl ) { $GLOBALS['matrix_cache'][ $key ] = $value; return true; }
	function delete_site_transient( $key ) { unset( $GLOBALS['matrix_cache'][ $key ] ); return true; }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
	function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
	function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
	function matrix_assert( bool $condition, string $description ): void {
		if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $description ); }
	}
	function wp_safe_remote_get( $url, $args ) {
		$expected = array_shift( $GLOBALS['matrix_responses'] );
		matrix_assert( null !== $expected && $url === $expected[0], 'Only the expected fixed-origin request is sent: ' . $url );
		matrix_assert( 0 === $args['redirection'] && true === $args['sslverify'] && 10 === $args['timeout'] && Api_Client::MAX_RESPONSE_BYTES + 1 === $args['limit_response_size'], 'Transport bounds retained throughout pipeline' );
		$GLOBALS['matrix_requests'][] = $url;
		return $expected[1];
	}
	function matrix_response( $data, $status = 200, $headers = array() ): array {
		return array( 'response' => array( 'code' => $status ), 'headers' => $headers + array( 'content-type' => 'application/json' ), 'body' => json_encode( $data ) );
	}
	function matrix_release( int $id = 1, string $tag = 'v1.1.0' ): array {
		return array(
			'id' => $id, 'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
			'published_at' => '2026-11-22T12:00:00Z',
			'zipball_url' => 'https://api.github.com/repos/owner/repository/zipball/' . $tag,
			'assets' => array( array(
				'id' => 100 + $id, 'name' => 'installed-plugin.zip', 'state' => 'uploaded', 'size' => 123,
				'url' => 'https://api.github.com/repos/owner/repository/releases/assets/' . ( 100 + $id ),
				'browser_download_url' => 'https://github.com/owner/repository/releases/download/' . $tag . '/installed-plugin.zip',
			) ),
		);
	}
	function matrix_reset( array $responses ): void {
		$GLOBALS['matrix_plugins'] = array( 'installed-plugin/main.php' => array( 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/owner/repository' ) );
		$GLOBALS['matrix_now'] = 1800000000;
		$GLOBALS['matrix_cache'] = array();
		$GLOBALS['matrix_requests'] = array();
		$GLOBALS['matrix_responses'] = $responses;
	}
	function matrix_complete( array $releases ): array {
		return array(
			array( 'https://api.github.com/repos/owner/repository', matrix_response( array( 'full_name' => 'Owner/Repository', 'private' => false ) ) ),
			array( 'https://api.github.com/repos/owner/repository/releases?per_page=100&page=1', matrix_response( $releases ) ),
			array( 'https://api.github.com/repos/owner/repository/releases?per_page=100&page=2', matrix_response( array() ) ),
		);
	}
	function matrix_error( $result, string $suffix, string $status ): void {
		matrix_assert( $result instanceof WP_Error && $result->get_error_code() === 'github_updater_' . $suffix, 'Expected error ' . $suffix );
		$state = Check_State::from_error( $result );
		matrix_assert( $state->is_error() && $state->status === $status, 'Failure remains distinct from successful empty or up-to-date results' );
		matrix_assert( ! str_contains( json_encode( $state->to_array() ), 'REMOTE_SECRET' ), 'No remote text in view state' );
	}
}
