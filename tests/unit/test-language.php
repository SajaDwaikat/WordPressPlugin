<?php
t_group( 'Language and translation checks (audit)' );

function lang_page( array $o = array(), array $li = array() ) {
    $ar_body = '<p>' . str_repeat( 'هذا نص عربي طويل يشرح كيفية صنع الخبز الحامض في المنزل خطوة بعد خطوة. ', 14 ) . '</p>';
    $info = array_merge( array( 'code' => 'ar', 'label' => 'Arabic', 'dir' => 'rtl', 'source' => 'manual', 'plugins' => array(), 'managed_by' => '', 'group' => 'g1', 'declared' => true, 'members' => array(), 'site_languages' => array( 'ar', 'en' ) ), $li );
    return array_merge( array( 'id' => 1, 'title' => 'دليل الخبز الحامض', 'url' => 'https://example.com/ar/bread/', 'content' => $ar_body, 'post_type' => 'post',
        'site_host' => 'example.com', 'year' => 2026, 'lang_info' => $info,
        'meta_description' => array( 'value' => 'وصف عربي قصير لصفحة عن الخبز الحامض وكيفية تحضيره في المنزل بسهولة.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
    ), $o );
}

/* ---- declared language vs text ---- */
$a = SEO_Pilot_Audit::analyze( lang_page() );
t_ok( ! in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'Arabic page with Arabic text: no mismatch' );
t_eq( 'pass', check_status( $a, 'lang_content' ), 'checklist shows it matches' );

$en_body = '<p>' . str_repeat( 'This is a long English text that explains how to bake sourdough bread at home step by step. ', 10 ) . '</p>';
$a = SEO_Pilot_Audit::analyze( lang_page( array( 'content' => $en_body ) ) );
t_ok( in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'page declared Arabic with English text is flagged' );
$a = SEO_Pilot_Audit::analyze( lang_page( array( 'content' => $en_body, 'title' => 'Sourdough guide', 'meta_description' => array( 'value' => 'A guide to baking sourdough bread at home with simple tools.', 'owner_label' => 'x', 'template_vars' => false ) ), array( 'code' => 'en', 'label' => 'English', 'dir' => 'ltr' ) ) );
t_ok( ! in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'English page with English text: fine' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'code' => 'en', 'label' => 'English', 'dir' => 'ltr' ) ) );
t_ok( in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'page declared English with Arabic text is flagged' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'code' => 'ru', 'label' => 'Russian' ) ) );
t_ok( ! in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'other scripts are never guessed' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'declared' => false, 'source' => 'site', 'site_languages' => array( 'ar' ) ) ) );
t_ok( ! in_array( 'lang_content_mismatch', finding_ids( $a ), true ), 'an assumed (undeclared) language is not used to accuse the page' );
t_eq( null, check_status( SEO_Pilot_Audit::analyze( lang_page( array(), array( 'declared' => false, 'source' => 'site', 'site_languages' => array( 'ar' ) ) ) ), 'lang_content' ), 'single-language site: no language noise' );
t_eq( 'info', check_status( SEO_Pilot_Audit::analyze( lang_page( array(), array( 'declared' => false, 'source' => 'site' ) ) ), 'lang_content' ), 'multilingual site, page without a language: info' );

/* ---- metadata in the wrong language ---- */
$a = SEO_Pilot_Audit::analyze( lang_page( array( 'meta_description' => array( 'value' => 'A guide to baking sourdough bread at home with simple tools.', 'owner_label' => 'x', 'template_vars' => false ),
    'seo_title' => array( 'value' => 'Sourdough bread guide for beginners', 'owner_label' => 'x', 'template_vars' => false ) ) ) );
t_ok( in_array( 'lang_meta_mismatch', finding_ids( $a ), true ), 'English title and description on an Arabic page are flagged' );
t_eq( 'warn', check_status( $a, 'lang_meta' ), 'checklist reflects it' );
$a = SEO_Pilot_Audit::analyze( lang_page( array( 'meta_description' => array( 'value' => 'Welcome to %%sitename%% and %%title%%', 'owner_label' => 'Yoast SEO', 'template_vars' => true ) ) ) );
t_ok( ! in_array( 'lang_meta_mismatch', finding_ids( $a ), true ), 'template-variable fields are not judged' );

