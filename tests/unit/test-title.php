<?php
t_group( 'Title analysis' );

// Minimal stand-ins for the WordPress functions the validator calls (the unit suite runs without WordPress).
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $s ) ) ); } }

function title_in( array $o = array() ) {
    $body = 'Sourdough bread needs flour, water, salt and time. This guide explains the starter, the folding and the baking so that beginners can bake a good loaf at home. '
        . str_repeat( 'We describe each step of the sourdough process in plain words. ', 8 );
    return array_merge( array(
        'title' => 'Sourdough bread guide', 'seo_title' => '', 'template_vars' => false, 'site_name' => 'Example Bakery',
        'primary' => '', 'secondary' => array(), 'headings' => array( array( 'level' => 2, 'text' => 'The sourdough starter' ) ),
        'text' => $body, 'word_count' => SEO_Pilot_Text::word_count( $body ), 'intent_label' => '', 'exact_duplicates' => 0, 'pool' => array(), 'self_id' => 1,
    ), $o );
}
function title_check( array $r, $id ) {
    foreach ( $r['checks'] as $c ) { if ( $c['id'] === $id ) { return $c; } }
    return null;
}

/* ---- width estimate ---- */
$narrow = SEO_Pilot_Title::estimate_width( str_repeat( 'i', 20 ) );
$wide   = SEO_Pilot_Title::estimate_width( str_repeat( 'W', 20 ) );
t_ok( $narrow['mid'] < 120 && $wide['mid'] > 300, 'width estimate: narrow letters are much narrower than wide letters' );
t_ok( $narrow['low'] < $narrow['mid'] && $narrow['mid'] < $narrow['high'], 'width estimate is a range' );
t_eq( 0, SEO_Pilot_Title::estimate_width( '' )['mid'], 'empty title has no width' );
$ar = SEO_Pilot_Title::estimate_width( 'دليل شامل لتحسين محركات البحث' );
t_ok( $ar['mid'] > 100 && $ar['mid'] < 600, 'Arabic title gets a plausible width' );
list( $pv, $cut ) = SEO_Pilot_Title::cut_preview( str_repeat( 'Wide words here ', 10 ) );
t_ok( $cut && '…' === mb_substr( $pv, -1 ), 'cut_preview shortens an over-long title with an ellipsis' );
list( $pv2, $cut2 ) = SEO_Pilot_Title::cut_preview( 'Short title' );
t_ok( ! $cut2 && 'Short title' === $pv2, 'cut_preview leaves a short title alone' );

