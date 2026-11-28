<?php
/** Native filter boundary checks with registry and hook doubles. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Updates\Upgrade_Guard;
use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;
require __DIR__ . '/support/registry-environment.php';
require dirname( __DIR__ ) . '/includes/updates/class-upgrade-guard.php';
function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['upgrade_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted ); }
function apply_upgrade_filter( $hook, ...$args ) {
	$priorities = $GLOBALS['upgrade_hooks'][ $hook ] ?? array(); ksort( $priorities );
	foreach ( $priorities as $callbacks ) { foreach ( $callbacks as list( $callback, $accepted ) ) { $args[0] = $callback( ...array_slice( $args, 0, $accepted ) ); } }
	return $args[0];
}
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
foreach ( array( false, true ) as $multisite ) {
	$options = array(); $networks = array(); $network = 1; $writes = 0; $fail_write = false; $upgrade_hooks = array();
	$record = new Registration( 'fixture/main.php', 'owner', 'repository' );
	( new Option_Storage() )->save( array( $record->plugin_basename => $record ) );
	$writes = 0;
	$guard = new Upgrade_Guard(); $guard->register_hooks();
	$prior_error = new WP_Error( 'previous_error', 'Fixed prior failure' );
	foreach ( array( array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'fixture/main.php' ), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'fixture/main.php', 'bulk' => true ), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'FIXTURE/Main.php', 'automatic' => true ), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'fixture/other.php' ), array( 'plugin' => 'fixture/main.php' ), array( 'type' => 'plugin', 'action' => 'language', 'plugin' => 'fixture/main.php' ) ) as $extra ) {
		expect_registry_error( apply_upgrade_filter( 'upgrader_pre_download', false, 'https://evil.test/REMOTE_SECRET.zip', (object) array(), $extra ), 'github_updater_upgrade_validation_pending' );
		expect_registry_error( apply_upgrade_filter( 'upgrader_pre_download', '/arbitrary/unvalidated.zip', 'ignored', null, $extra ), 'github_updater_upgrade_validation_pending' );
		expect_registry_error( apply_upgrade_filter( 'upgrader_pre_install', true, $extra ), 'github_updater_upgrade_validation_pending' );
		expect_registry_error( apply_upgrade_filter( 'upgrader_source_selection', '/upgrade/elsewhere/', '/upgrade/', null, $extra ), 'github_updater_upgrade_validation_pending' );
		check_registry( $prior_error === apply_upgrade_filter( 'upgrader_pre_download', $prior_error, 'ignored', null, $extra ), 'Prior download errors preserved' );
		check_registry( $prior_error === apply_upgrade_filter( 'upgrader_pre_install', $prior_error, $extra ), 'Prior install errors preserved' );
	}
	foreach ( array( array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'other/main.php' ), array( 'type' => 'theme', 'action' => 'update', 'theme' => 'fixture' ), array( 'type' => 'translation', 'action' => 'update', 'language_update_type' => 'plugin' ), array( 'type' => 'core', 'action' => 'update' ), array() ) as $extra ) {
		check_registry( false === apply_upgrade_filter( 'upgrader_pre_download', false, 'ignored', null, $extra ), 'Unrelated download path unchanged' );
		check_registry( '/prior/file.zip' === apply_upgrade_filter( 'upgrader_pre_download', '/prior/file.zip', 'ignored', null, $extra ), 'Unrelated prior short circuit unchanged' );
		check_registry( true === apply_upgrade_filter( 'upgrader_pre_install', true, $extra ), 'Unrelated preinstall unchanged' );
		check_registry( '/upgrade/unrelated/' === apply_upgrade_filter( 'upgrader_source_selection', '/upgrade/unrelated/', '/upgrade/', null, $extra ), 'Unrelated source unchanged' );
	}
	foreach ( array( array( 'type' => 'plugin', 'action' => 'update' ), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => array( 'fixture/main.php' ) ), null ) as $extra ) {
		expect_registry_error( $guard->pre_download( false, 'ignored', null, $extra ), 'github_updater_upgrade_context' );
	}
	$install = array( 'type' => 'plugin', 'action' => 'install' );
	expect_registry_error( $guard->source_selection( '/upgrade/fixture/', '/upgrade/', null, $install ), 'github_updater_upgrade_validation_pending' );
	expect_registry_error( $guard->source_selection( '/upgrade/Fixture/', '/upgrade/', null, $install ), 'github_updater_upgrade_validation_pending' );
	$deactivated = 0;
	add_filter( 'upgrader_pre_install', function ( $value ) use ( &$deactivated ) { if ( ! $value instanceof WP_Error ) { ++$deactivated; } return $value; }, 10, 1 );
	$managed = array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'fixture/main.php' );
	$guarded = apply_upgrade_filter( 'upgrader_pre_install', true, $managed );
	check_registry( $guarded instanceof WP_Error && 0 === $deactivated, 'Denial precedes error-respecting native deactivation handler' );
	check_registry( 0 === $writes, 'Hooks never mutate registry/activation state' );
	// Bad storage cannot be interpreted as no managed records.
	if ( $multisite ) { $networks[1][Option_Storage::OPTION_NAME] = 'bad'; } else { $options[Option_Storage::OPTION_NAME] = 'bad'; }
	check_registry( $guard->pre_download( false, 'ignored', null, $managed ) instanceof WP_Error, 'Malformed registry fails closed' );
	echo 'PASS: fail-closed upgrader download/preinstall/source hooks and unrelated passthrough (' . ( $multisite ? 'network' : 'single-site' ) . " doubles); no validated handoff yet.\n";
}
