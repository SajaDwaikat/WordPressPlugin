<?php
function recs_of( $post_id, $type, array $statuses = array() ) {
    return array_values( array_filter( SEO_Pilot_Recs::for_post( $post_id, $statuses ), function ( $r ) use ( $type ) { return $r['rec_type'] === $type; } ) );
}
function rec_do( $rec_id, $op, array $extra = array(), $user = 1 ) {
    return call_handler( 'rec_action', array_merge( array( 'rec_id' => $rec_id, 'op' => $op ), $extra ), 'seo_pilot_rec_' . $rec_id, $user );
}
function apply_do( $post_id, array $extra = array(), $user = 1 ) {
    return call_handler( 'apply', array_merge( array( 'post_id' => $post_id, 'mode' => 'live' ), $extra ), 'seo_pilot_apply_' . $post_id, $user );
}
function ai_json_full() {
    return ai_reply( array(
        'seo_title' => array( 'proposed' => 'Sourdough bread: a baker\'s guide', 'reason' => 'clearer', 'verify' => array() ),
        'meta_description' => array( 'proposed' => 'How our bakery makes sourdough bread each morning, with simple steps you can follow at home.', 'reason' => 'summary', 'verify' => array() ),
        'rewrites' => array( array( 'segment' => 'S1', 'proposed_html' => 'We bake sourdough bread every morning.', 'reason' => 'less wordy', 'verify' => array() ) ),
        'outline' => array( 'items' => array( array( 'h2' => 'Our process', 'h3' => array(), 'note' => '', 'new' => false ) ), 'reason' => 'r' ),
        'warnings' => array(),
    ) );
}
$CONTENT = "<p>We bake sourdough bread every morning and we really do bake it every single morning because baking bread in the morning is what we do.</p>\n\n<h2>Our process</h2>\n\n<p>Second paragraph stays exactly as it was written.</p>\n\n<img src=\"https://example.com/a.jpg\"><img src=\"https://example.com/b.jpg\" alt=\"\">";

t_group( 'Permissions, nonces, validation' );
configure_ai();
$wf = make_post( array( 'post_title' => 'Workflow post', 'post_content' => $CONTENT, 'post_name' => 'workflow-post' ) );
$editor = wp_insert_user( array( 'user_login' => 'ed1', 'user_pass' => 'x', 'role' => 'editor' ) );
$contrib = wp_insert_user( array( 'user_login' => 'co1', 'user_pass' => 'x', 'role' => 'contributor' ) );
$GLOBALS['ai_calls'] = array();
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $wf, $editor );
t_ok( isset( $r['died'] ), 'editor is blocked by the plugin capability (manage_options by default)' );
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $wf, 1, true );
t_ok( isset( $r['died'] ), 'bad nonce rejected (CSRF protection)' );
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title' ) ), null, 1 );
t_ok( isset( $r['died'] ), 'missing nonce rejected' );
$r = call_handler( 'apply', array( 'post_id' => $wf, 'confirm_live' => 1 ), 'seo_pilot_apply_' . $wf, 0 );
t_ok( isset( $r['died'] ), 'logged-out user rejected' );
add_filter( 'seo_pilot_capability', $capf = function () { return 'edit_posts'; } );
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $wf, $contrib );
t_ok( isset( $r['died'] ), 'contributor cannot act on a post they may not edit (per-post edit_post check)' );
remove_filter( 'seo_pilot_capability', $capf );
$att = wp_insert_attachment( array( 'post_title' => 'img', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '' );
$r = call_handler( 'generate', array( 'post_id' => $att, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $att, 1 );
t_ok( isset( $r['died'] ), 'unsupported post type rejected' );
t_eq( 0, count( $GLOBALS['ai_calls'] ), 'no AI request was made in any rejected case' );
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array() ), 'seo_pilot_generate_' . $wf, 1 );
t_ok( isset( $r['redirect'] ) && 'error' === $r['flash']['type'], 'no tasks selected: friendly error, no request' );
configure_ai( array( 'ack' => 0 ) );
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $wf, 1 );
t_ok( 'error' === $r['flash']['type'] && 0 === count( $GLOBALS['ai_calls'] ), 'unacknowledged data sharing blocks generation' );
configure_ai();

