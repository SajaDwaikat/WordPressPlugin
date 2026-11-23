<?php
/**
 * SEO Insights Dashboard (shell).
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\Checks\MetaDescriptionCheck;

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

		$meta = MetaDescriptionCheck::run();
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

			<div class="sos-card">
				<h2><?php esc_html_e( 'Meta descriptions', 'site-owner-seo' ); ?></h2>
				<?php $this->render_meta_description( $meta ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the result of the meta description check.
	 *
	 * @param array{total:int,missing:int,missing_ids:int[]} $meta Result of MetaDescriptionCheck::run().
	 * @return void
	 */
	private function render_meta_description( array $meta ): void {
		if ( 0 === $meta['total'] ) {
			echo '<p>' . esc_html__( 'No published pages or posts were found.', 'site-owner-seo' ) . '</p>';
			return;
		}

		if ( 0 === $meta['missing'] ) {
			echo '<p class="sos-ok">' . esc_html__( 'All your published pages have a meta description.', 'site-owner-seo' ) . '</p>';
			return;
		}

		printf(
			'<p class="sos-number"><strong>%1$s</strong> %2$s</p>',
			esc_html( number_format_i18n( $meta['missing'] ) ),
			esc_html(
				sprintf(
					/* translators: %s: total number of published pages and posts */
					__( 'of %s published pages have no meta description.', 'site-owner-seo' ),
					number_format_i18n( $meta['total'] )
				)
			)
		);

		echo '<ul class="sos-list">';
		foreach ( array_slice( $meta['missing_ids'], 0, 10 ) as $post_id ) {
			$title = get_the_title( $post_id );
			$url   = add_query_arg(
				array(
					'page'    => Menu::FORM_SLUG,
					'post_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<li><span dir="auto">%1$s</span> <a href="%2$s">%3$s</a></li>',
				esc_html( '' !== $title ? $title : __( '(no title)', 'site-owner-seo' ) ),
				esc_url( $url ),
				esc_html__( 'Add description', 'site-owner-seo' )
			);
		}
		echo '</ul>';
	}
}
