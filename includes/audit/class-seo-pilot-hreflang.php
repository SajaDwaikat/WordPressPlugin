<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Language codes and hreflang tags. Pure logic: no WordPress state, so it is unit-tested on its own.
 *
 * hreflang rules this code enforces when it builds tags (it never builds a tag it cannot stand behind):
 *  - every page in a translation group lists itself and every other page in the group, so the
 *    annotations are reciprocal by construction;
 *  - one tag per language code; absolute http(s) URLs only;
 *  - at least two languages, otherwise nothing is printed;
 *  - an x-default entry only when a default language was chosen and that page is in the group.
 */
final class SEO_Pilot_Hreflang {

    /** Languages written right to left. */
    const RTL = array( 'ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv' );

    private static function names() {
        return array(
            'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese',
            'nl' => 'Dutch', 'tr' => 'Turkish', 'ru' => 'Russian', 'fa' => 'Persian', 'ur' => 'Urdu', 'he' => 'Hebrew', 'id' => 'Indonesian',
            'hi' => 'Hindi', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'sv' => 'Swedish', 'pl' => 'Polish', 'el' => 'Greek',
            'ms' => 'Malay', 'bn' => 'Bengali', 'uk' => 'Ukrainian', 'ro' => 'Romanian', 'cs' => 'Czech', 'da' => 'Danish', 'fi' => 'Finnish',
            'no' => 'Norwegian', 'hu' => 'Hungarian', 'th' => 'Thai', 'vi' => 'Vietnamese', 'sw' => 'Swahili', 'ku' => 'Kurdish', 'ps' => 'Pashto',
        );
    }

    /**
     * Canonical spelling of a language tag: "ar_sa" -> "ar-SA", "zh-hant-tw" -> "zh-Hant-TW", "x-default" stays.
     * Returns '' when the value is not a plausible language tag.
     */
    public static function normalize_code( $code ) {
        $c = strtolower( trim( str_replace( '_', '-', (string) $code ) ) );
        if ( 'x-default' === $c ) { return 'x-default'; }
        if ( 1 !== preg_match( '/^[a-z]{2,3}(-[a-z]{4})?(-([a-z]{2}|[0-9]{3}))?$/', $c ) ) { return ''; }
        $parts = explode( '-', $c );
        $out   = array( array_shift( $parts ) );
        foreach ( $parts as $p ) {
            if ( 4 === strlen( $p ) ) { $out[] = ucfirst( $p ); }
            else { $out[] = strtoupper( $p ); }
        }
        return implode( '-', $out );
    }

    /** True only for an exact, correctly written hreflang value (underscores such as "ar_SA" are a common mistake). */
    public static function is_valid_hreflang( $value ) {
        $v = trim( (string) $value );
        if ( '' === $v || false !== strpos( $v, '_' ) ) { return false; }
        $n = self::normalize_code( $v );
        return '' !== $n && strtolower( $n ) === strtolower( $v );
    }

    /** First subtag, lower case: "ar-SA" -> "ar". */
    public static function lang_part( $code ) {
        $n = self::normalize_code( $code );
        if ( '' === $n || 'x-default' === $n ) { return ''; }
        $p = explode( '-', $n );
        return $p[0];
    }

    public static function is_rtl( $code ) {
        return in_array( self::lang_part( $code ), self::RTL, true );
    }

    /** English name of a language, or '' when unknown. */
    public static function name( $code ) {
        $l = self::lang_part( $code );
        $m = self::names();
        return isset( $m[ $l ] ) ? $m[ $l ] : '';
    }

