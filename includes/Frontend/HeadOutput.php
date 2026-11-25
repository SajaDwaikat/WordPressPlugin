<?php
/**
 * Puts the saved title, description and canonical on the live pages.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Frontend;

use SiteOwnerSEO\PostTypes;
use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the three values saved by the SEO Management form and shows them to visitors and search engines.
 * Nothing is printed for a value that is empty, so WordPress keeps its own default in that case.
 */
final class HeadOutput {

	/**
	 * Hooks into the public side of the site.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'print_tags' ), 1 );
	}

	/**
	 * The page this request is about, when it is one of the pages or posts the form manages.
	 * Also covers the page chosen as "Posts page" in Settings > Reading, which WordPress does not treat as singular.
	 *
	 * @return int Page ID, or 0 when there is none.
	 */
	private function current_post_id(): int {
		$is_posts_page = is_home() && ! is_front_page() && (int) get_option( 'page_for_posts' ) > 0;
		if ( ! is_singular( PostTypes::supported() ) && ! $is_posts_page ) {
			return 0;
		}

		$id = (int) get_queried_object_id();
		return in_array( get_post_type( $id ), PostTypes::supported(), true ) ? $id : 0;
	}

	/**
	 * Reads one saved value for the current page.
	 *
	 * @param string $meta_key One of the SeoFields meta keys.
	 * @return string Empty when nothing is saved.
	 */
	private function saved( string $meta_key ): string {
		$id = $this->current_post_id();
		return $id ? trim( (string) get_post_meta( $id, $meta_key, true ) ) : '';
	}

	/**
	 * Uses the saved title as the full page title.
	 *
	 * @param string $title Title WordPress would use (empty when no other plugin set one).
	 * @return string
	 */
	public function filter_title( $title ) {
		$saved = $this->saved( SeoFields::META_TITLE );
		return '' !== $saved ? $saved : $title;
	}

	/**
	 * Replaces WordPress's canonical address with the saved one.
	 * WordPress prints the tag itself, so there is never a second one.
	 *
	 * @param string|false $url  Canonical WordPress would use; false when the page should have none.
	 * @param \WP_Post     $post The page.
	 * @return string|false
	 */
	public function filter_canonical( $url, $post ) {
		if ( ! $url || ! $post instanceof \WP_Post || ! in_array( $post->post_type, PostTypes::supported(), true ) ) {
			return $url;
		}

		$saved = trim( (string) get_post_meta( $post->ID, SeoFields::META_CANONICAL, true ) );
		return '' !== $saved ? $saved : $url;
	}

	/**
	 * Prints the description, and the canonical on the "Posts page" (WordPress prints none there).
	 *
	 * @return void
	 */
	public function print_tags(): void {
		$description = $this->saved( SeoFields::META_DESCRIPTION );
		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
		}

		if ( ! is_singular() ) {
			$canonical = $this->saved( SeoFields::META_CANONICAL );
			if ( '' !== $canonical ) {
				printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
			}
		}
	}
}
