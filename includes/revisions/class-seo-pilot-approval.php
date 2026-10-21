<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The only code path that changes a post. It works exclusively from recommendations a person
 * has explicitly accepted, shows exactly what will change (plan), demands confirmation before
 * touching a live page or another plugin's field, snapshots the original, writes a history
 * entry for every changed field, and re-runs the audit afterwards.
 *
 * It can change only: post content (paragraph text, internal links and img alt), the SEO title and the
 * meta description. Canonicals, robots directives, structured data, slugs and dates are never touched.
 */
final class SEO_Pilot_Approval {

    public static function revisions_enabled( $post ) {
        $post = get_post( $post );
        return $post && post_type_supports( $post->post_type, 'revisions' ) && 0 !== (int) wp_revisions_to_keep( $post );
    }

    private static function field_label( $field ) {
        $m = array( 'seo_title' => 'SEO title', 'meta_description' => 'Meta description', 'post_content' => 'Post content', 'post_title' => 'Post title' );
        return isset( $m[ $field ] ) ? $m[ $field ] : $field;
    }

    /** Only these destinations can ever be written, by apply or by rollback. */
    private static function is_allowed_target( $type, $key ) {
        if ( 'post_field' === $type ) { return in_array( $key, array( 'post_content', 'post_title' ), true ); }
        $allowed = array( '_seo_pilot_seo_title', '_seo_pilot_meta_description', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description' );
        return 'post_meta' === $type && in_array( $key, $allowed, true );
    }

    /* ---------------------------------------------------------------- plan */

    /**
     * Work out exactly what applying the accepted recommendations would change, without changing anything.
     *
     * @return array post, changes[], blocked[], stale_ids[], noop_ids[], live, revisions, requires_owner_confirm
     */
    public static function plan( $post_id, array $only = array() ) {
        $post = get_post( $post_id );
        $plan = array( 'post' => $post, 'changes' => array(), 'blocked' => array(), 'stale_ids' => array(), 'noop_ids' => array(), 'live' => false, 'revisions' => false, 'requires_owner_confirm' => false );
        if ( ! $post ) { return $plan; }
        $plan['live']      = SEO_Pilot_Util::is_live_status( $post->post_status );
        $plan['revisions'] = self::revisions_enabled( $post );

        $accepted = array();
        foreach ( SEO_Pilot_Recs::for_post( $post_id, array( 'accepted' ) ) as $r ) {
            // $only: apply just these recommendation ids (the Fix assistant), leaving other accepted ones for the normal panel.
            if ( SEO_Pilot_Recs::is_applicable( $r['rec_type'] ) && ( ! $only || in_array( (int) $r['id'], array_map( 'intval', $only ), true ) ) ) { $accepted[] = $r; }
        }
        $owner = SEO_Pilot_Compat::owner();

        // SEO title and meta description: the newest accepted one wins.
        foreach ( array( 'seo_title', 'meta_description' ) as $field ) {
            $recs = array_values( array_filter( $accepted, function ( $r ) use ( $field ) { return $r['rec_type'] === $field; } ) );
            if ( ! $recs ) { continue; }
            $rec = end( $recs );
            $new = sanitize_text_field( $rec['proposed'] );
            if ( '' === $new ) { $plan['blocked'][] = array( 'rec_id' => $rec['id'], 'message' => self::field_label( $field ) . ' is empty after sanitising.' ); continue; }
            if ( ! SEO_Pilot_Compat::can_write( $owner, $field ) ) {
                $plan['blocked'][] = array( 'rec_id' => $rec['id'], 'message' => SEO_Pilot_Compat::label( $owner ) . ' manages the ' . strtolower( self::field_label( $field ) ) . ' and SEO Pilot cannot write to it. Copy the approved text into ' . SEO_Pilot_Compat::label( $owner ) . ' by hand, then reject this suggestion.' );
                continue;
            }
            $cur = SEO_Pilot_Compat::get( $post_id, $field, $owner );
            if ( $cur['value'] === $new ) { $plan['noop_ids'][] = $rec['id']; continue; }
            $third = SEO_Pilot_Compat::is_third_party( $owner );
            $plan['changes'][] = array(
                'field' => $field, 'label' => self::field_label( $field ), 'target_type' => 'post_meta', 'target_key' => SEO_Pilot_Compat::field_key( $owner, $field ),
                'owner' => $owner, 'owner_label' => SEO_Pilot_Compat::label( $owner ), 'third_party' => $third, 'overwrites' => $third && '' !== $cur['value'],
                'old' => $cur['value'], 'new' => $new, 'rec_ids' => array( $rec['id'] ),
            );
            if ( $third ) { $plan['requires_owner_confirm'] = true; }
        }

        // Content: paragraph rewrites and image alt text, composed into one new version of the content.
        $content = (string) $post->post_content;
        $new_content = $content;
        $ids = array();
        foreach ( $accepted as $r ) {
            if ( 'rewrite' === $r['rec_type'] ) {
                $inner = SEO_Pilot_Validator::clean_inline( $r['proposed'] );
                if ( '' === SEO_Pilot_Text::plain( $inner ) ) { $plan['blocked'][] = array( 'rec_id' => $r['id'], 'message' => 'A paragraph rewrite is empty after sanitising.' ); continue; }
                $res = SEO_Pilot_Parser::replace_paragraph( $new_content, (string) substr( $r['target'], 4 ), $inner );
                if ( is_wp_error( $res ) ) {
                    $plan['stale_ids'][] = $r['id'];
                    $plan['blocked'][]   = array( 'rec_id' => $r['id'], 'message' => $res->get_error_message() . ' Reject or regenerate this suggestion.' );
                    continue;
                }
                $new_content = $res;
                $ids[]       = $r['id'];
            } elseif ( 'internal_link' === $r['rec_type'] ) {
                $url = isset( $r['flags']['url'] ) ? (string) $r['flags']['url'] : '';
                $tid = isset( $r['flags']['target_id'] ) ? (int) $r['flags']['target_id'] : 0;
                if ( ! $tid || '' === $url || 'publish' !== get_post_status( $tid ) || get_permalink( $tid ) !== $url ) {
                    $plan['blocked'][] = array( 'rec_id' => $r['id'], 'message' => 'The page an internal link points to is no longer published or its address changed. Reject this suggestion and analyse the related pages again.' );
                    continue;
                }
                $inner = SEO_Pilot_Validator::clean_inline( $r['proposed'] );
                list( $inner, $removed ) = SEO_Pilot_Validator::strip_new_links( $inner, $r['original'], array( $url ) );
                if ( $removed || ! in_array( $url, SEO_Pilot_Parser::hrefs( $inner ), true ) ) {
                    $plan['blocked'][] = array( 'rec_id' => $r['id'], 'message' => 'An internal-link suggestion no longer contains exactly the approved link.' );
                    continue;
                }
                $res = SEO_Pilot_Parser::replace_paragraph( $new_content, (string) substr( $r['target'], 4 ), $inner );
                if ( is_wp_error( $res ) ) {
                    $plan['stale_ids'][] = $r['id'];
                    $plan['blocked'][]   = array( 'rec_id' => $r['id'], 'message' => $res->get_error_message() . ' Reject this suggestion and analyse the related pages again.' );
                    continue;
                }
                $new_content = $res;
                $ids[]       = $r['id'];
            } elseif ( 'alt_text' === $r['rec_type'] ) {
                $alt        = sanitize_text_field( $r['proposed'] );
                $decorative = ! empty( $r['flags']['decorative'] );
                $replace    = isset( $r['flags']['mode'] ) && 'replace' === $r['flags']['mode'];
                // An empty alt is only valid when a person marked the image as decorative.
                if ( '' === $alt && ! $decorative ) { $plan['blocked'][] = array( 'rec_id' => $r['id'], 'message' => 'An alt text entry is empty.' ); continue; }
                if ( $replace ) {
                    $old = isset( $r['flags']['old_alt'] ) ? (string) $r['flags']['old_alt'] : '';
                    list( $c, $n ) = SEO_Pilot_Parser::replace_img_alt( $new_content, $r['original'], $old, $alt );
                } else {
                    list( $c, $n ) = SEO_Pilot_Parser::set_img_alt( $new_content, $r['original'], $alt );
                }
                if ( 0 === $n ) {
                    $plan['stale_ids'][] = $r['id'];
                    $plan['blocked'][]   = array( 'rec_id' => $r['id'], 'message' => $replace
                        ? 'The image or its alt text has changed since this suggestion was made, so it was not replaced. Reject it and review the image again.'
                        : 'The image for an alt text entry is no longer in the post without an alt attribute.' );
                    continue;
                }
                $new_content = $c;
                $ids[]       = $r['id'];
            }
        }
        if ( $new_content !== $content ) {
            $plan['changes'][] = array(
                'field' => 'post_content', 'label' => 'Post content', 'target_type' => 'post_field', 'target_key' => 'post_content', 'owner' => '', 'owner_label' => 'WordPress',
                'third_party' => false, 'overwrites' => false, 'old' => $content, 'new' => $new_content, 'rec_ids' => $ids,
            );
        } elseif ( $ids ) {
            $plan['noop_ids'] = array_merge( $plan['noop_ids'], $ids );
        }
        return $plan;
    }

    /* --------------------------------------------------------------- apply */

    /**
     * @param int   $post_id
     * @param array $opts mode ('live'|'draft_copy'), confirm_live (bool), confirm_owner (bool), only (int[]: recommendation ids to apply; empty = all accepted)
     * @return array|WP_Error
     */
    public static function apply( $post_id, array $opts = array() ) {
        $opts = array_merge( array( 'mode' => 'live', 'confirm_live' => false, 'confirm_owner' => false, 'only' => array() ), $opts );
        $post = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'seo_pilot_no_post', 'That post no longer exists.' ); }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return new WP_Error( 'seo_pilot_forbidden', 'You are not allowed to edit this post.' ); }

        $plan = self::plan( $post_id, (array) $opts['only'] );
        foreach ( $plan['stale_ids'] as $sid ) { SEO_Pilot_Recs::set_status( $sid, 'stale' ); }
        if ( $plan['blocked'] ) {
            return new WP_Error( 'seo_pilot_blocked', 'Nothing was changed. ' . implode( ' ', array_map( function ( $b ) { return $b['message']; }, $plan['blocked'] ) ) );
        }
        if ( ! $plan['changes'] ) {
            foreach ( $plan['noop_ids'] as $nid ) { SEO_Pilot_Recs::set_status( $nid, 'applied' ); }
            return new WP_Error( 'seo_pilot_nothing', 'There is nothing to apply: no accepted suggestion would change the post.' );
        }

        if ( 'draft_copy' === $opts['mode'] ) {
            return self::apply_as_draft_copy( $post, $plan );
        }

        if ( $plan['live'] && empty( $opts['confirm_live'] ) ) {
            return new WP_Error( 'seo_pilot_confirm_live', 'This page is live. Tick the confirmation box to change it, or save the changes as a draft copy instead.' );
        }
        if ( $plan['requires_owner_confirm'] && empty( $opts['confirm_owner'] ) ) {
            return new WP_Error( 'seo_pilot_confirm_owner', 'Another SEO plugin controls the SEO fields. Tick the confirmation box to allow SEO Pilot to write to its field.' );
        }
        foreach ( $plan['changes'] as $c ) {
            if ( ! self::is_allowed_target( $c['target_type'], $c['target_key'] ) ) {
                return new WP_Error( 'seo_pilot_bad_target', 'Refusing to write to a field SEO Pilot is not allowed to change.' );
            }
        }

        $group    = SEO_Pilot_Util::uuid();
        $user_id  = get_current_user_id();
        $rev_id   = 0;
        $has_content = false;
        foreach ( $plan['changes'] as $c ) { if ( 'post_content' === $c['field'] ) { $has_content = true; } }
        if ( $has_content && $plan['revisions'] ) {
            wp_save_post_revision( $post_id ); // snapshot the pre-change state
            $revs = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1, 'fields' => 'ids' ) );
            $rev_id = $revs ? (int) reset( $revs ) : 0;
        }

        // Write SEO fields first, content last; undo already-written fields if anything fails.
        $written = array();
        $rows    = array();
        foreach ( $plan['changes'] as $c ) {
            if ( 'post_meta' === $c['target_type'] ) {
                $res = SEO_Pilot_Compat::set( $post_id, $c['field'], $c['new'], $c['owner'] );
                if ( is_wp_error( $res ) ) { self::undo( $post_id, $written ); return $res; }
                $written[] = $c;
                $c['stored'] = (string) get_post_meta( $post_id, $c['target_key'], true );
            } else {
                $res = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $c['new'] ) ), true );
                if ( is_wp_error( $res ) || ! $res ) { self::undo( $post_id, $written ); return is_wp_error( $res ) ? $res : new WP_Error( 'seo_pilot_update_failed', 'WordPress could not save the post.' ); }
                $written[]   = $c;
                $c['stored'] = (string) get_post_field( 'post_content', $post_id ); // what WordPress actually saved (filters may adjust it)
            }
            $rows[] = $c;
        }

        foreach ( $rows as $c ) {
            SEO_Pilot_History::insert( array(
                'group_id' => $group, 'post_id' => $post_id, 'user_id' => $user_id, 'action' => 'apply', 'field' => $c['field'],
                'target_type' => $c['target_type'], 'target_key' => $c['target_key'], 'owner' => $c['owner'],
                'old_value' => $c['old'], 'new_value' => $c['stored'], 'rec_ids' => implode( ',', $c['rec_ids'] ),
                'revision_id' => 'post_content' === $c['field'] ? $rev_id : 0, 'post_status' => $post->post_status,
            ) );
            foreach ( $c['rec_ids'] as $rid ) { SEO_Pilot_Recs::set_status( $rid, 'applied' ); }
        }
        foreach ( $plan['noop_ids'] as $nid ) { SEO_Pilot_Recs::set_status( $nid, 'applied' ); }
        // Older accepted suggestions for a field that was just replaced are retired.
        foreach ( SEO_Pilot_Recs::for_post( $post_id, array( 'accepted' ) ) as $r ) {
            if ( in_array( $r['rec_type'], array( 'seo_title', 'meta_description' ), true ) ) { SEO_Pilot_Recs::set_status( $r['id'], 'superseded' ); }
        }

        $audit = SEO_Pilot_Audit_Runner::audit_post( $post_id );
        return array( 'mode' => 'live', 'group_id' => $group, 'fields' => array_map( function ( $c ) { return $c['label']; }, $rows ), 'revision_id' => $rev_id, 'audit' => $audit );
    }

    private static function undo( $post_id, array $written ) {
        foreach ( array_reverse( $written ) as $c ) {
            if ( 'post_meta' === $c['target_type'] ) { SEO_Pilot_Compat::write_key( $post_id, $c['target_key'], $c['old'] ); }
            else { wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $c['old'] ) ) ); }
        }
    }

    /** Leaves the live page untouched; creates a new draft containing the approved changes. */
    private static function apply_as_draft_copy( $post, array $plan ) {
        $content = (string) $post->post_content;
        foreach ( $plan['changes'] as $c ) { if ( 'post_content' === $c['field'] ) { $content = $c['new']; } }
        $new_id = wp_insert_post( wp_slash( array(
            'post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => $post->post_title . ' (SEO Pilot draft)',
            'post_content' => $content, 'post_excerpt' => $post->post_excerpt, 'post_author' => get_current_user_id(),
        ) ), true );
        if ( is_wp_error( $new_id ) ) { return $new_id; }
        if ( ! $new_id ) { return new WP_Error( 'seo_pilot_draft_failed', 'WordPress could not create the draft copy.' ); }
        update_post_meta( $new_id, '_seo_pilot_draft_of', (int) $post->ID );
        $group = SEO_Pilot_Util::uuid();
        foreach ( $plan['changes'] as $c ) {
            if ( 'post_meta' === $c['target_type'] ) { SEO_Pilot_Compat::set( $new_id, $c['field'], $c['new'], $c['owner'] ); }
            SEO_Pilot_History::insert( array(
                'group_id' => $group, 'post_id' => (int) $post->ID, 'action' => 'draft_copy', 'field' => $c['field'], 'target_type' => $c['target_type'], 'target_key' => $c['target_key'],
                'owner' => $c['owner'], 'old_value' => '', 'new_value' => 'post_content' === $c['field'] ? '(content saved in draft #' . $new_id . ')' : $c['new'],
                'rec_ids' => implode( ',', $c['rec_ids'] ), 'post_status' => $post->post_status, 'note' => 'Draft copy #' . $new_id . ' created. The live post #' . $post->ID . ' was not changed.',
            ) );
            foreach ( $c['rec_ids'] as $rid ) { SEO_Pilot_Recs::set_status( $rid, 'drafted' ); }
        }
        foreach ( $plan['noop_ids'] as $nid ) { SEO_Pilot_Recs::set_status( $nid, 'applied' ); }
        return array( 'mode' => 'draft_copy', 'group_id' => $group, 'draft_id' => (int) $new_id, 'fields' => array_map( function ( $c ) { return $c['label']; }, $plan['changes'] ) );
    }

    /* ------------------------------------------------------------ rollback */

    private static function read_current( $post_id, array $row ) {
        if ( 'post_field' === $row['target_type'] ) { return (string) get_post_field( $row['target_key'], $post_id ); }
        return (string) get_post_meta( $post_id, $row['target_key'], true );
    }

    /**
     * Restore the values recorded before a group of changes. If something was edited after SEO Pilot
     * applied the change, the rollback stops and says so, unless the person explicitly forces it.
     *
     * @param string $group_id
     * @param array  $opts confirm_live (bool), force (bool)
     * @return array|WP_Error
     */
    public static function rollback( $group_id, array $opts = array() ) {
        $opts = array_merge( array( 'confirm_live' => false, 'force' => false ), $opts );
        $rows = array_values( array_filter( SEO_Pilot_History::group( $group_id ), function ( $r ) { return 'apply' === $r['action'] && 0 === $r['reverted']; } ) );
        if ( ! $rows ) { return new WP_Error( 'seo_pilot_nothing_to_rollback', 'There is nothing left to roll back for that change.' ); }
        $post_id = (int) $rows[0]['post_id'];
        $post    = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'seo_pilot_no_post', 'That post no longer exists.' ); }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return new WP_Error( 'seo_pilot_forbidden', 'You are not allowed to edit this post.' ); }
        foreach ( $rows as $r ) {
            if ( ! self::is_allowed_target( $r['target_type'], $r['target_key'] ) ) { return new WP_Error( 'seo_pilot_bad_target', 'Refusing to restore a field SEO Pilot is not allowed to change.' ); }
        }
        if ( SEO_Pilot_Util::is_live_status( $post->post_status ) && empty( $opts['confirm_live'] ) ) {
            return new WP_Error( 'seo_pilot_confirm_live', 'This page is live. Tick the confirmation box to roll the change back.' );
        }

        $conflicts = array();
        $current   = array();
        foreach ( $rows as $r ) {
            $current[ $r['id'] ] = self::read_current( $post_id, $r );
            if ( $current[ $r['id'] ] !== $r['new_value'] ) { $conflicts[] = self::field_label( $r['field'] ); }
        }
        if ( $conflicts && empty( $opts['force'] ) ) {
            return new WP_Error( 'seo_pilot_rollback_conflict', 'These fields were changed after SEO Pilot applied the change: ' . implode( ', ', $conflicts ) . '. Rolling back would discard those newer edits. Tick "restore anyway" if that is what you want.' );
        }

        $has_content = false;
        foreach ( $rows as $r ) { if ( 'post_content' === $r['target_key'] ) { $has_content = true; } }
        if ( $has_content && self::revisions_enabled( $post ) ) { wp_save_post_revision( $post_id ); }

        $new_group = SEO_Pilot_Util::uuid();
        $done      = array();
        foreach ( $rows as $r ) {
            if ( 'post_field' === $r['target_type'] ) {
                $res = wp_update_post( wp_slash( array( 'ID' => $post_id, $r['target_key'] => $r['old_value'] ) ), true );
                if ( is_wp_error( $res ) || ! $res ) { return is_wp_error( $res ) ? $res : new WP_Error( 'seo_pilot_update_failed', 'WordPress could not restore the post.' ); }
            } else {
                SEO_Pilot_Compat::write_key( $post_id, $r['target_key'], $r['old_value'] );
            }
            SEO_Pilot_History::insert( array(
                'group_id' => $new_group, 'post_id' => $post_id, 'action' => 'rollback', 'field' => $r['field'], 'target_type' => $r['target_type'], 'target_key' => $r['target_key'],
                'owner' => $r['owner'], 'old_value' => $current[ $r['id'] ], 'new_value' => $r['old_value'], 'rec_ids' => $r['rec_ids'], 'post_status' => $post->post_status,
                'note' => 'Rolled back change ' . $group_id . ( $conflicts ? ' (newer edits were overwritten at the editor\'s request)' : '' ),
            ) );
            $done[] = $r['id'];
        }
        SEO_Pilot_History::mark_reverted( $done, get_current_user_id() );
        $audit = SEO_Pilot_Audit_Runner::audit_post( $post_id );
        return array( 'restored' => count( $done ), 'audit' => $audit, 'overwrote_newer_edits' => (bool) $conflicts );
    }
}
