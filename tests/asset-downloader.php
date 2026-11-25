<?php
/** Bounded streamed download/redirect/temp lifecycle with real temporary files. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages {
	function hrtime( $as_number ) { return $GLOBALS['download_clock'] * 1000000000; }
}
namespace {
	use TenT\GitHubUpdater\GitHub\Release_Asset;
	use TenT\GitHubUpdater\Packages\Asset_Downloader;
	use TenT\GitHubUpdater\Registry\Registration;
	define( 'ABSPATH', '/unused/' );
	foreach ( array( 'github/class-repository.php', 'github/class-release-tag.php', 'github/class-release-asset.php', 'github/class-api-client.php', 'registry/class-registration.php', 'packages/class-asset-downloader.php' ) as $file ) { require dirname( __DIR__ ) . '/includes/' . $file; }
	class WP_Error { public function __construct( public string $code, public string $message ) {} }
	function __( $text, $domain ) { return $text; }
	function verify_download( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $message ); } }
	function wp_tempnam( $name ) { if ( $GLOBALS['fail_temp'] ) { return false; } $path = tempnam( sys_get_temp_dir(), '10t-download-' ); $GLOBALS['temporary_paths'][] = $path; return $path; }
	function wp_delete_file( $path ) { if ( ! $GLOBALS['fail_delete'] && file_exists( $path ) ) { unlink( $path ); } }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
	function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
	function wp_safe_remote_get( $url, $args ) {
		$expected = array_shift( $GLOBALS['responses'] );
		verify_download( null !== $expected && $expected[0] === $url, 'Only expected endpoint requested' );
		verify_download( $args['stream'] && $args['sslverify'] && 0 === $args['redirection'] && false === $args['decompress'] && 0 < $args['timeout'] && $args['timeout'] <= 20 && 101 === $args['limit_response_size'], 'Stream/TLS/time/byte limits applied on every hop' );
		verify_download( 'identity' === $args['headers']['Accept-Encoding'] && 'application/octet-stream' === $args['headers']['Accept'] && ! isset( $args['headers']['Authorization'] ) && array() === $args['cookies'], 'No credentials or content encoding' );
		verify_download( str_starts_with( $url, 'https://api.github.com/' ) === isset( $args['headers']['X-GitHub-Api-Version'] ), 'API headers not forwarded to storage' );
		verify_download( 0 === filesize( $args['filename'] ) && 0600 === ( fileperms( $args['filename'] ) & 0777 ), 'Restricted temporary file cleared before each hop' );
		$GLOBALS['requests'][] = $url;
		$GLOBALS['download_clock'] += $expected[3] ?? 0;
		if ( isset( $expected[2] ) ) { file_put_contents( $args['filename'], $expected[2] ); }
		if ( $GLOBALS['throw_http'] ) { throw new \LogicException( 'Fixture exception' ); }
		return $expected[1];
	}
	function response( $status = 200, $headers = array() ) { return array( 'response' => array( 'code' => $status ), 'headers' => $headers ); }
	function setup_download( array $queue ): void {
		$GLOBALS['responses'] = $queue; $GLOBALS['requests'] = array(); $GLOBALS['download_clock'] = 1000.0;
		$GLOBALS['fail_temp'] = false; $GLOBALS['fail_delete'] = false; $GLOBALS['throw_http'] = false;
	}
	function make_asset( int $size = 100 ): Release_Asset {
		$record = new Registration( 'fixture/main.php', 'owner', 'repository' );
		return Release_Asset::select( array( 'tag_name' => 'v1.1', 'assets' => array( array( 'id' => 123, 'name' => 'fixture.zip', 'size' => $size, 'state' => 'uploaded', 'url' => 'https://api.github.com/repos/owner/repository/releases/assets/123', 'browser_download_url' => 'https://github.com/owner/repository/releases/download/v1.1/fixture.zip' ) ) ), $record );
	}
	function expect_download_error( $result, string $suffix ): void {
		verify_download( $result instanceof WP_Error && 'github_updater_download_' . $suffix === $result->code, 'Expected download error: ' . $suffix );
		verify_download( ! str_contains( $result->message, 'REMOTE_SECRET' ), 'Remote text and signed queries excluded from diagnostics' );
		foreach ( $GLOBALS['temporary_paths'] as $path ) { verify_download( ! file_exists( $path ), 'Temporary files cleaned on errors' ); }
	}
	set_error_handler( function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
	$temporary_paths = array();
	$downloader = new Asset_Downloader(); $asset = make_asset();
	$api = $asset->api_url; $browser = $asset->download_url;
	$cdn = 'https://release-assets.githubusercontent.com/github-production-release-asset/42/abc-123?sig=REMOTE_SECRET%2F1';
	$body = str_repeat( 'x', 100 ); $called = 0;
	$consumer = function ( $path ) use ( &$called, $body ) { ++$called; verify_download( $body === file_get_contents( $path ), 'Consumer sees only final bytes' ); return 'consumed'; };
	foreach ( array( array( array( $api, response( 200, array( 'content-length' => '100' ) ), $body ) ), array( array( $api, response( 302, array( 'location' => $cdn ) ), 'redirect body' ), array( $cdn, response(), $body ) ), array( array( $api, response( 302, array( 'location' => $browser ) ) ), array( $browser, response( 307, array( 'location' => $cdn ) ) ), array( $cdn, response(), $body ) ) ) as $queue ) {
		setup_download( $queue ); verify_download( 'consumed' === $downloader->with_file( $asset, $consumer ), 'Direct/redirected success returns consumer result' );
		verify_download( ! file_exists( end( $temporary_paths ) ), 'Success path cleaned after consumer' );
	}
	$before = $called;
	foreach ( array( 'http://release-assets.githubusercontent.com/github-production-release-asset/42/a', 'https://127.0.0.1/REMOTE_SECRET', 'https://release-assets.githubusercontent.com.evil.test/github-production-release-asset/42/a', 'https://user@release-assets.githubusercontent.com/github-production-release-asset/42/a', 'https://release-assets.githubusercontent.com:443/github-production-release-asset/42/a', 'https://release-assets.githubusercontent.com/github-production-release-asset/42/../a', 'https://release-assets.githubusercontent.com/github-production-release-asset/42/%61', 'https://github.com/other/repo/releases/download/v1/fixture.zip', '//release-assets.githubusercontent.com/REMOTE_SECRET', '/relative', $cdn . '#fragment', $cdn . "\n", array( $cdn ) ) as $redirect ) {
		setup_download( array( array( $api, response( 302, array( 'location' => $redirect ) ) ) ) );
		expect_download_error( $downloader->with_file( $asset, $consumer ), 'redirect' ); verify_download( 1 === count( $requests ), 'Rejected Location never requested' );
	}
	foreach ( array( array( new WP_Error( 'http_request_failed', 'REMOTE_SECRET' ), 'request', 'partial' ), array( response( 404 ), 'http', '' ), array( response( 429 ), 'http', '' ), array( response( 503 ), 'http', '' ), array( response( 206 ), 'http', $body ), array( response( 304 ), 'http', '' ), array( response( true ), 'response', '' ), array( null, 'response', '' ), array( response(), 'size', '' ), array( response(), 'size', 'short' ), array( response(), 'size', str_repeat( 'x', 101 ) ), array( response( 200, array( 'content-length' => '99' ) ), 'response', $body ), array( response( 200, array( 'content-length' => array( '100' ) ) ), 'response', $body ), array( response( 200, array( 'content-encoding' => 'gzip' ) ), 'response', $body ) ) as list( $reply, $code, $bytes ) ) {
		setup_download( array( array( $api, $reply, $bytes ) ) ); expect_download_error( $downloader->with_file( $asset, $consumer ), $code );
	}
	$objects = 'https://objects.githubusercontent.com/github-production-release-asset-2e65be/42/abc-123?sig=opaque';
	setup_download( array( array( $api, response( 302, array( 'location' => $objects ) ) ), array( $objects, response(), $body ) ) );
	verify_download( 'consumed' === $downloader->with_file( $asset, $consumer ), 'Exact legacy storage endpoint supported' );
	$before = $called;
	foreach ( array( str_replace( '/42/', '/99/', $cdn ), $browser, str_replace( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', $cdn ) ) as $changed ) {
		setup_download( array( array( $api, response( 302, array( 'location' => $cdn ) ) ), array( $cdn, response( 302, array( 'location' => $changed ) ) ) ) );
		expect_download_error( $downloader->with_file( $asset, $consumer ), 'redirect' );
		verify_download( 2 === count( $requests ), 'Storage host/path pinned after first GitHub-issued location' );
	}
	setup_download( array( array( $api, response( 302, array( 'location' => $api ) ) ) ) ); expect_download_error( $downloader->with_file( $asset, $consumer ), 'redirect' );
	$cdn2 = str_replace( 'sig=REMOTE_SECRET%2F1', 'sig=2', $cdn );
	$cdn3 = str_replace( 'sig=REMOTE_SECRET%2F1', 'sig=3', $cdn );
	setup_download( array( array( $api, response( 302, array( 'location' => $cdn ) ) ), array( $cdn, response( 302, array( 'location' => $cdn2 ) ) ), array( $cdn2, response( 302, array( 'location' => $cdn3 ) ) ), array( $cdn3, response( 302, array( 'location' => $cdn . '4' ) ) ) ) );
	expect_download_error( $downloader->with_file( $asset, $consumer ), 'redirect' ); verify_download( 4 === count( $requests ), 'Hop bound sends at most four requests' );
	setup_download( array( array( $api, response(), $body, 61 ) ) ); expect_download_error( $downloader->with_file( $asset, $consumer ), 'timeout' );
	verify_download( $before === $called, 'No failure invokes consumer' );
	setup_download( array() ); $fail_temp = true; expect_download_error( $downloader->with_file( $asset, $consumer ), 'temporary_file' );
	setup_download( array() ); expect_download_error( $downloader->with_file( make_asset( Asset_Downloader::MAX_BYTES + 1 ), $consumer ), 'metadata' ); verify_download( array() === $requests, 'Excess declared size never requests data' );
	foreach ( array( 'http', 'consumer' ) as $where ) {
		setup_download( array( array( $api, response(), $body ) ) ); $throw_http = 'http' === $where;
		try { $downloader->with_file( $asset, function () { throw new \LogicException( 'Consumer exception' ); } ); throw new \RuntimeException( 'Expected exception' ); } catch ( \LogicException $expected ) {}
		verify_download( ! file_exists( end( $temporary_paths ) ), 'Exception cleans temporary file' );
	}
	setup_download( array( array( $api, response(), $body ) ) );
	$consumer_error = new WP_Error( 'fixture_invalid_package', 'Consumer validation failed' );
	verify_download( $consumer_error === $downloader->with_file( $asset, fn( $path ) => $consumer_error ) && ! file_exists( end( $temporary_paths ) ), 'Consumer rejection propagates after cleanup' );
	setup_download( array( array( $api, response(), $body ) ) ); $fail_delete = true;
	$result = $downloader->with_file( $asset, $consumer );
	verify_download( $result instanceof WP_Error && 'github_updater_download_cleanup' === $result->code, 'Failed cleanup reported' );
	unlink( end( $temporary_paths ) );
	echo "PASS: bounded streamed downloads, strict redirect hops, response/size/deadline checks and temporary-file lifecycle (HTTP doubles).\n";
}
