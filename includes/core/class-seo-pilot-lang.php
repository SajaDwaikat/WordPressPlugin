<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The language model: which language a post is in and which other posts are its translations.
 *
 * Where that knowledge comes from, in order:
 *   1. Polylang (read only, through its documented functions)
 *   2. WPML (read only, through its documented filters)
 *   3. the `seo_pilot_post_language` filter, for any other setup
 *   4. SEO Pilot's own pairing (post meta), for sites that link translations by hand
 *   5. the site language, and finally a script guess
 *
 * Plugins that translate on the fly (TranslatePress, Weglot) do not create separate posts, so there
 * is nothing to read here; they are detected so that nothing is output twice, and their pages are
 * audited through the Live check instead.
 */
final class SEO_Pilot_Lang {
    const META_LANG  = '_seo_pilot_lang';
    const META_GROUP = '_seo_pilot_trans_group';

    /* ------------------------------------------------------------ plugins */

    /** @return string[] ids of detected multilingual plugins. */
    public static function plugins() {
        $found = array();
        if ( function_exists( 'pll_get_post_language' ) || defined( 'POLYLANG_VERSION' ) ) { $found[] = 'polylang'; }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) { $found[] = 'wpml'; }
        if ( defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press' ) ) { $found[] = 'translatepress'; }
        if ( defined( 'WEGLOT_VERSION' ) || function_exists( 'weglot_get_current_language' ) ) { $found[] = 'weglot'; }
        $found = apply_filters( 'seo_pilot_detected_multilingual_plugins', $found );
        return is_array( $found ) ? array_values( $found ) : array();
    }

    public static function plugin_label( $id ) {
        $m = array( 'polylang' => 'Polylang', 'wpml' => 'WPML', 'translatepress' => 'TranslatePress', 'weglot' => 'Weglot' );
        return isset( $m[ $id ] ) ? $m[ $id ] : (string) $id;
    }

    /** True when another plugin is expected to print hreflang itself. */
    public static function owns_hreflang() {
        return (bool) self::plugins();
    }

    /* ------------------------------------------------------------ settings */

    /** @return array[] code, label, note (as saved in Settings → Languages). */
    public static function site_languages() {
        $rows = SEO_Pilot_Settings::get( 'languages', array() );
        return is_array( $rows ) ? $rows : array();
    }

    public static function default_language() {
        return SEO_Pilot_Hreflang::normalize_code( (string) SEO_Pilot_Settings::get( 'default_lang', '' ) );
    }

    /** Display name: the label set in Settings, else a built-in name, else the code. */
    public static function label( $code ) {
        $n = SEO_Pilot_Hreflang::normalize_code( $code );
        foreach ( self::site_languages() as $row ) {
            if ( $row['code'] === $n && '' !== $row['label'] ) { return $row['label']; }
        }
        $name = SEO_Pilot_Hreflang::name( $n );
        return '' !== $name ? $name : $n;
    }

    /** Regional wording preference the editor wrote for a language (trusted text for the AI), or ''. */
    public static function note( $code ) {
        $n = SEO_Pilot_Hreflang::normalize_code( $code );
        foreach ( self::site_languages() as $row ) {
            if ( $row['code'] === $n ) { return (string) $row['note']; }
        }
        return '';
    }

    /* ------------------------------------------------------------ resolver */

    private static function base( $id ) {
        return array(
            'id' => (int) $id, 'code' => '', 'label' => '', 'dir' => 'ltr', 'source' => '', 'plugins' => self::plugins(), 'managed_by' => '',
            'group' => '', 'declared' => false, 'members' => array(), 'site_languages' => array_map( function ( $r ) { return $r['code']; }, self::site_languages() ),
        );
    }