/* ---- empty / template variables ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => '' ) ) );
t_ok( ! $r['analyzed'] && ! $r['checks'], 'empty title is not analysed' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'seo_title' => '%%title%% %%sep%% %%sitename%%', 'template_vars' => true ) ) );
t_ok( ! $r['analyzed'] && 'manual' === $r['checks'][0]['status'], 'template-variable SEO title is not measured literally' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'seo_title' => 'Beginner sourdough guide', 'title' => 'x' ) ) );
t_eq( 'seo_title', $r['source'], 'explicit SEO title is the analysed title' );
t_eq( 'page_title', SEO_Pilot_Title::analyze( title_in() )['source'], 'page title is used when there is no SEO title' );

/* ---- width status ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'How to bake sourdough bread at home' ) ) );
t_eq( 'pass', title_check( $r, 'width' )['status'], 'normal title fits the estimated width' );
t_eq( 'estimate', title_check( $r, 'width' )['basis'], 'width check is labelled an estimate' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'The complete beginner guide to baking sourdough bread at home with a starter, flour and water' ) ) );
t_eq( 'warn', title_check( $r, 'width' )['status'], 'very long title is likely cut' );
t_ok( ! empty( title_check( $r, 'width' )['evidence'] ), 'cut-off check shows how the title may appear' );
// ~55 characters of wide capitals pass the 60-character rule but not the width estimate
$wide_title = 'WIDE WORDS MAKE WIDE TITLES WITH MANY WIDE CAPITALS HERE';
t_ok( SEO_Pilot_Text::len( $wide_title ) <= 60, 'sanity: capitals title is within 60 characters' );
t_eq( 'warn', title_check( SEO_Pilot_Title::analyze( title_in( array( 'title' => $wide_title ) ) ), 'width' )['status'], 'wide capitals exceed the width estimate before 60 characters' );

/* ---- keyword ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'primary' => 'sourdough bread' ) ) );
t_eq( 'pass', title_check( $r, 'keyword' )['status'], 'keyword present at the start passes' );
t_ok( false !== strpos( title_check( $r, 'keyword' )['detail'], 'near the start' ), 'keyword position reported (start)' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'A friendly guide for beginners to sourdough bread', 'primary' => 'sourdough bread' ) ) );
t_ok( false !== strpos( title_check( $r, 'keyword' )['detail'], 'near the end' ), 'keyword position reported (end)' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Baking guide', 'primary' => 'sourdough bread' ) ) );
t_eq( 'warn', title_check( $r, 'keyword' )['status'], 'missing keyword is flagged' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Bread for the sourdough lover', 'primary' => 'sourdough bread' ) ) );
t_eq( 'info', title_check( $r, 'keyword' )['status'], 'all keyword words present but separated is informational' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough guide', 'primary' => 'sourdough bread' ) ) );
t_ok( false !== strpos( title_check( $r, 'keyword' )['detail'], 'Only part' ), 'partial keyword match is described' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough bread: the sourdough bread guide', 'primary' => 'sourdough bread' ) ) );
t_eq( 'warn', title_check( $r, 'keyword_repeat' )['status'], 'repeated keyword is flagged' );
$r = SEO_Pilot_Title::analyze( title_in() );
t_eq( 'na', title_check( $r, 'keyword' )['status'], 'no keyword set: keyword check is skipped, not failed' );
$long_kw = 'Baking basics and many more words that fill the line up well beyond the visible limit of a result: sourdough bread';
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => $long_kw, 'primary' => 'sourdough bread' ) ) );
t_eq( 'warn', title_check( $r, 'keyword' )['status'], 'keyword that falls in the cut-off part is flagged' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'دليل تحسين محركات البحث', 'primary' => 'التحسين', 'text' => 'نص عن التحسين', 'word_count' => 3 ) ) );
t_ok( 'na' !== title_check( $r, 'keyword' )['status'], 'Arabic keyword is handled' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'والتحسين مهم للمواقع', 'primary' => 'تحسين' ) ) );
t_eq( 'pass', title_check( $r, 'keyword' )['status'], 'Arabic keyword matches through an attached prefix' );

/* ---- brand ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Beginner sourdough bread baking guide | Example Bakery' ) ) );
t_eq( 'pass', title_check( $r, 'brand' )['status'], 'a short site name suffix is fine' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Bread | Example Bakery And Cafe', 'site_name' => 'Example Bakery And Cafe' ) ) );
t_eq( 'info', title_check( $r, 'brand' )['status'], 'a site name that takes most of the title is noted' );
t_eq( null, title_check( SEO_Pilot_Title::analyze( title_in() ), 'brand' ), 'no brand check when the title does not contain the site name' );

/* ---- clarity ---- */
t_eq( 'info', title_check( SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Home' ) ) ), 'clarity' )['status'], 'placeholder title is noted' );
t_eq( 'info', title_check( SEO_Pilot_Title::analyze( title_in( array( 'title' => 'الرئيسية' ) ) ), 'clarity' )['status'], 'Arabic placeholder title is noted' );
t_eq( 'info', title_check( SEO_Pilot_Title::analyze( title_in( array( 'title' => 'BUY OUR AMAZING BREAD TODAY' ) ) ), 'clarity' )['status'], 'all-capitals title is noted' );
t_eq( 'info', title_check( SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Bread!! Best bread!!' ) ) ), 'clarity' )['status'], 'repeated exclamation marks are noted' );
t_eq( 'pass', title_check( SEO_Pilot_Title::analyze( title_in() ), 'clarity' )['status'], 'ordinary title shows no clarity flags' );
t_eq( 'heuristic', title_check( SEO_Pilot_Title::analyze( title_in() ), 'clarity' )['basis'], 'clarity check is labelled a heuristic' );

/* ---- alignment ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough starter guide' ) ) );
t_eq( 'pass', title_check( $r, 'alignment' )['status'], 'title words found in headings, opening and body pass' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Motorcycle maintenance schedule' ) ) );
t_eq( 'warn', title_check( $r, 'alignment' )['status'], 'title words absent from the body warn' );
t_ok( in_array( 'motorcycle', title_check( $r, 'alignment' )['evidence'], true ), 'alignment evidence lists the missing words' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough breads', 'text' => str_repeat( 'We bake loaf after loaf of sourdough bread each day. ', 15 ), 'word_count' => 150, 'headings' => array() ) ) );
t_ok( 'warn' !== title_check( $r, 'alignment' )['status'], 'plural title word matches singular body word' );
t_eq( 'manual', title_check( SEO_Pilot_Title::analyze( title_in( array( 'text' => 'Short.', 'word_count' => 1 ) ) ), 'alignment' )['status'], 'too little text: alignment needs a human' );

/* ---- intent cue ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough bread', 'intent_label' => 'informational' ) ) );
t_eq( 'info', title_check( $r, 'intent' )['status'], 'title without an intent cue on an informational page is noted' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'How to bake sourdough bread', 'intent_label' => 'informational' ) ) );
t_eq( 'pass', title_check( $r, 'intent' )['status'], 'title with a matching intent cue passes' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough bread price list', 'intent_label' => 'informational' ) ) );
t_eq( 'info', title_check( $r, 'intent' )['status'], 'title cue that disagrees with the page intent is noted' );
t_eq( null, title_check( SEO_Pilot_Title::analyze( title_in( array( 'intent_label' => 'undetermined' ) ) ), 'intent' ), 'no intent check when the intent is undetermined' );

/* ---- duplicates ---- */
$pool = array(
    1 => array( 'label' => 'Sourdough bread guide', 'key' => SEO_Pilot_Text::normalize( 'Sourdough bread guide' ), 'tokens' => SEO_Pilot_Title::tokens( 'Sourdough bread guide' ) ),
    2 => array( 'label' => 'Sourdough bread guides', 'key' => SEO_Pilot_Text::normalize( 'Sourdough bread guides' ), 'tokens' => SEO_Pilot_Title::tokens( 'Sourdough bread guides' ) ),
    3 => array( 'label' => 'Motorcycle repair manual', 'key' => SEO_Pilot_Text::normalize( 'Motorcycle repair manual' ), 'tokens' => SEO_Pilot_Title::tokens( 'Motorcycle repair manual' ) ),
    4 => array( 'label' => 'Sourdough bread guide', 'key' => SEO_Pilot_Text::normalize( 'Sourdough bread guide' ), 'tokens' => SEO_Pilot_Title::tokens( 'Sourdough bread guide' ) ),
);
$r = SEO_Pilot_Title::analyze( title_in( array( 'pool' => $pool, 'exact_duplicates' => 1 ) ) );
t_eq( 'warn', title_check( $r, 'dup_near' )['status'], 'near-identical title on another page is flagged' );
t_eq( 1, count( $r['near_duplicates'] ), 'only the near duplicate is listed (not the page itself, not the exact duplicate, not unrelated titles)' );
t_eq( 'warn', title_check( $r, 'dup_exact' )['status'], 'exact duplicate count is shown' );
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Wholesale flour delivery schedule', 'pool' => $pool ) ) );
t_eq( 'pass', title_check( $r, 'dup_near' )['status'], 'unrelated title has no near duplicates' );
t_eq( array(), SEO_Pilot_Title::near_duplicates( SEO_Pilot_Title::tokens( 'Bread' ), 'bread', $pool, 99 ), 'very short titles are not compared' );
t_eq( SEO_Pilot_Title::light_stem( 'guide' ), SEO_Pilot_Title::light_stem( 'guides' ), 'light stem: guide / guides' );
t_eq( SEO_Pilot_Title::light_stem( 'walk' ), SEO_Pilot_Title::light_stem( 'walking' ), 'light stem: walk / walking' );
t_eq( 'تحسين', SEO_Pilot_Title::light_stem( 'تحسين' ), 'light stem leaves Arabic words alone' );

/* ---- opportunities ---- */
$r = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Home' ) ) );
t_ok( count( $r['opportunities'] ) >= 1 && isset( $r['opportunities'][0]['basis'] ), 'opportunities are derived from flagged checks and carry their basis' );
$good = SEO_Pilot_Title::analyze( title_in( array( 'title' => 'Sourdough starter guide', 'primary' => 'sourdough starter', 'intent_label' => 'informational' ) ) );
t_eq( array(), $good['opportunities'], 'a well-formed title raises no opportunities' );

/* ---- wired into the audit ---- */
$a = SEO_Pilot_Audit::analyze( array(
    'id' => 1, 'title' => $wide_title, 'url' => 'https://example.com/a/', 'content' => '<p>' . str_repeat( 'Some page text about wide words and capitals. ', 15 ) . '</p>',
    'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026,
    'title_pool' => $pool, 'site_name' => 'Example Bakery',
) );
t_ok( isset( $a['title_analysis'] ) && $a['title_analysis']['analyzed'], 'audit stores the title analysis' );
t_ok( in_array( 'title_width_estimate', finding_ids( $a ), true ), 'audit reports a width-based cut-off for a title under 60 characters' );
t_eq( 'warn', check_status( $a, 'title_width' ), 'checklist includes the width check' );
$a = SEO_Pilot_Audit::analyze( array( 'id' => 1, 'title' => 'Sourdough bread guides', 'url' => 'https://example.com/a/', 'content' => '<p>text</p>', 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026, 'title_pool' => $pool ) );
t_ok( in_array( 'title_near_duplicate', finding_ids( $a ), true ), 'audit reports near-duplicate titles' );
$a = SEO_Pilot_Audit::analyze( array( 'id' => 1, 'title' => 'Untitled', 'url' => 'https://example.com/a/', 'content' => '<p>text</p>', 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026 ) );
t_ok( in_array( 'title_generic', finding_ids( $a ), true ), 'audit reports placeholder titles' );
$a = SEO_Pilot_Audit::analyze( array( 'id' => 1, 'title' => '', 'url' => 'https://example.com/a/', 'content' => '<p>text</p>', 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026 ) );
t_ok( isset( $a['title_analysis'] ) && ! $a['title_analysis']['analyzed'], 'empty title: analysis present but not run' );
t_eq( '2', $a['engine_version'], 'audit engine version bumped so old results are refreshed' );

/* ---- AI title review (advisory) ---- */
t_ok( isset( SEO_Pilot_Prompt::tasks()['title_review'] ), 'title_review is an available AI task' );
t_ok( ! SEO_Pilot_Recs::is_applicable( 'title_review' ), 'title_review can never be applied directly' );
$sys = SEO_Pilot_Prompt::system_prompt( array( 'title_review' ), 'Latin-script text (likely English)', 'English' );
t_ok( false !== strpos( $sys, '"title_review"' ) && false !== strpos( $sys, 'Title review:' ), 'system prompt includes the schema and the rule when the task is chosen' );
$sys2 = SEO_Pilot_Prompt::system_prompt( array( 'seo_title' ), 'x', 'English' );
t_ok( false === strpos( $sys2, 'Title review:' ), 'rule is absent when the task is not chosen' );
$ctx = array( 'tasks' => array( 'title_review' ), 'segments' => array(), 'content_text' => 'Sourdough bread guide for beginners with 3 steps.', 'seo_title' => '', 'meta_description' => '', 'title' => 'Sourdough bread guide' );
$ai  = wp_json_encode( array( 'title_review' => array(
    'critique' => 'Clear but generic.', 'alternatives' => array(
        array( 'title' => 'Sourdough Bread for Beginners: 3 Steps', 'reason' => 'Names the audience.' ),
        array( 'title' => 'Sourdough bread guide', 'reason' => 'same as current' ),
        array( 'title' => 'Sourdough in 99 steps', 'reason' => 'invented number' ),
    ), 'verify' => array() ) ) );
$p = SEO_Pilot_Validator::parse( $ai, $ctx );
t_ok( ! is_wp_error( $p ) && 1 === count( $p['recs'] ) && 'title_review' === $p['recs'][0]['rec_type'], 'validator turns a title review into one advisory rec' );
$d = json_decode( $p['recs'][0]['proposed'], true );
t_eq( 2, count( $d['alternatives'] ), 'an alternative identical to the current title is dropped' );
t_ok( ! empty( $p['recs'][0]['flags']['notes'] ), 'an alternative with a figure not on the page gets a verify note' );
t_eq( 0, count( SEO_Pilot_Validator::parse( wp_json_encode( array( 'title_review' => array( 'critique' => '', 'alternatives' => array() ) ) ), $ctx )['recs'] ), 'an empty review creates nothing' );
