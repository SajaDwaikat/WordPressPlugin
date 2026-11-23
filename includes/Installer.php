<?php
/**
 * Install and upgrade routine.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up default data on activation and when the stored data version is behind.
 */
final class Installer {

	public const DB_VERSION        = 1;
	public const DB_VERSION_OPTION = 'sos_db_version';

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		Settings::install_defaults();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Installs defaults on sites that have not been set up yet (for example
	 * after a network activation) and is the place for future data migrations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}
}
