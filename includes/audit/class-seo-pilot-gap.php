<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-internal content gap: terms that closely related pages on THIS site use but this page does
 * not mention. It compares only your own content (bag-of-words cosine); it knows nothing about
 * what searchers want, competitors, or search volume.
 */
final class SEO_Pilot_Gap {

    public static function vector( $text ) {
        $stop = SEO_Pilot_Text::stopwords();
        $v    = array();
        foreach ( SEO_Pilot_Text::words( SEO_Pilot_Text::normalize( $text ) ) as $w ) {
            if ( SEO_Pilot_Text::len( $w ) < 3 || isset( $stop[ $w ] ) || ctype_digit( $w ) ) { continue; }
            $v[ $w ] = isset( $v[ $w ] ) ? $v[ $w ] + 1 : 1;
        }
        return $v;
    }

    public static function cosine( array $a, array $b ) {
        if ( ! $a || ! $b ) { return 0.0; }
        $dot = 0; $na = 0; $nb = 0;
        foreach ( $a as $t => $c ) { $na += $c * $c; if ( isset( $b[ $t ] ) ) { $dot += $c * $b[ $t ]; } }
        foreach ( $b as $c ) { $nb += $c * $c; }
        return ( $na && $nb ) ? $dot / ( sqrt( $na ) * sqrt( $nb ) ) : 0.0;
    }

    /** @param array $others id => plain text. @return array id => score, best first. */
    public static function related( $target_text, array $others, $n = 5, $min = 0.15 ) {
        $tv = self::vector( $target_text );
        $sc = array();
        foreach ( $others as $id => $text ) {
            $s = self::cosine( $tv, self::vector( $text ) );
            if ( $s >= $min ) { $sc[ $id ] = round( $s, 3 ); }
        }
        arsort( $sc );
        return array_slice( $sc, 0, $n, true );
    }

    /**
     * Terms present in at least two related pages (or the only related page) but absent here.
     *
     * @param string[] $related_texts
     * @return array[] term, pages (how many related pages use it)
     */
    public static function missing_terms( $target_text, array $related_texts, $limit = 10 ) {
        $have = SEO_Pilot_Text::vector_keys( $target_text );
        $df   = array(); $tf = array();
        foreach ( $related_texts as $t ) {
            foreach ( self::vector( $t ) as $term => $c ) {
                $df[ $term ] = isset( $df[ $term ] ) ? $df[ $term ] + 1 : 1;
                $tf[ $term ] = isset( $tf[ $term ] ) ? $tf[ $term ] + $c : $c;
            }
        }
        $need = count( $related_texts ) >= 2 ? 2 : 1;
        $out  = array();
        foreach ( $df as $term => $d ) {
            if ( $d >= $need && ! isset( $have[ $term ] ) && $tf[ $term ] >= 2 ) { $out[] = array( 'term' => $term, 'pages' => $d, 'tf' => $tf[ $term ] ); }
        }
        usort( $out, function ( $x, $y ) { return ( $y['pages'] <=> $x['pages'] ) ?: ( $y['tf'] <=> $x['tf'] ); } );
        return array_map( function ( $r ) { return array( 'term' => $r['term'], 'pages' => $r['pages'] ); }, array_slice( $out, 0, $limit ) );
    }
}
