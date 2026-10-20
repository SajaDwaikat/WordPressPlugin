<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Audit history: who changed what, when, on which post, from what to what.
 * One row per changed field; rows applied together share a group_id so they roll back together.
 */
final class SEO_Pilot_History {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_pilot_history';
    }

    public static function insert( array $r ) {
        global $wpdb;
        $ok = $wpdb->insert( self::table(), array(
            'group_id'    => (string) $r['group_id'],
            'post_id'     => (int) $r['post_id'],
            'user_id'     => isset( $r['user_id'] ) ? (int) $r['user_id'] : get_current_user_id(),
            'action'      => (string) $r['action'],
            'field'       => (string) $r['field'],
            'target_type' => isset( $r['target_type'] ) ? (string) $r['target_type'] : 'post_meta',
            'target_key'  => isset( $r['target_key'] ) ? (string) $r['target_key'] : '',
            'owner'       => isset( $r['owner'] ) ? (string) $r['owner'] : '',
            'old_value'   => isset( $r['old_value'] ) ? (string) $r['old_value'] : '',
            'new_value'   => isset( $r['new_value'] ) ? (string) $r['new_value'] : '',
            'rec_ids'     => isset( $r['rec_ids'] ) ? (string) $r['rec_ids'] : '',
            'revision_id' => isset( $r['revision_id'] ) ? (int) $r['revision_id'] : 0,
            'post_status' => isset( $r['post_status'] ) ? (string) $r['post_status'] : '',
            'reverted'    => ! empty( $r['reverted'] ) ? 1 : 0,
            'note'        => isset( $r['note'] ) ? (string) $r['note'] : '',
            'created_at'  => SEO_Pilot_Util::now(),
        ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    private static function fix( $row ) {
        if ( ! is_array( $row ) ) { return null; }
        foreach ( array( 'id', 'post_id', 'user_id', 'revision_id', 'reverted', 'reverted_by' ) as $k ) { $row[ $k ] = (int) $row[ $k ]; }
        return $row;
    }

    public static function group( $group_id ) {
        global $wpdb;
        $t    = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE group_id = %s ORDER BY id ASC", $group_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( array( __CLASS__, 'fix' ), (array) $rows );
    }

    public static function query( $post_id = 0, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $t   = self::table();
        $sql = "SELECT * FROM {$t}";
        $arg = array();
        if ( $post_id ) { $sql .= ' WHERE post_id = %d'; $arg[] = (int) $post_id; }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $arg[] = (int) $limit; $arg[] = (int) $offset;
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map( array( __CLASS__, 'fix' ), (array) $rows );
    }

    public static function count( $post_id = 0 ) {
        global $wpdb;
        $t = self::table();
        if ( $post_id ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE post_id = %d", $post_id ) ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function mark_reverted( array $ids, $user_id ) {
        global $wpdb;
        foreach ( $ids as $id ) {
            $wpdb->update( self::table(), array( 'reverted' => 1, 'reverted_at' => SEO_Pilot_Util::now(), 'reverted_by' => (int) $user_id ), array( 'id' => (int) $id ) );
        }
    }

    /** Groups for one post that can still be rolled back, newest first. @return array[] group_id => rows */
    public static function open_groups( $post_id, $limit = 10 ) {
        $groups = array();
        foreach ( self::query( $post_id, 100, 0 ) as $row ) {
            if ( ! in_array( $row['action'], array( 'apply' ), true ) ) { continue; }
            $groups[ $row['group_id'] ][] = $row;
        }
        $out = array();
        foreach ( $groups as $gid => $rows ) {
            $rows = array_reverse( $rows );
            $open = array_filter( $rows, function ( $r ) { return 0 === $r['reverted']; } );
            if ( $open ) { $out[ $gid ] = $rows; }
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    public static function purge_for_post( $post_id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ) );
    }
}
