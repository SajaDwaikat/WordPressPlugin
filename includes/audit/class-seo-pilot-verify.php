<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Before/after check for a batch of fixes. Pure logic, unit-tested without WordPress.
 *
 * It compares two audits of the same page by finding id and says, in plain terms, what the fixes did:
 * which problems went away, which got smaller, which are still there, and whether anything new and
 * serious appeared. It only reports; rolling back stays a person's decision in Assisted mode.
 */
final class SEO_Pilot_Verify {

    /** id => severity, what, size (how many items the finding lists; 1 when it lists none). */
    public static function snapshot( array $audit ) {
        $out = array();
        foreach ( isset( $audit['findings'] ) ? (array) $audit['findings'] : array() as $f ) {
            $out[ $f['id'] ] = array(
                'id' => $f['id'], 'severity' => $f['severity'], 'what' => $f['what'],
                'size' => max( 1, isset( $f['evidence'] ) && is_array( $f['evidence'] ) ? count( $f['evidence'] ) : 1 ),
            );
        }
        return $out;
    }

    private static function serious( $sev ) {
        return in_array( $sev, array( 'high', 'medium' ), true );
    }

    /**
     * @param array    $before     Audit before the fixes.
     * @param array    $after      Audit after the fixes.
     * @param string[] $target_ids Finding ids the applied fixes were meant to resolve.
     * @return array verdict (verified|partial|not_resolved|regressed|nothing), resolved[], improved[], remaining[], new[], worsened[],
     *               severity_before, severity_after, targets[]
     */
    public static function compare( array $before, array $after, array $target_ids ) {
        $b = self::snapshot( $before );
        $a = self::snapshot( $after );
        $res = array( 'verdict' => 'nothing', 'resolved' => array(), 'improved' => array(), 'remaining' => array(), 'new' => array(), 'worsened' => array(),
            'severity_before' => self::counts( $before ), 'severity_after' => self::counts( $after ), 'targets' => array() );

        $targets = array_values( array_filter( array_unique( $target_ids ), function ( $id ) use ( $b ) { return isset( $b[ $id ] ); } ) );
        $res['targets'] = $targets;
        foreach ( $targets as $id ) {
            if ( ! isset( $a[ $id ] ) ) { $res['resolved'][] = $b[ $id ]; }
            elseif ( $a[ $id ]['size'] < $b[ $id ]['size'] ) { $res['improved'][] = array_merge( $a[ $id ], array( 'from' => $b[ $id ]['size'], 'to' => $a[ $id ]['size'] ) ); }
            else { $res['remaining'][] = $a[ $id ]; }
        }
        foreach ( $a as $id => $f ) {
            if ( ! isset( $b[ $id ] ) ) { $res['new'][] = $f; }
            elseif ( ! in_array( $id, $targets, true ) && $f['size'] > $b[ $id ]['size'] && self::serious( $f['severity'] ) ) { $res['worsened'][] = $f; }
        }

        $new_serious = array_filter( $res['new'], function ( $f ) { return self::serious( $f['severity'] ); } );
        if ( $new_serious || $res['worsened'] ) { $res['verdict'] = 'regressed'; }
        elseif ( ! $targets ) { $res['verdict'] = 'nothing'; }
        elseif ( ! $res['remaining'] && ! $res['improved'] ) { $res['verdict'] = 'verified'; }
        elseif ( ! $res['remaining'] ) { $res['verdict'] = 'partial'; }
        elseif ( $res['resolved'] || $res['improved'] ) { $res['verdict'] = 'partial'; }
        else { $res['verdict'] = 'not_resolved'; }
        return $res;
    }

    private static function counts( array $audit ) {
        $c = array( 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 );
        foreach ( isset( $audit['findings'] ) ? (array) $audit['findings'] : array() as $f ) { if ( isset( $c[ $f['severity'] ] ) ) { $c[ $f['severity'] ]++; } }
        return $c;
    }

    /** One honest sentence about the outcome. */
    public static function message( array $r ) {
        switch ( $r['verdict'] ) {
            case 'verified':     return 'Verified: every problem these fixes were meant to solve is gone, and no new serious problem appeared.';
            case 'partial':      return 'Partly improved: some of the targeted problems are gone or smaller, but others remain. See below.';
            case 'not_resolved': return 'Not resolved: the audit still reports the problems these fixes were meant to solve. The change may not have been what the check needs.';
            case 'regressed':    return 'Check this: the re-run audit found a new or larger serious problem after the change. You can roll the change back below.';
            default:             return 'The re-run audit had nothing specific to compare for these changes.';
        }
    }
}
