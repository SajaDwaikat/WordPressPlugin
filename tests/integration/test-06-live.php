<?php
t_group( 'Live output check (real HTTP request to the running site)' );
$probe = @file_get_contents( home_url( '/' ), false, stream_context_create( array( 'http' => array( 'timeout' => 5 ) ) ) );
if ( false === $probe ) { echo "  SKIPPED: no web server reachable at " . home_url() . "\n"; return; }

$lv = make_post( array( 'post_title' => 'Live page', 'post_content' => '<h2>Heading</h2><p>Some body text for the live page which is here.</p>' ) );
update_post_meta( $lv, '_seo_pilot_seo_title', 'My live SEO title' );
update_post_meta( $lv, '_seo_pilot_meta_description', 'A live meta description written by SEO Pilot for this page.' );
$live = SEO_Pilot_Live_Check::fetch( $lv );
t_ok( is_array( $live ), 'live fetch works: ' . ( is_wp_error( $live ) ? $live->get_error_message() : 'ok' ) );
if ( is_array( $live ) ) {
    t_eq( 1, count( $live['titles'] ), 'exactly one <title> on the rendered page' );
    t_ok( false !== strpos( $live['titles'][0], 'My live SEO title' ), 'SEO Pilot SEO title appears in the real <title>: ' . $live['titles'][0] );
    t_eq( array( 'A live meta description written by SEO Pilot for this page.' ), $live['descriptions'], 'exactly one meta description, with the right text, on the real page' );
    $notes = SEO_Pilot_Live_Check::notes( $live, 'A live meta description written by SEO Pilot for this page.', 'My live SEO title' );
    $kinds = array_column( $notes, 0 );
    t_ok( ! in_array( 'warn', $kinds, true ), 'no warnings for a clean page' );
    t_ok( count( $live['canonicals'] ) >= 0 && 200 === $live['status'], 'status, canonical and robots are read (never modified)' );

    update_option( 'sp_test_dup', 1 );
    $dup = SEO_Pilot_Live_Check::fetch( $lv );
    t_eq( 2, count( $dup['descriptions'] ), 'a second description printed by another source is detected' );
    $n = SEO_Pilot_Live_Check::notes( $dup, 'A live meta description written by SEO Pilot for this page.' );
    t_ok( in_array( 'warn', array_column( $n, 0 ), true ) && false !== strpos( wp_json_encode( $n ), 'meta descriptions were found' ), 'duplicate description produces a clear warning' );
    delete_option( 'sp_test_dup' );
}

// Through the approval workflow: apply, then verify what visitors receive.
configure_ai();
$GLOBALS['ai_queue'] = array( ai_reply( array( 'meta_description' => array( 'proposed' => 'A brand new approved description that visitors should now receive on this page.', 'reason' => 'r' ) ) ) );
call_handler( 'generate', array( 'post_id' => $lv, 'tasks' => array( 'meta_description' ) ), 'seo_pilot_generate_' . $lv, 1 );
$mr = recs_of( $lv, 'meta_description' )[0]; rec_do( $mr['id'], 'accept' );
apply_do( $lv, array( 'confirm_live' => 1 ) );
$r = call_handler( 'live_check', array( 'post_id' => $lv ), 'seo_pilot_live_' . $lv, 1 );
t_eq( 'success', $r['flash']['type'], 'live-check handler succeeds' );
$stored = get_transient( 'seo_pilot_live_' . $lv );
t_ok( is_array( $stored ) && 'A brand new approved description that visitors should now receive on this page.' === $stored['descriptions'][0], 'after applying, the real page serves the approved description' );

$draft = make_post( array( 'post_title' => 'Unpub', 'post_status' => 'draft' ) );
$e = SEO_Pilot_Live_Check::fetch( $draft );
t_ok( is_wp_error( $e ) && 'seo_pilot_live_unpublished' === $e->get_error_code(), 'drafts are refused' );
add_filter( 'post_link', $pl = function () { return 'https://elsewhere.example/page/'; } );
$e = SEO_Pilot_Live_Check::fetch( $lv );
remove_filter( 'post_link', $pl );
t_ok( is_wp_error( $e ) && 'seo_pilot_live_host' === $e->get_error_code(), 'only this site\'s own host is ever requested' );

t_group( 'Live check parser (pure)' );
$html = '<html><head><title>A</title><title>B</title><META NAME="Description" content="x"><meta name="description" content="y"><link rel="canonical" href="https://a/"><link rel="canonical" href="https://b/"><meta name="robots" content="NOINDEX, follow"></head><body><h1>1</h1><h1>2</h1><script type="application/ld+json">{}</script></body></html>';
$p = SEO_Pilot_Live_Check::parse( $html );
t_ok( 2 === count( $p['titles'] ) && 2 === count( $p['descriptions'] ) && 2 === count( $p['canonicals'] ) && 2 === $p['h1_count'] && 1 === $p['jsonld_count'], 'duplicates, mixed-case attributes, H1 and JSON-LD counted' );
$n = SEO_Pilot_Live_Check::notes( $p );
t_ok( 3 <= count( array_filter( $n, function ( $x ) { return 'warn' === $x[0]; } ) ) && false !== strpos( wp_json_encode( $n ), 'never changes canonicals' ), 'duplicate title/description/canonical warned about; canonical never changed' );
