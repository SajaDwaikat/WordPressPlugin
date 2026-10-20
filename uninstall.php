<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is kept unless the administrator opted in to deletion under SEO Pilot → Settings.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$settings = get_option( 'seo_pilot_settings', array() );

// Always safe to remove: short-lived caches.
delete_transient( 'seo_pilot_rl' );
$seo_pilot_like_t = $wpdb->esc_like( '_transient_seo_pilot_' ) . '%';
$seo_pilot_like_o = $wpdb->esc_like( '_transient_timeout_seo_pilot_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $seo_pilot_like_t, $seo_pilot_like_o ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] ) ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seo_pilot_recs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seo_pilot_searchdata" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seo_pilot_history" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
    // Includes the SEO titles / meta descriptions SEO Pilot itself stored (_seo_pilot_seo_title, _seo_pilot_meta_description).
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_seo_pilot_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( array( 'seo_pilot_settings', 'seo_pilot_secret', 'seo_pilot_db_version', 'seo_pilot_migrated_020', 'seo_pilot_last_audit', 'seo_pilot_imports' ) as $option ) {
        delete_option( $option );
    }
}
