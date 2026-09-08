<?php
if (!defined('ABSPATH')) { exit; }

/** Additional reversible HTML repairs; no database content rewriting or invented facts. */
class GSCSF_Repairs {
    public static function redirect_target($url, $fresh = true) {
        if (!GSCSF_Audit::local_url($url) || wp_parse_url($url, PHP_URL_QUERY) || GSCSF_Extended::utility($url)) { return ''; }
        $run = get_option('gscsf_job', array())['run_id'] ?? '';
        $key = 'gscsf_redirect_' . md5($run . '|' . $url);
        if (!$fresh && $run) {
            $cached = get_transient($key);
            if (is_array($cached)) { return $cached['target']; }
        }
        $remember = function ($target) use ($key, $fresh, $run) {
            if (!$fresh && $run) { set_transient($key, array('target' => $target), HOUR_IN_SECONDS); }
            return $target;
        };
        $args = array('timeout' => 2, 'redirection' => 0, 'limit_response_size' => 65536, 'user-agent' => 'GSC-Schema-Fix/' . GSC_SCHEMA_FIX_VERSION);
        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response) || !in_array(wp_remote_retrieve_response_code($response), array(301, 308), true)) { return $remember(''); }
        $raw = wp_remote_retrieve_header($response, 'location');
        if (!is_string($raw) || strpos($raw, '#') !== false) { return $remember(''); }
        $target = GSCSF_Extended::resolve($raw, $url);
        if (!$target || $target === $url || !GSCSF_Audit::local_url($target) || wp_parse_url($target, PHP_URL_QUERY) || GSCSF_Extended::utility($target)) { return $remember(''); }
        $final = wp_safe_remote_get($target, $args);
        return $remember(!is_wp_error($final) && wp_remote_retrieve_response_code($final) === 200 && stripos(wp_remote_retrieve_header($final, 'content-type'), 'text/html') !== false ? $target : '');
    }

    public static function canonical($html, $url, $value) {
        $dom = GSCSF_Audit::document($html);
        if (!$dom || trim($value) === '') { return ''; }
        $x = new DOMXPath($dom);
        if ($x->query('//head/base[@href]')->length) { return ''; }
        $target = GSCSF_Extended::resolve($value, $url);
        return $target && GSCSF_Audit::local_url($target) ? $target : '';
    }

    public static function dimensions($src, $class) {
        list($id) = GSCSF_Extended::image_alt($src, $class);
        if (!$id) { return array(); }
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta)) { return array(); }
        $full = wp_get_attachment_url($id);
        if ($src === $full && !empty($meta['width']) && !empty($meta['height'])) { return array((int) $meta['width'], (int) $meta['height']); }
        foreach ($meta['sizes'] ?? array() as $size) {
            if (!empty($size['file']) && $src === dirname($full) . '/' . $size['file'] && !empty($size['width']) && !empty($size['height'])) { return array((int) $size['width'], (int) $size['height']); }
        }
        return array();
    }

    public static function analyze($html, $url, $post, $safe, $fresh = true) {
        $dom = GSCSF_Audit::document($html); if (!$dom) { return array(); }
        $x = new DOMXPath($dom); $out = array();
        $root = $x->query('/html')->item(0);
        if ($root && trim($root->getAttribute('lang')) === '') { $out[] = GSCSF_Audit::issue('html_language', 'Missing document language. Use the configured WordPress language; existing language choices are preserved.', $safe && get_bloginfo('language') !== '', 'info'); }
        $checked = 0; $has_base = $x->query('//head/base[@href]')->length > 0;
        // Bounded probes: at most two unique internal anchors per page, no redirect following.
        $anchors = array();
        if ($safe && !$has_base && GSCSF_Capabilities::enabled('internal_redirect_probe')) {
            foreach ($x->query('//body//a[@href]') as $anchor) {
                if ($anchor->hasAttribute('download')) { continue; }
                $source = GSCSF_Extended::resolve($anchor->getAttribute('href'), $url);
                if (!$source || !GSCSF_Audit::local_url($source) || GSCSF_Extended::utility($source) || wp_parse_url($source, PHP_URL_QUERY) || isset($anchors[$source])) { continue; }
                if (untrailingslashit($source) === untrailingslashit(home_url('/'))) { continue; }
                $linked_id = url_to_postid($source);
                if ($linked_id && untrailingslashit(get_permalink($linked_id)) === untrailingslashit($source)) { continue; }
                if (count($anchors) >= 2) { break; }
                $anchors[$source] = true;
                $target = self::redirect_target($source, $fresh);
                if ($target) {
                    $issue = GSCSF_Audit::issue('internal_redirect_' . md5($source), 'Internal link ' . $source . ' permanently redirects to verified HTML destination ' . $target . '. Auto Fix can update the rendered link.', true, 'info');
                    $issue['replacement'] = array('from' => $source, 'to' => $target);
                    $out[] = $issue;
                }
            }
        }
        foreach ($x->query('//body//img') as $img) {
            if (++$checked > 200) { break; }
            if ($img->hasAttribute('width') || $img->hasAttribute('height') || $img->hasAttribute('srcset') || $img->hasAttribute('sizes') || $img->hasAttribute('style') || strtolower($img->parentNode->nodeName) === 'picture' || $has_base) { continue; }
            $src = GSCSF_Extended::resolve($img->getAttribute('src'), $url);
            $dimensions = self::dimensions($src, $img->getAttribute('class'));
            if (!$dimensions) { continue; }
            $code = 'image_dimensions_' . md5($src);
            $out[$code] = GSCSF_Audit::issue($code, 'Missing image dimensions: ' . $src . '. Media Library dimensions can reserve layout space; this alone does not certify Core Web Vitals.', $safe, 'info');
        }
        return array_values($out);
    }

    public static function repair($html, $post, $flags) {
        $facts = GSCSF_Audit::facts($html); if (!$facts || !class_exists('WP_HTML_Tag_Processor')) { return $html; }
        $dom = GSCSF_Audit::document($html); $x = new DOMXPath($dom);
        $has_base = $x->query('//head/base[@href]')->length > 0;
        $p = new WP_HTML_Tag_Processor($html); $in_head = false; $picture = 0; $checked = 0;
        while ($p->next_tag(array('tag_closers' => 'visit'))) {
            $tag = $p->get_tag(); $closing = $p->is_tag_closer();
            if ($tag === 'HEAD') { $in_head = !$closing; }
            if ($tag === 'BODY' && !$closing) { $in_head = false; }
            if ($tag === 'PICTURE') { $picture = max(0, $picture + ($closing ? -1 : 1)); }
            if ($closing) { continue; }
            if ($tag === 'A' && !$has_base && $p->get_attribute('download') === null) {
                $href = (string) $p->get_attribute('href');
                $source = GSCSF_Extended::resolve($href, get_permalink($post));
                $map = $flags['internal_redirect_' . md5($source)] ?? null;
                if (is_array($map) && ($map['from'] ?? '') === $source && GSCSF_Audit::local_url($map['to'] ?? '') && !GSCSF_Extended::utility($map['to'])) {
                    $fragment = strpos($href, '#') !== false ? substr($href, strpos($href, '#')) : '';
                    $p->set_attribute('href', $map['to'] . $fragment);
                }
            }
            if ($tag === 'HTML' && !empty($flags['html_language']) && trim((string) $p->get_attribute('lang')) === '') { $p->set_attribute('lang', get_bloginfo('language')); }
            if ($in_head && $tag === 'META' && strtolower((string) $p->get_attribute('name')) === 'description' && !empty($flags['description_conflict']) && count($facts['descriptions']) === 1 && $facts['descriptions'][0] === '') {
                $description = GSCSF_Audit::public_description($post, $html);
                if ($description !== '') { $p->set_attribute('content', $description); }
            }
            if ($in_head && $tag === 'LINK' && preg_match('/(?:^|\s)canonical(?:\s|$)/i', (string) $p->get_attribute('rel')) && !empty($flags['canonical_relative']) && count($facts['canonicals']) === 1) {
                $value = (string) $p->get_attribute('href');
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $absolute = self::canonical($html, get_permalink($post), $value);
                    if ($absolute) { $p->set_attribute('href', $absolute); }
                }
            }
            if ($tag === 'IMG' && ++$checked <= 200 && !$picture && !$has_base) {
                foreach (array('width', 'height', 'srcset', 'sizes', 'style') as $attribute) { if ($p->get_attribute($attribute) !== null) { continue 2; } }
                $src = GSCSF_Extended::resolve((string) $p->get_attribute('src'), get_permalink($post));
                if (empty($flags['image_dimensions_' . md5($src)])) { continue; }
                $dimensions = self::dimensions($src, (string) $p->get_attribute('class'));
                if ($dimensions) { $p->set_attribute('width', $dimensions[0]); $p->set_attribute('height', $dimensions[1]); }
            }
        }
        $html = $p->get_updated_html();
        if (!empty($flags['viewport']) && !$facts['viewport']) {
            $pos = GSCSF_Audit::head_end($html);
            $html = substr($html, 0, $pos) . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n" . substr($html, $pos);
        }
        return $html;
    }
}
