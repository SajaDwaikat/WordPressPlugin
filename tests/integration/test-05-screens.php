<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
t_group( 'Admin screens render without errors' );

$GLOBALS['php_problems'] = array();
set_error_handler( function ( $no, $str, $file, $line ) { $GLOBALS['php_problems'][] = "$str ($file:$line)"; return true; } );
function render_to_string( callable $fn ) { ob_start(); try { $fn(); } catch ( Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(); } return ob_get_clean(); }
function with_get( array $get, callable $fn ) { $_GET = $get; $_REQUEST = $get; $o = render_to_string( $fn ); $_GET = array(); $_REQUEST = array(); return $o; }

configure_ai();
wp_set_current_user( 1 );
SEO_Pilot_Audit_Runner::audit_all( 300 );

// Overview
$o = render_to_string( array( 'SEO_Pilot_Admin', 'render_overview' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Run audit now' ) && false !== strpos( $o, 'Open in Content SEO' ), 'Overview renders with audit findings' );
t_ok( false !== strpos( $o, 'name="action" value="seo_pilot_run_audit"' ), 'MVP "run audit" form kept' );

// Settings: no secrets
$o = render_to_string( array( 'SEO_Pilot_Admin', 'render_settings' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'seo_pilot_settings[endpoint]' ), 'Settings renders' );
t_ok( false === strpos( $o, 'SECRET-KEY' ) && false === strpos( $o, 'sk-test' ), 'API key is never printed into the settings page' );
t_ok( false !== strpos( $o, 'Saved — leave blank' ) && false !== strpos( $o, 'type="password"' ), 'key field is a password input with a "saved" placeholder' );
t_ok( false !== strpos( $o, 'name="seo_pilot_settings[ack]"' ) && false !== strpos( $o, 'checked' ), 'data-sharing acknowledgement control present' );
define( 'SEO_PILOT_API_KEY', 'sk-from-constant-1234567890' );
$o = render_to_string( array( 'SEO_Pilot_Admin', 'render_settings' ) );
t_ok( false !== strpos( $o, 'SEO_PILOT_API_KEY constant' ) && false === strpos( $o, 'sk-from-constant' ), 'constant-supplied key: no input shown, value not printed' );

// List
$o = with_get( array( 'page' => 'seo-pilot-content' ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Open workspace' ) && false !== strpos( $o, 'Workflow post' ), 'Content SEO list renders posts' );
$o = with_get( array( 's' => 'Yoast page' ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( false !== strpos( $o, 'Yoast page' ) && false === strpos( $o, 'Workflow post' ), 'list search filter works' );
$o = with_get( array( 'status' => 'draft' ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( false !== strpos( $o, 'A draft' ) && false === strpos( $o, 'Yoast page' ), 'list status filter works (drafts are listed)' );

// Workspace with recommendations + history
$wf_posts = get_posts( array( 'title' => 'Workflow post', 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ) );
$wid = $wf_posts[0]->ID;
$GLOBALS['ai_queue'] = array( ai_json_full() );
call_handler( 'generate', array( 'post_id' => $wid, 'tasks' => array( 'seo_title', 'meta_description', 'rewrite', 'outline' ) ), 'seo_pilot_generate_' . $wid, 1 );
foreach ( SEO_Pilot_Recs::for_post( $wid, array( 'pending' ) ) as $x ) { if ( in_array( $x['rec_type'], array( 'seo_title', 'rewrite' ), true ) ) { rec_do( $x['id'], 'accept' ); } }
$o = with_get( array( 'page' => 'seo-pilot-content', 'post_id' => $wid ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ), 'workspace renders: ' . ( 0 === strpos( $o, 'EXCEPTION' ) ? $o : 'ok' ) );
foreach ( array( 'Current SEO fields and who owns them', 'Keywords, intent and audience', 'Content checklist', 'Prioritised findings', 'AI recommendations', 'Recommendations to review', 'Apply accepted changes', 'Check live output', 'History and rollback', 'Show exactly what will be sent' ) as $needle ) {
    t_ok( false !== strpos( $o, $needle ), "workspace has section: $needle" );
}
t_ok( false !== strpos( $o, 'seo-pilot-ins' ) && false !== strpos( $o, 'seo-pilot-del' ), 'original-vs-proposed diff is shown' );
t_ok( false !== strpos( $o, 'name="confirm_live"' ) && false !== strpos( $o, 'Save as a new draft copy' ), 'live page: confirmation checkbox and draft-copy option shown' );
t_ok( false !== strpos( $o, 'name="op" value="accept"' ) && false !== strpos( $o, 'name="op" value="regenerate"' ) || false !== strpos( $o, 'value="regenerate"' ), 'accept / regenerate controls present' );
t_ok( false !== strpos( $o, 'does not claim' ) || false !== strpos( $o, 'not a Google ranking factor' ), 'assessment disclaimer shown' );
t_ok( false !== strpos( $o, 'no search volume' ) || false !== strpos( $o, 'has no search volume' ), 'states that no search data exists' );
t_ok( false === strpos( $o, 'SECRET-KEY' ), 'no secret in the workspace' );
preg_match_all( '/name="action" value="(seo_pilot_[a-z_]+)"/', $o, $m );
foreach ( array_unique( $m[1] ) as $act ) { t_ok( has_action( 'admin_post_' . $act ), "form action $act has a registered handler" ); }
t_ok( substr_count( $o, 'name="_wpnonce"' ) >= 5, 'every form carries a nonce' );
// apply then check rollback form
apply_do( $wid, array( 'confirm_live' => 1 ) );
$o = with_get( array( 'post_id' => $wid ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( false !== strpos( $o, 'value="seo_pilot_rollback_group"' ) && false !== strpos( $o, 'Roll back this change' ), 'rollback form appears after an apply and posts to the right action' );
clear_flash();

// Draft post
$o = with_get( array( 'post_id' => $dp ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Preview' ), 'draft post workspace renders with a preview link' );
t_ok( false === strpos( $o, 'name="confirm_live"' ), 'draft post: no live confirmation needed' );

// History page
$o = with_get( array( 'page' => 'seo-pilot-history' ), array( 'SEO_Pilot_Admin', 'render_history' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Before → after' ) && false !== strpos( $o, 'admin' ), 'History screen lists who changed what' );

t_group( 'Escaping (XSS) and RTL' );
$xss = make_post( array( 'post_title' => 'T <script>alert("t")</script> & "q"', 'post_content' => '<p>Para <img src=x onerror=alert("c")> text that is long enough to count as a paragraph here.</p><h2><script>alert("h")</script>Head</h2>' ) );
update_post_meta( $xss, '_seo_pilot_seo_title', '"><script>alert("s")</script>' );
update_post_meta( $xss, SEO_Pilot_Audit_Runner::META_PRIMARY, '"><img src=x onerror=alert(1)>' );
SEO_Pilot_Recs::insert( array( 'post_id' => $xss, 'rec_type' => 'meta_description', 'original' => '<script>alert("o")</script>', 'proposed' => '</textarea><script>alert("p")</script>', 'rationale' => '<script>alert("r")</script>', 'flags' => array( 'verify' => array( '<script>v</script>' ), 'notes' => array() ), 'status' => 'pending' ) );
SEO_Pilot_Recs::insert( array( 'post_id' => $xss, 'rec_type' => 'outline', 'proposed' => wp_json_encode( array( 'items' => array( array( 'h2' => '<script>alert("ol")</script>', 'h3' => array(), 'note' => '', 'new' => true ) ) ) ), 'status' => 'pending' ) );
SEO_Pilot_History::insert( array( 'group_id' => 'xss1', 'post_id' => $xss, 'action' => 'apply', 'field' => 'seo_title', 'old_value' => '<script>h</script>', 'new_value' => '<img onerror=1>', 'note' => '<script>n</script>' ) );
SEO_Pilot_Audit_Runner::audit_post( $xss );
$o = with_get( array( 'post_id' => $xss ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ), 'XSS fixture renders' );
t_ok( false === stripos( $o, '<script>alert' ) && false === stripos( $o, '<img src=x onerror' ), 'no raw <script> or <img onerror> from titles, content, keywords, recommendations or history reaches the page' );
t_ok( false !== strpos( $o, '&lt;script&gt;' ), 'hostile text is shown escaped instead' );
$o = render_to_string( array( 'SEO_Pilot_Admin', 'render_history' ) );
t_ok( false === stripos( $o, '<script>h' ) && false === stripos( $o, '<img onerror' ) && false === stripos( $o, '<script>n' ), 'History screen escapes values' );

$arp = make_post( array( 'post_title' => 'دليل تحسين محركات البحث للمواقع', 'post_content' => '<h2>مقدمة</h2><p>' . str_repeat( 'نتحدث في هذا المقال عن تحسين محركات البحث للمواقع الصغيرة. ', 8 ) . '</p>' ) );
$o = with_get( array( 'post_id' => $arp ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( false !== strpos( $o, '<h1 dir="rtl">' ), 'Arabic page: heading rendered right-to-left' );
t_ok( substr_count( $o, 'dir="auto"' ) > 5, 'user text fields use dir="auto" so mixed Arabic/English displays correctly' );
t_ok( false !== strpos( $o, 'دليل تحسين محركات البحث' ), 'Arabic text is displayed intact' );

t_group( 'Capability gates on screens' );
foreach ( array( array( 'SEO_Pilot_Admin', 'render_overview' ), array( 'SEO_Pilot_Admin', 'render_settings' ), array( 'SEO_Pilot_Admin', 'render_history' ), array( 'SEO_Pilot_Workspace', 'render' ) ) as $cb ) {
    wp_set_current_user( $editor );
    $d = function () { return function ( $m ) { throw new SP_Die( (string) $m ); }; }; add_filter( 'wp_die_handler', $d );
    $died = false; try { ob_start(); call_user_func( $cb ); ob_end_clean(); } catch ( SP_Die $e ) { ob_end_clean(); $died = true; }
    remove_filter( 'wp_die_handler', $d );
    t_ok( $died, 'non-admin blocked from ' . $cb[1] );
}
wp_set_current_user( 1 );
restore_error_handler();
t_eq( array(), $GLOBALS['php_problems'], 'no PHP warnings or notices while rendering every screen' );
if ( $GLOBALS['php_problems'] ) { print_r( array_slice( $GLOBALS['php_problems'], 0, 8 ) ); }
