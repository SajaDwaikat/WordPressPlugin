<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Recommendation storage. Proposed text always lives here, separately from the post, until
 * a person approves it and the approval manager applies it.
 */
final class SEO_Pilot_Recs {
    /** Types that change the site once approved. Everything else is advisory guidance. */
    const APPLICABLE = array( 'seo_title', 'meta_description', 'rewrite', 'alt_text', 'internal_link' );

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_pilot_recs';
    }

    public static function is_applicable( $type ) {
        return in_array( $type, self::APPLICABLE, true );
    }

    public static function type_label( $type ) {
        $m = array(
            'seo_title' => 'SEO title', 'meta_description' => 'Meta description', 'rewrite' => 'Paragraph rewrite', 'alt_text' => 'Image alt text',
            'internal_link' => 'Internal link', 'inbound_links' => 'Pages that could link here', 'site_gap' => 'Terms related pages use',
            'title_review' => 'Title review (AI advice)', 'keywords_local' => 'Localized keyword suggestions (AI advice)', 'outline' => 'Suggested outline', 'topics' => 'Missing topics and questions', 'faq' => 'FAQ ideas', 'refresh' => 'Refresh and verification notes',
        );
        return isset( $m[ $type ] ) ? $m[ $type ] : $type;
    }

    private static function normalize( $row ) {
        if ( ! is_array( $row ) ) { return null; }
        $flags = json_decode( (string) $row['flags'], true );
        $row['flags'] = is_array( $flags ) ? array_merge( array( 'verify' => array(), 'notes' => array() ), $flags ) : array( 'verify' => array(), 'notes' => array() );
        foreach ( array( 'id', 'post_id', 'created_by' ) as $k ) { $row[ $k ] = (int) $row[ $k ]; }
        return $row;
    }

    public static function insert( array $r ) {
        global $wpdb;
        $now = SEO_Pilot_Util::now();
        $ok  = $wpdb->insert( self::table(), array(
            'post_id'    => (int) $r['post_id'],
            'rec_type'   => (string) $r['rec_type'],
            'target'     => isset( $r['target'] ) ? (string) $r['target'] : '',
            'original'   => isset( $r['original'] ) ? (string) $r['original'] : '',
            'proposed'   => isset( $r['proposed'] ) ? (string) $r['proposed'] : '',
            'rationale'  => isset( $r['rationale'] ) ? (string) $r['rationale'] : '',
            'flags'      => wp_json_encode( isset( $r['flags'] ) ? $r['flags'] : array( 'verify' => array(), 'notes' => array() ) ),
            'source'     => isset( $r['source'] ) ? (string) $r['source'] : 'ai',
            'status'     => isset( $r['status'] ) ? (string) $r['status'] : 'pending',
            'base_hash'  => isset( $r['base_hash'] ) ? (string) $r['base_hash'] : '',
            'batch_id'   => isset( $r['batch_id'] ) ? (string) $r['batch_id'] : '',
            'created_by' => isset( $r['created_by'] ) ? (int) $r['created_by'] : get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function get( $id ) {
        global $wpdb;
        $t = self::table();
        return self::normalize( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @return array[] newest batch first, applicable types before advisory ones. */
    public static function for_post( $post_id, array $statuses = array() ) {
        global $wpdb;
        $t   = self::table();
        $sql = "SELECT * FROM {$t} WHERE post_id = %d";
        $arg = array( (int) $post_id );
        if ( $statuses ) {
            $sql .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
            $arg  = array_merge( $arg, $statuses );
        }
        $sql .= ' ORDER BY id ASC';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out  = array();
        foreach ( (array) $rows as $row ) { $out[] = self::normalize( $row ); }
        return $out;
    }

    public static function update( $id, array $fields ) {
        global $wpdb;
        $allowed = array( 'proposed', 'status', 'flags', 'rationale', 'source' );
        $data    = array();
        foreach ( $fields as $k => $v ) {
            if ( ! in_array( $k, $allowed, true ) ) { continue; }
            $data[ $k ] = 'flags' === $k ? wp_json_encode( $v ) : (string) $v;
        }
        if ( ! $data ) { return false; }
        $data['updated_at'] = SEO_Pilot_Util::now();
        return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
    }

    public static function set_status( $id, $status ) {
        return self::update( $id, array( 'status' => $status ) );
    }

    /** Retire open recommendations of the given types (optionally one target) when new ones replace them. */
    public static function supersede( $post_id, array $types, $target = null ) {
        foreach ( self::for_post( $post_id, array( 'pending', 'accepted', 'rejected', 'stale' ) ) as $r ) {
            if ( ! in_array( $r['rec_type'], $types, true ) ) { continue; }
            if ( null !== $target && $r['target'] !== $target ) { continue; }
            self::set_status( $r['id'], 'superseded' );
        }
    }

    public static function purge_for_post( $post_id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ) );
    }

    public static function counts( $post_id ) {
        $c = array( 'pending' => 0, 'accepted' => 0, 'rejected' => 0, 'applied' => 0 );
        foreach ( self::for_post( $post_id ) as $r ) {
            if ( isset( $c[ $r['status'] ] ) ) { $c[ $r['status'] ]++; }
        }
        return $c;
    }
}
