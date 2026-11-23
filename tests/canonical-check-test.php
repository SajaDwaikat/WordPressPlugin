<?php
/**
 * Unit test for CanonicalCheck::classify(). No WordPress needed.
 * Run: php tests/canonical-check-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/SeoFields.php';
require __DIR__ . '/../includes/Checks/MetaDescriptionCheck.php';
require __DIR__ . '/../includes/Checks/CanonicalCheck.php';

use SiteOwnerSEO\Checks\CanonicalCheck as Check;

$home = 'https://example.com';
$own  = 'https://example.com/about/';

$cases = array(
	'empty uses default'         => array( Check::classify( '', $own, $home ), 'default' ),
	'spaces only is default'     => array( Check::classify( '  ', $own, $home ), 'default' ),
	'same address'               => array( Check::classify( 'https://example.com/about/', $own, $home ), 'ok' ),
	'no trailing slash is same'  => array( Check::classify( 'https://example.com/about', $own, $home ), 'ok' ),
	'host in capitals is same'   => array( Check::classify( 'https://EXAMPLE.com/about/', $own, $home ), 'ok' ),
	'fragment ignored'           => array( Check::classify( 'https://example.com/about/#team', $own, $home ), 'ok' ),
	'another page'               => array( Check::classify( 'https://example.com/contact/', $own, $home ), 'other_page' ),
	'query makes it different'   => array( Check::classify( 'https://example.com/about/?a=1', $own, $home ), 'other_page' ),
	'another website'            => array( Check::classify( 'https://other.com/about/', $own, $home ), 'other_domain' ),
	'www is another host'        => array( Check::classify( 'https://www.example.com/about/', $own, $home ), 'other_domain' ),
	'http on an https site'      => array( Check::classify( 'http://example.com/about/', $own, $home ), 'insecure' ),
	'not a web address'          => array( Check::classify( '/about/', $own, $home ), 'invalid' ),
	'has spaces'                 => array( Check::classify( 'https://example.com/a b', $own, $home ), 'invalid' ),
	'http site, http canonical'  => array( Check::classify( 'http://example.com/about/', 'http://example.com/about/', 'http://example.com' ), 'ok' ),
);

$failed = 0;
foreach ( $cases as $name => $case ) {
	$ok = $case[0] === $case[1];
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : ' (got ' . $case[0] . ')' ) . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed ? 1 : 0 );
