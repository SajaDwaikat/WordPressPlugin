<?php
/**
 * Dashboard check: meta descriptions that are missing, too short or too long.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Checks;

use SiteOwnerSEO\PostTypes;
use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at the description saved by this plugin for every live page and post.
 * The length limits are common SEO advice, not hard rules, so they are easy to change here.
 */
final class MetaDescriptionCheck {

	public const MIN_LENGTH = 70;
	public const MAX_LENGTH = 160;

	/**
	 * Whether a saved description counts as missing (empty or only spaces).
	 * Pure PHP, so it can be unit tested without WordPress.
	 *
	 * @param string $description Saved description.
	 * @return bool
	 */
	public static function is_missing( string $description ): bool {
		return 0 === preg_match( '/\S/u', $description );
	}

	/**
	 * Judges one description on its own. Pure PHP.
	 *
	 * @param string $description Saved description.
	 * @return string One of: missing, too_short, too_long, ok.
	 */
	public static function classify( string $description ): string {
		if ( self::is_missing( $description ) ) {
			return 'missing';
		}

		// Count characters, not bytes, so Arabic text is measured fairly.
		$length = mb_strlen( trim( $description ) );
		if ( $length < self::MIN_LENGTH ) {
			return 'too_short';
		}
		if ( $length > self::MAX_LENGTH ) {
			return 'too_long';
		}
		return 'ok';
	}

	/**
	 * Runs the check on all published pages and posts.
	 *
	 * @return array{total:int,problems:array<string,int[]>} Total pages, and the page IDs for each problem
	 *                                                      (missing, too_short, too_long).
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
		);

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$kind = self::classify( (string) get_post_meta( $id, SeoFields::META_DESCRIPTION, true ) );
			if ( 'ok' !== $kind ) {
				$problems[ $kind ][] = $id;
			}
		}

		return array(
			'total'    => count( $ids ),
			'problems' => $problems,
		);
	}
}
