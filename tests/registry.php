<?php
/**
 * Registry workflow checks with WordPress discovery/option/capability doubles.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\Registry\Option_Storage;
use TenT\GitHubUpdater\Registry\Registration;
use TenT\GitHubUpdater\Registry\Registry;

require __DIR__ . '/support/registry-environment.php';

foreach ( array( false, true ) as $multisite ) {
	$options     = array();
	$networks    = array();
	$network     = 1;
	$writes      = 0;
	$fail_write  = false;
	$permissions = array( 'update_plugins' => true, 'manage_network_plugins' => true );
	$plugins     = array(
		'fixture/main.php' => array( 'Name' => 'Fixture', 'UpdateURI' => 'https://github.com/owner/repository' ),
		'other/main.php'   => array( 'Name' => 'Other', 'UpdateURI' => 'https://github.com/owner/other' ),
		'pending/main.php' => array( 'Name' => 'Pending', 'UpdateURI' => '' ),
		'legacy.php'       => array( 'Name' => 'Standalone', 'UpdateURI' => '' ),
	);
	$registry = new Registry();
	check_registry( array() === $registry->all() && 0 === $writes, 'Empty read does not initialize storage' );
	expect_registry_error( $registry->get( 'fixture/main.php' ), 'github_updater_plugin_not_registered' );
	$record = new Registration( 'fixture/main.php', 'owner', 'repository' );
	check_registry( true === ( new Option_Storage() )->save( array( $record->plugin_basename => $record ) ), 'Seed persisted fixture' );
	check_registry( $record->to_array() === ( new Registry() )->get( 'fixture/main.php' )->to_array(), 'Exact persisted lookup' );
	expect_registry_error( $registry->get( 'FIXTURE/main.php' ), 'github_updater_plugin_not_registered' );
	check_registry( true === $registry->check_update_uri( 'fixture/main.php' ), 'Current matching header' );
	$plugins['fixture/main.php']['UpdateURI'] = 'https://github.com/owner/different';
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_update_uri_mismatch' );
	$plugins['fixture/main.php']['UpdateURI'] = '';
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_missing_update_uri' );

	$permissions['update_plugins'] = false;
	$before = $writes;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_forbidden' );
	check_registry( $before === $writes, 'Unauthorized removal does not write' );
	$permissions['update_plugins'] = true;
	if ( $multisite ) {
		$permissions['manage_network_plugins'] = false;
		expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_forbidden' );
		$permissions['manage_network_plugins'] = true;
	}
	$fail_write = true;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_registry_write_failed' );
	check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Failed removal preserves registration' );
	$fail_write = false;
	unset( $plugins['fixture/main.php'] );
	check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Orphaned record remains inspectable' );
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_plugin_not_installed' );
	check_registry( true === $registry->remove( 'fixture/main.php' ), 'Orphaned record can be removed' );
	$before = $writes;
	expect_registry_error( $registry->remove( 'fixture/main.php' ), 'github_updater_plugin_not_registered' );
	check_registry( $before === $writes, 'Absent removal does not write' );
	$plugins['fixture/main.php'] = array( 'Name' => 'Fixture', 'UpdateURI' => 'https://github.com/owner/repository' );
	$created = $registry->register( 'fixture/main.php', 'HTTPS://GITHUB.COM/OWNER/Repository.git/' );
	check_registry( $created instanceof Registration && 'https://github.com/owner/repository' === $created->repository_url, 'Register canonical identity from normalized URL' );
	check_registry( true === $registry->check_update_uri( 'fixture/main.php' ), 'Compatible registered plugin passes fresh check' );
	$before = $writes;
	expect_registry_error( $registry->register( 'fixture/main.php', 'https://github.com/owner/repository' ), 'github_updater_plugin_already_registered' );
	check_registry( $before === $writes, 'Existing registration is never silently replaced' );
	$pending = $registry->register( 'pending/main.php', 'https://github.com/owner/pending' );
	check_registry( $pending instanceof Registration, 'Missing header permits pending registration' );
	expect_registry_error( $registry->check_update_uri( 'pending/main.php' ), 'github_updater_missing_update_uri' );
	$plugins['pending/main.php']['UpdateURI'] = 'https://github.com/OWNER/Pending.git/';
	check_registry( true === $registry->check_update_uri( 'pending/main.php' ), 'Corrected header enables compatibility without registry rewrite' );
	$before = $writes;
	foreach ( array(
		array( 'false', 'github_updater_disabled_update_uri' ),
		array( 'not a URL', 'github_updater_invalid_update_uri' ),
		array( 'https://github.com/owner/different', 'github_updater_update_uri_mismatch' ),
		array( 'https://GITHUB.COM/owner/other', 'github_updater_update_uri_hostname_mismatch' ),
	) as list( $uri, $code ) ) {
		$plugins['other/main.php']['UpdateURI'] = $uri;
		expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), $code );
	}
	$plugins['other/main.php']['UpdateURI'] = 'https://github.com/owner/other';
	expect_registry_error( $registry->register( 'other/main.php', 'http://evil.test/owner/other' ), 'github_updater_invalid_repository_url' );
	expect_registry_error( $registry->register( 'missing/main.php', 'https://github.com/owner/missing' ), 'github_updater_plugin_not_installed' );
	expect_registry_error( $registry->register( 'legacy.php', 'https://github.com/owner/legacy' ), 'github_updater_unsupported_plugin_basename' );
	expect_registry_error( $registry->register( 'wordpress-github-updater/wordpress-github-updater.php', 'https://github.com/owner/fixture' ), 'github_updater_self_registration_reserved' );
	check_registry( $before === $writes, 'Rejected registration attempts never write' );
	$permissions['update_plugins'] = false;
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_forbidden' );
	$permissions['update_plugins'] = true;
	if ( $multisite ) {
		$permissions['manage_network_plugins'] = false;
		expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_forbidden' );
		$permissions['manage_network_plugins'] = true;
		$network = 2;
		check_registry( array() === $registry->all(), 'Separate networks retain separate registries' );
		$network = 1;
		check_registry( $registry->get( 'fixture/main.php' ) instanceof Registration, 'Shared network record persists' );
	}
	$fail_write = true;
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_registry_write_failed' );
	expect_registry_error( $registry->get( 'other/main.php' ), 'github_updater_plugin_not_registered' );
	$fail_write = false;
	$before_plugins = $plugins;
	check_registry( true === $registry->remove( 'fixture/main.php' ), 'Remove a registration for a still-installed plugin' );
	check_registry( $before_plugins === $plugins, 'Removal does not change installed plugins or headers' );
	check_registry( $registry->get( 'pending/main.php' ) instanceof Registration, 'Removal preserves other registrations' );
	// Cross-repository and directory conflicts are checked before any write.
	check_registry( true === $registry->remove( 'pending/main.php' ), 'Clear prior fixture' );
	$plugins['fixture/main.php']['UpdateURI'] = '';
	check_registry( $registry->register( 'fixture/main.php', 'https://github.com/owner/repository' ) instanceof Registration, 'Register base conflict fixture' );
	$plugins['other/main.php']['UpdateURI'] = '';
	$before = $writes;
	expect_registry_error( $registry->register( 'other/main.php', 'HTTPS://GITHUB.COM/OWNER/Repository.git/' ), 'github_updater_repository_already_registered' );
	check_registry( $before === $writes, 'Equivalent repository alias cannot write' );
	unset( $plugins['fixture/main.php'] );
	$plugins['Fixture/Main.php'] = array( 'Name' => 'Case Alias', 'UpdateURI' => '' );
	expect_registry_error( $registry->register( 'Fixture/Main.php', 'https://github.com/owner/alias' ), 'github_updater_plugin_identity_conflict' );
	unset( $plugins['Fixture/Main.php'] );
	$plugins['fixture/second.php'] = array( 'Name' => 'Other Entry', 'UpdateURI' => '' );
	expect_registry_error( $registry->register( 'fixture/second.php', 'https://github.com/owner/second' ), 'github_updater_plugin_directory_conflict' );
	unset( $plugins['fixture/second.php'] );
	$plugins['fixture/main.php'] = array( 'Name' => 'Fixture', 'UpdateURI' => 'https://github.com/owner/repository' );
	$plugins['fixture/second.php'] = array( 'Name' => 'Other Entry', 'UpdateURI' => '' );
	expect_registry_error( $registry->check_update_uri( 'fixture/main.php' ), 'github_updater_plugin_directory_conflict' );
	unset( $plugins['fixture/second.php'] );
	$plugins['shared/one.php'] = array( 'Name' => 'One', 'UpdateURI' => '' );
	$plugins['shared/two.php'] = array( 'Name' => 'Two', 'UpdateURI' => '' );
	expect_registry_error( $registry->register( 'shared/one.php', 'https://github.com/owner/shared' ), 'github_updater_plugin_directory_conflict' );

	// Readiness must also reject collisions already present in stored data.
	$alias = new Registration( 'other/main.php', 'OWNER', 'Repository' );
	$base = $registry->get( 'fixture/main.php' );
	check_registry( true === (new Option_Storage())->save(array($base->plugin_basename => $base, $alias->plugin_basename => $alias)), 'Seed legacy ambiguous records' );
	expect_registry_error( $registry->check_update_uri('fixture/main.php'), 'github_updater_repository_already_registered' );
	check_registry( true === $registry->remove('other/main.php'), 'Ambiguous records remain removable' );
	check_registry( true === $registry->check_update_uri('fixture/main.php'), 'Readiness recovers after explicit conflict removal' );

	// A denied lock cannot write, and reentrant option hooks cannot mutate again.
	$wpdb->acquire = '0';
	$before = $writes;
	expect_registry_error($registry->register('other/main.php', 'https://github.com/owner/other'), 'github_updater_registry_busy');
	expect_registry_error($registry->remove('fixture/main.php'), 'github_updater_registry_busy');
	check_registry($before === $writes, 'Contended operations do not write');
	$wpdb->acquire = null;
	expect_registry_error($registry->remove('fixture/main.php'), 'github_updater_registry_lock_unavailable');
	$wpdb->acquire = '1';
	check_registry(!$wpdb->locked, 'Registry operations release locks on all tested return paths');
	$key = Option_Storage::OPTION_NAME;
	if ( $multisite ) {
		$networks[ $network ][ $key ] = array( 'schema_version' => 2, 'registrations' => array() );
	} else {
		$options[ $key ] = array( 'schema_version' => 2, 'registrations' => array() );
	}
	$before = $writes;
	expect_registry_error( $registry->all(), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->get( 'pending/main.php' ), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->remove( 'pending/main.php' ), 'github_updater_unsupported_registry_schema' );
	expect_registry_error( $registry->register( 'other/main.php', 'https://github.com/owner/other' ), 'github_updater_unsupported_registry_schema' );
	check_registry( $before === $writes, 'Registry operations preserve unreadable storage' );
	echo 'PASS: ' . ( $multisite ? 'network' : 'single-site' ) . " registration, lookup, removal, permissions and fresh diagnostics\n";
}
