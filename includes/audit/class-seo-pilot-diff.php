<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Word-level diff (longest common subsequence). Output is HTML-escaped; only the
 * <del>/<ins> wrappers are markup. Big inputs fall back to a whole-block replacement.
 */
final class SEO_Pilot_Diff {
    const MAX_TOKENS = 1200;

    public static function tokens( $s ) {
        $t = preg_split( '/(\s+)/u', (string) $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        return is_array( $t ) ? $t : array( (string) $s );
    }

    /** @return array[] each [ 'op' => 'eq'|'del'|'ins', 'text' => string ] */
    public static function ops( $old, $new ) {
        $a = self::tokens( $old );
        $b = self::tokens( $new );

        $pre = 0;
        $na  = count( $a );
        $nb  = count( $b );
        while ( $pre < $na && $pre < $nb && $a[ $pre ] === $b[ $pre ] ) { $pre++; }
        $suf = 0;
        while ( $suf < $na - $pre && $suf < $nb - $pre && $a[ $na - 1 - $suf ] === $b[ $nb - 1 - $suf ] ) { $suf++; }

        $ma = array_slice( $a, $pre, $na - $pre - $suf );
        $mb = array_slice( $b, $pre, $nb - $pre - $suf );

        $ops = array();
        if ( $pre ) { $ops[] = array( 'op' => 'eq', 'text' => implode( '', array_slice( $a, 0, $pre ) ) ); }

        if ( count( $ma ) > self::MAX_TOKENS || count( $mb ) > self::MAX_TOKENS ) {
            if ( $ma ) { $ops[] = array( 'op' => 'del', 'text' => implode( '', $ma ) ); }
            if ( $mb ) { $ops[] = array( 'op' => 'ins', 'text' => implode( '', $mb ) ); }
        } else {
            foreach ( self::lcs_ops( $ma, $mb ) as $op ) { $ops[] = $op; }
        }

        if ( $suf ) { $ops[] = array( 'op' => 'eq', 'text' => implode( '', array_slice( $a, $na - $suf ) ) ); }
        return self::merge( $ops );
    }

    private static function lcs_ops( array $a, array $b ) {
        $n = count( $a );
        $m = count( $b );
        if ( 0 === $n && 0 === $m ) { return array(); }
        $t = array();
        for ( $i = 0; $i <= $n; $i++ ) { $t[ $i ] = array_fill( 0, $m + 1, 0 ); }
        for ( $i = $n - 1; $i >= 0; $i-- ) {
            for ( $j = $m - 1; $j >= 0; $j-- ) {
                $t[ $i ][ $j ] = ( $a[ $i ] === $b[ $j ] ) ? $t[ $i + 1 ][ $j + 1 ] + 1 : max( $t[ $i + 1 ][ $j ], $t[ $i ][ $j + 1 ] );
            }
        }
        $ops = array();
        $i   = 0;
        $j   = 0;
        while ( $i < $n && $j < $m ) {
            if ( $a[ $i ] === $b[ $j ] ) {
                $ops[] = array( 'op' => 'eq', 'text' => $a[ $i ] ); $i++; $j++;
            } elseif ( $t[ $i + 1 ][ $j ] >= $t[ $i ][ $j + 1 ] ) {
                $ops[] = array( 'op' => 'del', 'text' => $a[ $i ] ); $i++;
            } else {
                $ops[] = array( 'op' => 'ins', 'text' => $b[ $j ] ); $j++;
            }
        }
        while ( $i < $n ) { $ops[] = array( 'op' => 'del', 'text' => $a[ $i++ ] ); }
        while ( $j < $m ) { $ops[] = array( 'op' => 'ins', 'text' => $b[ $j++ ] ); }
        return $ops;
    }

    private static function merge( array $ops ) {
        $out = array();
        foreach ( $ops as $op ) {
            $last = count( $out ) - 1;
            if ( $last >= 0 && $out[ $last ]['op'] === $op['op'] ) {
                $out[ $last ]['text'] .= $op['text'];
            } else {
                $out[] = $op;
            }
        }
        return $out;
    }

    public static function changed( $old, $new ) {
        return (string) $old !== (string) $new;
    }

    /** Escaped HTML with <del> for removed and <ins> for added text. */
    public static function html( $old, $new ) {
        $html = '';
        foreach ( self::ops( $old, $new ) as $op ) {
            $e = htmlspecialchars( $op['text'], ENT_QUOTES, 'UTF-8' );
            if ( 'del' === $op['op'] ) {
                $html .= '<del class="seo-pilot-del">' . $e . '</del>';
            } elseif ( 'ins' === $op['op'] ) {
                $html .= '<ins class="seo-pilot-ins">' . $e . '</ins>';
            } else {
                $html .= $e;
            }
        }
        return $html;
    }
}
