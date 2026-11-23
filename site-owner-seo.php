<?php
/**
 * Plugin Name:       Site Owner SEO
 * Description:       A simple SEO health check and editor for site owners: see your score, fix the five most important problems, and apply changes to your live pages.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            Saja Mohammad
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-owner-seo
 * Domain Path:       /languages
 *
 * @package SiteOwnerSEO
 */

// This file must stay parseable by old PHP versions so the requirements check can run and explain the problem.

defined( 'ABSPATH' ) || exit;

define( 'SOS_VERSION', '0.2.0' );
define( 'SOS_FILE', __FILE__ );
define( 'SOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SOS_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 autoloader for the SiteOwnerSEO namespace (maps to includes/).
 * Classes are only loaded after the requirements check passes.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'SiteOwnerSEO\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = SOS_PATH . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once SOS_PATH . 'includes/Requirements.php';

/**
 * Returns the list of unmet requirements (empty when all are met).
 *
 * @return array
 */
function sos_requirement_problems() {
	return \SiteOwnerSEO\Requirements::check( PHP_VERSION, $GLOBALS['wp_version'] );
}

/**
 * Shows a plain-language admin notice when the site does not meet the requirements.
 *
 * @return void
 */
function sos_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$problems = sos_requirement_problems();
	if ( isset( $problems['php'] ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: PHP version the site runs now */
					__( 'Site Owner SEO needs PHP %1$s or newer, but your site runs PHP %2$s. Ask your hosting company to switch your PHP version.', 'site-owner-seo' ),
					$problems['php']['required'],
					$problems['php']['current']
				)
			)
		);
	}
	if ( isset( $problems['wp'] ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WordPress version, 2: WordPress version the site runs now */
					__( 'Site Owner SEO needs WordPress %1$s or newer, but your site runs WordPress %2$s. Please update WordPress.', 'site-owner-seo' ),
					$problems['wp']['required'],
					$problems['wp']['current']
				)
			)
		);
	}
}

/**
 * Activation: refuse to activate on unsupported sites, otherwise store default settings.
 *
 * @return void
 */
function sos_activate() {
	if ( sos_requirement_problems() ) {
		deactivate_plugins( plugin_basename( SOS_FILE ) );
		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: required WordPress version */
					__( 'Site Owner SEO needs PHP %1$s or newer and WordPress %2$s or newer.', 'site-owner-seo' ),
					\SiteOwnerSEO\Requirements::MIN_PHP,
					\SiteOwnerSEO\Requirements::MIN_WP
				)
			),
			'',
			array( 'back_link' => true )
		);
	}
	\SiteOwnerSEO\Installer::install();
}

/**
 * Deactivation: stop background work but keep all saved data.
 *
 * @return void
 */
function sos_deactivate() {
	if ( sos_requirement_problems() ) {
		return;
	}
	\SiteOwnerSEO\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'sos_activate' );
register_deactivation_hook( __FILE__, 'sos_deactivate' );

if ( sos_requirement_problems() ) {
	add_action( 'admin_notices', 'sos_requirements_notice' );
	return;
}

add_action( 'plugins_loaded', array( '\SiteOwnerSEO\Plugin', 'boot' ) );
