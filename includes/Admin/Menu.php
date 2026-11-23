<?php
/**
 * Admin menu.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "SEO Insights" menu with its three pages (DB-01, MF-01).
 */
final class Menu {

	public const DASHBOARD_SLUG = 'site-owner-seo';
	public const FORM_SLUG      = 'site-owner-seo-form';
	public const SETTINGS_SLUG  = 'site-owner-seo-settings';

	/**
	 * Hook suffixes of this plugin's pages, used to load assets on those pages only.
	 *
	 * @var string[]
	 */
	private array $screens = array();

	/**
	 * Hooks the menu into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Who may open the SEO Management form (decision D5).
	 * Administrators always can; editors only if the setting allows it.
	 * Per-page permission checks are added with the form in milestone M2.
	 *
	 * @return string A WordPress capability.
	 */
	public static function form_capability(): string {
		return Settings::get( 'editors_can_use_form' ) ? 'edit_pages' : 'manage_options';
	}

	/**
	 * Adds the top-level menu and its pages.
	 *
	 * @return void
	 */
	public function add_menus(): void {
		$dashboard = new DashboardPage();
		$form      = new FormPage();
		$settings  = new SettingsPage();

		$this->screens[] = (string) add_menu_page(
			__( 'SEO Insights', 'site-owner-seo' ),
			__( 'SEO Insights', 'site-owner-seo' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $dashboard, 'render' ),
			'dashicons-chart-area',
			58
		);

		// Re-register the first entry so the submenu is labelled "Dashboard".
		$this->screens[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'SEO Insights', 'site-owner-seo' ),
			__( 'Dashboard', 'site-owner-seo' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $dashboard, 'render' )
		);

		$this->screens[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'SEO Management', 'site-owner-seo' ),
			__( 'SEO Management', 'site-owner-seo' ),
			self::form_capability(),
			self::FORM_SLUG,
			array( $form, 'render' )
		);

		$this->screens[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Settings', 'site-owner-seo' ),
			__( 'Settings', 'site-owner-seo' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $settings, 'render' )
		);
	}

	/**
	 * Loads the plugin's stylesheet on its own pages only (NF-04).
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'sos-admin', SOS_URL . 'assets/css/admin.css', array(), SOS_VERSION );
	}
}
