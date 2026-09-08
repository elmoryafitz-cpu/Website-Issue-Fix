<?php
// Disposable WordPress only. No actual customer website or Google API requests.
if (getenv('GSCSF_TEST_SITE') !== '1' || !is_file(getenv('GSCSF_WP_ROOT') . '/wp-load.php')) { fwrite(STDERR, "Set GSCSF_WP_ROOT and GSCSF_TEST_SITE=1.\n"); exit(1); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require getenv('GSCSF_WP_ROOT') . '/wp-load.php';
set_exception_handler(function ($e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
wp_set_current_user(1);
$checks = 0;
function verify($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $checks++; echo "PASS: $message\n"; }
function call_hidden($object, $method, ...$args) { $r = new ReflectionMethod($object, $method); $r->setAccessible(true); return $r->invokeArgs($object, $args); }
function reply($body, $code = 200, $headers = array()) { return array('body' => $body, 'response' => array('code' => $code), 'headers' => array_merge(array('content-type' => 'text/html'), $headers)); }
$plugin = new GSCSF_Plugin(); global $wpdb;
$table = $wpdb->prefix . 'gscsf_urls';
update_option('gscsf_job', array('phase' => 'scan', 'started' => '2026-09-01 10:00:00'));
update_option('gscsf_settings', array('daily' => 1, 'auto' => 1));
wp_schedule_single_event(time() + 10, 'gscsf_tick');
wp_schedule_event(time() + 10, 'daily', 'gscsf_daily');
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
GSCSF_Plugin::activate();
verify(get_option('gscsf_job')['phase'] === 'saved', 'Activation archives old active work instead of running it');
verify(!wp_next_scheduled('gscsf_tick') && !wp_next_scheduled('gscsf_daily'), 'Activation removes stale continuation and daily events');
verify(!get_option('gscsf_settings')['daily'] && !get_option('gscsf_settings')['auto'], 'Old automation settings cannot silently restart scanning');
verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") === $count, 'Saved report rows survive activation');
$plugin->cron_tick(); $plugin->daily();
verify(get_option('gscsf_job')['phase'] === 'saved', 'Already-dispatched cron callbacks do not restart archived work');
call_hidden($plugin, 'state');
verify(get_option('gscsf_job')['phase'] === 'saved', 'Opening the dashboard state is read-only');
update_option('blog_public', 1); update_option('gscsf_settings', array()); delete_option('gscsf_ahrefs');
$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Discovery fixture', 'post_name' => 'discovery-fixture', 'post_content' => 'Public content.'));
add_post_meta($id, '_wp_old_slug', 'former-discovery-fixture');
$hits = 0; $broken = false;
$old = home_url('/shared-redirect/'); $target = home_url('/shared-target/');
$mock = function ($pre, $args, $url) use (&$hits, &$broken, $old, $target) {
    $hits++;
    if ($url === GSCSF_Audit::robots_url()) { return reply("User-agent: *\nSitemap: " . home_url('/custom-auto-map.xml'), 200, array('content-type' => 'text/plain')); }
    if (strpos($url, '/custom-auto-map.xml') !== false) { return reply('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . home_url('/sitemap-hidden-page/') . '</loc></url></urlset>', 200, array('content-type' => 'application/xml')); }
    if (preg_match('~/(?:wp-sitemap|sitemap_index|sitemap)\.xml$~', $url)) { return reply('not found', 404); }
    if ($url === $old) { return reply('', 301, array('location' => $target)); }
    if ($url === $target) { return reply('<html><head></head><body>Target</body></html>', $broken ? 404 : 200); }
    return reply('<html lang="en"><head><title>Public page</title></head><body><h1>Public page</h1></body></html>');
};
add_filter('pre_http_request', $mock, 10, 3);
call_hidden($plugin, 'start', true);
verify(get_option('gscsf_job')['auto_fix'] === true && get_option('gscsf_job')['phase'] === 'detecting', 'One-click workflow records fix consent but starts with detection');
$loops = 0;
while (get_option('gscsf_job')['phase'] !== 'scan' && ++$loops < 100) { call_hidden($plugin, 'step'); }
verify((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE url = %s", home_url('/former-discovery-fixture/'))), 'Old WordPress slugs are discovered without URL entry');
verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE kind = 'sitemap_probe'") >= 2, 'Common sitemap candidates are automatic when fields are blank');
$loops = 0;
while ($wpdb->get_var("SELECT id FROM $table WHERE kind IN ('robots','sitemap','sitemap_probe') AND status = 'pending' LIMIT 1") && ++$loops < 20) { call_hidden($plugin, 'step'); }
verify((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE url = %s", home_url('/sitemap-hidden-page/'))), 'robots.txt discovers a custom sitemap and its otherwise unknown URL');
verify((int) $wpdb->get_var("SELECT SUM(issue_count) FROM $table WHERE kind = 'sitemap_probe'") === 0, 'Absent guessed sitemap paths do not become false website errors');
$hits = 0;
for ($i = 0; $i < 100; $i++) { GSCSF_Repairs::redirect_target($old, false); }
verify($hits === 2, '100 shared-link checks reuse one redirect and destination request (2 versus 200)');
$broken = true;
verify(GSCSF_Repairs::redirect_target($old, true) === '' && $hits === 4, 'Repair preflight bypasses cached evidence and rejects a newly broken destination');
$job = get_option('gscsf_job'); $job['run_id'] = wp_generate_uuid4(); update_option('gscsf_job', $job);
verify(GSCSF_Repairs::redirect_target($old, false) === '' && $hits === 6, 'A new scan never reuses the previous scan redirect cache');
// Isolate a six-row queue to verify useful work per browser/cron tick.
$wpdb->query("DELETE FROM $table");
for ($i = 0; $i < 6; $i++) { call_hidden($plugin, 'enqueue', home_url('/batch-fixture-' . $i . '/')); }
$job = get_option('gscsf_job'); $job['phase'] = 'scan'; $job['auto_fix'] = false; update_option('gscsf_job', $job);
$units = call_hidden($plugin, 'batch');
verify($units === 6 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'done'") === 6, 'One batch processes six fast URLs instead of one');
call_hidden($plugin, 'batch');
verify(get_option('gscsf_job')['phase'] === 'complete' && !wp_next_scheduled('gscsf_tick'), 'Completion clears background continuation');
call_hidden($plugin, 'start', false);
verify(get_option('gscsf_job')['auto_fix'] === false, 'Scan Only explicitly disables automatic repair for that run');
GSCSF_Plugin::deactivate();
verify(get_option('gscsf_job')['phase'] === 'saved', 'Deactivation pauses active work before reactivation');
$_POST = array('daily' => '1');
call_hidden($plugin, 'save_settings');
verify((bool) wp_next_scheduled('gscsf_daily'), 'Daily scheduling starts only after an explicit settings save');
$_POST = array(); call_hidden($plugin, 'save_settings');
verify(!wp_next_scheduled('gscsf_daily'), 'Turning daily scans off removes the scheduled event');
update_option('gscsf_settings', array());
call_hidden($plugin, 'start', false);
$job = get_option('gscsf_job'); $job['phase'] = 'scan'; update_option('gscsf_job', $job);
$pause_during_fetch = function ($pre) { GSCSF_Plugin::deactivate(); return $pre; };
add_filter('pre_http_request', $pause_during_fetch, 20);
call_hidden($plugin, 'step');
remove_filter('pre_http_request', $pause_during_fetch, 20);
verify(get_option('gscsf_job')['phase'] === 'saved' && !wp_next_scheduled('gscsf_tick'), 'A worker finishing an in-flight fetch cannot reactivate paused work');
wp_delete_post($id, true);
echo "$checks workflow and speed checks passed.\n";
