<?php
/** Deterministic clock, transient and HTTP doubles exercise real retrieval logic. */
namespace TenT\GitHubUpdater\GitHub {
    function time(): int { return $GLOBALS['now']; }
}
namespace {
use TenT\GitHubUpdater\GitHub\Cached_Releases;
use TenT\GitHubUpdater\GitHub\Repository;
use TenT\GitHubUpdater\GitHub\Stable_Releases;

define('ABSPATH','/unused/');
define('DB_NAME','cached-releases-tests');
require __DIR__.'/support/class-lock-database-double.php';
foreach(array('class-repository.php','class-api-client.php','class-stable-releases.php','class-cached-releases.php') as $file){require dirname(__DIR__).'/includes/github/'.$file;}
class WP_Error {
    public function __construct(public string $code,public string $message,public mixed $data=null){}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function __($text,$domain){return $text;}
function is_multisite(){return $GLOBALS['multisite'];}
function get_current_network_id(){return $GLOBALS['network'];}
function get_current_blog_id(){return $GLOBALS['blog'];}
function wp_using_ext_object_cache(){return $GLOBALS['external'];}
function wp_cache_get($key,$group,$force=false){$GLOBALS['forced_cache_reads'][]=array($key,$group,$force);return $GLOBALS['cache'][$key]??false;}
function wp_cache_delete($key,$group){$GLOBALS['cache_deletes'][]=array($key,$group);return true;}
// Deliberately retain expired data: wrapper must enforce its own timestamps too.
function get_site_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_site_transient($key,$value,$ttl){
    if($GLOBALS['fail_set']){return false;}
    $GLOBALS['cache'][$key]=$value; $GLOBALS['ttls'][$key]=$ttl; return true;
}
function delete_site_transient($key){
    if($GLOBALS['fail_delete']){return false;}
    unset($GLOBALS['cache'][$key]); return true;
}
function wp_remote_retrieve_response_code($response){return $response['response']['code']??'';}
function wp_remote_retrieve_header($response,$name){return $response['headers'][$name]??'';}
function wp_remote_retrieve_body($response){return $response['body']??'';}
function wp_safe_remote_get($url,$args){
    $GLOBALS['requests'][]=$url;
    if($GLOBALS['during_http']){$callback=$GLOBALS['during_http'];$GLOBALS['during_http']=null;$callback();}
    if($GLOBALS['throw_http']){throw new LogicException('HTTP fixture exception');}
    if($GLOBALS['mode']==='failure'){return new WP_Error('http_request_failed','connection failed');}
    if($GLOBALS['mode']==='rate'){return array('response'=>array('code'=>429),'headers'=>array('retry-after'=>'3600'),'body'=>'');}
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/releases')){
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        $body=$query['page']==='1'&&!$GLOBALS['empty']?array(candidate($GLOBALS['release_id'])):array();
    }else{
        $body=array('full_name'=>substr($path,7),'private'=>false);
    }
    return array('response'=>array('code'=>200),'headers'=>array('content-type'=>'application/json'),'body'=>json_encode($body));
}
function candidate($id){return array('id'=>$id,'tag_name'=>'v1.0.'.$id,'published_at'=>'2026-01-01T00:00:00Z','draft'=>false,'prerelease'=>false,'assets'=>array());}
function check_cache($condition,$message){if(!$condition){throw new RuntimeException('FAIL: '.$message);}}
function cache_error($result,$code){check_cache($result instanceof WP_Error&&$result->get_error_code()===$code,'Expected error: '.$code);}
set_error_handler(function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
$now=1800000000;$cache=array();$ttls=array();$requests=array();$cache_deletes=array();$forced_cache_reads=array();
$multisite=false;$network=1;$blog=1;$external=false;$fail_set=false;$fail_delete=false;$mode='success';$empty=false;$release_id=1;$during_http=null;$throw_http=false;
$wpdb=new Lock_Database_Double();
$reader=new Cached_Releases();
$repository=Repository::from_url('https://github.com/owner/repository');
$alias=Repository::from_url('HTTPS://GITHUB.COM/OWNER/Repository.git/');
check_cache($reader->get($repository)[0]['id']===1&&count($requests)===3,'Cold cache performs complete retrieval');
$key=array_key_first($cache);
check_cache($ttls[$key]===43200&&strlen($key)<167,'Twelve-hour TTL and bounded transient key');
check_cache((new Cached_Releases())->get($alias)[0]['id']===1&&count($requests)===3,'Normalized repository alias hits same cache across instances');
$release_id=2;
check_cache($reader->get($repository)[0]['id']===1&&count($requests)===3,'Unexpired success avoids network');
check_cache($reader->get($repository,true)[0]['id']===2&&count($requests)===6,'Manual refresh bypasses success and replaces entry');
$now+=43200;
check_cache($reader->get($repository)[0]['id']===2&&count($requests)===9,'Absolute expiration refetches even if backend retained data');
check_cache(true===$reader->invalidate($repository)&&!isset($cache[$key]),'Explicit invalidation removes entry');
$empty=true;
check_cache(array()===$reader->get($repository),'Completed empty result caches successfully');
$before=count($requests);
check_cache(array()===$reader->get($repository)&&count($requests)===$before,'Empty list is distinct from cache miss');
$empty=false;$release_id=3;
$reader->get($repository,true);
$mode='failure';
cache_error($reader->get($repository,true),'github_updater_api_request_failed');
$before=count($requests);
cache_error($reader->get($repository),'github_updater_api_request_failed');
check_cache(count($requests)===$before&&$ttls[$key]===300,'Failures suppress repeated automatic requests for five minutes, without stale fallback');
$mode='success';
check_cache($reader->get($repository,true)[0]['id']===3,'Manual refresh bypasses failure backoff');
$mode='rate';
cache_error($reader->get($repository,true),'github_updater_api_rate_limited');
check_cache($ttls[$key]===3600,'Longer bounded retry hint controls failure TTL');
$mode='success';$now+=3600;
$reader->get($repository);

// Malformed cache entries are misses and cannot bypass eligibility validation.
$valid=$cache[$key];
foreach(array('scalar','schema','repository','time','future','flags','date','duplicate','list') as $corruption){
    $cache[$key]=$valid;
    if($corruption==='scalar'){$cache[$key]='invalid';}
    if($corruption==='future'){$cache[$key]['created_at']=$now+1;}
    if($corruption==='schema'){$cache[$key]['schema_version']=2;}
    if($corruption==='repository'){$cache[$key]['repository_url']='https://github.com/other/repo';}
    if($corruption==='time'){$cache[$key]['expires_at']++;}
    if($corruption==='flags'){$cache[$key]['releases'][0]['prerelease']=true;}
    if($corruption==='date'){$cache[$key]['releases'][0]['published_at']='invalid';}
    if($corruption==='duplicate'){$cache[$key]['releases'][]=$cache[$key]['releases'][0];}
    if($corruption==='list'){$cache[$key]['releases']=array('bad'=>candidate(3));}
    $before=count($requests);
    check_cache($reader->get($repository)[0]['id']===3&&count($requests)===$before+3,'Refetch corrupt cache: '.$corruption);
}
$fail_delete=true;$before=count($requests);
cache_error($reader->invalidate($repository),'github_updater_release_cache_clear_failed');
cache_error($reader->get($repository,true),'github_updater_release_cache_clear_failed');
check_cache(count($requests)===$before,'Failed clear never mislabels cached data as a fresh fetch');
$fail_delete=false;$reader->invalidate($repository);
$fail_set=true;
check_cache($reader->get($repository)[0]['id']===3&&!isset($cache[$key]),'Cache write failure still returns fresh successful metadata');
$fail_set=false;
$wpdb->acquire='0';$before=count($requests);
cache_error($reader->get($repository),'github_updater_release_refresh_busy');
cache_error($reader->invalidate($repository),'github_updater_release_refresh_busy');
check_cache(count($requests)===$before,'Contended refresh/invalidation sends no requests');
$wpdb->acquire=null;
cache_error($reader->get($repository),'github_updater_release_cache_lock_unavailable');
$wpdb->acquire='1';
$during_http=function()use($reader,$repository){
    cache_error((new Cached_Releases())->get($repository),'github_updater_release_refresh_busy');
    cache_error($reader->invalidate($repository),'github_updater_release_refresh_busy');
};
$reader->get($repository);
check_cache(!$wpdb->locked&&!$wpdb->suppressed,'Locks/error-output state restored');
$throw_http=true;
try{$reader->get($repository,true);throw new RuntimeException('Expected fixture exception');}catch(LogicException $error){}
check_cache(!$wpdb->locked,'Exception releases refresh lock');
$throw_http=false;
$reader->get($repository);
$wpdb->release=null;
cache_error($reader->invalidate($repository),'github_updater_release_cache_lock_release_failed');
$wpdb->release='1';
check_cache(true===$reader->invalidate($repository),'Invalidating an already-absent entry succeeds');

// Simulate a global persistent cache shared even across different networks.
$multisite=true;$external=true;$network=1;$blog=10;
$before=count($requests);$reader->get($repository);
check_cache(count($requests)===$before+3,'Network cache separated from ordinary single-site cache');
$blog=11;$before=count($requests);$reader->get($repository);
check_cache(count($requests)===$before,'Sites in one network share results');
$network=2;$reader->get($repository);
check_cache(count($requests)===$before+3,'Network ID included even in global site-transient cache');
$network=1;$reader->invalidate($repository);
$network=2;$before=count($requests);$reader->get($repository);
check_cache(count($requests)===$before,'Invalidation does not clear another network');
$other=Repository::from_url('https://github.com/owner/other');
$reader->get($other);$before=count($requests);
$reader->invalidate($repository);$reader->get($other);
check_cache(count($requests)===$before,'Invalidation does not clear another repository');
check_cache(count($forced_cache_reads)>0&&$forced_cache_reads[0][2]===true,'Force backend cache read after lock acquisition');
echo "PASS: release cache TTLs, empty/error records, force/invalidate, corruption, locks and shared/isolated network scopes\n";
}
