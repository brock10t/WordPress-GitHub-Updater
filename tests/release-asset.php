<?php
/**
 * Offline uploaded-asset identity, ambiguity and endpoint checks.
 *
 * @package TenTGitHubUpdater
 */
use TenT\GitHubUpdater\GitHub\Release_Asset;
use TenT\GitHubUpdater\Registry\Registration;

define('ABSPATH','/unused/');
foreach(array('github/class-repository.php','github/class-release-tag.php','registry/class-registration.php','github/class-release-asset.php') as $file){require dirname(__DIR__).'/includes/'.$file;}
class WP_Error {
    public function __construct(public string $code,public string $message,public mixed $data=null){}
    public function get_error_code(){return $this->code;}
}
function __($text,$domain){return $text;}
function check_asset($condition,$description){if(!$condition){throw new RuntimeException('FAIL: '.$description);}}
function asset_error($result,$code){check_asset($result instanceof WP_Error && $result->get_error_code()===$code,'Expected asset error: '.$code);}
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$registration=new Registration('installed-plugin/custom-main.php','Owner','Different-Repository');
$asset=array(
    'id'=>123,'name'=>'installed-plugin.zip','state'=>'uploaded','size'=>1024,
    'url'=>'https://api.github.com/repos/Owner/Different-Repository/releases/assets/123',
    'browser_download_url'=>'https://github.com/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'content_type'=>'application/octet-stream',
);
$release=array('tag_name'=>'v1.0.0','assets'=>array($asset),'zipball_url'=>'https://api.github.com/repos/owner/different-repository/zipball/v1.0.0');
$result=Release_Asset::select($release,$registration);
check_asset($result instanceof Release_Asset && $result->name==='installed-plugin.zip' && $result->id===123 && $result->size===1024,'Select exact installed-slug ZIP, independent of repository/main filename');
check_asset($result->download_url===$asset['browser_download_url'] && $result->api_url===$asset['url'],'Retain validated pointers with original tag prefix');
$with_other=$release; $with_other['assets'][]=array('name'=>'SHA256SUMS');
check_asset(Release_Asset::select($with_other,$registration) instanceof Release_Asset,'Ignore well-named unrelated assets without treating their metadata as selected');
$missing=$release; $missing['assets']=array();
asset_error(Release_Asset::select($missing,$registration),'github_updater_release_asset_missing');
$other=$release; $other['assets'][0]['name']='different-repository.zip';
asset_error(Release_Asset::select($other,$registration),'github_updater_release_asset_missing');
$other['assets'][0]['name']='Installed-plugin.zip';
asset_error(Release_Asset::select($other,$registration),'github_updater_release_asset_missing');
$duplicate=$release; $duplicate['assets'][]=$asset;
asset_error(Release_Asset::select($duplicate,$registration),'github_updater_release_asset_ambiguous');

foreach(array('id','state','size','url','browser_download_url','name') as $field){
    $bad=$release; unset($bad['assets'][0][$field]);
    asset_error(Release_Asset::select($bad,$registration),'github_updater_invalid_asset_metadata');
}
foreach(array(
    array('id'=>'123'),array('id'=>0),array('id'=>-1),array('id'=>1.5),
    array('size'=>'1024'),array('size'=>0),array('size'=>-1),array('size'=>1.5),
    array('state'=>'starter'),array('url'=>array()),array('browser_download_url'=>false),
) as $overrides){
    $bad=$release; $bad['assets'][0]=array_replace($asset,$overrides);
    asset_error(Release_Asset::select($bad,$registration),'github_updater_invalid_asset_metadata');
}
foreach(array(
    'http://github.com/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'https://github.com.evil.test/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'https://user@github.com/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'https://github.com:443/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'https://github.com/other/Different-Repository/releases/download/v1.0.0/installed-plugin.zip',
    'https://github.com/Owner/other/releases/download/v1.0.0/installed-plugin.zip',
    'https://github.com/Owner/Different-Repository/releases/download/1.0.0/installed-plugin.zip',
    'https://github.com/Owner/Different-Repository/releases/download/v1.0.0/other.zip',
    'https://github.com/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip?x=1',
    'https://github.com/Owner/Different-Repository/releases/download/v1.0.0/installed-plugin.zip#fragment',
    'https://github.com/Owner/Different-Repository/archive/refs/tags/v1.0.0.zip',
    'https://127.0.0.1/package.zip',
    $asset['browser_download_url'].'/extra',
    str_replace('/v1.0.0/','/v1.0.0%2fother/',$asset['browser_download_url']),
    str_replace('installed-plugin.zip','installed-plugin%2f.zip',$asset['browser_download_url']),
    $asset['browser_download_url']."\n",
) as $url){
    $bad=$release; $bad['assets'][0]['browser_download_url']=$url;
    asset_error(Release_Asset::select($bad,$registration),'github_updater_release_asset_url_mismatch');
}
foreach(array(
    'https://api.github.com/repos/Owner/Different-Repository/releases/assets/999',
    'https://api.github.com/repos/other/Different-Repository/releases/assets/123',
    'https://api.github.com/repos/Owner/Different-Repository/zipball/v1.0.0',
    'https://evil.test/asset/123',
) as $url){
    $bad=$release; $bad['assets'][0]['url']=$url;
    asset_error(Release_Asset::select($bad,$registration),'github_updater_release_asset_url_mismatch');
}
foreach(array(array('tag_name'=>'v1.0.0'),array('tag_name'=>'v1.0.0','assets'=>null),array('tag_name'=>'v1.0.0','assets'=>array('key'=>$asset)),array('tag_name'=>'v1.0.0','assets'=>array(false))) as $bad){
    asset_error(Release_Asset::select($bad,$registration),'github_updater_invalid_asset_metadata');
}
$bad=$release; $bad['tag_name']='v1.1.0-rc.1';
asset_error(Release_Asset::select($bad,$registration),'github_updater_unsupported_release_tag');

// An encoded filename is matched against literal installed identity, not decoded into a path.
$spaced=new Registration('plugin name/main.php','owner','different-repository');
$encoded=$asset; $encoded['name']='plugin name.zip';
$encoded['browser_download_url']='https://github.com/owner/different-repository/releases/download/v1.0.0/plugin%20name.zip';
check_asset(Release_Asset::select(array('tag_name'=>'v1.0.0','assets'=>array($encoded)),$spaced) instanceof Release_Asset,'Canonical filename encoding preserves registered identity');
$immutable=false;
try{$result->download_url='https://evil.test/package.zip';}catch(Error $error){$immutable=true;}
check_asset($immutable,'Validated metadata pointer is immutable');
echo "PASS: exact uploaded ZIP selection, ambiguity/missing/malformed rejection and repository/tag/asset URL binding\n";
