<?php
t_group( 'Live check: language and hreflang' );

$html = '<!doctype html><html lang="ar-SA" dir="rtl"><head><title>خبز</title>'
    . '<link rel="canonical" href="https://example.com/ar/bread/">'
    . '<link rel="alternate" hreflang="ar-SA" href="https://example.com/ar/bread/">'
    . '<link rel="alternate" hreflang="en" href="https://example.com/bread/">'
    . '<link rel="ALTERNATE" HREFLANG="x-default" href="https://example.com/bread/">'
    . '<link rel="stylesheet" hreflang="fr" href="style.css">'
    . '<meta property="og:locale" content="ar_SA"></head><body><h1>خبز</h1></body></html>';
$live = SEO_Pilot_Live_Check::parse( $html );
t_eq( 'ar-SA', $live['html_lang'], 'parse: html lang' );
t_eq( 3, count( $live['hreflang'] ), 'parse: only rel=alternate links with hreflang count (a stylesheet with hreflang is ignored)' );
t_eq( 'x-default', $live['hreflang'][2]['lang'], 'parse: attribute and rel case is ignored' );
t_eq( 'https://example.com/bread/', $live['hreflang'][1]['href'], 'parse: hreflang href' );
t_eq( 'ar_SA', $live['og_locale'], 'parse: og:locale' );
$none = SEO_Pilot_Live_Check::parse( '<html><head><title>x</title></head><body></body></html>' );
t_ok( '' === $none['html_lang'] && array() === $none['hreflang'] && '' === $none['og_locale'], 'parse: pages without language data' );
t_eq( array(), SEO_Pilot_Live_Check::parse( '' )['hreflang'], 'parse: empty html' );

$ctx = array( 'url' => 'https://example.com/ar/bread/', 'code' => 'ar-SA', 'members' => array( 'en' => 'https://example.com/bread/' ), 'mode' => 'seo_pilot', 'expects' => true );
$sev = function ( array $notes ) { return array_map( function ( $n ) { return $n[0]; }, $notes ); };
$txt = function ( array $notes ) { return implode( ' | ', array_map( function ( $n ) { return $n[1]; }, $notes ) ); };

$n = SEO_Pilot_Live_Check::lang_notes( $live, $ctx );
t_ok( ! in_array( 'warn', $sev( $n ), true ), 'a correct set of tags raises no warning' );
t_ok( false !== strpos( $txt( $n ), '3 hreflang tag(s)' ), 'summary lists the tags' );
t_ok( false === strpos( $txt( $n ), 'og:locale is' ), 'og:locale in the same language raises nothing' );

$wrong = $live; $wrong['html_lang'] = 'en';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $wrong, $ctx ) ), 'does not match this page' ), 'html lang that disagrees with the page language is a warning' );
$nolang = $live; $nolang['html_lang'] = '';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $nolang, $ctx ) ), 'no lang attribute' ), 'missing html lang is noted' );

$bad = $live; $bad['hreflang'][0]['lang'] = 'ar_SA';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $bad, $ctx ) ), 'not a valid value' ), 'underscore codes are flagged' );

$noself = $live; array_shift( $noself['hreflang'] );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $noself, $ctx ) ), 'does not list itself' ), 'missing self-reference is flagged' );

$dupe = $live; $dupe['hreflang'][] = array( 'lang' => 'en', 'href' => 'https://example.com/bread/' );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $dupe, $ctx ) ), 'appears 2 times' ), 'duplicate language tags are flagged' );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $dupe, $ctx ) ), 'another plugin or the theme' ), 'and blamed on a second source when SEO Pilot prints them too' );

$miss = $live; $miss['hreflang'] = array( $live['hreflang'][0] );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $miss, $ctx ) ), 'No hreflang tag for the en version' ), 'a linked translation without a tag is flagged' );

