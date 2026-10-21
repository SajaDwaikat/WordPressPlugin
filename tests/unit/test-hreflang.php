<?php
t_group( 'Language codes and hreflang tags' );

t_eq( 'ar-SA', SEO_Pilot_Hreflang::normalize_code( 'ar_sa' ), 'normalize: underscore and case' );
t_eq( 'zh-Hant-TW', SEO_Pilot_Hreflang::normalize_code( 'zh-hant-tw' ), 'normalize: script subtag' );
t_eq( 'en', SEO_Pilot_Hreflang::normalize_code( ' EN ' ), 'normalize: trims and lower-cases the language' );
t_eq( 'x-default', SEO_Pilot_Hreflang::normalize_code( 'X-Default' ), 'normalize: x-default' );
t_eq( '', SEO_Pilot_Hreflang::normalize_code( 'english' ), 'normalize: a word is not a code' );
t_eq( '', SEO_Pilot_Hreflang::normalize_code( 'a' ), 'normalize: too short' );
t_eq( '', SEO_Pilot_Hreflang::normalize_code( '' ), 'normalize: empty' );
t_ok( SEO_Pilot_Hreflang::is_valid_hreflang( 'ar-SA' ) && SEO_Pilot_Hreflang::is_valid_hreflang( 'en' ) && SEO_Pilot_Hreflang::is_valid_hreflang( 'x-default' ), 'valid hreflang values' );
t_ok( ! SEO_Pilot_Hreflang::is_valid_hreflang( 'ar_SA' ), 'underscore is invalid in hreflang' );
t_ok( ! SEO_Pilot_Hreflang::is_valid_hreflang( 'english' ) && ! SEO_Pilot_Hreflang::is_valid_hreflang( '' ), 'words and empty are invalid' );
t_eq( 'ar', SEO_Pilot_Hreflang::lang_part( 'ar-SA' ), 'lang_part' );
t_eq( '', SEO_Pilot_Hreflang::lang_part( 'x-default' ), 'x-default has no language part' );
t_ok( SEO_Pilot_Hreflang::is_rtl( 'ar' ) && SEO_Pilot_Hreflang::is_rtl( 'fa-IR' ) && ! SEO_Pilot_Hreflang::is_rtl( 'en-GB' ), 'rtl detection' );
t_eq( 'Arabic', SEO_Pilot_Hreflang::name( 'ar_SA' ), 'language name' );
t_eq( '', SEO_Pilot_Hreflang::name( 'xx' ), 'unknown language has no name' );
t_eq( 'arabic', SEO_Pilot_Hreflang::script_class( 'ar' ), 'script class: Arabic' );
t_eq( 'latin', SEO_Pilot_Hreflang::script_class( 'fr-CA' ), 'script class: Latin' );
t_eq( '', SEO_Pilot_Hreflang::script_class( 'ru' ), 'script class: other scripts are not guessed' );

t_ok( SEO_Pilot_Hreflang::same_url( 'https://Example.com/a/', 'https://example.com/a' ), 'same_url ignores host case and trailing slash' );
t_ok( ! SEO_Pilot_Hreflang::same_url( 'https://example.com/a/', 'https://example.com/b/' ), 'same_url: different paths' );
t_ok( ! SEO_Pilot_Hreflang::same_url( 'https://example.com/a/', 'http://example.com/a/' ) , 'same_url: scheme matters' );
t_ok( ! SEO_Pilot_Hreflang::same_url( '', '' ), 'same_url: empty is never equal' );

