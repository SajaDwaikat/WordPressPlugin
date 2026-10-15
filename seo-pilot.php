<?php
/**
 * Plugin Name: SEO Pilot – SEO Assistant
 * Description: A local-first SEO audit and approval-based optimization assistant for WordPress.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: SEO Pilot
 * License: GPL-2.0-or-later
 * Text Domain: seo-pilot
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SEO_PILOT_VERSION', '0.1.0' );
define( 'SEO_PILOT_FILE', __FILE__ );
define( 'SEO_PILOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEO_PILOT_URL', plugin_dir_url( __FILE__ ) );

require_once SEO_PILOT_PATH . 'includes/class-seo-pilot.php';

function seo_pilot_boot() {
    return SEO_Pilot::instance();
}
add_action( 'plugins_loaded', 'seo_pilot_boot' );
