<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin bootstrap. Keeps the 0.1.0 public surface (class name, SEO_Pilot::instance(),
 * the seo_pilot_settings option, the admin-post action names) and wires in the modules.
 */
final class SEO_Pilot {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_init', array( 'SEO_Pilot_Installer', 'maybe_upgrade' ), 5 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Front-end output. Both are skipped whenever another SEO plugin is active.
        add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
        add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );

        SEO_Pilot_Admin::init();
        SEO_Pilot_Workspace::init();
        SEO_Pilot_Admin_Data::init();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'seo-pilot', false, dirname( plugin_basename( SEO_PILOT_FILE ) ) . '/languages' );
    }

    public function register_settings() {
        register_setting( 'seo_pilot_settings_group', SEO_Pilot_Settings::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( 'SEO_Pilot_Settings', 'sanitize' ),
            'default'           => SEO_Pilot_Settings::defaults(),
        ) );
    }

    /** True when SEO Pilot itself is allowed to print SEO tags (no third-party SEO plugin owns them). */
    private function owns_output() {
        return ! SEO_Pilot_Compat::active_plugins();
    }

    public function output_meta_description() {
        if ( is_admin() || ! is_singular() || ! $this->owns_output() ) { return; }
        $desc = get_post_meta( get_queried_object_id(), '_seo_pilot_meta_description', true );
        if ( is_string( $desc ) && '' !== trim( $desc ) ) {
            echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
    }

    /** Uses the SEO title saved by SEO Pilot, only when no other SEO plugin is active. */
    public function filter_document_title( $title ) {
        if ( is_admin() || ! is_singular() || ! $this->owns_output() ) { return $title; }
        $seo = get_post_meta( get_queried_object_id(), '_seo_pilot_seo_title', true );
        return ( is_string( $seo ) && '' !== trim( $seo ) ) ? trim( $seo ) : $title;
    }
}
