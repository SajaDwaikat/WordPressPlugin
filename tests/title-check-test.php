<?php
/**
 * Unit test for TitleCheck::classify() and normalize(). No WordPress needed.
 * Run: php tests/title-check-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/SeoFields.php';
require __DIR__ . '/../includes/Checks/MetaDescriptionCheck.php';
require __DIR__ . '/../includes/Checks/TitleCheck.php';

use SiteOwnerSEO\Checks\TitleCheck as Check;

$cases = array(
	'empty'              => array( Check::classify( '' ), 'missing' ),
	'spaces only'        => array( Check::classify( '   ' ), 'missing' ),
	'too short'          => array( Check::classify( 'Home' ), 'too_short' ),
	'29 chars'           => array( Check::classify( str_repeat( 'a', 29 ) ), 'too_short' ),
	'30 chars is fine'   => array( Check::classify( str_repeat( 'a', 30 ) ), 'ok' ),
	'60 chars is fine'   => array( Check::classify( str_repeat( 'a', 60 ) ), 'ok' ),
	'61 chars too long'  => array( Check::classify( str_repeat( 'a', 61 ) ), 'too_long' ),
	'arabic counted'     => array( Check::classify( str_repeat( 'ع', 40 ) ), 'ok' ),
	'normalize case'     => array( Check::normalize( '  About   Us ' ), 'about us' ),
	'normalize same'     => array( Check::normalize( 'ABOUT US' ) === Check::normalize( 'about  us' ), true ),
);

$failed = 0;
foreach ( $cases as $name => $case ) {
	$ok = $case[0] === $case[1];
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed ? 1 : 0 );
