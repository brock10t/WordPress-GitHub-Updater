<?php
/** Deterministic HTTP failure, timeout and retry-header handling checks. */
namespace TenT\GitHubUpdater\GitHub { function time(): int { return $GLOBALS['now']; } }
namespace {
use TenT\GitHubUpdater\GitHub\Api_Client;
use TenT\GitHubUpdater\GitHub\Repository;
define('ABSPATH','/unused/');
require dirname(__DIR__).'/includes/github/class-repository.php';
require dirname(__DIR__).'/includes/github/class-api-client.php';
class WP_Error {
    public function __construct(public string $code,public string $message,public mixed $data=null){}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function __($text,$domain){return $text;}
function wp_safe_remote_get($url,$args){++$GLOBALS['requests'];return $GLOBALS['response'];}
function wp_remote_retrieve_response_code($response){return $response['response']['code']??'';}
function wp_remote_retrieve_header($response,$name){return $response['headers'][$name]??'';}
function wp_remote_retrieve_body($response){return $response['body']??'';}
function failure_response($status,$headers=array(),$body=''){return array('response'=>array('code'=>$status),'headers'=>$headers,'body'=>$body);}
function check_resilience($condition,$message){if(!$condition){throw new RuntimeException('FAIL: '.$message);}}
set_error_handler(function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
$now=1800000000;$requests=0;
$repo=Repository::from_url('https://github.com/owner/repository');$client=new Api_Client();
$cases=array(
    array(new WP_Error('http_request_failed','cURL error 28: Operation timed out; https://private.invalid'), 'github_updater_api_timeout',true,null),
    array(new WP_Error('http_request_failed','Connection timed out'), 'github_updater_api_timeout',true,null),
    array(new WP_Error('http_request_failed','TLS verification failed for private.invalid'), 'github_updater_api_request_failed',true,null),
    array(failure_response(408),'github_updater_api_timeout',true,null),
    array(failure_response(401),'github_updater_api_access_denied',false,null),
    array(failure_response(403),'github_updater_api_access_denied',false,null),
    array(failure_response(404),'github_updater_api_not_found',false,null),
    array(failure_response(410),'github_updater_api_resource_gone',false,null),
    array(failure_response(422),'github_updater_api_http_error',false,null),
    array(failure_response(500),'github_updater_api_unavailable',true,null),
    array(failure_response(502),'github_updater_api_unavailable',true,null),
    array(failure_response(503,array('retry-after'=>'120')),'github_updater_api_unavailable',true,120),
    array(failure_response(503,array('retry-after'=>gmdate('D, d M Y H:i:s \G\M\T',$now+90))),'github_updater_api_unavailable',true,90),
    array(failure_response(429),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array('x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)($now+600))),'github_updater_api_rate_limited',true,600),
    array(failure_response(403,array('x-ratelimit-remaining'=>' 0 ','x-ratelimit-reset'=>(string)($now-1))),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array('x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)($now+120),'retry-after'=>'240')),'github_updater_api_rate_limited',true,240),
    array(failure_response(403,array('retry-after'=>'0')),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array(),' {"message":"You have exceeded a secondary rate limit."}'),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array(),'{"message":"API rate limit exceeded for private.invalid"}'),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array(),'{"message":"abuse detection triggered <script>"}'),'github_updater_api_rate_limited',true,60),
    array(failure_response(403,array(),'{"message":["secondary rate limit"]}'),'github_updater_api_access_denied',false,null),
    array(failure_response(403,array(),'<script>secondary rate limit</script>'),'github_updater_api_access_denied',false,null),
    array(failure_response(403,array('retry-after'=>'tomorrow')),'github_updater_api_access_denied',false,null),
    array(failure_response(403,array('retry-after'=>array('bad'),'x-ratelimit-remaining'=>array('bad'))),'github_updater_api_access_denied',false,null),
    array(failure_response(429,array('retry-after'=>'999999999999999999999')),'github_updater_api_rate_limited',true,60),
    array(failure_response(429,array('retry-after'=>'99999')),'github_updater_api_rate_limited',true,86400),
    array(failure_response(403,array('x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)($now+864000))),'github_updater_api_rate_limited',true,86400),
);
foreach($cases as list($response,$code,$retryable,$delay)){
    $before=$requests;$result=$client->get_repository($repo);
    check_resilience($result instanceof WP_Error&&$result->get_error_code()===$code,'Failure classification '.$code);
    $data=$result->get_error_data();
    check_resilience($data['retryable']===$retryable,'Retryability for '.$code);
    check_resilience(($data['retry_after']??null)===$delay,'Retry delay for '.$code);
    check_resilience(($data['retry_at']??null)===(null===$delay?null:$now+$delay),'Absolute retry deadline for '.$code);
    check_resilience($requests===$before+1,'Exactly one request, no retries');
    check_resilience(!str_contains($result->message,'<script>')&&!str_contains($result->message,'private.invalid'),'Never reflect raw transport/body details');
}
foreach(array("bad\0date",'Fri, 99 Jan 2027 00:00:00 GMT','Fri, 15 Jan 2027 25:00:00 GMT','-1','1.5','1e3',false,array(),gmdate('D, d M Y H:i:s \G\M\T',$now-1)) as $header){
    $response=failure_response(503,array('retry-after'=>$header));
    $result=$client->get_repository($repo);
    check_resilience($result->get_error_code()==='github_updater_api_unavailable'&&!isset($result->get_error_data()['retry_at']),'Malformed/past Retry-After adds no server deadline');
}
echo 'PASS: '.count($cases)." transport/HTTP/rate cases, retry dates/epochs, malformed headers and no retries/raw-message exposure\n";
}