/* ---- tags ---- */
$members = array(
    array( 'code' => 'en', 'url' => 'https://example.com/about/' ),
    array( 'code' => 'ar', 'url' => 'https://example.com/ar/about/' ),
    array( 'code' => 'fr_FR', 'url' => 'https://example.com/fr/about/' ),
);
$t = SEO_Pilot_Hreflang::tags( $members, 'en' );
t_eq( array( 'ar', 'en', 'fr-FR', 'x-default' ), array_map( function ( $x ) { return $x['hreflang']; }, $t ), 'tags: sorted, normalized, x-default last' );
t_eq( 'https://example.com/about/', $t[3]['href'], 'x-default points to the default language page' );
t_eq( 3, count( SEO_Pilot_Hreflang::tags( $members, '' ) ), 'no x-default without a default language' );
t_eq( 3, count( SEO_Pilot_Hreflang::tags( $members, 'de' ) ), 'no x-default when the default language is not in the group' );
t_eq( array(), SEO_Pilot_Hreflang::tags( array( array( 'code' => 'en', 'url' => 'https://example.com/a/' ) ), 'en' ), 'a single language prints nothing' );
t_eq( array(), SEO_Pilot_Hreflang::tags( array( array( 'code' => 'en', 'url' => 'https://example.com/a/' ), array( 'code' => 'en', 'url' => 'https://example.com/b/' ) ) ), 'two pages in the same language count as one language' );
t_eq( 2, count( SEO_Pilot_Hreflang::tags( array( array( 'code' => 'en', 'url' => 'https://e.com/a/' ), array( 'code' => 'ar', 'url' => 'https://e.com/b/' ), array( 'code' => 'nope', 'url' => 'https://e.com/c/' ), array( 'code' => 'fr', 'url' => '/relative/' ) ) ) ), 'invalid codes and relative URLs are dropped' );
$html = SEO_Pilot_Hreflang::render( array( array( 'hreflang' => 'ar', 'href' => 'https://example.com/a?x=1&y="2"' ) ) );
t_ok( false !== strpos( $html, 'href="https://example.com/a?x=1&amp;y=&quot;2&quot;"' ) && false !== strpos( $html, 'hreflang="ar"' ), 'render escapes attribute values' );

/* ---- reciprocity by construction: every member of a group gets the same full list ---- */
$a = SEO_Pilot_Hreflang::tags( $members, 'en' );
$b = SEO_Pilot_Hreflang::tags( array_reverse( $members ), 'en' );
t_eq( $a, $b, 'every page of a group prints the same complete list regardless of order' );

/* ---- linking rules ---- */
$p = SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array( 'en' => 1 ) ), array( 'id' => 2, 'code' => 'ar', 'members' => array( 'ar' => 2 ) ) );
t_ok( $p['ok'] && $p['members'] === array( 'en' => 1, 'ar' => 2 ), 'two pages in different languages can be linked' );
t_ok( ! SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array() ), array( 'id' => 1, 'code' => 'ar', 'members' => array() ) )['ok'], 'a page cannot be linked to itself' );
t_ok( ! SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array() ), array( 'id' => 2, 'code' => 'en-GB', 'members' => array() ) )['ok'] === false, 'en and en-GB are different codes and can be linked' );
t_ok( ! SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array() ), array( 'id' => 2, 'code' => 'en', 'members' => array() ) )['ok'], 'same language cannot be linked' );
t_ok( ! SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => '', 'members' => array() ), array( 'id' => 2, 'code' => 'ar', 'members' => array() ) )['ok'], 'a page without a language cannot be linked' );
$three = SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array( 'en' => 1, 'fr' => 3 ) ), array( 'id' => 2, 'code' => 'ar', 'members' => array( 'ar' => 2 ) ) );
t_ok( $three['ok'] && 3 === count( $three['members'] ), 'linking merges existing groups' );
$clash = SEO_Pilot_Hreflang::plan_link( array( 'id' => 1, 'code' => 'en', 'members' => array( 'en' => 1, 'fr' => 3 ) ), array( 'id' => 2, 'code' => 'ar', 'members' => array( 'ar' => 2, 'fr' => 4 ) ) );
t_ok( ! $clash['ok'] && false !== strpos( $clash['error'], 'French' ), 'merging two groups with different pages in one language is refused' );

/* ---- <html> lang attribute ---- */
t_eq( 'lang="ar-SA"', SEO_Pilot_Hreflang::with_lang_attribute( 'lang="en-US"', 'ar_sa' ), 'lang attribute replaced and normalised' );
t_eq( 'dir="rtl" lang="ar"', SEO_Pilot_Hreflang::with_lang_attribute( 'dir="rtl" lang="en-US"', 'ar' ), 'other attributes are kept' );
t_eq( 'lang="fr"', SEO_Pilot_Hreflang::with_lang_attribute( '', 'fr' ), 'lang added when there is none' );
t_eq( 'lang="ar" xml:lang="ar"', SEO_Pilot_Hreflang::with_lang_attribute( 'lang="en-US" xml:lang="en-US"', 'ar' ), 'xml:lang is replaced too' );
t_eq( "lang='en'", SEO_Pilot_Hreflang::with_lang_attribute( "lang='en'", 'x-default' ), 'x-default is never written as a page language' );
t_eq( 'lang="en-US"', SEO_Pilot_Hreflang::with_lang_attribute( 'lang="en-US"', 'nonsense' ), 'an invalid code leaves the attributes alone' );