$wrongurl = $live; $wrongurl['hreflang'][1]['href'] = 'https://example.com/other/';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $wrongurl, $ctx ) ), 'but the linked en page is' ), 'a tag pointing at the wrong page is flagged' );

$nox = $live; array_pop( $nox['hreflang'] );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $nox, $ctx ) ), 'no x-default' ), 'missing x-default is informational' );
t_ok( ! in_array( 'warn', $sev( array_filter( SEO_Pilot_Live_Check::lang_notes( $nox, $ctx ), function ( $x ) { return false !== strpos( $x[1], 'x-default' ); } ) ), true ), 'and never a warning' );

$none_ctx = SEO_Pilot_Live_Check::lang_notes( $none, $ctx );
t_ok( false !== strpos( $txt( $none_ctx ), 'No hreflang tags were found, but this page has translations' ), 'translations without any hreflang are flagged' );
t_ok( false === strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $none, array_merge( $ctx, array( 'expects' => false, 'members' => array() ) ) ) ), 'No hreflang tags were found' ), 'a page with no translations is not nagged' );

$cross = $live; $cross['canonicals'] = array( 'https://example.com/bread/' );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $cross, $ctx ) ), 'canonical points to https://example.com/bread/' ), 'a canonical pointing at another language version is flagged' );
$selfcanon = $live; $selfcanon['canonicals'] = array( 'https://example.com/ar/bread' );
t_ok( false === strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $selfcanon, $ctx ) ), 'canonical points to' ), 'a self canonical (trailing slash aside) is fine' );
$og = $live; $og['og_locale'] = 'en_US';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $og, $ctx ) ), 'og:locale is "en_US"' ), 'og:locale in another language is noted' );
$param = $live; $param['hreflang'][1]['href'] = 'https://example.com/bread/?lang=en';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::lang_notes( $param, array_merge( $ctx, array( 'members' => array() ) ) ) ), 'language parameter' ), 'language parameters in hreflang URLs are noted' );

/* ---- reciprocity ---- */
$alt_ok = array( 'https://example.com/bread/' => array( 'code' => 'en', 'live' => array( 'hreflang' => array( array( 'lang' => 'ar-SA', 'href' => 'https://example.com/ar/bread/' ), array( 'lang' => 'en', 'href' => 'https://example.com/bread/' ) ), 'canonicals' => array( 'https://example.com/bread/' ), 'html_lang' => 'en-US' ) ) );
$r = SEO_Pilot_Live_Check::reciprocity_notes( 'https://example.com/ar/bread/', $alt_ok );
t_eq( array( 'ok' ), $sev( $r ), 'an alternate that links back is fine' );
$alt_no = $alt_ok; $alt_no['https://example.com/bread/']['live']['hreflang'] = array( array( 'lang' => 'en', 'href' => 'https://example.com/bread/' ) );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::reciprocity_notes( 'https://example.com/ar/bread/', $alt_no ) ), 'does not link back' ), 'a missing return tag is flagged' );
$alt_can = $alt_ok; $alt_can['https://example.com/bread/']['live']['canonicals'] = array( 'https://example.com/ar/bread/' );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::reciprocity_notes( 'https://example.com/ar/bread/', $alt_can ) ), 'canonical points to' ), 'an alternate canonical to another language is flagged' );
$alt_lang = $alt_ok; $alt_lang['https://example.com/bread/']['live']['html_lang'] = 'fr';
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::reciprocity_notes( 'https://example.com/ar/bread/', $alt_lang ) ), 'does not match its hreflang' ), 'an alternate with the wrong html lang is flagged' );
$alt_err = array( 'https://example.com/bread/' => array( 'code' => 'en', 'error' => 'HTTP 404' ) );
t_ok( false !== strpos( $txt( SEO_Pilot_Live_Check::reciprocity_notes( 'https://example.com/ar/bread/', $alt_err ) ), 'could not be checked: HTTP 404' ), 'an unreachable alternate is reported' );