/* ---- translations ---- */
$sk = SEO_Pilot_Similarity::sketch( SEO_Pilot_Text::plain( lang_page()['content'] ) );
$member = function ( array $o = array() ) use ( $sk ) {
    return array_merge( array( 'id' => 2, 'code' => 'en', 'url' => 'https://example.com/bread/', 'status' => 'publish', 'title' => 'Sourdough guide', 'seo_title' => '', 'meta' => 'An English description of the sourdough page.', 'keyword' => 'sourdough', 'noindex' => false, 'sketch' => array( 'n' => 0, 's' => array() ) ), $o );
};
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member() ) ) ) );
t_ok( ! array_intersect( array( 'lang_title_identical', 'lang_meta_identical', 'lang_untranslated_copy', 'lang_keyword_identical' ), finding_ids( $a ) ), 'properly localised translation raises nothing' );
t_eq( 'pass', check_status( $a, 'lang_translations' ), 'translations are shown as linked' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member( array( 'title' => 'دليل الخبز الحامض' ) ) ) ) ) );
t_ok( in_array( 'lang_title_identical', finding_ids( $a ), true ), 'same title as the translation is flagged (not localised)' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member( array( 'seo_title' => 'دليل الخبز الحامض' ) ) ) ) ) );
t_ok( in_array( 'lang_title_identical', finding_ids( $a ), true ), 'the SEO title of the translation is compared when it has one' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member( array( 'meta' => 'وصف عربي قصير لصفحة عن الخبز الحامض وكيفية تحضيره في المنزل بسهولة.' ) ) ) ) ) );
t_ok( in_array( 'lang_meta_identical', finding_ids( $a ), true ), 'same meta description as the translation is flagged' );
$a = SEO_Pilot_Audit::analyze( lang_page( array( 'keywords' => array( 'primary' => 'خبز حامض', 'secondary' => array(), 'primary_source' => 'seo_pilot' ) ), array( 'members' => array( 'en' => $member( array( 'keyword' => 'خبز حامض' ) ) ) ) ) );
t_ok( in_array( 'lang_keyword_identical', finding_ids( $a ), true ), 'same keyword in both languages is noted' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member( array( 'sketch' => $sk ) ) ) ) ) );
t_ok( in_array( 'lang_untranslated_copy', finding_ids( $a ), true ), 'near-identical text across two language versions is flagged as a likely untranslated copy' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member() ), 'site_languages' => array( 'ar', 'en', 'fr' ) ) ) );
t_ok( in_array( 'lang_missing_translation', finding_ids( $a ), true ), 'a configured language with no version is listed (info)' );
$ev = null; foreach ( $a['findings'] as $f ) { if ( 'lang_missing_translation' === $f['id'] ) { $ev = $f; } }
t_ok( 'info' === $ev['severity'] && array( 'French' ) === $ev['evidence'], 'and only as information' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array(), 'site_languages' => array( 'ar', 'en', 'fr' ) ) ) );
t_ok( ! in_array( 'lang_missing_translation', finding_ids( $a ), true ), 'a manual-mode page with no group is not nagged about missing translations' );
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'source' => 'polylang', 'managed_by' => 'polylang', 'members' => array(), 'site_languages' => array( 'ar', 'en' ) ) ) );
t_ok( in_array( 'lang_missing_translation', finding_ids( $a ), true ), 'a Polylang-managed page with no translation is listed (info)' );

/* ---- stored result ---- */
$a = SEO_Pilot_Audit::analyze( lang_page( array(), array( 'members' => array( 'en' => $member( array( 'sketch' => $sk ) ) ) ) ) );
t_ok( isset( $a['lang_info']['members']['en'] ) && ! isset( $a['lang_info']['members']['en']['sketch'] ), 'the audit stores language info without the text sketches' );
t_eq( 'ar', $a['lang_info']['code'], 'language code is stored' );

/* ---- criterion ---- */
$c = SEO_Pilot_Report::criteria( $a );
$keys = array_map( function ( $x ) { return $x['key']; }, $c );
t_ok( in_array( 'language', $keys, true ), 'report has a Language and translations criterion' );
