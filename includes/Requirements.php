<?php
/**
 * Minimum PHP and WordPress versions.
 *
 * Kept free of modern PHP syntax so it can run on old PHP and explain the problem.
 *
 * @package SiteOwnerSEO
 */

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the site meets the plugin's minimum versions.
 */
class Requirements {

	const MIN_PHP = '8.3';
	const MIN_WP  = '6.8';

	/**
	 * Compares the given versions with the minimums.
	 *
	 * @param string $php_version The PHP version in use.
	 * @param string $wp_version  The WordPress version in use (pre-release suffixes are ignored).
	 * @return array Unmet requirements, keyed 'php' and/or 'wp', each with 'current' and 'required'.
	 */
	public static function check( $php_version, $wp_version ) {
		$problems = array();

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			$problems['php'] = array(
				'current'  => $php_version,
				'required' => self::MIN_PHP,
			);
		}

		// Treat "6.8-RC1" or "6.9-alpha-12345" as the release they lead up to.
		$clean_wp = preg_replace( '/[-+].*$/', '', $wp_version );
		if ( version_compare( $clean_wp, self::MIN_WP, '<' ) ) {
			$problems['wp'] = array(
				'current'  => $wp_version,
				'required' => self::MIN_WP,
			);
		}

		return $problems;
	}
}
