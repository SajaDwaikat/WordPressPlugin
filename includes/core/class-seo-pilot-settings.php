<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin settings. The API key is kept out of the main settings array, is never
 * autoloaded, is encrypted at rest when libsodium is available, and can be supplied
 * through the SEO_PILOT_API_KEY constant instead of the database.
 */
final class SEO_Pilot_Settings {
    const OPTION        = 'seo_pilot_settings';
    const SECRET_OPTION = 'seo_pilot_secret';

    public static function defaults() {
        return array(
            'provider'            => 'openai_compatible',
            'endpoint'            => '',
            'model'               => '',
            'max_chars'           => 6000,
            'rate_limit'          => 20,
            'timeout'             => 45,
            'send_headings'       => 1,
            'ack'                 => 0,
            'ack_user'            => 0,
            'ack_time'            => '',
            // Sending images to the provider is a separate, opt-in decision from sending text.
            'send_images'         => 0,
            'ack_images'          => 0,
            'ack_images_user'     => 0,
            'ack_images_time'     => '',
            // Multilingual sites: the languages the site uses, which one is the default, and what SEO Pilot may print.
            'languages'           => array(),
            'default_lang'        => '',
            'hreflang_output'     => 0,
            'html_lang'           => 0,
            'delete_on_uninstall' => 0,
        );
    }

