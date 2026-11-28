<?php
/** Bound-session lifecycle with real disposable trees and native-method doubles. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Updates\Native_Session;
use TenT\GitHubUpdater\Updates\Upgrade_Guard;
require __DIR__ . '/support/handoff-environment.php';
require dirname(__DIR__).'/includes/updates/class-upgrade-guard.php';
require dirname(__DIR__).'/includes/updates/class-native-session.php';
define('WP_CONTENT_DIR',$root.'/content');mkdir(WP_CONTENT_DIR);
$hook_table=array();$mode='success';$observed=array();
function add_filter($name,$cb,$priority=10,$accepted=1){$GLOBALS['hook_table'][$name][$priority][]=array($cb,$accepted);}
function remove_filter($name,$cb,$priority=10){foreach($GLOBALS['hook_table'][$name][$priority]??array()as$i=>$item){if($item[0]===$cb){unset($GLOBALS['hook_table'][$name][$priority][$i]);}}}
function remove_action($name,$cb,$priority=10){remove_filter($name,$cb,$priority);}
function filter_session($name,...$args){$items=$GLOBALS['hook_table'][$name]??array();ksort($items);foreach($items as$list){foreach($list as[$cb,$accepted]){$args[0]=$cb(...array_slice($args,0,$accepted));}}return$args[0];}
function unzip_file($zip,$to){mkdir($to.'/fixture');file_put_contents($to.'/fixture/main.php',$GLOBALS['source']);return true;}
class WP_Upgrader_Skin {}
class Plugin_Upgrader {
 public function __construct($skin=null){}
 public function init(){}public function upgrade_strings(){}
 public function download_package($package,$signatures=false,$extra=array()){return filter_session('upgrader_pre_download',false,$package,$this,$extra);}
 public function run($options){
  $GLOBALS['observed']['extra']=$options['hook_extra'];
  $wrong=$options['hook_extra'];$wrong['_10t_session']='replayed';
  assert_zip(filter_session('upgrader_pre_download',false,$options['package'],$this,$wrong)instanceof WP_Error,'Wrong token denied');
  assert_zip(filter_session('upgrader_pre_download',false,$options['package'],new Plugin_Upgrader(),$options['hook_extra'])instanceof WP_Error,'Wrong upgrader denied');
  $file=$this->download_package($options['package'],false,$options['hook_extra']);if($file instanceof WP_Error){return$file;}
  $working=$this->unpack_package($file,true);if($working instanceof WP_Error){return$working;}$GLOBALS['observed']['working']=$working;
  if($GLOBALS['mode']==='tamper'){file_put_contents($working.'/fixture/main.php','changed');}
  $args=array('source'=>$working,'destination'=>WP_PLUGIN_DIR,'clear_destination'=>true,'clear_working'=>true,'hook_extra'=>$options['hook_extra']);
  if($GLOBALS['mode']==='destination'){$args['destination']='/wrong';}
  return$this->install_package($args);
 }
 public function install_package($args){
  $pre=filter_session('upgrader_pre_install',true,$args['hook_extra']);if($pre instanceof WP_Error){return$pre;}
  $source=filter_session('upgrader_source_selection',$args['source'].'/fixture/',$args['source'],$this,$args['hook_extra']);if($source instanceof WP_Error){return$source;}
  $backup=WP_CONTENT_DIR.'/upgrade-temp-backup/plugins/fixture';mkdir(dirname($backup),0755,true);rename(WP_PLUGIN_DIR.'/fixture',$backup);mkdir(WP_PLUGIN_DIR.'/fixture');copy($source.'main.php',WP_PLUGIN_DIR.'/fixture/main.php');
  if($GLOBALS['mode']==='lock_lost'){$GLOBALS['wpdb']->held=array();return new WP_Error('fixture_lock_lost','Injected');}
  if($GLOBALS['mode']==='exception'){throw new LogicException('Fixture after copy');}
  if(in_array($GLOBALS['mode'],array('post_error','rollback_fail'),true)){return new WP_Error('fixture_copy_failed','Injected');}
  return array('destination'=>WP_PLUGIN_DIR.'/fixture');
 }
 public function restore_temp_backup($entries=array()){
  assert_zip(count($GLOBALS['wpdb']->held)===2,'Restoration inside both ownership scopes');$GLOBALS['observed']['restored']=true;
  if($GLOBALS['mode']==='rollback_fail'){return new WP_Error('fixture_restore_failed','Injected');}
  $backup=WP_CONTENT_DIR.'/upgrade-temp-backup/plugins/fixture';if(is_dir($backup)){unlink(WP_PLUGIN_DIR.'/fixture/main.php');rmdir(WP_PLUGIN_DIR.'/fixture');rename($backup,WP_PLUGIN_DIR.'/fixture');}return true;
 }
 public function delete_temp_backup($entries=array()){
  assert_zip(count($GLOBALS['wpdb']->held)===2,'Backup cleanup inside both ownership scopes');
  if($GLOBALS['mode']==='cleanup_fail'){return false;}$backup=WP_CONTENT_DIR.'/upgrade-temp-backup/plugins/fixture';if(is_dir($backup)){unlink($backup.'/main.php');rmdir($backup);}return true;
 }
}
$guard=new Upgrade_Guard();$guard->register_hooks();$session=new Native_Session();$old=file_get_contents(WP_PLUGIN_DIR.'/fixture/main.php');
try{
 $GLOBALS['wp_filter']['upgrader_pre_install']=(object)array('callbacks'=>array(10=>array(array('function'=>array(new Plugin_Upgrader(),'deactivate_plugin_before_upgrade')))));
 assert_zip($session->run($record,$expected) instanceof WP_Error,'Cannot enter during another native activation lifecycle');unset($GLOBALS['wp_filter']);
 foreach(array('success','tamper','destination','post_error','exception','rollback_fail','cleanup_fail','lock_lost')as$mode){
  reset_handoff();$observed=array();file_put_contents(WP_PLUGIN_DIR.'/fixture/main.php',$old);$before=serialize($options);
  $result=$session->run($record,$expected);
  if($mode==='success'){assert_zip(is_array($result)&&$source===file_get_contents(WP_PLUGIN_DIR.'/fixture/main.php'),'Exact staged item installed');}
  else{assert_zip($result instanceof WP_Error,'Failure not reported as successful update');if(!in_array($mode,array('rollback_fail','cleanup_fail','lock_lost'),true)){assert_zip($old===file_get_contents(WP_PLUGIN_DIR.'/fixture/main.php'),'Old fixture restored or untouched');}}
  assert_zip(null===Native_Session::current()&&array()===$wpdb->held&&$before===serialize($options),'Session/locks/options restored after each outcome');
  if(isset($observed['working'])){assert_zip(!file_exists($observed['working']),'Staging cleanup after outcome');}
  assert_zip($guard->pre_install(true,$observed['extra'])instanceof WP_Error,'Closed session cannot replay approval');
  $backup=WP_CONTENT_DIR.'/upgrade-temp-backup/plugins/fixture';if(is_dir($backup)){assert_zip(in_array($mode,array('rollback_fail','cleanup_fail','lock_lost'),true),'Only failed cleanup/restoration retains backup');unlink($backup.'/main.php');rmdir($backup);}
 }
 foreach($temps as$path){assert_zip(!file_exists($path),'No ZIP or staging temp leaks');}
 echo "PASS: bound native-session protocol, token/upgrader/stage/destination gates, success, failures, restoration and cleanup under scopes (native methods doubled).\n";
}finally{unlink($zip);unlink(WP_PLUGIN_DIR.'/fixture/main.php');rmdir(WP_PLUGIN_DIR.'/fixture');if(is_dir(WP_CONTENT_DIR.'/upgrade-temp-backup/plugins')){rmdir(WP_CONTENT_DIR.'/upgrade-temp-backup/plugins');rmdir(WP_CONTENT_DIR.'/upgrade-temp-backup');}rmdir(WP_CONTENT_DIR);rmdir($root);}
