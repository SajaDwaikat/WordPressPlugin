<?php
/**
 * Deactivation routine.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Stops background work on deactivation. Saved SEO data is always kept (NF-11).
 */
final class Deactivator {

	/**
	 * Runs on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'sos_daily_scan' );
	}
}
