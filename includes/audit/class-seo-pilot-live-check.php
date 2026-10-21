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
        $out = array( 'titles' => array(), 'descriptions' => array(), 'canonicals' => array(), 'robots' => array(), 'h1_count' => 0, 'jsonld_count' => 0, 'html_lang' => '', 'hreflang' => array(), 'og_locale' => '' );
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

        $html_el = $xp->query( '/html' )->item( 0 );
        if ( $html_el ) {
            $lang = trim( (string) $html_el->getAttribute( 'lang' ) );
            if ( '' === $lang ) { $lang = trim( (string) $html_el->getAttribute( 'xml:lang' ) ); }
            $out['html_lang'] = $lang;
        }
        foreach ( $xp->query( '//link[@hreflang]' ) as $n ) {
            $rels = preg_split( '/\s+/', strtolower( trim( (string) $n->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
            if ( is_array( $rels ) && in_array( 'alternate', $rels, true ) ) {
                $out['hreflang'][] = array( 'lang' => trim( (string) $n->getAttribute( 'hreflang' ) ), 'href' => trim( (string) $n->getAttribute( 'href' ) ) );
            }
        }
        foreach ( $xp->query( '//meta[' . sprintf( $lower, '@property' ) . "='og:locale']" ) as $n ) { $out['og_locale'] = trim( (string) $n->getAttribute( 'content' ) ); break; }
        return $out;
    }

    /**
     * Language checks on a parsed page. Pure function.
     *
     * @param array $live Result of parse().
     * @param array $ctx  url (this page), code (its language), members (code => url of its published, indexable translations),
     *                    mode ('plugin' | 'seo_pilot' | 'none': who is expected to print hreflang), expects (bool: translations exist)
     * @return array[] severity (warn|info|ok), message
     */
    public static function lang_notes( array $live, array $ctx ) {
        $ctx  = array_merge( array( 'url' => '', 'code' => '', 'members' => array(), 'mode' => 'none', 'expects' => false ), $ctx );
        $live = array_merge( array( 'html_lang' => '', 'hreflang' => array(), 'og_locale' => '', 'canonicals' => array() ), $live );
        $n    = array();
        $own  = SEO_Pilot_Hreflang::lang_part( $ctx['code'] );

        if ( '' === $live['html_lang'] ) {
            $n[] = array( 'info', 'The <html> tag has no lang attribute. Browsers and assistive technology use it to pick pronunciation and fonts.' );
        } elseif ( '' !== $own && SEO_Pilot_Hreflang::lang_part( $live['html_lang'] ) !== $own ) {
            $n[] = array( 'warn', 'The <html lang="' . $live['html_lang'] . '"> attribute does not match this page\'s language (' . $ctx['code'] . '). The theme usually prints one site-wide language; in Settings → Languages SEO Pilot can set it per page.' );
        } else {
            $n[] = array( 'ok', '<html lang="' . $live['html_lang'] . '">' );
        }

        $tags = $live['hreflang'];
        if ( ! $tags ) {
            if ( $ctx['expects'] ) { $n[] = array( 'warn', 'No hreflang tags were found, but this page has translations. Without them search engines have to guess which language version to show.' ); }
        } else {
            $codes = array(); $by = array(); $self = false;
            foreach ( $tags as $t ) {
                $codes[] = $t['lang'];
                if ( ! SEO_Pilot_Hreflang::is_valid_hreflang( $t['lang'] ) ) {
                    $n[] = array( 'warn', 'hreflang="' . $t['lang'] . '" is not a valid value. Use a language code such as "ar", "en" or "ar-SA" (with a hyphen, not an underscore), or "x-default".' );
                }
                $key = strtolower( SEO_Pilot_Hreflang::normalize_code( $t['lang'] ) ? SEO_Pilot_Hreflang::normalize_code( $t['lang'] ) : $t['lang'] );
                $by[ $key ][] = $t['href'];
                if ( 'x-default' !== $key && SEO_Pilot_Hreflang::same_url( $t['href'], $ctx['url'] ) ) { $self = true; }
                if ( 1 === preg_match( '/[?&](lang|language|locale)=/i', $t['href'] ) ) {
                    $n[] = array( 'info', 'An hreflang URL uses a language parameter (' . $t['href'] . '). Separate paths or sub-domains per language are easier for search engines to treat as distinct pages.' );
                }
            }
            foreach ( $by as $k => $hrefs ) {
                if ( count( $hrefs ) > 1 ) {
                    $n[] = array( 'warn', 'hreflang="' . $k . '" appears ' . count( $hrefs ) . ' times' . ( 'seo_pilot' === $ctx['mode'] ? ': another plugin or the theme is probably printing hreflang as well. Turn off one of them.' : '. Only one tag per language is allowed; two sources may be printing hreflang.' ) );
                }
            }
            if ( ! $self ) { $n[] = array( 'warn', 'This page does not list itself in its hreflang tags. Each page should include a self-referencing entry.' ); }
            foreach ( $ctx['members'] as $mc => $murl ) {
                $key = strtolower( SEO_Pilot_Hreflang::normalize_code( $mc ) );
                if ( ! isset( $by[ $key ] ) ) { $n[] = array( 'warn', 'No hreflang tag for the ' . $mc . ' version (' . $murl . ').' ); }
                elseif ( ! SEO_Pilot_Hreflang::same_url( $by[ $key ][0], $murl ) ) { $n[] = array( 'warn', 'The hreflang tag for ' . $mc . ' points to ' . $by[ $key ][0] . ', but the linked ' . $mc . ' page is ' . $murl . '.' ); }
            }
            if ( count( $tags ) >= 2 && ! isset( $by['x-default'] ) ) { $n[] = array( 'info', 'There is no x-default hreflang entry. It is optional; it tells search engines which version to show people whose language is not listed.' ); }
            $n[] = array( 'ok', count( $tags ) . ' hreflang tag(s): ' . implode( ', ', $codes ) );
        }

        if ( $live['canonicals'] && ( $tags || $ctx['expects'] ) && '' !== $ctx['url'] && ! SEO_Pilot_Hreflang::same_url( $live['canonicals'][0], $ctx['url'] ) ) {
            $n[] = array( 'warn', 'The canonical points to ' . $live['canonicals'][0] . ', not to this page. For translated pages each language version should be canonical to itself; a canonical to another language tells search engines to ignore this one. (SEO Pilot never changes canonicals.)' );
        }
        if ( '' !== $live['og_locale'] && '' !== $own && SEO_Pilot_Hreflang::lang_part( $live['og_locale'] ) !== $own ) {
            $n[] = array( 'info', 'og:locale is "' . $live['og_locale'] . '", which does not match this page\'s language (' . $ctx['code'] . '). Social previews use it.' );
        }
        return $n;
    }

    /**
     * Do the alternate language pages link back? Pure function.
     *
     * @param string $page_url This page.
     * @param array  $alts     href => array( code, live (parse() result) | error (string) )
     * @return array[] severity (warn|info|ok), message
     */
    public static function reciprocity_notes( $page_url, array $alts ) {
        $n = array();
        foreach ( $alts as $href => $a ) {
            $label = isset( $a['code'] ) && '' !== $a['code'] ? $a['code'] : $href;
            if ( isset( $a['error'] ) ) { $n[] = array( 'warn', 'The ' . $label . ' page (' . $href . ') could not be checked: ' . $a['error'] ); continue; }
            $l = array_merge( array( 'hreflang' => array(), 'canonicals' => array(), 'html_lang' => '' ), $a['live'] );
            $back = false;
            foreach ( $l['hreflang'] as $t ) { if ( SEO_Pilot_Hreflang::same_url( $t['href'], $page_url ) ) { $back = true; } }
            $n[] = $back ? array( 'ok', 'The ' . $label . ' page links back to this page with hreflang.' )
                         : array( 'warn', 'The ' . $label . ' page (' . $href . ') does not link back to this page with hreflang. hreflang only works when both pages point at each other (a return tag).' );
            if ( $l['canonicals'] && ! SEO_Pilot_Hreflang::same_url( $l['canonicals'][0], $href ) ) {
                $n[] = array( 'warn', 'The ' . $label . ' page\'s canonical points to ' . $l['canonicals'][0] . ' instead of itself.' );
            }
            if ( isset( $a['code'] ) && '' !== $a['code'] && '' !== $l['html_lang'] && SEO_Pilot_Hreflang::lang_part( $l['html_lang'] ) !== SEO_Pilot_Hreflang::lang_part( $a['code'] ) ) {
                $n[] = array( 'warn', 'The ' . $label . ' page declares <html lang="' . $l['html_lang'] . '">, which does not match its hreflang (' . $a['code'] . ').' );
            }
        }
        return $n;
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
        return self::fetch_url( get_permalink( $post ) );
    }

    /**
     * Fetch one URL on this site and parse it. Never requests another host.
     *
     * @return array|WP_Error
     */
    public static function fetch_url( $url ) {
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

    /**
     * Fetch the alternate-language pages this page lists in its hreflang tags (this site only,
     * at most $limit, one request each) so their return tags can be checked.
     *
     * @return array href => array( code, live ) or array( code, error )
     */
    public static function fetch_alternates( array $live, $page_url, $limit = 5 ) {
        $out = array();
        foreach ( (array) $live['hreflang'] as $t ) {
            if ( count( $out ) >= $limit ) { break; }
            if ( 'x-default' === strtolower( $t['lang'] ) || '' === $t['href'] || isset( $out[ $t['href'] ] ) || SEO_Pilot_Hreflang::same_url( $t['href'], $page_url ) ) { continue; }
            $res = self::fetch_url( $t['href'] );
            $out[ $t['href'] ] = is_wp_error( $res ) ? array( 'code' => $t['lang'], 'error' => $res->get_error_message() ) : array( 'code' => $t['lang'], 'live' => $res );
        }
        return $out;
    }
}
