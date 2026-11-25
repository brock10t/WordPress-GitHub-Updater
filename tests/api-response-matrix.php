<?php
/** Offline cross-component API response regressions. @package TenTGitHubUpdater */
	use TenT\GitHubUpdater\GitHub\Api_Client;
	use TenT\GitHubUpdater\GitHub\Cached_Releases;
	use TenT\GitHubUpdater\GitHub\Check_State;
	use TenT\GitHubUpdater\GitHub\Release_Asset;
	use TenT\GitHubUpdater\GitHub\Release_Tag;
	use TenT\GitHubUpdater\GitHub\Repository;
	use TenT\GitHubUpdater\GitHub\Version_Comparison;
	use TenT\GitHubUpdater\Registry\Registration;
	use TenT\GitHubUpdater\Registry\Update_URI;

	require __DIR__ . '/support/metadata-environment.php';
	set_error_handler( function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
	$wpdb = new Lock_Database_Double();
	$repository = Repository::from_url( 'https://github.com/owner/repository' );
	$registration = new Registration( 'installed-plugin/main.php', 'owner', 'repository' );
	$reader = new Cached_Releases();

	// Every failure is exercised before retrieval and after a valid first page.
	$failures = array(
		array( new WP_Error( 'http_request_failed', 'REMOTE_SECRET cURL error 28' ), 'api_timeout', 'unavailable' ),
		array( new WP_Error( 'http_request_failed', 'REMOTE_SECRET TLS failure' ), 'api_request_failed', 'unavailable' ),
		array( matrix_response( array( 'message' => 'REMOTE_SECRET' ), 401 ), 'api_access_denied', 'blocked' ),
		array( matrix_response( array(), 403 ), 'api_access_denied', 'blocked' ),
		array( matrix_response( array(), 404 ), 'api_not_found', 'blocked' ),
		array( matrix_response( array(), 410 ), 'api_resource_gone', 'blocked' ),
		array( matrix_response( array(), 429, array( 'retry-after' => '120' ) ), 'api_rate_limited', 'cooldown' ),
		array( matrix_response( array( 'message' => 'REMOTE_SECRET secondary rate limit' ), 403 ), 'api_rate_limited', 'cooldown' ),
		array( matrix_response( array(), 503 ), 'api_unavailable', 'unavailable' ),
		array( matrix_response( array(), 503, array( 'retry-after' => '120' ) ), 'api_unavailable', 'cooldown' ),
		array( matrix_response( array(), 302, array( 'location' => 'http://127.0.0.1/REMOTE_SECRET' ) ), 'api_redirect_refused', 'blocked' ),
		array( matrix_response( array(), 304 ), 'api_redirect_refused', 'blocked' ),
		array( matrix_response( array(), 204 ), 'api_http_error', 'blocked' ),
		array( matrix_response( array(), 600 ), 'api_invalid_response', 'blocked' ),
		array( matrix_response( array(), true ), 'api_invalid_response', 'blocked' ),
		array( matrix_response( array(), '200 OK' ), 'api_invalid_response', 'blocked' ),
	);
	foreach ( array( "[\"\xff\"]", '[1e400]', '[', "\xef\xbb\xbf[]", '[] REMOTE_SECRET' ) as $body ) {
		$response = matrix_response( array() );
		$response['body'] = $body;
		// Overflowed JSON numbers are not valid release entries, even when decoded.
		$failures[] = array( $response, '[1e400]' === $body ? 'invalid_release_metadata' : 'api_invalid_response', 'blocked' );
	}
	$response = matrix_response( array() );
	$response['body'] = str_repeat( ' ', Api_Client::MAX_RESPONSE_BYTES + 1 );
	$failures[] = array( $response, 'api_response_too_large', 'blocked' );
	$cases = 0;
	foreach ( $failures as list( $response, $suffix, $status ) ) {
		foreach ( array( 'repository', 'later_page' ) as $stage ) {
			// A list root is invalid repository metadata before entry validation.
			$expected = 'repository' === $stage && 'invalid_release_metadata' === $suffix ? 'api_invalid_response' : $suffix;
			$queue = matrix_complete( array( matrix_release() ) );
			$queue = 'repository' === $stage ? array( array( $queue[0][0], $response ) ) : array( $queue[0], $queue[1], array( $queue[2][0], $response ) );
			matrix_reset( $queue );
			matrix_error( $reader->get( $repository ), $expected, $status );
			matrix_assert( array() === $matrix_responses, 'Exactly the planned requests, no retry or redirect' );
			$before = count( $matrix_requests );
			matrix_error( ( new Cached_Releases() )->get( $repository ), $expected, $status );
			matrix_assert( $before === count( $matrix_requests ), 'Cached failures suppress HTTP and never return an earlier partial candidate' );
			if ( 'cooldown' === $status ) {
				$reader->invalidate( $repository );
				matrix_error( $reader->get( $repository, true ), $expected, $status );
				matrix_assert( $before === count( $matrix_requests ), 'Invalidation and force preserve server waits across presentation' );
			}
			matrix_assert( ! $wpdb->locked && ! $wpdb->suppressed, 'Lock and error-output state restored' );
			++$cases;
		}
	}

	// Public identity and pagination failures must stay errors across cache reads.
	foreach ( array(
		array( array( 'full_name' => 'owner/other', 'private' => false ), 'repository_identity_mismatch' ),
		array( array( 'full_name' => 'owner/repository', 'private' => true ), 'repository_not_public' ),
		array( array( 'full_name' => 'owner/repository', 'private' => 'false' ), 'api_invalid_response' ),
	) as list( $metadata, $suffix ) ) {
		matrix_reset( array( array( 'https://api.github.com/repos/owner/repository', matrix_response( $metadata ) ) ) );
		matrix_error( $reader->get( $repository ), $suffix, 'blocked' );
		matrix_error( $reader->get( $repository ), $suffix, 'blocked' );
		matrix_assert( 1 === count( $matrix_requests ), 'Invalid public identity prevents all release requests' );
	}
	$queue = matrix_complete( array( matrix_release() ) );
	$queue[2][1] = matrix_response( array( matrix_release() ) );
	matrix_reset( $queue );
	matrix_error( $reader->get( $repository ), 'release_scan_changed', 'incomplete' );
	matrix_error( $reader->get( $repository ), 'release_scan_changed', 'incomplete' );
	matrix_assert( 3 === count( $matrix_requests ), 'Duplicate page fails completely and is backed off' );
	$queue = matrix_complete( array( matrix_release() ) );
	$queue = array( $queue[0] );
	for ( $page = 1; $page <= 5; ++$page ) {
		$queue[] = array( 'https://api.github.com/repos/owner/repository/releases?per_page=100&page=' . $page, matrix_response( array( matrix_release( $page ) ) ) );
	}
	matrix_reset( $queue );
	matrix_error( $reader->get( $repository ), 'release_scan_incomplete', 'incomplete' );
	matrix_error( $reader->get( $repository ), 'release_scan_incomplete', 'incomplete' );
	matrix_assert( 6 === count( $matrix_requests ), 'Bound reached without an extra request or partial success' );

	// Short pages and untrusted Link headers never terminate or redirect scans.
	$queue = matrix_complete( array( matrix_release( 1, 'v9.0' ) ) );
	$queue[1][1]['headers']['link'] = '<http://127.0.0.1/REMOTE_SECRET>; rel="next"';
	$queue[2][1] = matrix_response( array( array_replace( matrix_release( 2, 'v1.1' ), array( 'published_at' => '2026-11-23T12:00:00Z' ) ) ) );
	$queue[] = array( 'https://api.github.com/repos/owner/repository/releases?per_page=100&page=3', matrix_response( array() ) );
	matrix_reset( $queue );
	$candidates = $reader->get( $repository );
	matrix_assert( array( 2, 1 ) === array_column( $candidates, 'id' ) && 4 === count( $matrix_requests ), 'Complete fixed-endpoint scan orders publication, not numeric versions' );
	matrix_assert( 1 === Version_Comparison::compare( $candidates[1]['tag_name'], $candidates[0]['tag_name'] ), 'First publication candidate cannot be assumed highest numeric version' );

	// Retrieval's success cache does not imply downstream eligibility.
	$mutations = array(
		'bad tag' => array( 'tag_name' => 'v1.1.0-beta' ),
		'missing ZIP' => array( 'assets' => array() ),
		'ambiguous ZIP' => array( 'assets' => array( matrix_release()['assets'][0], matrix_release()['assets'][0] ) ),
		'foreign asset' => array( 'assets' => array( array_replace( matrix_release()['assets'][0], array( 'browser_download_url' => 'https://github.com/other/repository/releases/download/v1.1.0/installed-plugin.zip' ) ) ) ),
		'incomplete upload' => array( 'assets' => array( array_replace( matrix_release()['assets'][0], array( 'state' => 'new' ) ) ) ),
	);
	foreach ( $mutations as $name => $changes ) {
		matrix_reset( matrix_complete( array( array_replace( matrix_release(), $changes ) ) ) );
		$reader->get( $repository );
		$warm = $reader->get( $repository );
		$error = Release_Asset::select( $warm[0], $registration );
		$expected = array( 'bad tag' => 'unsupported_release_tag', 'missing ZIP' => 'release_asset_missing', 'ambiguous ZIP' => 'release_asset_ambiguous', 'foreign asset' => 'release_asset_url_mismatch', 'incomplete upload' => 'invalid_asset_metadata' )[ $name ];
		matrix_error( $error, $expected, 'blocked' );
		matrix_assert( 3 === count( $matrix_requests ), 'Warm metadata still needs tag/asset checks without HTTP: ' . $name );
	}

	matrix_reset( matrix_complete( array( matrix_release() ) ) );
	$release = $reader->get( $repository )[0];
	matrix_assert( '1.1.0' === Release_Tag::normalize( $release['tag_name'] ), 'Normalize a real decoded tag' );
	matrix_assert( 1 === Version_Comparison::compare( $release['tag_name'], '1.0.0' ), 'Decoded tag compares newer' );
	matrix_assert( Release_Asset::select( $release, $registration ) instanceof Release_Asset, 'Identify metadata pointer with installed directory distinct from repository name' );
	foreach ( array( '1.1', '1.2.0' ) as $installed ) {
		$warm = $reader->get( $repository );
		matrix_assert( Version_Comparison::compare( $warm[0]['tag_name'], $installed ) <= 0, 'Fresh installed version can make warm candidate equal or older' );
	}
	matrix_error( Version_Comparison::compare( $release['tag_name'], 'unknown' ), 'unsupported_installed_version', 'blocked' );
	matrix_error( Release_Asset::select( $release, new Registration( 'different-plugin/main.php', 'owner', 'repository' ) ), 'release_asset_missing', 'blocked' );
	matrix_assert( true === Update_URI::check( array( 'UpdateURI' => $repository->url ), $repository ), 'Matching current header prerequisite accepted' );
	matrix_error( Update_URI::check( array( 'UpdateURI' => 'false' ), $repository ), 'disabled_update_uri', 'blocked' );
	matrix_assert( 3 === count( $matrix_requests ), 'Installed version, directory and header are never taken from the metadata cache' );

	$draft = matrix_release( 2, 'v99.0' ); $draft['draft'] = true;
	$preview = matrix_release( 3, 'v99.1' ); $preview['prerelease'] = true;
	$unpublished = matrix_release( 4, 'v99.2' ); $unpublished['published_at'] = null;
	matrix_reset( matrix_complete( array( $draft, $preview, $unpublished ) ) );
	matrix_assert( array() === $reader->get( $repository ) && array() === $reader->get( $repository ), 'Excluded-only scan remains a successful cached empty list' );
	matrix_assert( ! Check_State::no_releases()->is_error() && 3 === count( $matrix_requests ), 'Empty scan is distinct from error backoff' );

	// A forced later-page failure must replace a previous complete success.
	matrix_reset( matrix_complete( array( matrix_release() ) ) );
	$reader->get( $repository );
	$matrix_responses = matrix_complete( array( matrix_release( 2, 'v1.2.0' ) ) );
	$matrix_responses[2][1] = new WP_Error( 'http_request_failed', 'REMOTE_SECRET timeout' );
	matrix_error( $reader->get( $repository, true ), 'api_timeout', 'unavailable' );
	matrix_error( $reader->get( $repository ), 'api_timeout', 'unavailable' );
	matrix_assert( 6 === count( $matrix_requests ), 'Neither stale success nor partial newer metadata survives failed force' );
	$matrix_now += 300;
	$matrix_responses = matrix_complete( array( matrix_release( 2, 'v1.2.0' ) ) );
	matrix_assert( 2 === $reader->get( $repository )[0]['id'] && 9 === count( $matrix_requests ), 'Expired error backoff allows complete recovery' );
	echo 'PASS: ' . $cases . " staged API failures, cache/state propagation, downstream gates and recovery (offline doubles; no production selector).\n";
