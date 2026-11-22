<?php
/**
 * Isolated foundation checks; no WordPress database or installation is changed.
 *
 * @package TenTGitHubUpdater
 */

if ( ! isset( $argv[1] ) ) {
	foreach ( array( 'single', 'network', 'site-only', 'old-wordpress', 'unauthorized', 'versions' ) as $scenario ) {
		passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
		if ( 0 !== $status ) {
			exit( $status );
		}
	}
	exit( 0 );
}

$scenario = $argv[1];
$hooks    = array();
define( 'ABSPATH', '/unused/' );
$GLOBALS['wp_version'] = in_array( $scenario, array( 'old-wordpress', 'unauthorized' ), true ) ? '6.5' : '6.6';

function __( $text, $domain ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function is_multisite() { return in_array( $GLOBALS['scenario'], array( 'network', 'site-only' ), true ); }
function current_user_can( $capability ) { return 'unauthorized' !== $GLOBALS['scenario']; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function get_site_option( $name, $default ) {
	return 'network' === $GLOBALS['scenario'] ? array( 'wordpress-github-updater/wordpress-github-updater.php' => 1 ) : $default;
}
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function register_activation_hook( $file, $callback ) { $GLOBALS['activation'] = $callback; }
function wp_die( $message, $title, $args ) { throw new RuntimeException( $message ); }
function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/wordpress-github-updater.php';

foreach ( $hooks['plugins_loaded'] ?? array() as $callback ) {
	$callback();
}

$should_load = ! in_array( $scenario, array( 'site-only', 'old-wordpress', 'unauthorized' ), true );
check( class_exists( 'TenT\\GitHubUpdater\\Plugin', false ) === $should_load, 'Coordinator loading: ' . $scenario );

if ( 'versions' === $scenario ) {
	check( '' !== TenT\GitHubUpdater\Environment::requirements_error( '8.1.99', '6.6' ), 'Reject PHP below minimum' );
	check( '' !== TenT\GitHubUpdater\Environment::requirements_error( '8.2', '6.5.9' ), 'Reject WordPress below minimum' );
	check( '' === TenT\GitHubUpdater\Environment::requirements_error( '8.2', '6.6' ), 'Accept exact minimums' );
	check( '' === TenT\GitHubUpdater\Environment::requirements_error( '8.5', '7.0' ), 'Accept newer versions' );
}

$rejected = false;
try {
	call_user_func( $GLOBALS['activation'], 'network' === $scenario );
} catch ( RuntimeException $error ) {
	$rejected = true;
	check( '' !== $error->getMessage(), 'Activation explains rejection' );
}
check( $rejected === ! $should_load, 'Activation outcome: ' . $scenario );

ob_start();
TenT\GitHubUpdater\Environment::render_notice();
$notice = ob_get_clean();
check( ( '' !== $notice ) === ( ! $should_load && 'unauthorized' !== $scenario ), 'Diagnostic visibility: ' . $scenario );

echo 'PASS: ' . $scenario . PHP_EOL;
