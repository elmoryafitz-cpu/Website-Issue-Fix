<?php
if (!defined('ABSPATH')) { exit; }

/** Site evidence and a shared policy for scanning and applying repairs. */
class GSCSF_Capabilities {
    public static function groups() {
        return array('metadata' => 'Titles, descriptions and canonical URLs', 'social' => 'Open Graph social metadata', 'images' => 'Media Library alt text and image dimensions', 'links' => 'Verified permanent internal redirect links', 'schema' => 'General WebPage structured data', 'document' => 'Document language and viewport');
    }

    public static function group($code) {
        if (strpos($code, 'internal_redirect_') === 0) { return 'links'; }
        if (strpos($code, 'image_') === 0) { return 'images'; }
        if (strpos($code, 'og_') !== false) { return 'social'; }
        if ($code === 'missing_schema') { return 'schema'; }
        if (in_array($code, array('html_language', 'viewport'), true)) { return 'document'; }
        return 'metadata';
    }

    public static function enabled($code) {
        $settings = get_option('gscsf_settings', array());
        return !isset($settings['repairs']) || !empty($settings['repairs'][self::group($code)]);
    }

    public static function detect() {
        $commerce = array();
        if (class_exists('WooCommerce') || defined('WC_VERSION')) { $commerce[] = 'WooCommerce'; }
        if (class_exists('Easy_Digital_Downloads') || defined('EDD_VERSION')) { $commerce[] = 'Easy Digital Downloads'; }
        if (defined('BIGCOMMERCE_VERSION')) { $commerce[] = 'BigCommerce'; }
        $types = array();
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ($type->name === 'attachment' || !is_post_type_viewable($type)) { continue; }
            $counts = wp_count_posts($type->name);
            $types[] = array('name' => $type->name, 'label' => $type->label, 'published' => (int) ($counts->publish ?? 0));
            if (in_array($type->name, array('product', 'download', 'wpsc-product', 'bigcommerce_product'), true)) { $commerce[] = 'Registered catalog: ' . $type->name; }
        }
        $seo = array();
        foreach (array('WPSEO_VERSION' => 'Yoast SEO', 'RANK_MATH_VERSION' => 'Rank Math', 'AIOSEO_VERSION' => 'All in One SEO', 'SEOPRESS_VERSION' => 'SEOPress') as $constant => $name) {
            if (defined($constant)) { $seo[] = $name; }
        }
        $profile = array('type' => $commerce ? 'Ecommerce / catalog website' : 'General WordPress website', 'commerce' => $commerce, 'content_types' => $types, 'seo' => $seo, 'public' => (bool) get_option('blog_public'), 'detected_at' => current_time('mysql'));
        update_option('gscsf_profile', $profile, false);
        return $profile;
    }

    public static function coverage($google) {
        return array(
            array('name' => 'Crawling and indexing signals', 'status' => 'Local checks', 'detail' => 'HTTP errors, redirects and loops, robots.txt availability, noindex, canonicals, sitemap parsing and membership. Local requests do not simulate Googlebot or JavaScript rendering.'),
            array('name' => 'Pages and resources', 'status' => 'Local checks', 'detail' => 'Public post types and archives; headings, metadata, language, images, links, CSS, mixed content and baseline structured data. Resource and response limits are reported.'),
            array('name' => 'Google indexed status and rich-result errors', 'status' => $google ? 'Inspection enabled' : 'Connection required', 'detail' => 'URL Inspection supplies indexed snapshots, subject to authentication and quotas. It does not run live tests or request indexing.'),
            array('name' => 'Core Web Vitals, video indexing, manual actions and security', 'status' => 'External review required', 'detail' => 'Not fully available through the URL Inspection API. Use Search Console, PageSpeed Insights and the relevant content or hosting tools. Indexing, ranking and complete rich-result eligibility cannot be guaranteed.'),
        );
    }
}
