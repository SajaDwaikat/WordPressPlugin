<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Content SEO module's admin screens and form handlers. Every handler checks the plugin
 * capability, a nonce, the post type, and edit_post for the specific post before doing anything.
 */
final class SEO_Pilot_Workspace {

    public static function init() {
        $actions = array( 'audit_post', 'save_page', 'generate', 'rec_action', 'save_alt', 'apply', 'rollback_group', 'live_check', 'analyse_site' );
        foreach ( $actions as $a ) {
            add_action( 'admin_post_seo_pilot_' . $a, array( __CLASS__, 'handle_' . $a ) );
        }
    }

    /** Menu callback: the screens live in SEO_Pilot_Workspace_View. */
    public static function render() {
        self::gate();
        SEO_Pilot_Workspace_View::render();
    }

    /* ----------------------------------------------------------- guards */

    private static function gate() {
        if ( ! current_user_can( SEO_Pilot_Util::capability() ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'seo-pilot' ), '', array( 'response' => 403 ) ); }
    }

    /** Capability + nonce + supported post + edit_post. Returns the post. */
    private static function guard( $key, $post_id ) {
        self::gate();
        check_admin_referer( 'seo_pilot_' . $key . '_' . $post_id );
        return self::guard_post( $post_id );
    }

    private static function guard_post( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || ! in_array( $post->post_type, SEO_Pilot_Util::content_post_types(), true ) ) {
            wp_die( esc_html__( 'That post is not available for this action.', 'seo-pilot' ), '', array( 'response' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You are not allowed to edit this post.', 'seo-pilot' ), '', array( 'response' => 403 ) );
        }
        return $post;
    }

    private static function post_id_from_request() {
        return isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
    }

    private static function back( $post_id ) {
        SEO_Pilot_Util::redirect( 'seo-pilot-content', array( 'post_id' => (int) $post_id ) );
    }

    private static function posted( $key ) {
        return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sanitised by the caller after nonce verification.
    }

    /* --------------------------------------------------------- handlers */

    public static function handle_audit_post() {
        $post = self::guard( 'audit', self::post_id_from_request() );
        $res  = SEO_Pilot_Audit_Runner::audit_post( $post );
        SEO_Pilot_Util::flash( 'success', sprintf( 'Audit refreshed: %d finding(s).', $res['summary']['total_findings'] ) );
        self::back( $post->ID );
    }

    public static function handle_save_page() {
        $post = self::guard( 'page', self::post_id_from_request() );
        SEO_Pilot_Audit_Runner::save_page_settings( $post->ID, array(
            'primary' => self::posted( 'primary' ), 'secondary' => self::posted( 'secondary' ),
            'intent' => self::posted( 'intent' ), 'audience' => self::posted( 'audience' ),
        ) );
        SEO_Pilot_Audit_Runner::audit_post( $post );
        SEO_Pilot_Util::flash( 'success', 'Page settings saved and the audit was refreshed.' );
        self::back( $post->ID );
    }

    public static function handle_generate() {
        $post  = self::guard( 'generate', self::post_id_from_request() );
        $tasks = array();
        if ( isset( $_POST['tasks'] ) && is_array( $_POST['tasks'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $tasks = array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST['tasks'] ) ), array_keys( SEO_Pilot_Prompt::tasks() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        }
        if ( ! $tasks ) {
            SEO_Pilot_Util::flash( 'error', 'Choose at least one thing to generate.' );
            self::back( $post->ID );
        }
        $instruction = SEO_Pilot_Text::sub( sanitize_textarea_field( self::posted( 'instruction' ) ), 0, 500 );
        $res = self::generate( $post, $tasks, $instruction );
        if ( is_wp_error( $res ) ) {
            SEO_Pilot_Util::flash( 'error', $res->get_error_message() );
        } else {
            $msg = $res['created'] ? sprintf( '%d recommendation(s) are ready for review. Nothing has been changed on the page.', $res['created'] ) : 'The AI had no changes to suggest for the selected tasks.';
            if ( $res['truncated_input'] ) { $msg .= ' Some paragraphs were left out because of the character limit.'; }
            if ( $res['truncated_output'] ) { $msg .= ' The AI answer was cut off by the provider; some items may be missing.'; }
            if ( $res['warnings'] ) { $msg .= ' ' . implode( ' ', $res['warnings'] ); }
            SEO_Pilot_Util::flash( $res['created'] ? 'success' : 'info', $msg );
        }
        self::back( $post->ID );
    }

    public static function handle_rec_action() {
        self::gate();
        $rec_id = isset( $_POST['rec_id'] ) ? absint( $_POST['rec_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        check_admin_referer( 'seo_pilot_rec_' . $rec_id );
        $rec = SEO_Pilot_Recs::get( $rec_id );
        if ( ! $rec ) { wp_die( esc_html__( 'That recommendation no longer exists.', 'seo-pilot' ), '', array( 'response' => 404 ) ); }
        $post = self::guard_post( $rec['post_id'] );
        $op   = sanitize_key( self::posted( 'op' ) );
        $open = in_array( $rec['status'], array( 'pending', 'accepted', 'rejected' ), true );

        if ( in_array( $op, array( 'save', 'accept' ), true ) ) {
            if ( ! $open ) { SEO_Pilot_Util::flash( 'error', 'This recommendation can no longer be changed.' ); self::back( $post->ID ); }
            if ( SEO_Pilot_Recs::is_applicable( $rec['rec_type'] ) && isset( $_POST['proposed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $clean = self::clean_proposed( $rec, (string) self::posted( 'proposed' ) );
                if ( is_wp_error( $clean ) ) { SEO_Pilot_Util::flash( 'error', $clean->get_error_message() ); self::back( $post->ID ); }
                if ( $clean !== $rec['proposed'] ) {
                    $flags = $rec['flags'];
                    $flags['edited'] = true;
                    SEO_Pilot_Recs::update( $rec_id, array( 'proposed' => $clean, 'flags' => $flags, 'source' => 'user', 'status' => 'pending' ) );
                    $rec['status'] = 'pending';
                }
            }
            if ( 'accept' === $op ) {
                SEO_Pilot_Recs::set_status( $rec_id, 'accepted' );
                SEO_Pilot_Util::flash( 'success', SEO_Pilot_Recs::is_applicable( $rec['rec_type'] ) ? 'Accepted. It will only be applied when you use the "Apply accepted changes" panel and confirm.' : 'Marked as noted.' );
            } else {
                SEO_Pilot_Util::flash( 'success', 'Your edit was saved. It still needs to be accepted.' );
            }
        } elseif ( 'reject' === $op ) {
            SEO_Pilot_Recs::set_status( $rec_id, 'rejected' );
            SEO_Pilot_Util::flash( 'info', 'Rejected. Nothing was changed.' );
        } elseif ( 'reopen' === $op ) {
            if ( $open ) { SEO_Pilot_Recs::set_status( $rec_id, 'pending' ); }
        } elseif ( 'regenerate' === $op ) {
            $hash = ( 'rewrite' === $rec['rec_type'] ) ? (string) substr( $rec['target'], 4 ) : null;
            if ( ! in_array( $rec['rec_type'], array( 'seo_title', 'meta_description', 'rewrite', 'outline', 'topics', 'faq', 'refresh' ), true ) ) {
                SEO_Pilot_Util::flash( 'error', 'This item cannot be regenerated.' ); self::back( $post->ID );
            }
            $res = self::generate( $post, array( 'rewrite' === $rec['rec_type'] ? 'rewrite' : $rec['rec_type'] ), '', $hash );
            if ( is_wp_error( $res ) ) { SEO_Pilot_Util::flash( 'error', $res->get_error_message() ); }
            else { SEO_Pilot_Util::flash( $res['created'] ? 'success' : 'info', $res['created'] ? 'A new suggestion replaced the old one.' : 'The AI had nothing different to suggest; the earlier suggestion was kept.' ); }
        }
        self::back( $post->ID );
    }

    public static function handle_save_alt() {
        $post = self::guard( 'alt', self::post_id_from_request() );
        $audit = SEO_Pilot_Audit_Runner::get_saved( $post->ID );
        $srcmap = array();
        if ( $audit ) {
            foreach ( $audit['findings'] as $f ) {
                if ( 'alt_missing' === $f['id'] ) { foreach ( $f['evidence'] as $e ) { $srcmap[ $e['hash'] ] = $e['src']; } }
            }
        }
        $alts = ( isset( $_POST['alt'] ) && is_array( $_POST['alt'] ) ) ? wp_unslash( $_POST['alt'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $n    = 0;
        foreach ( $alts as $hash => $text ) {
            $hash = preg_replace( '/[^a-f0-9]/', '', (string) $hash );
            $text = SEO_Pilot_Text::sub( sanitize_text_field( (string) $text ), 0, 300 );
            if ( '' === $text || ! isset( $srcmap[ $hash ] ) ) { continue; }
            SEO_Pilot_Recs::supersede( $post->ID, array( 'alt_text' ), 'img:' . $hash );
            SEO_Pilot_Recs::insert( array(
                'post_id' => $post->ID, 'rec_type' => 'alt_text', 'target' => 'img:' . $hash, 'original' => $srcmap[ $hash ], 'proposed' => $text,
                'rationale' => 'Written by you after looking at the image.', 'flags' => array( 'verify' => array(), 'notes' => array() ),
                'source' => 'user', 'status' => 'accepted', 'base_hash' => sha1( (string) $post->post_content ),
            ) );
            $n++;
        }
        SEO_Pilot_Util::flash( $n ? 'success' : 'info', $n ? sprintf( '%d alt text entr(ies) saved as accepted. They are only applied when you use "Apply accepted changes".', $n ) : 'No alt text was entered.' );
        self::back( $post->ID );
    }

    public static function handle_apply() {
        $post = self::guard( 'apply', self::post_id_from_request() );
        $before = SEO_Pilot_Audit_Runner::get_saved( $post->ID );
        $res = SEO_Pilot_Approval::apply( $post->ID, array(
            'mode' => 'draft_copy' === sanitize_key( self::posted( 'mode' ) ) ? 'draft_copy' : 'live',
            'confirm_live' => ! empty( $_POST['confirm_live'] ), 'confirm_owner' => ! empty( $_POST['confirm_owner'] ), // phpcs:ignore WordPress.Security.NonceVerification
        ) );
        if ( is_wp_error( $res ) ) {
            SEO_Pilot_Util::flash( 'error', $res->get_error_message() );
        } elseif ( 'draft_copy' === $res['mode'] ) {
            SEO_Pilot_Util::flash( 'success', sprintf( 'A draft copy (#%d) was created with the approved changes (%s). The live page was not changed. Review and publish the draft from the WordPress editor.', $res['draft_id'], implode( ', ', $res['fields'] ) ) );
        } else {
            $b = $before ? (int) $before['summary']['total_findings'] : null;
            $a = isset( $res['audit']['summary']['total_findings'] ) ? (int) $res['audit']['summary']['total_findings'] : null;
            $msg = 'Applied: ' . implode( ', ', $res['fields'] ) . '.' . ( $res['revision_id'] ? ' A WordPress revision of the previous content was saved first.' : '' ) . ' The change is recorded in the history and can be rolled back.';
            if ( null !== $a ) { $msg .= ' The audit was re-run: ' . $a . ' finding(s)' . ( null !== $b ? ' (was ' . $b . ')' : '' ) . '.'; }
            SEO_Pilot_Util::flash( 'success', $msg . ' Use "Check live output" to confirm what visitors receive.' );
        }
        self::back( $post->ID );
    }

    public static function handle_rollback_group() {
        self::gate();
        $group = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) self::posted( 'group_id' ) );
        check_admin_referer( 'seo_pilot_rollback_' . $group );
        $rows = SEO_Pilot_History::group( $group );
        if ( ! $rows ) { wp_die( esc_html__( 'That history entry does not exist.', 'seo-pilot' ), '', array( 'response' => 404 ) ); }
        $post = self::guard_post( $rows[0]['post_id'] );
        $res  = SEO_Pilot_Approval::rollback( $group, array( 'confirm_live' => ! empty( $_POST['confirm_live'] ), 'force' => ! empty( $_POST['force'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $res ) ) { SEO_Pilot_Util::flash( 'error', $res->get_error_message() ); }
        else { SEO_Pilot_Util::flash( 'success', sprintf( 'Rolled back %d field(s) to their earlier values.', $res['restored'] ) ); }
        self::back( $post->ID );
    }

    public static function handle_live_check() {
        $post = self::guard( 'live', self::post_id_from_request() );
        $live = SEO_Pilot_Live_Check::fetch( $post );
        if ( is_wp_error( $live ) ) { SEO_Pilot_Util::flash( 'error', $live->get_error_message() ); }
        else {
            set_transient( 'seo_pilot_live_' . $post->ID, $live, 15 * MINUTE_IN_SECONDS );
            SEO_Pilot_Util::flash( 'success', 'Live output fetched. See the results below.' );
        }
        self::back( $post->ID );
    }

    /** Local analysis (no AI, nothing leaves the site): link opportunities, pages that could link here, content gap. */
    public static function handle_analyse_site() {
        $post = self::guard( 'analyse', self::post_id_from_request() );
        $res  = self::analyse_site( $post );
        SEO_Pilot_Util::flash( 'success', sprintf( 'Local analysis finished: %d internal link suggestion(s), %d page(s) that could link here, %d related page(s) compared for content gaps. Nothing was changed.', $res['links'], $res['inbound'], $res['related'] ) );
        self::back( $post->ID );
    }

    private static function analyse_site( WP_Post $post ) {
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
        $idx     = SEO_Pilot_Audit_Runner::index( true );
        $targets = array();
        foreach ( $idx['sketch'] as $id => $o ) {
            if ( (int) $id === (int) $post->ID || SEO_Pilot_Compat::robots_noindex( $id )['noindex'] ) { continue; }
            $set = SEO_Pilot_Audit_Runner::page_settings( $id );
            $own = (string) get_post_meta( $id, SEO_Pilot_Audit_Runner::META_PRIMARY, true );
            $targets[] = array( 'id' => (int) $id, 'title' => $o['title'], 'url' => get_permalink( $id ), 'primary' => $own, 'secondary' => $set['secondary'] );
        }
        $hash     = sha1( (string) $post->post_content );
        $segments = SEO_Pilot_Parser::paragraph_segments( $post->post_content );
        $sug      = SEO_Pilot_Links::suggest( $segments, SEO_Pilot_Parser::hrefs( $post->post_content ), $targets, 5 );
        SEO_Pilot_Recs::supersede( $post->ID, array( 'internal_link', 'inbound_links', 'site_gap' ) );
        foreach ( $sug as $s ) {
            SEO_Pilot_Recs::insert( array(
                'post_id' => $post->ID, 'rec_type' => 'internal_link', 'target' => 'seg:' . $s['segment_hash'], 'original' => $s['original'], 'proposed' => $s['proposed'],
                'rationale' => 'The text already says "' . $s['phrase'] . '", which names your page "' . $s['target_title'] . '". Linking it helps readers find that page.',
                'flags' => array( 'verify' => array(), 'notes' => array( 'Check that the linked page is what a reader of this sentence would want next.' ), 'url' => $s['url'], 'target_id' => $s['target_id'], 'target_title' => $s['target_title'], 'phrase' => $s['phrase'] ),
                'source' => 'local', 'status' => 'pending', 'base_hash' => $hash,
            ) );
        }
        // Pages whose text already mentions this page by name but that do not link to it.
        $mine    = array( 'id' => $post->ID, 'title' => wp_strip_all_tags( get_the_title( $post ) ), 'primary' => (string) get_post_meta( $post->ID, SEO_Pilot_Audit_Runner::META_PRIMARY, true ), 'secondary' => SEO_Pilot_Audit_Runner::page_settings( $post->ID )['secondary'] );
        $phrases = SEO_Pilot_Links::phrases_for( $mine );
        $my_path = trim( preg_replace( '#^https?://[^/]+#i', '', (string) get_permalink( $post ) ), '/' );
        $inbound = array();
        foreach ( $targets as $t ) {
            if ( count( $inbound ) >= 10 ) { break; }
            $c = (string) get_post_field( 'post_content', $t['id'] );
            if ( '' !== $my_path && false !== stripos( $c, $my_path ) ) { continue; }
            $plain = SEO_Pilot_Text::plain( $c );
            foreach ( $phrases as $ph ) {
                if ( SEO_Pilot_Text::has_phrase( $plain, $ph ) ) { $inbound[] = array( 'id' => $t['id'], 'title' => $t['title'], 'phrase' => $ph ); break; }
            }
        }
        if ( $inbound ) {
            SEO_Pilot_Recs::insert( array( 'post_id' => $post->ID, 'rec_type' => 'inbound_links', 'proposed' => wp_json_encode( array( 'pages' => $inbound ) ),
                'rationale' => 'These pages already mention this page by name or keyword but do not link to it. Open each one and decide whether a link helps its readers.', 'source' => 'local', 'status' => 'pending', 'base_hash' => $hash ) );
        }
        // Content gap against closely related pages of this site.
        $plain  = SEO_Pilot_Text::plain( $post->post_content );
        $others = array();
        foreach ( array_slice( array_keys( $idx['sketch'] ), 0, 400 ) as $id ) {
            if ( (int) $id !== (int) $post->ID ) { $others[ $id ] = SEO_Pilot_Text::plain( (string) get_post_field( 'post_content', $id ) ); }
        }
        $related = SEO_Pilot_Gap::related( $plain, $others, 5, 0.15 );
        if ( $related ) {
            $terms = SEO_Pilot_Gap::missing_terms( $plain, array_values( array_intersect_key( $others, $related ) ), 10 );
            if ( $terms ) {
                $rel = array();
                foreach ( $related as $id => $score ) { $rel[] = array( 'id' => (int) $id, 'title' => $idx['sketch'][ $id ]['title'], 'score' => $score ); }
                SEO_Pilot_Recs::insert( array( 'post_id' => $post->ID, 'rec_type' => 'site_gap', 'proposed' => wp_json_encode( array( 'terms' => $terms, 'related' => $rel ) ),
                    'rationale' => 'Words that closely related pages on your own site use but this page does not mention. This compares only your own content; it says nothing about what searchers want or what competitors cover.', 'source' => 'local', 'status' => 'pending', 'base_hash' => $hash ) );
            }
        }
        return array( 'links' => count( $sug ), 'inbound' => count( $inbound ), 'related' => count( $related ) );
    }

    /* ----------------------------------------------------- AI orchestration */

    private static function clean_proposed( array $rec, $text ) {
        $type = $rec['rec_type'];
        if ( 'seo_title' === $type || 'meta_description' === $type || 'alt_text' === $type ) {
            $max = 'seo_title' === $type ? 200 : ( 'meta_description' === $type ? 400 : 300 );
            $t   = sanitize_text_field( $text );
            if ( '' === $t ) { return new WP_Error( 'seo_pilot_empty', 'The text cannot be empty.' ); }
            if ( SEO_Pilot_Text::len( $t ) > $max ) { return new WP_Error( 'seo_pilot_long', 'That text is too long.' ); }
            return $t;
        }
        if ( 'internal_link' === $type ) {
            $url  = isset( $rec['flags']['url'] ) ? (string) $rec['flags']['url'] : '';
            $html = SEO_Pilot_Validator::clean_inline( $text );
            list( $html ) = SEO_Pilot_Validator::strip_new_links( $html, $rec['original'], array( $url ) );
            if ( '' === $url || ! in_array( $url, SEO_Pilot_Parser::hrefs( $html ), true ) ) { return new WP_Error( 'seo_pilot_link', 'The approved link must stay in the paragraph. To drop it, reject the suggestion instead.' ); }
            if ( SEO_Pilot_Text::len( SEO_Pilot_Text::plain( $html ) ) > SEO_Pilot_Text::len( SEO_Pilot_Text::plain( $rec['original'] ) ) * 2 + 200 ) { return new WP_Error( 'seo_pilot_long', 'That paragraph is much longer than the original.' ); }
            return $html;
        }
        if ( 'rewrite' === $type ) {
            $html = SEO_Pilot_Validator::clean_inline( $text );
            list( $html ) = SEO_Pilot_Validator::strip_new_links( $html, $rec['original'] );
            $plain = SEO_Pilot_Text::plain( $html );
            if ( '' === $plain ) { return new WP_Error( 'seo_pilot_empty', 'The paragraph cannot be empty.' ); }
            if ( SEO_Pilot_Text::len( $plain ) > SEO_Pilot_Text::len( SEO_Pilot_Text::plain( $rec['original'] ) ) * 3 + 400 ) { return new WP_Error( 'seo_pilot_long', 'That paragraph is much longer than the original.' ); }
            return $html;
        }
        return new WP_Error( 'seo_pilot_type', 'This item is advisory and cannot be edited.' );
    }

    /** Context shared by the prompt builder and the "what will be sent" preview. */
    public static function prompt_context( WP_Post $post, array $audit, $instruction = '' ) {
        $set      = SEO_Pilot_Audit_Runner::page_settings( $post->ID );
        $st       = SEO_Pilot_Compat::get( $post->ID, 'seo_title' );
        $md       = SEO_Pilot_Compat::get( $post->ID, 'meta_description' );
        $parsed   = SEO_Pilot_Parser::parse( $post->post_content );
        $findings = array();
        foreach ( $audit['findings'] as $f ) { if ( 'info' !== $f['severity'] ) { $findings[] = $f['what']; } }
        $path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
        $q    = (string) wp_parse_url( get_permalink( $post ), PHP_URL_QUERY );
        return array(
            'title' => wp_strip_all_tags( get_the_title( $post ) ), 'url_path' => $path . ( $q ? '?' . $q : '' ), 'post_type' => $post->post_type,
            'language' => $audit['language'], 'seo_title' => $st['value'], 'meta_description' => $md['value'],
            'primary' => $set['primary'], 'secondary' => $set['secondary'], 'intent_label' => $audit['intent']['label'], 'intent_source' => $audit['intent']['source'],
            'audience' => $set['audience'], 'headings' => $parsed['headings'], 'segments' => SEO_Pilot_Parser::paragraph_segments( $post->post_content ),
            'findings' => $findings, 'instruction' => $instruction,
            'explain_lang' => 0 === strpos( determine_locale(), 'ar' ) ? 'ar' : 'en',
        );
    }

    /** @return array|WP_Error created, warnings, truncated_input, truncated_output */
    private static function generate( WP_Post $post, array $tasks, $instruction = '', $only_hash = null ) {
        $problem = SEO_Pilot_Settings::configuration_problem();
        if ( '' !== $problem ) { return new WP_Error( 'seo_pilot_ai_not_configured', $problem ); }
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( max( 60, (int) SEO_Pilot_Settings::get( 'timeout', 45 ) + 20 ) ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions

        $audit = SEO_Pilot_Audit_Runner::audit_post( $post );
        $ctx   = self::prompt_context( $post, $audit, $instruction );
        $opts  = array();
        if ( $only_hash ) {
            $found = '';
            foreach ( $ctx['segments'] as $s ) { if ( $s['hash'] === $only_hash ) { $found = $s['id']; } }
            if ( '' === $found ) { return new WP_Error( 'seo_pilot_stale', 'That paragraph has changed since the suggestion was made, so it cannot be regenerated. Reject it and generate fresh suggestions.' ); }
            $opts['only_segments'] = array( $found );
        }
        $built = SEO_Pilot_Prompt::build( $ctx, $tasks, $opts );
        $resp  = SEO_Pilot_AI_Client::chat( $built['messages'] );
        if ( is_wp_error( $resp ) ) { return $resp; }

        $segmap = array();
        foreach ( $ctx['segments'] as $s ) { if ( in_array( $s['id'], $built['included_segments'], true ) ) { $segmap[ $s['id'] ] = $s; } }
        $parsed = SEO_Pilot_Validator::parse( $resp['content'], array(
            'tasks' => $tasks, 'segments' => $segmap, 'content_text' => SEO_Pilot_Text::plain( $post->post_content ),
            'seo_title' => $ctx['seo_title'], 'meta_description' => $ctx['meta_description'], 'title' => $ctx['title'],
        ) );
        if ( is_wp_error( $parsed ) ) {
            return $resp['truncated'] ? new WP_Error( $parsed->get_error_code(), $parsed->get_error_message() . ' The provider cut the answer off, so choose fewer tasks or raise the model\'s output limit.' ) : $parsed;
        }

        if ( $only_hash ) {
            SEO_Pilot_Recs::supersede( $post->ID, array( 'rewrite' ), 'seg:' . $only_hash );
        } else {
            SEO_Pilot_Recs::supersede( $post->ID, array_map( function ( $t ) { return 'rewrite' === $t ? 'rewrite' : $t; }, $tasks ) );
        }
        $batch = SEO_Pilot_Util::uuid();
        $hash  = sha1( (string) $post->post_content );
        $n     = 0;
        foreach ( $parsed['recs'] as $r ) {
            $r['post_id'] = $post->ID; $r['batch_id'] = $batch; $r['base_hash'] = $hash; $r['source'] = 'ai'; $r['status'] = 'pending';
            if ( SEO_Pilot_Recs::insert( $r ) ) { $n++; }
        }
        return array( 'created' => $n, 'warnings' => $parsed['warnings'], 'truncated_input' => $built['truncated'], 'truncated_output' => $resp['truncated'] );
    }
}
