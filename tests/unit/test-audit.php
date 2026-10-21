<?php
t_group( 'Audit engine' );

function base_page( array $o = array() ) {
    $body = '<h2>Overview</h2><p>' . str_repeat( 'This page explains how our bakery makes sourdough bread every morning with care. ', 12 ) . '</p>';
    return array_merge( array(
        'id' => 1, 'title' => 'Sourdough bread guide', 'url' => 'https://example.com/sourdough/', 'content' => $body,
        'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026,
        'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and patience.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
    ), $o );
}

/* ---- the MVP's Arabic byte-length bug ---- */
$ar_title = 'دليل شامل لتحسين محركات البحث للمواقع الصغيرة'; // 44 chars, ~80 bytes
t_ok( strlen( $ar_title ) > 60 && SEO_Pilot_Text::len( $ar_title ) <= 60, 'sanity: Arabic title is over 60 bytes but under 60 characters' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'title' => $ar_title ) ) );
t_ok( ! in_array( 'title_long', finding_ids( $a ), true ), 'Arabic title is NOT flagged as too long (characters, not bytes)' );
$ar_meta = str_repeat( 'وصف مختصر ', 10 ); // 100 chars, 190 bytes
$a = SEO_Pilot_Audit::analyze( base_page( array( 'meta_description' => array( 'value' => trim( $ar_meta ), 'owner_label' => 'x', 'template_vars' => false ) ) ) );
t_ok( ! in_array( 'meta_long', finding_ids( $a ), true ), 'Arabic meta description of ~100 chars is not "too long"' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'title' => str_repeat( 'word ', 15 ) ) ) );
t_ok( in_array( 'title_long', finding_ids( $a ), true ), 'long English title flagged' );

