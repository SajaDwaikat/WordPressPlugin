<?php
/**
 * Integration tests: run inside a real WordPress install.
 *   SEO_PILOT_WP_LOAD=/path/to/wp-load.php php tests/integration/run.php
 * The plugin must be installed at wp-content/plugins/seo-pilot. Use a THROWAWAY site:
 * the tests create posts and finish by running the uninstall routine.
 */
$wp_load = getenv( 'SEO_PILOT_WP_LOAD' ) ? getenv( 'SEO_PILOT_WP_LOAD' ) : '/home/claude/wptest/site/wp-load.php';
define( 'WP_USE_THEMES', false );
require $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0; $GLOBALS['t_fails'] = array();
function t_ok( $cond, $name ) { if ( $cond ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail']++; $GLOBALS['t_fails'][] = $name; echo "  FAIL: $name\n"; } }
function t_eq( $expected, $actual, $name ) { $ok = ( $expected === $actual ); t_ok( $ok, $name . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) ); }
function t_group( $name ) { echo "\n== $name\n"; }

class SP_Redirect extends Exception { public $location; public function __construct( $l ) { $this->location = $l; parent::__construct( 'redirect' ); } }
class SP_Die extends Exception {}

/** Call an admin-post handler as a given user with a valid (or deliberately wrong) nonce; returns [ 'redirect' => url ] or [ 'died' => message ]. */
function call_handler( $action, array $post, $nonce_action = null, $user_id = 1, $bad_nonce = false ) {
    wp_set_current_user( $user_id );
    $_POST = $post; $_GET = array(); $_REQUEST = $post;
    if ( null !== $nonce_action ) { $_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = $bad_nonce ? 'badnonce' : wp_create_nonce( $nonce_action ); }
    $r = function ( $loc ) { throw new SP_Redirect( $loc ); };
    $d = function () { return function ( $m ) { throw new SP_Die( is_wp_error( $m ) ? $m->get_error_message() : (string) $m ); }; };
    add_filter( 'wp_redirect', $r );
    add_filter( 'wp_die_handler', $d );
    $out = array();
    try { do_action( 'admin_post_seo_pilot_' . $action ); $out = array( 'none' => true ); }
    catch ( SP_Redirect $e ) { $out = array( 'redirect' => $e->location ); }
    catch ( SP_Die $e ) { $out = array( 'died' => $e->getMessage() ); }
    remove_filter( 'wp_redirect', $r );
    remove_filter( 'wp_die_handler', $d );
    $out['flash'] = SEO_Pilot_Util::take_flash();
    return $out;
}

/** Mock the AI provider. $GLOBALS['ai_queue'] holds responses to return in order; $GLOBALS['ai_calls'] records requests. */
$GLOBALS['ai_queue'] = array(); $GLOBALS['ai_calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( 0 !== strpos( $url, 'https://ai.example.test/' ) ) { return $pre; }
    $GLOBALS['ai_calls'][] = array( 'url' => $url, 'args' => $args );
    $next = array_shift( $GLOBALS['ai_queue'] );
    if ( null === $next ) { return new WP_Error( 'http_request_failed', 'no mock queued' ); }
    return $next;
}, 10, 3 );
function ai_reply( $content, $code = 200, $finish = 'stop' ) {
    return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => array(), 'cookies' => array(),
        'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => is_array( $content ) ? wp_json_encode( $content ) : $content ), 'finish_reason' => $finish ) ) ) ) );
}
function ai_error( $code, $message ) {
    return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => array(), 'cookies' => array(), 'body' => wp_json_encode( array( 'error' => array( 'message' => $message ) ) ) );
}
function configure_ai( array $over = array() ) {
    update_option( 'seo_pilot_settings', array_merge( SEO_Pilot_Settings::defaults(), array( 'endpoint' => 'https://ai.example.test/v1/', 'model' => 'test-model', 'ack' => 1, 'ack_user' => 1, 'ack_time' => '2026-01-01 00:00:00' ), $over ) );
    SEO_Pilot_Settings::store_key( 'sk-test-SECRET-KEY-1234567890' );
    delete_transient( 'seo_pilot_rl' );
}
function make_post( array $o = array() ) {
    return wp_insert_post( array_merge( array( 'post_title' => 'Sourdough bread guide', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1, 'post_content' => '' ), $o ) );
}
function clear_flash() { SEO_Pilot_Util::take_flash(); }

echo "WordPress " . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', DB: ' . ( defined( 'SQLITE_DB_DROPIN_VERSION' ) ? 'SQLite' : 'MySQL/other' ) . "\n";
switch_theme( 'twentynineteen' );
wp_set_current_user( 1 );

$res = activate_plugin( 'seo-pilot/seo-pilot.php' );
if ( is_wp_error( $res ) ) { echo 'ACTIVATION FAILED: ' . $res->get_error_message() . "\n"; exit( 1 ); }

foreach ( glob( __DIR__ . '/test-*.php' ) as $f ) { require $f; }

echo "\n----------------------------------------\n";
echo 'Passed: ' . $GLOBALS['t_pass'] . '   Failed: ' . $GLOBALS['t_fail'] . "\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
