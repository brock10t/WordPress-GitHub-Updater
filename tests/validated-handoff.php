<?php
/** Scoped ownership regressions. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Selector;
use TenT\GitHubUpdater\Registry\Registry;
use TenT\GitHubUpdater\Updates\Validated_Handoff;
require __DIR__ . '/support/handoff-environment.php';
try {
	reset_handoff(); $path_seen = null; $late_recheck = null;
	$result = $handoff->run( $record, $expected, function ( $path, $recheck ) use ( &$path_seen, &$late_recheck, $record ) {
		$path_seen = $path; $late_recheck = $recheck;
		assert_zip( is_file($path) && count($GLOBALS['wpdb']->held)===2, 'Both scopes and ZIP exist inside consumer' );
		assert_zip( is_array($recheck()), 'Recheck validates same protected bytes and current destination' );
		$stage = sys_get_temp_dir() . '/10t-handoff-stage-' . bin2hex(random_bytes(6)); mkdir($stage); mkdir($stage.'/fixture'); file_put_contents($stage.'/fixture/main.php',$GLOBALS['source']);
		try {
			assert_zip( true === $recheck($stage), 'Scoped recheck also verifies extracted working tree' );
			file_put_contents($stage.'/fixture/main.php','tampered');
			assert_zip( $recheck($stage) instanceof WP_Error, 'Scoped stage tampering rejected' );
		} finally { unlink($stage.'/fixture/main.php'); rmdir($stage.'/fixture'); rmdir($stage); }

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
