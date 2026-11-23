<?php
/** Offline GitHub HTTP boundary tests; no network or WordPress installation. */
use TenT\GitHubUpdater\GitHub\Api_Client;
use TenT\GitHubUpdater\GitHub\Repository;

define('ABSPATH', '/unused/');
require dirname(__DIR__) . '/includes/github/class-repository.php';
require dirname(__DIR__) . '/includes/github/class-api-client.php';
class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function __($text, $domain) { return $text; }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = array($url, $args);
    return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function api_response($body, $status = 200, $headers = array()) {
    return array('response' => array('code' => $status), 'headers' => $headers + array('content-type' => 'application/json; charset=utf-8'), 'body' => $body);
}
function check_api($condition, $description) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
}
function api_error($result, $code) {
    check_api($result instanceof WP_Error && $result->get_error_code() === $code, 'Expected API error: ' . $code);
}
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$repository = Repository::from_url('https://github.com/OWNER/Repository.git');
$client = new Api_Client();
$requests = array();
$response = api_response('{"full_name":"Owner/Repository","private":false,"visibility":"public"}');
check_api($client->get_repository($repository)['full_name'] === 'Owner/Repository', 'Matching public repository accepted');
list($url, $args) = $requests[0];
check_api($url === 'https://api.github.com/repos/owner/repository', 'Fixed API origin and normalized identity');
check_api($args['timeout'] === 10 && $args['redirection'] === 0 && $args['sslverify'] === true, 'Bound timeout, disable redirects, verify TLS');
check_api($args['limit_response_size'] === Api_Client::MAX_RESPONSE_BYTES + 1, 'Transport size cap includes overflow sentinel');
check_api($args['headers']['X-GitHub-Api-Version'] === '2022-11-28' && $args['headers']['Accept'] === 'application/vnd.github+json', 'Pinned API contract');
check_api($args['headers']['Accept-Encoding'] === 'identity' && !isset($args['headers']['Authorization']), 'Request uncompressed public metadata without credentials');
check_api($args['user-agent'] === '10T-GitHub-Updater', 'Non-site-identifying user agent');
$response = api_response('[]');
check_api(array() === $client->get_releases_page($repository, 2, 25), 'Empty release page is not a transport error');
check_api($requests[1][0] === 'https://api.github.com/repos/owner/repository/releases?per_page=25&page=2', 'Explicit pagination URL');
$response = api_response('[{"draft":true,"prerelease":true}]');
check_api($client->get_releases_page($repository)[0]['draft'] === true, 'Raw transport does not claim release eligibility');
$before = count($requests);
foreach (array(array(0,100), array(-1,100), array(1,0), array(1,101)) as list($page,$per_page)) {
    api_error($client->get_releases_page($repository,$page,$per_page), 'github_updater_invalid_api_pagination');
}
check_api(count($requests) === $before, 'Invalid pagination sends no requests');
foreach (array(
    array(new WP_Error('http_request_failed', '<unsafe network detail>'), 'github_updater_api_request_failed'),
    array(api_response('',301,array('location'=>'http://127.0.0.1/private')), 'github_updater_api_redirect_refused'),
    array(api_response('',302,array('location'=>'https://api.github.com/repositories/1')), 'github_updater_api_redirect_refused'),
    array(api_response('',404), 'github_updater_api_not_found'),
    array(api_response('',403,array('x-ratelimit-remaining'=>'0')), 'github_updater_api_rate_limited'),
    array(api_response('',403,array('retry-after'=>'60')), 'github_updater_api_rate_limited'),
    array(api_response('',429), 'github_updater_api_rate_limited'),
    array(api_response('',403,array('x-ratelimit-remaining'=>array('bad'))), 'github_updater_api_http_error'),
    array(api_response('',500), 'github_updater_api_http_error'),
    array(api_response('',204), 'github_updater_api_http_error'),
    array(api_response('<script>bad</script>'), 'github_updater_api_invalid_response'),
    array(api_response('[] trailing'), 'github_updater_api_invalid_response'),
    array(api_response('{}'), 'github_updater_api_invalid_response'),
    array(api_response('null'), 'github_updater_api_invalid_response'),
    array(api_response('"value"'), 'github_updater_api_invalid_response'),
    array(api_response('[]',200,array('content-type'=>'text/html')), 'github_updater_api_invalid_response'),
    array(api_response('[]',200,array('content-type'=>array('application/json'))), 'github_updater_api_invalid_response'),
    array(api_response(str_repeat(' ',Api_Client::MAX_RESPONSE_BYTES+1)), 'github_updater_api_response_too_large'),
    array(api_response(str_repeat('[',65).str_repeat(']',65)), 'github_updater_api_invalid_response'),
    array(array(), 'github_updater_api_invalid_response'),
    array(null, 'github_updater_api_invalid_response'),
) as list($response, $code)) {
    $before = count($requests);
    $result = $client->get_releases_page($repository);
    api_error($result, $code);
    check_api(count($requests) === $before+1, 'Failure does not trigger retries or redirect requests');
    check_api(!str_contains($result->message, '<script>') && !str_contains($result->message, '<unsafe'), 'No untrusted response content in user message');
}
$response = api_response('[]',429,array('retry-after'=>'99999'));
check_api($client->get_releases_page($repository)->get_error_data()['retry_after'] === 86400, 'Retry hint is bounded; no automatic retry');
foreach (array(
    array('{"full_name":"owner/other","private":false}', 'github_updater_repository_identity_mismatch'),
    array('{"full_name":"owner/repository","private":true}', 'github_updater_repository_not_public'),
    array('{"full_name":"owner/repository","private":false,"visibility":"internal"}', 'github_updater_repository_not_public'),
    array('{"full_name":"owner/repository","private":"false"}', 'github_updater_api_invalid_response'),
    array('{}','github_updater_api_invalid_response'),
    array('[]','github_updater_api_invalid_response'),
) as list($body, $code)) {
    $response = api_response($body);
    api_error($client->get_repository($repository),$code);
}
echo "PASS: fixed-origin GitHub HTTP requests, pagination, limits, redirects, JSON/identity validation and bounded errors\n";
