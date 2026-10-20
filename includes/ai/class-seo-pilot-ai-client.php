<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Server-side client for an OpenAI-compatible Chat Completions endpoint.
 * The API key only ever appears in the Authorization header of the outgoing request:
 * never in HTML, browser code, logs or error messages.
 */
final class SEO_Pilot_AI_Client {
    const MAX_RESPONSE_BYTES = 2097152; // 2 MB

    /**
     * @param array[] $messages Chat messages (role/content).
     * @return array|WP_Error [ content, truncated ]
     */
    public static function chat( array $messages ) {
        $problem = SEO_Pilot_Settings::configuration_problem();
        if ( '' !== $problem ) { return new WP_Error( 'seo_pilot_ai_not_configured', $problem ); }

        $s = SEO_Pilot_Settings::all();
        list( $ok, $normalized, $error ) = SEO_Pilot_Settings::validate_endpoint( $s['endpoint'] );
        if ( ! $ok || '' === $normalized ) { return new WP_Error( 'seo_pilot_ai_bad_endpoint', $error ? $error : 'The AI endpoint is not valid.' ); }

        $limit = SEO_Pilot_Rate_Limiter::check();
        if ( is_wp_error( $limit ) ) { return $limit; }

        $url     = trailingslashit( $normalized ) . 'chat/completions';
        $payload = array( 'model' => $s['model'], 'temperature' => 0.3, 'messages' => $messages );

        $result = self::post( $url, $payload, $s );
        // Some newer models only accept the default temperature: retry once without it.
        if ( is_wp_error( $result ) && 'seo_pilot_ai_temperature' === $result->get_error_code() ) {
            unset( $payload['temperature'] );
            $result = self::post( $url, $payload, $s );
        }
        return $result;
    }

    private static function post( $url, array $payload, array $s ) {
        SEO_Pilot_Rate_Limiter::record();
        $response = wp_remote_post( $url, array(
            'timeout'             => (int) $s['timeout'],
            'redirection'         => 0,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers'             => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . SEO_Pilot_Settings::api_key(),
            ),
            'body'                => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = SEO_Pilot_Util::redact( $response->get_error_message() );
            SEO_Pilot_Util::log( 'AI request failed: ' . $msg );
            return new WP_Error( 'seo_pilot_ai_request_failed', 'Could not reach the AI provider: ' . $msg . ' Check the API base URL, your server\'s outbound connections, and the timeout in Settings.' );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $detail = '';
        if ( is_array( $body ) && isset( $body['error'] ) ) {
            $e      = $body['error'];
            $detail = is_array( $e ) && isset( $e['message'] ) ? (string) $e['message'] : ( is_string( $e ) ? $e : '' );
            $detail = SEO_Pilot_Text::sub( SEO_Pilot_Util::redact( wp_strip_all_tags( $detail ) ), 0, 200 );
        }

        if ( $status >= 200 && $status < 300 ) {
            $content = ( is_array( $body ) && isset( $body['choices'][0]['message']['content'] ) && is_string( $body['choices'][0]['message']['content'] ) ) ? trim( $body['choices'][0]['message']['content'] ) : '';
            if ( '' === $content ) {
                return new WP_Error( 'seo_pilot_ai_empty', 'The AI provider returned an empty answer. Try again, or check that the model supports chat completions.' );
            }
            $finish = isset( $body['choices'][0]['finish_reason'] ) ? (string) $body['choices'][0]['finish_reason'] : '';
            return array( 'content' => $content, 'truncated' => 'length' === $finish );
        }

        SEO_Pilot_Util::log( 'AI provider HTTP ' . $status . ( $detail ? ': ' . $detail : '' ) );
        $suffix = $detail ? ' Provider message: ' . $detail : '';
        if ( 400 === $status && false !== stripos( $detail, 'temperature' ) ) {
            return new WP_Error( 'seo_pilot_ai_temperature', $detail );
        }
        switch ( true ) {
            case 401 === $status || 403 === $status:
                return new WP_Error( 'seo_pilot_ai_auth', 'The AI provider rejected the request (HTTP ' . $status . '). Check the API key and that it can use this model.' . $suffix );
            case 404 === $status:
                return new WP_Error( 'seo_pilot_ai_not_found', 'The AI provider could not find the endpoint or model (HTTP 404). Check the API base URL (usually ends in /v1/) and the model name.' . $suffix );
            case 429 === $status:
                return new WP_Error( 'seo_pilot_ai_provider_limit', 'The AI provider says the rate limit or quota was reached (HTTP 429). Wait a while or check your plan.' . $suffix );
            case $status >= 500:
                return new WP_Error( 'seo_pilot_ai_provider_error', 'The AI provider had a problem (HTTP ' . $status . '). Try again shortly.' . $suffix );
            default:
                return new WP_Error( 'seo_pilot_ai_http', 'The AI provider returned HTTP ' . $status . '.' . $suffix );
        }
    }
}
