<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AI-drafted image alt text (opt-in). It only ever proposes: drafts are stored as pending
 * recommendations and go through the same review, approval and rollback flow as every other change.
 *
 * What may leave the site, and when:
 *  - only after the editor enabled "Allow sending images" AND ticked its own confirmation in Settings;
 *  - only when the editor presses "Draft alt text with AI" on one page;
 *  - at most MAX_IMAGES images per press, each a Media Library upload that is referenced by that page,
 *    resized to MAX_EDGE px (so the original file is not sent when WordPress can resize it);
 *  - never the editor's keywords, and never an arbitrary URL: files are read from the uploads folder only.
 */
final class SEO_Pilot_Alt_AI {
    const MAX_IMAGES = 5;
    const MAX_EDGE   = 1024;
    const MAX_BYTES  = 1000000;

    /** Image rows (from the saved audit) that deserve a draft, newest data first, limited to MAX_IMAGES. */
    public static function candidates( WP_Post $post, $audit ) {
        $rows = ( is_array( $audit ) && ! empty( $audit['image_analysis']['items'] ) ) ? $audit['image_analysis']['items'] : array();
        $taken = array();
        foreach ( SEO_Pilot_Recs::for_post( $post->ID, array( 'pending', 'accepted' ) ) as $r ) { if ( 'alt_text' === $r['rec_type'] ) { $taken[ $r['target'] ] = true; } }
        $out = array();
        foreach ( $rows as $it ) {
            if ( empty( $it['attachment_id'] ) || isset( $taken[ 'img:' . $it['hash'] ] ) ) { continue; }
            // Still referenced by the current content?
            if ( false === strpos( (string) $post->post_content, 'wp-image-' . (int) $it['attachment_id'] ) ) { continue; }
            $poor = false;
            foreach ( $it['issues'] as $is ) { if ( in_array( $is['id'], array( 'filename', 'generic' ), true ) ) { $poor = true; } }
            if ( in_array( $it['kind'], array( 'missing', 'empty_in_link' ), true ) || $poor ) { $out[] = $it; }
            if ( count( $out ) >= self::MAX_IMAGES ) { break; }
        }
        return $out;
    }

