<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-wide rolling-window limit on AI requests (default 20 per hour). Every attempt counts,
 * including failed ones, so a misconfigured endpoint cannot be hammered.
 */
final class SEO_Pilot_Rate_Limiter {
    const KEY    = 'seo_pilot_rl';
    const WINDOW = 3600;

    private static function log() {
        $log = get_transient( self::KEY );
        if ( ! is_array( $log ) ) { $log = array(); }
        $cut = time() - self::WINDOW;
        $log = array_values( array_filter( $log, function ( $t ) use ( $cut ) { return (int) $t > $cut; } ) );
        sort( $log );
        return $log;
    }

    /** @return true|WP_Error */
    public static function check() {
        $limit = (int) SEO_Pilot_Settings::get( 'rate_limit', 20 );
        $log   = self::log();
        if ( count( $log ) >= $limit ) {
            $wait = max( 1, (int) ceil( ( $log[0] + self::WINDOW - time() ) / 60 ) );
            return new WP_Error( 'seo_pilot_rate_limited', sprintf( 'SEO Pilot allows %d AI requests per hour (see Settings). Try again in about %d minute(s).', $limit, $wait ) );
        }
        return true;
    }

    public static function record() {
        $log   = self::log();
        $log[] = time();
        set_transient( self::KEY, $log, self::WINDOW );
    }

    public static function remaining() {
        return max( 0, (int) SEO_Pilot_Settings::get( 'rate_limit', 20 ) - count( self::log() ) );
    }
}
