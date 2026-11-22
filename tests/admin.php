<?php
/** Administration boundary and escaped-rendering checks with WordPress doubles. */
use TenT\GitHubUpdater\Admin\Registry_Page;
use TenT\GitHubUpdater\Registry\Registry;

require __DIR__ . '/support/registry-environment.php';
require dirname(__DIR__) . '/includes/admin/class-registry-page.php';
function is_admin() { return $GLOBALS['admin_context']; }
function is_network_admin() { return $GLOBALS['network_context']; }
function wp_unslash($value) { return stripslashes($value); }
function wp_verify_nonce($nonce, $action) { return $nonce === 'nonce:' . $action; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_html__($value, $domain) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function add_action($hook, $callback) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_plugins_page(...$arguments) { $GLOBALS['menus'][] = $arguments; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function network_admin_url($path) { return 'https://example.test/wp-admin/network/' . $path; }
function get_current_user_id() { return 17; }
function get_site_transient($key) { return $GLOBALS['notices'][$key] ?? false; }
function delete_site_transient($key) { unset($GLOBALS['notices'][$key]); }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="' . esc_attr('nonce:' . $action) . '">'; }
function submit_button($text, $type = '', $name = '', $wrap = true) { echo '<button>' . esc_html($text) . '</button>'; }
function wp_die($message, $title, $args) { throw new RuntimeException('denied:' . $args['response']); }

foreach (array(false, true) as $multisite) {
    $options = array(); $networks = array(); $network = 1; $writes = 0; $fail_write = false;
    $admin_context = true; $network_context = $multisite;
    $permissions = array('update_plugins' => true, 'manage_network_plugins' => true);
    $plugins = array(
        'fixture/main.php' => array('Name' => '<script>fixture</script>', 'Version' => '<unsafe>', 'UpdateURI' => ''),
        'other/main.php' => array('Name' => 'Other', 'UpdateURI' => 'https://github.com/owner/other'),
        'legacy.php' => array('Name' => 'Standalone', 'UpdateURI' => ''),
    );
    $hooks = array(); $menus = array(); $notices = array();
    $page = new Registry_Page();
    $page->register_hooks();
    check_registry(isset($hooks[$multisite ? 'network_admin_menu' : 'admin_menu']), 'Menu hook in correct context');
    check_registry(isset($hooks[($multisite ? 'network_admin_edit_' : 'admin_post_') . '10t_gu_register']), 'Correct authenticated mutation route');
    check_registry(!isset($hooks['admin_post_nopriv_10t_gu_register']), 'No anonymous write route');
    $page->add_menu();
    check_registry(count($menus) === 1, 'Authorized management menu');
    $post = array('plugin_basename' => 'fixture/main.php', 'repository_url' => 'https://github.com/owner/fixture', '_wpnonce' => 'nonce:10t_gu_register');
    expect_registry_error($page->process_request('register', $post, 'GET'), 'github_updater_invalid_request_method');
    $bad = $post; unset($bad['_wpnonce']);
    expect_registry_error($page->process_request('register', $bad, 'POST'), 'github_updater_invalid_nonce');
    foreach (array('expired', array('nonce')) as $nonce) {
        $bad = $post; $bad['_wpnonce'] = $nonce;
        expect_registry_error($page->process_request('register', $bad, 'POST'), 'github_updater_invalid_nonce');
    }
    foreach (array('plugin_basename', 'repository_url', 'allow_pending') as $field) {
        $bad = $post; $bad[$field] = array('unexpected');
        expect_registry_error($page->process_request('register', $bad, 'POST'), 'github_updater_invalid_admin_input');
    }
    expect_registry_error($page->process_request('register', $post, 'POST'), 'github_updater_missing_update_uri');
    check_registry(0 === $writes, 'Invalid input and unchecked pending registration do not write');
    $permissions['update_plugins'] = false;
    expect_registry_error($page->process_request('register', $post, 'POST'), 'github_updater_admin_forbidden');
    $page->add_menu(); check_registry(count($menus) === 1, 'No menu for unauthorized user');
    $permissions['update_plugins'] = true;
    $network_context = !$multisite;
    expect_registry_error($page->process_request('register', $post, 'POST'), 'github_updater_admin_forbidden');
    try { $page->render(); throw new LogicException('Unauthorized render allowed'); } catch (RuntimeException $error) { check_registry('denied:403' === $error->getMessage(), 'Wrong-context rendering denied'); }
    $network_context = $multisite;
    if ($multisite) {
        $permissions['manage_network_plugins'] = false;
        expect_registry_error($page->process_request('register', $post, 'POST'), 'github_updater_admin_forbidden');
        $permissions['manage_network_plugins'] = true;
    }
    $post['allow_pending'] = '1';
    check_registry($page->process_request('register', $post, 'POST') instanceof TenT\GitHubUpdater\Registry\Registration, 'Explicit pending opt-in succeeds');
    $notices['10t_gu_notice_17'] = array('message' => '<script>notice</script>', 'type' => 'success" onclick="bad');
    ob_start(); $page->render(); $html = ob_get_clean();
    check_registry(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;fixture'), 'Escape plugin header and notice output');
    check_registry(str_contains($html, '&lt;unsafe&gt;') && str_contains($html, 'Update URI: https://github.com/owner/fixture'), 'Escaped version and pending correction visible');
    check_registry(str_contains($html, 'legacy.php') && str_contains($html, ' disabled'), 'Standalone entries visible but disabled');
    check_registry(!str_contains($html, 'checked'), 'Pending option defaults unchecked');
    check_registry(str_contains($html, $multisite ? 'network/edit.php?action=10t_gu_register' : 'admin-post.php'), 'Form uses correct native endpoint');
    check_registry(!isset($notices['10t_gu_notice_17']), 'Notice consumed once');
    $remove = array('plugin_basename' => 'fixture/main.php', '_wpnonce' => 'nonce:10t_gu_remove_other/main.php');
    expect_registry_error($page->process_request('remove', $remove, 'POST'), 'github_updater_invalid_nonce');
    $remove['_wpnonce'] = 'nonce:10t_gu_remove_fixture/main.php';
    $before = $plugins;
    check_registry(true === $page->process_request('remove', $remove, 'POST'), 'Nonce-bound removal succeeds');
    check_registry($before === $plugins && array() === (new Registry())->all(), 'Only registry record removed');
    echo 'PASS: ' . ($multisite ? 'network' : 'single-site') . " admin routing, authorization, POST/nonces, pending consent and escaped rendering\n";
}