t_group( 'Generate -> nothing changes on the page' );
$before = get_post( $wf );
$GLOBALS['ai_queue'] = array( ai_json_full() ); $GLOBALS['ai_calls'] = array();
$r = call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title', 'meta_description', 'rewrite', 'outline' ), 'instruction' => 'Friendly' ), 'seo_pilot_generate_' . $wf, 1 );
t_ok( 'success' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'Nothing has been changed' ), 'success message says nothing was changed' );
$after = get_post( $wf );
t_ok( $before->post_content === $after->post_content && $before->post_title === $after->post_title && $before->post_modified === $after->post_modified, 'post content, title and modified date untouched by generation' );
t_eq( 1, count( $GLOBALS['ai_calls'] ), 'exactly one provider request' );
$sent = $GLOBALS['ai_calls'][0]['args']['body'];
t_ok( false !== strpos( $sent, 'Friendly' ) && false === strpos( $sent, 'SECRET-KEY' ), 'editor note sent; key not in the body' );
t_eq( 4, count( SEO_Pilot_Recs::for_post( $wf, array( 'pending' ) ) ), 'four pending recommendations stored separately' );
$rw = recs_of( $wf, 'rewrite' )[0];
t_ok( 0 === strpos( $rw['target'], 'seg:' ) && '' !== $rw['original'], 'rewrite keeps the original text for comparison' );

t_group( 'Accept / edit / reject / reopen' );
$title_rec = recs_of( $wf, 'seo_title' )[0]; $meta_rec = recs_of( $wf, 'meta_description' )[0]; $outline = recs_of( $wf, 'outline' )[0];
$r = rec_do( $title_rec['id'], 'accept', array( 'proposed' => 'Edited <b>title</b><script>x</script> for bread' ) );
$t = SEO_Pilot_Recs::get( $title_rec['id'] );
t_eq( 'accepted', $t['status'], 'edited proposal accepted' );
t_eq( 'Edited title for bread', $t['proposed'], 'edited text is sanitised (tags removed)' );
t_ok( ! empty( $t['flags']['edited'] ) && 'user' === $t['source'], 'edit is recorded' );
$r = rec_do( $meta_rec['id'], 'accept' ); t_eq( 'accepted', SEO_Pilot_Recs::get( $meta_rec['id'] )['status'], 'meta accepted' );
rec_do( $meta_rec['id'], 'reject' ); t_eq( 'rejected', SEO_Pilot_Recs::get( $meta_rec['id'] )['status'], 'rejected' );
rec_do( $meta_rec['id'], 'reopen' ); t_eq( 'pending', SEO_Pilot_Recs::get( $meta_rec['id'] )['status'], 'moved back to pending' );
rec_do( $meta_rec['id'], 'accept' );
$r = rec_do( $rw['id'], 'accept', array( 'proposed' => '<p>We bake sourdough bread every morning.</p><a href="https://evil.example">x</a>' ) );
t_ok( false === strpos( SEO_Pilot_Recs::get( $rw['id'] )['proposed'], 'evil.example' ), 'edited rewrite cannot introduce a new link' );
$r = rec_do( $title_rec['id'], 'save', array( 'proposed' => '' ) );
t_eq( 'error', $r['flash']['type'], 'empty edit refused' );
$r = rec_do( $outline['id'], 'accept' ); t_eq( 'accepted', SEO_Pilot_Recs::get( $outline['id'] )['status'], 'advisory item can be marked noted' );
$r = rec_do( $title_rec['id'], 'accept', array(), $editor ); t_ok( isset( $r['died'] ), 'editor cannot act on recommendations' );

t_group( 'Apply: confirmation gates' );
$plan = SEO_Pilot_Approval::plan( $wf );
t_eq( array( 'seo_title', 'meta_description', 'post_content' ), array_column( $plan['changes'], 'field' ), 'plan lists exactly the accepted, applicable changes' );
t_ok( $plan['live'] && ! $plan['requires_owner_confirm'], 'live post, SEO Pilot owns the fields' );
$snap = get_post( $wf );
$r = apply_do( $wf );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'live' ), 'live page: apply refused without confirmation' );
$now = get_post( $wf );
t_ok( $now->post_content === $snap->post_content && '' === (string) get_post_meta( $wf, '_seo_pilot_seo_title', true ), 'nothing changed after the refused apply' );
t_eq( 'accepted', SEO_Pilot_Recs::get( $title_rec['id'] )['status'], 'recommendations stay accepted (not applied)' );
$r = apply_do( $wf, array(), $editor ); t_ok( isset( $r['died'] ), 'editor cannot apply' );