    /** 'arabic' | 'latin' | '' (unknown or another script). Only used for rough script checks, never to pick a language. */
    public static function script_class( $code ) {
        $l = self::lang_part( $code );
        if ( in_array( $l, array( 'ar', 'fa', 'ur', 'ps', 'sd', 'ug', 'ku' ), true ) ) { return 'arabic'; }
        if ( in_array( $l, array( 'en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'tr', 'id', 'ms', 'sv', 'pl', 'ro', 'cs', 'da', 'fi', 'no', 'hu', 'vi', 'sw' ), true ) ) { return 'latin'; }
        return '';
    }

    /** Loose URL comparison for hreflang and canonical checks: scheme and host case, fragment and trailing slash ignored. */
    public static function same_url( $a, $b ) {
        $norm = function ( $u ) {
            $u = trim( (string) $u );
            if ( '' === $u ) { return ''; }
            $p = parse_url( $u );
            if ( ! is_array( $p ) || empty( $p['host'] ) ) { return rtrim( $u, '/' ); }
            $path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
            return strtolower( ( isset( $p['scheme'] ) ? $p['scheme'] : 'http' ) ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
        };
        return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
    }

    /**
     * hreflang entries for one translation group.
     *
     * @param array[] $members     Each: code, url. Include the current page. Callers must already have removed
     *                             drafts, noindex pages and anything not on this site.
     * @param string  $default_code Language code that gets x-default ('' for none).
     * @return array[] each: hreflang, href. Empty when fewer than two valid languages remain.
     */
    public static function tags( array $members, $default_code = '' ) {
        $seen = array(); $tags = array();
        foreach ( $members as $m ) {
            $c   = self::normalize_code( isset( $m['code'] ) ? $m['code'] : '' );
            $url = isset( $m['url'] ) ? trim( (string) $m['url'] ) : '';
            if ( '' === $c || 'x-default' === $c || isset( $seen[ $c ] ) || 1 !== preg_match( '#^https?://#i', $url ) ) { continue; }
            $seen[ $c ] = $url;
        }
        if ( count( $seen ) < 2 ) { return array(); }
        ksort( $seen );
        foreach ( $seen as $c => $url ) { $tags[] = array( 'hreflang' => $c, 'href' => $url ); }
        $d = self::normalize_code( $default_code );
        if ( '' !== $d && 'x-default' !== $d && isset( $seen[ $d ] ) ) { $tags[] = array( 'hreflang' => 'x-default', 'href' => $seen[ $d ] ); }
        return $tags;
    }

    /** HTML for the tags (attribute values escaped). */
    public static function render( array $tags ) {
        $out = '';
        foreach ( $tags as $t ) {
            $out .= '<link rel="alternate" hreflang="' . htmlspecialchars( $t['hreflang'], ENT_QUOTES, 'UTF-8' ) . '" href="' . htmlspecialchars( $t['href'], ENT_QUOTES, 'UTF-8' ) . '" />' . "\n";
        }
        return $out;
    }

    /**
     * The string WordPress prints inside <html ...> with its lang (and xml:lang) replaced by $code,
     * or added when missing. Other attributes such as dir are left alone.
     */
    public static function with_lang_attribute( $attributes, $code ) {
        $c = self::normalize_code( $code );
        if ( '' === $c || 'x-default' === $c ) { return (string) $attributes; }
        $v = htmlspecialchars( $c, ENT_QUOTES, 'UTF-8' );
        $attributes = (string) $attributes;
        if ( 1 === preg_match( '/(?<![\w:-])lang=(["\']).*?\1/', $attributes ) ) {
            $attributes = preg_replace( '/(?<![\w:-])lang=(["\']).*?\1/', 'lang="' . $v . '"', $attributes, 1 );
            return preg_replace( '/xml:lang=(["\']).*?\1/', 'xml:lang="' . $v . '"', $attributes, 1 );
        }
        return trim( $attributes . ' lang="' . $v . '"' );
    }

    /**
     * Can two pages be joined into one translation group?
     *
     * @param array $a id, code, members (code => id, including itself)
     * @param array $b same
     * @return array ok (bool), error (string), members (code => id) of the merged group
     */
    public static function plan_link( array $a, array $b ) {
        $ca = self::normalize_code( $a['code'] ); $cb = self::normalize_code( $b['code'] );
        $fail = function ( $m ) { return array( 'ok' => false, 'error' => $m, 'members' => array() ); };
        if ( (int) $a['id'] === (int) $b['id'] ) { return $fail( 'A page cannot be a translation of itself.' ); }
        if ( '' === $ca || '' === $cb ) { return $fail( 'Both pages need a language before they can be linked.' ); }
        if ( $ca === $cb ) { return $fail( 'Both pages are set to the same language. A translation must be in a different language.' ); }
        $merged = array();
        foreach ( array( (array) $a['members'], (array) $b['members'], array( $ca => (int) $a['id'], $cb => (int) $b['id'] ) ) as $set ) {
            foreach ( $set as $code => $id ) {
                $code = self::normalize_code( $code );
                if ( isset( $merged[ $code ] ) && (int) $merged[ $code ] !== (int) $id ) {
                    return $fail( 'The group would contain two different pages in ' . ( self::name( $code ) ? self::name( $code ) : $code ) . '. Unlink one of them first.' );
                }
                $merged[ $code ] = (int) $id;
            }
        }
        return array( 'ok' => true, 'error' => '', 'members' => $merged );
    }
}
