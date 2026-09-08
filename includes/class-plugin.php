<?php
if (!defined('ABSPATH')) { exit; }

class GSCSF_Plugin {
    private $table;
    private $log;
    private $resource_count = null;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'gscsf_urls';
        $this->log = $wpdb->prefix . 'gscsf_changes';
        add_action('init', array($this, 'upgrade'));
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('wp_ajax_gscsf_action', array($this, 'ajax'));
        add_action('gscsf_tick', array($this, 'cron_tick'));
        add_action('gscsf_daily', array($this, 'daily'));
        add_action('template_redirect', array($this, 'buffer'), 99);
        // Retire the old unbounded/unsafe cron callback on upgrade.
        wp_clear_scheduled_hook('gsc_schema_fix_daily_scan');
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $urls = $wpdb->prefix . 'gscsf_urls';
        $log = $wpdb->prefix . 'gscsf_changes';
        dbDelta("CREATE TABLE $urls (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url text NOT NULL,
            url_hash char(32) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(20) NOT NULL DEFAULT 'page',
            status varchar(20) NOT NULL DEFAULT 'pending',
            issues longtext NOT NULL,
            issue_count int unsigned NOT NULL DEFAULT 0,
            fixable int unsigned NOT NULL DEFAULT 0,
            fix_state varchar(20) NOT NULL DEFAULT '',
            fixed int unsigned NOT NULL DEFAULT 0,
            checked_at datetime DEFAULT NULL,
            http_code int unsigned NOT NULL DEFAULT 0,
            redirect_to text DEFAULT NULL,
            refs longtext DEFAULT NULL,
            in_sitemap tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY status (status),
            KEY fixable (fixable)
        ) $charset;");
        dbDelta("CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            url text NOT NULL,
            before_flags longtext NOT NULL,
            after_flags longtext NOT NULL,
            codes text NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'verified',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) $charset;");
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $urls");
        if (!in_array('refs', $columns, true) || !in_array('redirect_to', $columns, true)) {
            add_action('admin_notices', function () { echo '<div class="notice notice-error"><p>GSC Schema Fix database upgrade could not finish. Check database permissions and storage before scanning.</p></div>'; });
            return;
        }
        update_option('gscsf_version', GSC_SCHEMA_FIX_VERSION, false);
        add_option('gscsf_settings', array('daily' => 0, 'auto' => 0, 'google' => 0, 'property' => '', 'sitemaps' => '', 'extra_urls' => ''), '', false);
        if (!wp_next_scheduled('gscsf_daily')) { wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'gscsf_daily'); }
        wp_clear_scheduled_hook('gsc_schema_fix_daily_scan');
    }

    public function upgrade() {
        if (get_option('gscsf_version') !== GSC_SCHEMA_FIX_VERSION) { self::install(); }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('gscsf_tick');
        wp_clear_scheduled_hook('gscsf_daily');
        wp_clear_scheduled_hook('gsc_schema_fix_daily_scan');
        // Persist the queue so reactivation can resume it from the dashboard.
    }

    private function settings() { return get_option('gscsf_settings', array()); }
    private function job() { return get_option('gscsf_job', array('phase' => 'idle')); }
    private function save_job($job) { update_option('gscsf_job', $job, false); }
    private function active($job) { return in_array($job['phase'], array('posts', 'terms', 'scan', 'summarize', 'fixing'), true); }

    /** DB-backed atomic lock: AJAX and cron must never mutate the queue concurrently. */
    private function lock() {
        global $wpdb;
        $token = time() . ':' . wp_generate_uuid4();
        if (add_option('gscsf_lock', $token, '', false)) { return $token; }
        $old = get_option('gscsf_lock');
        if ((int) $old < time() - 180) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'gscsf_lock', $old));
            wp_cache_delete('gscsf_lock', 'options');
            if (add_option('gscsf_lock', $token, '', false)) { return $token; }
        }
        return false;
    }

    private function unlock($token) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'gscsf_lock', $token));
        wp_cache_delete('gscsf_lock', 'options');
    }

    private function enqueue($url, $post_id = 0, $kind = 'page', $source = '', $in_sitemap = false) {
        global $wpdb;
        if (is_wp_error($url) || !is_string($url)) { return; }
        $url = esc_url_raw(preg_replace('/#.*$/', '', $url));
        if (!$url || strlen($url) > 2048 || (!GSCSF_Audit::local_url($url) && !($kind === 'resource' && !empty($this->settings()['external'])))) { return; }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,refs FROM {$this->table} WHERE url_hash = %s", md5($url)));
        if (!$existing && $kind === 'resource') {
            if ($this->resource_count === null) { $this->resource_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE kind = 'resource'"); }
            if ($this->resource_count >= 3000) { update_option('gscsf_resource_limit', true, false); return; }
        }
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->table} (url,url_hash,post_id,kind,issues,redirect_to,refs) VALUES (%s,%s,%d,%s,%s,%s,%s)", $url, md5($url), $post_id, $kind, '[]', '', '[]'));
        if ($wpdb->last_error) { throw new RuntimeException('Could not save the scan queue. Check database permissions and storage.'); }
        if (!$existing && $kind === 'resource') { $this->resource_count++; }
        if ($post_id) { $wpdb->update($this->table, array('post_id' => $post_id, 'kind' => 'page'), array('url_hash' => md5($url))); }
        elseif ($existing && $kind === 'page') { $wpdb->update($this->table, array('kind' => 'page'), array('url_hash' => md5($url))); }
        if ($in_sitemap) { $wpdb->update($this->table, array('in_sitemap' => 1), array('url_hash' => md5($url))); }
        if ($source && $source !== $url) {
            $refs = $existing ? (json_decode($existing->refs ?: '[]', true) ?: array()) : array();
            if (count($refs) < 5 && !in_array($source, $refs, true)) { $refs[] = $source; $wpdb->update($this->table, array('refs' => wp_json_encode($refs)), array('url_hash' => md5($url))); }
        }
    }

    private function start() {
        global $wpdb;
        if ($this->active($this->job())) { throw new RuntimeException('A scan or repair is already in progress. Resume it or cancel first.'); }
        $wpdb->query("DELETE FROM {$this->table}");
        $this->resource_count = 0;
        delete_option('gscsf_resource_limit'); delete_option('gscsf_sitemap_seen');
        $job = array('phase' => 'posts', 'cursor' => 0, 'term_offset' => 0, 'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"), 'started' => current_time('mysql'), 'completed' => '', 'notice' => '');
        $this->save_job($job);
        $this->enqueue(home_url('/'), (int) get_option('page_on_front'));
        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page) { $this->enqueue(get_permalink($posts_page)); }
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ($type->has_archive && is_post_type_viewable($type)) { $this->enqueue(get_post_type_archive_link($type->name)); }
        }
        $settings = $this->settings();
        foreach (preg_split('/\R/', $settings['extra_urls'] ?? '') as $url) { $this->enqueue(trim($url)); }
        foreach (get_option('gscsf_ahrefs', array())['urls'] ?? array() as $url) {
            $kind = preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?)(?:\?|$)/i', $url) ? 'resource' : 'page';
            $this->enqueue($url, 0, $kind);
        }
        $this->enqueue(home_url('/robots.txt'), 0, 'robots');
        $sitemaps = trim($settings['sitemaps'] ?? '');
        if ($sitemaps === '') {
            $sitemaps = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') ? home_url('/sitemap_index.xml') : (function_exists('get_sitemap_url') ? get_sitemap_url('index') : home_url('/wp-sitemap.xml'));
        }
        foreach (preg_split('/\R/', (string) $sitemaps) as $url) { $this->enqueue(trim($url), 0, 'sitemap'); }
        $this->schedule();
    }

    private function schedule() {
        if (!wp_next_scheduled('gscsf_tick')) { wp_schedule_single_event(time() + 15, 'gscsf_tick'); }
    }

    public function daily() {
        if (empty($this->settings()['daily'])) { return; }
        $token = $this->lock();
        if (!$token) { return; }
        try { if (!$this->active($this->job())) { $this->start(); } }
        catch (Throwable $e) { $job = $this->job(); $job['notice'] = $e->getMessage(); $this->save_job($job); }
        finally { $this->unlock($token); }
    }

    public function cron_tick() {
        $token = $this->lock();
        if (!$token) { $this->schedule(); return; }
        try { $this->step(); }
        catch (Throwable $e) { $job = $this->job(); $job['notice'] = $e->getMessage(); $job['phase'] = 'paused'; $this->save_job($job); }
        finally { $this->unlock($token); }
    }

    private function step() {
        global $wpdb;
        $job = $this->job();
        if (!$this->active($job)) { return; }
        if ($job['phase'] === 'posts') {
            $types = array_values(array_filter(get_post_types(array('public' => true), 'names'), function ($type) { return $type !== 'attachment' && is_post_type_viewable($type); }));
            $ids = array();
            if ($types) {
                $holders = implode(',', array_fill(0, count($types), '%s'));
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($holders) ORDER BY ID ASC LIMIT 100", array_merge(array($job['cursor'], $job['max_id']), $types)));
            }
            foreach ($ids as $id) { $this->enqueue(get_permalink($id), (int) $id); $job['cursor'] = (int) $id; }
            if (count($ids) < 100) { $job['phase'] = 'terms'; }
        } elseif ($job['phase'] === 'terms') {
            $taxonomies = get_taxonomies(array('public' => true), 'names');
            $terms = $taxonomies ? get_terms(array('taxonomy' => $taxonomies, 'hide_empty' => true, 'number' => 100, 'offset' => $job['term_offset'], 'orderby' => 'term_id', 'order' => 'ASC')) : array();
            if (is_wp_error($terms)) { throw new RuntimeException('Could not enumerate taxonomy archives.'); }
            foreach ($terms as $term) { $this->enqueue(get_term_link($term)); }
            $job['term_offset'] += count($terms);
            if (count($terms) < 100) { $job['phase'] = 'scan'; }
        } elseif ($job['phase'] === 'scan') {
            $row = $wpdb->get_row("SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
            if ($row) { $this->scan_row($row); }
            else {
                $job['phase'] = 'summarize'; $job['summary_cursor'] = 0;
            }
        } elseif ($job['phase'] === 'summarize') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE id > %d ORDER BY id LIMIT 100", $job['summary_cursor']));
            foreach ($rows as $row) { $this->summarize($row); $job['summary_cursor'] = (int) $row->id; }
            if (count($rows) < 100) {
                $job['phase'] = !empty($this->settings()['auto']) ? 'fixing' : 'complete';
                if ($job['phase'] === 'complete') { $job['completed'] = current_time('mysql'); }
            }
        } elseif ($job['phase'] === 'fixing') {
            $row = $wpdb->get_row("SELECT * FROM {$this->table} WHERE fixable > 0 AND fix_state = '' ORDER BY id ASC LIMIT 1");
            if ($row) { $this->fix_row($row); }
            else { $job['phase'] = 'complete'; $job['completed'] = current_time('mysql'); }
        }
        $this->save_job($job);
        if ($this->active($job)) { $this->schedule(); }
    }

    private function store_row($row, $issues, $extra = array()) {
        global $wpdb;
        $data = array_merge(array('issues' => wp_json_encode($issues), 'status' => 'done', 'issue_count' => count($issues), 'fixable' => count(array_filter($issues, function ($i) { return !empty($i['fixable']); })), 'checked_at' => current_time('mysql')), $extra);
        if (false === $wpdb->update($this->table, $data, array('id' => $row->id))) { throw new RuntimeException('Could not save scan results.'); }
    }

    private function scan_row($row) {
        $settings = $this->settings();
        $response = GSCSF_Audit::fetch($row->url, $row->kind === 'resource' && !empty($settings['external']));
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $redirect = '';
        if ($code >= 300 && $code < 400) {
            $redirect = GSCSF_Extended::resolve(wp_remote_retrieve_header($response, 'location'), $row->url);
            if ($redirect) { $this->enqueue($redirect, 0, 'resource', $row->url); }
        }
        if ($row->kind === 'sitemap') { $issues = $this->sitemap($response); }
        elseif ($row->kind === 'robots') { $issues = $this->robots($response); }
        elseif ($row->kind === 'resource') {
            $issues = array();
            if (is_wp_error($response)) { $issues[] = GSCSF_Audit::issue('resource_unavailable', 'Resource fetch failed or was blocked by safe HTTP validation. Check the source URL, DNS and access policy.', false, 'warning'); }
            elseif ($code >= 400 || $code === 0) { $issues[] = GSCSF_Audit::issue('resource_http_' . $code, 'Linked destination returned HTTP ' . $code . '. ' . ($code === 403 || $code === 429 ? 'Access/rate limiting may be involved; do not assume the file is missing.' : 'Review the source link and actual destination.'), false, 'error'); }
            elseif ($code >= 300) { $issues[] = GSCSF_Audit::issue('resource_redirect', 'Linked destination redirects to ' . $redirect . '. Review source references after verifying the final destination.', false, 'info'); }
            if ($code === 200 && stripos(wp_remote_retrieve_header($response, 'content-type'), 'text/css') !== false && strlen(wp_remote_retrieve_body($response)) > 150000) { $issues[] = GSCSF_Audit::issue('large_css', 'CSS response exceeds the 150 kB audit guideline. Review unused CSS, theme assets, compression and caching; automatic minification/removal could break layout.', false, 'info'); }
            if (!is_wp_error($response) && strlen(wp_remote_retrieve_body($response)) >= GSCSF_Audit::MAX_BODY) { $issues[] = GSCSF_Audit::issue('resource_limit', 'Resource response reached the 4 MiB inspection limit.', false, 'info'); }
        }
        else {
            $post = $row->post_id ? get_post($row->post_id) : null;
            $issues = GSCSF_Audit::analyze($row->url, $response, $post);
            if (!empty($settings['google'])) { $issues = array_merge($issues, GSCSF_Google::inspect($row->url, $settings['property'] ?? '')); }
            if ($code === 200 && !GSCSF_Extended::utility($row->url) && stripos(wp_remote_retrieve_header($response, 'content-type'), 'text/html') !== false) {
                $links = GSCSF_Extended::links(wp_remote_retrieve_body($response), $row->url);
                if (count($links) > 200) { $issues[] = GSCSF_Audit::issue('link_check_limit', 'Only the first 200 unique links/assets from this HTML response were added to the resource audit.', false, 'info'); }
                $external = 0; $nofollow = 0;
                foreach (array_slice($links, 0, 200) as $link) {
                    if (!GSCSF_Audit::local_url($link['url']) && empty($settings['external'])) { $external++; continue; }
                    if ($link['nofollow'] && GSCSF_Audit::local_url($link['url'])) { $nofollow++; }
                    $this->enqueue($link['url'], 0, 'resource', $row->url);
                }
                if ($external) { $issues[] = GSCSF_Audit::issue('external_unchecked', $external . ' external destinations were not fetched. Enable external destination checks to inspect them.', false, 'info'); }
                if ($nofollow) { $issues[] = GSCSF_Audit::issue('internal_nofollow', $nofollow . ' internal nofollow links observed. Review intent; nofollow is not removed automatically.', false, 'info'); }
            }
        }
        $this->store_row($row, $issues, array('http_code' => $code, 'redirect_to' => $redirect));
    }

    private function summarize($row) {
        global $wpdb;
        $issues = json_decode($row->issues, true) ?: array();
        if ($row->http_code >= 300 && $row->http_code < 400) {
            $next = $row; $seen = array(); $hops = 0;
            while ($next && $next->http_code >= 300 && $next->http_code < 400 && $hops++ < 10) {
                if (isset($seen[$next->url])) { $issues[] = GSCSF_Audit::issue('redirect_loop', 'A redirect loop was observed in the fetched destination graph. Repair the source redirect rules.', false, 'error'); break; }
                $seen[$next->url] = true;
                if (!$next->redirect_to) { $issues[] = GSCSF_Audit::issue('redirect_invalid', 'Redirect has no usable HTTP(S) Location.', false, 'error'); break; }
                $next = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE url_hash = %s", md5($next->redirect_to)));
                if (!$next) { $issues[] = GSCSF_Audit::issue('redirect_unchecked', 'A redirect destination was outside scan scope or a resource limit was reached. Final status is unknown.', false, 'info'); break; }
                if ($next->http_code >= 400 || !$next->http_code) { $issues[] = GSCSF_Audit::issue('broken_redirect', 'Redirect chain ends at HTTP ' . $next->http_code . ': ' . $next->url . '. Select an appropriate live destination; no homepage fallback is applied.', false, 'error'); break; }
            }
            if ($hops > 1) { $issues[] = GSCSF_Audit::issue('redirect_chain', 'Multiple redirect hops observed. Review source links/rules after confirming the intended destination.', false, 'info'); }
            if ($hops > 10) { $issues[] = GSCSF_Audit::issue('redirect_chain_limit', 'Chain inspection stopped after 10 hops; final destination is not confirmed.', false, 'warning'); }
        }
        $codes = array_column($issues, 'code');
        if ($row->kind === 'page' && $row->post_id && $row->http_code == 200 && !in_array('noindex', $codes, true) && !in_array('alternate_canonical', $codes, true) && get_option('blog_public')) {
            if (!$row->in_sitemap && get_option('gscsf_sitemap_seen')) { $issues[] = GSCSF_Audit::issue('not_in_sitemap', 'This published URL was not found in the successfully parsed sitemap entries. Check the sitemap provider and intended exclusions; sitemap coverage may be incomplete.', false, 'info'); }
            if (count(json_decode($row->refs ?: '[]', true) ?: array()) === 1) { $issues[] = GSCSF_Audit::issue('one_observed_inlink', 'Only one referring URL was observed in this bounded scan. Review navigation where useful; this is not proof that only one inlink exists.', false, 'info'); }
        }
        $this->store_row($row, $issues);
    }

    private function sitemap($response) {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array(GSCSF_Audit::issue('sitemap_unavailable', 'Sitemap could not be fetched with HTTP 200. Check the sitemap provider, URL and rewrite rules; enter the correct sitemap URL in settings.', false, 'warning'));
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) >= GSCSF_Audit::MAX_BODY || stripos($body, '<!DOCTYPE') !== false || !function_exists('simplexml_load_string')) {
            return array(GSCSF_Audit::issue('sitemap_limit', 'Sitemap cannot be parsed (4 MiB limit, unsupported DOCTYPE or missing PHP SimpleXML). Its URLs were not fully discovered. Split large sitemaps.', false, 'error'));
        }
        $old = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        if (!$xml || !in_array($xml->getName(), array('urlset', 'sitemapindex'), true)) {
            return array(GSCSF_Audit::issue('invalid_sitemap', 'Response is not a valid XML sitemap or sitemap index.', false, 'error'));
        }
        $kind = $xml->getName() === 'sitemapindex' ? 'sitemap' : 'page';
        if ($kind === 'page') { update_option('gscsf_sitemap_seen', true, false); }
        $locations = $xml->xpath('/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"] | /*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]');
        $skipped = 0;
        foreach ($locations as $loc) {
            $url = trim((string) $loc);
            if (!GSCSF_Audit::local_url($url)) { $skipped++; continue; }
            $this->enqueue($url, 0, $kind, '', $kind === 'page');
        }
        return $skipped ? array(GSCSF_Audit::issue('sitemap_external', $skipped . ' sitemap URLs were outside this WordPress site and were not scanned.', false, 'info')) : array();
    }

    private function robots($response) {
        $out = array();
        if (!get_option('blog_public')) { $out[] = GSCSF_Audit::issue('site_private', 'WordPress Search Engine Visibility discourages indexing. Confirm whether this is a private/staging site before changing Settings > Reading.', false, 'warning'); }
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 500) {
            $out[] = GSCSF_Audit::issue('robots_unavailable', 'robots.txt could not be fetched or returned a server error. Check hosting and firewall rules.', false, 'error');
        } elseif (wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            foreach (preg_split('/\R/', $body) as $line) {
                if (preg_match('/^\s*Sitemap:\s*(https?:\/\/\S+)/i', $line, $m)) { $this->enqueue($m[1], 0, 'sitemap'); }
            }
            if (preg_match('/^\s*Disallow:\s*\/\s*(?:#.*)?$/mi', $body)) {
                $out[] = GSCSF_Audit::issue('robots_disallow', 'robots.txt contains a root Disallow rule. Review its user-agent group; it may intentionally target a specific crawler. Rules are not changed automatically.', false, 'warning');
            }
            if (strlen($body) >= GSCSF_Audit::MAX_BODY) { $out[] = GSCSF_Audit::issue('robots_limit', 'robots.txt inspection reached the response-size limit.', false, 'warning'); }
        }
        return $out;
    }

    private function fix_row($row) {
        global $wpdb;
        $post = $row->post_id ? get_post($row->post_id) : null;
        if (!GSCSF_Audit::eligible($post)) {
            $wpdb->update($this->table, array('fix_state' => 'skipped', 'fixable' => 0), array('id' => $row->id)); return;
        }
        $fresh = GSCSF_Audit::analyze($row->url, GSCSF_Audit::fetch($row->url), $post);
        $original = json_decode($row->issues, true) ?: array();
        $allowed = array_column(array_filter($original, function ($i) { return !empty($i['fixable']); }), 'code');
        $codes = array_values(array_intersect($allowed, array_column(array_filter($fresh, function ($i) { return !empty($i['fixable']); }), 'code')));
        $google = array_values(array_filter($original, function ($i) { return $i['source'] === 'google' || in_array($i['code'], array('not_in_sitemap', 'one_observed_inlink', 'external_unchecked', 'internal_nofollow', 'link_check_limit'), true); }));
        if (!$codes) { $this->store_row($row, array_merge($fresh, $google), array('fix_state' => 'skipped', 'fixable' => 0)); return; }
        $before = get_post_meta($post->ID, '_gscsf_repairs', true);
        $before = is_array($before) ? $before : array();
        $after = $before;
        foreach ($codes as $code) { $after[$code] = true; }
        update_post_meta($post->ID, '_gscsf_repairs', $after);
        clean_post_cache($post->ID);
        do_action('gscsf_purge_url_cache', $row->url, $post->ID);
        $verified = GSCSF_Audit::analyze($row->url, GSCSF_Audit::fetch($row->url), $post);
        $remaining = array_column($verified, 'code');
        $unverifiable = array_intersect($remaining, array('fetch_failed', 'body_limit', 'not_html', 'parse_failed', 'redirect', 'noindex'));
        foreach ($remaining as $code) { if (strpos($code, 'http_') === 0) { $unverifiable[] = $code; } }
        $fixed = $unverifiable ? array() : array_values(array_diff($codes, $remaining));
        // Keep only verified additions. Failed attempts cannot silently leave an active repair behind.
        $final = $before;
        foreach ($fixed as $code) { $final[$code] = true; }
        if ($final) { update_post_meta($post->ID, '_gscsf_repairs', $final); }
        else { delete_post_meta($post->ID, '_gscsf_repairs'); }
        clean_post_cache($post->ID);
        if ($final !== $after) { do_action('gscsf_purge_url_cache', $row->url, $post->ID); }
        if ($fixed) {
            $saved = $wpdb->insert($this->log, array('post_id' => $post->ID, 'url' => $row->url, 'before_flags' => wp_json_encode($before), 'after_flags' => wp_json_encode($final), 'codes' => wp_json_encode($fixed), 'created_at' => current_time('mysql')));
            if ($saved === false) {
                if ($before) { update_post_meta($post->ID, '_gscsf_repairs', $before); }
                else { delete_post_meta($post->ID, '_gscsf_repairs'); }
                clean_post_cache($post->ID);
                do_action('gscsf_purge_url_cache', $row->url, $post->ID);
                throw new RuntimeException('Could not save undo history. Repair additions were rolled back; check database storage.');
            }
        }
        if (count($fixed) < count($codes)) {
            $verified[] = GSCSF_Audit::issue('fix_unverified', 'Some repairs could not be verified and were rolled back. Check page/CDN caches, theme output and loopback access, then rescan.', false, 'warning');
        }
        $this->store_row($row, array_merge($verified, $google), array('fix_state' => $fixed ? 'verified' : 'failed', 'fixed' => count($fixed), 'fixable' => 0));
    }

    private function undo($id) {
        global $wpdb;
        if ($this->active($this->job())) { throw new RuntimeException('Finish or cancel the current operation before undoing a repair.'); }
        $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->log} WHERE id = %d AND state = 'verified'", $id));
        if (!$entry || !current_user_can('edit_post', $entry->post_id)) { throw new RuntimeException('This repair is unavailable or cannot be edited.'); }
        $current = get_post_meta($entry->post_id, '_gscsf_repairs', true);
        if ($current !== json_decode($entry->after_flags, true)) { throw new RuntimeException('Repair flags changed since this entry. Undo newer repairs first.'); }
        $before = json_decode($entry->before_flags, true);
        if ($before) { update_post_meta($entry->post_id, '_gscsf_repairs', $before); }
        else { delete_post_meta($entry->post_id, '_gscsf_repairs'); }
        clean_post_cache($entry->post_id);
        do_action('gscsf_purge_url_cache', $entry->url, $entry->post_id);
        $wpdb->update($this->log, array('state' => 'undone'), array('id' => $entry->id));
        $job = $this->job(); $job['notice'] = 'Repair undone. Rescan to refresh the report; clear external page caches if used.'; $this->save_job($job);
    }

    public function buffer() {
        if (is_admin() || !is_singular() || is_feed() || is_preview() || is_embed() || is_user_logged_in() || is_paged() || (int) get_query_var('page') > 1) { return; }
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || !GSCSF_Audit::eligible($post)) { return; }
        $flags = get_post_meta($post->ID, '_gscsf_repairs', true);
        if (!is_array($flags) || !$flags) { return; }
        ob_start(function ($html) use ($post, $flags) {
            if (http_response_code() !== false && http_response_code() !== 200) { return $html; }
            return GSCSF_Audit::repair_html($html, $post, $flags);
        });
    }

    public function ajax() {
        if (!current_user_can('manage_options')) { wp_send_json_error('Administrator access required.', 403); }
        check_ajax_referer('gscsf_admin', 'nonce');
        $verb = sanitize_key(wp_unslash($_POST['verb'] ?? 'state'));
        if ($verb === 'state') { wp_send_json_success($this->state(absint($_POST['page'] ?? 0))); }
        $token = $this->lock();
        if (!$token) { wp_send_json_error('Another worker is processing this site. Try again shortly.', 409); }
        $error = null;
        try {
            if ($verb === 'start') { $this->start(); }
            elseif ($verb === 'tick') { $this->step(); }
            elseif ($verb === 'fix') {
                $job = $this->job();
                if ($job['phase'] !== 'complete') { throw new RuntimeException('Complete the entire scan before using Auto Fix.'); }
                $job['phase'] = 'fixing'; $job['notice'] = ''; $this->save_job($job); $this->schedule();
            } elseif ($verb === 'cancel') {
                $job = $this->job(); $job['phase'] = 'cancelled'; $job['notice'] = 'Operation cancelled. Existing verified repairs remain active.'; $this->save_job($job); wp_clear_scheduled_hook('gscsf_tick');
            } elseif ($verb === 'undo') { $this->undo(absint($_POST['id'] ?? 0)); }
            elseif ($verb === 'save') { $this->save_settings(); }
            elseif ($verb === 'import') {
                if ($this->active($this->job())) { throw new RuntimeException('Finish or cancel the current scan before importing.'); }
                $file = $_FILES['audit'] ?? array();
                if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { throw new RuntimeException('Upload failed. Check the server upload size limit and select a ZIP/CSV file.'); }
                $summary = GSCSF_Ahrefs::read($file['tmp_name'], $file['name']);
                if (!$summary['urls']) { throw new RuntimeException('No matching public URLs for this WordPress site. Check that this is the correct site export; login/admin URLs are deliberately excluded.'); }
                update_option('gscsf_ahrefs', $summary, false);
            } elseif ($verb === 'clear_import') {
                if ($this->active($this->job())) { throw new RuntimeException('Finish or cancel the current scan first.'); }
                delete_option('gscsf_ahrefs');
            }
            else { throw new RuntimeException('Unknown action.'); }
        } catch (Throwable $e) { $error = $e->getMessage(); }
        finally { $this->unlock($token); }
        if ($error) { wp_send_json_error($error, 400); }
        wp_send_json_success($this->state());
    }

    private function save_settings() {
        if ($this->active($this->job())) { throw new RuntimeException('Finish or cancel the current operation before changing settings.'); }
        $settings = array('daily' => !empty($_POST['daily']) ? 1 : 0, 'auto' => !empty($_POST['auto']) ? 1 : 0, 'google' => !empty($_POST['google']) ? 1 : 0, 'external' => !empty($_POST['external']) ? 1 : 0);
        $settings['property'] = trim(sanitize_text_field(wp_unslash($_POST['property'] ?? '')));
        if ($settings['google'] && (!GSCSF_Google::configured() || $settings['property'] === '')) { throw new RuntimeException('Configure a service account and enter the exact Search Console property before enabling Google inspection.'); }
        foreach (array('sitemaps', 'extra_urls') as $field) {
            $urls = array_filter(array_map('trim', preg_split('/\R/', wp_unslash($_POST[$field] ?? ''))));
            if (count($urls) > 200) { throw new RuntimeException('Use at most 200 entries per URL field; sitemap files can supply larger lists.'); }
            foreach ($urls as $url) { if (!GSCSF_Audit::local_url($url) || strlen($url) > 2048) { throw new RuntimeException('All custom URLs must be absolute URLs within this WordPress site.'); } }
            $settings[$field] = implode("\n", array_map('esc_url_raw', $urls));
        }
        update_option('gscsf_settings', $settings, false);
        delete_transient('gscsf_google_backoff');
        delete_transient('gscsf_google_token');
    }

    private function state($page = 0) {
        global $wpdb;
        $counts = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(status = 'done') AS done, SUM(issue_count) AS findings, SUM(fixable) AS fixable, SUM(fixed) AS fixed FROM {$this->table}", ARRAY_A);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,url,kind,post_id,issues,fix_state,checked_at,refs FROM {$this->table} WHERE issue_count > 0 ORDER BY id ASC LIMIT 50 OFFSET %d", $page * 50), ARRAY_A);
        foreach ($rows as &$row) { $row['issues'] = json_decode($row['issues'], true) ?: array(); $row['refs'] = json_decode($row['refs'] ?: '[]', true) ?: array(); }
        unset($row);
        $logs = $wpdb->get_results("SELECT id,url,codes,state,created_at FROM {$this->log} ORDER BY id DESC LIMIT 20", ARRAY_A);
        $import = get_option('gscsf_ahrefs', array());
        $import['url_count'] = count($import['urls'] ?? array()); unset($import['urls']);
        return array('job' => $this->job(), 'counts' => array_map('intval', $counts ?: array()), 'rows' => $rows, 'page' => $page, 'report_rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE issue_count > 0"), 'logs' => $logs, 'import' => $import, 'resource_limit' => (bool) get_option('gscsf_resource_limit'));
    }

    public function menu() { add_options_page('GSC Schema Fix', 'GSC Schema Fix', 'manage_options', 'gsc-schema-fix', array($this, 'page')); }
    public function assets($hook) {
        if ($hook !== 'settings_page_gsc-schema-fix') { return; }
        wp_enqueue_style('gscsf-admin', plugins_url('assets/admin.css', GSCSF_FILE), array(), GSC_SCHEMA_FIX_VERSION);
        wp_enqueue_script('gscsf-admin', plugins_url('assets/admin.js', GSCSF_FILE), array('jquery'), GSC_SCHEMA_FIX_VERSION, true);
        wp_localize_script('gscsf-admin', 'gscsf', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('gscsf_admin')));
    }

    public function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = $this->settings();
        ?>
        <div class="wrap gscsf">
            <h1>GSC Schema Fix <small><?php echo esc_html(GSC_SCHEMA_FIX_VERSION); ?></small></h1>
            <p>Website diagnostics for blogs, business sites, portfolios, publications, shops and public custom content.</p>
            <div class="gscsf-card">
                <h2>Scan your website</h2>
                <p>Checks published public content, homepage, archives and same-site sitemap URLs. Add old or missing URLs from Search Console below. Scans use server HTML, not JavaScript rendering. Findings include SEO suggestions and intentional exclusions, not only errors.</p>
                <p><button type="button" class="button button-primary" id="gscsf-start">Scan Website</button>
                <button type="button" class="button" id="gscsf-resume" hidden>Resume</button>
                <button type="button" class="button" id="gscsf-cancel" hidden>Cancel</button></p>
                <p id="gscsf-status" role="status" aria-live="polite">Loading scan status…</p>
                <progress id="gscsf-progress" max="100" value="0"></progress>
                <p id="gscsf-counts"></p>
                <div id="gscsf-message" role="status" aria-live="polite"></div>
            </div>
            <div class="gscsf-card">
                <h2>Scan results</h2>
                <p>Auto Fix supplements missing metadata and restores absent image alt attributes from existing Media Library descriptions. Every repair is rechecked over HTTP. Explicit empty alt, intentional noindex, redirects and business facts are preserved.</p>
                <button type="button" class="button button-primary" id="gscsf-fix" hidden>Auto Fix Solvable Issues</button>
                <div id="gscsf-results"></div>
                <p><button type="button" class="button" id="gscsf-prev" hidden>Previous 50</button> <button type="button" class="button" id="gscsf-next" hidden>Next 50</button></p>
            </div>
            <div class="gscsf-card">
                <h2>Verified repair history</h2>
                <p>Most recent 20 repairs. Undo newer repairs on the same page first. Deactivating this plugin also stops its metadata supplements.</p>
                <div id="gscsf-history"></div>
            </div>
            <div class="gscsf-card">
                <h2>Import an Ahrefs audit</h2>
                <p>Upload a ZIP, CSV or TSV export. Duplicate files are ignored; only URLs belonging to this WordPress site are imported. Login/admin URLs are excluded. Imported reports are historical evidence, not repair instructions. Run a fresh scan afterward.</p>
                <form id="gscsf-import" enctype="multipart/form-data">
                    <p><label for="gscsf-audit">Ahrefs export (up to 32 MiB; server limits may be lower)</label><br><input id="gscsf-audit" type="file" name="audit" accept=".zip,.csv,.tsv" required></p>
                    <button class="button" type="submit">Import Audit</button>
                    <button class="button" id="gscsf-clear-import" type="button">Clear Imported URLs</button>
                </form>
                <div id="gscsf-import-summary" aria-live="polite"></div>
            </div>
            <div class="gscsf-card">
                <h2>Scan settings</h2>
                <form id="gscsf-settings">
                    <p><label><input type="checkbox" name="daily" value="1" <?php checked(!empty($s['daily'])); ?>> Run daily scans with WordPress cron</label></p>
                    <p><label><input type="checkbox" name="auto" value="1" <?php checked(!empty($s['auto'])); ?>> Automatically apply verified safe repairs after a completed scan</label></p>
                    <p><label><input type="checkbox" name="external" value="1" <?php checked(!empty($s['external'])); ?>> Also fetch external link/image/CSS/script destinations found in public pages</label></p>
                    <p>External checks send ordinary HTTP requests to those public destinations, with no WordPress cookies. Resource checks are bounded to 200 unique destinations per page and 3,000 additional URLs per scan. Redirect destinations are checked separately and summarized after scanning.</p>
                    <p>Automatic mode is optional; the Auto Fix button works even when it is off. Background work depends on WordPress cron traffic. Large sites should configure a server cron.</p>
                    <p><label for="gscsf-sitemaps">Sitemap URLs (one per line; blank selects the core or detected SEO sitemap)</label><br>
                    <textarea id="gscsf-sitemaps" name="sitemaps" rows="3"><?php echo esc_textarea($s['sitemaps'] ?? ''); ?></textarea></p>
                    <p><label for="gscsf-urls">Additional same-site URLs, including old URLs from Search Console (one per line, maximum 200)</label><br>
                    <textarea id="gscsf-urls" name="extra_urls" rows="4"><?php echo esc_textarea($s['extra_urls'] ?? ''); ?></textarea></p>
                    <h3>Optional Google Search Console connection</h3>
                    <p>Local scanning works without Google credentials. To add indexed-page evidence, enable the Search Console API in Google Cloud, give a service account access to your property, and define <code>GSCSF_SERVICE_ACCOUNT_FILE</code> in wp-config.php with a private JSON-key path outside the web root. See the included README for setup.</p>
                    <p>Credentials: <strong><?php echo GSCSF_Google::configured() ? 'Server path configured (authentication tested during scan)' : 'Not configured'; ?></strong></p>
                    <p><label for="gscsf-property">Exact property (for example sc-domain:example.com or https://example.com/)</label><br>
                    <input type="text" id="gscsf-property" name="property" value="<?php echo esc_attr($s['property'] ?? ''); ?>"></p>
                    <p><label><input type="checkbox" name="google" value="1" <?php checked(!empty($s['google'])); ?>> Send scanned page URLs to Google for read-only URL Inspection</label></p>
                    <p>Google results are indexed snapshots, cached for 24 hours. A local budget of 1,900 new inspections per rolling 24 hours leaves room below Google's 2,000-per-property daily quota. Other tools share that quota. Deferred inspections are shown explicitly.</p>
                    <button type="submit" class="button">Save Settings</button>
                </form>
            </div>
            <div class="gscsf-card">
                <h2>What needs review outside this plugin</h2>
                <p>Google controls indexing and canonical selection. Content quality, discovered/crawled but not indexed, soft 404s, manual actions, security issues, video eligibility and Core Web Vitals may require content, hosting or specialist changes. The plugin cannot certify complete rich-result eligibility or guarantee rankings.</p>
                <p>After repairs, clear page/CDN caches and use Search Console's live test and Validate Fix where available. Submit your sitemap in Search Console. The general Indexing API is not a bulk-indexing tool for ordinary WordPress pages.</p>
                <p>Documentation reviewed: 8 September 2026. FAQ and HowTo rich-result promises and generated product facts from version 4 have been removed. Original settings and content remain in the database for review; the old schema generator is no longer active.</p>
                <p><a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Open Search Console</a> · <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener noreferrer">Rich Results Test</a> · <a href="https://pagespeed.web.dev/" target="_blank" rel="noopener noreferrer">PageSpeed Insights</a> · <a href="https://developers.google.com/search/updates" target="_blank" rel="noopener noreferrer">Google documentation updates</a></p>
            </div>
        </div>
        <?php
    }
}
