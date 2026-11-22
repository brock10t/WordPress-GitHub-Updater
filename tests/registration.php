<?php
/**
 * Isolated registry identity and serialization checks; no WordPress functions.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';

function verify_registration( bool $condition, string $description ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

function rejects_registration( callable $operation, string $description ): void {
	try {
		$operation();
	} catch ( InvalidArgumentException $error ) {
		verify_registration( '' !== $error->getMessage(), 'Validation explains rejection' );
		return;
	}
	throw new RuntimeException( 'FAIL: ' . $description );
}

$registration = new Registration( 'My-Plugin/main.php', 'Example-Owner', 'different-repository' );
verify_registration( 'My-Plugin/main.php' === $registration->plugin_basename, 'Preserve installed basename and case' );
verify_registration( 'My-Plugin' === $registration->plugin_slug, 'Directory identity comes from installed basename' );
verify_registration( 'main.php' === $registration->main_file, 'Preserve main filename' );
verify_registration( 'My-Plugin.zip' === $registration->asset_name, 'Asset name follows installed directory, not repository name' );
verify_registration( 'https://github.com/Example-Owner/different-repository' === $registration->repository_url, 'Derive repository URL' );
verify_registration( $registration->to_array() === Registration::from_array( $registration->to_array() )->to_array(), 'Plain array round trip' );

$immutable = false;
try {
	$registration->plugin_basename = 'other/main.php';
} catch ( Error $error ) {
	$immutable = true;
}
verify_registration( $immutable, 'Identity is immutable' );

foreach ( array( '', 'plugin.php', '/plugin/main.php', '../main.php', 'plugin/../main.php', 'plugin/..', 'plugin//main.php', 'plugin/main.txt', 'plugin/.php', 'plugin\\main.php', 'C:/main.php', 'plugin/ma' . "\0" . 'in.php', 'plugin/%2e%2e.php' ) as $basename ) {
	rejects_registration( fn() => new Registration( $basename, 'owner', 'repository' ), 'Reject malformed basename: ' . $basename );
}
foreach ( array( '', '.', '..', 'owner/repo', 'https://github.com/owner', 'name?query', "name\n", 'name%2fother' ) as $component ) {
	rejects_registration( fn() => new Registration( 'plugin/main.php', $component, 'repository' ), 'Reject malformed owner' );
	rejects_registration( fn() => new Registration( 'plugin/main.php', 'owner', $component ), 'Reject malformed repository' );
}

foreach ( array_keys( $registration->to_array() ) as $field ) {
	$data = $registration->to_array();
	unset( $data[ $field ] );
	rejects_registration( fn() => Registration::from_array( $data ), 'Reject missing field: ' . $field );
	$data[ $field ] = array();
	rejects_registration( fn() => Registration::from_array( $data ), 'Reject wrong field type: ' . $field );
}
foreach ( array( 'plugin_slug', 'main_file', 'repository_url', 'asset_name' ) as $field ) {
	$data           = $registration->to_array();
	$data[ $field ] = 'unrelated';
	rejects_registration( fn() => Registration::from_array( $data ), 'Reject contradictory identity: ' . $field );
}
$data                    = $registration->to_array();
$data['plugin_basename'] = 'other/main.php';
rejects_registration( fn() => Registration::from_array( $data ), 'Reject changed basename with stale package identity' );

echo "PASS: registration identity, immutability, serialization and malformed-record checks\n";
