<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SEO_Pilot {
    private static $instance = null;
    private $option_key = 'seo_pilot_settings';

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_seo_pilot_run_audit', array( $this, 'handle_audit' ) );
        add_action( 'admin_post_seo_pilot_ai_suggest', array( $this, 'handle_ai_suggest' ) );
        add_action( 'admin_post_seo_pilot_apply_fix', array( $this, 'handle_apply_fix' ) );
        add_action( 'admin_post_seo_pilot_rollback', array( $this, 'handle_rollback' ) );
        add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'SEO Pilot', 'seo-pilot' ),
            __( 'SEO Pilot', 'seo-pilot' ),
            'manage_options',
            'seo-pilot',
            array( $this, 'render_page' ),
            'dashicons-search',
            58
        );
    }

    public function register_settings() {
        register_setting( 'seo_pilot_settings_group', $this->option_key, array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $old = get_option( $this->option_key, array() );
        $clean = array();
        $clean['provider'] = 'openai_compatible';
        $clean['endpoint'] = isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ) ) : '';
        $clean['model'] = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '';
        $clean['api_key'] = isset( $input['api_key'] ) && '' !== trim( $input['api_key'] )
            ? sanitize_text_field( trim( $input['api_key'] ) )
            : ( isset( $old['api_key'] ) ? $old['api_key'] : '' );
        return $clean;
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_seo-pilot' !== $hook ) { return; }
        wp_enqueue_style( 'seo-pilot-admin', SEO_PILOT_URL . 'assets/admin.css', array(), SEO_PILOT_VERSION );
    }

    private function can_manage() {
        return current_user_can( 'manage_options' );
    }

    private function redirect_notice( $notice ) {
        wp_safe_redirect( add_query_arg( 'seo_pilot_notice', rawurlencode( $notice ), admin_url( 'admin.php?page=seo-pilot' ) ) );
        exit;
    }

    public function handle_audit() {
        if ( ! $this->can_manage() ) { wp_die( esc_html__( 'You do not have permission to run an audit.', 'seo-pilot' ) ); }
        check_admin_referer( 'seo_pilot_run_audit' );

        $items = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ) );

        $results = array();
        foreach ( $items as $post ) {
            $results[ $post->ID ] = $this->audit_post( $post );
        }
        update_option( 'seo_pilot_last_audit', array(
            'ran_at' => current_time( 'mysql' ),
            'count'  => count( $items ),
            'items'  => $results,
        ), false );
        $this->redirect_notice( 'audit_complete' );
    }

    private function audit_post( $post ) {
        $issues = array();
        $title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
        $content = (string) $post->post_content;
        $description = $this->get_meta_description( $post->ID );
        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) );

        if ( '' === $title ) {
            $issues[] = array( 'id' => 'missing_title', 'severity' => 'high', 'message' => 'Page title is empty.', 'fixable' => true );
        } elseif ( strlen( $title ) > 60 ) {
            $issues[] = array( 'id' => 'long_title', 'severity' => 'medium', 'message' => 'Title is longer than 60 characters (a guideline, not a ranking rule).', 'fixable' => false );
        }
        if ( '' === $description ) {
            $issues[] = array( 'id' => 'missing_description', 'severity' => 'high', 'message' => 'SEO meta description is missing.', 'fixable' => true );
        } elseif ( strlen( $description ) > 160 ) {
            $issues[] = array( 'id' => 'long_description', 'severity' => 'low', 'message' => 'Meta description is longer than 160 characters (display can vary).', 'fixable' => false );
        }

        preg_match_all( '/<h1\b[^>]*>/i', $content, $h1_matches );
        if ( 0 === count( $h1_matches[0] ) ) {
            $issues[] = array( 'id' => 'missing_h1', 'severity' => 'medium', 'message' => 'No H1 heading was found in the stored post content. The theme may render one elsewhere.', 'fixable' => false );
        } elseif ( count( $h1_matches[0] ) > 1 ) {
            $issues[] = array( 'id' => 'multiple_h1', 'severity' => 'low', 'message' => 'More than one H1 was found in stored post content; review the page context before changing it.', 'fixable' => false );
        }

        preg_match_all( '/<img\b[^>]*>/i', $content, $images );
        $missing_alt = 0;
        foreach ( $images[0] as $img ) {
            if ( ! preg_match( '/\balt\s*=\s*(["\']).*?\1/i', $img ) ) { $missing_alt++; }
        }
        if ( $missing_alt > 0 ) {
            $issues[] = array( 'id' => 'missing_alt', 'severity' => 'medium', 'message' => sprintf( '%d image(s) in stored content have no alt attribute.', $missing_alt ), 'fixable' => false );
        }

        if ( strlen( $plain ) < 120 ) {
            $issues[] = array( 'id' => 'thin_content', 'severity' => 'low', 'message' => 'Stored text content is short; assess intent and usefulness rather than adding words just for length.', 'fixable' => false );
        }

        $robots = get_post_meta( $post->ID, '_seo_pilot_noindex', true );
        if ( '1' === $robots ) {
            $issues[] = array( 'id' => 'seo_pilot_noindex', 'severity' => 'high', 'message' => 'SEO Pilot noindex flag is enabled for this post.', 'fixable' => false );
        }

        return array(
            'post_id' => $post->ID,
            'title' => $title,
            'url' => get_permalink( $post ),
            'description' => $description,
            'issues' => $issues,
            'issue_count' => count( $issues ),
        );
    }

    private function get_meta_description( $post_id ) {
        $desc = get_post_meta( $post_id, '_seo_pilot_meta_description', true );
        if ( '' !== trim( (string) $desc ) ) { return trim( (string) $desc ); }
        // Read-only detection of common SEO plugin fields; avoid overwriting their data.
        $keys = array( '_yoast_wpseo_metadesc', 'rank_math_description', 'aioseo_description' );
        foreach ( $keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( is_string( $value ) && '' !== trim( $value ) ) { return trim( $value ); }
        }
        return '';
    }

    public function output_meta_description() {
        if ( is_admin() || ! is_singular() ) { return; }
        // Avoid competing with popular SEO plugins; they should remain the source of truth.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) { return; }
        $post_id = get_queried_object_id();
        $desc = get_post_meta( $post_id, '_seo_pilot_meta_description', true );
        if ( is_string( $desc ) && '' !== trim( $desc ) ) {
            echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
    }

    public function handle_ai_suggest() {
        if ( ! $this->can_manage() ) { wp_die( esc_html__( 'Permission denied.', 'seo-pilot' ) ); }
        check_admin_referer( 'seo_pilot_ai_suggest' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status ) {
            $this->redirect_notice( 'invalid_post' );
        }
        $settings = get_option( $this->option_key, array() );
        if ( empty( $settings['endpoint'] ) || empty( $settings['model'] ) || empty( $settings['api_key'] ) ) {
            $this->redirect_notice( 'ai_not_configured' );
        }
        $content = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ) );
        $payload = array(
            'model' => $settings['model'],
            'temperature' => 0.3,
            'messages' => array(
                array( 'role' => 'system', 'content' => 'You are an SEO editor. Return ONLY valid JSON with keys "title" and "meta_description". Suggest accurate, non-clickbait metadata based only on the supplied content. Do not invent facts. Keep title concise and meta description around 140-160 characters when possible.' ),
                array( 'role' => 'user', 'content' => 'Current title: ' . get_the_title( $post ) . "\nURL: " . get_permalink( $post ) . "\nContent excerpt: " . mb_substr( $content, 0, 5000 ) ),
            ),
        );
        $response = wp_remote_post( trailingslashit( $settings['endpoint'] ) . 'chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $settings['api_key'],
            ),
            'body' => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) { $this->redirect_notice( 'ai_request_failed' ); }
        $status = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw = isset( $body['choices'][0]['message']['content'] ) ? trim( $body['choices'][0]['message']['content'] ) : '';
        $raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw );
        $suggestion = json_decode( $raw, true );
        if ( $status < 200 || $status >= 300 || ! is_array( $suggestion ) || empty( $suggestion['title'] ) || empty( $suggestion['meta_description'] ) ) {
            $this->redirect_notice( 'ai_invalid_response' );
        }
        $suggestion = array(
            'post_id' => $post_id,
            'title' => sanitize_text_field( $suggestion['title'] ),
            'meta_description' => sanitize_text_field( $suggestion['meta_description'] ),
            'created_at' => current_time( 'mysql' ),
        );
        update_post_meta( $post_id, '_seo_pilot_pending_suggestion', $suggestion );
        $this->redirect_notice( 'ai_suggestion_ready' );
    }

    public function handle_apply_fix() {
        if ( ! $this->can_manage() ) { wp_die( esc_html__( 'Permission denied.', 'seo-pilot' ) ); }
        check_admin_referer( 'seo_pilot_apply_fix' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post = get_post( $post_id );
        $pending = get_post_meta( $post_id, '_seo_pilot_pending_suggestion', true );
        if ( ! $post || ! is_array( $pending ) || (int) $pending['post_id'] !== $post_id ) {
            $this->redirect_notice( 'no_pending_suggestion' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { wp_die( esc_html__( 'You cannot edit this post.', 'seo-pilot' ) ); }

        $snapshot = array(
            'title' => $post->post_title,
            'meta_description' => get_post_meta( $post_id, '_seo_pilot_meta_description', true ),
            'saved_at' => current_time( 'mysql' ),
        );
        update_post_meta( $post_id, '_seo_pilot_last_snapshot', $snapshot );

        // Apply the title only after an explicit approval; description is stored in SEO Pilot metadata.
        $new_title = sanitize_text_field( $pending['title'] );
        if ( '' !== $new_title && $new_title !== $post->post_title ) {
            wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_title' => $new_title ) ) );
        }
        update_post_meta( $post_id, '_seo_pilot_meta_description', sanitize_text_field( $pending['meta_description'] ) );
        delete_post_meta( $post_id, '_seo_pilot_pending_suggestion' );
        $this->redirect_notice( 'fix_applied' );
    }

    public function handle_rollback() {
        if ( ! $this->can_manage() ) { wp_die( esc_html__( 'Permission denied.', 'seo-pilot' ) ); }
        check_admin_referer( 'seo_pilot_rollback' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post = get_post( $post_id );
        $snapshot = get_post_meta( $post_id, '_seo_pilot_last_snapshot', true );
        if ( ! $post || ! is_array( $snapshot ) || ! current_user_can( 'edit_post', $post_id ) ) {
            $this->redirect_notice( 'no_snapshot' );
        }
        wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_title' => $snapshot['title'] ) ) );
        if ( '' === (string) $snapshot['meta_description'] ) {
            delete_post_meta( $post_id, '_seo_pilot_meta_description' );
        } else {
            update_post_meta( $post_id, '_seo_pilot_meta_description', $snapshot['meta_description'] );
        }
        delete_post_meta( $post_id, '_seo_pilot_last_snapshot' );
        $this->redirect_notice( 'rollback_complete' );
    }

    private function notices() {
        if ( empty( $_GET['seo_pilot_notice'] ) ) { return; }
        $key = sanitize_key( wp_unslash( $_GET['seo_pilot_notice'] ) );
        $messages = array(
            'audit_complete' => 'Audit completed. Review the findings below.',
            'ai_not_configured' => 'Configure the AI endpoint, model, and API key in Settings first.',
            'ai_request_failed' => 'The AI request failed. Check the endpoint, network, and server logs.',
            'ai_invalid_response' => 'The AI response could not be parsed. Try again or use a compatible chat-completions API.',
            'ai_suggestion_ready' => 'AI suggestion generated. Review it before applying.',
            'fix_applied' => 'Approved changes applied. Run the audit again to verify.',
            'rollback_complete' => 'The previous title and SEO Pilot description were restored.',
            'no_pending_suggestion' => 'No pending suggestion was found for this post.',
            'no_snapshot' => 'No rollback snapshot is available for this post.',
            'invalid_post' => 'The selected post is not available for this action.',
        );
        if ( isset( $messages[ $key ] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
        }
    }

    public function render_page() {
        if ( ! $this->can_manage() ) { return; }
        $audit = get_option( 'seo_pilot_last_audit', array() );
        $items = isset( $audit['items'] ) && is_array( $audit['items'] ) ? $audit['items'] : array();
        $settings = get_option( $this->option_key, array() );
        $issue_total = 0;
        foreach ( $items as $item ) { $issue_total += isset( $item['issue_count'] ) ? (int) $item['issue_count'] : 0; }
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1>SEO Pilot <span class="seo-pilot-version">MVP <?php echo esc_html( SEO_PILOT_VERSION ); ?></span></h1>
            <?php $this->notices(); ?>
            <p class="seo-pilot-intro">Audit published pages and posts, review issues, and generate AI metadata suggestions. Changes are never applied without your approval.</p>
            <div class="seo-pilot-cards">
                <div class="seo-pilot-card"><span>Pages audited</span><strong><?php echo esc_html( isset( $audit['count'] ) ? (string) $audit['count'] : '—' ); ?></strong></div>
                <div class="seo-pilot-card"><span>Issues detected</span><strong><?php echo esc_html( isset( $audit['ran_at'] ) ? (string) $issue_total : '—' ); ?></strong></div>
                <div class="seo-pilot-card"><span>Last audit</span><strong class="seo-pilot-date"><?php echo esc_html( isset( $audit['ran_at'] ) ? $audit['ran_at'] : 'Not run yet' ); ?></strong></div>
            </div>
            <div class="seo-pilot-panel">
                <h2>Run an SEO audit</h2>
                <p>Checks up to 300 published posts and pages. This first version performs local content checks; it is not a full external crawler.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="seo_pilot_run_audit">
                    <?php wp_nonce_field( 'seo_pilot_run_audit' ); ?>
                    <?php submit_button( 'Run audit now', 'primary', 'submit', false ); ?>
                </form>
            </div>
            <div class="seo-pilot-panel">
                <h2>AI API settings</h2>
                <p>Uses an OpenAI-compatible Chat Completions endpoint. Your API key is stored in this WordPress site's options; only send content to a provider you trust.</p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'seo_pilot_settings_group' ); ?>
                    <input type="hidden" name="<?php echo esc_attr( $this->option_key ); ?>[provider]" value="openai_compatible">
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="seo-pilot-endpoint">API base URL</label></th><td><input id="seo-pilot-endpoint" class="regular-text" type="url" name="<?php echo esc_attr( $this->option_key ); ?>[endpoint]" value="<?php echo esc_attr( isset( $settings['endpoint'] ) ? $settings['endpoint'] : '' ); ?>" placeholder="https://api.openai.com/v1/"><p class="description">Include the API base path, commonly /v1/.</p></td></tr>
                        <tr><th scope="row"><label for="seo-pilot-model">Model</label></th><td><input id="seo-pilot-model" class="regular-text" type="text" name="<?php echo esc_attr( $this->option_key ); ?>[model]" value="<?php echo esc_attr( isset( $settings['model'] ) ? $settings['model'] : '' ); ?>" placeholder="your-model-name"></td></tr>
                        <tr><th scope="row"><label for="seo-pilot-api-key">API key</label></th><td><input id="seo-pilot-api-key" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( $this->option_key ); ?>[api_key]" value="" placeholder="<?php echo esc_attr( ! empty( $settings['api_key'] ) ? 'Saved — leave blank to keep current key' : 'Enter API key' ); ?>"><p class="description">Leave blank to keep the saved key. Do not share screenshots containing credentials.</p></td></tr>
                    </table>
                    <?php submit_button( 'Save AI settings' ); ?>
                </form>
            </div>
            <div class="seo-pilot-panel">
                <h2>Audit findings</h2>
                <?php if ( empty( $items ) ) : ?>
                    <p>No audit results yet. Run an audit to populate this section.</p>
                <?php else : ?>
                    <?php foreach ( $items as $item ) :
                        $post_id = (int) $item['post_id'];
                        $post = get_post( $post_id );
                        if ( ! $post ) { continue; }
                        $pending = get_post_meta( $post_id, '_seo_pilot_pending_suggestion', true );
                        $snapshot = get_post_meta( $post_id, '_seo_pilot_last_snapshot', true );
                        ?>
                        <div class="seo-pilot-result">
                            <div class="seo-pilot-result-head">
                                <div><h3><?php echo esc_html( $item['title'] ? $item['title'] : '(Untitled)' ); ?></h3><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer">View page ↗</a> · <a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>">Edit in WordPress</a></div>
                                <span class="seo-pilot-count"><?php echo esc_html( (string) $item['issue_count'] ); ?> issue(s)</span>
                            </div>
                            <?php if ( empty( $item['issues'] ) ) : ?><p class="seo-pilot-ok">No issues detected by the current MVP checks.</p><?php else : ?>
                                <ul class="seo-pilot-issues">
                                <?php foreach ( $item['issues'] as $issue ) : ?>
                                    <li><span class="seo-pilot-severity severity-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( strtoupper( $issue['severity'] ) ); ?></span> <?php echo esc_html( $issue['message'] ); ?></li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="seo-pilot-actions">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="seo_pilot_ai_suggest"><input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>"><?php wp_nonce_field( 'seo_pilot_ai_suggest' ); ?>
                                    <?php submit_button( 'Generate AI title + description', 'secondary', 'submit', false ); ?>
                                </form>
                                <?php if ( is_array( $pending ) && ! empty( $pending['title'] ) ) : ?>
                                    <div class="seo-pilot-suggestion">
                                        <h4>Pending suggestion — review before applying</h4>
                                        <p><strong>Title:</strong> <?php echo esc_html( $pending['title'] ); ?></p>
                                        <p><strong>Meta description:</strong> <?php echo esc_html( $pending['meta_description'] ); ?></p>
                                        <form class="seo-pilot-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="seo_pilot_apply_fix"><input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>"><?php wp_nonce_field( 'seo_pilot_apply_fix' ); ?>
                                            <?php submit_button( 'Approve & apply', 'primary', 'submit', false ); ?>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <?php if ( is_array( $snapshot ) && isset( $snapshot['title'] ) ) : ?>
                                    <form class="seo-pilot-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="seo_pilot_rollback"><input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>"><?php wp_nonce_field( 'seo_pilot_rollback' ); ?>
                                        <?php submit_button( 'Rollback last applied change', 'delete', 'submit', false ); ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="seo-pilot-footnote"><strong>Current limitations:</strong> local stored-content checks only; no full-site HTTP crawler, Core Web Vitals, Search Console integration, sitemap generation, or automatic fixes for headings/images. Meta descriptions are output only when Yoast SEO, Rank Math, and All in One SEO are not detected.</div>
        </div>
        <?php
    }
}
