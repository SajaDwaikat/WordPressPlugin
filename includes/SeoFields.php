<?php
/**
 * Rules for the four SEO fields of a page (MF-02 to MF-06).
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Names of the saved values and the checks that must pass before they are saved.
 * Pure PHP, so it can be unit tested without WordPress.
 *
 * Only hard limits are enforced here. Advice about ideal lengths belongs to the
 * dashboard checks (T1-T4, M1-M4), not to the form.
 */
final class SeoFields {

	// Post meta keys. The SEO Specialist plugin reads the same keys (section 11 of the requirements).
	public const META_TITLE       = '_sos_title';
	public const META_DESCRIPTION = '_sos_description';
	public const META_CANONICAL   = '_sos_canonical';

	public const TITLE_MAX       = 200;
	public const DESCRIPTION_MAX = 500;
	public const SLUG_MAX        = 200;
	public const CANONICAL_MAX   = 2000;

	/**
	 * Checks the values and returns the problems found.
	 *
	 * @param array $values        Keys: title, description, slug, canonical (already cleaned of tags).
	 * @param bool  $slug_required True when the page is live, so its address must not be empty.
	 * @return array<string,string> Field name => problem code. Empty when everything is fine.
	 */
	public static function validate( array $values, bool $slug_required ): array {
		$errors = array();

		$title       = (string) ( $values['title'] ?? '' );
		$description = (string) ( $values['description'] ?? '' );
		$slug        = (string) ( $values['slug'] ?? '' );
		$canonical   = (string) ( $values['canonical'] ?? '' );

		// Count characters, not bytes, so Arabic text is measured fairly.
		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$errors['title'] = 'too_long';
		}

		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			$errors['description'] = 'too_long';
		}

		if ( '' === $slug && $slug_required ) {
			$errors['slug'] = 'empty';
		} elseif ( mb_strlen( $slug ) > self::SLUG_MAX ) {
			$errors['slug'] = 'too_long';
		}

		if ( '' !== $canonical ) {
			if ( mb_strlen( $canonical ) > self::CANONICAL_MAX ) {
				$errors['canonical'] = 'too_long';
			} elseif ( ! self::is_web_address( $canonical ) ) {
				$errors['canonical'] = 'invalid';
			}
		}

		return $errors;
	}

	/**
	 * Whether the text is a full web address (http or https, with a host, no spaces).
	 *
	 * @param string $url Text to check.
	 * @return bool
	 */
	public static function is_web_address( string $url ): bool {
		if ( 1 === preg_match( '/\s/u', $url ) ) {
			return false;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}
}
