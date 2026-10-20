<?php
/**
 * Standalone unit tests for the pure-logic modules. No WordPress required.
 * Run:  php tests/run-unit.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'SEO_PILOT_PATH', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code; private $msg;
        public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->msg; }
    }
    function is_wp_error( $x ) { return $x instanceof WP_Error; }
}

require SEO_PILOT_PATH . 'includes/class-seo-pilot-loader.php';
SEO_Pilot_Loader::register();

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
$GLOBALS['t_fails'] = array();

function t_ok( $cond, $name ) {
    if ( $cond ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail']++; $GLOBALS['t_fails'][] = $name; echo "  FAIL: $name\n"; }
}
function t_eq( $expected, $actual, $name ) {
    $ok = ( $expected === $actual );
    t_ok( $ok, $name . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}
function t_group( $name ) { echo "\n== $name\n"; }
function finding_ids( $audit ) { return array_map( function ( $f ) { return $f['id']; }, $audit['findings'] ); }
function check_status( $audit, $id ) {
    foreach ( $audit['checklist'] as $c ) { if ( $c['id'] === $id ) { return $c['status']; } }
    return null;
}

foreach ( glob( __DIR__ . '/unit/test-*.php' ) as $file ) {
    require $file;
}

echo "\n----------------------------------------\n";
echo 'Passed: ' . $GLOBALS['t_pass'] . '   Failed: ' . $GLOBALS['t_fail'] . "\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
