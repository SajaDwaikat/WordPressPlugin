<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Near-duplicate detection with a bottom-k sketch of word 5-gram shingles (an estimate of Jaccard
 * similarity). Pure functions. It only compares stored text; it is a heuristic that a person must
 * review, and SEO Pilot never sets canonicals or redirects because of it.
 */
final class SEO_Pilot_Similarity {
    const K         = 5;
    const SIZE      = 128;
    const MIN_WORDS = 80;

    /** @return array [ n => word count, s => sorted shingle hashes (empty when the text is too short) ] */
    public static function sketch( $text ) {
        $words = SEO_Pilot_Text::words( SEO_Pilot_Text::normalize( $text ) );
        $n     = count( $words );
        if ( $n < self::MIN_WORDS ) { return array( 'n' => $n, 's' => array() ); }
        $set = array();
        for ( $i = 0; $i <= $n - self::K; $i++ ) {
            $set[ crc32( implode( ' ', array_slice( $words, $i, self::K ) ) ) ] = true;
        }
        $hashes = array_keys( $set );
        sort( $hashes );
        return array( 'n' => $n, 's' => array_slice( $hashes, 0, self::SIZE ) );
    }

    /** Estimated Jaccard similarity, 0..1. */
    public static function jaccard( array $a, array $b ) {
        $sa = isset( $a['s'] ) ? $a['s'] : array();
        $sb = isset( $b['s'] ) ? $b['s'] : array();
        if ( ! $sa || ! $sb ) { return 0.0; }
        $union = array_unique( array_merge( $sa, $sb ) );
        sort( $union );
        $union = array_slice( $union, 0, self::SIZE );
        $fa    = array_flip( $sa );
        $fb    = array_flip( $sb );
        $both  = 0;
        foreach ( $union as $h ) { if ( isset( $fa[ $h ] ) && isset( $fb[ $h ] ) ) { $both++; } }
        return (float) ( $both / count( $union ) );
    }

    /**
     * @param array  $target  Sketch of the page being audited.
     * @param array  $others  id => [ 'title' => string, 'sketch' => array ]
     * @param int    $self_id
     * @return array[] id, title, score (0..1), sorted by score, only >= $min
     */
    public static function find_similar( array $target, array $others, $self_id, $min = 0.5, $limit = 5 ) {
        $out = array();
        foreach ( $others as $id => $o ) {
            if ( (int) $id === (int) $self_id ) { continue; }
            $s = self::jaccard( $target, $o['sketch'] );
            if ( $s >= $min ) { $out[] = array( 'id' => (int) $id, 'title' => $o['title'], 'score' => round( $s, 2 ) ); }
        }
        usort( $out, function ( $x, $y ) { return $y['score'] <=> $x['score']; } );
        return array_slice( $out, 0, $limit );
    }
}
