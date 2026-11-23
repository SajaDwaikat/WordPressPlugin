<?php
/**
 * Translation loading.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's translation files (LC-02).
 */
final class I18n {

	public const TEXT_DOMAIN = 'site-owner-seo';

	/**
	 * Loads translations from the plugin's languages folder when WordPress.org translations are not used.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( SOS_FILE ) ) . '/languages'
		);
	}
}
