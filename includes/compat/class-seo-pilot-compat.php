<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Knows which SEO plugin owns the SEO title and meta description of a post, reads those
 * fields read-only by default, and writes only to fields that are documented post meta.
 *
 * Verified/assumed field storage:
 *  - Yoast SEO:  post meta _yoast_wpseo_title / _yoast_wpseo_metadesc / _yoast_wpseo_focuskw
 *  - Rank Math:  post meta rank_math_title / rank_math_description / rank_math_focus_keyword
 *  - AIOSEO 4.x: its own aioseo_posts table (not post meta) => SEO Pilot treats it as READ-ONLY.
 *                Column names are not verified against a live install; reads fail safe to "unknown".
 *  - SEO Pilot:  _seo_pilot_seo_title / _seo_pilot_meta_description
 */
final class SEO_Pilot_Compat {
    const OWN = 'seo_pilot';

    private static $labels = array(
        'yoast'     => 'Yoast SEO',
        'rankmath'  => 'Rank Math',
        'aioseo'    => 'All in One SEO',
        self::OWN   => 'SEO Pilot',
    );

    private static $keys = array(
        'yoast'    => array( 'seo_title' => '_yoast_wpseo_title', 'meta_description' => '_yoast_wpseo_metadesc' ),
        'rankmath' => array( 'seo_title' => 'rank_math_title', 'meta_description' => 'rank_math_description' ),
        'aioseo'   => array(),
        self::OWN  => array( 'seo_title' => '_seo_pilot_seo_title', 'meta_description' => '_seo_pilot_meta_description' ),
    );

    public static function label( $id ) {
        return isset( self::$labels[ $id ] ) ? self::$labels[ $id ] : (string) $id;
    }

