<?php
if (!defined('ABSPATH')) { exit; }

class GSCSF_Extended {
    public static function utility($url) {
        return (bool) preg_match('~/(?:wp-(?:login|signup)\.php(?:/|$)|wp-admin(?:/|$)|xmlrpc\.php(?:/|$))~i', wp_parse_url($url, PHP_URL_PATH) ?: '');
    }

    public static function resolve($reference, $base) {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES, 'UTF-8'));
        if (!$reference || $reference[0] === '#' || preg_match('~^(?:mailto|tel|data|javascript|blob):~i', $reference)) { return ''; }
        $url = WP_Http::make_absolute_url($reference, $base);
        $p = wp_parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), array('https', 'http'), true) || isset($p['user']) || isset($p['pass'])) { return ''; }
        return esc_url_raw(preg_replace('/#.*$/', '', $url));
    }

    /** Match actual media URLs, never infer an image description from a filename. */
    public static function image_alt($src, $class) {
        if (!GSCSF_Audit::local_url($src)) { return array(0, ''); }
        $id = preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m) ? (int) $m[1] : attachment_url_to_postid($src);
        if (!$id || get_post_type($id) !== 'attachment') { return array(0, ''); }
        $full = wp_get_attachment_url($id);
        $urls = array($full);
        $meta = wp_get_attachment_metadata($id);
        if ($full && is_array($meta)) {
            foreach ($meta['sizes'] ?? array() as $size) { if (!empty($size['file'])) { $urls[] = dirname($full) . '/' . $size['file']; } }
        }
        if (!in_array($src, $urls, true)) { return array(0, ''); }
        $alt = trim(wp_strip_all_tags((string) get_post_meta($id, '_wp_attachment_image_alt', true)));
        return array($id, $alt);
    }

    public static function analyze($url, $html, $post, $safe) {
        $dom = GSCSF_Audit::document($html);
        if (!$dom) { return array(); }
        $x = new DOMXPath($dom); $out = array();
        $h1 = $x->query('//body//h1');
        if (!$h1->length || ($h1->length === 1 && trim($h1->item(0)->textContent) === '')) {
            $out[] = GSCSF_Audit::issue('h1_missing', 'Missing or empty H1. Review the theme/page-builder heading; an automatic insertion can duplicate a visually rendered heading.', false, 'warning');
        } elseif ($h1->length > 1) { $out[] = GSCSF_Audit::issue('h1_multiple', $h1->length . ' H1 elements. Review document structure; multiple H1s are not automatically a Google indexing error.', false, 'info'); }
        $f = GSCSF_Audit::facts($html);
        $length = function ($value) { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value); };
        if (!empty($f['titles'][0]) && $length($f['titles'][0]) > 60) { $out[] = GSCSF_Audit::issue('title_length', 'Title exceeds the 60-character audit guideline. Review readability; Google has no fixed character limit. It is not truncated automatically.', false, 'info'); }
        if (count($f['descriptions']) === 1 && $f['descriptions'][0] !== '') {
            $len = $length($f['descriptions'][0]);
            if ($len < 70 || $len > 160) { $out[] = GSCSF_Audit::issue('description_length', 'Description has ' . $len . ' characters (audit guideline: 70–160). This is editorial guidance, not a Google requirement.', false, 'info'); }
        }
        $og = array(); $langs = array();
        foreach ($x->query('//head/meta[@property]') as $node) { $og[strtolower($node->getAttribute('property'))][] = $node->getAttribute('content'); }
        $public_title = $safe && trim($post->post_title) !== '' && strpos(GSCSF_Audit::public_text($html), wp_strip_all_tags($post->post_title)) !== false;
        foreach (array('og:title', 'og:description', 'og:url', 'og:type') as $key) {
            if (!isset($og[$key])) {
                $can = $public_title && ($key !== 'og:description' || GSCSF_Audit::public_description($post, $html) !== '');
                $out[] = GSCSF_Audit::issue('missing_' . str_replace(':', '_', $key), 'Missing ' . $key . '. An optional social metadata supplement can use existing public content.', $can, 'info');
            } elseif (count($og[$key]) !== 1 || trim($og[$key][0]) === '') { $out[] = GSCSF_Audit::issue('og_conflict_' . $key, 'Empty or duplicate ' . $key . '. Review the source metadata provider.', false, 'warning'); }
        }
        foreach ($x->query('//head/link[@hreflang]') as $node) {
            $lang = strtolower(trim($node->getAttribute('hreflang')));
            $langs[$lang][] = $node->getAttribute('href');
        }
        if ($langs && !isset($langs['x-default'])) { $out[] = GSCSF_Audit::issue('hreflang_x_default', 'Hreflang annotations have no optional x-default fallback. Configure the language selector/fallback in the translation plugin if appropriate; do not point every translation to an arbitrary page.', false, 'info'); }
        foreach ($langs as $lang => $urls) {
            if (count(array_unique($urls)) > 1) { $out[] = GSCSF_Audit::issue('hreflang_conflict_' . $lang, 'Conflicting hreflang destinations for ' . $lang . '. Review the translation plugin.', false, 'warning'); }
        }
        $images = $x->query('//body//img'); $checked = 0;
        foreach ($images as $image) {
            if (++$checked > 200) { $out[] = GSCSF_Audit::issue('image_check_limit', 'Only the first 200 image elements were checked on this response.', false, 'info'); break; }
            // Explicit empty alt is a legitimate decorative-image choice.
            if ($image->hasAttribute('alt')) { continue; }
            $src = self::resolve($image->getAttribute('src'), $url);
            list($id, $alt) = self::image_alt($src, $image->getAttribute('class'));
            $can = $safe && $alt !== '' && !in_array(strtolower($image->getAttribute('role')), array('presentation', 'none'), true) && strtolower($image->getAttribute('aria-hidden')) !== 'true';
            $code = $can ? 'image_alt_' . $id : 'image_alt_review_' . md5($src);
            $out[$code] = GSCSF_Audit::issue($code, 'Missing alt attribute: ' . $src . ($can ? '. Existing Media Library alt text can be restored.' : '. Supply an accurate description, or explicitly use empty alt for a decorative image.'), $can, 'warning');
        }
        return array_values($out);
    }

    public static function links($html, $url) {
        $dom = GSCSF_Audit::document($html);
        if (!$dom) { return array(); }
        $x = new DOMXPath($dom); $out = array();
        // A base element changes relative URL meaning; respect it instead of guessing.
        $base = $x->query('//head/base[@href]');
        if ($base->length) { $url = self::resolve($base->item(0)->getAttribute('href'), $url) ?: $url; }
        foreach ($x->query('//a[@href] | //img[@src] | //link[@href] | //script[@src]') as $node) {
            $tag = strtolower($node->tagName);
            if ($tag === 'link' && !preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $node->getAttribute('rel'))) { continue; }
            $target = self::resolve($node->getAttribute(in_array($tag, array('a', 'link'), true) ? 'href' : 'src'), $url);
            if (!$target || self::utility($target)) { continue; }
            $out[$target] = array('url' => $target, 'kind' => $tag === 'img' ? 'image' : ($tag === 'link' ? 'css' : ($tag === 'script' ? 'script' : 'link')), 'nofollow' => (bool) preg_match('/(?:^|\s)nofollow(?:\s|$)/i', $node->getAttribute('rel')));
            if (count($out) >= 201) { break; }
        }
        return array_values($out);
    }

    public static function repair($html, $post, $flags) {
        $f = GSCSF_Audit::facts($html); if (!$f) { return $html; }
        $dom = GSCSF_Audit::document($html); $x = new DOMXPath($dom); $existing = array();
        foreach ($x->query('//head/meta[@property]') as $node) { $existing[strtolower($node->getAttribute('property'))] = true; }
        $public_title = trim($post->post_title) !== '' && strpos(GSCSF_Audit::public_text($html), wp_strip_all_tags($post->post_title)) !== false;
        $extra = '';
        if ($public_title) {
            $values = array('og:title' => wp_strip_all_tags($post->post_title), 'og:description' => GSCSF_Audit::public_description($post, $html), 'og:url' => get_permalink($post), 'og:type' => 'website');
            foreach ($values as $key => $value) {
                if ($value !== '' && !isset($existing[$key]) && !empty($flags['missing_' . str_replace(':', '_', $key)])) { $extra .= '<meta property="' . esc_attr($key) . '" content="' . esc_attr($value) . '">' . "\n"; }
            }
        }
        if ($extra !== '') { $pos = GSCSF_Audit::head_end($html); $html = substr($html, 0, $pos) . $extra . substr($html, $pos); }
        if (!class_exists('WP_HTML_Tag_Processor')) { return $html; }
        $p = new WP_HTML_Tag_Processor($html); $checked = 0;
        while ($p->next_tag('IMG') && ++$checked <= 200) {
            if ($p->get_attribute('alt') !== null || in_array(strtolower((string) $p->get_attribute('role')), array('none', 'presentation'), true) || strtolower((string) $p->get_attribute('aria-hidden')) === 'true') { continue; }
            $src = self::resolve((string) $p->get_attribute('src'), get_permalink($post));
            list($id, $alt) = self::image_alt($src, (string) $p->get_attribute('class'));
            if ($alt !== '' && !empty($flags['image_alt_' . $id])) { $p->set_attribute('alt', $alt); }
        }
        return $p->get_updated_html();
    }
}
