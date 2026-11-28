<?php
/** Scoped ownership + real package validation and lock-loss tests. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Selector;
use TenT\GitHubUpdater\Registry\Registry;
use TenT\GitHubUpdater\Updates\Validated_Handoff;
require __DIR__ . '/support/replacement-environment.php';
foreach ( array( 'github/class-api-client.php', 'github/class-stable-releases.php', 'github/class-cached-releases.php', 'github/class-release-asset.php', 'github/class-release-candidate.php', 'github/class-release-selector.php', 'github/class-check-state.php', 'github/class-release-check.php', 'packages/class-asset-downloader.php', 'updates/class-upgrade-lock.php', 'updates/class-validated-handoff.php' ) as $file ) { require dirname( __DIR__ ) . '/includes/' . $file; }
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
try {
	reset_handoff(); $path_seen = null; $late_recheck = null;
	$result = $handoff->run( $record, $expected, function ( $path, $recheck ) use ( &$path_seen, &$late_recheck, $record ) {
		$path_seen = $path; $late_recheck = $recheck;
		assert_zip( is_file($path) && count($GLOBALS['wpdb']->held)===2, 'Both scopes and ZIP exist inside consumer' );
		assert_zip( is_array($recheck()), 'Recheck validates same protected bytes and current destination' );
		$denied = (new Registry())->remove($record->plugin_basename);
		assert_zip( $denied instanceof WP_Error && 'github_updater_registry_busy' === $denied->code, 'Registry mutation cannot nest during handoff' );
		return 'consumed';
	} );
	assert_zip( 'consumed'===$result && !file_exists($path_seen) && array()===$wpdb->held, 'Scoped success cleans ZIP and all locks' );
	assert_zip( $late_recheck() instanceof WP_Error, 'Escaped recheck expires after scope' );
	reset_handoff();
	try { $handoff->run($record,$expected,function(){throw new LogicException('Consumer fixture exception');}); throw new RuntimeException('Expected exception'); } catch(LogicException $exception) {}
	assert_zip(array()===$wpdb->held,'Exception releases both locks');
	reset_handoff();
	$result=$handoff->run($record,$expected,function($path,$recheck){file_put_contents($path,'tampered');return $recheck();});
	assert_zip($result instanceof WP_Error&&'github_updater_handoff_changed'===$result->code,'ZIP tampering invalidates recheck');
	reset_handoff();
	$result=$handoff->run($record,$expected,function($path,$recheck){$GLOBALS['plugins']['fixture/main.php']['Version']='1.2';return $recheck();});
	assert_zip($result instanceof WP_Error&&'github_updater_replacement_version'===$result->code,'Installed change rechecked before mutation'); $plugins['fixture/main.php']['Version']='1.0';
	reset_handoff(); $wpdb->single_lock_only=true;
	$result=$handoff->run($record,$expected,function(){throw new RuntimeException('Unsafe consumer ran');});
	assert_zip($result instanceof WP_Error&&array()===$http,'Implicit old-server lock release fails before HTTP'); $wpdb->single_lock_only=false;
	reset_handoff(); $wpdb->acquire='0';
	assert_zip($handoff->run($record,$expected,fn()=>false) instanceof WP_Error && array()===$http,'Busy physical tree does not fetch'); $wpdb->acquire='1';
	reset_handoff();
	$stale=new Release_Candidate($expected->plugin_basename,$expected->repository_url,999,$expected->tag_name,$expected->version,$expected->asset);
	$result=$handoff->run($record,$stale,function(){throw new RuntimeException('Stale consumer ran');});
	assert_zip($result instanceof WP_Error&&'github_updater_handoff_selection'===$result->code&&3===count($http),'Changed selection never downloads package');
	reset_handoff();
	$numeric_stale=new Release_Candidate($expected->plugin_basename,$expected->repository_url,$expected->release_id,$expected->tag_name,'1.20',$expected->asset);
	$result=$handoff->run($record,$numeric_stale,fn()=>false);
	assert_zip($result instanceof WP_Error&&'github_updater_handoff_selection'===$result->code,'Selection identity comparison is strict, not numeric-string coercion');
	reset_handoff(); $multisite=true; $network_id=1;
	$result=$handoff->run($record,$expected,function()use($record,$expected){
		$GLOBALS['network_id']=2;
		$blocked=(new Validated_Handoff())->run($record,$expected,fn()=>false);
		$GLOBALS['network_id']=1;
		assert_zip($blocked instanceof WP_Error&&'github_updater_upgrade_lock_busy'===$blocked->code,'Same physical tree serialized across simulated networks');
		return 'network-scoped';
	});
	assert_zip('network-scoped'===$result&&array()===$wpdb->held,'Simulated network-scoped registry and shared physical lock release'); $multisite=false;
	reset_handoff();
	$result=$handoff->run($record,$expected,function($path,$recheck){$GLOBALS['wpdb']->held=array();return $recheck();});
	assert_zip($result instanceof WP_Error&&array()===$wpdb->held,'Lost connection ownership cannot pass recheck');
	foreach($temps as $path){assert_zip(!file_exists($path),'Every downloaded temp cleaned');}
	echo "PASS: validated scoped handoff, fresh selection, both cooperative locks, ZIP/installed rechecks, lifetime expiry and error/exception cleanup; native approval remains closed.\n";
} finally { unlink($zip); unlink($root.'/fixture/main.php'); rmdir($root.'/fixture'); rmdir($root); }
