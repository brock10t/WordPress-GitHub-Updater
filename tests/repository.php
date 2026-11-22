<?php
/**
 * Offline GitHub URL identity and malformed-input checks.
 *
 * @package TenTGitHubUpdater
 */

use TenT\GitHubUpdater\GitHub\Repository;
use TenT\GitHubUpdater\Registry\Registration;

define( 'ABSPATH', '/unused/' );
require dirname( __DIR__ ) . '/includes/github/class-repository.php';
require dirname( __DIR__ ) . '/includes/registry/class-registration.php';

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}
function __( $text, $domain ) { return $text; }
function check_repository( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $description );
	}
}

$valid = array(
	'https://github.com/Owner/Repository'       => 'https://github.com/owner/repository',
	'HTTPS://GITHUB.COM/Owner/Repository/'      => 'https://github.com/owner/repository',
	' https://github.com/Owner/Repository.git ' => 'https://github.com/owner/repository',
	'https://github.com/Owner/Repository.GIT/'  => 'https://github.com/owner/repository',
	'https://github.com/a/1'                    => 'https://github.com/a/1',
	'https://github.com/owner-123/my.repo_v2'    => 'https://github.com/owner-123/my.repo_v2',
	'https://github.com/owner/.github'          => 'https://github.com/owner/.github',
);
$boundary = 'https://github.com/' . str_repeat( 'a', 39 ) . '/' . str_repeat( 'r', 100 );
$valid[ $boundary ]          = $boundary;
$valid[ $boundary . '.git/' ] = $boundary;
foreach ( $valid as $input => $expected ) {
	$repository = Repository::from_url( $input );
	check_repository( $repository instanceof Repository, 'Accept repository root: ' . $input );
	check_repository( $expected === $repository->url, 'Canonical URL: ' . $input );
	check_repository( $expected === Repository::from_url( $repository->url )->url, 'Normalization is idempotent' );
	check_repository( 'https://github.com/' . $repository->owner . '/' . $repository->name === $repository->url, 'Components agree with canonical URL' );
}

$invalid = array(
	'', 'github.com/owner/repo', '//github.com/owner/repo', 'http://github.com/owner/repo',
	'git@github.com:owner/repo.git', 'ssh://github.com/owner/repo', 'file:///owner/repo',
	'https://www.github.com/owner/repo', 'https://api.github.com/owner/repo',
	'https://github.com.evil.test/owner/repo', 'https://github.com./owner/repo',
	'https://github.com@evil.test/owner/repo', 'https://evil.test@github.com/owner/repo',
	'https://user:password@github.com/owner/repo', 'https://github.com:443/owner/repo',
	'https://127.0.0.1/owner/repo', 'https://[::1]/owner/repo',
	'https://github.com/owner', 'https://github.com//repo', 'https://github.com/owner/',
	'https://github.com/owner//repo', 'https://github.com/owner/repo//',
	'https://github.com/owner/repo/tree/main', 'https://github.com/owner/repo/releases',
	'https://github.com/owner/repo?x=1', 'https://github.com/owner/repo?',
	'https://github.com/owner/repo#readme', 'https://github.com/owner/repo#',
	'https://github.com/owner/../repo', 'https://github.com/owner/.', 'https://github.com/owner/..',
	'https://github.com/owner/%2e%2e', 'https://github.com/owner/repo%2fother',
	'https://github.com/owner/repo%5cother', 'https://github.com/owner/repo%00',
	'https://github.com/owner\\repo', 'https://github.com\\@evil.test/owner/repo',
	'https://github.com/owner/repo name', 'https://github.com/owner/répo',
	'https://gіthub.com/owner/repo', // Non-ASCII lookalike host.
	'https://github.com/-owner/repo', 'https://github.com/owner-/repo',
	'https://github.com/own--er/repo', 'https://github.com/own.er/repo',
	'https://github.com/owner/.git', 'https://github.com/owner/..git',
	'https://github.com/owner/...git', 'https://github.com/owner/repo.git.git',
	'https://github.com/' . str_repeat( 'a', 40 ) . '/repo',
	'https://github.com/owner/' . str_repeat( 'r', 101 ),
	'https://github.com/owner/' . str_repeat( 'r', 101 ) . '.git',
	"https://github.com/owner/repo\n", "\thttps://github.com/owner/repo",
	"https://github.com/owner/repo\0", "https://github.com/owner/repo\r\nHost: evil.test",
);
foreach ( $invalid as $input ) {
	$result = Repository::from_url( $input );
	check_repository( $result instanceof WP_Error && 'github_updater_invalid_repository_url' === $result->get_error_code(), 'Reject malformed or unsupported URL: ' . $input );
}

$repository   = Repository::from_url( 'https://github.com/OWNER/different-repository.git/' );
$registration = new Registration( 'Actual-Plugin/custom-main.php', $repository->owner, $repository->name );
check_repository( $repository->url === $registration->repository_url, 'Use normalized components in registration' );
check_repository( 'Actual-Plugin/custom-main.php' === $registration->plugin_basename && 'Actual-Plugin.zip' === $registration->asset_name, 'Repository normalization does not change plugin identity' );
$immutable = false;
try {
	$repository->url = 'https://evil.test/owner/repo';
} catch ( Error $error ) {
	$immutable = true;
}
check_repository( $immutable, 'Validated identity cannot be mutated' );
echo 'PASS: ' . count( $valid ) . ' canonical URLs, ' . count( $invalid ) . " rejected inputs, immutable identity and registration handoff\n";
