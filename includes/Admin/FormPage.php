<?php
/**
 * SEO Management Form: pick a page, edit its SEO text, save.
 *
 * @package SiteOwnerSEO
 */

declare( strict_types=1 );

namespace SiteOwnerSEO\Admin;

use SiteOwnerSEO\PostTypes;
use SiteOwnerSEO\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Slice 1 of milestone M2: the form and saving (MF-01 to MF-08).
 * Language handling and showing the saved text on the live page come in the next slices.
 */
final class FormPage {

	private const ACTION = 'sos_save_seo';

	/**
	 * Hooks the save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Content types the form can edit.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {
		return PostTypes::supported();
	}

	/**
	 * Stops the request unless the person may use the form.
	 *
	 * @return void
	 */
	private static function require_form_access(): void {
		if ( ! current_user_can( Menu::form_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to open this page.', 'site-owner-seo' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Pages and posts this person is allowed to edit.
	 *
	 * @return \WP_Post[]
	 */
	private static function choices(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_values(
			array_filter(
				$posts,
				static function ( $post ) {
					return current_user_can( 'edit_post', $post->ID );
				}
			)
		);
	}

	/**
	 * Wording for each problem the rules can report.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function error_messages(): array {
		return array(
			'title'       => array(
				/* translators: %d: maximum number of characters */
				'too_long' => sprintf( __( 'The page title is too long. Please keep it under %d characters.', 'site-owner-seo' ), SeoFields::TITLE_MAX + 1 ),
			),
			'description' => array(
				/* translators: %d: maximum number of characters */
				'too_long' => sprintf( __( 'The description is too long. Please keep it under %d characters.', 'site-owner-seo' ), SeoFields::DESCRIPTION_MAX + 1 ),
			),
			'slug'        => array(
				'empty'    => __( 'The page address cannot be empty for a page that is live.', 'site-owner-seo' ),
				'too_long' => __( 'The page address is too long.', 'site-owner-seo' ),
			),
			'canonical'   => array(
				'invalid'  => __( 'The canonical address must be a full web address that starts with http:// or https://.', 'site-owner-seo' ),
				'too_long' => __( 'The canonical address is too long.', 'site-owner-seo' ),
			),
		);
	}

