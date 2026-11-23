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

$failed = 0;
foreach ( $cases as $name => $case ) {
	$ok = Check::is_missing( $case[0] ) === $case[1];
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed ? 1 : 0 );
