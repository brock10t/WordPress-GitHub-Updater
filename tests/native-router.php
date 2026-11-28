<?php
/** Original-upgrader hook routing with suspended ownership and real fixtures. @package TenTGitHubUpdater */
use TenT\GitHubUpdater\Updates\Native_Router;
use TenT\GitHubUpdater\Updates\Upgrade_Guard;
require __DIR__.'/support/handoff-environment.php';
require dirname(__DIR__).'/includes/updates/class-request-handoff.php';
require dirname(__DIR__).'/includes/updates/class-native-router.php';
require dirname(__DIR__).'/includes/updates/class-upgrade-guard.php';
$content=sys_get_temp_dir().'/10t-router-'.bin2hex(random_bytes(6));mkdir($content);mkdir($content.'/upgrade');define('WP_CONTENT_DIR',$content);
$hooks=array();$filter_stack=array();
function add_filter($hook,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][$priority][]=array($cb,$accepted);}
function add_action($hook,$cb,$priority=10,$accepted=1){add_filter($hook,$cb,$priority,$accepted);}
function remove_filter($hook,$cb,$priority=10){foreach($GLOBALS['hooks'][$hook][$priority]??array()as$i=>$item){if($item[0]===$cb){unset($GLOBALS['hooks'][$hook][$priority][$i]);}}}
function remove_action($hook,$cb,$priority=10){remove_filter($hook,$cb,$priority);}
function doing_filter($hook){return in_array($hook,$GLOBALS['filter_stack'],true);}
function route_filter($hook,...$args){$GLOBALS['filter_stack'][]=$hook;$priorities=array_keys($GLOBALS['hooks'][$hook]??array());sort($priorities);try{foreach($priorities as$p){foreach($GLOBALS['hooks'][$hook][$p]??array()as[$cb,$accepted]){$args[0]=$cb(...array_slice($args,0,$accepted));}}return$args[0];}finally{array_pop($GLOBALS['filter_stack']);}}
function do_action($hook,...$args){$priorities=array_keys($GLOBALS['hooks'][$hook]??array());sort($priorities);foreach($priorities as$p){foreach($GLOBALS['hooks'][$hook][$p]??array()as[$cb,$accepted]){$cb(...array_slice($args,0,$accepted));}}}
class Plugin_Upgrader { public int $deactivated=0; public function deactivate_plugin_before_upgrade($value,$extra){if(!$value instanceof WP_Error){++$this->deactivated;}return$value;} }
$managed=array('package'=>$expected->asset->api_url,'destination'=>WP_PLUGIN_DIR,'clear_destination'=>true,'clear_working'=>true,'hook_extra'=>array('plugin'=>$record->plugin_basename,'temp_backup'=>array('slug'=>'fixture','src'=>WP_PLUGIN_DIR,'dir'=>'plugins')));
try{
 foreach(array('manual','bulk','tamper','early_failure')as$mode){
  reset_handoff();$hooks=array();$router=new Native_Router();$guard=new Upgrade_Guard(router:$router);$guard->register_hooks();$router->register_hooks();$upgrader=new Plugin_Upgrader();
  $operation=$managed;if($mode!=='bulk'){$operation['hook_extra']+=array('type'=>'plugin','action'=>'update');}
  $operation=route_filter('upgrader_package_options',$operation);$extra=$operation['hook_extra'];
  assert_zip(isset($extra['_10t_route'])&&$extra['type']==='plugin','Managed single/bulk context normalized and tokenized');
  $path=route_filter('upgrader_pre_download',false,$operation['package'],$upgrader,$extra);
  assert_zip(is_string($path)&&is_file($path)&&count($wpdb->held)===2,'Original native caller receives validated copy inside retained scopes');
  assert_zip($guard->pre_download(false,$operation['package'],new Plugin_Upgrader(),$extra)instanceof WP_Error,'Another upgrader cannot borrow item');
  if($mode!=='early_failure'){
   $working=$content.'/upgrade/item';mkdir($working);mkdir($working.'/fixture');file_put_contents($working.'/fixture/main.php',$source);
   assert_zip(null===route_filter('pre_unzip_file',null,$path,$working),'Checked copy may enter native extraction');
   assert_zip(true===route_filter('unzip_file',true,$path,$working),'Actual staged bytes checked');unlink($path);
   if($mode==='tamper'){file_put_contents($working.'/fixture/main.php','altered');}
   add_filter('upgrader_pre_install',array($upgrader,'deactivate_plugin_before_upgrade'),10,2);
   $pre=route_filter('upgrader_pre_install',true,$extra);
   if($mode==='tamper'){assert_zip($pre instanceof WP_Error,'Changed tree rejected before mutation');$result=$pre;}
   else{
    assert_zip(true===$pre&&0===$upgrader->deactivated,'Activation choices preserved without changing options');
    assert_zip($working.'/fixture/'===route_filter('upgrader_source_selection',$working.'/fixture/',$working,$upgrader,$extra),'Exact original source and upgrader validated');
    $result=array('destination'=>WP_PLUGIN_DIR.'/fixture');
   }
   $result=route_filter('upgrader_install_package_result',$result,$extra);
   assert_zip(($mode==='tamper')===($result instanceof WP_Error),'Original per-item native result retained');
   assert_zip(!file_exists($working),'Owned working tree cleaned');
  }
  if($mode==='bulk'){
   $second=route_filter('upgrader_package_options',$managed);$next=route_filter('upgrader_pre_download',false,$second['package'],$upgrader,$second['hook_extra']);
   assert_zip(is_string($next)&&is_file($next)&&count($wpdb->held)===2,'Next bulk item reuses request scopes with a fresh validated download');
   $failure=new WP_Error('fixture_failure','Injected second-item failure');assert_zip($failure===route_filter('upgrader_install_package_result',$failure,$second['hook_extra']),'Later item failure preserves its result');
  }
  $backup_checked=false;
  add_action('shutdown',function()use(&$backup_checked){assert_zip(count($GLOBALS['wpdb']->held)===2,'Core backup phase still has both scopes');$backup_checked=true;},100,0);
  do_action('shutdown');assert_zip($backup_checked&&array()===$wpdb->held,'Shutdown backups precede resource release');
  foreach($temps as$temp){assert_zip(!file_exists($temp),'ZIP/copy resources cleaned including early unpack failure');}
  $other=$managed;$other['hook_extra']['plugin']='unregistered/main.php';assert_zip($other===route_filter('upgrader_package_options',$other),'Unregistered options passed exactly');
 }
 // Working directory conflicts must not be purged on our behalf.
 reset_handoff();$hooks=array();$router=new Native_Router();$guard=new Upgrade_Guard(router:$router);$guard->register_hooks();$router->register_hooks();file_put_contents($content.'/upgrade/foreign','keep');
 $operation=route_filter('upgrader_package_options',$managed);$error=$guard->pre_download(false,$operation['package'],new Plugin_Upgrader(),$operation['hook_extra']);assert_zip($error instanceof WP_Error&&file_get_contents($content.'/upgrade/foreign')==='keep','Foreign shared staging fails without deletion');unlink($content.'/upgrade/foreign');
 echo "PASS: standard single/bulk-shaped native routing, original result/upgrader binding, staged checks, native backup ordering and shutdown cleanup (native methods doubled).\n";
}finally{unlink($zip);unlink($root.'/fixture/main.php');rmdir($root.'/fixture');rmdir($root);rmdir($content.'/upgrade');rmdir($content);}
