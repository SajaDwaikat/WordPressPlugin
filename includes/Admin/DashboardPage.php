<?php
/**
 * SEO Insights Dashboard (shell).
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder for the dashboard; the score, page speed and top 5 issues arrive in milestones M3 to M5.
 */
final class DashboardPage {

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
		?>
		<div class="wrap sos-wrap">
			<h1><?php esc_html_e( 'SEO Insights', 'site-owner-seo' ); ?></h1>
			<div class="sos-card">
				<h2><?php esc_html_e( 'Your SEO health check will appear here', 'site-owner-seo' ); ?></h2>
				<p><?php esc_html_e( 'This page will show your overall SEO score, your page speed, and the five most important problems to fix first.', 'site-owner-seo' ); ?></p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version number */
							__( 'Early build, version %s. The checks are not switched on yet.', 'site-owner-seo' ),
							SOS_VERSION
						)
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
