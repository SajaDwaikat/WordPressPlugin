<?php
/**
 * SEO Insights Dashboard (shell).
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\Checks\CanonicalCheck;
use SiteOwnerSEO\Checks\MetaDescriptionCheck;
use SiteOwnerSEO\Checks\TitleCheck;

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

		$meta      = MetaDescriptionCheck::run();
		$titles    = TitleCheck::run();
		$canonical = CanonicalCheck::run();
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

			<div class="sos-card">
				<h2><?php esc_html_e( 'Page titles', 'site-owner-seo' ); ?></h2>
				<?php $this->render_titles( $titles ); ?>
			</div>

			<div class="sos-card">
				<h2><?php esc_html_e( 'Canonical addresses', 'site-owner-seo' ); ?></h2>
				<?php $this->render_canonical( $canonical ); ?>
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

	/**
	 * Prints the result of the page title check.
	 *
	 * @param array{total:int,problems:array<string,int[]>} $result Result of TitleCheck::run().
	 * @return void
	 */
	private function render_titles( array $result ): void {
		if ( 0 === $result['total'] ) {
			echo '<p>' . esc_html__( 'No published pages or posts were found.', 'site-owner-seo' ) . '</p>';
			return;
		}

		$labels = array(
			'missing'   => __( 'No title', 'site-owner-seo' ),
			'too_short' => __( 'Too short', 'site-owner-seo' ),
			'too_long'  => __( 'Too long', 'site-owner-seo' ),
			'duplicate' => __( 'Same title as another page', 'site-owner-seo' ),
		);

		// Group the problems by page, so a page with two problems is listed once.
		$by_page = array();
		foreach ( $result['problems'] as $kind => $ids ) {
			foreach ( $ids as $post_id ) {
				$by_page[ $post_id ][] = $labels[ $kind ];
			}
		}

		if ( ! $by_page ) {
			echo '<p class="sos-ok">' . esc_html__( 'All your published pages have a good title.', 'site-owner-seo' ) . '</p>';
			return;
		}

		printf(
			'<p class="sos-number"><strong>%1$s</strong> %2$s</p>',
			esc_html( number_format_i18n( count( $by_page ) ) ),
			esc_html(
				sprintf(
					/* translators: %s: total number of published pages and posts */
					__( 'of %s published pages need a better title.', 'site-owner-seo' ),
					number_format_i18n( $result['total'] )
				)
			)
		);

		$this->render_problem_list( $by_page, __( 'Edit title', 'site-owner-seo' ) );
	}

	/**
	 * Prints up to ten pages with their problems and a link to the SEO Management form.
	 *
	 * @param array<int,string[]> $by_page   Page ID => problem labels.
	 * @param string              $link_text Text of the link.
	 * @return void
	 */
	private function render_problem_list( array $by_page, string $link_text ): void {
		echo '<ul class="sos-list">';
		foreach ( array_slice( $by_page, 0, 10, true ) as $post_id => $problems ) {
			$title = get_the_title( $post_id );
			$url   = add_query_arg(
				array(
					'page'    => Menu::FORM_SLUG,
					'post_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<li><span dir="auto">%1$s</span> <span class="description">%2$s</span> <a href="%3$s">%4$s</a></li>',
				esc_html( '' !== $title ? $title : __( '(no title)', 'site-owner-seo' ) ),
				esc_html( implode( ', ', $problems ) ),
				esc_url( $url ),
				esc_html( $link_text )
			);
		}
		echo '</ul>';
	}

	/**
	 * Prints the result of the canonical check.
	 *
	 * @param array{total:int,custom:int,problems:array<string,int[]>} $result Result of CanonicalCheck::run().
	 * @return void
	 */
	private function render_canonical( array $result ): void {
		if ( 0 === $result['total'] ) {
			echo '<p>' . esc_html__( 'No published pages or posts were found.', 'site-owner-seo' ) . '</p>';
			return;
		}

		$labels = array(
			'invalid'      => __( 'Not a full web address', 'site-owner-seo' ),
			'other_domain' => __( 'Points to another website', 'site-owner-seo' ),
			'insecure'     => __( 'Uses http instead of https', 'site-owner-seo' ),
			'other_page'   => __( 'Points to a different page', 'site-owner-seo' ),
		);

		$by_page = array();
		foreach ( $result['problems'] as $kind => $ids ) {
			foreach ( $ids as $post_id ) {
				$by_page[ $post_id ][] = $labels[ $kind ];
			}
		}

		if ( ! $by_page ) {
			echo '<p class="sos-ok">' . esc_html__( 'No canonical problems found.', 'site-owner-seo' ) . '</p>';
		} else {
			printf(
				'<p class="sos-number"><strong>%1$s</strong> %2$s</p>',
				esc_html( number_format_i18n( count( $by_page ) ) ),
				esc_html__( 'pages have a canonical address worth a look. If it is on purpose, you can leave it.', 'site-owner-seo' )
			);
			$this->render_problem_list( $by_page, __( 'Edit canonical', 'site-owner-seo' ) );
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: pages with their own canonical, 2: total published pages and posts */
				__( '%1$s of %2$s pages have their own canonical address. The others use the default one from WordPress.', 'site-owner-seo' ),
				number_format_i18n( $result['custom'] ),
				number_format_i18n( $result['total'] )
			)
		) . '</p>';
	}
}
