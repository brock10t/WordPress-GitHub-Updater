<?php
/** Shared real ZIP byte fixture environment. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Zip_Inspector;
use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__, 2 ) . '/includes/registry/class-registration.php';
require dirname( __DIR__, 2 ) . '/includes/packages/class-archive-manifest.php';
require dirname( __DIR__, 2 ) . '/includes/packages/class-zip-inspector.php';
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
		$body = $entry['compressed_body'] ?? ( 8 === $entry['method'] ? gzdeflate( $entry['body'] ) : $entry['body'] );
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

/** Independent Python zipfile writer fixtures, stored/deflated and CRC-tested. */
function independent_zip_vectors(): array {
	return array(
		'UEsDBBQAAAAAAAAAIQAAAAAAAAAAAAAAAAAIAAAAZml4dHVyZS9QSwMEFAAAAAAAAAAhALVeQP82AAAANgAAABAAAABmaXh0dXJlL21haW4ucGhwPD9waHAgLyogUGx1Z2luIE5hbWU6IEluZGVwZW5kZW50bHkgd3JpdHRlbiBmaXh0dXJlICovUEsBAhQDFAAAAAAAAAAhAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAQAO1BAAAAAGZpeHR1cmUvUEsBAhQDFAAAAAAAAAAhALVeQP82AAAANgAAABAAAAAAAAAAAAAAAKSBJgAAAGZpeHR1cmUvbWFpbi5waHBQSwUGAAAAAAIAAgB0AAAAigAAAAAA',
		'UEsDBBQAAAAAAAAAIQAAAAAAAAAAAAAAAAAIAAAAZml4dHVyZS9QSwMEFAAAAAgAAAAhALVeQP82AAAANgAAABAAAABmaXh0dXJlL21haW4ucGhws7EvyChQ0NdSCMgpTc/MU/BLzE21UvDMS0ktSAUSeSU5lQrlRZklJal5CmmZFSWlRakKWvoAUEsBAhQDFAAAAAAAAAAhAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAQAO1BAAAAAGZpeHR1cmUvUEsBAhQDFAAAAAgAAAAhALVeQP82AAAANgAAABAAAAAAAAAAAAAAAKSBJgAAAGZpeHR1cmUvbWFpbi5waHBQSwUGAAAAAAIAAgB0AAAAigAAAAAA',
	);
}
