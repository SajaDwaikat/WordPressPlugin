<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The WordPress-facing side of the audit: gathers a post's data, runs the pure engine,
 * and stores the latest result in post meta (one small JSON blob per post, not one huge option).
 */
final class SEO_Pilot_Audit_Runner {
    const META_AUDIT     = '_seo_pilot_audit';
    const META_PRIMARY   = '_seo_pilot_primary_keyword';
    const META_SECONDARY = '_seo_pilot_secondary_keywords';
    const META_INTENT    = '_seo_pilot_intent';
    const META_AUDIENCE  = '_seo_pilot_audience';
    const SUMMARY_OPTION = 'seo_pilot_last_audit';

    private static $index = null;

    /* ------------------------------------------------------- page settings */

    public static function split_keywords( $raw ) {
        $parts = preg_split( '/[\r\n,،;]+/u', (string) $raw );
        $out   = array();
        foreach ( (array) $parts as $p ) {
            $p = trim( sanitize_text_field( $p ) );
            if ( '' !== $p && ! in_array( $p, $out, true ) ) { $out[] = $p; }
        }
        return array_slice( $out, 0, 10 );
    }

    /** Keywords, intent and audience the editor has set for a page. */
    public static function page_settings( $post_id ) {
        $primary = (string) get_post_meta( $post_id, self::META_PRIMARY, true );
        $source  = '' !== $primary ? 'seo_pilot' : '';
        if ( '' === $primary ) {
            $det = SEO_Pilot_Compat::detected_focus_keyword( $post_id );
            if ( '' !== $det['value'] ) { $primary = $det['value']; $source = SEO_Pilot_Compat::label( $det['owner'] ); }
        }
        return array(
            'primary'        => $primary,
            'primary_source' => $source,
            'secondary'      => self::split_keywords( get_post_meta( $post_id, self::META_SECONDARY, true ) ),
            'intent'         => (string) get_post_meta( $post_id, self::META_INTENT, true ),
            'audience'       => (string) get_post_meta( $post_id, self::META_AUDIENCE, true ),
        );
    }

    public static function save_page_settings( $post_id, array $in ) {
        $primary   = isset( $in['primary'] ) ? sanitize_text_field( $in['primary'] ) : '';
        $secondary = isset( $in['secondary'] ) ? implode( "\n", self::split_keywords( $in['secondary'] ) ) : '';
        $intent    = isset( $in['intent'] ) && in_array( $in['intent'], SEO_Pilot_Intent::labels(), true ) ? $in['intent'] : '';
        $audience  = isset( $in['audience'] ) ? sanitize_text_field( $in['audience'] ) : '';
        foreach ( array( self::META_PRIMARY => $primary, self::META_SECONDARY => $secondary, self::META_INTENT => $intent, self::META_AUDIENCE => $audience ) as $k => $v ) {
            if ( '' === $v ) { delete_post_meta( $post_id, $k ); } else { SEO_Pilot_Util::save_meta( $post_id, $k, $v ); }
        }
    }

    /* ------------------------------------------------------- site-wide index */

    /** Exact-match index of SEO titles, meta descriptions and primary keywords across published pages. */
    public static function index( $refresh = false ) {
        if ( null !== self::$index && ! $refresh ) { return self::$index; }
        $ids = get_posts( array(
            'post_type' => SEO_Pilot_Util::content_post_types(), 'post_status' => 'publish', 'posts_per_page' => 1000,
            'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true,
        ) );
        if ( $ids ) { update_meta_cache( 'post', $ids ); }
        $idx = array( 'title' => array(), 'meta' => array(), 'kw' => array(), 'sketch' => array(), 'title_pool' => array() );
        foreach ( $ids as $id ) {
            $label = wp_strip_all_tags( get_the_title( $id ) );
            $t     = SEO_Pilot_Compat::get( $id, 'seo_title' );
            $tv    = '' !== $t['value'] ? $t['value'] : $label;
            if ( '' !== $tv && ! $t['template_vars'] ) {
                $idx['title'][ SEO_Pilot_Text::normalize( $tv ) ][ $id ] = $label;
                $idx['title_pool'][ $id ] = array( 'label' => $label, 'key' => SEO_Pilot_Text::normalize( $tv ), 'tokens' => SEO_Pilot_Title::tokens( $tv ) );
            }
            $m = SEO_Pilot_Compat::get( $id, 'meta_description' );
            if ( '' !== $m['value'] && ! $m['template_vars'] ) { $idx['meta'][ SEO_Pilot_Text::normalize( $m['value'] ) ][ $id ] = $label; }
            $idx['sketch'][ $id ] = array( 'title' => $label, 'sketch' => self::cached_sketch( $id ) );
            $kw = trim( (string) get_post_meta( $id, self::META_PRIMARY, true ) );
            if ( '' !== $kw ) { $idx['kw'][ SEO_Pilot_Text::normalize( $kw ) ][ $id ] = $label; }
        }
        self::$index = $idx;
        return $idx;
    }

