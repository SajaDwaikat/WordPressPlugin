<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Multibyte- and Arabic-aware text helpers. Pure functions: no WordPress state.
 * Everything that measures length counts characters, never bytes.
 */
final class SEO_Pilot_Text {

    public static function len( $s ) {
        $s = (string) $s;
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : (int) preg_match_all( '/./us', $s );
    }

    public static function sub( $s, $start, $length = null ) {
        $s = (string) $s;
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $s, $start, $length, 'UTF-8' ); }
        $chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
        return implode( '', array_slice( $chars ? $chars : array(), $start, $length ) );
    }

    public static function lower( $s ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
    }

    /** Collapse all whitespace (including NBSP) to single spaces. */
    public static function ws( $s ) {
        $s   = str_replace( "\xC2\xA0", ' ', (string) $s );
        $out = preg_replace( '/\s+/u', ' ', $s );
        if ( null === $out ) { $out = preg_replace( '/\s+/', ' ', $s ); }
        return trim( (string) $out );
    }

    /** HTML / block markup to visible-ish plain text. */
    public static function plain( $html ) {
        $html = (string) $html;
        if ( function_exists( 'strip_shortcodes' ) ) { $html = strip_shortcodes( $html ); }
        $html = preg_replace( '/<!--.*?-->/s', ' ', $html );
        $html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
        $html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/blockquote|/figcaption)\b[^>]*>#i', ' ', $html );
        $html = strip_tags( $html );
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return self::ws( $html );
    }

    public static function words( $text ) {
        $parts = preg_split( '/[^\p{L}\p{N}\p{M}\'’]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) { return array(); }
        $out = array();
        foreach ( $parts as $p ) {
            $p = trim( $p, "'’" );
            if ( '' !== $p ) { $out[] = $p; }
        }
        return $out;
    }

    public static function word_count( $text ) {
        return count( self::words( $text ) );
    }

    public static function sentences( $text ) {
        $parts = preg_split( '/(?<=[.!?؟…۔])\s+/u', self::ws( $text ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? $parts : array();
    }

    /** Share of letters that are Arabic script (0..1). */
    public static function arabic_ratio( $text ) {
        $letters = (int) preg_match_all( '/\p{L}/u', (string) $text );
        if ( 0 === $letters ) { return 0.0; }
        $arabic = (int) preg_match_all( '/\p{Arabic}/u', (string) $text );
        return $arabic / $letters;
    }

    /** 'ar' | 'mixed' | 'latin' | 'unknown' — script based, not a real language detector. */
    public static function language( $text ) {
        $letters = (int) preg_match_all( '/\p{L}/u', (string) $text );
        if ( $letters < 10 ) { return 'unknown'; }
        $r = self::arabic_ratio( $text );
        if ( $r >= 0.5 ) { return 'ar'; }
        if ( $r >= 0.15 ) { return 'mixed'; }
        return 'latin';
    }

    public static function contains_arabic( $text ) {
        return 1 === preg_match( '/\p{Arabic}/u', (string) $text );
    }

    /** Lowercase, strip Arabic diacritics/tatweel, unify alef forms, Arabic-Indic digits to ASCII. */
    public static function normalize( $s ) {
        $s = self::lower( self::ws( $s ) );
        $s = str_replace( array( "\u{2019}", "\u{2018}" ), "'", $s );
        $s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s );
        $s = preg_replace( '/[\x{0622}\x{0623}\x{0625}]/u', "\u{0627}", $s );
        $s = strtr( $s, array(
            "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
            "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
            "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
            "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
        ) );
        return (string) $s;
    }

    /**
     * Occurrences of a phrase. Latin phrases match on word boundaries; Arabic phrases match as
     * substrings because Arabic attaches prefixes (و، ب، ل، ال) directly to words.
     */
    public static function count_phrase( $haystack, $phrase ) {
        $h = self::normalize( $haystack );
        $p = self::normalize( $phrase );
        if ( '' === $p || '' === $h ) { return 0; }
        if ( self::contains_arabic( $p ) ) {
            return function_exists( 'mb_substr_count' ) ? mb_substr_count( $h, $p, 'UTF-8' ) : substr_count( $h, $p );
        }
        $re = '/(?<![\p{L}\p{N}])' . preg_quote( $p, '/' ) . '(?![\p{L}\p{N}])/u';
        return (int) preg_match_all( $re, $h );
    }

    public static function has_phrase( $haystack, $phrase ) {
        return self::count_phrase( $haystack, $phrase ) > 0;
    }

    /** First N words of a text, as a string. */
    public static function first_words( $text, $n ) {
        return implode( ' ', array_slice( self::words( $text ), 0, (int) $n ) );
    }

    /** @return array [text, truncated(bool)] — cuts on a word boundary where possible. */
    public static function truncate( $text, $max ) {
        $text = (string) $text;
        if ( self::len( $text ) <= $max ) { return array( $text, false ); }
        $cut = self::sub( $text, 0, $max );
        $pos = strrpos( $cut, ' ' );
        if ( false !== $pos && $pos > strlen( $cut ) * 0.6 ) { $cut = substr( $cut, 0, $pos ); }
        return array( rtrim( $cut ), true );
    }

    public static function stopwords() {
        static $list = null;
        if ( null === $list ) {
            $en = 'a an and are as at be but by for from has have he her his i if in into is it its of on or our she so that the their them then there these they this to was we were what when which who will with you your not can do does how why about also more most than too very just';
            $ar = 'في من على إلى الى عن مع هذا هذه ذلك تلك التي الذي الذين هو هي هم هن أن ان إن كان كانت يكون ما لا لم لن قد كل بعد قبل بين حتى أو او ثم لكن كما عند هنا هناك أي اي إذا اذا و';
            $list = array();
            foreach ( preg_split( '/\s+/u', $en . ' ' . $ar, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
                $list[ self::normalize( $w ) ] = true;
            }
        }
        return $list;
    }

    /** Most repeated meaningful terms: [ term => count ], descending. */
    public static function top_terms( array $words, $limit = 5, $min_len = 3 ) {
        $stop   = self::stopwords();
        $counts = array();
        foreach ( $words as $w ) {
            $n = self::normalize( $w );
            if ( self::len( $n ) < $min_len || isset( $stop[ $n ] ) || ctype_digit( $n ) ) { continue; }
            $counts[ $n ] = isset( $counts[ $n ] ) ? $counts[ $n ] + 1 : 1;
        }
        arsort( $counts );
        return array_slice( $counts, 0, $limit, true );
    }

    /** Distinct numbers (digits normalised) in a text — used to spot figures the AI added. */
    public static function numbers( $text ) {
        $t = self::normalize( $text );
        preg_match_all( '/\d+(?:[.,]\d+)*/', $t, $m );
        $out = array();
        foreach ( $m[0] as $n ) { $out[ rtrim( $n, '.,' ) ] = true; }
        return array_map( 'strval', array_keys( $out ) );
    }

    public static function urls( $text ) {
        preg_match_all( '#https?://[^\s"\'<>)\]]+#i', (string) $text, $m );
        return array_values( array_unique( $m[0] ) );
    }

    public static function is_generic_anchor( $text ) {
        $n    = self::normalize( preg_replace( '/[\p{P}\p{S}]+/u', ' ', (string) $text ) );
        $list = array( 'click here', 'here', 'read more', 'learn more', 'more', 'this link', 'link', 'this', 'اضغط هنا', 'انقر هنا', 'هنا', 'المزيد', 'اقرا المزيد', 'اقرأ المزيد', 'اعرف المزيد', 'رابط' );
        $n    = self::ws( $n );
        foreach ( $list as $g ) {
            if ( $n === self::normalize( $g ) ) { return true; }
        }
        return false;
    }

    public static function looks_like_filename_alt( $alt ) {
        $a = trim( (string) $alt );
        if ( '' === $a ) { return false; }
        if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|avif)$/i', $a ) ) { return true; }
        if ( preg_match( '/^(img|dsc|image|photo|screenshot|pic)[\s_\-]*\d*$/i', $a ) ) { return true; }
        return 1 === preg_match( '/^[\w\-]+_\d{3,}$/', $a );
    }

    /** Set of normalised words in a text, for membership tests: [ word => true ]. */
    public static function vector_keys( $text ) {
        $out = array();
        foreach ( self::words( self::normalize( $text ) ) as $w ) { $out[ $w ] = true; }
        return $out;
    }
}
