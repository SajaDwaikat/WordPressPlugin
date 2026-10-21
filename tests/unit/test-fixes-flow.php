<?php
t_group( 'Fix assistant: real audits end to end' );

function ff_page( array $o = array() ) {
    $body = '<h2>Overview</h2><p>' . str_repeat( 'This page explains how our bakery makes sourdough bread every morning with care. ', 12 ) . '</p><img src="https://example.com/a.jpg" class="wp-image-7">';
    return array_merge( array(
        'id' => 1, 'title' => 'Sourdough bread guide', 'url' => 'https://example.com/sourdough/', 'content' => $body, 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026,
        'meta_description' => array( 'value' => '', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
        'image_data' => array( 'by_id' => array( 7 => array( 'library_alt' => 'A loaf of sourdough bread on a wooden board', 'filesize' => 0 ) ), 'featured' => null ),
    ), $o );
}
$ctx = array( 'ai_ready' => true, 'images_ready' => true, 'library_count' => 1, 'alt_ai_count' => 1, 'has_source' => false, 'title_writable' => true, 'meta_writable' => true, 'owner_label' => 'SEO Pilot' );

/* A page with a missing meta description and an image without alt text. */
$before = SEO_Pilot_Audit::analyze( ff_page() );
$ids    = finding_ids( $before );
t_ok( in_array( 'meta_missing', $ids, true ) && in_array( 'alt_missing', $ids, true ), 'sanity: the real audit reports both problems' );
$fixes = SEO_Pilot_Fixes::for_audit( $before, $ctx );
t_ok( in_array( 'meta_fill', array_map( function ( $f ) { return $f['id']; }, $fixes ), true ), 'the catalog offers a fix for the real "meta_missing" finding' );
t_ok( in_array( 'alt_library', array_map( function ( $f ) { return $f['id']; }, $fixes ), true ), 'and for the real "alt_missing" finding' );

/* Apply the fixes to the inputs the way Approval would, then audit again. */
$fixed_html = '<h2>Overview</h2><p>' . str_repeat( 'This page explains how our bakery makes sourdough bread every morning with care. ', 12 ) . '</p>';
list( $with_alt, $n ) = SEO_Pilot_Parser::set_img_alt( ff_page()['content'], 'https://example.com/a.jpg', 'A loaf of sourdough bread on a wooden board' );
t_eq( 1, $n, 'the alt text is added to the image' );
$after = SEO_Pilot_Audit::analyze( ff_page( array(
    'content' => $with_alt,
    'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and a little patience.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
) ) );
$targets = SEO_Pilot_Fixes::targets_for_rec_types( array( 'meta_description', 'alt_text' ), array_keys( SEO_Pilot_Verify::snapshot( $before ) ) );
t_eq( array( 'meta_missing', 'alt_missing' ), $targets, 'targets are the findings that existed and belong to the applied types' );
$v = SEO_Pilot_Verify::compare( $before, $after, $targets );
t_eq( 'verified', $v['verdict'], 'after the fixes the real re-audit verifies them' );
t_eq( array( 'meta_missing', 'alt_missing' ), array_map( function ( $x ) { return $x['id']; }, $v['resolved'] ), 'both findings are reported as solved' );

/* Only the meta description was fixed: the alt problem must not be claimed as solved. */
$partial_after = SEO_Pilot_Audit::analyze( ff_page( array( 'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and a little patience.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ) ) ) );
$v = SEO_Pilot_Verify::compare( $before, $partial_after, $targets );
t_eq( 'partial', $v['verdict'], 'fixing one of two targeted problems is partly improved' );
t_eq( array( 'alt_missing' ), array_map( function ( $x ) { return $x['id']; }, $v['remaining'] ), 'and the remaining one is named' );

/* A bad change: the new meta description is far too long. */
$bad_after = SEO_Pilot_Audit::analyze( ff_page( array( 'content' => $with_alt, 'meta_description' => array( 'value' => str_repeat( 'Very long description text. ', 12 ), 'owner_label' => 'SEO Pilot', 'template_vars' => false ) ) ) );
$v = SEO_Pilot_Verify::compare( $before, $bad_after, array( 'meta_missing', 'alt_missing' ) );
t_eq( 'verified', $v['verdict'], 'a too-long description still solves "missing"; the new LOW finding is not a regression' );
t_ok( in_array( 'meta_long', array_map( function ( $x ) { return $x['id']; }, $v['new'] ), true ), 'but the new low-severity finding is shown so the editor sees it' );

/* A change that breaks something serious is flagged. */
$broken = ff_page( array( 'content' => '', 'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and a little patience.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ) ) );
$v = SEO_Pilot_Verify::compare( $before, SEO_Pilot_Audit::analyze( $broken ), array( 'meta_missing' ) );
t_eq( 'regressed', $v['verdict'], 'emptying the content (a new medium finding) is reported as a regression' );

/* A page with nothing the assistant can fix. */
$clean = SEO_Pilot_Audit::analyze( ff_page( array( 'content' => $with_alt, 'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and a little patience.', 'owner_label' => 'SEO Pilot', 'template_vars' => false ) ) ) );
$left = array_map( function ( $f ) { return $f['id']; }, SEO_Pilot_Fixes::for_audit( $clean, $ctx ) );
t_ok( ! in_array( 'meta_fill', $left, true ) && ! in_array( 'alt_library', $left, true ), 'once fixed, the assistant no longer offers those fixes' );

/* Every finding id the catalog and the target map rely on really exists in the engine. */
$source = file_get_contents( SEO_PILOT_PATH . 'includes/audit/class-seo-pilot-audit.php' );
$used = array();
foreach ( SEO_Pilot_Fixes::defs() as $d ) { $used = array_merge( $used, $d['findings'] ); }
foreach ( array( 'meta_description', 'seo_title', 'alt_text' ) as $t ) { $used = array_merge( $used, SEO_Pilot_Fixes::targets_for_rec_types( array( $t ), array( 'meta_missing', 'meta_long', 'meta_short', 'duplicate_meta', 'lang_meta_identical', 'lang_meta_mismatch', 'title_long', 'title_short', 'title_width_estimate', 'title_generic', 'title_near_duplicate', 'duplicate_title', 'lang_title_identical', 'alt_missing', 'alt_suspicious', 'alt_empty_in_link', 'alt_quality', 'alt_duplicate' ) ) ); }
$missing = array();
foreach ( array_unique( $used ) as $id ) {
    // ids built from a prefix ("duplicate_" . $k) are checked by their parts
    $found = false !== strpos( $source, "'" . $id . "'" ) || ( 0 === strpos( $id, 'duplicate_' ) && false !== strpos( $source, "'duplicate_'" ) );
    if ( ! $found ) { $missing[] = $id; }
}
t_eq( array(), $missing, 'every finding id used by the catalog exists in the audit engine' );
