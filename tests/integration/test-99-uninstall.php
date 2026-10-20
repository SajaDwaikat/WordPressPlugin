<?php
t_group( 'Uninstall' );
global $wpdb;
$uid = make_post( array( 'post_title' => 'Uninstall probe' ) );
SEO_Pilot_Searchdata::import( "Page,Query,Clicks,Impressions,Position\n" . get_permalink( $uid ) . ",probe,1,1,1\n", array( 'label' => 'probe' ) );
update_post_meta( $uid, '_seo_pilot_meta_description', 'x' ); update_post_meta( $uid, '_seo_pilot_audit', '{}' ); update_post_meta( $uid, '_yoast_wpseo_metadesc', 'keep me' );
set_transient( 'seo_pilot_live_1', array( 1 ), 600 );
$s = SEO_Pilot_Settings::all(); $s['delete_on_uninstall'] = 0; update_option( SEO_Pilot_Settings::OPTION, $s );
define( 'WP_UNINSTALL_PLUGIN', 'seo-pilot/seo-pilot.php' );
include SEO_PILOT_PATH . 'uninstall.php';
wp_cache_flush(); // uninstall uses raw SQL; a real uninstall runs in a fresh request with a cold cache
t_ok( SEO_Pilot_Installer::tables_exist(), 'default: history and recommendations survive uninstall' );
t_eq( 'x', get_post_meta( $uid, '_seo_pilot_meta_description', true ), 'default: stored SEO fields survive uninstall' );
t_ok( false === get_transient( 'seo_pilot_live_1' ), 'caches are always removed' );
$s['delete_on_uninstall'] = 1; update_option( SEO_Pilot_Settings::OPTION, $s );
include SEO_PILOT_PATH . 'uninstall.php';
wp_cache_flush();
t_ok( ! SEO_Pilot_Installer::tables_exist(), 'opt-in: tables dropped' );
t_ok( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SEO_Pilot_Searchdata::table() ) ) !== SEO_Pilot_Searchdata::table() && false === get_option( SEO_Pilot_Searchdata::OPTION ), 'opt-in: imported search data table and import list removed' );
t_eq( '', (string) get_post_meta( $uid, '_seo_pilot_meta_description', true ), 'opt-in: SEO Pilot post meta removed' );
t_eq( 'keep me', get_post_meta( $uid, '_yoast_wpseo_metadesc', true ), 'other plugins\' data is never touched' );
t_ok( false === get_option( SEO_Pilot_Settings::OPTION ) && false === get_option( SEO_Pilot_Settings::SECRET_OPTION ), 'opt-in: settings and the stored key removed' );
