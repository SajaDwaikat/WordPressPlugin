<?php
t_group( 'Fix assistant: catalog and verification' );

function fx_audit( array $findings ) {
    $out = array();
    foreach ( $findings as $f ) {
        $out[] = array_merge( array( 'severity' => 'low', 'what' => 'finding ' . $f['id'], 'evidence' => array() ), $f );
    }
    return array( 'findings' => $out );
}
function fx_ctx( array $o = array() ) {
    return array_merge( array( 'ai_ready' => true, 'images_ready' => true, 'library_count' => 2, 'alt_ai_count' => 2, 'has_source' => true, 'title_writable' => true, 'meta_writable' => true, 'owner_label' => 'SEO Pilot' ), $o );
}
function fx_ids( array $fixes ) { return array_map( function ( $f ) { return $f['id']; }, $fixes ); }
function fx_find( array $fixes, $id ) { foreach ( $fixes as $f ) { if ( $f['id'] === $id ) { return $f; } } return null; }

/* ---- catalog ---- */
$a = fx_audit( array( array( 'id' => 'meta_missing', 'severity' => 'medium' ), array( 'id' => 'title_long' ), array( 'id' => 'alt_missing', 'severity' => 'medium', 'evidence' => array( 1, 2 ) ), array( 'id' => 'lang_title_identical' ), array( 'id' => 'kw_missing_body' ) ) );
$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx() );
t_eq( array( 'title_improve', 'meta_fill', 'alt_library', 'alt_ai', 'localize' ), fx_ids( $f ), 'fixes are listed by area (title, meta, images, multilingual), lower risk first' );
t_ok( null === fx_find( $f, 'meta_improve' ), 'a fix is listed only when one of its findings is present' );
t_ok( null === fx_find( $f, 'kw_missing_body' ), 'findings without a fix are not invented into one' );
t_eq( array( 'meta_missing' ), fx_find( $f, 'meta_fill' )['targets'], 'each fix lists the findings it targets' );
t_ok( fx_find( $f, 'meta_fill' )['checked'] && fx_find( $f, 'alt_library' )['checked'] && fx_find( $f, 'alt_ai' )['checked'], 'low-risk (A) and fill-empty (B) fixes are ticked by default' );
t_ok( ! fx_find( $f, 'title_improve' )['checked'] && ! fx_find( $f, 'localize' )['checked'], 'fixes that replace existing text (C) are not ticked by default' );
t_eq( 'A', fx_find( $f, 'alt_library' )['risk'], 'Media Library alt text is class A' );
t_ok( '' !== fx_find( $f, 'title_improve' )['risk_text'], 'every fix explains its risk' );

$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx( array( 'ai_ready' => false, 'ai_problem' => 'Configure the AI endpoint.' ) ) );
t_ok( ! fx_find( $f, 'meta_fill' )['available'] && 'Configure the AI endpoint.' === fx_find( $f, 'meta_fill' )['unavailable'], 'AI fixes are unavailable, with the reason, when AI is not configured' );
t_ok( fx_find( $f, 'alt_library' )['available'] && ! fx_find( $f, 'meta_fill' )['checked'], 'local fixes stay available, unavailable ones are never ticked' );
$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx( array( 'images_ready' => false, 'images_problem' => 'Sending images is switched off.' ) ) );
t_ok( ! fx_find( $f, 'alt_ai' )['available'] && fx_find( $f, 'alt_library' )['available'], 'vision AI needs its own setting; the Media Library fix does not' );
$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx( array( 'library_count' => 0 ) ) );
t_ok( ! fx_find( $f, 'alt_library' )['available'], 'no Media Library text: that fix is unavailable' );
$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx( array( 'has_source' => false ) ) );
t_ok( ! fx_find( $f, 'localize' )['available'] && false !== strpos( fx_find( $f, 'localize' )['unavailable'], 'Language panel' ), 'localizing needs a linked translation and says where to link it' );
$f = SEO_Pilot_Fixes::for_audit( $a, fx_ctx( array( 'meta_writable' => false, 'owner_label' => 'All in One SEO' ) ) );
t_ok( ! fx_find( $f, 'meta_fill' )['available'] && false !== strpos( fx_find( $f, 'meta_fill' )['unavailable'], 'All in One SEO' ), 'a read-only SEO plugin field makes the fix unavailable, naming the plugin' );
t_ok( fx_find( $f, 'title_improve' )['available'], 'and only the field that cannot be written' );
t_eq( array(), SEO_Pilot_Fixes::for_audit( fx_audit( array() ), fx_ctx() ), 'a clean page has no fixes' );

