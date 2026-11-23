<?php
/**
 * Plugin bootstrap.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's parts to WordPress hooks.
 */
final class Plugin {

	/**
	 * Starts the plugin once, on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'init', array( I18n::class, 'load_textdomain' ) );

		// Sites activated on a network, or updated in place, get their defaults here.
		Installer::maybe_upgrade();

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
			( new Admin\SettingsPage() )->register();
			( new Admin\FormPage() )->register();
			add_filter( 'plugin_action_links_' . plugin_basename( SOS_FILE ), array( self::class, 'action_links' ) );
		} else {
			( new Frontend\HeadOutput() )->register();
		}
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . Admin\Menu::SETTINGS_SLUG );
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Settings', 'site-owner-seo' )
			)
		);
		return $links;
	}
}