t_group( 'Apply: live update with revision, history, re-audit' );
$rev_before = count( wp_get_post_revisions( $wf ) );
$audit_before = SEO_Pilot_Audit_Runner::get_saved( $wf );
$r = apply_do( $wf, array( 'confirm_live' => 1 ) );
t_eq( 'success', $r['flash']['type'], 'apply succeeded with confirmation: ' . ( isset( $r['flash']['message'] ) ? $r['flash']['message'] : '' ) );
$post = get_post( $wf );
t_ok( false !== strpos( $post->post_content, '<p>We bake sourdough bread every morning.' ) && false === strpos( $post->post_content, 'every single morning' ), 'first paragraph replaced' );
t_ok( false === strpos( $post->post_content, 'evil.example' ), 'no unapproved link reached the post' );
t_ok( false !== strpos( $post->post_content, 'Second paragraph stays exactly as it was written.' ) && false !== strpos( $post->post_content, '<h2>Our process</h2>' ), 'untouched content is preserved' );
t_eq( 'Edited title for bread', get_post_meta( $wf, '_seo_pilot_seo_title', true ), 'SEO title saved to the SEO field' );
t_eq( 'Workflow post', $post->post_title, 'visible post title NOT overwritten (MVP bug fixed)' );
t_ok( $post->post_name === $snap->post_name && $post->post_date === $snap->post_date && $post->post_status === 'publish', 'slug, publish date and status untouched' );
t_ok( count( wp_get_post_revisions( $wf ) ) > $rev_before, 'a WordPress revision was created' );
$hist = SEO_Pilot_History::query( $wf, 20 );
t_eq( 3, count( $hist ), 'one history row per changed field' );
t_ok( 1 === $hist[0]['user_id'] && 'apply' === $hist[0]['action'] && '' !== $hist[0]['created_at'], 'history records the user, action and time' );
$hc = array_values( array_filter( $hist, function ( $h ) { return 'post_content' === $h['field']; } ) )[0];
t_ok( $hc['old_value'] === $snap->post_content && $hc['revision_id'] > 0, 'history keeps the exact original content and the revision id' );
t_eq( 'applied', SEO_Pilot_Recs::get( $title_rec['id'] )['status'], 'recommendations marked applied' );
$audit_after = SEO_Pilot_Audit_Runner::get_saved( $wf );
t_ok( $audit_after['audited_at'] >= $audit_before['audited_at'] && sha1( $post->post_content ) === $audit_after['content_hash'], 'audit re-run against the new content' );
$gid = $hc['group_id'];

t_group( 'Rollback' );
wp_update_post( wp_slash( array( 'ID' => $wf, 'post_content' => $post->post_content . "\n\n<p>Added later by a person.</p>" ) ) );
$r = call_handler( 'rollback_group', array( 'group_id' => $gid ), 'seo_pilot_rollback_' . $gid, 1 );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'live' ), 'live rollback needs confirmation' );
$r = call_handler( 'rollback_group', array( 'group_id' => $gid, 'confirm_live' => 1 ), 'seo_pilot_rollback_' . $gid, 1 );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'Post content' ), 'rollback stops when the content was edited after the change' );
t_ok( false !== strpos( get_post( $wf )->post_content, 'Added later by a person.' ), 'the newer edit was not discarded' );
$r = call_handler( 'rollback_group', array( 'group_id' => $gid, 'confirm_live' => 1, 'force' => 1 ), 'seo_pilot_rollback_' . $gid, 1 );
t_eq( 'success', $r['flash']['type'], 'forced rollback succeeds' );
t_eq( $CONTENT, get_post( $wf )->post_content, 'original content restored exactly' );
t_eq( '', (string) get_post_meta( $wf, '_seo_pilot_seo_title', true ), 'SEO title restored to empty' );
t_ok( 0 === count( SEO_Pilot_History::open_groups( $wf ) ), 'rolled-back group is closed' );
$rb = array_values( array_filter( SEO_Pilot_History::query( $wf, 30 ), function ( $h ) { return 'rollback' === $h['action']; } ) );
t_ok( count( $rb ) >= 3, 'the rollback itself is recorded in the history' );
$r = call_handler( 'rollback_group', array( 'group_id' => $gid, 'confirm_live' => 1 ), 'seo_pilot_rollback_' . $gid, 1 );
t_eq( 'error', $r['flash']['type'], 'a group cannot be rolled back twice' );

