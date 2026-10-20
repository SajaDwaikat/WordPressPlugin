<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates and upgrades the plugin's tables and migrates data from the 0.1.0 MVP.
 * Safe to run repeatedly: every step is idempotent.
 */
final class SEO_Pilot_Installer {
    const DB_OPTION      = 'seo_pilot_db_version';
    const MIGRATED_OPTION = 'seo_pilot_migrated_020';

    public static function activate() {
        if ( ! class_exists( 'DOMDocument' ) ) {
            wp_die( esc_html__( 'SEO Pilot needs the PHP DOM extension (php-xml). Ask your host to enable it, then activate the plugin again.', 'seo-pilot' ), '', array( 'back_link' => true ) );
        }
        self::install();
        self::maybe_migrate();
    }

    /** Runs on admin_init: cheap version check, upgrades only when needed. */
    public static function maybe_upgrade() {
        if ( get_option( self::DB_OPTION ) !== SEO_PILOT_DB_VERSION ) { self::install(); }
        if ( ! get_option( self::MIGRATED_OPTION ) ) { self::maybe_migrate(); }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $recs    = SEO_Pilot_Recs::table();
        $history = SEO_Pilot_History::table();

        dbDelta( "CREATE TABLE {$recs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  rec_type varchar(32) NOT NULL,
  target varchar(191) NOT NULL DEFAULT '',
  original longtext,
  proposed longtext,
  rationale text,
  flags longtext,
  source varchar(16) NOT NULL DEFAULT 'ai',
  status varchar(16) NOT NULL DEFAULT 'pending',
  base_hash varchar(40) NOT NULL DEFAULT '',
  batch_id varchar(40) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_status (post_id,status),
  KEY batch_id (batch_id)
) {$charset};" );

        dbDelta( "CREATE TABLE {$history} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id varchar(40) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(16) NOT NULL,
  field varchar(32) NOT NULL,
  target_type varchar(16) NOT NULL DEFAULT 'post_meta',
  target_key varchar(100) NOT NULL DEFAULT '',
  owner varchar(16) NOT NULL DEFAULT '',
  old_value longtext,
  new_value longtext,
  rec_ids varchar(255) NOT NULL DEFAULT '',
  revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_status varchar(20) NOT NULL DEFAULT '',
  reverted tinyint(1) NOT NULL DEFAULT 0,
  reverted_at datetime NULL DEFAULT NULL,
  reverted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  note text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY group_id (group_id)
) {$charset};" );

        $sd = SEO_Pilot_Searchdata::table();
        dbDelta( "CREATE TABLE {$sd} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  import_id varchar(40) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_url varchar(500) NOT NULL DEFAULT '',
  query varchar(255) NOT NULL DEFAULT '',
  clicks int(11) NOT NULL DEFAULT 0,
  impressions int(11) NOT NULL DEFAULT 0,
  position decimal(8,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY import_id (import_id),
  KEY post_id (post_id)
) {$charset};" );

        update_option( self::DB_OPTION, SEO_PILOT_DB_VERSION, false );
    }

    public static function tables_exist() {
        global $wpdb;
        foreach ( array( SEO_Pilot_Recs::table(), SEO_Pilot_History::table(), SEO_Pilot_Searchdata::table() ) as $t ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return false; }
        }
        return true;
    }

    /* ------------------------------------------------------- MVP migration */

    public static function maybe_migrate() {
        if ( ! self::tables_exist() ) { return; }
        self::migrate_api_key();
        self::migrate_pending_suggestions();
        self::migrate_snapshots();
        // The 0.1.0 audit result format is not compatible; ask for a fresh audit.
        delete_option( 'seo_pilot_last_audit' );
        update_option( self::MIGRATED_OPTION, SEO_PILOT_VERSION, false );
    }

    /** The key moves out of the settings array into its own, non-autoloaded, encrypted option. */
    private static function migrate_api_key() {
        $old = get_option( SEO_Pilot_Settings::OPTION, array() );
        if ( ! is_array( $old ) || ! array_key_exists( 'api_key', $old ) ) { return; }
        if ( '' !== trim( (string) $old['api_key'] ) && '' === (string) get_option( SEO_Pilot_Settings::SECRET_OPTION, '' ) ) {
            SEO_Pilot_Settings::store_key( (string) $old['api_key'] );
        }
        unset( $old['api_key'] );
        update_option( SEO_Pilot_Settings::OPTION, $old );
    }

    /** 0.1.0 kept one pending suggestion per post in post meta; they become reviewable recommendations. */
    private static function migrate_pending_suggestions() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_seo_pilot_pending_suggestion'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ( (array) $rows as $row ) {
            $pid  = (int) $row['post_id'];
            $data = maybe_unserialize( $row['meta_value'] );
            if ( ! $pid || ! is_array( $data ) || ! get_post( $pid ) ) { continue; }
            $batch = SEO_Pilot_Util::uuid();
            foreach ( array( 'title' => 'seo_title', 'meta_description' => 'meta_description' ) as $legacy => $type ) {
                if ( empty( $data[ $legacy ] ) ) { continue; }
                $cur = SEO_Pilot_Compat::get( $pid, $type );
                SEO_Pilot_Recs::insert( array(
                    'post_id' => $pid, 'rec_type' => $type, 'original' => $cur['value'], 'proposed' => (string) $data[ $legacy ],
                    'rationale' => 'Suggestion created by SEO Pilot 0.1; review it like any other.',
                    'flags' => array( 'verify' => array(), 'notes' => array( 'Migrated from the SEO Pilot 0.1 MVP. Check it against the page before accepting.' ) ),
                    'source' => 'ai', 'status' => 'pending', 'batch_id' => $batch, 'created_by' => 0,
                ) );
            }
            delete_post_meta( $pid, '_seo_pilot_pending_suggestion' );
        }
    }

    /** 0.1.0 kept one rollback snapshot per post; they become history entries that can still be rolled back. */
    private static function migrate_snapshots() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_seo_pilot_last_snapshot'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ( (array) $rows as $row ) {
            $pid  = (int) $row['post_id'];
            $snap = maybe_unserialize( $row['meta_value'] );
            $post = get_post( $pid );
            if ( ! $pid || ! $post || ! is_array( $snap ) ) { continue; }
            $gid  = 'legacy-' . $pid;
            $note = 'Migrated from the SEO Pilot 0.1 MVP snapshot (user not recorded).';
            if ( isset( $snap['title'] ) && (string) $snap['title'] !== $post->post_title ) {
                SEO_Pilot_History::insert( array( 'group_id' => $gid, 'post_id' => $pid, 'user_id' => 0, 'action' => 'apply', 'field' => 'post_title', 'target_type' => 'post_field',
                    'target_key' => 'post_title', 'old_value' => (string) $snap['title'], 'new_value' => $post->post_title, 'post_status' => $post->post_status, 'note' => $note ) );
            }
            $own = (string) get_post_meta( $pid, '_seo_pilot_meta_description', true );
            $old = isset( $snap['meta_description'] ) ? (string) $snap['meta_description'] : '';
            if ( $old !== $own ) {
                SEO_Pilot_History::insert( array( 'group_id' => $gid, 'post_id' => $pid, 'user_id' => 0, 'action' => 'apply', 'field' => 'meta_description', 'target_type' => 'post_meta',
                    'target_key' => '_seo_pilot_meta_description', 'owner' => SEO_Pilot_Compat::OWN, 'old_value' => $old, 'new_value' => $own, 'post_status' => $post->post_status, 'note' => $note ) );
            }
            delete_post_meta( $pid, '_seo_pilot_last_snapshot' );
        }
    }
}
