<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * SEO data is removed only if the owner ticked "Delete all SEO data when this
 * plugin is uninstalled" in the settings (decision D6, NF-11).
 *
 * @package SiteOwnerSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes this plugin's data from the current site, if the owner opted in.
 *
 * @return void
 */
function sos_uninstall_current_site() {
	global $wpdb;

	$settings = get_option( 'sos_settings' );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'sos_settings' );
	delete_option( 'sos_db_version' );
	delete_option( 'sos_home_seo' );
	delete_option( 'sos_scan_state' );
	delete_transient( 'sos_psi_mobile' );
	delete_transient( 'sos_psi_desktop' );
	wp_clear_scheduled_hook( 'sos_daily_scan' );

	// Saved SEO values per page (_sos_title, _sos_description, ...).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_sos_' ) . '%'
		)
	);

	// Scan results table (created in a later milestone).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sos_scan_results" );
}

if ( is_multisite() ) {
	// Each site decides for itself through its own setting.
	$sos_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sos_site_ids as $sos_site_id ) {
		switch_to_blog( (int) $sos_site_id );
		sos_uninstall_current_site();
		restore_current_blog();
	}
} else {
	sos_uninstall_current_site();
}