    /**
     * Resized copy of a Media Library image as a data URL.
     *
     * @return string|WP_Error
     */
    public static function prepare_image( $attachment_id ) {
        $id   = (int) $attachment_id;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'attachment' !== $post->post_type ) { return new WP_Error( 'seo_pilot_alt_attachment', 'The image is not in the Media Library.' ); }
        $mime = (string) get_post_mime_type( $id );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
            return new WP_Error( 'seo_pilot_alt_type', 'Only JPEG, PNG, WebP and GIF images can be sent.' );
        }
        $file = get_attached_file( $id );
        $real = $file ? realpath( $file ) : false;
        $up   = wp_upload_dir();
        $base = ! empty( $up['basedir'] ) ? realpath( $up['basedir'] ) : false;
        if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) ) {
            return new WP_Error( 'seo_pilot_alt_file', 'The image file could not be read from the uploads folder.' );
        }

        $bytes = false; $out_mime = $mime;
        $editor = wp_get_image_editor( $real );
        if ( ! is_wp_error( $editor ) ) {
            $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
            $tmp = wp_tempnam( 'seo-pilot-alt' );
            if ( $tmp ) {
                $dest  = $tmp . '.jpg';
                $saved = $editor->save( $dest, 'image/jpeg' );
                if ( ! is_wp_error( $saved ) && is_readable( $dest ) ) { $bytes = file_get_contents( $dest ); $out_mime = 'image/jpeg'; }
                if ( file_exists( $dest ) ) { wp_delete_file( $dest ); }
                wp_delete_file( $tmp );
            }
        }
        if ( false === $bytes || '' === $bytes ) {
            if ( filesize( $real ) > self::MAX_BYTES ) { return new WP_Error( 'seo_pilot_alt_big', 'The image is too large to send and could not be resized on this server.' ); }
            $bytes = file_get_contents( $real );
        }
        if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES ) { return new WP_Error( 'seo_pilot_alt_big', 'The image is too large to send.' ); }
        return 'data:' . $out_mime . ';base64,' . base64_encode( $bytes );
    }

    /** @return array|WP_Error created, warnings */
    public static function draft( WP_Post $post, $audit ) {
        $problem = SEO_Pilot_Settings::images_problem();
        if ( '' !== $problem ) { return new WP_Error( 'seo_pilot_alt_not_enabled', $problem ); }
        if ( ! is_array( $audit ) ) { return new WP_Error( 'seo_pilot_alt_no_audit', 'Run the audit for this page first.' ); }
        $cands = self::candidates( $post, $audit );
        if ( ! $cands ) { return new WP_Error( 'seo_pilot_alt_none', 'No image on this page needs a draft: images without a usable alt text must come from your Media Library and not already have a pending or accepted entry.' ); }
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( max( 90, (int) SEO_Pilot_Settings::get( 'timeout', 45 ) + 30 ) ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions

        $warnings = array(); $images = array(); $map = array(); $n = 0;
        foreach ( $cands as $it ) {
            $data = self::prepare_image( $it['attachment_id'] );
            if ( is_wp_error( $data ) ) { $warnings[] = SEO_Pilot_Images::label( $it['src'] ) . ' was skipped: ' . $data->get_error_message(); continue; }
            $n++;
            $id = 'I' . $n;
            $images[ $id ] = array(
                'id' => $id, 'data_url' => $data, 'context' => $it['context'], 'caption' => $it['caption'], 'in_link' => $it['in_link'],
                'link_text' => $it['link_text'], 'current_alt' => $it['alt'],
            );
            $map[ $id ] = $it;
        }
        if ( ! $images ) { return new WP_Error( 'seo_pilot_alt_nothing', 'None of the images could be prepared. ' . implode( ' ', $warnings ) ); }

        $li    = SEO_Pilot_Lang::for_post( $post->ID );
        $built = SEO_Pilot_Prompt::build_alt( array(
            'page_title' => wp_strip_all_tags( get_the_title( $post ) ), 'language' => isset( $audit['language'] ) ? $audit['language'] : 'unknown',
            'lang_name' => $li['declared'] ? $li['label'] . ' (' . $li['code'] . ')' : '',
            'explain_lang' => SEO_Pilot_Hreflang::lang_part( determine_locale() ),
        ), $images );
        $resp = SEO_Pilot_AI_Client::chat( $built['messages'] );
        if ( is_wp_error( $resp ) ) {
            $msg = $resp->get_error_message();
            if ( 'seo_pilot_ai_http' === $resp->get_error_code() || 'seo_pilot_ai_not_found' === $resp->get_error_code() ) { $msg .= ' If the model does not accept images, choose a vision-capable model in Settings.'; }
            return new WP_Error( $resp->get_error_code(), $msg );
        }
        $parsed = SEO_Pilot_Validator::parse_alt( $resp['content'], array( 'images' => $map, 'page_title' => wp_strip_all_tags( get_the_title( $post ) ), 'context_text' => self::context_text( $map, $post ),
            'expected_script' => $li['declared'] ? SEO_Pilot_Hreflang::script_class( $li['code'] ) : '', 'lang_name' => $li['declared'] ? $li['label'] : '' ) );
        if ( is_wp_error( $parsed ) ) { return $parsed; }

        $batch = SEO_Pilot_Util::uuid();
        $hash  = sha1( (string) $post->post_content );
        $made  = 0;
        foreach ( $parsed['recs'] as $r ) {
            SEO_Pilot_Recs::supersede( $post->ID, array( 'alt_text' ), $r['target'] );
            $r['post_id'] = $post->ID; $r['batch_id'] = $batch; $r['base_hash'] = $hash; $r['source'] = 'ai'; $r['status'] = 'pending';
            if ( SEO_Pilot_Recs::insert( $r ) ) { $made++; }
        }
        return array( 'created' => $made, 'warnings' => array_merge( $warnings, $parsed['warnings'] ) );
    }

    /** Text the model was given about the images, used to spot numbers it might have invented. */
    private static function context_text( array $map, WP_Post $post ) {
        $t = wp_strip_all_tags( get_the_title( $post ) );
        foreach ( $map as $it ) { $t .= ' ' . $it['context'] . ' ' . $it['caption'] . ' ' . $it['link_text'] . ' ' . $it['alt']; }
        return $t;
    }
}