t_group( 'Rollback safety: only whitelisted fields' );
$gid2 = SEO_Pilot_Util::uuid();
SEO_Pilot_History::insert( array( 'group_id' => $gid2, 'post_id' => $wf, 'action' => 'apply', 'field' => 'robots', 'target_type' => 'post_meta', 'target_key' => 'rank_math_robots', 'old_value' => 'x', 'new_value' => '' ) );
$res = SEO_Pilot_Approval::rollback( $gid2, array( 'confirm_live' => 1, 'force' => 1 ) );
t_ok( is_wp_error( $res ) && 'seo_pilot_bad_target' === $res->get_error_code(), 'a forged history row cannot make SEO Pilot write to robots/canonical/other fields' );

t_group( 'Draft copy mode leaves the live page alone' );
$GLOBALS['ai_queue'] = array( ai_json_full() );
call_handler( 'generate', array( 'post_id' => $wf, 'tasks' => array( 'seo_title', 'meta_description', 'rewrite' ) ), 'seo_pilot_generate_' . $wf, 1 );
foreach ( SEO_Pilot_Recs::for_post( $wf, array( 'pending' ) ) as $x ) { if ( SEO_Pilot_Recs::is_applicable( $x['rec_type'] ) ) { rec_do( $x['id'], 'accept' ); } }
$live_before = get_post( $wf );
$r = apply_do( $wf, array( 'mode' => 'draft_copy' ) );
t_eq( 'success', $r['flash']['type'], 'draft copy created without live confirmation' );
$drafts = get_posts( array( 'post_status' => 'draft', 'meta_key' => '_seo_pilot_draft_of', 'meta_value' => $wf ) );
t_eq( 1, count( $drafts ), 'a new draft exists' );
t_ok( false !== strpos( $drafts[0]->post_content, '<p>We bake sourdough bread every morning.</p>' ), 'the draft carries the approved paragraph' );
t_ok( get_post( $wf )->post_content === $live_before->post_content && '' === (string) get_post_meta( $wf, '_seo_pilot_seo_title', true ), 'the live post and its SEO fields are unchanged' );
t_ok( 'draft' === $drafts[0]->post_status, 'copy is a draft, not published' );

t_group( 'Stale suggestions are refused' );
$sp = make_post( array( 'post_title' => 'Stale post', 'post_content' => "<p>Original paragraph that will be edited by a person soon.</p>\n\n<p>Another one.</p>" ) );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'rewrites' => array( array( 'segment' => 'S1', 'proposed_html' => 'Original paragraph, edited by the AI.', 'reason' => 'r', 'verify' => array() ) ) ) ) );
call_handler( 'generate', array( 'post_id' => $sp, 'tasks' => array( 'rewrite' ) ), 'seo_pilot_generate_' . $sp, 1 );
$sr = recs_of( $sp, 'rewrite' )[0]; rec_do( $sr['id'], 'accept' );
wp_update_post( wp_slash( array( 'ID' => $sp, 'post_content' => "<p>A person already rewrote this paragraph.</p>\n\n<p>Another one.</p>" ) ) );
$r = apply_do( $sp, array( 'confirm_live' => 1 ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'no longer' ), 'apply refused: paragraph changed since the suggestion' );
t_eq( 'stale', SEO_Pilot_Recs::get( $sr['id'] )['status'], 'recommendation marked stale' );
t_ok( false !== strpos( get_post( $sp )->post_content, 'A person already rewrote' ), 'the person\'s newer text is intact' );

