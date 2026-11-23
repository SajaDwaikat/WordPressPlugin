<?php
/**
 * Unit test for Score::compute(). No WordPress needed.
 * Run: php tests/score-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/Checks/Score.php';

use SiteOwnerSEO\Checks\Score;

function t_meta( array $missing ): array {
	return array( 'total' => 0, 'missing' => count( $missing ), 'missing_ids' => $missing );
}
function t_titles( int $total, array $p = array() ): array {
	return array( 'total' => $total, 'problems' => $p + array( 'missing' => array(), 'too_short' => array(), 'too_long' => array(), 'duplicate' => array() ) );
}
function t_canon( array $p = array() ): array {
	return array( 'total' => 0, 'custom' => 0, 'problems' => $p + array( 'invalid' => array(), 'other_domain' => array(), 'insecure' => array(), 'other_page' => array() ) );
}

$failed = 0;
function check( string $name, bool $ok ): void {
	global $failed;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$failed += $ok ? 0 : 1;
}

$r = Score::compute( t_meta( array() ), t_titles( 5 ), t_canon() );
check( 'all clean is 100', 100 === $r['score'] && array() === $r['issues'] );

$r = Score::compute( t_meta( array() ), t_titles( 0 ), t_canon() );
check( 'no pages gives null score', null === $r['score'] );

$r = Score::compute( t_meta( array( 1, 2, 3, 4, 5 ) ), t_titles( 5 ), t_canon() );
check( 'every description missing: 5 of 15 fail = 67', 67 === $r['score'] );

$r = Score::compute( t_meta( array() ), t_titles( 5, array( 'too_short' => array( 1 ), 'duplicate' => array( 1 ) ) ), t_canon() );
check( 'one page with two title problems fails the title test once: 14 of 15 = 93', 93 === $r['score'] );
check( 'but lists both issues', 2 === count( $r['issues'] ) );

$r = Score::compute( t_meta( array( 1, 2, 3 ) ), t_titles( 5, array( 'too_long' => array( 4 ) ) ), t_canon( array( 'other_page' => array( 5 ) ) ) );
check( 'issues sorted by pages affected', 'meta_missing' === $r['issues'][0]['key'] && 3 === $r['issues'][0]['count'] );
check( 'tie broken by severity (title_too_long before canonical_other_page)', 'title_too_long' === $r['issues'][1]['key'] && 'canonical_other_page' === $r['issues'][2]['key'] );

$r = Score::compute( t_meta( array() ), t_titles( 10, array( 'missing' => array( 1 ), 'too_short' => array( 2, 3 ), 'too_long' => array( 4, 5, 6 ), 'duplicate' => array( 7, 8 ) ) ), t_canon( array( 'invalid' => array( 9 ), 'other_page' => array( 10 ) ) ) );
check( 'only the top 5 issues are kept', 5 === count( $r['issues'] ) && 'title_too_long' === $r['issues'][0]['key'] );

exit( $failed ? 1 : 0 );
