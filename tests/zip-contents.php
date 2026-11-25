<?php
/** Actual payload integrity/resource checks with real ZIP bytes. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages {
	function hrtime( $number ) { $GLOBALS['content_clock'] += $GLOBALS['content_clock_step']; return $GLOBALS['content_clock'] * 1000000000; }
}
namespace {
	use TenT\GitHubUpdater\Packages\Zip_Contents;
	use TenT\GitHubUpdater\Packages\Zip_Inspector;
	use TenT\GitHubUpdater\Registry\Registration;
	require __DIR__ . '/support/zip-environment.php';
	require dirname( __DIR__ ) . '/includes/packages/class-zip-contents.php';
	set_error_handler( function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
	$temporary = tempnam( sys_get_temp_dir(), '10t-content-test-' );
	$registration = new Registration( 'fixture/main.php', 'owner', 'repository' );
	$inspector = new Zip_Inspector();
	$content = new Zip_Contents();
	$content_clock = 1000.0; $content_clock_step = 0.0;
	function content_bytes( string $bytes ) {
		global $temporary, $content, $registration;
		file_put_contents( $temporary, $bytes ); clearstatcache( true, $temporary );
		$hash = hash_file( 'sha256', $temporary );
		$result = $content->check( $temporary, $registration );
		assert_zip( $hash === hash_file( 'sha256', $temporary ), 'Content validation never changes input bytes' );
		return $result;
	}
	function content_error( string $bytes, string $suffix ): void {
		$result = content_bytes( $bytes );
		assert_zip( $result instanceof WP_Error && 'github_updater_zip_content_' . $suffix === $result->code, 'Expected content ' . $suffix . ', got ' . ( $result instanceof WP_Error ? $result->code : 'success' ) );
		assert_zip( ! str_contains( $result->message, 'REMOTE_SECRET' ), 'No raw payload or path in message' );
	}
	try {
		if ( 'memory' === ( $argv[1] ?? '' ) ) {
			assert_zip( true === $content->check( $argv[2], $registration ), '100 MiB expanded ZIP validates under 16 MiB PHP memory limit' );
			echo "PASS: 100 MiB of expanded payload validated with memory_limit=16M.\n";
		} elseif ( 'unsupported' === ( $argv[1] ?? '' ) ) {
			assert_zip( true === content_bytes( zip_bytes( array( array( 'method' => 0 ) ) ) ), 'Stored ZIP remains supported without zlib streaming' );
			content_error( zip_bytes( array( array() ) ), 'unsupported' );
			echo "PASS: unavailable zlib streaming fails closed; stored payloads still work.\n";
		} else {
			foreach ( independent_zip_vectors() as $vector ) { assert_zip( true === content_bytes( base64_decode( $vector, true ) ), 'Independent stored/deflated writer payloads verified' ); }
			foreach ( array( 0, 8 ) as $method ) {
				foreach ( array( false, 'signed', 'unsigned' ) as $descriptor ) {
					assert_zip( true === content_bytes( zip_bytes( array( array( 'method' => $method, 'descriptor' => $descriptor ) ) ) ), 'Stored/deflated payload and descriptor validation' );
				}
				$dir = array( 'name' => 'fixture/', 'body' => '', 'method' => $method, 'external' => ( 0040755 << 16 ) | 0x10 );
				assert_zip( true === content_bytes( zip_bytes( array( $dir, array( 'method' => $method ), array( 'name' => 'fixture/empty', 'body' => '', 'method' => $method ) ) ) ), 'Empty payloads and directory CRC checked' );
				content_error( zip_bytes( array( array( 'method' => $method, 'local' => array( 'crc' => 123 ), 'central' => array( 'crc' => 123 ) ) ) ), 'crc' );
			}
			$body = 'nonrepeating deterministic text REMOTE_SECRET 0123456789 abcdefghijklmnop';
			foreach ( array( strlen( $body ) - 1, strlen( $body ) + 1 ) as $size ) {
				content_error( zip_bytes( array( array( 'body' => $body, 'local' => array( 'size' => $size ), 'central' => array( 'size' => $size ) ) ) ), 'size' );
			}
			foreach ( array( substr( gzdeflate( $body ), 0, -1 ), gzdeflate( $body ) . 'junk', gzdeflate( $body ) . gzdeflate( $body ), 'not a deflate stream REMOTE_SECRET', gzencode( $body ), gzcompress( $body ) ) as $compressed ) {
				content_error( zip_bytes( array( array( 'body' => $body, 'compressed_body' => $compressed ) ) ), 'invalid' );
			}
			// Same-size stored corruption is caught by CRC, not just byte counts.
			content_error( zip_bytes( array( array( 'body' => $body, 'method' => 0, 'compressed_body' => str_repeat( 'x', strlen( $body ) ) ) ) ), 'crc' );
			// False declarations pass structural/manifest policy, actual inflation fails early.
			$bomb = array( 'body' => str_repeat( 'A', 20971521 ), 'local' => array( 'size' => 20971520 ), 'central' => array( 'size' => 20971520 ) );
			// Pad with incompressible prefix to fit the manifest's <=100:1 declaration ratio.
			$seed = ''; for ( $i = 0; $i < 8192; ++$i ) { $seed .= hash( 'sha256', (string) $i, true ); }
			$bomb['body'] = $seed . str_repeat( 'A', 20971521 );
			content_error( zip_bytes( array( $bomb ) ), 'limits' );
			unset( $bomb );
			// Cross many input/output chunks without retaining complete decompressed files.
			$body = ''; for ( $i = 0; $i < 32768; ++$i ) { $body .= hash( 'sha256', 'fixture-' . $i, true ); }
			assert_zip( true === content_bytes( zip_bytes( array( array( 'body' => $body ) ) ) ), 'Multi-chunk deflate stream with exact consumed byte count' );
			$body = str_repeat( 's', 20971520 );
			assert_zip( true === content_bytes( zip_bytes( array( array( 'body' => $body, 'method' => 0 ) ) ) ), 'Actual file limit inclusive for stored data' );
			unset( $body );
			$prefix = ''; for ( $i = 0; $i < 10240; ++$i ) { $prefix .= hash( 'sha256', 'aggregate-' . $i, true ); }
			$boundary_body = $prefix . str_repeat( 'A', 20971520 - strlen( $prefix ) );
			$entries = array();
			for ( $i = 0; $i < 5; ++$i ) { $entries[] = array( 'name' => 0 === $i ? 'fixture/main.php' : 'fixture/part-' . $i, 'body' => $boundary_body ); }
			assert_zip( true === content_bytes( zip_bytes( $entries ) ), 'Actual expanded aggregate limit is inclusive at 100 MiB' );
			$command = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=16M ' . escapeshellarg( __FILE__ ) . ' memory ' . escapeshellarg( $temporary );
			passthru( $command, $memory_status ); assert_zip( 0 === $memory_status, 'Bounded-memory payload validation subprocess' );
			unset( $entries, $boundary_body, $prefix );
			$content_clock_step = 31.0;
			content_error( zip_bytes( array( array() ) ), 'timeout' );
			$content_clock_step = 0.0;
			$closed = null;
			file_put_contents( $temporary, zip_bytes( array( array() ) ) ); clearstatcache( true, $temporary );
			try { $inspector->with_entries( $temporary, $registration, function ( $handle, $records ) use ( &$closed ) { $closed = $handle; throw new \LogicException( 'Consumer exception' ); } ); } catch ( \LogicException $expected ) {}
			assert_zip( ! is_resource( $closed ), 'Same-handle consumer exception closes inspected stream' );
			$called = false;
			file_put_contents( $temporary, zip_bytes( array( array( 'name' => '../REMOTE_SECRET' ) ) ) ); clearstatcache( true, $temporary );
			$result = $inspector->with_entries( $temporary, $registration, function () use ( &$called ) { $called = true; } );
			assert_zip( $result instanceof WP_Error && ! $called, 'Unsafe record policy prevents all payload consumption' );
			try { trigger_error( 'Restored test handler', E_USER_WARNING ); throw new \RuntimeException( 'Handler not restored' ); } catch ( \ErrorException $expected ) {}
			$command = escapeshellarg( PHP_BINARY ) . ' -d disable_functions=inflate_init,inflate_add,inflate_get_status,inflate_get_read_len ' . escapeshellarg( __FILE__ ) . ' unsupported';
			passthru( $command, $status ); assert_zip( 0 === $status, 'Unavailable-runtime subprocess' );
			echo "PASS: actual ZIP CRC/size/stream boundaries, chunked payloads, expanded-file limit, deadline and same-handle lifecycle; no extraction.\n";
		}
	} finally { unlink( $temporary ); }
}
