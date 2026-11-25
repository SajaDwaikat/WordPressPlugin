<?php
/**
 * Dashboard check: page titles that are missing, too short, too long or repeated.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Checks;

use SiteOwnerSEO\PostTypes;
use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at the title saved by this plugin for every published page and post.
 * The length limits are common SEO advice, not hard rules, so they are easy to change here.
 */
final class TitleCheck {

	public const MIN_LENGTH = 30;
	public const MAX_LENGTH = 60;

	/**
	 * Judges one title on its own (without comparing it to other pages).
	 * Pure PHP, so it can be unit tested without WordPress.
	 *
	 * @param string $title Saved title.
	 * @return string One of: missing, too_short, too_long, ok.
	 */
	public static function classify( string $title ): string {
		$title = trim( $title );
		if ( '' === $title ) {
			return 'missing';
		}

		// Count characters, not bytes, so Arabic text is measured fairly.
		$length = mb_strlen( $title );
		if ( $length < self::MIN_LENGTH ) {
			return 'too_short';
		}
		if ( $length > self::MAX_LENGTH ) {
			return 'too_long';
		}
		return 'ok';
	}

	/**
	 * Normalises a title so that "About Us" and " about  us " count as the same.
	 * Pure PHP.
	 *
	 * @param string $title Saved title.
	 * @return string
	 */
	public static function normalize( string $title ): string {
		$title = preg_replace( '/\s+/u', ' ', trim( $title ) );
		return mb_strtolower( (string) $title );
	}

	/**
	 * Runs the check on all published pages and posts.
	 *
	 * @return array{total:int,problems:array<string,int[]>} Total pages, and the page IDs for each problem
	 *                                                      (missing, too_short, too_long, duplicate).
	 */
	public static function run(): array {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::supported(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$problems = array(
			'missing'   => array(),
			'too_short' => array(),
			'too_long'  => array(),
			'duplicate' => array(),
		);
		$seen     = array();

		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$title = (string) get_post_meta( $id, SeoFields::META_TITLE, true );
			$kind  = self::classify( $title );

			if ( 'ok' !== $kind ) {
				$problems[ $kind ][] = $id;
			}
			if ( 'missing' !== $kind ) {
				$seen[ self::normalize( $title ) ][] = $id;
			}
		}

		foreach ( $seen as $same ) {
			if ( count( $same ) > 1 ) {
				$problems['duplicate'] = array_merge( $problems['duplicate'], $same );
			}
		}

		return array(
			'total'    => count( $ids ),
			'problems' => $problems,
		);
	}
}
