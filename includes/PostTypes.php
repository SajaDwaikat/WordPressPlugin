<?php
/**
 * The content types the plugin manages.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * One place that decides which kinds of content get SEO fields, checks and live output.
 */
final class PostTypes {

	/**
	 * Public content types that have an edit screen: pages, posts, and any type the theme or another plugin adds
	 * (for example a "Services" or "Work" type). Media files are left out.
	 *
	 * Developers can change the list with the `sos_post_types` filter.
	 *
	 * @return string[]
	 */
	public static function supported(): array {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);
		unset( $types['attachment'] );

		$types = (array) apply_filters( 'sos_post_types', array_values( $types ) );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}
}