    /** @return string[] ids of detected third-party SEO plugins, in priority order. */
    public static function active_plugins() {
        $active = array();
        if ( defined( 'WPSEO_VERSION' ) ) { $active[] = 'yoast'; }
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) { $active[] = 'rankmath'; }
        if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) { $active[] = 'aioseo'; }
        return apply_filters( 'seo_pilot_detected_seo_plugins', $active );
    }

    public static function owner() {
        $active = self::active_plugins();
        return $active ? $active[0] : self::OWN;
    }

    public static function has_conflict() {
        return count( self::active_plugins() ) > 1;
    }

    public static function field_key( $owner, $field ) {
        return isset( self::$keys[ $owner ][ $field ] ) ? self::$keys[ $owner ][ $field ] : null;
    }

    public static function can_write( $owner, $field ) {
        return null !== self::field_key( $owner, $field );
    }

    public static function is_third_party( $owner ) {
        return self::OWN !== $owner;
    }

    /** SEO plugins store variables like %%title%% or %title%; those values cannot be measured literally. */
    public static function has_template_vars( $value ) {
        return 1 === preg_match( '/%%[^%]+%%|%[a-z_]{3,}%|#(?:post|site|page|author|separator|current|tagline|categories|taxonomy)[a-z_]*/i', (string) $value );
    }

    /**
     * Read one SEO field.
     *
     * @return array value, owner, owner_label, writable, template_vars, source
     */
    public static function get( $post_id, $field, $owner = null ) {
        $owner = $owner ? $owner : self::owner();
        $out   = array(
            'value' => '', 'owner' => $owner, 'owner_label' => self::label( $owner ),
            'writable' => self::can_write( $owner, $field ), 'template_vars' => false, 'source' => 'meta',
        );
        $key = self::field_key( $owner, $field );
        if ( $key ) {
            $v = get_post_meta( $post_id, $key, true );
            $out['value'] = is_string( $v ) ? trim( $v ) : '';
        } elseif ( 'aioseo' === $owner ) {
            $out['source'] = 'table';
            $out['value']  = self::aioseo_read( $post_id, $field );
        }
        $out['template_vars'] = self::has_template_vars( $out['value'] );
        return $out;
    }

    private static function aioseo_read( $post_id, $field ) {
        global $wpdb;
        $col   = 'seo_title' === $field ? 'title' : 'description';
        $table = $wpdb->prefix . 'aioseo_posts';
        $wpdb->suppress_errors( true );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $value  = '';
        if ( $exists === $table ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT {$col} AS v FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( is_array( $row ) && isset( $row['v'] ) ) { $value = trim( (string) $row['v'] ); }
        }
        $wpdb->suppress_errors( false );
        if ( '' === $value ) {
            $legacy = get_post_meta( $post_id, 'seo_title' === $field ? '_aioseop_title' : '_aioseop_description', true );
            $value  = is_string( $legacy ) ? trim( $legacy ) : '';
        }
        return $value;
    }

    /**
     * Write one SEO field into its owner's documented post meta. Callers must already hold
     * explicit user approval; this method never decides that for them.
     *
     * @return true|WP_Error
     */
    public static function set( $post_id, $field, $value, $owner = null ) {
        $owner = $owner ? $owner : self::owner();
        $key   = self::field_key( $owner, $field );
        if ( ! $key ) {
            return new WP_Error( 'seo_pilot_owner_readonly', sprintf( '%s manages this field and SEO Pilot cannot write to it. Copy the approved text into %s manually.', self::label( $owner ), self::label( $owner ) ) );
        }
        $value = sanitize_text_field( (string) $value );
        if ( '' === $value ) {
            delete_post_meta( $post_id, $key );
        } else {
            SEO_Pilot_Util::save_meta( $post_id, $key, $value );
        }
        $now = get_post_meta( $post_id, $key, true );
        if ( (string) $now !== $value ) {
            return new WP_Error( 'seo_pilot_write_failed', 'The SEO field could not be saved.' );
        }
        return true;
    }

    /** Write directly to a known meta key (used by rollback, which restores the exact key that was changed). */
    public static function write_key( $post_id, $key, $value ) {
        if ( '' === (string) $value ) {
            delete_post_meta( $post_id, $key );
        } else {
            SEO_Pilot_Util::save_meta( $post_id, $key, (string) $value );
        }
    }

    /** SEO Pilot description stored but not output because a third-party plugin owns the field. */
    public static function orphan_value( $post_id, $field ) {
        $owner = self::owner();
        if ( ! self::is_third_party( $owner ) ) { return ''; }
        $own = self::get( $post_id, $field, self::OWN );
        $cur = self::get( $post_id, $field, $owner );
        return ( '' !== $own['value'] && '' === $cur['value'] ) ? $own['value'] : '';
    }

    /** Focus keyword set in another plugin (display only; never copied silently). */
    public static function detected_focus_keyword( $post_id ) {
        $y = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
        if ( is_string( $y ) && '' !== trim( $y ) ) { return array( 'value' => trim( $y ), 'owner' => 'yoast' ); }
        $r = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( is_string( $r ) && '' !== trim( $r ) ) {
            $parts = explode( ',', $r );
            return array( 'value' => trim( $parts[0] ), 'owner' => 'rankmath' );
        }
        return array( 'value' => '', 'owner' => '' );
    }

    /** Read-only robots status. SEO Pilot never changes robots directives. */
    public static function robots_noindex( $post_id ) {
        if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
            return array( 'noindex' => true, 'source' => 'Yoast SEO' );
        }
        $rm = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
            return array( 'noindex' => true, 'source' => 'Rank Math' );
        }
        if ( '1' === (string) get_post_meta( $post_id, '_seo_pilot_noindex', true ) ) {
            return array( 'noindex' => true, 'source' => 'SEO Pilot flag (legacy)' );
        }
        return array( 'noindex' => false, 'source' => '' );
    }

    public static function site_discourages_search() {
        return '0' === (string) get_option( 'blog_public', '1' );
    }
}
