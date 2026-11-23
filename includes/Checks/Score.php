<?php
/**
 * Overall SEO score and the issues to fix first.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Combines the results of the three page checks. Pure PHP, so it can be unit tested without WordPress.
 *
 * Every published page is tested three times: has a good description, has a good title, has a sound canonical.
 * The score is the share of those tests that pass, from 0 to 100. All three count the same.
 */
final class Score {

	public const TOP_ISSUES = 5;

	/**
	 * Issue keys, most serious first. Used to order issues that affect the same number of pages.
	 */
	public const SEVERITY = array(
		'title_missing',
		'meta_missing',
		'canonical_invalid',
		'canonical_other_domain',
		'canonical_insecure',
		'title_duplicate',
		'meta_too_long',
		'title_too_long',
		'meta_too_short',
		'title_too_short',
		'canonical_other_page',
	);

	/**
	 * Works out the score and the top issues.
	 *
	 * @param array $meta      Result of MetaDescriptionCheck::run().
	 * @param array $titles    Result of TitleCheck::run().
	 * @param array $canonical Result of CanonicalCheck::run().
	 * @return array{score:?int,issues:array<int,array{key:string,count:int,post_ids:int[]}>} Score is null when there are no pages.
	 */
	public static function compute( array $meta, array $titles, array $canonical ): array {
		$total = (int) $titles['total'];

		$issues = array();
		foreach ( $meta['problems'] as $kind => $ids ) {
			$issues[ 'meta_' . $kind ] = array_map( 'intval', $ids );
		}
		foreach ( $titles['problems'] as $kind => $ids ) {
			$issues[ 'title_' . $kind ] = array_map( 'intval', $ids );
		}
		foreach ( $canonical['problems'] as $kind => $ids ) {
			$issues[ 'canonical_' . $kind ] = array_map( 'intval', $ids );
		}

		// A page that has two problems in one check still fails that check only once.
		$failed = count( self::union( $meta['problems'] ) )
			+ count( self::union( $titles['problems'] ) )
			+ count( self::union( $canonical['problems'] ) );

		$score = $total > 0 ? (int) round( 100 * ( 3 * $total - $failed ) / ( 3 * $total ) ) : null;

		$list = array();
		foreach ( $issues as $key => $ids ) {
			if ( $ids ) {
				$list[] = array(
					'key'      => $key,
					'count'    => count( $ids ),
					'post_ids' => $ids,
				);
			}
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] <=> $a['count'];
				}
				return array_search( $a['key'], self::SEVERITY, true ) <=> array_search( $b['key'], self::SEVERITY, true );
			}
		);

		return array(
			'score'  => $score,
			'issues' => array_slice( $list, 0, self::TOP_ISSUES ),
		);
	}

	/**
	 * All different page IDs found in a set of problem lists.
	 *
	 * @param array<string,int[]> $problems Problem name => page IDs.
	 * @return int[]
	 */
	private static function union( array $problems ): array {
		$ids = array();
		foreach ( $problems as $list ) {
			foreach ( $list as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		return array_keys( $ids );
	}
}
