<?php
/** Offline stable-release eligibility, pagination and ordering checks. */
use TenT\GitHubUpdater\GitHub\Repository;
use TenT\GitHubUpdater\GitHub\Stable_Releases;

define('ABSPATH', '/unused/');
require dirname(__DIR__) . '/includes/github/class-repository.php';
require dirname(__DIR__) . '/includes/github/class-api-client.php';
require dirname(__DIR__) . '/includes/github/class-stable-releases.php';
class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_code() { return $this->code; }
}
function __($text, $domain) { return $text; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function response_for($data) {
    return array('response'=>array('code'=>200),'headers'=>array('content-type'=>'application/json'),'body'=>json_encode($data));
}
function wp_safe_remote_get($url,$args) {
    $GLOBALS['requests'][]=$url;
    if ($url==='https://api.github.com/repos/owner/repository') { return response_for($GLOBALS['metadata']); }
    $parts=parse_url($url); parse_str($parts['query']??'', $query);
    check_release($parts['host']==='api.github.com' && $parts['path']==='/repos/owner/repository/releases', 'Use fixed release-list endpoint, never latest/source archives');
    check_release(($query['per_page']??'')==='100', 'Bound page size');
    $page=(int)($query['page']??0);
    $result=$GLOBALS['pages'][$page]??array();
    return $result instanceof WP_Error ? $result : response_for($result);
}
function check_release($condition,$description) {
    if (!$condition) { throw new RuntimeException('FAIL: '.$description); }
}
function release_error($result,$code) {
    check_release($result instanceof WP_Error && $result->get_error_code()===$code,'Expected release error: '.$code);
}
function release_fixture($id,$published='2026-01-01T00:00:00Z') {
    return array('id'=>$id,'tag_name'=>'v1.0.'.$id,'draft'=>false,'prerelease'=>false,'published_at'=>$published,'assets'=>array(),'zipball_url'=>'https://api.github.com/repos/owner/repository/zipball/v1');
}
function setup_releases($values) {
    $GLOBALS['pages']=$values;
    $GLOBALS['metadata']=array('full_name'=>'owner/repository','private'=>false);
    $GLOBALS['requests']=array();
}
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$repository=Repository::from_url('https://github.com/owner/repository');
$reader=new Stable_Releases();
$draft=release_fixture(20); $draft['draft']=true; $draft['published_at']=null;
$prerelease=release_fixture(21); $prerelease['prerelease']=true;
$unpublished=release_fixture(22); $unpublished['published_at']=null;
setup_releases(array(
    1=>array(release_fixture(1),$draft,$prerelease,$unpublished),
    2=>array(release_fixture(2,'2026-04-01T00:00:00Z'),release_fixture(3,'2026-04-01T00:00:00Z')),
));
$result=$reader->get($repository);
check_release(array_column($result,'id')===array(3,2,1),'Exclude drafts/prereleases/unpublished, inspect later short pages, sort by publication and ID');
check_release(count($requests)===4,'One repository request, two nonempty pages and an empty-page confirmation');
check_release($result[2]===release_fixture(1),'Preserve raw metadata without turning source ZIP URL into a package');

setup_releases(array());
check_release($reader->get($repository)===array(),'No releases is an empty completed result');
setup_releases(array(1=>array($draft,$prerelease,$unpublished)));
check_release($reader->get($repository)===array(),'No stable published candidates');
setup_releases(array(1=>array(release_fixture(1)),2=>new WP_Error('http_request_failed','timeout')));
release_error($reader->get($repository),'github_updater_api_request_failed');
check_release(count($requests)===3,'Later request failure returns no partial result or retries');
setup_releases(array(1=>array(release_fixture(1)),2=>array(release_fixture(1))));
release_error($reader->get($repository),'github_updater_release_scan_changed');

foreach (array(
    array('draft'=>null),array('draft'=>'false'),array('prerelease'=>0),array('prerelease'=>true,'draft'=>null),
    array('id'=>'1'),array('id'=>0),array('tag_name'=>''),array('tag_name'=>"v1\n"),
    array('published_at'=>false),array('published_at'=>'2026-02-30T00:00:00Z'),
    array('published_at'=>'2026-01-01T25:00:00Z'),array('published_at'=>'2026-01-01'),
) as $overrides) {
    setup_releases(array(1=>array(array_replace(release_fixture(1),$overrides))));
    release_error($reader->get($repository),'github_updater_invalid_release_metadata');
}
foreach (array('draft','prerelease','published_at','id','tag_name') as $field) {
    $bad=release_fixture(1); unset($bad[$field]);
    setup_releases(array(1=>array(release_fixture(2),$bad)));
    release_error($reader->get($repository),'github_updater_invalid_release_metadata');
}
foreach (array(null,false,'not an object') as $bad) {
    setup_releases(array(1=>array($bad)));
    release_error($reader->get($repository),'github_updater_invalid_release_metadata');
}
setup_releases(array(1=>array_map('release_fixture',range(1,101))));
release_error($reader->get($repository),'github_updater_invalid_release_metadata');
setup_releases(array_fill(1,Stable_Releases::MAX_PAGES,array($prerelease)));
release_error($reader->get($repository),'github_updater_release_scan_incomplete');
check_release(count($requests)===Stable_Releases::MAX_PAGES+1,'Hard request bound; no extra page or partial success');
setup_releases(array());
$metadata['private']=true;
release_error($reader->get($repository),'github_updater_repository_not_public');
check_release(count($requests)===1,'Public-repository failure prevents release requests');

// Retrieval ordering is independent of version/tag semantics, handled next.
$tagged=release_fixture(1); $tagged['tag_name']='not-yet-normalized';
setup_releases(array(1=>array($tagged)));
check_release($reader->get($repository)[0]['tag_name']==='not-yet-normalized','Defer tag validation and version comparison');
echo "PASS: stable published flags, strict metadata, bounded complete scans, ordering and no partial results\n";