/* ---- meta ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'meta_description' => array( 'value' => '', 'owner_label' => 'x', 'template_vars' => false ) ) ) );
t_ok( in_array( 'meta_missing', finding_ids( $a ), true ), 'missing meta description flagged' );
t_eq( 'fail', check_status( $a, 'meta_present' ), 'checklist reflects missing meta description' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'meta_description' => array( 'value' => 'Welcome to %%sitename%% — %%title%%', 'owner_label' => 'Yoast SEO', 'template_vars' => true ) ) ) );
t_eq( 'manual', check_status( $a, 'meta_length' ), 'template-variable descriptions are not measured literally' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'orphan_meta' => 'Old text' ) ) );
t_ok( in_array( 'meta_orphaned', finding_ids( $a ), true ), 'orphaned SEO Pilot description reported (MVP bug 2)' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'duplicates' => array( 'title' => array( array( 'id' => 9, 'title' => 'Other', 'url' => '' ) ), 'meta' => array() ) ) ) );
t_ok( in_array( 'duplicate_title', finding_ids( $a ), true ), 'duplicate title reported' );

/* ---- indexing (read-only) ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'noindex' => array( 'noindex' => true, 'source' => 'Yoast SEO' ) ) ) );
t_ok( in_array( 'page_noindex', finding_ids( $a ), true ), 'noindex reported' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'site_noindex' => true ) ) );
t_ok( in_array( 'site_noindex', finding_ids( $a ), true ), 'site-wide discourage-search-engines reported' );

/* ---- thin content is never called low quality ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<p>Call us on weekdays.</p>' ) ) );
$f = null; foreach ( $a['findings'] as $x ) { if ( 'short_content' === $x['id'] ) { $f = $x; } }
t_ok( null !== $f && 'low' === $f['severity'] && $f['needs_human'], 'short content: low severity and needs a human' );
t_ok( false !== stripos( $f['why'], 'perfectly fine' ), 'short content message says short pages can be fine' );
foreach ( $a['findings'] as $x ) { t_ok( false === stripos( $x['what'] . $x['why'], 'low quality' ), 'no finding calls content "low quality" (' . $x['id'] . ')' ); }
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '' ) ) );
t_ok( in_array( 'empty_content', finding_ids( $a ), true ), 'empty stored content explained (page builder caveat)' );

/* ---- structure ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<h2>A</h2><p>text</p><h4>Skipped</h4><p>more</p>' ) ) );
t_ok( in_array( 'heading_skip', finding_ids( $a ), true ), 'heading level skip H2 -> H4' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<h3>First is h3</h3><p>text</p>' ) ) );
t_ok( in_array( 'heading_skip', finding_ids( $a ), true ), 'first heading at H3 counts as a skip (title is H1)' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<h1>Title in body</h1><p>text</p>' ) ) );
t_ok( in_array( 'h1_in_content', finding_ids( $a ), true ), 'H1 inside content noted' );
$long = '<p>' . implode( ' ', array_fill( 0, 450, 'word' ) ) . '</p>';
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => $long ) ) );
t_ok( in_array( 'no_subheadings', finding_ids( $a ), true ), '450 words without sub-headings flagged' );

/* ---- readability / repetition ---- */
$rep = '<p>' . str_repeat( 'The quick brown fox jumps over the lazy dog today. ', 3 ) . '</p>';
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => $rep ) ) );
t_ok( in_array( 'repeated_sentences', finding_ids( $a ), true ), 'repeated sentence detected' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<p>At the end of the day, it goes without saying that bread is good. ' . str_repeat( 'We bake daily. ', 4 ) . '</p>' ) ) );
t_ok( in_array( 'filler_phrases', finding_ids( $a ), true ), 'English filler phrases detected' );

/* ---- freshness / verification: facts only ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<p>In 2019 we opened. Currently we sell 85% more bread than last year according to our survey of customers.</p>', 'modified' => '2024-01-01 10:00:00' ) ) );
$ids = finding_ids( $a );
t_ok( in_array( 'freshness_signals', $ids, true ) && in_array( 'claims_to_verify', $ids, true ), 'freshness and claim signals raised' );
foreach ( $a['findings'] as $x ) {
    if ( 'freshness_signals' === $x['id'] ) {
        t_ok( $x['needs_human'] && 'info' === $x['severity'], 'freshness signal is info + needs human' );
        t_ok( false !== strpos( $x['what'], '2019' ), 'freshness cites the year found in the text' );
        t_ok( false === stripos( $x['what'] . $x['why'], 'is outdated' ) && false === stripos( $x['what'] . $x['why'], 'was last updated' ), 'no invented claim that content is outdated or when it was updated' );
    }
}

/* ---- links ---- */
$resolver = function ( $href ) { return false !== strpos( $href, 'draft' ) ? 'unpublished' : ( false !== strpos( $href, 'mystery' ) ? 'unknown' : 'ok' ); };
$c = '<p>' . str_repeat( 'Lots of words about bread and baking in the morning. ', 8 ) . '<a href="https://example.com/draft-page/">a draft</a> <a href="/mystery/">m</a> <a href="https://www.example.com/fine/">fine</a> <a href="https://other.org/">ext</a> <a href="/wp-content/uploads/a.pdf">file</a> <a href="#top">top</a> <a href="/x/">click here</a></p>';
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => $c, 'link_resolver' => $resolver ) ) );
$ids = finding_ids( $a );
t_ok( in_array( 'link_unpublished', $ids, true ), 'link to unpublished content flagged' );
t_ok( in_array( 'link_unverified', $ids, true ), 'unmatched internal link flagged as unverified, not broken' );
t_ok( in_array( 'generic_anchor', $ids, true ), 'generic anchor flagged' );
t_eq( 4, $a['metrics']['internal_links'], 'internal links counted (www, relative; files/anchors/external excluded)' );
t_eq( 1, $a['metrics']['external_links'], 'external links counted' );
foreach ( $a['findings'] as $x ) { if ( 'link_unverified' === $x['id'] ) { t_ok( false !== strpos( $x['why'], 'sends no HTTP requests' ), 'unverified-link finding is honest about its limits' ); } }
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<p>' . str_repeat( 'Bread words and more bread words here today. ', 25 ) . '</p>' ) ) );
t_ok( in_array( 'no_internal_links', finding_ids( $a ), true ), 'no internal links on a long page' );

