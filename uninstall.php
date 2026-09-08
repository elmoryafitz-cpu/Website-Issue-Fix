<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
wp_clear_scheduled_hook('gscsf_tick');
wp_clear_scheduled_hook('gscsf_daily');
wp_clear_scheduled_hook('gsc_schema_fix_daily_scan');
// Retain reports and repair flags by default so an accidental deletion is reversible.
// Opt-in removal is per site; see README for multisite cleanup.
if (!defined('GSCSF_DELETE_DATA') || !GSCSF_DELETE_DATA) { return; }
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gscsf_urls");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gscsf_changes");
delete_post_meta_by_key('_gscsf_repairs');
foreach (array('gscsf_version', 'gscsf_settings', 'gscsf_job', 'gscsf_lock', 'gscsf_ahrefs', 'gscsf_resource_limit', 'gscsf_sitemap_seen') as $name) { delete_option($name); }
$names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('gscsf_q_') . '%', $wpdb->esc_like('_transient_gscsf_') . '%', $wpdb->esc_like('_transient_timeout_gscsf_') . '%'));
foreach ($names as $name) { delete_option($name); }
