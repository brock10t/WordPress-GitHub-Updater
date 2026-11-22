<?php
/**
 * Run with a supported PHP and with PHP < 8.2; no WordPress state is changed.
 *
 * @package TenTGitHubUpdater
 */

$scenario = isset( $argv[1] ) ? $argv[1] : 'wordpress';
if ( ! in_array( $scenario, array( 'wordpress', 'php', 'both' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/unsupported-environment.php wordpress|php|both\n" );
	exit( 1 );
}
if ( 'wordpress' !== $scenario && version_compare( PHP_VERSION, '8.2', '>=' ) ) {
	fwrite( STDERR, "PHP rejection checks require a real interpreter older than PHP 8.2.\n" );
	exit( 1 );
}

error_reporting( E_ALL );
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

define( 'ABSPATH', '/unused/' );
$GLOBALS['wp_version'] = 'php' === $scenario ? '6.6' : '6.5.9';
$hooks                 = array();
$allowed               = true;
$network               = false;

// Include hostile translation characters to verify diagnostic escaping.
function __( $text, $domain ) { return $text . ' <script>alert("test")</script>'; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain ) { return esc_html( __( $text, $domain ) ); }
function is_multisite() { return $GLOBALS['network']; }
function current_user_can( $capability ) {
	verify( $capability === ( $GLOBALS['network'] ? 'manage_network_plugins' : 'activate_plugins' ), 'Context-specific diagnostic capability' );
	return $GLOBALS['allowed'];
}
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function register_activation_hook( $file, $callback ) { $GLOBALS['activation'] = $callback; }
function wp_die( $message, $title, $args ) { throw new RuntimeException( $message ); }
function verify( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

ob_start();
require dirname( __DIR__ ) . '/wordpress-github-updater.php';
verify( '' === ob_get_clean(), 'No bootstrap output' );
verify( ! isset( $hooks['plugins_loaded'] ), 'Unsupported runtime never schedules coordinator loading' );
verify( ! class_exists( 'TenT\\GitHubUpdater\\Plugin', false ), 'Coordinator is not loaded' );
verify( isset( $GLOBALS['activation'] ), 'Activation guard is registered' );
$expected = version_compare( PHP_VERSION, '8.2', '<' ) ? 'requires PHP 8.2' : 'requires WordPress 6.6';

foreach ( array( 'admin_notices', 'network_admin_notices' ) as $hook ) {
	$network = 'network_admin_notices' === $hook;
	verify( isset( $hooks[ $hook ] ), 'Diagnostic hook exists: ' . $hook );
	foreach ( array( true, false ) as $permission ) {
		$allowed = $permission;
		ob_start();
		foreach ( $hooks[ $hook ] as $callback ) {
			call_user_func( $callback );
		}
		$output = ob_get_clean();
		if ( $permission ) {
			verify( false !== strpos( $output, $expected ), 'Actionable diagnostic: ' . $hook );
			verify( false === strpos( $output, '<script>' ) && false !== strpos( $output, '&lt;script&gt;' ), 'Escaped diagnostic' );
		} else {
			verify( '' === $output, 'No diagnostic for unauthorized users' );
		}
	}
}

$rejected = false;
try {
	call_user_func( $GLOBALS['activation'], false );
} catch ( RuntimeException $error ) {
	$rejected = true;
	verify( false !== strpos( $error->getMessage(), $expected ), 'Activation explains the version requirement' );
	verify( false === strpos( $error->getMessage(), '<script>' ), 'Activation error is escaped' );
}
verify( $rejected, 'Unsupported activation is rejected' );
echo 'PASS: ' . $scenario . ' (real PHP ' . PHP_VERSION . ', injected WordPress ' . $GLOBALS['wp_version'] . ")\n";