/* ---- images ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<h2>x</h2><p>t</p><img src="a.jpg"><img src="b.jpg" alt="IMG_1234"><img src="d.jpg" alt="">' ) ) );
$ids = finding_ids( $a );
t_ok( in_array( 'alt_missing', $ids, true ) && in_array( 'alt_suspicious', $ids, true ), 'missing and filename-like alt flagged' );
foreach ( $a['findings'] as $x ) {
    if ( 'alt_missing' === $x['id'] ) {
        t_eq( 1, count( $x['evidence'] ), 'only the image with NO alt attribute is listed (empty alt is decorative)' );
        t_eq( sha1( 'a.jpg' ), $x['evidence'][0]['hash'], 'evidence carries the src hash used for human-entered alt text' );
        t_ok( $x['needs_human'], 'alt text needs a human' );
    }
}

/* ---- keywords ---- */
$kw = function ( $p, $s = array() ) { return array( 'primary' => $p, 'secondary' => $s, 'primary_source' => 'seo_pilot' ); };
$a = SEO_Pilot_Audit::analyze( base_page( array( 'keywords' => $kw( 'sourdough bread' ) ) ) );
t_ok( ! in_array( 'kw_missing_title', finding_ids( $a ), true ) && ! in_array( 'kw_missing_body', finding_ids( $a ), true ), 'keyword present: no keyword findings' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'keywords' => $kw( 'gluten free cake' ) ) ) );
$ids = finding_ids( $a );
t_ok( in_array( 'kw_missing_title', $ids, true ) && in_array( 'kw_missing_body', $ids, true ), 'keyword absent from title and body flagged' );
$stuffed = '<p>' . str_repeat( 'cheap shoes ', 40 ) . 'some other words to pad the text out a little bit more.</p>';
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => $stuffed, 'keywords' => $kw( 'cheap shoes' ) ) ) );
t_ok( in_array( 'kw_overuse', finding_ids( $a ), true ), 'extreme keyword repetition flagged' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'keywords' => $kw( '' ) ) ) );
t_eq( 'info', check_status( $a, 'kw_set' ), 'no keyword: keyword checks skipped, not failed' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'keywords' => $kw( 'sourdough bread' ), 'cannibal' => array( array( 'id' => 5, 'title' => 'Other bread page', 'url' => '' ) ) ) ) );
t_ok( in_array( 'kw_cannibalization', finding_ids( $a ), true ), 'cannibalization flagged from other pages\' keywords' );
$a = SEO_Pilot_Audit::analyze( base_page( array( 'keywords' => $kw( 'sourdough bread', array( 'rye starter' ) ) ) ) );
t_ok( in_array( 'secondary_missing', finding_ids( $a ), true ), 'missing secondary keyword noted' );

/* ---- Arabic keyword matching through prefixes ---- */
$arc = '<h2>مقدمة</h2><p>' . str_repeat( 'نتحدث في هذا المقال عن تحسين محركات البحث للمواقع الصغيرة والمتوسطة. ', 6 ) . '</p>';
$a = SEO_Pilot_Audit::analyze( base_page( array( 'title' => 'دليل تحسين محركات البحث', 'content' => $arc, 'keywords' => $kw( 'تحسين محركات البحث' ) ) ) );
t_ok( ! in_array( 'kw_missing_title', finding_ids( $a ), true ) && ! in_array( 'kw_missing_body', finding_ids( $a ), true ), 'Arabic keyword found in title and body' );
t_ok( $a['rtl'] && 'ar' === $a['language'], 'Arabic page flagged RTL' );

/* ---- intent: user override, estimate retained ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'title' => 'How to bake bread', 'intent_user' => 'transactional' ) ) );
t_eq( 'transactional', $a['intent']['label'], 'user-chosen intent wins' );
t_eq( 'user', $a['intent']['source'], 'intent source recorded' );
t_eq( 'informational', $a['intent']['estimate']['label'], 'rule-based estimate kept alongside' );

/* ---- structural guarantees ---- */
$a = SEO_Pilot_Audit::analyze( base_page( array( 'content' => '<img src="a.jpg"><p>short</p>', 'title' => '', 'keywords' => $kw( 'zzz' ) ) ) );
foreach ( $a['findings'] as $x ) {
    t_ok( '' !== $x['what'] && '' !== $x['why'] && '' !== $x['action'], 'finding "' . $x['id'] . '" explains what, why and action' );
    t_ok( in_array( $x['severity'], array( 'high', 'medium', 'low', 'info' ), true ), 'finding "' . $x['id'] . '" has a valid severity' );
}
$prev = 99; $sorted = true;
foreach ( $a['findings'] as $x ) { $r = SEO_Pilot_Audit::severity_rank( $x['severity'] ); if ( $r > $prev ) { $sorted = false; } $prev = $r; }
t_ok( $sorted, 'findings are prioritised high -> info' );
t_ok( ! isset( $a['score'] ) && ! isset( $a['summary']['score'] ), 'no arbitrary numeric score is produced' );
t_ok( false !== strpos( $a['summary']['note'], 'not a Google ranking factor' ), 'assessment note disclaims ranking claims' );
foreach ( $a['checklist'] as $c ) { t_ok( in_array( $c['status'], array( 'pass', 'warn', 'fail', 'manual', 'info', 'na' ), true ), 'checklist status valid: ' . $c['id'] ); }
