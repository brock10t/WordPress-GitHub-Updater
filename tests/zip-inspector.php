<?php
/** Real ZIP byte fixtures; no WordPress runtime or extraction. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Zip_Inspector;
use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';
require dirname( __DIR__ ) . '/includes/packages/class-archive-manifest.php';
require dirname( __DIR__ ) . '/includes/packages/class-zip-inspector.php';
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}
function __( $text, $domain ) { return $text; }
function assert_zip( bool $condition, string $description ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $description ); }
}
/** Build actual stored/deflated ZIPs, including deliberately inconsistent records. */
function zip_bytes( array $entries, array $end_changes = array() ): string {
	$locals = ''; $central = '';
	foreach ( $entries as $entry ) {
		$entry += array( 'name' => 'fixture/main.php', 'body' => '<?php /* Plugin Name: Fixture */', 'method' => 8, 'flags' => 0, 'made' => 3 * 256 + 20, 'needed' => 20, 'external' => 0100644 << 16, 'extra' => '', 'comment' => '', 'descriptor' => false );
		$body = 8 === $entry['method'] ? gzdeflate( $entry['body'] ) : $entry['body'];
		$crc = crc32( $entry['body'] );
		$h = array( 'needed' => $entry['needed'], 'flags' => $entry['flags'] | ( $entry['descriptor'] ? 8 : 0 ), 'method' => $entry['method'], 'time' => 0, 'date' => 0, 'crc' => $crc, 'compressed' => strlen( $body ), 'size' => strlen( $entry['body'] ), 'name' => $entry['name'], 'extra' => $entry['extra'] );
		$l = array_replace( $h, $entry['local'] ?? array() );
		if ( $entry['descriptor'] ) { $l['crc'] = 0; $l['compressed'] = 0; $l['size'] = 0; }
		$c = array_replace( $h, $entry['central'] ?? array() );
		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, $entry['made'], $c['needed'], $c['flags'], $c['method'], $c['time'], $c['date'], $c['crc'], $c['compressed'], $c['size'], strlen( $c['name'] ), strlen( $c['extra'] ), strlen( $entry['comment'] ), 0, 0, $entry['external'], strlen( $locals ) ) . $c['name'] . $c['extra'] . $entry['comment'];
		$locals .= pack( 'VvvvvvVVVvv', 0x04034b50, $l['needed'], $l['flags'], $l['method'], $l['time'], $l['date'], $l['crc'], $l['compressed'], $l['size'], strlen( $l['name'] ), strlen( $l['extra'] ) ) . $l['name'] . $l['extra'] . $body;
		if ( $entry['descriptor'] ) {
			$locals .= ( 'signed' === $entry['descriptor'] ? "PK\x07\x08" : '' ) . pack( 'VVV', $crc, strlen( $body ), strlen( $entry['body'] ) );
		}
	}
	$e = $end_changes + array( 'disk' => 0, 'central_disk' => 0, 'disk_count' => count( $entries ), 'count' => count( $entries ), 'size' => strlen( $central ), 'offset' => strlen( $locals ), 'comment' => '' );
	return $locals . $central . pack( 'VvvvvVVv', 0x06054b50, $e['disk'], $e['central_disk'], $e['disk_count'], $e['count'], $e['size'], $e['offset'], strlen( $e['comment'] ) ) . $e['comment'];
}
function inspect_bytes( string $bytes ) {
	global $temporary, $inspector, $registration;
	file_put_contents( $temporary, $bytes );
	clearstatcache( true, $temporary );
	$before = hash_file( 'sha256', $temporary );
	$result = $inspector->inspect( $temporary, $registration );
	assert_zip( $before === hash_file( 'sha256', $temporary ), 'Inspection does not modify ZIP bytes' );
	return $result;
}
function bad_zip( string $bytes, string $code = '' ): void {
	$result = inspect_bytes( $bytes );
	assert_zip( $result instanceof WP_Error, 'Hostile ZIP rejected' );
	assert_zip( '' === $code || $result->code === $code, 'Expected ' . $code . ', got ' . $result->code );
	assert_zip( ! str_contains( $result->message, 'REMOTE_SECRET' ), 'No raw path reflected' );
}
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$temporary = tempnam( sys_get_temp_dir(), '10t-zip-test-' );
$inspector = new Zip_Inspector();
$registration = new Registration( 'fixture/main.php', 'owner', 'repository' );
try {
	// Independent Python 3 zipfile writer vectors (stored and deflated), CRC-tested by zipfile.
	foreach ( array(
		'UEsDBBQAAAAAAAAAIQAAAAAAAAAAAAAAAAAIAAAAZml4dHVyZS9QSwMEFAAAAAAAAAAhALVeQP82AAAANgAAABAAAABmaXh0dXJlL21haW4ucGhwPD9waHAgLyogUGx1Z2luIE5hbWU6IEluZGVwZW5kZW50bHkgd3JpdHRlbiBmaXh0dXJlICovUEsBAhQDFAAAAAAAAAAhAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAQAO1BAAAAAGZpeHR1cmUvUEsBAhQDFAAAAAAAAAAhALVeQP82AAAANgAAABAAAAAAAAAAAAAAAKSBJgAAAGZpeHR1cmUvbWFpbi5waHBQSwUGAAAAAAIAAgB0AAAAigAAAAAA',
		'UEsDBBQAAAAAAAAAIQAAAAAAAAAAAAAAAAAIAAAAZml4dHVyZS9QSwMEFAAAAAgAAAAhALVeQP82AAAANgAAABAAAABmaXh0dXJlL21haW4ucGhws7EvyChQ0NdSCMgpTc/MU/BLzE21UvDMS0ktSAUSeSU5lQrlRZklJal5CmmZFSWlRakKWvoAUEsBAhQDFAAAAAAAAAAhAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAQAO1BAAAAAGZpeHR1cmUvUEsBAhQDFAAAAAgAAAAhALVeQP82AAAANgAAABAAAAAAAAAAAAAAAKSBJgAAAGZpeHR1cmUvbWFpbi5waHBQSwUGAAAAAAIAAgB0AAAAigAAAAAA',
	) as $vector ) {
		$result = inspect_bytes( base64_decode( $vector, true ) );
		assert_zip( is_array( $result ) && 2 === count( $result ), 'Independent ZIP writer fixture accepted' );
	}
	foreach ( array( 0, 8 ) as $method ) {
		foreach ( array( false, 'signed', 'unsigned' ) as $descriptor ) {
			$bytes = zip_bytes( array( array( 'method' => $method, 'descriptor' => $descriptor ) ) );
			$result = inspect_bytes( $bytes );
			assert_zip( is_array( $result ) && 1 === count( $result ) && 'fixture/main.php' === $result[0]['name'] && $method === $result[0]['method'], 'Stored/deflated and signed/unsigned descriptor fixtures accepted' );
		}
	}
	$dir = array( 'name' => 'fixture/', 'body' => '', 'method' => 0, 'external' => ( 0040755 << 16 ) | 0x10 );
	$result = inspect_bytes( zip_bytes( array( $dir, array(), array( 'name' => 'fixture/readme.txt', 'body' => 'read me', 'made' => 20, 'external' => 0 ) ), array( 'comment' => "ZIP comment PK\x05\x06 end" ) ) );
	assert_zip( is_array( $result ) && 3 === count( $result ) && 'directory' === $result[0]['type'], 'Unix directories, DOS regular files and harmless comments' );
	$timestamp = pack( 'vvCV', 0x5455, 5, 1, 1800000000 );
	assert_zip( is_array( inspect_bytes( zip_bytes( array( array( 'extra' => $timestamp ) ) ) ) ), 'Inert timestamp extra supported' );
	foreach ( array( array( 'needed' => 45 ), array( 'method' => 12 ), array( 'flags' => 1 ), array( 'flags' => 0x40 ), array( 'flags' => 0x2000 ), array( 'made' => 19 * 256 + 20 ), array( 'external' => 0120777 << 16 ), array( 'external' => 0010644 << 16 ), array( 'external' => 0600 << 16 ), array( 'external' => ( 0104755 << 16 ) ), array( 'external' => ( 0100644 << 16 ) | 0x400 ), array( 'extra' => pack( 'vv', 1, 0 ) ), array( 'extra' => pack( 'vv', 0x7075, 0 ) ), array( 'extra' => "x" ), array( 'extra' => pack( 'vv', 0x5455, 5 ) ), array( 'extra' => $timestamp . $timestamp ) ) as $change ) {
		bad_zip( zip_bytes( array( $change ) ), 'github_updater_zip_unsupported' );
	}
	foreach ( array( 'name' => 'fixture/evil.php', 'crc' => 1, 'size' => 42, 'compressed' => 1, 'method' => 0, 'flags' => 8, 'time' => 1, 'needed' => 10 ) as $field => $value ) {
		bad_zip( zip_bytes( array( array( 'local' => array( $field => $value ) ) ) ), 'github_updater_zip_invalid' );
	}
	foreach ( array( array( 'disk' => 1 ), array( 'central_disk' => 1 ), array( 'count' => 65535, 'disk_count' => 65535 ), array( 'offset' => 0xffffffff ) ) as $change ) { bad_zip( zip_bytes( array( array() ), $change ), 'github_updater_zip_unsupported' ); }
	foreach ( array( array( 'disk_count' => 2 ), array( 'size' => 1 ), array( 'offset' => 1 ), array( 'count' => 0, 'disk_count' => 0 ), array( 'count' => 2, 'disk_count' => 2 ) ) as $change ) { bad_zip( zip_bytes( array( array() ), $change ) ); }
	// Two central records may be reordered without changing local byte layout.
	$ordered = zip_bytes( array( array(), array( 'name' => 'fixture/readme.txt', 'body' => 'readme' ) ) );
	$first = strpos( $ordered, "PK\x01\x02" );
	$second = strpos( $ordered, "PK\x01\x02", $first + 4 );
	$end = strlen( $ordered ) - 22;
	$reordered = substr( $ordered, 0, $first ) . substr( $ordered, $second, $end - $second ) . substr( $ordered, $first, $second - $first ) . substr( $ordered, $end );
	assert_zip( is_array( inspect_bytes( $reordered ) ), 'Central directory order does not override local offset validation' );
	// Reusing a local offset creates an overlap and leaves an unindexed local record.
	bad_zip( substr_replace( $ordered, pack( 'V', 0 ), $second + 42, 4 ), 'github_updater_zip_invalid' );
	// Local-only name extras must also be rejected, not ignored after central checks.
	bad_zip( zip_bytes( array( array( 'local' => array( 'extra' => pack( 'vv', 0x7075, 0 ) ) ) ) ), 'github_updater_zip_unsupported' );
	$bytes = zip_bytes( array( array() ) );
	for ( $cut = 0; $cut < strlen( $bytes ); ++$cut ) { bad_zip( substr( $bytes, 0, $cut ) ); }
	bad_zip( $bytes . 'trailing data' );
	bad_zip( 'stub' . $bytes );
	$central = strpos( $bytes, "PK\x01\x02" );
	bad_zip( substr_replace( $bytes, pack( 'V', 1 ), $central + 42, 4 ) );
	bad_zip( substr_replace( $bytes, pack( 'V', 0xffffffff ), $central + 20, 4 ) );
	$descriptor = zip_bytes( array( array( 'descriptor' => 'signed' ) ) );
	$at = strpos( $descriptor, "PK\x07\x08" );
	bad_zip( substr_replace( $descriptor, pack( 'V', 123 ), $at + 4, 4 ) );
	bad_zip( zip_bytes( array( array(), array() ) ), 'github_updater_archive_collision' );
	foreach ( array( '../REMOTE_SECRET', '/REMOTE_SECRET', 'fixture/../REMOTE_SECRET', 'fixture\\REMOTE_SECRET', 'fixture/%2e%2e/REMOTE_SECRET' ) as $name ) { bad_zip( zip_bytes( array( array( 'name' => $name ) ) ), 'github_updater_archive_path' ); }
	bad_zip( zip_bytes( array( array( 'name' => 'other/main.php' ) ) ), 'github_updater_archive_identity' );
	bad_zip( zip_bytes( array( array( 'name' => 'fixture/other.php' ) ) ), 'github_updater_archive_main_file' );
	bad_zip( zip_bytes( array( array( 'body' => str_repeat( 'A', 100000 ) ) ) ), 'github_updater_archive_limits' );
	// The whole archive bound also counts headers, extras and comments.
	$handle = fopen( $temporary, 'wb' );
	ftruncate( $handle, Zip_Inspector::MAX_ARCHIVE_BYTES + 1 );
	fclose( $handle );
	clearstatcache( true, $temporary );
	$too_large = $inspector->inspect( $temporary, $registration );
	assert_zip( $too_large instanceof WP_Error && 'github_updater_zip_limits' === $too_large->code, 'Actual file size cap enforced before record reads' );
	assert_zip( $inspector->inspect( 'php://memory', $registration ) instanceof WP_Error, 'Stream wrapper not accepted as local ZIP' );
	assert_zip( $inspector->inspect( '/definitely-missing-10t.zip', $registration ) instanceof WP_Error, 'Missing file fails safely' );
	$link = $temporary . '-link';
	symlink( $temporary, $link );
	try { assert_zip( $inspector->inspect( $link, $registration ) instanceof WP_Error, 'Symlink input rejected' ); } finally { unlink( $link ); }
	echo "PASS: real ZIP stored/deflated/descriptor fixtures, record consistency, malformed/truncated archives, types/extras and manifest gates; no extraction.\n";
} finally {
	unlink( $temporary );
}
