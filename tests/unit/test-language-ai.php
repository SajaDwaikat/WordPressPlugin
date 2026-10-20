<?php
t_group( 'Language-aware AI' );

/* ---- prompt helpers ---- */
t_eq( 'Arabic (ar) (the language set for this page)', SEO_Pilot_Prompt::language_label( array( 'lang_name' => 'Arabic (ar)', 'language' => 'latin' ) ), 'the language set by the editor or a plugin wins over the script guess' );
t_ok( false === strpos( SEO_Pilot_Prompt::language_label( array( 'language' => 'latin' ) ), 'likely English' ), 'a Latin-script page is no longer assumed to be English' );
t_eq( 'Arabic', SEO_Pilot_Prompt::language_label( array( 'language' => 'ar' ) ), 'script guess still works when nothing is set' );
t_eq( 'Arabic', SEO_Pilot_Prompt::explain_name( array( 'explain_lang' => 'ar' ) ), 'explanations in Arabic for an Arabic admin' );
t_eq( 'French', SEO_Pilot_Prompt::explain_name( array( 'explain_lang' => 'fr' ) ), 'explanations in French for a French admin' );
t_eq( 'English', SEO_Pilot_Prompt::explain_name( array( 'explain_lang' => 'xx' ) ), 'unknown admin language falls back to English' );
t_eq( 'English', SEO_Pilot_Prompt::explain_name( array() ), 'no admin language: English' );

$sys = SEO_Pilot_Prompt::system_prompt( array( 'seo_title', 'localize' ), 'Arabic (ar)', 'English' );
t_ok( false !== strpos( $sys, '"localize"' ) && false !== strpos( $sys, '11. Localize' ) && false !== strpos( $sys, 'do not translate word for word' ), 'localize task adds its schema and rule' );
t_ok( false === strpos( SEO_Pilot_Prompt::system_prompt( array( 'seo_title' ), 'x', 'English' ), '11. Localize' ), 'and only when chosen' );
t_ok( false !== strpos( $sys, 'never claim volume' ), 'localize rule forbids search-data claims' );
t_ok( isset( SEO_Pilot_Prompt::tasks()['localize'] ), 'localize is an available task' );

/* ---- validator: localize ---- */
$vctx = array( 'tasks' => array( 'localize' ), 'segments' => array(), 'content_text' => 'نص عن الخبز الحامض', 'seo_title' => '', 'meta_description' => '', 'title' => 'الخبز الحامض',
    'expected_script' => 'arabic', 'lang_name' => 'Arabic (ar)', 'source_keyword' => 'sourdough bread', 'source_lang' => 'English (en)', 'source_text' => 'Sourdough bread guide for 2 people' );
$ai = wp_json_encode( array( 'localize' => array(
    'seo_title' => array( 'proposed' => 'دليل الخبز الحامض للمبتدئين', 'reason' => 'natural wording', 'verify' => array() ),
    'meta_description' => array( 'proposed' => 'Learn how to bake sourdough bread at home in simple steps for beginners.', 'reason' => 'x', 'verify' => array() ),
    'keywords' => array( 'primary' => 'sourdough bread', 'secondary' => array( 'خبز حامض', 'sourdough bread', 'خميرة طبيعية' ), 'reason' => 'phrasing ideas' ),
) ) );
$p = SEO_Pilot_Validator::parse( $ai, $vctx );
$types = array_map( function ( $r ) { return $r['rec_type']; }, $p['recs'] );
t_eq( array( 'seo_title', 'meta_description', 'keywords_local' ), $types, 'localize produces an applicable title and description and an advisory keyword rec' );
t_ok( 0 === strpos( $p['recs'][0]['rationale'], 'Localized from the English (en) version' ), 'rationale says where it was localised from' );
t_ok( empty( $p['recs'][0]['flags']['notes'] ), 'an Arabic title on an Arabic page raises no language note' );
t_ok( ! empty( array_filter( $p['recs'][1]['flags']['notes'], function ( $n ) { return false !== strpos( $n, 'not in Arabic script' ); } ) ), 'an English description on an Arabic page is flagged' );
$kw = json_decode( $p['recs'][2]['proposed'], true );
t_eq( array( 'خبز حامض', 'خميرة طبيعية' ), $kw['secondary'], 'secondary keywords exclude the primary and keep the rest' );
t_ok( ! empty( array_filter( $p['recs'][2]['flags']['notes'], function ( $n ) { return false !== strpos( $n, 'identical to the source' ); } ) ), 'a keyword identical to the source keyword is flagged' );
t_ok( false !== strpos( $p['recs'][2]['rationale'], 'no search data' ), 'keyword advice states that it is not researched' );
t_ok( ! SEO_Pilot_Recs::is_applicable( 'keywords_local' ), 'keyword suggestions are never applied automatically' );
t_ok( SEO_Pilot_Recs::is_applicable( 'seo_title' ) && SEO_Pilot_Recs::is_applicable( 'meta_description' ), 'the localised title and description follow the normal approval flow' );

/* ---- validator: script check on ordinary tasks ---- */
$one = function ( $text, array $extra ) {
    return SEO_Pilot_Validator::parse( wp_json_encode( array( 'seo_title' => array( 'proposed' => $text, 'reason' => 'r', 'verify' => array() ) ) ),
        array_merge( array( 'tasks' => array( 'seo_title' ), 'segments' => array(), 'content_text' => 'text', 'seo_title' => '', 'meta_description' => '', 'title' => 'T' ), $extra ) );
};
$has_note = function ( $res, $needle ) { return ! empty( array_filter( $res['recs'][0]['flags']['notes'], function ( $n ) use ( $needle ) { return false !== strpos( $n, $needle ); } ) ); };
t_ok( $has_note( $one( 'A plain English title for the page', array( 'expected_script' => 'arabic', 'lang_name' => 'Arabic (ar)' ) ), 'Check the language' ), 'the script check also applies to the ordinary SEO title task' );
t_ok( empty( $one( 'A plain English title for the page', array() )['recs'][0]['flags']['notes'] ), 'no language note when the page language is unknown' );
t_ok( empty( $one( 'Un titre en français pour la page', array( 'expected_script' => 'latin', 'lang_name' => 'French (fr)' ) )['recs'][0]['flags']['notes'] ), 'Latin-script text on a Latin-script page is not second-guessed (English and French cannot be told apart)' );
t_ok( $has_note( $one( 'عنوان عربي للصفحة', array( 'expected_script' => 'latin', 'lang_name' => 'English (en)' ) ), 'in Arabic script' ), 'Arabic text on a page set to English is flagged' );
t_ok( empty( $one( 'عنوان', array( 'expected_script' => 'latin', 'lang_name' => 'English (en)' ) )['recs'][0]['flags']['notes'] ), 'very short texts are not judged' );