	/**
	 * Reads one posted text field. The nonce is checked in handle_save() before this runs.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Saves the form (MF-07, MF-08).
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( self::ACTION );
		self::require_form_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		// Per-page check: the person must be allowed to edit this very page (MF-08).
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die(
				esc_html__( 'You are not allowed to edit this page.', 'site-owner-seo' ),
				'',
				array( 'response' => 403 )
			);
		}

		$values = array(
			'title'       => self::posted( 'sos_title' ),
			'description' => self::posted( 'sos_description' ),
			'slug'        => sanitize_title( self::posted( 'sos_slug' ) ),
			'canonical'   => self::posted( 'sos_canonical' ),
		);

		$is_live = in_array( $post->post_status, array( 'publish', 'private' ), true );
		$errors  = SeoFields::validate( $values, $is_live );
		$back    = array(
			'page'    => Menu::FORM_SLUG,
			'post_id' => $post_id,
		);

		if ( $errors ) {
			// Keep what was typed so nothing is lost, and show it again with the problems.
			set_transient(
				self::state_key(),
				array(
					'post_id' => $post_id,
					'errors'  => $errors,
					'values'  => $values,
				),
				5 * MINUTE_IN_SECONDS
			);
			$back['sos_msg'] = 'error';
			wp_safe_redirect( add_query_arg( $back, admin_url( 'admin.php' ) ) );
			exit;
		}

		self::store( $post_id, SeoFields::META_TITLE, $values['title'] );
		self::store( $post_id, SeoFields::META_DESCRIPTION, $values['description'] );
		self::store( $post_id, SeoFields::META_CANONICAL, '' === $values['canonical'] ? '' : esc_url_raw( $values['canonical'] ) );

		$message = 'saved';
		if ( '' !== $values['slug'] && $values['slug'] !== $post->post_name ) {
			$result = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $values['slug'],
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$message = 'slug_failed';
			} elseif ( get_post_field( 'post_name', $post_id ) !== $values['slug'] ) {
				// Another page already uses that address; WordPress picked the next free one (MF-06).
				$message = 'slug_adjusted';
			}
		}

		$back['sos_msg'] = $message;
		wp_safe_redirect( add_query_arg( $back, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Saves a value, or removes it when empty so the site's normal text is used.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $key     Meta key.
	 * @param string $value   Value to save.
	 * @return void
	 */
	private static function store( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	/**
	 * Where a failed save is kept for a moment, per person.
	 *
	 * @return string
	 */
	private static function state_key(): string {
		return 'sos_form_' . get_current_user_id();
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public function render(): void {
		self::require_form_access();

		// Reading which page to show changes nothing, so no nonce is needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['sos_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['sos_msg'] ) ) : '';
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( $post && ( ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			$post = null;
		}

		$errors = array();
		$values = null;
		$state  = get_transient( self::state_key() );
		if ( is_array( $state ) ) {
			delete_transient( self::state_key() );
			if ( $post && (int) ( $state['post_id'] ?? 0 ) === $post->ID && 'error' === $message ) {
				$errors = (array) ( $state['errors'] ?? array() );
				$values = (array) ( $state['values'] ?? array() );
			}
		}

		?>
		<div class="wrap sos-wrap">
			<h1><?php esc_html_e( 'SEO Management', 'site-owner-seo' ); ?></h1>
			<?php $this->render_notice( $message ); ?>

			<div class="sos-card">
				<h2><?php esc_html_e( 'Choose a page', 'site-owner-seo' ); ?></h2>
				<?php $this->render_picker( $post ? $post->ID : 0 ); ?>
			</div>

			<?php if ( $post ) : ?>
				<?php $this->render_form( $post, $values, $errors ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints the result message after saving.
	 *
	 * @param string $message Message code from the address.
	 * @return void
	 */
	private function render_notice( string $message ): void {
		$notices = array(
			'saved'         => array( 'success', __( 'Saved. Your changes are stored for this page.', 'site-owner-seo' ) ),
			'slug_adjusted' => array( 'warning', __( 'Saved, but another page already uses that address, so a different one was chosen. Check the page address below.', 'site-owner-seo' ) ),
			'slug_failed'   => array( 'error', __( 'The title, description and canonical address were saved, but the page address could not be changed.', 'site-owner-seo' ) ),
			'error'         => array( 'error', __( 'Nothing was saved. Please fix the problems marked below.', 'site-owner-seo' ) ),
		);
		if ( ! isset( $notices[ $message ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $message ][0] ),
			esc_html( $notices[ $message ][1] )
		);
	}

	/**
	 * Prints the page chooser (MF-02).
	 *
	 * @param int $selected Selected page ID, or 0.
	 * @return void
	 */
	private function render_picker( int $selected ): void {
		$choices = self::choices();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::FORM_SLUG ); ?>" />
			<p>
				<label for="sos-post-id" class="screen-reader-text"><?php esc_html_e( 'Page', 'site-owner-seo' ); ?></label>
				<select id="sos-post-id" name="post_id">
					<option value="0"><?php esc_html_e( 'Select a page…', 'site-owner-seo' ); ?></option>
					<?php foreach ( $choices as $choice ) : ?>
						<?php
						$label = '' !== trim( $choice->post_title ) ? $choice->post_title : __( '(no title)', 'site-owner-seo' );
						$type  = get_post_type_object( $choice->post_type );
						if ( $type && ! in_array( $choice->post_type, array( 'page', 'post' ), true ) ) {
							$label .= ' (' . $type->labels->singular_name . ')';
						}
						?>
						<option value="<?php echo esc_attr( (string) $choice->ID ); ?>" <?php selected( $selected, $choice->ID ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Open', 'site-owner-seo' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints the four fields for one page (MF-03 to MF-06).
	 *
	 * @param \WP_Post   $post   The page.
	 * @param array|null $values Values to show instead of the saved ones (after a failed save).
	 * @param array      $errors Problem codes per field.
	 * @return void
	 */
	private function render_form( \WP_Post $post, ?array $values, array $errors ): void {
		if ( null === $values ) {
			$values = array(
				'title'       => (string) get_post_meta( $post->ID, SeoFields::META_TITLE, true ),
				'description' => (string) get_post_meta( $post->ID, SeoFields::META_DESCRIPTION, true ),
				'slug'        => $post->post_name,
				'canonical'   => (string) get_post_meta( $post->ID, SeoFields::META_CANONICAL, true ),
			);
		}
		$messages = self::error_messages();
		$is_live  = in_array( $post->post_status, array( 'publish', 'private' ), true );
		?>
		<div class="sos-card">
			<h2><?php echo esc_html( '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'site-owner-seo' ) ); ?></h2>
			<p>
				<a href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'View this page', 'site-owner-seo' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>" />

				<table class="form-table" role="presentation">
					<?php
					$this->render_row(
						'sos_title',
						__( 'Page title', 'site-owner-seo' ),
						'title',
						$values,
						$errors,
						$messages,
						'text',
						__( 'This is the title people see in Google. Leave empty to use the page\'s normal title.', 'site-owner-seo' )
					);
					$this->render_row(
						'sos_description',
						__( 'Meta description', 'site-owner-seo' ),
						'description',
						$values,
						$errors,
						$messages,
						'textarea',
						__( 'A short summary shown under the title in Google. Leave empty to let Google choose.', 'site-owner-seo' )
					);
					$this->render_row(
						'sos_slug',
						__( 'Page address (URL slug)', 'site-owner-seo' ),
						'slug',
						$values,
						$errors,
						$messages,
						'text',
						$is_live
							? __( 'This page is live. If you change its address, visitors who use the old address are sent to the new one automatically.', 'site-owner-seo' )
							: __( 'The last part of the page web address. Leave empty to let WordPress choose.', 'site-owner-seo' )
					);
					$this->render_row(
						'sos_canonical',
						__( 'Canonical address', 'site-owner-seo' ),
						'canonical',
						$values,
						$errors,
						$messages,
						'url',
						__( 'The main web address for this page, starting with https://. Leave empty to use the page\'s own address.', 'site-owner-seo' )
					);
					?>
				</table>

				<?php submit_button( __( 'Save changes', 'site-owner-seo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one labelled field with its help text and any problem.
	 *
	 * @param string $name     Form field name.
	 * @param string $label    Visible label.
	 * @param string $key      Key in $values and $errors.
	 * @param array  $values   Values to show.
	 * @param array  $errors   Problem codes per field.
	 * @param array  $messages Wording per problem code.
	 * @param string $type     text, textarea or url.
	 * @param string $help     Help text.
	 * @return void
	 */
	private function render_row( string $name, string $label, string $key, array $values, array $errors, array $messages, string $type, string $help ): void {
		$value   = (string) ( $values[ $key ] ?? '' );
		$problem = isset( $errors[ $key ], $messages[ $key ][ $errors[ $key ] ] ) ? $messages[ $key ][ $errors[ $key ] ] : '';
		// Text follows its own direction (Arabic reads right to left); web addresses always read left to right.
		$dir = 'url' === $type ? 'ltr' : 'auto';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( 'textarea' === $type ) : ?>
					<textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text" dir="<?php echo esc_attr( $dir ); ?>" aria-describedby="<?php echo esc_attr( $name ); ?>-help"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="large-text" dir="<?php echo esc_attr( $dir ); ?>" aria-describedby="<?php echo esc_attr( $name ); ?>-help" />
				<?php endif; ?>
				<?php if ( '' !== $problem ) : ?>
					<p class="sos-error" role="alert"><?php echo esc_html( $problem ); ?></p>
				<?php endif; ?>
				<p class="description" id="<?php echo esc_attr( $name ); ?>-help"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}
}