/* ---- task planning ---- */
$p = SEO_Pilot_Fixes::plan_tasks( array( 'meta_fill', 'title_improve', 'alt_library', 'alt_ai' ) );
t_eq( array( 'meta_description', 'seo_title' ), $p['ai_tasks'], 'title and meta fixes become one AI request with both tasks' );
t_ok( $p['library'] && $p['alt_vision'], 'alt fixes are handled separately from the text request' );
$p = SEO_Pilot_Fixes::plan_tasks( array( 'meta_fill', 'title_improve', 'localize' ) );
t_eq( array( 'localize' ), $p['ai_tasks'], 'localizing replaces the separate title and description tasks (it produces both)' );
t_eq( array( 'ai_tasks' => array(), 'library' => false, 'alt_vision' => false ), SEO_Pilot_Fixes::plan_tasks( array( 'nonsense' ) ), 'unknown fixes are ignored' );

/* ---- which findings should the applied recs have solved ---- */
t_eq( array( 'meta_missing', 'title_long' ), SEO_Pilot_Fixes::targets_for_rec_types( array( 'meta_description', 'seo_title' ), array( 'meta_missing', 'title_long', 'alt_missing' ) ), 'targets follow the applied recommendation types and the findings that existed' );
t_eq( array( 'alt_missing' ), SEO_Pilot_Fixes::targets_for_rec_types( array( 'alt_text' ), array( 'alt_missing', 'meta_missing' ) ), 'alt text targets the alt findings' );
t_eq( array(), SEO_Pilot_Fixes::targets_for_rec_types( array( 'rewrite' ), array( 'meta_missing' ) ), 'types without a mapping target nothing' );

/* ---- default ticking of proposals ---- */
t_ok( SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'meta_description', 'source' => 'ai', 'original' => '', 'flags' => array( 'verify' => array(), 'notes' => array() ) ) ), 'clean AI text for an empty field is ticked' );
t_ok( ! SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'meta_description', 'source' => 'ai', 'original' => 'Existing text', 'flags' => array( 'verify' => array(), 'notes' => array() ) ) ), 'AI text replacing existing text is not ticked' );
t_ok( ! SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'meta_description', 'source' => 'ai', 'original' => '', 'flags' => array( 'verify' => array(), 'notes' => array( 'Contains figure(s) not found in the page text: 5. Confirm or remove.' ) ) ) ), 'AI text with a warning is not ticked even for an empty field' );
t_ok( ! SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'alt_text', 'source' => 'ai', 'original' => 'a.jpg', 'flags' => array( 'verify' => array( 'compare it with the image' ), 'notes' => array(), 'mode' => 'add' ) ) ), 'AI alt text always needs a look at the image, so it is not ticked' );
t_ok( SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'alt_text', 'source' => 'local', 'original' => 'a.jpg', 'flags' => array( 'verify' => array(), 'notes' => array( 'Media Library text is written once for every use.' ), 'mode' => 'add' ) ) ), 'Media Library alt text is ticked despite its advisory note' );
t_ok( SEO_Pilot_Fixes::rec_checked( array( 'rec_type' => 'alt_text', 'source' => 'user', 'original' => 'a.jpg', 'flags' => array( 'verify' => array(), 'notes' => array() ) ) ), 'text the editor typed is ticked' );

