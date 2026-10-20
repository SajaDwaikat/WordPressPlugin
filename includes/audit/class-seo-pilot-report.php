<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transparent assessment per criterion, and the site-wide report with CSV export.
 * No numeric score: each criterion shows its status and exactly which checks produced it.
 */
final class SEO_Pilot_Report {

    public static function criteria_list() {
        return array(
            'intent'    => 'Search intent alignment',
            'coverage'  => 'Coverage of relevant subtopics',
            'structure' => 'Heading structure',
            'readable'  => 'Readability',
            'links'     => 'Internal linking',
            'meta'      => 'Title and meta description quality',
            'content'   => 'Content specificity and usefulness',
            'verify'    => 'Factual verification flags',
            'index'     => 'Indexability',
            'images'    => 'Images and alt text',
        );
    }

    /** Which criterion a checklist item feeds. */
    public static function criterion_of( array $row ) {
        $by_id = array(
            'indexable' => 'index', 'title_alignment' => 'intent', 'kw_set' => 'intent', 'kw_title' => 'intent', 'kw_meta' => 'intent', 'kw_intro' => 'intent', 'kw_body' => 'intent', 'kw_unique' => 'intent',
            'kw_headings' => 'coverage', 'kw_secondary' => 'coverage', 'sd_gap' => 'coverage', 'sd_cannibal' => 'intent',
            'h1' => 'structure', 'heading_order' => 'structure', 'subheadings' => 'structure',
        );
        if ( isset( $by_id[ $row['id'] ] ) ) { return $by_id[ $row['id'] ]; }
        $by_group = array( 'meta' => 'meta', 'structure' => 'structure', 'content' => 'content', 'readability' => 'readable', 'links' => 'links', 'images' => 'images', 'keywords' => 'intent', 'intent' => 'intent', 'verification' => 'verify', 'search_data' => 'coverage' );
        return isset( $by_group[ $row['group'] ] ) ? $by_group[ $row['group'] ] : 'content';
    }

    /**
     * @return array[] key, label, status (fail|warn|manual|pass|na), pass, total, influenced_by[] (label — status)
     */
    public static function criteria( array $audit ) {
        $rows = array();
        foreach ( self::criteria_list() as $k => $label ) { $rows[ $k ] = array( 'key' => $k, 'label' => $label, 'status' => 'na', 'pass' => 0, 'total' => 0, 'influenced_by' => array(), 'seen' => array() ); }
        foreach ( $audit['checklist'] as $c ) {
            if ( in_array( $c['status'], array( 'info', 'na' ), true ) ) { continue; }
            $k = self::criterion_of( $c );
            $rows[ $k ]['total']++;
            $rows[ $k ]['seen'][ $c['status'] ] = true;
            if ( 'pass' === $c['status'] ) { $rows[ $k ]['pass']++; }
            else { $rows[ $k ]['influenced_by'][] = $c['label'] . ' — ' . ( $c['detail'] ? $c['detail'] : $c['status'] ); }
        }
        foreach ( $rows as $k => $r ) {
            $s = $r['seen'];
            $rows[ $k ]['status'] = isset( $s['fail'] ) ? 'fail' : ( isset( $s['warn'] ) ? 'warn' : ( isset( $s['manual'] ) ? 'manual' : ( isset( $s['pass'] ) ? 'pass' : 'na' ) ) );
            unset( $rows[ $k ]['seen'] );
        }
        return array_values( $rows );
    }

    /* ------------------------------------------------------------ site report */

    /** Aggregate the saved audits of all pages. */
    public static function site_report( array $audits ) {
        $sev = array( 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 );
        $issues = array(); $crit = array(); $worst = array(); $human = 0;
        foreach ( self::criteria_list() as $k => $l ) { $crit[ $k ] = array( 'label' => $l, 'fail' => 0, 'warn' => 0, 'manual' => 0, 'pass' => 0 ); }
        foreach ( $audits as $a ) {
            foreach ( $a['findings'] as $f ) {
                $sev[ $f['severity'] ]++;
                if ( $f['needs_human'] ) { $human++; }
                if ( ! isset( $issues[ $f['id'] ] ) ) { $issues[ $f['id'] ] = array( 'id' => $f['id'], 'severity' => $f['severity'], 'what' => $f['what'], 'pages' => 0 ); }
                $issues[ $f['id'] ]['pages']++;
            }
            foreach ( self::criteria( $a ) as $c ) { if ( isset( $crit[ $c['key'] ][ $c['status'] ] ) ) { $crit[ $c['key'] ][ $c['status'] ]++; } }
            $worst[] = array( 'post_id' => $a['post_id'], 'title' => $a['title'], 'high' => $a['summary']['severity']['high'], 'medium' => $a['summary']['severity']['medium'], 'total' => $a['summary']['total_findings'] );
        }
        usort( $issues, function ( $x, $y ) { return ( $y['pages'] <=> $x['pages'] ) ?: SEO_Pilot_Audit::severity_rank( $y['severity'] ) <=> SEO_Pilot_Audit::severity_rank( $x['severity'] ); } );
        usort( $worst, function ( $x, $y ) { return ( $y['high'] <=> $x['high'] ) ?: ( ( $y['medium'] <=> $x['medium'] ) ?: ( $y['total'] <=> $x['total'] ) ); } );
        return array( 'pages' => count( $audits ), 'severity' => $sev, 'needs_human' => $human, 'top_issues' => array_slice( array_values( $issues ), 0, 15 ), 'criteria' => array_values( $crit ), 'worst_pages' => array_slice( $worst, 0, 20 ) );
    }

    /** Spreadsheet formula injection guard: cells starting with = + - @ (or tab/CR) are prefixed with an apostrophe. */
    public static function csv_cell( $v ) {
        $v = (string) $v;
        return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
    }

    /** @param array[] $audits saved audit results keyed or listed. */
    public static function csv( array $audits ) {
        $fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- in-memory stream for fputcsv(), not a file on disk.
        fwrite( $fh, "\xEF\xBB\xBF" ); // BOM so Excel reads Arabic correctly
        fputcsv( $fh, array( 'post_id', 'title', 'url', 'status', 'severity', 'category', 'finding_id', 'what', 'why_it_may_matter', 'suggested_action', 'needs_human', 'evidence', 'audited_at' ) );
        foreach ( $audits as $a ) {
            foreach ( $a['findings'] as $f ) {
                $ev = array();
                foreach ( $f['evidence'] as $e ) { $ev[] = is_array( $e ) ? ( isset( $e['src'] ) ? $e['src'] : wp_json_encode( $e ) ) : (string) $e; }
                fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), array( $a['post_id'], $a['title'], $a['url'], isset( $a['post_status'] ) ? $a['post_status'] : '', $f['severity'], $f['category'], $f['id'], $f['what'], $f['why'], $f['action'], $f['needs_human'] ? 'yes' : 'no', implode( ' | ', $ev ), $a['audited_at'] ) ) );
            }
        }
        rewind( $fh );
        $out = stream_get_contents( $fh );
        fclose( $fh );
        return $out;
    }
}
