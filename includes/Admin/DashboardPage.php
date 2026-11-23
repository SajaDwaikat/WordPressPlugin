<?php
/**
 * SEO Insights Dashboard.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\Checks\CanonicalCheck;
use SiteOwnerSEO\Checks\MetaDescriptionCheck;
use SiteOwnerSEO\Checks\Score;
use SiteOwnerSEO\Checks\TitleCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard: SEO score, top issues and one card per check. Page speed arrives in a later milestone.
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
		$summary   = Score::compute( $meta, $titles, $canonical );
		?>
		<div class="wrap sos-wrap">
			<h1><?php esc_html_e( 'SEO Insights', 'site-owner-seo' ); ?></h1>
			<div class="sos-card">
				<h2><?php esc_html_e( 'Your SEO score', 'site-owner-seo' ); ?></h2>
				<?php $this->render_score( $summary ); ?>
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
	 * @param array{total:int,problems:array<string,int[]>} $meta Result of MetaDescriptionCheck::run().
	 * @return void
	 */
	private function render_meta_description( array $meta ): void {
		if ( 0 === $meta['total'] ) {
			echo '<p>' . esc_html__( 'No published pages or posts were found.', 'site-owner-seo' ) . '</p>';
			return;
		}

		$labels = array(
			'missing'   => __( 'No description', 'site-owner-seo' ),
			'too_short' => __( 'Too short', 'site-owner-seo' ),
			'too_long'  => __( 'Too long', 'site-owner-seo' ),
		);

		$by_page = array();
		foreach ( $meta['problems'] as $kind => $ids ) {
			foreach ( $ids as $post_id ) {
				$by_page[ $post_id ][] = $labels[ $kind ];
			}
		}

		if ( ! $by_page ) {
			echo '<p class="sos-ok">' . esc_html__( 'All your published pages have a good meta description.', 'site-owner-seo' ) . '</p>';
			return;
		}

		printf(
			'<p class="sos-number"><strong>%1$s</strong> %2$s</p>',
			esc_html( number_format_i18n( count( $by_page ) ) ),
			esc_html(
				sprintf(
					/* translators: %s: total number of published pages and posts */
					__( 'of %s published pages need a better meta description.', 'site-owner-seo' ),
					number_format_i18n( $meta['total'] )
				)
			)
		);

		$this->render_problem_list( $by_page, __( 'Edit description', 'site-owner-seo' ) );
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

	/**
	 * Wording for each issue the score can list.
	 *
	 * @return array<string,string>
	 */
	private static function issue_labels(): array {
		return array(
			'meta_missing'           => __( 'Pages with no meta description', 'site-owner-seo' ),
			'meta_too_short'         => __( 'Pages with a meta description that is too short', 'site-owner-seo' ),
			'meta_too_long'          => __( 'Pages with a meta description that is too long', 'site-owner-seo' ),
			'title_missing'          => __( 'Pages with no title', 'site-owner-seo' ),
			'title_too_short'        => __( 'Pages with a title that is too short', 'site-owner-seo' ),
			'title_too_long'         => __( 'Pages with a title that is too long', 'site-owner-seo' ),
			'title_duplicate'        => __( 'Pages that share a title with another page', 'site-owner-seo' ),
			'canonical_invalid'      => __( 'Pages whose canonical is not a full web address', 'site-owner-seo' ),
			'canonical_other_domain' => __( 'Pages whose canonical points to another website', 'site-owner-seo' ),
			'canonical_insecure'     => __( 'Pages whose canonical uses http instead of https', 'site-owner-seo' ),
			'canonical_other_page'   => __( 'Pages whose canonical points to a different page', 'site-owner-seo' ),
		);
	}

	/**
	 * Prints the score and the issues to fix first.
	 *
	 * @param array{score:?int,issues:array<int,array{key:string,count:int,post_ids:int[]}>} $summary Result of Score::compute().
	 * @return void
	 */
	private function render_score( array $summary ): void {
		if ( null === $summary['score'] ) {
			echo '<p>' . esc_html__( 'No published pages or posts were found, so there is nothing to score yet.', 'site-owner-seo' ) . '</p>';
			return;
		}

		$score = $summary['score'];
		$band  = $score >= 80 ? 'good' : ( $score >= 50 ? 'fair' : 'poor' );

		printf(
			'<p class="sos-score sos-score--%1$s"><strong>%2$s</strong> <span>/ 100</span></p>',
			esc_attr( $band ),
			esc_html( number_format_i18n( $score ) )
		);

		if ( ! $summary['issues'] ) {
			echo '<p class="sos-ok">' . esc_html__( 'No problems found. Your pages pass every check.', 'site-owner-seo' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Fix these first', 'site-owner-seo' ) . '</h3>';
			echo '<ol class="sos-top-issues">';
			$labels = self::issue_labels();
			foreach ( $summary['issues'] as $issue ) {
				$url = add_query_arg(
					array(
						'page'    => Menu::FORM_SLUG,
						'post_id' => $issue['post_ids'][0],
					),
					admin_url( 'admin.php' )
				);
				printf(
					'<li><span>%1$s <strong>(%2$s)</strong></span> <a href="%3$s">%4$s</a></li>',
					esc_html( $labels[ $issue['key'] ] ?? $issue['key'] ),
					esc_html( number_format_i18n( $issue['count'] ) ),
					esc_url( $url ),
					esc_html__( 'Fix the first one', 'site-owner-seo' )
				);
			}
			echo '</ol>';
		}

		echo '<p class="description">' . esc_html__( 'The score is the share of checks your pages pass: a good description, a good title and a sound canonical address for every published page. Page speed will be added later.', 'site-owner-seo' ) . '</p>';
	}
}