t_group( 'Drafts apply directly; regeneration' );
$dp = make_post( array( 'post_title' => 'A draft', 'post_status' => 'draft', 'post_content' => "<p>Draft paragraph that needs a better, tighter, clearer sentence structure.</p>" ) );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'meta_description' => array( 'proposed' => 'A first description of the draft page that is long enough to be sensible here.', 'reason' => 'r' ) ) ) );
call_handler( 'generate', array( 'post_id' => $dp, 'tasks' => array( 'meta_description' ) ), 'seo_pilot_generate_' . $dp, 1 );
$m1 = recs_of( $dp, 'meta_description' )[0]; rec_do( $m1['id'], 'accept' );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'meta_description' => array( 'proposed' => 'A second and different description for the draft page, written on regeneration.', 'reason' => 'r' ) ) ) );
$r = rec_do( $m1['id'], 'regenerate' );
t_eq( 'superseded', SEO_Pilot_Recs::get( $m1['id'] )['status'], 'regenerate retires the old suggestion' );
$m2 = recs_of( $dp, 'meta_description', array( 'pending' ) )[0];
t_ok( false !== strpos( $m2['proposed'], 'second and different' ), 'regenerate stores the new suggestion as pending' );
rec_do( $m2['id'], 'accept' );
$r = apply_do( $dp );
t_eq( 'success', $r['flash']['type'], 'unpublished post: no live confirmation needed' );
t_ok( false !== strpos( (string) get_post_meta( $dp, '_seo_pilot_meta_description', true ), 'second and different' ), 'description saved' );
t_eq( 'draft', get_post( $dp )->post_status, 'draft stays a draft' );

t_group( 'Another SEO plugin owns the fields' );
add_filter( 'seo_pilot_detected_seo_plugins', $yf = function () { return array( 'yoast' ); } );
$yp = make_post( array( 'post_title' => 'Yoast page', 'post_content' => '<p>Some text for the Yoast owned page that has enough words in it.</p>' ) );
update_post_meta( $yp, '_yoast_wpseo_metadesc', 'Existing Yoast description' );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'meta_description' => array( 'proposed' => 'A proposed replacement description for the Yoast owned page, long enough.', 'reason' => 'r' ) ) ) );
call_handler( 'generate', array( 'post_id' => $yp, 'tasks' => array( 'meta_description' ) ), 'seo_pilot_generate_' . $yp, 1 );
$yr = recs_of( $yp, 'meta_description' )[0];
t_eq( 'Existing Yoast description', $yr['original'], 'the proposal is compared against the Yoast value, not an SEO Pilot copy' );
rec_do( $yr['id'], 'accept' );
$plan = SEO_Pilot_Approval::plan( $yp );
t_ok( $plan['requires_owner_confirm'] && $plan['changes'][0]['overwrites'], 'plan warns it will overwrite an existing Yoast value' );
$r = apply_do( $yp, array( 'confirm_live' => 1 ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'SEO plugin' ), 'writing to Yoast needs its own confirmation' );
t_eq( 'Existing Yoast description', get_post_meta( $yp, '_yoast_wpseo_metadesc', true ), 'Yoast value untouched without that confirmation' );
$r = apply_do( $yp, array( 'confirm_live' => 1, 'confirm_owner' => 1 ) );
t_eq( 'success', $r['flash']['type'], 'confirmed write to Yoast succeeds' );
t_ok( false !== strpos( get_post_meta( $yp, '_yoast_wpseo_metadesc', true ), 'proposed replacement' ), 'Yoast field updated' );
t_eq( '', (string) get_post_meta( $yp, '_seo_pilot_meta_description', true ), 'no duplicate SEO Pilot copy written while Yoast is active' );
$hy = SEO_Pilot_History::query( $yp, 5 )[0];
t_eq( 'Existing Yoast description', $hy['old_value'], 'history keeps the old Yoast value for rollback' );
$g = $hy['group_id'];
$r = call_handler( 'rollback_group', array( 'group_id' => $g, 'confirm_live' => 1 ), 'seo_pilot_rollback_' . $g, 1 );
t_eq( 'Existing Yoast description', get_post_meta( $yp, '_yoast_wpseo_metadesc', true ), 'rollback restores the Yoast value' );
remove_filter( 'seo_pilot_detected_seo_plugins', $yf );
// read-only owner
add_filter( 'seo_pilot_detected_seo_plugins', $af = function () { return array( 'aioseo' ); } );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'seo_title' => array( 'proposed' => 'A title for the AIOSEO page', 'reason' => 'r' ) ) ) );
call_handler( 'generate', array( 'post_id' => $yp, 'tasks' => array( 'seo_title' ) ), 'seo_pilot_generate_' . $yp, 1 );
$ar = recs_of( $yp, 'seo_title', array( 'pending' ) )[0]; rec_do( $ar['id'], 'accept' );
$r = apply_do( $yp, array( 'confirm_live' => 1, 'confirm_owner' => 1 ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'cannot write' ), 'AIOSEO-owned field: apply refuses and tells you to copy it manually' );
remove_filter( 'seo_pilot_detected_seo_plugins', $af );