    /** One translation as shown in the panel and used by the audit (self is not included). */
    private static function member_info( $id, $code ) {
        $p = get_post( $id );
        if ( ! $p ) { return null; }
        $st = SEO_Pilot_Compat::get( $id, 'seo_title' );
        $md = SEO_Pilot_Compat::get( $id, 'meta_description' );
        $kw = trim( (string) get_post_meta( $id, SEO_Pilot_Audit_Runner::META_PRIMARY, true ) );
        return array(
            'id' => (int) $id, 'code' => $code, 'url' => (string) get_permalink( $p ), 'status' => $p->post_status, 'title' => wp_strip_all_tags( get_the_title( $p ) ),
            'seo_title' => $st['template_vars'] ? '' : $st['value'], 'meta' => $md['template_vars'] ? '' : $md['value'], 'keyword' => $kw,
            'noindex' => (bool) SEO_Pilot_Compat::robots_noindex( $id )['noindex'],
        );
    }

    private static function finish( array $out, array $ids_by_code ) {
        foreach ( $ids_by_code as $code => $mid ) {
            $code = SEO_Pilot_Hreflang::normalize_code( $code );
            if ( '' === $code || (int) $mid === $out['id'] || $code === $out['code'] ) { continue; }
            $mi = self::member_info( (int) $mid, $code );
            if ( $mi ) { $out['members'][ $code ] = $mi; }
        }
        $out['label']    = self::label( $out['code'] );
        $out['dir']      = SEO_Pilot_Hreflang::is_rtl( $out['code'] ) ? 'rtl' : 'ltr';
        $out['declared'] = in_array( $out['source'], array( 'polylang', 'wpml', 'filter', 'manual' ), true );
        return $out;
    }