    /** Sketch of a post's text, cached in post meta until its content changes. */
    private static function cached_sketch( $post_id ) {
        $plain = SEO_Pilot_Text::plain( (string) get_post_field( 'post_content', $post_id ) );
        $h     = sha1( $plain );
        $c     = SEO_Pilot_Util::get_json_meta( $post_id, '_seo_pilot_sketch' );
        if ( $c && isset( $c['h'], $c['k'] ) && $c['h'] === $h ) { return $c['k']; }
        $k = SEO_Pilot_Similarity::sketch( $plain );
        SEO_Pilot_Util::save_json_meta( $post_id, '_seo_pilot_sketch', array( 'h' => $h, 'k' => $k ) );
        return $k;
    }

    private static function others( array $map, $key, $self_id, array $exclude = array() ) {
        $out = array();
        if ( isset( $map[ $key ] ) ) {
            foreach ( $map[ $key ] as $id => $label ) {
                if ( (int) $id !== (int) $self_id && ! in_array( (int) $id, $exclude, true ) ) { $out[] = array( 'id' => (int) $id, 'title' => $label, 'url' => '' ); }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------- link resolving */

    /** Returns a callable: href => 'ok' | 'unpublished' | 'unknown'. Local lookups only — no HTTP. */
    public static function link_resolver() {
        $cache = array();
        return function ( $href ) use ( &$cache ) {
            if ( isset( $cache[ $href ] ) ) { return $cache[ $href ]; }
            $abs = preg_match( '#^https?://#i', $href ) ? $href : home_url( '/' . ltrim( $href, '/' ) );
            if ( 0 === strpos( $href, '//' ) ) { $abs = 'https:' . $href; }
            $parts = wp_parse_url( $abs );
            $q     = array();
            if ( ! empty( $parts['query'] ) ) { wp_parse_str( $parts['query'], $q ); }
            $id = 0;
            if ( ! empty( $q['p'] ) ) { $id = (int) $q['p']; } elseif ( ! empty( $q['page_id'] ) ) { $id = (int) $q['page_id']; }
            if ( ! $id ) { $id = (int) url_to_postid( $abs ); }
            if ( $id ) {
                $st  = get_post_status( $id );
                $res = ( 'publish' === $st || 'inherit' === $st ) ? 'ok' : ( $st ? 'unpublished' : 'unknown' );
            } else {
                $path = trim( (string) ( isset( $parts['path'] ) ? $parts['path'] : '' ), '/' );
                $home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
                $res  = ( $path === $home || '' === $path ) ? 'ok' : 'unknown';
            }
            return $cache[ $href ] = $res;
        };
    }

    /* ---------------------------------------------------------------- audit */

    public static function build_page( WP_Post $post ) {
        $id   = (int) $post->ID;
        $idx  = self::index();
        $st   = SEO_Pilot_Compat::get( $id, 'seo_title' );
        $md   = SEO_Pilot_Compat::get( $id, 'meta_description' );
        $set  = self::page_settings( $id );
        $title = wp_strip_all_tags( get_the_title( $post ) );

        $tkey = SEO_Pilot_Text::normalize( '' !== $st['value'] ? $st['value'] : $title );
        $mkey = SEO_Pilot_Text::normalize( $md['value'] );
        $kkey = SEO_Pilot_Text::normalize( $set['primary'] );
        $kw_own = trim( (string) get_post_meta( $id, self::META_PRIMARY, true ) );

        // Translations of this page are not duplicates, cannibals or near-duplicates of it: they are checked on their own terms in the language checks.
        $li      = SEO_Pilot_Lang::for_post( $id );
        $exclude = SEO_Pilot_Lang::translation_ids( $li );
        foreach ( $li['members'] as $mc => $m ) { $li['members'][ $mc ]['sketch'] = self::cached_sketch( $m['id'] ); }

        return array(
            'id' => $id, 'title' => $title, 'url' => (string) get_permalink( $post ), 'slug' => $post->post_name,
            'content' => (string) $post->post_content, 'post_type' => $post->post_type, 'post_status' => $post->post_status, 'modified' => $post->post_modified,
            'seo_title' => array( 'value' => $st['value'], 'owner_label' => $st['owner_label'], 'template_vars' => $st['template_vars'] ),
            'meta_description' => array( 'value' => $md['value'], 'owner_label' => $md['owner_label'], 'template_vars' => $md['template_vars'] ),
            'orphan_meta' => SEO_Pilot_Compat::orphan_value( $id, 'meta_description' ),
            'keywords' => array( 'primary' => $set['primary'], 'secondary' => $set['secondary'], 'primary_source' => $set['primary_source'] ),
            'intent_user' => $set['intent'], 'audience' => $set['audience'],
            'noindex' => SEO_Pilot_Compat::robots_noindex( $id ), 'site_noindex' => SEO_Pilot_Compat::site_discourages_search(),
            'cannibal' => ( '' !== $kkey && '' !== $kw_own ) ? self::others( $idx['kw'], $kkey, $id, $exclude ) : array(),
            'duplicates' => array(
                'title' => ( ! $st['template_vars'] && '' !== $tkey ) ? self::others( $idx['title'], $tkey, $id, $exclude ) : array(),
                'meta'  => ( ! $md['template_vars'] && '' !== $mkey ) ? self::others( $idx['meta'], $mkey, $id, $exclude ) : array(),
            ),
            'similar' => SEO_Pilot_Similarity::find_similar( SEO_Pilot_Similarity::sketch( SEO_Pilot_Text::plain( (string) $post->post_content ) ), array_diff_key( $idx['sketch'], array_flip( $exclude ) ), $id, 0.5, 5 ),
            'lang_info' => $li,
            'search_data' => SEO_Pilot_Searchdata::for_audit( $id, SEO_Pilot_Text::plain( (string) $post->post_content ) ),
            'site_host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
            'link_resolver' => self::link_resolver(),
            'year' => (int) wp_date( 'Y' ),
            'site_name' => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
            'title_pool' => $idx['title_pool'],
            'image_data' => self::image_data( $post ),
        );
    }

    /**
     * What WordPress knows about the images in a post: Media Library alt text and file size for
     * images that carry a wp-image-N class (at most 40), plus the featured image. Read only.
     */
    public static function image_data( WP_Post $post ) {
        $ids = array();
        if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $m ) ) {
            $ids = array_slice( array_unique( array_map( 'intval', $m[1] ) ), 0, 40 );
        }
        $featured_id = (int) get_post_thumbnail_id( $post );
        $all = $ids;
        if ( $featured_id ) { $all[] = $featured_id; }
        if ( $all ) { update_meta_cache( 'post', $all ); }

        $read = function ( $aid ) {
            if ( 'attachment' !== get_post_type( $aid ) ) { return null; }
            $meta = wp_get_attachment_metadata( $aid );
            return array(
                'library_alt' => trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) ),
                'filesize'    => ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) ? (int) $meta['filesize'] : 0,
            );
        };
        $by_id = array();
        foreach ( $ids as $aid ) {
            $row = $read( $aid );
            if ( $row ) { $by_id[ $aid ] = $row; }
        }
        $featured = null;
        if ( $featured_id ) {
            $row = $read( $featured_id );
            if ( $row ) { $featured = array( 'id' => $featured_id, 'alt' => $row['library_alt'], 'filesize' => $row['filesize'] ); }
        }
        return array( 'by_id' => $by_id, 'featured' => $featured );
    }

    /** Audit one post and store the result. */
    public static function audit_post( $post, $save = true ) {
        $post = get_post( $post );
        if ( ! $post ) { return null; }
        $result = SEO_Pilot_Audit::analyze( self::build_page( $post ) );
        $result['post_status']  = $post->post_status;
        $result['post_type']    = $post->post_type;
        $result['content_hash'] = sha1( (string) $post->post_content );
        if ( $save ) { SEO_Pilot_Util::save_json_meta( $post->ID, self::META_AUDIT, $result ); }
        return $result;
    }

    public static function get_saved( $post_id ) {
        return SEO_Pilot_Util::get_json_meta( $post_id, self::META_AUDIT );
    }

    /** Every stored audit (for the site report). @return array[] */
    public static function all_saved( $limit = 2000 ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d", self::META_AUDIT, (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $out = array();
        foreach ( $ids as $id ) {
            $p = get_post( (int) $id );
            if ( ! $p || 'trash' === $p->post_status ) { continue; }
            $a = self::get_saved( (int) $id );
            if ( $a && isset( $a['findings'], $a['checklist'], $a['summary'] ) ) { $out[] = $a; }
        }
        return $out;
    }

    /** Audit up to $limit published posts/pages, and store the lightweight overview used by the dashboard. */
    public static function audit_all( $limit = 300 ) {
        $limit = (int) apply_filters( 'seo_pilot_audit_limit', $limit );
        $ids   = get_posts( array(
            'post_type' => SEO_Pilot_Util::content_post_types(), 'post_status' => 'publish', 'posts_per_page' => $limit,
            'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true,
        ) );
        if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
        if ( $ids ) { update_meta_cache( 'post', $ids ); }
        self::index( true );
        $items = array();
        foreach ( $ids as $id ) {
            $res = self::audit_post( $id );
            if ( ! $res ) { continue; }
            $items[ $id ] = array_merge( array( 'issue_count' => $res['summary']['total_findings'] ), $res['summary']['severity'] );
        }
        update_option( self::SUMMARY_OPTION, array( 'ran_at' => SEO_Pilot_Util::now(), 'count' => count( $items ), 'items' => $items ), false );
        return count( $items );
    }
}
