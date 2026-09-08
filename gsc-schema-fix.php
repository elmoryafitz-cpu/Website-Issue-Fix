<?php
/**
 * Plugin Name: GSC Schema Fix
 * Plugin URI: https://github.com/elmoryafitz-cpu/Website-Issue-Fix
 * Description: Site-wide WordPress SEO diagnostics, optional Search Console inspection, and verified, reversible automatic repairs.
 * Version: 5.2.0
 * Author: dratzymarcano
 * License: GPL v2 or later
 * Text Domain: gsc-schema-fix
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
define('GSC_SCHEMA_FIX_VERSION', '5.2.0');
define('GSCSF_FILE', __FILE__);
require_once __DIR__ . '/includes/class-audit.php';
require_once __DIR__ . '/includes/class-extended.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-repairs.php';
require_once __DIR__ . '/includes/class-ahrefs.php';
require_once __DIR__ . '/includes/class-google.php';
require_once __DIR__ . '/includes/class-plugin.php';
register_activation_hook(__FILE__, array('GSCSF_Plugin', 'install'));
register_deactivation_hook(__FILE__, array('GSCSF_Plugin', 'deactivate'));
add_action('plugins_loaded', function () { new GSCSF_Plugin(); });
