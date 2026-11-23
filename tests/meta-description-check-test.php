<?php
/**
 * Unit test for MetaDescriptionCheck::is_missing(). No WordPress needed.
 * Run: php tests/meta-description-check-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/SeoFields.php';
require __DIR__ . '/../includes/Checks/MetaDescriptionCheck.php';

use SiteOwnerSEO\Checks\MetaDescriptionCheck as Check;

$cases = array(
	'empty'              => array( '', true ),
	'spaces only'        => array( "   \n\t ", true ),
	'non-breaking space' => array( "\u{00A0}", true ),
	'english text'       => array( 'A short description.', false ),
	'arabic text'        => array( 'وصف قصير للصفحة', false ),
	'text with spaces'   => array( '  hello  ', false ),
);

$cases['classify empty']       = array( Check::classify( '' ), 'missing' );
$cases['classify 69 chars']    = array( Check::classify( str_repeat( 'a', 69 ) ), 'too_short' );
$cases['classify 70 chars']    = array( Check::classify( str_repeat( 'a', 70 ) ), 'ok' );
$cases['classify 160 chars']   = array( Check::classify( str_repeat( 'a', 160 ) ), 'ok' );
$cases['classify 161 chars']   = array( Check::classify( str_repeat( 'a', 161 ) ), 'too_long' );
$cases['classify arabic ok']   = array( Check::classify( str_repeat( 'ع', 100 ) ), 'ok' );
$cases['classify trims']       = array( Check::classify( '  ' . str_repeat( 'a', 69 ) . '  ' ), 'too_short' );

$failed = 0;
foreach ( $cases as $name => $case ) {
	$ok = ( is_bool( $case[1] ) ? Check::is_missing( $case[0] ) : $case[0] ) === $case[1];
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed ? 1 : 0 );
