<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only check of what visitors' browsers actually receive: title tags, meta descriptions,
 * canonical, robots meta and H1 count. It never modifies anything, and only requests URLs on
 * this site's own host.
 */
final class SEO_Pilot_Live_Check {
    const MAX_BYTES = 2097152;

    /** Parse rendered HTML. Pure function, usable without WordPress. */
    public static function parse( $html ) {
        $out = array( 'titles' => array(), 'descriptions' => array(), 'canonicals' => array(), 'robots' => array(), 'h1_count' => 0, 'jsonld_count' => 0 );
        $html = (string) $html;
        if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) { return $out; }
        $prev = libxml_use_internal_errors( true );
        $dom  = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $xp    = new DOMXPath( $dom );
        $lower = "translate(%s,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";

        foreach ( $xp->query( '//head//title' ) as $n ) { $out['titles'][] = trim( SEO_Pilot_Text::ws( $n->textContent ) ); }
        foreach ( $xp->query( '//meta[' . sprintf( $lower, '@name' ) . "='description']" ) as $n ) { $out['descriptions'][] = trim( SEO_Pilot_Text::ws( (string) $n->getAttribute( 'content' ) ) ); }
        foreach ( $xp->query( '//link[' . sprintf( $lower, '@rel' ) . "='canonical']" ) as $n ) { $out['canonicals'][] = trim( (string) $n->getAttribute( 'href' ) ); }
        foreach ( $xp->query( '//meta[' . sprintf( $lower, '@name' ) . "='robots']" ) as $n ) { $out['robots'][] = strtolower( trim( (string) $n->getAttribute( 'content' ) ) ); }
        $out['h1_count']     = $xp->query( '//body//h1' )->length;
        $out['jsonld_count'] = $xp->query( "//script[@type='application/ld+json']" )->length;
        return $out;
    }

    /**
     * Turn parsed output into plain-language notes. Pure function.
     *
     * @param array  $live        Result of parse().
     * @param string $expect_meta The description SEO Pilot or the SEO plugin should be outputting ('' to skip).
     * @param string $expect_title The SEO title that should appear inside the <title> tag ('' to skip).
     * @return array[] severity (warn|info|ok), message
     */
    public static function notes( array $live, $expect_meta = '', $expect_title = '' ) {
        $n = array();
        $tc = count( $live['titles'] );
        if ( 0 === $tc ) { $n[] = array( 'warn', 'No <title> tag was found in the rendered page.' ); }
        elseif ( $tc > 1 ) { $n[] = array( 'warn', "$tc <title> tags were found. A page should output only one; another plugin or the theme may be adding a second." ); }
        else { $n[] = array( 'ok', 'One <title> tag: ' . $live['titles'][0] ); }

        $dc = count( $live['descriptions'] );
        if ( 0 === $dc ) { $n[] = array( 'warn', 'No meta description is output on the rendered page.' ); }
        elseif ( $dc > 1 ) { $n[] = array( 'warn', "$dc meta descriptions were found. A page should output only one; two SEO sources are probably active." ); }
        else { $n[] = array( 'ok', 'One meta description: ' . $live['descriptions'][0] ); }

        $cc = count( $live['canonicals'] );
        if ( $cc > 1 ) { $n[] = array( 'warn', "$cc canonical tags were found. SEO Pilot never changes canonicals; check which plugin or theme outputs the extra ones." ); }
        elseif ( 1 === $cc ) { $n[] = array( 'info', 'Canonical: ' . $live['canonicals'][0] ); }
        foreach ( $live['robots'] as $r ) {
            if ( false !== strpos( $r, 'noindex' ) ) { $n[] = array( 'info', 'The page outputs a robots meta tag containing "noindex" (' . $r . ').' ); }
        }
        if ( 1 !== $live['h1_count'] ) { $n[] = array( 'info', 'The rendered page has ' . $live['h1_count'] . ' H1 element(s).' ); }

        if ( '' !== $expect_meta && 1 === $dc ) {
            if ( SEO_Pilot_Text::normalize( $live['descriptions'][0] ) === SEO_Pilot_Text::normalize( $expect_meta ) ) {
                $n[] = array( 'ok', 'The live meta description matches the saved one.' );
            } else {
                $n[] = array( 'warn', 'The live meta description is different from the saved one. A cache, the theme or another plugin may be overriding it, or the SEO plugin has not refreshed yet (try updating the post once in the editor).' );
            }
        }
        if ( '' !== $expect_title && 1 === $tc ) {
            if ( false !== strpos( SEO_Pilot_Text::normalize( $live['titles'][0] ), SEO_Pilot_Text::normalize( $expect_title ) ) ) {
                $n[] = array( 'ok', 'The live <title> contains the saved SEO title.' );
            } else {
                $n[] = array( 'warn', 'The live <title> does not contain the saved SEO title. A cache, the theme or another plugin may be overriding it.' );
            }
        }
        return $n;
    }

    /** @return array|WP_Error live data plus http status */
    public static function fetch( $post ) {
        $post = get_post( $post );
        if ( ! $post ) { return new WP_Error( 'seo_pilot_live_nopost', 'Post not found.' ); }
        if ( 'publish' !== $post->post_status ) {
            return new WP_Error( 'seo_pilot_live_unpublished', 'The live check is only available for published pages.' );
        }
        $url  = get_permalink( $post );
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $site = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host || strtolower( $host ) !== strtolower( (string) $site ) ) {
            return new WP_Error( 'seo_pilot_live_host', 'The live check only requests pages on this site\'s own address.' );
        }
        $resp = wp_remote_get( $url, array(
            'timeout' => 12, 'redirection' => 3, 'limit_response_size' => self::MAX_BYTES,
            'user-agent' => 'SEO-Pilot/' . SEO_PILOT_VERSION . '; ' . home_url(),
            'headers' => array( 'Cache-Control' => 'no-cache' ),
        ) );
        if ( is_wp_error( $resp ) ) {
            return new WP_Error( 'seo_pilot_live_failed', 'The page could not be fetched from the server itself: ' . $resp->get_error_message() . ' Some hosts block requests to their own address; open the page in a browser and view its source instead.' );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( 200 !== $code ) {
            return new WP_Error( 'seo_pilot_live_status', 'The page returned HTTP ' . $code . ' when fetched from the server.' );
        }
        $live           = self::parse( wp_remote_retrieve_body( $resp ) );
        $live['status'] = $code;
        $live['url']    = $url;
        $live['at']     = SEO_Pilot_Util::now();
        return $live;
    }
}
