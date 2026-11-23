<?php
/**
 * Dashboard check M1: published pages without a meta description.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Checks;

use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Counts live pages and posts whose meta description is empty.
 * Only the description saved by this plugin is read, so the result is a real count, never an estimate.
 */
final class MetaDescriptionCheck {

	/**
	 * Content types covered by the SEO Management form.
	 */
	public const POST_TYPES = array( 'page', 'post' );

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
	 * Runs the check on all published pages and posts.
	 *
	 * @return array{total:int,missing:int,missing_ids:int[]} Totals and the IDs without a description.
	 */
	public static function run(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPES,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$missing = array();
		foreach ( $ids as $id ) {
			$description = (string) get_post_meta( (int) $id, SeoFields::META_DESCRIPTION, true );
			if ( self::is_missing( $description ) ) {
				$missing[] = (int) $id;
			}
		}

		return array(
			'total'       => count( $ids ),
			'missing'     => count( $missing ),
			'missing_ids' => $missing,
		);
	}
}
