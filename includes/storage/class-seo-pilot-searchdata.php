<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Real search performance data, imported from CSV exports (Google Search Console or any tool that
 * exports page/query/clicks/impressions/position). SEO Pilot never estimates or invents these
 * numbers: everything shown comes from a file a person imported, labelled with the period they give.
 *
 * Supported exports (English column headers):
 *  - Pages report:       Page, Clicks, Impressions, ... [Position]
 *  - Queries report:     Query, Clicks, Impressions, ... [Position]   (for ONE page you choose at import)
 *  - Page + query data:  Page, Query, Clicks, Impressions, ... [Position]
 * The CTR column is ignored; CTR is always computed as clicks / impressions.
 */
final class SEO_Pilot_Searchdata {
    const OPTION   = 'seo_pilot_imports';
    const MAX_ROWS = 20000;
    const MAX_BYTES = 5242880; // 5 MB

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_pilot_searchdata';
    }

    /* ---------------------------------------------------------------- parsing */

    private static function num( $s ) {
        $s = trim( str_replace( array( "\xC2\xA0", ' ', '%' ), '', (string) $s ) );
        if ( '' === $s ) { return 0.0; }
        if ( false !== strpos( $s, ',' ) && false !== strpos( $s, '.' ) ) { $s = str_replace( ',', '', $s ); }
        elseif ( false !== strpos( $s, ',' ) ) { $s = preg_match( '/^\d{1,3}(,\d{3})+$/', $s ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s ); }
        return is_numeric( $s ) ? (float) $s : 0.0;
    }

    private static function header_key( $h ) {
        $h = strtolower( trim( (string) $h, " \t\n\r\0\x0B\"'" ) );
        $map = array(
            'page' => array( 'page', 'pages', 'top pages', 'url', 'landing page', 'page url' ),
            'query' => array( 'query', 'queries', 'top queries', 'search query', 'keyword' ),
            'clicks' => array( 'clicks' ),
            'impressions' => array( 'impressions' ),
            'position' => array( 'position', 'average position', 'avg. position', 'avg position' ),
        );
        foreach ( $map as $k => $names ) { if ( in_array( $h, $names, true ) ) { return $k; } }
        return '';
    }

    /** @return array|WP_Error kind (pages|queries|page_query), rows[], skipped */
    public static function parse_csv( $csv ) {
        $csv = (string) $csv;
        if ( 0 === strpos( $csv, "\xEF\xBB\xBF" ) ) { $csv = substr( $csv, 3 ); }
        if ( '' === trim( $csv ) ) { return new WP_Error( 'seo_pilot_csv_empty', 'The file is empty.' ); }
        if ( ! preg_match( '//u', $csv ) ) { return new WP_Error( 'seo_pilot_csv_encoding', 'The file is not valid UTF-8. Re-export it as UTF-8 CSV.' ); }
        $first = strtok( $csv, "\n" );
        $delim = ',';
        $best  = substr_count( $first, ',' );
        foreach ( array( ';', "\t" ) as $d ) { if ( substr_count( $first, $d ) > $best ) { $best = substr_count( $first, $d ); $delim = $d; } }

        $fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- in-memory stream, not a file on disk.
        fwrite( $fh, $csv );
        rewind( $fh );
        $head = fgetcsv( $fh, 0, $delim );
        if ( ! is_array( $head ) ) { fclose( $fh ); return new WP_Error( 'seo_pilot_csv_header', 'The header row could not be read.' ); }
        $cols = array();
        foreach ( $head as $i => $h ) { $k = self::header_key( $h ); if ( '' !== $k && ! isset( $cols[ $k ] ) ) { $cols[ $k ] = $i; } }
        if ( ! isset( $cols['clicks'] ) && ! isset( $cols['impressions'] ) ) {
            fclose( $fh );
            return new WP_Error( 'seo_pilot_csv_columns', 'No "Clicks" or "Impressions" column was found. This importer reads English column headers (Page, Query, Clicks, Impressions, Position); export from Search Console with the interface in English.' );
        }
        if ( ! isset( $cols['page'] ) && ! isset( $cols['query'] ) ) {
            fclose( $fh );
            return new WP_Error( 'seo_pilot_csv_columns', 'No "Page" or "Query" column was found.' );
        }
        $kind = isset( $cols['page'], $cols['query'] ) ? 'page_query' : ( isset( $cols['page'] ) ? 'pages' : 'queries' );

        $rows = array(); $skipped = 0;
        while ( false !== ( $r = fgetcsv( $fh, 0, $delim ) ) ) {
            if ( null === $r || array( null ) === $r ) { continue; }
            if ( count( $rows ) >= self::MAX_ROWS ) { $skipped++; continue; }
            $get = function ( $k ) use ( $cols, $r ) { return isset( $cols[ $k ], $r[ $cols[ $k ] ] ) ? $r[ $cols[ $k ] ] : ''; };
            $page  = trim( (string) $get( 'page' ) );
            $query = SEO_Pilot_Text::sub( sanitize_text_field( (string) $get( 'query' ) ), 0, 255 );
            if ( ( 'queries' !== $kind && '' === $page ) || ( 'pages' !== $kind && '' === $query ) ) { $skipped++; continue; }
            $rows[] = array(
                'page' => $page, 'query' => $query,
                'clicks' => max( 0, (int) round( self::num( $get( 'clicks' ) ) ) ),
                'impressions' => max( 0, (int) round( self::num( $get( 'impressions' ) ) ) ),
                'position' => round( max( 0, min( 1000, self::num( $get( 'position' ) ) ) ), 2 ),
            );
        }
        fclose( $fh );
        if ( ! $rows ) { return new WP_Error( 'seo_pilot_csv_norows', 'The file has no usable data rows.' ); }
        return array( 'kind' => $kind, 'rows' => $rows, 'skipped' => $skipped );
    }

    /* ----------------------------------------------------------------- import */

    private static function same_host( $url ) {
        $h = wp_parse_url( $url, PHP_URL_HOST );
        $s = wp_parse_url( home_url(), PHP_URL_HOST );
        $n = function ( $x ) { return preg_replace( '/^www\./', '', strtolower( (string) $x ) ); };
        return $h && $n( $h ) === $n( $s );
    }

    /**
     * @param string $csv
     * @param array  $opts label (required, the period the export covers, as you describe it), post_id (required for a Queries report)
     * @return array|WP_Error summary
     */
    public static function import( $csv, array $opts ) {
        global $wpdb;
        $label = SEO_Pilot_Text::sub( sanitize_text_field( isset( $opts['label'] ) ? $opts['label'] : '' ), 0, 100 );
        if ( '' === $label ) { return new WP_Error( 'seo_pilot_import_label', 'Describe the period this export covers (for example "Search Console, last 3 months, exported 2026-10-01"). It is shown next to every number.' ); }
        if ( strlen( (string) $csv ) > self::MAX_BYTES ) { return new WP_Error( 'seo_pilot_import_size', 'The file is larger than 5 MB.' ); }
        $p = self::parse_csv( $csv );
        if ( is_wp_error( $p ) ) { return $p; }

        $post_id = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;
        if ( 'queries' === $p['kind'] ) {
            if ( ! $post_id || ! get_post( $post_id ) ) { return new WP_Error( 'seo_pilot_import_post', 'This looks like a Queries report, which has no page column. Choose which page it belongs to.' ); }
        }
        $import_id = SEO_Pilot_Util::uuid();
        $foreign = 0; $unresolved = 0; $stored = 0; $posts = array(); $seen = array(); $batch = array();
        foreach ( $p['rows'] as $r ) {
            $pid = $post_id; $url = '';
            if ( 'queries' !== $p['kind'] ) {
                $url = SEO_Pilot_Text::sub( esc_url_raw( $r['page'] ), 0, 500 );
                if ( '' === $url || ! self::same_host( $url ) ) { $foreign++; continue; }
                $pid = (int) url_to_postid( $url ); // note: for ?p=ID URLs this returns the number without checking the post exists
                $pp  = $pid ? get_post( $pid ) : null;
                if ( ! $pp || ! in_array( $pp->post_type, SEO_Pilot_Util::content_post_types(), true ) ) { $unresolved++; continue; }
            }
            $key = $pid . '|' . $r['query'];
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true; $posts[ $pid ] = true;
            $batch[] = $wpdb->prepare( '(%s,%d,%s,%s,%d,%d,%f)', $import_id, $pid, $url, $r['query'], $r['clicks'], $r['impressions'], $r['position'] );
            if ( count( $batch ) >= 200 ) { self::flush( $batch ); $stored += 200; $batch = array(); }
        }
        if ( $batch ) { $stored += count( $batch ); self::flush( $batch ); }
        if ( 0 === $stored ) {
            return new WP_Error( 'seo_pilot_import_none', sprintf( 'No rows matched pages on this site (%d from other hosts, %d that are not WordPress pages or posts). Check that the export is for this site.', $foreign, $unresolved ) );
        }
        $list   = get_option( self::OPTION, array() );
        $list   = is_array( $list ) ? $list : array();
        $list[] = array( 'id' => $import_id, 'label' => $label, 'kind' => $p['kind'], 'rows' => $stored, 'posts' => count( $posts ), 'foreign' => $foreign, 'unresolved' => $unresolved,
            'skipped' => $p['skipped'], 'created_at' => SEO_Pilot_Util::now(), 'user_id' => get_current_user_id() );
        update_option( self::OPTION, $list, false );
        return end( $list );
    }

    private static function flush( array $values ) {
        global $wpdb;
        $t = self::table();
        $wpdb->query( "INSERT INTO {$t} (import_id,post_id,page_url,query,clicks,impressions,position) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- every tuple in $values was individually built with $wpdb->prepare().
    }

    public static function imports() {
        $l = get_option( self::OPTION, array() );
        return is_array( $l ) ? array_reverse( $l ) : array();
    }

    public static function delete_import( $import_id ) {
        global $wpdb;
        $import_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $import_id );
        $wpdb->delete( self::table(), array( 'import_id' => $import_id ) );
        $l = array_values( array_filter( (array) get_option( self::OPTION, array() ), function ( $i ) use ( $import_id ) { return $i['id'] !== $import_id; } ) );
        update_option( self::OPTION, $l, false );
    }

    /* ---------------------------------------------------------------- queries */

    /** The most recent import that has rows for this post. @return array|null the import record */
    public static function latest_import_for( $post_id ) {
        global $wpdb;
        $t   = self::table();
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT import_id FROM {$t} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        if ( ! $ids ) { return null; }
        foreach ( self::imports() as $imp ) { if ( in_array( $imp['id'], $ids, true ) ) { return $imp; } }
        return null;
    }

    public static function top_queries( $post_id, $import_id, $limit = 10 ) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT query, clicks, impressions, position FROM {$t} WHERE post_id = %d AND import_id = %s AND query <> '' ORDER BY impressions DESC, clicks DESC LIMIT %d", $post_id, $import_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return (array) $rows;
    }

    public static function totals( $post_id, $import_id ) {
        global $wpdb;
        $t = self::table();
        $r = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions, COUNT(*) AS rows_n FROM {$t} WHERE post_id = %d AND import_id = %s", $post_id, $import_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return array( 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'rows' => (int) $r['rows_n'] );
    }

    /** Imported queries whose significant words the page text barely mentions. */
    public static function query_gaps( array $queries, $text, $limit = 8 ) {
        $have = SEO_Pilot_Text::vector_keys( $text );
        $stop = SEO_Pilot_Text::stopwords();
        $out  = array();
        foreach ( $queries as $q ) {
            $toks = array();
            foreach ( SEO_Pilot_Text::words( SEO_Pilot_Text::normalize( $q['query'] ) ) as $w ) { if ( SEO_Pilot_Text::len( $w ) >= 3 && ! isset( $stop[ $w ] ) ) { $toks[] = $w; } }
            if ( ! $toks || (int) $q['impressions'] < 1 ) { continue; }
            $found = 0;
            foreach ( $toks as $w ) {
                if ( isset( $have[ $w ] ) || SEO_Pilot_Text::has_phrase( $text, $w ) ) { $found++; }
            }
            if ( $found / count( $toks ) < 0.5 ) { $out[] = array( 'query' => $q['query'], 'impressions' => (int) $q['impressions'], 'clicks' => (int) $q['clicks'], 'position' => (float) $q['position'] ); }
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    /** Queries on which this page AND at least one other page of the site appear in the same export. */
    public static function cannibalization( $post_id, $import_id, $limit = 5 ) {
        global $wpdb;
        $t   = self::table();
        $mine = $wpdb->get_results( $wpdb->prepare( "SELECT query, impressions FROM {$t} WHERE post_id = %d AND import_id = %s AND query <> '' AND impressions > 0 ORDER BY impressions DESC LIMIT 200", $post_id, $import_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $out = array();
        foreach ( (array) $mine as $m ) {
            $others = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$t} WHERE import_id = %s AND query = %s AND impressions > 0 AND post_id <> %d", $import_id, $m['query'], $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            if ( ! $others ) { continue; }
            $titles = array();
            foreach ( $others as $oid ) { $titles[] = wp_strip_all_tags( get_the_title( (int) $oid ) ); }
            $out[] = array( 'query' => $m['query'], 'impressions' => (int) $m['impressions'], 'pages' => $titles );
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    /** Everything the audit needs for one post, or null when no data was imported for it. */
    public static function for_audit( $post_id, $text ) {
        $imp = self::latest_import_for( $post_id );
        if ( ! $imp ) { return null; }
        $queries = self::top_queries( $post_id, $imp['id'], 25 );
        return array(
            'label' => $imp['label'], 'created_at' => $imp['created_at'],
            'totals' => self::totals( $post_id, $imp['id'] ),
            'gaps' => self::query_gaps( $queries, $text ),
            'cannibal' => self::cannibalization( $post_id, $imp['id'] ),
        );
    }
}
