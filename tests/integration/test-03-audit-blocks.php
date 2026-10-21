<?php
t_group( 'Audit runner on real posts' );
configure_ai();
$GLOBALS['sp_posts'] = array();
$blocks = "<!-- wp:paragraph -->\n<p>We bake sourdough bread every morning with <a href=\"/about/\">our team</a>.</p>\n<!-- /wp:paragraph -->\n\n"
    . "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph {\"className\":\"nested\"} -->\n<p class=\"nested\">A nested paragraph that is rather wordy and repeats itself a lot and a lot.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n"
    . "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/a.jpg\"/></figure>\n<!-- /wp:image -->";
$p_blocks = make_post( array( 'post_title' => 'Block post', 'post_content' => $blocks ) );
$p_ar     = make_post( array( 'post_title' => 'دليل تحسين محركات البحث', 'post_content' => '<h2>مقدمة</h2><p>' . str_repeat( 'نتحدث في هذا المقال عن تحسين محركات البحث للمواقع الصغيرة. ', 8 ) . '</p>' ) );
$res = SEO_Pilot_Audit_Runner::audit_post( $p_ar );
t_ok( 'ar' === $res['language'] && $res['rtl'], 'Arabic post detected as RTL' );
$saved = SEO_Pilot_Audit_Runner::get_saved( $p_ar );
t_eq( $res['title'], $saved['title'], 'Arabic audit survives the post-meta JSON round trip (slashing handled)' );
t_ok( ! in_array( 'title_long', array_column( $saved['findings'], 'id' ), true ), 'Arabic title not flagged long' );

$n = SEO_Pilot_Audit_Runner::audit_all( 300 );
t_ok( $n >= 3, 'audit_all audits published posts (' . $n . ')' );
$sum = get_option( SEO_Pilot_Audit_Runner::SUMMARY_OPTION );
t_ok( isset( $sum['items'][ $p_ar ]['issue_count'] ), 'overview summary option written' );

t_group( 'Duplicates and cannibalization' );
$d1 = make_post( array( 'post_title' => 'Dup A', 'post_content' => '<p>one</p>' ) ); $d2 = make_post( array( 'post_title' => 'Dup B', 'post_content' => '<p>two</p>' ) );
update_post_meta( $d1, '_seo_pilot_seo_title', 'Exactly the same title' ); update_post_meta( $d2, '_seo_pilot_seo_title', 'Exactly the same title' );
update_post_meta( $d1, SEO_Pilot_Audit_Runner::META_PRIMARY, 'gluten free bread' ); update_post_meta( $d2, SEO_Pilot_Audit_Runner::META_PRIMARY, 'Gluten Free Bread' );
SEO_Pilot_Audit_Runner::index( true );
$a = SEO_Pilot_Audit_Runner::audit_post( $d1 );
$ids = array_column( $a['findings'], 'id' );
t_ok( in_array( 'duplicate_title', $ids, true ), 'duplicate SEO title found across posts' );
t_ok( in_array( 'kw_cannibalization', $ids, true ), 'same primary keyword on two pages flagged (case-insensitive)' );

