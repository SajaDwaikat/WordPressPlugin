<?php
/**
 * Dashboard check: canonical addresses that point somewhere unexpected.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Checks;

use SiteOwnerSEO\PostTypes;
use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the canonical saved for each published page with the page's own address.
 * An empty canonical is not a problem: WordPress then uses the page's own address.
 */
final class CanonicalCheck {

	/**
	 * Judges one saved canonical. Pure PHP, so it can be unit tested without WordPress.
	 *
	 * @param string $canonical Saved canonical (may be empty).
	 * @param string $own_url   The page's own address.
	 * @param string $home_url  The site's home address.
	 * @return string One of: default, ok, invalid, other_domain, insecure, other_page.
	 */
	public static function classify( string $canonical, string $own_url, string $home_url ): string {
		$canonical = trim( $canonical );
		if ( '' === $canonical ) {
			return 'default';
		}

		if ( ! SeoFields::is_web_address( $canonical ) ) {
			return 'invalid';
		}

		$target = parse_url( $canonical );
		$home   = parse_url( $home_url );
		$target_host = strtolower( (string) ( $target['host'] ?? '' ) );
		$home_host   = strtolower( (string) ( $home['host'] ?? '' ) );

		if ( $target_host !== $home_host ) {
			return 'other_domain';
		}

		$home_scheme   = strtolower( (string) ( $home['scheme'] ?? '' ) );
		$target_scheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
		if ( 'https' === $home_scheme && 'http' === $target_scheme ) {
			return 'insecure';
		}

		return self::path_and_query( $canonical ) === self::path_and_query( $own_url ) ? 'ok' : 'other_page';
	}

	/**
	 * The part of an address after the host, without a trailing slash or a #fragment.
	 * Pure PHP.
	 *
	 * @param string $url Full address.
	 * @return string
	 */
	public static function path_and_query( string $url ): string {
		$parts = parse_url( $url );
		$path  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $path . $query;
	}

	/**
	 * Runs the check on all published pages and posts.
	 *
	 * @return array{total:int,custom:int,problems:array<string,int[]>} Total pages, how many have their own canonical,
	 *                                                                  and page IDs per problem (invalid, other_domain,
	 *                                                                  insecure, other_page).
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
			'invalid'      => array(),
			'other_domain' => array(),
			'insecure'     => array(),
			'other_page'   => array(),
		);
		$custom   = 0;
		$home     = home_url();

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$kind = self::classify(
				(string) get_post_meta( $id, SeoFields::META_CANONICAL, true ),
				(string) get_permalink( $id ),
				$home
			);

			if ( 'default' !== $kind ) {
				++$custom;
			}
			if ( isset( $problems[ $kind ] ) ) {
				$problems[ $kind ][] = $id;
			}
		}

		return array(
			'total'    => count( $ids ),
			'custom'   => $custom,
			'problems' => $problems,
		);
	}
}
