<?php
/**
 * Settings page.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator choose who can use the form and what happens to saved data on uninstall.
 */
final class SettingsPage {

	private const GROUP = 'sos_settings_group';

	/**
	 * Registers the setting. WordPress itself checks the nonce and the administrator capability on save.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Registers the option with its sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to open this page.', 'site-owner-seo' ),
				'',
				array( 'response' => 403 )
			);
		}

		$settings = Settings::all();
		$name     = Settings::OPTION;
		?>
		<div class="wrap sos-wrap">
			<h1><?php esc_html_e( 'Settings', 'site-owner-seo' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php" class="sos-card">
				<?php settings_fields( self::GROUP ); ?>

				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[editors_can_use_form]" value="1" <?php checked( ! empty( $settings['editors_can_use_form'] ) ); ?> />
						<?php esc_html_e( 'Let editors use the SEO Management form', 'site-owner-seo' ); ?>
					</label><br />
					<span class="description"><?php esc_html_e( 'Editors can only change the pages they are already allowed to edit.', 'site-owner-seo' ); ?></span>
				</p>

				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Delete all SEO data saved by this plugin if it is uninstalled', 'site-owner-seo' ); ?>
					</label><br />
					<span class="description"><?php esc_html_e( 'Leave this unticked to keep your page titles, descriptions and canonical addresses if you remove the plugin.', 'site-owner-seo' ); ?></span>
				</p>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