t_group( 'SEO plugin compatibility' );
t_eq( 'seo_pilot', SEO_Pilot_Compat::owner(), 'no SEO plugin: SEO Pilot owns the fields' );
add_filter( 'seo_pilot_detected_seo_plugins', $yoast = function () { return array( 'yoast' ); } );
t_eq( 'yoast', SEO_Pilot_Compat::owner(), 'Yoast detected as owner' );
update_post_meta( $d1, '_yoast_wpseo_metadesc', 'Yoast says this %%sitename%%' );
$g = SEO_Pilot_Compat::get( $d1, 'meta_description' );
t_ok( 'Yoast SEO' === $g['owner_label'] && $g['template_vars'] && 'Yoast says this %%sitename%%' === $g['value'], 'reads Yoast field and notices template variables' );
update_post_meta( $d2, '_seo_pilot_meta_description', 'Orphaned MVP description' );
$a2 = SEO_Pilot_Audit_Runner::audit_post( $d2 );
t_ok( in_array( 'meta_orphaned', array_column( $a2['findings'], 'id' ), true ), 'MVP description hidden behind Yoast is reported, not silently counted as present' );
ob_start(); $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => $d2 ) ); SEO_Pilot::instance()->output_meta_description(); $out = ob_get_clean();
t_eq( '', $out, 'SEO Pilot prints no meta description while Yoast is active' );
t_eq( 'Original', SEO_Pilot::instance()->filter_document_title( 'Original' ), 'SEO Pilot leaves the <title> alone while Yoast is active' );
$w = SEO_Pilot_Compat::set( $d2, 'meta_description', 'New "quoted" text \\ with a backslash', 'yoast' );
t_ok( true === $w && 'New "quoted" text \\ with a backslash' === get_post_meta( $d2, '_yoast_wpseo_metadesc', true ), 'write to Yoast meta keeps quotes and backslashes intact' );
add_filter( 'seo_pilot_detected_seo_plugins', $aio = function () { return array( 'aioseo' ); }, 20 );
remove_filter( 'seo_pilot_detected_seo_plugins', $yoast );
$w = SEO_Pilot_Compat::set( $d2, 'meta_description', 'x' );
t_ok( is_wp_error( $w ) && 'seo_pilot_owner_readonly' === $w->get_error_code(), 'All in One SEO is read-only' );
t_eq( '', SEO_Pilot_Compat::get( $d2, 'meta_description' )['value'], 'AIOSEO read fails safe to empty when its table is absent' );
remove_filter( 'seo_pilot_detected_seo_plugins', $aio, 20 );
t_eq( 'seo_pilot', SEO_Pilot_Compat::owner(), 'owner back to SEO Pilot' );
update_post_meta( $d2, '_yoast_wpseo_meta-robots-noindex', '1' );
t_ok( SEO_Pilot_Compat::robots_noindex( $d2 )['noindex'], 'Yoast noindex read (read-only)' );
update_option( 'blog_public', '0' ); t_ok( SEO_Pilot_Compat::site_discourages_search(), 'discourage-search-engines detected' ); update_option( 'blog_public', '1' );

t_group( 'Own front-end output' );
update_post_meta( $d2, '_seo_pilot_meta_description', 'My <b>safe</b> "description"' ); update_post_meta( $d2, '_seo_pilot_seo_title', 'My SEO title' );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => $d2 ) );
ob_start(); SEO_Pilot::instance()->output_meta_description(); $out = ob_get_clean();
t_ok( false !== strpos( $out, 'name="description"' ) && false === strpos( $out, '<b>' ), 'meta description printed and escaped when no SEO plugin is active' );
t_eq( 'My SEO title', SEO_Pilot::instance()->filter_document_title( 'Original' ), 'SEO title used when no SEO plugin is active' );
wp_reset_query();

t_group( 'Paragraph + alt edits on block content (real WordPress parser)' );
$post = get_post( $p_blocks );
$segs = SEO_Pilot_Parser::paragraph_segments( $post->post_content );
t_eq( 2, count( $segs ), 'paragraphs found at top level AND inside a group block' );
t_eq( 'block', $segs[0]['mode'], 'block mode used' );
$new = SEO_Pilot_Parser::replace_paragraph( $post->post_content, $segs[1]['hash'], 'A tighter nested paragraph.' );
t_ok( is_string( $new ), 'nested paragraph replaced' );
t_ok( false !== strpos( $new, '<p class="nested">A tighter nested paragraph.</p>' ), 'wrapper class kept' );
t_ok( false !== strpos( $new, '<!-- wp:paragraph {"className":"nested"} -->' ), 'block comment attributes kept' );
t_eq( substr( $post->post_content, 0, strpos( $post->post_content, '<!-- wp:group' ) ), substr( $new, 0, strpos( $new, '<!-- wp:group' ) ), 'everything before the edit is byte-identical' );
t_eq( substr( $post->post_content, strpos( $post->post_content, '<!-- wp:image' ) ), substr( $new, strpos( $new, '<!-- wp:image' ) ), 'everything after the edit is byte-identical' );
t_eq( $new, serialize_blocks( parse_blocks( $new ) ), 'result is a valid block document (round-trips through the parser)' );
$new1 = SEO_Pilot_Parser::replace_paragraph( $post->post_content, $segs[0]['hash'], 'We bake <a href="/about/">our team</a> bread.' );
t_ok( is_string( $new1 ) && false !== strpos( $new1, 'href="/about/"' ), 'inline link preserved in a block paragraph' );
list( $with_alt, $cnt ) = SEO_Pilot_Parser::set_img_alt( $post->post_content, 'https://example.com/a.jpg', 'A loaf of bread' );
t_eq( 1, $cnt, 'alt added to the block image' );
t_eq( $with_alt, serialize_blocks( parse_blocks( $with_alt ) ), 'image block still valid' );
