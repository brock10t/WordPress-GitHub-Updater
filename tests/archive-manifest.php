<?php
/** Pre-extraction manifest policy with hostile entry fixtures. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Packages\Archive_Manifest;
use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';
require dirname( __DIR__ ) . '/includes/packages/class-archive-manifest.php';
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code() { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function entry( string $name, int $size = 10, int $compressed = 10 ): array {
	return array( 'name' => $name, 'type' => 'file', 'size' => $size, 'compressed_size' => $compressed, 'encrypted' => false, 'method' => 0 );
}
function directory( string $name ): array { return array_replace( entry( $name, 0, 0 ), array( 'type' => 'directory' ) ); }
function verify( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } }
function rejected( array $entries, string $suffix ): void {
	global $registration;
	$result = Archive_Manifest::check( $entries, $registration );
	verify( $result instanceof WP_Error && 'github_updater_archive_' . $suffix === $result->code, 'Expected ' . $suffix );
	verify( ! str_contains( $result->message, 'REMOTE_SECRET' ), 'Hostile entry paths never reflected' );
}
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$registration = new Registration( 'fixture/main.php', 'owner', 'repository' );
$main = entry( 'fixture/main.php' );
verify( true === Archive_Manifest::check( array( $main ), $registration ), 'Explicit directory records are optional' );
$valid = array( $main, entry( 'fixture/assets/file.css' ), directory( 'fixture/assets/' ), directory( 'fixture/' ), entry( 'fixture/.htaccess' ), entry( 'fixture/assets/a file+1@2.css', 0, 0 ) );
verify( true === Archive_Manifest::check( $valid, $registration ), 'Safe files, empty files, dotfiles and directories after children' );
verify( true === Archive_Manifest::check( array_reverse( $valid ), $registration ), 'Directory and child order does not affect acceptance' );
$bad_paths = array( '', '/fixture/REMOTE_SECRET', '../REMOTE_SECRET', 'fixture/../REMOTE_SECRET', 'fixture/./REMOTE_SECRET', 'fixture//REMOTE_SECRET', 'fixture\\REMOTE_SECRET', 'C:/REMOTE_SECRET', '//server/REMOTE_SECRET', 'fixture/a:REMOTE_SECRET', 'fixture/%2e%2e/REMOTE_SECRET', "fixture/REMOTE_SECRET\0.php", "fixture/REMOTE_SECRET\n.php", 'fixture/name.', 'fixture/name ', 'fixture/ leading', 'fixture/NUL', 'fixture/CON.txt', 'fixture/CON .txt', 'fixture/COM1.php', 'fixture/LPT9', 'fixture/café.php', 'fixture/' . str_repeat( 'a', 240 ), 'fixture/' . str_repeat( 'd/', 20 ) . 'REMOTE_SECRET' );
foreach ( $bad_paths as $path ) { rejected( array( $main, entry( $path ) ), 'path' ); }
rejected( array( $main, entry( 'fixture/file/' ) ), 'path' );
rejected( array( $main, array_replace( directory( 'fixture/dir/' ), array( 'name' => 'fixture/dir' ) ) ), 'path' );
foreach ( array( 'other/main.php', 'Fixture/other.php', 'fixture', '__MACOSX/file' ) as $path ) { rejected( array( $main, entry( $path ) ), 'identity' ); }
foreach ( array( 'symlink', 'fifo', 'socket', 'device', 'unknown' ) as $type ) { rejected( array( $main, array_replace( entry( 'fixture/link' ), array( 'type' => $type ) ) ), 'entry_type' ); }
rejected( array( array_replace( $main, array( 'encrypted' => true ) ) ), 'entry_type' );
rejected( array( array_replace( $main, array( 'method' => 99 ) ) ), 'entry_type' );
foreach ( array_keys( $main ) as $field ) { $bad = $main; unset( $bad[ $field ] ); rejected( array( $bad ), 'metadata' ); }
foreach ( array( array( 'size' => '10' ), array( 'size' => -1 ), array( 'compressed_size' => false ), array( 'encrypted' => 0 ), array( 'method' => '0' ), array( 'name' => array() ), array( 'type' => null ) ) as $changes ) { rejected( array( array_replace( $main, $changes ) ), 'metadata' ); }
rejected( array( false ), 'metadata' );
rejected( array( array_replace( directory( 'fixture/' ), array( 'size' => 1 ) ) ), 'metadata' );
rejected( array(), 'limits' );
rejected( array( 'key' => $main ), 'limits' );
rejected( array_fill( 0, Archive_Manifest::MAX_ENTRIES + 1, $main ), 'limits' );
foreach ( array( array( $main, $main ), array( $main, entry( 'fixture/Main.php' ) ), array( $main, directory( 'fixture/main.php/' ) ), array( $main, entry( 'fixture/main.php/child' ) ), array( entry( 'fixture/main.php/child' ), $main ), array( $main, entry( 'fixture/A/a' ), entry( 'fixture/a/b' ) ), array( $main, directory( 'fixture/' ), directory( 'fixture/' ) ) ) as $entries ) { rejected( $entries, 'collision' ); }
rejected( array( entry( 'fixture/other.php' ) ), 'main_file' );
rejected( array( entry( 'fixture/main.php', 0, 0 ) ), 'main_file' );
rejected( array( directory( 'fixture/main.php/' ) ), 'main_file' );
rejected( array( entry( 'fixture/main.php', PHP_INT_MAX, PHP_INT_MAX ) ), 'limits' );
rejected( array( entry( 'fixture/main.php', 10, PHP_INT_MAX ) ), 'limits' );
rejected( array( entry( 'fixture/main.php', Archive_Manifest::MAX_FILE_BYTES + 1, Archive_Manifest::MAX_FILE_BYTES + 1 ) ), 'limits' );
rejected( array( array_replace( entry( 'fixture/main.php', 101, 1 ), array( 'method' => 8 ) ) ), 'limits' );
rejected( array( array_replace( entry( 'fixture/main.php', 1, 0 ), array( 'method' => 8 ) ) ), 'limits' );
rejected( array( entry( 'fixture/main.php', 10, 9 ) ), 'limits' );
verify( true === Archive_Manifest::check( array( array_replace( entry( 'fixture/main.php', 100, 1 ), array( 'method' => 8 ) ) ), $registration ), 'Ratio boundary accepted' );
$many = array( $main );
for ( $i = 1; $i < Archive_Manifest::MAX_ENTRIES; ++$i ) { $many[] = entry( 'fixture/f' . $i, 0, 0 ); }
verify( true === Archive_Manifest::check( $many, $registration ), 'Entry limit inclusive' );
$large = array();
for ( $i = 0; $i < 5; ++$i ) { $large[] = array_replace( entry( 0 === $i ? 'fixture/main.php' : 'fixture/f' . $i, Archive_Manifest::MAX_FILE_BYTES, 1048576 ), array( 'method' => 8 ) ); }
verify( true === Archive_Manifest::check( $large, $registration ), 'Expanded size and individual file boundaries accepted' );
$large[] = entry( 'fixture/over', 1, 1 ); rejected( $large, 'limits' );
$large = array( $main, entry( 'fixture/a', 20971520, 20971520 ), entry( 'fixture/b', 20971520, 20971520 ), entry( 'fixture/c', 10485750, 10485750 ) );
verify( true === Archive_Manifest::check( $large, $registration ), 'Compressed total boundary accepted' );
$large[] = entry( 'fixture/over', 1, 1 ); rejected( $large, 'limits' );
echo "PASS: manifest path/type/identity/collision policy, malformed entries, order independence and resource boundaries (no ZIP parsing/extraction).\n";
