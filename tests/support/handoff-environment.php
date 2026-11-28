<?php
/** Scoped ownership + real package validation and lock-loss tests. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Selector;
use TenT\GitHubUpdater\Registry\Registry;
use TenT\GitHubUpdater\Updates\Validated_Handoff;
require __DIR__ . '/replacement-environment.php';
foreach ( array( 'github/class-api-client.php', 'github/class-stable-releases.php', 'github/class-cached-releases.php', 'github/class-release-asset.php', 'github/class-release-candidate.php', 'github/class-release-selector.php', 'github/class-check-state.php', 'github/class-release-check.php', 'packages/class-asset-downloader.php', 'packages/class-staged-files.php', 'updates/class-upgrade-lock.php', 'updates/class-validated-handoff.php' ) as $file ) { require dirname( __DIR__, 2 ) . '/includes/' . $file; }
class Handoff_DB extends Lock_Database_Double {
	public array $held = array();
	public bool $single_lock_only = false;
	public function get_var( $sql ) {
		$this->queries[] = $sql; $name = substr( $sql, strrpos( $sql, '|' ) + 1 );
		if ( str_contains( $sql, 'GET_LOCK(' ) ) { if ( $this->single_lock_only ) { $this->held = array(); } if ( '1' === $this->acquire ) { $this->held[$name] = true; } return $this->acquire; }
		if ( str_contains( $sql, 'IS_USED_LOCK(' ) ) { return isset( $this->held[$name] ) ? '1' : '0'; }
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) { unset( $this->held[$name] ); return $this->release; }
		throw new RuntimeException('Unexpected query');
	}
}
$wpdb = new Handoff_DB();
function current_user_can( $cap ) { return true; }
function wp_cache_delete( $key, $group ) {}
function get_current_network_id() { return $GLOBALS['network_id'] ?? 1; }
function get_current_blog_id() { return 1; }
function wp_using_ext_object_cache() { return false; }
function get_site_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['cache'][$key] = $value; return true; }
function delete_site_transient( $key ) { unset( $GLOBALS['cache'][$key] ); return true; }
function wp_tempnam( $name ) { $path = tempnam( sys_get_temp_dir(), '10t-hand-off-' ); $GLOBALS['temps'][] = $path; return $path; }
function wp_delete_file( $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['http'][] = $url;
	if ( ! empty( $args['stream'] ) ) { file_put_contents( $args['filename'], $GLOBALS['zip_body'] ); return array( 'response'=>array('code'=>200), 'headers'=>array() ); }
	if ( str_contains( $url, '/releases?' ) ) { parse_str( parse_url( $url, PHP_URL_QUERY ), $query ); $data = '1' === $query['page'] ? array( $GLOBALS['release'] ) : array(); }
	else { $data = array('full_name'=>'owner/repository','private'=>false); }
	return array('response'=>array('code'=>200),'headers'=>array('content-type'=>'application/json'),'body'=>json_encode($data));
}
function reset_handoff(): void { $GLOBALS['cache'] = array(); $GLOBALS['http'] = array(); }
$temps = array(); $zip_body = file_get_contents( $zip );
$release = array('id'=>1,'tag_name'=>'v1.2','draft'=>false,'prerelease'=>false,'published_at'=>'2026-11-28T12:00:00Z','assets'=>array(array('id'=>10,'name'=>'fixture.zip','state'=>'uploaded','size'=>strlen($zip_body),'url'=>'https://api.github.com/repos/owner/repository/releases/assets/10','browser_download_url'=>'https://github.com/owner/repository/releases/download/v1.2/fixture.zip')));
$expected = Release_Selector::select( array( $release ), $record, '1.0' );
$handoff = new Validated_Handoff();
