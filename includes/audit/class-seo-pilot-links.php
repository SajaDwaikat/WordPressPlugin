<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Contextual internal link suggestions. A suggestion exists ONLY where a phrase naming another
 * real page (its title or a keyword you set for it) already appears in this page's own text, so
 * no text is invented. Applying one wraps that existing phrase in a link, with approval.
 */
final class SEO_Pilot_Links {

    /** Wrap the first occurrence of $phrase that is not already inside a link. @return string|null new inner HTML */
    public static function link_phrase( $inner, $phrase, $url ) {
        $phrase = trim( (string) $phrase );
        if ( SEO_Pilot_Text::len( $phrase ) < 4 || '' === (string) $url ) { return null; }
        $parts = preg_split( '/(<[^>]*>)/', (string) $inner, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) { return null; }
        $re    = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
        $depth = 0; $done = false;
        foreach ( $parts as $i => $p ) {
            if ( '' === $p ) { continue; }
            if ( '<' === $p[0] ) {
                if ( preg_match( '/^<a\b/i', $p ) ) { $depth++; }
                elseif ( preg_match( '/^<\/a\s*>/i', $p ) ) { $depth = max( 0, $depth - 1 ); }
                continue;
            }
            if ( $done || $depth > 0 ) { continue; }
            if ( 1 === preg_match( $re, $p, $m, PREG_OFFSET_CAPTURE ) ) {
                $off = $m[0][1];
                $parts[ $i ] = substr( $p, 0, $off ) . '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $m[0][0] . '</a>' . substr( $p, $off + strlen( $m[0][0] ) );
                $done = true;
            }
        }
        return $done ? implode( '', $parts ) : null;
    }

    /** Candidate anchor phrases for a target page, longest first: its keywords and its title (with a trailing "| Site" removed). */
    public static function phrases_for( array $target ) {
        $out = array();
        foreach ( array_merge( array( isset( $target['primary'] ) ? $target['primary'] : '' ), isset( $target['secondary'] ) ? (array) $target['secondary'] : array() ) as $k ) { $out[] = trim( (string) $k ); }
        $title = trim( (string) $target['title'] );
        $out[] = $title;
        $short = trim( preg_split( '/\s+[\|\x{2013}\x{2014}\-:]\s+/u', $title )[0] );
        $out[] = $short;
        $seen = array(); $res = array();
        foreach ( $out as $p ) {
            $n = SEO_Pilot_Text::normalize( $p );
            if ( '' === $n || isset( $seen[ $n ] ) || SEO_Pilot_Text::len( $p ) < 4 ) { continue; }
            $seen[ $n ] = true; $res[] = $p;
        }
        usort( $res, function ( $a, $b ) { return SEO_Pilot_Text::len( $b ) <=> SEO_Pilot_Text::len( $a ); } );
        return $res;
    }

    private static function path_key( $url ) {
        return trim( preg_replace( '#^https?://[^/]+#i', '', (string) $url ), '/' );
    }

    /**
     * @param array   $segments Paragraph segments of the source page (Parser::paragraph_segments).
     * @param string[] $existing_hrefs Links already in the source content.
     * @param array[] $targets  id, title, url, primary, secondary — only real, published, indexable pages.
     * @return array[] target_id, target_title, url, phrase, segment_hash, segment_id, original, proposed
     */
    public static function suggest( array $segments, array $existing_hrefs, array $targets, $max = 5 ) {
        $have = array();
        foreach ( $existing_hrefs as $h ) { $have[ self::path_key( $h ) ] = true; }
        $used = array(); $out = array();
        foreach ( $targets as $t ) {
            if ( count( $out ) >= $max ) { break; }
            if ( isset( $have[ self::path_key( $t['url'] ) ] ) ) { continue; }
            foreach ( self::phrases_for( $t ) as $phrase ) {
                $hit = false;
                foreach ( $segments as $seg ) {
                    if ( isset( $used[ $seg['hash'] ] ) || substr_count( strtolower( $seg['inner'] ), '<a ' ) >= 2 ) { continue; }
                    $new = self::link_phrase( $seg['inner'], $phrase, $t['url'] );
                    if ( null === $new ) { continue; }
                    $used[ $seg['hash'] ] = true;
                    $out[] = array( 'target_id' => (int) $t['id'], 'target_title' => $t['title'], 'url' => $t['url'], 'phrase' => $phrase,
                        'segment_hash' => $seg['hash'], 'segment_id' => $seg['id'], 'original' => $seg['inner'], 'proposed' => $new );
                    $hit = true;
                    break;
                }
                if ( $hit ) { break; }
            }
        }
        return $out;
    }
}
