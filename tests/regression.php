<?php
// Run only against a disposable WordPress install: set GSCSF_WP_ROOT and GSCSF_TEST_SITE=1.
if (getenv('GSCSF_TEST_SITE') !== '1' || !is_file(getenv('GSCSF_WP_ROOT') . '/wp-load.php')) { fwrite(STDERR, "Set GSCSF_WP_ROOT and GSCSF_TEST_SITE=1 for a disposable test installation.\n"); exit(1); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require getenv('GSCSF_WP_ROOT') . '/wp-load.php';
set_exception_handler(function ($e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
$checks = 0;
function verify($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $checks++; echo "PASS: $message\n"; }
function invoke_private($object, $method, ...$args) { $r = new ReflectionMethod($object, $method); $r->setAccessible(true); return $r->invokeArgs($object, $args); }
function fixture_response($html, $code = 200, $headers = array()) { return array('response' => array('code' => $code), 'body' => $html, 'headers' => array_merge(array('content-type' => 'text/html'), $headers)); }
function repair_codes($issues) { return array_column(array_filter($issues, function ($i) { return $i['fixable']; }), 'code'); }
wp_set_current_user(1);
update_option('blog_public', 1); update_option('gscsf_settings', array()); update_option('gscsf_job', array('phase' => 'idle'));
$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Repair regression', 'post_content' => 'Existing public factual text.'));
$post = get_post($id); $url = get_permalink($id);
$old = home_url('/old-permanent-fixture/'); $target = home_url('/new-permanent-fixture/');
$mode = 'permanent'; $calls = 0;
$mock = function ($pre, $args, $request) use ($old, $target, &$mode, &$calls) {
    $calls++;
    if ($request === $old) { return fixture_response('', $mode === 'temporary' ? 302 : 301, array('location' => $mode === 'external' ? 'https://foreign.invalid/page/' : $target)); }
    if ($request === $target) { return fixture_response('<html><head></head><body>Destination</body></html>', $mode === 'broken' ? 404 : 200); }
    return new WP_Error('test', 'Network disabled in test');
};
add_filter('pre_http_request', $mock, 10, 3);
$html = '<html><head><title>Repair regression</title><meta name="description" content=""><link rel="canonical" href="/chosen/"></head><body><h1>Repair regression</h1><p>Existing public factual text.</p><a href="' . esc_attr($old) . '#section">Read more</a></body></html>';
$issues = GSCSF_Audit::analyze($url, fixture_response($html), $post);
$codes = repair_codes($issues);
verify(!array_diff(array('description_conflict', 'canonical_relative', 'viewport', 'html_language', 'internal_redirect_' . md5($old)), $codes), 'New repair families are detected from factual page evidence');
$flags = array_fill_keys($codes, true);
foreach ($issues as $issue) { if (isset($issue['replacement'])) { $flags[$issue['code']] = $issue['replacement']; } }
$fixed = GSCSF_Audit::repair_html($html, $post, $flags);
verify(strpos($fixed, 'content="Existing public factual text."') !== false, 'Single empty meta description is filled');
verify(strpos($fixed, esc_attr(home_url('/chosen/'))) !== false, 'Relative canonical preserves its selected destination');
verify(strpos($fixed, 'lang="en-US"') !== false && strpos($fixed, 'name="viewport"') !== false, 'Language and viewport are added');
verify(strpos($fixed, esc_attr($target) . '#section') !== false, 'Permanent internal redirect repair preserves link fragments');
verify(GSCSF_Audit::repair_html($fixed, $post, $flags) === $fixed, 'All new repairs are idempotent');
verify(!array_intersect($codes, repair_codes(GSCSF_Audit::analyze($url, fixture_response($fixed), $post))), 'Reanalysis verifies resolved candidates');
foreach (array('temporary', 'broken', 'external') as $mode) { verify(GSCSF_Repairs::redirect_target($old) === '', 'Reject ' . $mode . ' redirect replacement'); }
$mode = 'permanent';
$base = str_replace('<head>', '<head><base href="https://foreign.invalid/">', $html);
verify(!in_array('canonical_relative', repair_codes(GSCSF_Audit::analyze($url, fixture_response($base), $post)), true), 'Base element prevents ambiguous canonical repair');
verify(strpos(GSCSF_Audit::repair_html($base, $post, $flags), esc_attr($old) . '#section') !== false, 'Base element prevents relative link mutation');
$duplicate = str_replace('</head>', '<meta name="description" content="Keep this"></head>', $html);
verify(!in_array('description_conflict', repair_codes(GSCSF_Audit::analyze($url, fixture_response($duplicate), $post)), true), 'Multiple meta descriptions require review');
$noindex = str_replace('</head>', '<meta name="robots" content="noindex"></head>', $html);
verify(GSCSF_Audit::repair_html($noindex, $post, $flags) === $noindex, 'New repairs respect noindex');
update_option('gscsf_settings', array('repairs' => array_fill_keys(array_keys(GSCSF_Capabilities::groups()), 0)));
verify(!repair_codes(GSCSF_Audit::analyze($url, fixture_response($html), $post)), 'Disabled groups produce no automatic candidates');
verify(GSCSF_Audit::repair_html($html, $post, $flags) === $html, 'Disabled groups suspend existing output flags');
update_option('gscsf_settings', array());
$attachment = wp_insert_attachment(array('post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_title' => 'Image dimensions fixture'));
update_post_meta($attachment, '_wp_attached_file', '2026/09/dimension-fixture.png');
update_post_meta($attachment, '_wp_attachment_metadata', array('width' => 640, 'height' => 480, 'file' => '2026/09/dimension-fixture.png'));
$src = wp_get_attachment_url($attachment);
$image = '<img src="' . esc_attr($src) . '" alt="" class="wp-image-' . $attachment . '">';
$image_html = str_replace('</body>', $image . '</body>', $html);
$dim = 'image_dimensions_' . md5($src);
verify(in_array($dim, repair_codes(GSCSF_Audit::analyze($url, fixture_response($image_html), $post)), true), 'Verified Media Library image dimensions are detected');
$dimension_fixed = GSCSF_Audit::repair_html($image_html, $post, array($dim => true));
verify(strpos($dimension_fixed, 'width="640"') !== false && strpos($dimension_fixed, 'height="480"') !== false && strpos($dimension_fixed, 'alt=""') !== false, 'Dimensions restored without overwriting decorative alt');
$responsive = str_replace('<img ', '<img srcset="small.png 320w, large.png 640w" ', $image_html);
verify(GSCSF_Audit::repair_html($responsive, $post, array($dim => true)) === $responsive, 'Responsive art direction is not assigned guessed dimensions');
if (post_type_exists('product')) { unregister_post_type('product'); }
$profile = GSCSF_Capabilities::detect();
verify($profile['type'] === 'General WordPress website', 'General website detection works without commerce');
register_post_type('product', array('public' => true, 'label' => 'Products'));
$profile = GSCSF_Capabilities::detect();
verify($profile['type'] === 'Ecommerce / catalog website' && in_array('Registered catalog: product', $profile['commerce'], true), 'Commerce detection records catalog evidence');
unregister_post_type('product');
$plugin = new GSCSF_Plugin();
invoke_private($plugin, 'start');
verify(get_option('gscsf_job')['phase'] === 'detecting', 'Every new scan starts with website detection');
invoke_private($plugin, 'step');
verify(get_option('gscsf_job')['phase'] === 'posts' && get_option('gscsf_profile')['type'] === 'General WordPress website', 'Detection completes before issue scanning');
update_option('gscsf_job', array('phase' => 'complete'));
// Exercise the real repair transaction and undo with HTTP output simulated through the plugin.
remove_filter('pre_http_request', $mock, 10);
$http = function ($pre, $args, $request) use ($url, $post, $html, $mock) {
    if ($request === $url) { return fixture_response(GSCSF_Audit::repair_html($html, $post, (array) get_post_meta($post->ID, '_gscsf_repairs', true))); }
    return $mock($pre, $args, $request);
};
add_filter('pre_http_request', $http, 10, 3);
global $wpdb;
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gscsf_urls WHERE post_id = %d", $id));
if (!$row) { invoke_private($plugin, 'enqueue', $url, $id); $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gscsf_urls WHERE post_id = %d", $id)); }
invoke_private($plugin, 'scan_row', $row);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gscsf_urls WHERE id = %d", $row->id));
invoke_private($plugin, 'fix_row', $row);
$saved = get_post_meta($id, '_gscsf_repairs', true);
verify(is_array($saved['internal_redirect_' . md5($old)] ?? null), 'Repair transaction stores and verifies redirect destination evidence');
$log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gscsf_changes WHERE post_id = %d ORDER BY id DESC LIMIT 1", $id));
invoke_private($plugin, 'undo', $log->id);
verify(!get_post_meta($id, '_gscsf_repairs', true), 'Undo restores metadata and link repair flags together');
wp_delete_post($id, true); wp_delete_attachment($attachment, true);
echo "$checks regression checks passed.\n";
