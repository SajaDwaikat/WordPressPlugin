<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Small shared helpers. Nothing here talks to the AI provider.
 */
final class SEO_Pilot_Util {

    /** Remove anything that looks like a credential from a string before it is shown or logged. */
    public static function redact( $text ) {
        $text = (string) $text;
        $text = preg_replace( '/Bearer\s+[A-Za-z0-9._\-~+\/=]+/i', 'Bearer [redacted]', $text );
        $text = preg_replace( '/\b(sk|pk|key|api)[-_][A-Za-z0-9_\-]{12,}/i', '[redacted]', $text );
        if ( class_exists( 'SEO_Pilot_Settings' ) ) {
            $key = SEO_Pilot_Settings::api_key();
            if ( '' !== $key && strlen( $key ) >= 8 ) {
                $text = str_replace( $key, '[redacted]', $text );
            }
        }
        return $text;
    }

    /** Debug logging. Only writes when WP_DEBUG_LOG is on, and always redacts. */
    public static function log( $message ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'SEO Pilot: ' . self::redact( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /** Store a one-time notice for the current user (shown on the next admin page load). */
    public static function flash( $type, $message ) {
        $uid = get_current_user_id();
        if ( ! $uid ) { return; }
        set_transient( 'seo_pilot_flash_' . $uid, array( 'type' => $type, 'message' => (string) $message ), 120 );
    }

    public static function take_flash() {
        $uid = get_current_user_id();
        if ( ! $uid ) { return null; }
        $flash = get_transient( 'seo_pilot_flash_' . $uid );
        if ( $flash ) { delete_transient( 'seo_pilot_flash_' . $uid ); }
        return is_array( $flash ) ? $flash : null;
    }

    public static function render_flash() {
        $flash = self::take_flash();
        if ( ! $flash ) { return; }
        $allowed = array( 'success', 'error', 'warning', 'info' );
        $type    = in_array( $flash['type'], $allowed, true ) ? $flash['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
    }

    /** Redirect back to an SEO Pilot admin page and stop. */
    public static function redirect( $page, array $args = array() ) {
        $args['page'] = $page;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** update_post_meta() un-slashes its input, so JSON/text containing backslashes must be slashed first. */
    public static function save_meta( $post_id, $key, $value ) {
        return update_post_meta( $post_id, $key, wp_slash( $value ) );
    }

    public static function save_json_meta( $post_id, $key, $data ) {
        return self::save_meta( $post_id, $key, wp_json_encode( $data ) );
    }

    public static function get_json_meta( $post_id, $key ) {
        $raw = get_post_meta( $post_id, $key, true );
        if ( ! is_string( $raw ) || '' === $raw ) { return null; }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    public static function now() {
        return current_time( 'mysql' );
    }

    public static function uuid() {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sha1( uniqid( '', true ) );
    }

    /** Post statuses that are (or will become) visible to visitors. Changing these needs explicit confirmation. */
    public static function is_live_status( $status ) {
        return in_array( $status, array( 'publish', 'private', 'future' ), true );
    }

    /** Post types the Content SEO module works with. */
    public static function content_post_types() {
        return apply_filters( 'seo_pilot_content_post_types', array( 'post', 'page' ) );
    }

    /** Statuses listed in the Content SEO module. */
    public static function content_statuses() {
        return array( 'publish', 'draft', 'pending', 'future', 'private' );
    }

    public static function capability() {
        return apply_filters( 'seo_pilot_capability', 'manage_options' );
    }
}