    /**
     * Rows from the Languages table, cleaned: code (normalised, unique), label, note. At most 12.
     *
     * @return array[]
     */
    public static function clean_languages( $rows ) {
        $out = array(); $seen = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $code = SEO_Pilot_Hreflang::normalize_code( isset( $row['code'] ) ? (string) $row['code'] : '' );
            if ( '' === $code || 'x-default' === $code || isset( $seen[ $code ] ) ) { continue; }
            $seen[ $code ] = true;
            $out[] = array(
                'code'  => $code,
                'label' => SEO_Pilot_Text::sub( sanitize_text_field( isset( $row['label'] ) ? (string) $row['label'] : '' ), 0, 60 ),
                'note'  => SEO_Pilot_Text::sub( sanitize_text_field( isset( $row['note'] ) ? (string) $row['note'] : '' ), 0, 200 ),
            );
            if ( count( $out ) >= 12 ) { break; }
        }
        return $out;
    }

    public static function all() {
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        $s = array_merge( self::defaults(), $saved );
        $s['max_chars']  = max( 1000, min( 12000, (int) $s['max_chars'] ) );
        $s['rate_limit'] = max( 1, min( 200, (int) $s['rate_limit'] ) );
        $s['timeout']    = max( 10, min( 120, (int) $s['timeout'] ) );
        return $s;
    }

    public static function get( $key, $default = null ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /* ---------------------------------------------------------------- API key */

    public static function key_source() {
        if ( defined( 'SEO_PILOT_API_KEY' ) && '' !== trim( (string) SEO_PILOT_API_KEY ) ) { return 'constant'; }
        return '' !== self::decrypt( (string) get_option( self::SECRET_OPTION, '' ) ) ? 'database' : 'none';
    }

    public static function api_key() {
        if ( defined( 'SEO_PILOT_API_KEY' ) && '' !== trim( (string) SEO_PILOT_API_KEY ) ) {
            return trim( (string) SEO_PILOT_API_KEY );
        }
        return self::decrypt( (string) get_option( self::SECRET_OPTION, '' ) );
    }

    public static function has_key() {
        return '' !== self::api_key();
    }

    public static function store_key( $plain ) {
        $plain = trim( (string) $plain );
        if ( '' === $plain ) {
            delete_option( self::SECRET_OPTION );
            return;
        }
        // autoload = false so the secret is not loaded on every request.
        update_option( self::SECRET_OPTION, self::encrypt( $plain ), false );
    }

    private static function crypto_key() {
        return substr( hash( 'sha256', wp_salt( 'auth' ) . '|seo-pilot-secret', true ), 0, 32 );
    }

    public static function encrypt( $plain ) {
        if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
            $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plain, $nonce, self::crypto_key() );
            return 'v1:' . base64_encode( $nonce . $cipher );
        }
        return 'plain:' . base64_encode( $plain );
    }

    public static function decrypt( $stored ) {
        $stored = (string) $stored;
        if ( '' === $stored ) { return ''; }
        if ( 0 === strpos( $stored, 'plain:' ) ) {
            $out = base64_decode( substr( $stored, 6 ), true );
            return false === $out ? '' : $out;
        }
        if ( 0 === strpos( $stored, 'v1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $raw = base64_decode( substr( $stored, 3 ), true );
            if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return ''; }
            $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::crypto_key() );
            return false === $plain ? '' : $plain;
        }
        return '';
    }

    /* --------------------------------------------------------------- endpoint */

    /**
     * Endpoint must be HTTPS. Plain HTTP is only accepted for local model servers.
     *
     * @return array [ok(bool), normalized(string), error(string)]
     */
    public static function validate_endpoint( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) { return array( true, '', '' ); }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return array( false, '', 'The API base URL is not a valid URL.' );
        }
        $scheme = strtolower( $parts['scheme'] );
        $host   = strtolower( $parts['host'] );
        $local  = in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
        if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local ) ) {
            return array( false, '', 'The API base URL must use https:// (http:// is only allowed for localhost).' );
        }
        if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
            return array( false, '', 'Do not put credentials in the API base URL.' );
        }
        return array( true, esc_url_raw( $url ), '' );
    }

    public static function provider_host() {
        $parts = wp_parse_url( (string) self::get( 'endpoint', '' ) );
        return ! empty( $parts['host'] ) ? $parts['host'] : '';
    }

    /** Everything needed before any content may leave the site. */
    public static function configuration_problem() {
        $s = self::all();
        if ( '' === $s['endpoint'] || '' === $s['model'] || ! self::has_key() ) {
            return 'Configure the AI endpoint, model, and API key in SEO Pilot → Settings first.';
        }
        if ( empty( $s['ack'] ) ) {
            return 'Confirm in SEO Pilot → Settings that you understand page content is sent to the AI provider.';
        }
        return '';
    }

    /** Images may be sent only when the feature is switched on AND its own confirmation was given. */
    public static function images_ready() {
        $s = self::all();
        return ! empty( $s['send_images'] ) && ! empty( $s['ack_images'] );
    }

    /** Empty string when ready; otherwise what the editor must do first (text settings must be complete too). */
    public static function images_problem() {
        $p = self::configuration_problem();
        if ( '' !== $p ) { return $p; }
        if ( ! self::images_ready() ) {
            return 'Sending images to the AI provider is switched off. To draft alt text with AI, enable it and confirm in SEO Pilot → Settings → Images.';
        }
        return '';
    }

    /* ------------------------------------------------------------ sanitizing */

    /** register_setting() callback. Must be idempotent: WordPress can call it twice on first save. */
    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $old   = self::all();
        $clean = self::defaults();

        $endpoint_in = isset( $input['endpoint'] ) ? $input['endpoint'] : $old['endpoint'];
        list( $ok, $normalized, $error ) = self::validate_endpoint( $endpoint_in );
        if ( $ok ) {
            $clean['endpoint'] = $normalized;
        } else {
            $clean['endpoint'] = $old['endpoint'];
            add_settings_error( self::OPTION, 'seo_pilot_endpoint', $error, 'error' );
        }

        $clean['model']         = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $old['model'];
        $clean['max_chars']     = max( 1000, min( 12000, isset( $input['max_chars'] ) ? (int) $input['max_chars'] : $old['max_chars'] ) );
        $clean['rate_limit']    = max( 1, min( 200, isset( $input['rate_limit'] ) ? (int) $input['rate_limit'] : $old['rate_limit'] ) );
        $clean['timeout']       = max( 10, min( 120, isset( $input['timeout'] ) ? (int) $input['timeout'] : $old['timeout'] ) );
        $clean['send_headings'] = ! empty( $input['send_headings'] ) ? 1 : 0;
        $clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] ) ? 1 : 0;

        if ( ! empty( $input['ack'] ) ) {
            $clean['ack'] = 1;
            if ( ! empty( $old['ack'] ) ) {
                $clean['ack_user'] = (int) $old['ack_user'];
                $clean['ack_time'] = $old['ack_time'];
            } else {
                $clean['ack_user'] = get_current_user_id();
                $clean['ack_time'] = current_time( 'mysql' );
            }
        }

        // Languages. A form without the table leaves the saved languages as they were.
        $clean['languages'] = array_key_exists( 'languages', $input ) ? self::clean_languages( $input['languages'] ) : ( isset( $old['languages'] ) ? self::clean_languages( $old['languages'] ) : array() );
        $dl = array_key_exists( 'languages', $input ) ? SEO_Pilot_Hreflang::normalize_code( isset( $input['default_lang'] ) ? (string) $input['default_lang'] : '' ) : SEO_Pilot_Hreflang::normalize_code( isset( $old['default_lang'] ) ? (string) $old['default_lang'] : '' );
        $codes = array_map( function ( $r ) { return $r['code']; }, $clean['languages'] );
        $clean['default_lang']    = in_array( $dl, $codes, true ) ? $dl : '';
        $clean['hreflang_output'] = array_key_exists( 'languages', $input ) ? ( ! empty( $input['hreflang_output'] ) ? 1 : 0 ) : ( ! empty( $old['hreflang_output'] ) ? 1 : 0 );
        $clean['html_lang']       = array_key_exists( 'languages', $input ) ? ( ! empty( $input['html_lang'] ) ? 1 : 0 ) : ( ! empty( $old['html_lang'] ) ? 1 : 0 );

        $clean['send_images'] = ! empty( $input['send_images'] ) ? 1 : 0;
        if ( ! empty( $input['ack_images'] ) ) {
            $clean['ack_images'] = 1;
            if ( ! empty( $old['ack_images'] ) ) {
                $clean['ack_images_user'] = (int) $old['ack_images_user'];
                $clean['ack_images_time'] = $old['ack_images_time'];
            } else {
                $clean['ack_images_user'] = get_current_user_id();
                $clean['ack_images_time'] = current_time( 'mysql' );
            }
        }

        if ( ! empty( $input['clear_api_key'] ) ) {
            self::store_key( '' );
        } elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
            self::store_key( sanitize_text_field( (string) $input['api_key'] ) );
        }
        return $clean;
    }
}
