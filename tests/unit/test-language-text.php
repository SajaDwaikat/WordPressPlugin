<?php
t_group( 'Arabic folding, word lists and unsupported languages' );

/* ---- folding ---- */
t_eq( 'صوره', SEO_Pilot_Text::fold_arabic( 'صورة' ), 'ة folds to ه' );
t_eq( 'يمني', SEO_Pilot_Text::fold_arabic( 'يمنى' ), 'ى folds to ي' );
t_eq( 'سوال', SEO_Pilot_Text::fold_arabic( 'سؤال' ), 'ؤ folds to و' );
t_eq( 'كيف', SEO_Pilot_Text::fold_arabic( 'کیف' ), 'Persian kaf and yeh fold to Arabic forms' );
t_eq( SEO_Pilot_Text::len( 'مدرسة' ), SEO_Pilot_Text::len( SEO_Pilot_Text::fold_arabic( 'مدرسة' ) ), 'folding never changes the length (positions stay valid)' );

t_ok( SEO_Pilot_Text::has_phrase( 'نقدم خدمات صيانة المدرسه الحديثة', 'المدرسة' ), 'keyword ending in ة matches the ه spelling in the text' );
t_ok( SEO_Pilot_Text::has_phrase( 'مقال عن فتاة شجاعة', 'فتاه' ), 'and the other way round' );
t_ok( SEO_Pilot_Text::has_phrase( 'سألت عن المستشفى', 'المستشفي' ), 'ى and ي match each other' );
t_ok( SEO_Pilot_Text::has_phrase( 'والتحسين مهم', 'تحسين' ), 'prefix matching still works' );
t_ok( ! SEO_Pilot_Text::has_phrase( 'نص عن الخبز', 'الحليب' ), 'unrelated words still do not match' );
t_eq( 1, SEO_Pilot_Text::count_phrase( 'SEO is great, Seoul is far', 'seo' ), 'Latin whole-word matching is unchanged' );
t_ok( SEO_Pilot_Text::normalize( 'صورة' ) === 'صورة', 'normalize() itself is unchanged, so duplicate detection behaves as before' );

/* ---- title position uses the same folding ---- */
$r = SEO_Pilot_Title::analyze( array( 'title' => 'دليل المدرسه الحديثة', 'primary' => 'المدرسة', 'text' => 'نص', 'word_count' => 1 ) );
$k = null; foreach ( $r['checks'] as $c ) { if ( 'keyword' === $c['id'] ) { $k = $c; } }
t_eq( 'pass', $k['status'], 'Arabic keyword with a different ة/ه spelling is found in the title' );

/* ---- word lists ---- */
t_ok( SEO_Pilot_Text::lists_supported( 'ar' ) && SEO_Pilot_Text::lists_supported( 'en-GB' ) && SEO_Pilot_Text::lists_supported( '' ), 'English, Arabic and unknown are supported' );
t_ok( ! SEO_Pilot_Text::lists_supported( 'fr' ) && ! SEO_Pilot_Text::lists_supported( 'de-AT' ), 'other languages are not' );

function lang_lists_page( $code, $label ) {
    $body = '<h2>Titre</h2><p>' . str_repeat( 'Ceci est un texte plus long sur le pain au levain et sur la boulangerie de quartier. ', 16 ) . '</p>';
    return array( 'id' => 1, 'title' => 'Le guide du pain au levain', 'url' => 'https://example.com/fr/pain/', 'content' => $body, 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026,
        'lang_info' => array( 'code' => $code, 'label' => $label, 'dir' => 'ltr', 'source' => 'manual', 'plugins' => array(), 'managed_by' => '', 'group' => '', 'declared' => true, 'members' => array(), 'site_languages' => array( 'en', 'fr' ) ) );
}
$a = SEO_Pilot_Audit::analyze( lang_lists_page( 'fr', 'French' ) );
t_eq( 'manual', check_status( $a, 'title_alignment' ), 'French page: stopword-based title alignment is left to a human' );
t_eq( 'info', check_status( $a, 'lang_lists' ), 'and the audit says why' );
t_eq( 'na', check_status( $a, 'filler' ), 'the English filler check does not run on a French page' );
t_ok( ! in_array( 'term_repetition', finding_ids( $a ), true ), 'word-frequency finding is not produced without a word list' );
$ta = $a['title_analysis'];
$al = null; foreach ( $ta['checks'] as $c ) { if ( 'alignment' === $c['id'] ) { $al = $c; } }
t_eq( 'manual', $al['status'], 'title analysis also leaves alignment to a human' );
$a = SEO_Pilot_Audit::analyze( lang_lists_page( 'en', 'English' ) );
t_ok( null === check_status( $a, 'lang_lists' ), 'English page: no such note' );
$a = SEO_Pilot_Audit::analyze( array_merge( lang_lists_page( 'fr', 'French' ), array( 'lists_ok' => true ) ) );
t_ok( 'manual' !== check_status( $a, 'title_alignment' ), 'a site that declared support for the language gets the checks (lists_ok override)' );

/* ---- filler check follows the declared language ---- */
$en_filler = '<p>' . str_repeat( 'It goes without saying that bread matters. Needless to say we bake every day with care and flour. ', 8 ) . '</p>';
$lp = lang_lists_page( 'en', 'English' ); $lp['content'] = $en_filler;
t_ok( in_array( 'filler_phrases', finding_ids( SEO_Pilot_Audit::analyze( $lp ) ), true ), 'English filler is found on an English page' );
$lp2 = lang_lists_page( 'fr', 'French' ); $lp2['content'] = $en_filler;
t_ok( ! in_array( 'filler_phrases', finding_ids( SEO_Pilot_Audit::analyze( $lp2 ) ), true ), 'but not on a page declared French' );