    /**
     * Language of a post and its translations.
     *
     * @return array id, code, label, dir (ltr|rtl), source (polylang|wpml|filter|manual|site), plugins[], managed_by, group,
     *               declared (bool: a person or plugin said so, as opposed to a guess), members (code => info), site_languages[]
     */
    public static function for_post( $post_id ) {
        $id  = (int) $post_id;
        $out = self::base( $id );
        $post = get_post( $id );
        if ( ! $post ) { return $out; }

        // 1. Polylang
        if ( function_exists( 'pll_get_post_language' ) ) {
            $loc  = pll_get_post_language( $id, 'locale' );
            $slug = pll_get_post_language( $id, 'slug' );
            if ( is_string( $loc ) && '' !== $loc || is_string( $slug ) && '' !== $slug ) {
                $out['code'] = SEO_Pilot_Hreflang::normalize_code( ( is_string( $loc ) && '' !== $loc ) ? $loc : $slug );
                $out['source'] = 'polylang'; $out['managed_by'] = 'polylang';
                $ids = array();
                if ( function_exists( 'pll_get_post_translations' ) ) {
                    foreach ( (array) pll_get_post_translations( $id ) as $s => $mid ) {
                        $ml = pll_get_post_language( (int) $mid, 'locale' );
                        $ids[ ( is_string( $ml ) && '' !== $ml ) ? $ml : $s ] = (int) $mid;
                    }
                }
                return self::finish( $out, $ids );
            }
        }

        // 2. WPML
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $d = apply_filters( 'wpml_post_language_details', null, $id );
            if ( is_array( $d ) && ! empty( $d['language_code'] ) ) {
                $out['code'] = SEO_Pilot_Hreflang::normalize_code( ! empty( $d['locale'] ) ? $d['locale'] : $d['language_code'] );
                $out['source'] = 'wpml'; $out['managed_by'] = 'wpml';
                $type = 'post_' . $post->post_type;
                $trid = apply_filters( 'wpml_element_trid', null, $id, $type );
                $ids  = array();
                if ( $trid ) {
                    foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $lc => $t ) {
                        if ( is_object( $t ) && ! empty( $t->element_id ) ) { $ids[ $lc ] = (int) $t->element_id; }
                    }
                }
                return self::finish( $out, $ids );
            }
        }

        // 3. Any other setup, through a filter. Expected: array( 'code' => 'ar', 'members' => array( 'en' => 12 ) ).
        $f = apply_filters( 'seo_pilot_post_language', null, $id );
        if ( is_array( $f ) && ! empty( $f['code'] ) && '' !== SEO_Pilot_Hreflang::normalize_code( $f['code'] ) ) {
            $out['code'] = SEO_Pilot_Hreflang::normalize_code( $f['code'] );
            $out['source'] = 'filter';
            return self::finish( $out, isset( $f['members'] ) && is_array( $f['members'] ) ? $f['members'] : array() );
        }

        // 4. SEO Pilot's own pairing
        $mine = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $id, self::META_LANG, true ) );
        if ( '' !== $mine ) {
            $out['code'] = $mine; $out['source'] = 'manual';
            $out['group'] = (string) get_post_meta( $id, self::META_GROUP, true );
            return self::finish( $out, '' !== $out['group'] ? self::group_members( $out['group'] ) : array() );
        }

        // 5. Site language (a guess about this post), script detection happens in the audit.
        $out['code'] = SEO_Pilot_Hreflang::normalize_code( get_locale() );
        $out['source'] = 'site';
        return self::finish( $out, array() );
    }

    /** code => post id for a manual group. */
    public static function group_members( $group ) {
        if ( '' === (string) $group ) { return array(); }
        $ids = get_posts( array(
            'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 30, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_key' => self::META_GROUP, 'meta_value' => (string) $group, 'orderby' => 'ID', 'order' => 'ASC',
        ) );
        $map = array();
        foreach ( $ids as $mid ) {
            $c = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $mid, self::META_LANG, true ) );
            if ( '' !== $c && ! isset( $map[ $c ] ) ) { $map[ $c ] = (int) $mid; }
        }
        return $map;
    }

    /**
     * The translation to localize FROM: the default-language page when it is one of the members,
     * otherwise a published translation, otherwise the first one. Null when the page has no translation.
     */
    public static function localize_source( array $info ) {
        if ( ! $info['members'] ) { return null; }
        $def  = self::default_language();
        $pick = null;
        if ( '' !== $def && isset( $info['members'][ $def ] ) ) { $pick = $info['members'][ $def ]; }
        if ( null === $pick ) { foreach ( $info['members'] as $m ) { if ( 'publish' === $m['status'] ) { $pick = $m; break; } } }
        if ( null === $pick ) { $pick = reset( $info['members'] ); }
        $p = get_post( $pick['id'] );
        if ( ! $p ) { return null; }
        $parsed = SEO_Pilot_Parser::parse( $p->post_content );
        $open   = SEO_Pilot_Text::truncate( SEO_Pilot_Text::plain( (string) $p->post_content ), 500 );
        return array(
            'id' => (int) $pick['id'], 'code' => $pick['code'], 'lang_name' => self::label( $pick['code'] ) . ' (' . $pick['code'] . ')',
            'title' => $pick['title'], 'seo_title' => $pick['seo_title'], 'meta_description' => $pick['meta'], 'primary' => $pick['keyword'],
            'secondary' => SEO_Pilot_Audit_Runner::split_keywords( get_post_meta( $pick['id'], SEO_Pilot_Audit_Runner::META_SECONDARY, true ) ),
            'headings' => $parsed['headings'], 'opening' => $open[0],
        );
    }

    /** Post ids the audit must not treat as duplicates or near-duplicates of this one: its translations. */
    public static function translation_ids( array $info ) {
        $ids = array();
        foreach ( $info['members'] as $m ) { $ids[] = (int) $m['id']; }
        return $ids;
    }

    /**
     * Members that may appear in hreflang: published, indexable, plus the post itself.
     *
     * @return array[] code, url
     */
    public static function indexable_members( array $info ) {
        $out = array();
        $self = get_post( $info['id'] );
        if ( ! $self || 'publish' !== $self->post_status || SEO_Pilot_Compat::robots_noindex( $info['id'] )['noindex'] ) { return array(); }
        $out[] = array( 'code' => $info['code'], 'url' => (string) get_permalink( $self ) );
        foreach ( $info['members'] as $m ) {
            if ( 'publish' !== $m['status'] || $m['noindex'] ) { continue; }
            $out[] = array( 'code' => $m['code'], 'url' => $m['url'] );
        }
        return $out;
    }

    /* ------------------------------------------------------------ manual pairing */

    private static function manual_allowed( $post_id ) {
        $info = self::for_post( $post_id );
        if ( '' !== $info['managed_by'] ) {
            return new WP_Error( 'seo_pilot_lang_managed', 'The language of this page is managed by ' . self::plugin_label( $info['managed_by'] ) . '. Change it there.' );
        }
        return true;
    }

    /** Set the language of a post (manual mode). An empty code removes it. */
    public static function set_language( $post_id, $code ) {
        $ok = self::manual_allowed( $post_id );
        if ( is_wp_error( $ok ) ) { return $ok; }
        $code = trim( (string) $code );
        if ( '' === $code ) { self::unlink( $post_id ); delete_post_meta( $post_id, self::META_LANG ); return true; }
        $n = SEO_Pilot_Hreflang::normalize_code( $code );
        if ( '' === $n || 'x-default' === $n ) { return new WP_Error( 'seo_pilot_lang_code', 'That is not a valid language code. Use a code such as ar, en, fr or ar-SA.' ); }
        // Changing the language of a post that is already in a group must not create a clash.
        $group = (string) get_post_meta( $post_id, self::META_GROUP, true );
        if ( '' !== $group ) {
            $members = self::group_members( $group );
            $current = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $post_id, self::META_LANG, true ) );
            unset( $members[ $current ] );
            if ( isset( $members[ $n ] ) && (int) $members[ $n ] !== (int) $post_id ) { return new WP_Error( 'seo_pilot_lang_clash', 'Another page in this translation group already uses that language. Unlink it first.' ); }
        }
        SEO_Pilot_Util::save_meta( $post_id, self::META_LANG, $n );
        return true;
    }

    /**
     * Link $post_id and $other_id as translations (manual mode). If $other_id has no language yet it gets $lang.
     *
     * @return true|WP_Error
     */
    public static function link( $post_id, $other_id, $lang = '' ) {
        foreach ( array( $post_id, $other_id ) as $pid ) {
            $ok = self::manual_allowed( $pid );
            if ( is_wp_error( $ok ) ) { return $ok; }
        }
        $other_code = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $other_id, self::META_LANG, true ) );
        if ( '' === $other_code && '' !== $lang ) {
            $set = self::set_language( $other_id, $lang );
            if ( is_wp_error( $set ) ) { return $set; }
            $other_code = SEO_Pilot_Hreflang::normalize_code( $lang );
        }
        $mine_code = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $post_id, self::META_LANG, true ) );
        $ga = (string) get_post_meta( $post_id, self::META_GROUP, true );
        $gb = (string) get_post_meta( $other_id, self::META_GROUP, true );
        $plan = SEO_Pilot_Hreflang::plan_link(
            array( 'id' => $post_id, 'code' => $mine_code, 'members' => self::group_members( $ga ) ),
            array( 'id' => $other_id, 'code' => $other_code, 'members' => self::group_members( $gb ) )
        );
        if ( ! $plan['ok'] ) { return new WP_Error( 'seo_pilot_lang_link', $plan['error'] ); }
        $gid = '' !== $ga ? $ga : ( '' !== $gb ? $gb : SEO_Pilot_Util::uuid() );
        foreach ( $plan['members'] as $mid ) { SEO_Pilot_Util::save_meta( $mid, self::META_GROUP, $gid ); }
        return true;
    }

    /** Remove a post from its translation group (the group is dissolved when one page is left). */
    public static function unlink( $post_id ) {
        $group = (string) get_post_meta( $post_id, self::META_GROUP, true );
        if ( '' === $group ) { return true; }
        delete_post_meta( $post_id, self::META_GROUP );
        $rest = self::group_members( $group );
        if ( count( $rest ) < 2 ) { foreach ( $rest as $mid ) { delete_post_meta( $mid, self::META_GROUP ); } }
        return true;
    }
}
