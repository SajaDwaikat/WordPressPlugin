<?php
/**
 * Plugin settings storage.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the plugin's settings (stored in one option).
 */
final class Settings {

	public const OPTION = 'sos_settings';

	/**
	 * Default settings (decisions D5 and D6: editors may use the form; data is kept on uninstall).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'editors_can_use_form' => true,
			'delete_on_uninstall'  => false,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Value to return when the setting does not exist.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Stores the defaults without overwriting anything already saved.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		add_option( self::OPTION, self::defaults() );
	}

	/**
	 * Sanitize callback for the settings form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		return self::normalize( is_array( $input ) ? $input : array(), self::all() );
	}

	/**
	 * Turns submitted values into valid settings. Unknown keys are dropped and
	 * stored keys that the form does not manage are kept.
	 *
	 * @param array $input   Submitted values.
	 * @param array $current Current settings.
	 * @return array
	 */
	public static function normalize( array $input, array $current ): array {
		$settings = $current;
		foreach ( array_keys( self::defaults() ) as $key ) {
			// An unticked checkbox is simply missing from the submission.
			$settings[ $key ] = ! empty( $input[ $key ] );
		}
		return $settings;
	}
}