t_group( 'Alt text needs human input' );
$ap = make_post( array( 'post_title' => 'Alt page', 'post_content' => "<p>Text before images in this alt page with several words in it.</p>\n\n<img src=\"https://example.com/a.jpg\"><img src=\"https://example.com/b.jpg\" alt=\"\">" ) );
SEO_Pilot_Audit_Runner::audit_post( $ap );
$hash = sha1( 'https://example.com/a.jpg' );
$r = call_handler( 'save_alt', array( 'post_id' => $ap, 'alt' => array( $hash => 'A loaf of sourdough on a board', sha1( 'https://nothere' ) => 'ignored', sha1( 'https://example.com/b.jpg' ) => 'must not apply' ) ), 'seo_pilot_alt_' . $ap, 1 );
t_eq( 1, count( recs_of( $ap, 'alt_text' ) ), 'only images actually missing an alt attribute are accepted' );
t_eq( 0, count( $GLOBALS['ai_queue'] ), 'no AI request was needed (the AI never guesses alt text)' );
$r = apply_do( $ap, array( 'confirm_live' => 1 ) );
$c = get_post( $ap )->post_content;
t_ok( false !== strpos( $c, '<img alt="A loaf of sourdough on a board" src="https://example.com/a.jpg">' ), 'alt added to the image that had none' );
t_ok( false !== strpos( $c, 'src="https://example.com/b.jpg" alt=""' ), 'decorative image (empty alt) left alone' );
$a3 = SEO_Pilot_Audit_Runner::get_saved( $ap );
t_ok( ! in_array( 'alt_missing', array_column( $a3['findings'], 'id' ), true ), 'audit re-run: alt finding resolved' );

t_group( 'Advisory items never change the page' );
$adv = make_post( array( 'post_title' => 'Advice', 'post_content' => '<p>Text.</p>' ) );
$GLOBALS['ai_queue'] = array( ai_reply( array( 'outline' => array( 'items' => array( array( 'h2' => 'X', 'h3' => array(), 'note' => '', 'new' => true ) ), 'reason' => 'r' ) ) ) );
call_handler( 'generate', array( 'post_id' => $adv, 'tasks' => array( 'outline' ) ), 'seo_pilot_generate_' . $adv, 1 );
rec_do( recs_of( $adv, 'outline' )[0]['id'], 'accept' );
$r = apply_do( $adv, array( 'confirm_live' => 1 ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'nothing to apply' ), 'accepted guidance has nothing to apply' );
t_eq( '<p>Text.</p>', get_post( $adv )->post_content, 'content unchanged' );

t_group( 'Legacy MVP form actions' );
foreach ( array( 'seo_pilot_ai_suggest', 'seo_pilot_apply_fix', 'seo_pilot_rollback_x' ) as $_ ) {}
wp_set_current_user( 1 );
$rd = function ( $l ) { throw new SP_Redirect( $l ); }; add_filter( 'wp_redirect', $rd );
foreach ( array( 'ai_suggest', 'apply_fix' ) as $act ) { try { do_action( 'admin_post_seo_pilot_' . $act ); t_ok( false, "legacy $act redirects" ); } catch ( SP_Redirect $e ) { t_ok( false !== strpos( $e->location, 'seo-pilot-content' ), "legacy $act form redirects to the workspace" ); } }
remove_filter( 'wp_redirect', $rd ); clear_flash();
