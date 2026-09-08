<?php
if (!defined('ABSPATH')) { exit; }

/** HTML inspection is deliberately separate from mutations and Google index data. */
class GSCSF_Audit {
    const MAX_BODY = 4194304;

    public static function issue($code, $message, $fixable = false, $severity = 'warning', $source = 'local') {
        return compact('code', 'message', 'fixable', 'severity', 'source');
    }

    public static function local_url($url) {
        $a = wp_parse_url($url);
        $b = wp_parse_url(home_url('/'));
        if (!$a || !$b || !isset($a['host'], $a['scheme']) || isset($a['user']) || isset($a['pass'])) { return false; }
        if (!in_array(strtolower($a['scheme']), array('https', 'http'), true)) { return false; }
        if (strtolower($a['host']) !== strtolower($b['host']) || ($a['port'] ?? null) !== ($b['port'] ?? null)) { return false; }
        $base = trailingslashit($b['path'] ?? '/');
        $path = $a['path'] ?? '/';
        if (preg_match('~(?:^|/|\\\\)\.{1,2}(?:/|\\\\|$)~', rawurldecode($path))) { return false; }
        return $base === '/' || $path === rtrim($base, '/') || strpos($path, $base) === 0;
    }

    public static function fetch($url, $external = false) {
        if (!self::local_url($url) && !$external) { return new WP_Error('scope', 'URL is outside this WordPress site.'); }
        $parts = wp_parse_url($url);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) { return new WP_Error('scope', 'Unsupported URL.'); }
        // No cookies, credentials, arbitrary hosts, insecure TLS, or automatic redirects.
        $started = microtime(true);
        $response = wp_safe_remote_get($url, array(
            'timeout' => 12, 'redirection' => 0, 'limit_response_size' => self::MAX_BODY,
            'user-agent' => 'GSC-Schema-Fix/' . GSC_SCHEMA_FIX_VERSION . ' WordPress site audit',
            'headers' => array('Cache-Control' => 'no-cache'),
        ));
        if (!is_wp_error($response)) { $response['gscsf_elapsed_ms'] = (int) round((microtime(true) - $started) * 1000); }
        return $response;
    }

    public static function document($html) {
        if (!class_exists('DOMDocument')) { return false; }
        $old = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        return $ok ? $dom : false;
    }

    public static function head_end($html) {
        // Ignore apparent closing tags inside comments and raw-text elements.
        $pattern = '~<!--.*?(?:-->|$)|<(script|style|title|textarea)\b(?:[^\x27"<>]|"[^"]*"|\x27[^\x27]*\x27)*>.*?</\1\s*>|</head\s*>~is';
        if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) { return false; }
        foreach ($matches[0] as $match) {
            if (preg_match('~^</head\s*>$~i', $match[0])) { return $match[1]; }
        }
        return false;
    }

    public static function facts($html) {
        $dom = self::document($html);
        if (!$dom) { return false; }
        $x = new DOMXPath($dom);
        $facts = array('titles' => array(), 'descriptions' => array(), 'canonicals' => array(), 'robots' => array(), 'images' => array(), 'json' => array(), 'viewport' => false, 'other_schema' => false);
        foreach ($x->query('//head/title') as $node) { $facts['titles'][] = trim($node->textContent); }
        foreach ($x->query('//meta') as $node) {
            $name = strtolower($node->getAttribute('name'));
            $content = trim($node->getAttribute('content'));
            if (in_array($name, array('robots', 'googlebot', 'googlebot-news'), true)) { $facts['robots'][] = $content; }
            if (strtolower($node->parentNode->nodeName) !== 'head') { continue; }
            if ($name === 'description') { $facts['descriptions'][] = $content; }
            if ($name === 'viewport') { $facts['viewport'] = true; }
            if (strtolower($node->getAttribute('property')) === 'og:image') { $facts['images'][] = $content; }
        }
        foreach ($x->query('//head/link') as $node) {
            if (in_array('canonical', preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))), true)) {
                $facts['canonicals'][] = trim($node->getAttribute('href'));
            }
        }
        foreach ($x->query('//script') as $node) {
            if (strtolower(trim($node->getAttribute('type'))) === 'application/ld+json') { $facts['json'][] = trim($node->textContent); }
        }
        $facts['other_schema'] = $x->query('//*[@itemscope or @typeof]')->length > 0;
        return $facts;
    }

    public static function description($post) {
        if (!$post) { return ''; }
        $text = $post->post_excerpt ?: $post->post_content;
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        // Do not execute blocks, shortcodes or untrusted embeds during a scan.
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($text), true)));
        return wp_trim_words($text, 30, '…');
    }

    public static function public_text($html) {
        $dom = self::document($html);
        if (!$dom) { return ''; }
        $x = new DOMXPath($dom);
        foreach ($x->query('//script | //style | //template | //noscript | //*[@hidden or @aria-hidden="true"]') as $node) {
            if ($node->parentNode) { $node->parentNode->removeChild($node); }
        }
        return trim(preg_replace('/\s+/u', ' ', $dom->textContent));
    }

    public static function public_description($post, $html) {
        $description = self::description($post);
        $prefix = preg_replace('/…$/u', '', $description);
        // Membership/paywall providers can restrict otherwise published posts.
        // Never expose stored text that was absent from the anonymous response.
        return $prefix !== '' && strpos(self::public_text($html), $prefix) !== false ? $description : '';
    }

    public static function eligible($post) {
        return $post && $post->post_status === 'publish' && empty($post->post_password)
            && is_post_type_viewable($post->post_type) && (bool) get_option('blog_public');
    }

    public static function blocked($directives) {
        return (bool) preg_match('/(?:^|[\s,:;])(?:noindex|none)(?:$|[\s,;])/i', implode(' ', (array) $directives));
    }

    public static function schema_nodes($value, &$nodes, $depth = 0) {
        if (!is_array($value) || $depth > 30 || count($nodes) > 500) { return; }
        if (isset($value['@type'])) { $nodes[] = $value; }
        foreach ($value as $child) { if (is_array($child)) { self::schema_nodes($child, $nodes, $depth + 1); } }
    }

    public static function analyze($url, $response, $post = null) {
        $out = array();
        if (is_wp_error($response)) {
            return array(self::issue('fetch_failed', 'Could not fetch this URL. Check DNS, TLS, firewall and loopback access; this does not prove Googlebot is blocked.', false, 'error'));
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 300 && $status < 400) {
            return array(self::issue('redirect', 'HTTP ' . $status . '. Target: ' . wp_remote_retrieve_header($response, 'location') . '. A redirect can be intentional; update internal links or repair the mapping if wrong.', false, 'info'));
        }
        if ($status !== 200) {
            return array(self::issue('http_' . $status, 'HTTP ' . $status . '. Check deleted content, permissions, rate limits or hosting. Keep intentional 404/410 responses; use a relevant replacement only when one exists.', false, 'error'));
        }
        if (GSCSF_Extended::utility($url)) { return array(self::issue('utility_page', 'WordPress login/admin utility page. Keep intentional noindex; headings and missing SEO metadata here are not public-content repair targets.', false, 'info')); }
        $html = wp_remote_retrieve_body($response);
        if (strlen($html) >= self::MAX_BODY) { return array(self::issue('body_limit', 'Response reached the 4 MiB audit limit. HTML inspection is incomplete.', false, 'error')); }
        if (stripos(wp_remote_retrieve_header($response, 'content-type'), 'text/html') === false) {
            return array(self::issue('not_html', 'This URL did not return HTML. Metadata checks were skipped.', false, 'info'));
        }
        $f = self::facts($html);
        if (!$f || self::head_end($html) === false) { return array(self::issue('parse_failed', 'A complete HTML head could not be inspected. Check PHP DOM support and theme output.', false, 'error')); }
        $directives = array_merge($f['robots'], (array) wp_remote_retrieve_header($response, 'x-robots-tag'));
        $blocked = self::blocked($directives);
        if ($blocked) { $out[] = self::issue('noindex', 'A noindex/none directive is present. Review intent in WordPress, your SEO plugin or server configuration; it is preserved.', false, 'info'); }
        $safe = self::eligible($post) && !$blocked && untrailingslashit($url) === untrailingslashit(get_permalink($post));
        $out = array_merge($out, GSCSF_Extended::analyze($url, $html, $post, $safe));
        if (($response['gscsf_elapsed_ms'] ?? 0) > 3000) { $out[] = self::issue('slow_fetch', 'Full HTTP fetch exceeded 3 seconds. This single server-side measurement is not TTFB, browser load time or Core Web Vitals; review hosting and caching.', false, 'info'); }
        $public_title = $safe && trim($post->post_title) !== '' && strpos(self::public_text($html), wp_strip_all_tags($post->post_title)) !== false;
        if (!$f['titles'] || $f['titles'][0] === '') {
            $out[] = self::issue('missing_title', 'Missing HTML title. Auto Fix can use an existing title also present in the public response when no title element exists.', $public_title && !$f['titles'], 'warning');
        } elseif (count($f['titles']) > 1) { $out[] = self::issue('duplicate_title', 'Multiple HTML title elements. Select one theme/SEO provider.', false, 'warning'); }
        if (!$f['descriptions']) {
            $out[] = self::issue('missing_description', 'No meta description. Auto Fix can derive a short description from existing publicly served text. This is an SEO improvement, not a confirmed GSC error.', $safe && self::public_description($post, $html) !== '', 'info');
        } elseif (count($f['descriptions']) > 1 || $f['descriptions'][0] === '') {
            $out[] = self::issue('description_conflict', 'Empty or multiple meta descriptions. Correct the theme/SEO provider rather than choosing an arbitrary description.', false, 'warning');
        }
        $link = wp_remote_retrieve_header($response, 'link');
        $http_canonical = (bool) preg_match('/rel\s*=\s*["\x27]?canonical/i', is_array($link) ? implode(',', $link) : $link);
        if (!$f['canonicals'] && !$http_canonical) {
            $out[] = self::issue('missing_canonical', 'No canonical hint found. Auto Fix can add the published permalink on eligible singular content.', $safe, 'info');
        } elseif (count($f['canonicals']) > 1) {
            $out[] = self::issue('canonical_conflict', 'Multiple HTML canonicals. Review the intended canonical in your theme/SEO plugin.', false, 'warning');
        } elseif ($f['canonicals'] && !filter_var($f['canonicals'][0], FILTER_VALIDATE_URL)) {
            $out[] = self::issue('canonical_relative', 'Canonical is empty or not an absolute URL. Configure an absolute preferred URL.', false, 'warning');
        } elseif ($f['canonicals'] && untrailingslashit($f['canonicals'][0]) !== untrailingslashit($url)) {
            $out[] = self::issue('alternate_canonical', 'Canonical points to ' . $f['canonicals'][0] . '. This can be correct for duplicate content; review before changing.', false, 'info');
        }
        if (!$f['viewport']) { $out[] = self::issue('viewport', 'No viewport meta tag. Check the responsive theme. This is not the retired Mobile Usability report.', false, 'info'); }
        if (strpos($url, 'https://') === 0 && preg_match('/<(?:img|script|iframe|link)\b[^>]*(?:src|href)\s*=\s*["\x27]http:\/\//i', $html)) {
            $out[] = self::issue('mixed_content', 'HTTP resource references found on an HTTPS page. Update the source asset URLs after checking HTTPS availability.', false, 'warning');
        }
        if (!$f['images'] && $safe && get_the_post_thumbnail_url($post, 'full') && strpos(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), get_the_post_thumbnail_url($post, 'full')) !== false) {
            $out[] = self::issue('missing_og_image', 'No og:image. Auto Fix can reference the existing featured image; search appearance is not guaranteed.', true, 'info');
        }
        if (!$f['json'] && !$f['other_schema']) {
            $general = $public_title && !in_array($post->post_type, array('product', 'download', 'wpsc-product', 'bigcommerce_product'), true);
            $out[] = self::issue('missing_schema', 'No JSON-LD, microdata or RDFa detected. Optional WebPage markup can describe existing general content; missing schema alone does not prevent indexing.', $general, 'info');
        }
        $nodes = array();
        foreach ($f['json'] as $json) {
            $data = json_decode($json, true, 64);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $out[] = self::issue('invalid_jsonld', 'Invalid or non-object JSON-LD. Repair the theme/plugin producing this script; automatic replacement could lose valid data.', false, 'error');
            } else { self::schema_nodes($data, $nodes); }
        }
        foreach ($nodes as $node) {
            foreach ((array) $node['@type'] as $type) {
                if (!is_string($type)) { continue; }
                $type = preg_replace('~^https?://schema.org/~', '', $type);
                if (in_array($type, array('FAQPage', 'HowTo', 'CourseInfo', 'LearningVideo', 'Vehicle', 'SpecialAnnouncement', 'PracticeProblem'), true)) {
                    $out[] = self::issue('retired_' . $type, $type . ' markup does not provide its former dedicated Google rich-result feature. It may still have non-Google uses; no replacement is required.', false, 'info');
                }
                // A deliberately small baseline; Google inspection supplies feature-specific errors when connected.
                $required = array('Product' => array('name'), 'Offer' => array('price', 'priceCurrency'), 'Review' => array('author'), 'BreadcrumbList' => array('itemListElement'), 'VideoObject' => array('name', 'thumbnailUrl', 'uploadDate'), 'Event' => array('name', 'startDate', 'location'), 'Recipe' => array('name', 'image'), 'JobPosting' => array('title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'));
                if ($type === 'Offer' && isset($node['priceSpecification'])) { $required[$type] = array(); }
                // Remote-only jobs can use applicantLocationRequirements instead of a physical jobLocation.
                if ($type === 'JobPosting' && isset($node['jobLocationType']) && $node['jobLocationType'] === 'TELECOMMUTE') { $required[$type] = array('title', 'description', 'datePosted', 'hiringOrganization', 'applicantLocationRequirements'); }
                foreach ($required[$type] ?? array() as $field) {
                    if (!isset($node[$field]) || $node[$field] === '' || $node[$field] === array()) {
                        $out[] = self::issue('schema_' . $type . '_' . $field, $type . ' is missing ' . $field . ' in this node. Review the complete graph and applicable Google feature requirements; enter factual data in its source plugin.', false, 'warning');
                    }
                }
                if ($type === 'Product' && !isset($node['offers']) && !isset($node['review']) && !isset($node['aggregateRating'])) {
                    $out[] = self::issue('product_eligibility', 'Product has no offers, review or aggregateRating in this node. Check rich-result eligibility using real product data.', false, 'warning');
                }
            }
        }
        return array_values(array_reduce($out, function ($all, $issue) { $all[$issue['code']] = $issue; return $all; }, array()));
    }

    /** Insert only absent tags. Never rewrite a theme document or override another provider. */
    public static function repair_html($html, $post, $flags) {
        if (!self::eligible($post) || strlen($html) >= self::MAX_BODY || self::head_end($html) === false) { return $html; }
        $f = self::facts($html);
        if (!$f || self::blocked($f['robots'])) { return $html; }
        foreach (headers_list() as $header) {
            if (stripos($header, 'X-Robots-Tag:') === 0 && self::blocked(array($header))) { return $html; }
        }
        $extra = '';
        $public_title = trim($post->post_title) !== '' && strpos(self::public_text($html), wp_strip_all_tags($post->post_title)) !== false;
        if (!empty($flags['missing_title']) && !$f['titles'] && $public_title) {
            $extra .= '<title>' . esc_html(wp_strip_all_tags($post->post_title)) . '</title>' . "\n";
        }
        $description = self::public_description($post, $html);
        if (!empty($flags['missing_description']) && !$f['descriptions'] && $description !== '') {
            $extra .= '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        }
        $http_canonical = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Link:') === 0 && preg_match('/rel\s*=\s*["\x27]?canonical/i', $header)) { $http_canonical = true; }
        }
        if (!empty($flags['missing_canonical']) && !$f['canonicals'] && !$http_canonical) {
            $extra .= '<link rel="canonical" href="' . esc_url(wp_get_canonical_url($post)) . '">' . "\n";
        }
        $image = get_the_post_thumbnail_url($post, 'full');
        if (!empty($flags['missing_og_image']) && !$f['images'] && $image && strpos(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), $image) !== false) {
            $extra .= '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
        if (!empty($flags['missing_schema']) && !$f['json'] && !$f['other_schema'] && $public_title) {
            $schema = array('@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => get_permalink($post) . '#webpage', 'url' => get_permalink($post), 'name' => wp_strip_all_tags($post->post_title), 'inLanguage' => get_bloginfo('language'));
            if ($description !== '') { $schema['description'] = $description; }
            if ($image && strpos(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), $image) !== false) { $schema['image'] = $image; }
            $extra .= '<script type="application/ld+json">' . wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
        if ($extra !== '') {
            $pos = self::head_end($html);
            $html = substr($html, 0, $pos) . "\n<!-- GSC Schema Fix: verified metadata supplements -->\n" . $extra . substr($html, $pos);
        }
        return GSCSF_Extended::repair($html, $post, $flags);
    }
}