/* ---- verification ---- */
$before = fx_audit( array( array( 'id' => 'meta_missing', 'severity' => 'medium' ), array( 'id' => 'alt_missing', 'severity' => 'medium', 'evidence' => array( 1, 2, 3 ) ), array( 'id' => 'title_long' ) ) );
$v = SEO_Pilot_Verify::compare( $before, fx_audit( array( array( 'id' => 'title_long' ) ) ), array( 'meta_missing', 'alt_missing' ) );
t_eq( 'verified', $v['verdict'], 'all targeted findings gone and nothing new: verified' );
t_eq( array( 'meta_missing', 'alt_missing' ), array_map( function ( $x ) { return $x['id']; }, $v['resolved'] ), 'resolved findings are listed' );
t_eq( 1, $v['severity_after']['low'] + $v['severity_after']['medium'] + $v['severity_after']['high'], 'severity counts after are reported' );
t_eq( 2, $v['severity_before']['medium'], 'and before' );

$v = SEO_Pilot_Verify::compare( $before, fx_audit( array( array( 'id' => 'alt_missing', 'severity' => 'medium', 'evidence' => array( 1 ) ), array( 'id' => 'title_long' ) ) ), array( 'meta_missing', 'alt_missing' ) );
t_eq( 'partial', $v['verdict'], 'a smaller problem counts as partly improved' );
t_eq( array( 3 ), array_map( function ( $x ) { return $x['from']; }, $v['improved'] ), 'and says how much smaller' );

$v = SEO_Pilot_Verify::compare( $before, $before, array( 'meta_missing' ) );
t_eq( 'not_resolved', $v['verdict'], 'nothing changed: not resolved' );
t_eq( array( 'meta_missing' ), array_map( function ( $x ) { return $x['id']; }, $v['remaining'] ), 'remaining findings are listed' );

$v = SEO_Pilot_Verify::compare( $before, fx_audit( array( array( 'id' => 'title_long' ), array( 'id' => 'page_noindex', 'severity' => 'high' ) ) ), array( 'meta_missing', 'alt_missing' ) );
t_eq( 'regressed', $v['verdict'], 'a new high-severity finding is a regression even if the targets are gone' );
t_eq( array( 'page_noindex' ), array_map( function ( $x ) { return $x['id']; }, $v['new'] ), 'new findings are listed' );

$v = SEO_Pilot_Verify::compare( $before, fx_audit( array( array( 'id' => 'title_long' ), array( 'id' => 'heading_skip', 'severity' => 'low' ) ) ), array( 'meta_missing', 'alt_missing' ) );
t_eq( 'verified', $v['verdict'], 'a new LOW finding does not make a regression' );

$wb = fx_audit( array( array( 'id' => 'meta_missing', 'severity' => 'medium' ), array( 'id' => 'empty_content', 'severity' => 'medium', 'evidence' => array( 1 ) ) ) );
$wa = fx_audit( array( array( 'id' => 'empty_content', 'severity' => 'medium', 'evidence' => array( 1, 2 ) ) ) );
t_eq( 'regressed', SEO_Pilot_Verify::compare( $wb, $wa, array( 'meta_missing' ) )['verdict'], 'a serious finding that got larger counts as a regression' );

t_eq( 'nothing', SEO_Pilot_Verify::compare( $before, $before, array( 'not_there' ) )['verdict'], 'targets that were not present before leave nothing to verify' );
t_eq( 'nothing', SEO_Pilot_Verify::compare( array(), array(), array() )['verdict'], 'empty audits are handled' );
foreach ( array( 'verified', 'partial', 'not_resolved', 'regressed', 'nothing' ) as $vd ) {
    t_ok( '' !== SEO_Pilot_Verify::message( array( 'verdict' => $vd ) ), 'there is a plain sentence for the verdict: ' . $vd );
}
