<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Menus and the non-workspace screens: Overview (kept from the MVP), Settings and History.
 */
final class SEO_Pilot_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_seo_pilot_run_audit', array( __CLASS__, 'handle_audit' ) );
        // Actions from 0.1.0 forms (e.g. a tab left open during the upgrade) are handled gracefully.
        add_action( 'admin_post_seo_pilot_ai_suggest', array( __CLASS__, 'handle_legacy' ) );
        add_action( 'admin_post_seo_pilot_apply_fix', array( __CLASS__, 'handle_legacy' ) );
        add_action( 'admin_post_seo_pilot_rollback', array( __CLASS__, 'handle_legacy' ) );
    }

    public static function menu() {
        $cap = SEO_Pilot_Util::capability();
        add_menu_page( __( 'SEO Pilot', 'seo-pilot' ), __( 'SEO Pilot', 'seo-pilot' ), $cap, 'seo-pilot', array( __CLASS__, 'render_overview' ), 'dashicons-search', 58 );
        add_submenu_page( 'seo-pilot', __( 'Overview', 'seo-pilot' ), __( 'Overview', 'seo-pilot' ), $cap, 'seo-pilot', array( __CLASS__, 'render_overview' ) );
        add_submenu_page( 'seo-pilot', __( 'Content SEO', 'seo-pilot' ), __( 'Content SEO', 'seo-pilot' ), $cap, 'seo-pilot-content', array( 'SEO_Pilot_Workspace', 'render' ) );
        add_submenu_page( 'seo-pilot', __( 'History', 'seo-pilot' ), __( 'History', 'seo-pilot' ), $cap, 'seo-pilot-history', array( __CLASS__, 'render_history' ) );
        add_submenu_page( 'seo-pilot', __( 'Settings', 'seo-pilot' ), __( 'Settings', 'seo-pilot' ), $cap, 'seo-pilot-settings', array( __CLASS__, 'render_settings' ) );
    }

    public static function assets( $hook ) {
        if ( false === strpos( (string) $hook, 'seo-pilot' ) ) { return; }
        wp_enqueue_style( 'seo-pilot-admin', SEO_PILOT_URL . 'assets/admin.css', array(), SEO_PILOT_VERSION );
    }

    private static function check_cap() {
        if ( ! current_user_can( SEO_Pilot_Util::capability() ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'seo-pilot' ), '', array( 'response' => 403 ) ); }
    }

    /* ------------------------------------------------------------- handlers */

    public static function handle_audit() {
        self::check_cap();
        check_admin_referer( 'seo_pilot_run_audit' );
        $n = SEO_Pilot_Audit_Runner::audit_all( 300 );
        SEO_Pilot_Util::flash( 'success', sprintf( 'Audit completed for %d published page(s). Open a page in Content SEO to review the findings.', $n ) );
        SEO_Pilot_Util::redirect( 'seo-pilot' );
    }

    public static function handle_legacy() {
        self::check_cap();
        SEO_Pilot_Util::flash( 'info', 'AI suggestions, review, approval and rollback now live in the Content SEO workspace. Open a page there to continue.' );
        SEO_Pilot_Util::redirect( 'seo-pilot-content' );
    }

    /* ------------------------------------------------------------- overview */

    public static function render_overview() {
        self::check_cap();
        $audit = get_option( SEO_Pilot_Audit_Runner::SUMMARY_OPTION, array() );
        $items = isset( $audit['items'] ) && is_array( $audit['items'] ) ? $audit['items'] : array();
        $total = 0; $high = 0;
        foreach ( $items as $i ) { $total += (int) $i['issue_count']; $high += (int) $i['high']; }
        uasort( $items, function ( $a, $b ) { return ( (int) $b['high'] - (int) $a['high'] ) ?: ( (int) $b['issue_count'] - (int) $a['issue_count'] ); } );
        $shown = array_slice( $items, 0, 50, true );
        $ai    = SEO_Pilot_Settings::configuration_problem();
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1>SEO Pilot <span class="seo-pilot-version">MVP <?php echo esc_html( SEO_PILOT_VERSION ); ?></span></h1>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <p class="seo-pilot-intro"><?php esc_html_e( 'Audit published pages and posts, review issues, and generate AI suggestions. Changes are never applied without your approval.', 'seo-pilot' ); ?></p>
            <div class="seo-pilot-cards">
                <div class="seo-pilot-card"><span><?php esc_html_e( 'Pages audited', 'seo-pilot' ); ?></span><strong><?php echo esc_html( isset( $audit['count'] ) ? (string) $audit['count'] : '—' ); ?></strong></div>
                <div class="seo-pilot-card"><span><?php esc_html_e( 'Findings (high severity)', 'seo-pilot' ); ?></span><strong><?php echo esc_html( isset( $audit['ran_at'] ) ? $total . ' (' . $high . ')' : '—' ); ?></strong></div>
                <div class="seo-pilot-card"><span><?php esc_html_e( 'Last audit', 'seo-pilot' ); ?></span><strong class="seo-pilot-date"><?php echo esc_html( isset( $audit['ran_at'] ) ? $audit['ran_at'] : __( 'Not run yet', 'seo-pilot' ) ); ?></strong></div>
            </div>
            <div class="seo-pilot-panel">
                <h2><?php esc_html_e( 'Run an SEO audit', 'seo-pilot' ); ?></h2>
                <p><?php esc_html_e( 'Checks up to 300 published posts and pages using local checks on the stored content. It is not a full external crawler, and it does not send anything to an AI provider.', 'seo-pilot' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="seo_pilot_run_audit">
                    <?php wp_nonce_field( 'seo_pilot_run_audit' ); ?>
                    <?php submit_button( __( 'Run audit now', 'seo-pilot' ), 'primary', 'submit', false ); ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-content' ) ); ?>"><?php esc_html_e( 'Open Content SEO', 'seo-pilot' ); ?></a>
                </form>
            </div>
            <div class="seo-pilot-panel">
                <h2><?php esc_html_e( 'AI recommendations', 'seo-pilot' ); ?></h2>
                <?php if ( '' !== $ai ) : ?>
                    <p><?php echo esc_html( $ai ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'seo-pilot' ); ?></a></p>
                <?php else : ?>
                    <?php /* translators: %s: provider host name. */ ?>
                    <p><?php printf( esc_html__( 'AI is configured (provider host: %s). Page content is only sent when you press Generate in the Content SEO workspace.', 'seo-pilot' ), '<code>' . esc_html( SEO_Pilot_Settings::provider_host() ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
                <?php endif; ?>
            </div>
            <div class="seo-pilot-panel">
                <h2><?php esc_html_e( 'Audit findings', 'seo-pilot' ); ?></h2>
                <?php if ( ! $items ) : ?>
                    <p><?php esc_html_e( 'No audit results yet. Run an audit to populate this section.', 'seo-pilot' ); ?></p>
                <?php else : ?>
                    <?php /* translators: 1: number of pages shown, 2: number of pages audited. */ ?>
                    <p class="description"><?php printf( esc_html__( 'Showing the %1$d pages with the most serious findings, out of %2$d audited.', 'seo-pilot' ), count( $shown ), count( $items ) ); ?></p>
                    <?php foreach ( $shown as $pid => $row ) :
                        $post = get_post( (int) $pid );
                        if ( ! $post ) { continue; }
                        $saved = SEO_Pilot_Audit_Runner::get_saved( $pid );
                        ?>
                        <div class="seo-pilot-result">
                            <div class="seo-pilot-result-head">
                                <div>
                                    <h3 dir="auto"><?php echo esc_html( '' !== trim( wp_strip_all_tags( get_the_title( $post ) ) ) ? wp_strip_all_tags( get_the_title( $post ) ) : __( '(Untitled)', 'seo-pilot' ) ); ?></h3>
                                    <a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page ↗', 'seo-pilot' ); ?></a> ·
                                    <a href="<?php echo esc_url( get_edit_post_link( $pid, 'raw' ) ); ?>"><?php esc_html_e( 'Edit in WordPress', 'seo-pilot' ); ?></a> ·
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $pid ) ); ?>"><strong><?php esc_html_e( 'Open in Content SEO', 'seo-pilot' ); ?></strong></a>
                                </div>
                                <?php /* translators: %d: number of findings. */ ?>
                                <span class="seo-pilot-count"><?php echo esc_html( sprintf( _n( '%d finding', '%d findings', (int) $row['issue_count'], 'seo-pilot' ), (int) $row['issue_count'] ) ); ?></span>
                            </div>
                            <?php if ( $saved && ! empty( $saved['findings'] ) ) : ?>
                                <ul class="seo-pilot-issues">
                                    <?php foreach ( array_slice( $saved['findings'], 0, 4 ) as $f ) : ?>
                                        <li><span class="seo-pilot-severity severity-<?php echo esc_attr( $f['severity'] ); ?>"><?php echo esc_html( strtoupper( $f['severity'] ) ); ?></span> <?php echo esc_html( $f['what'] ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p class="seo-pilot-ok"><?php esc_html_e( 'No findings.', 'seo-pilot' ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="seo-pilot-footnote"><strong><?php esc_html_e( 'Current limitations:', 'seo-pilot' ); ?></strong> <?php esc_html_e( 'local stored-content checks only; no full-site HTTP crawler, Core Web Vitals, Search Console or keyword-data integration, sitemap generation, or similar-content detection yet. SEO Pilot never shows search volume, rankings or click-through data because it has none.', 'seo-pilot' ); ?></div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------- settings */

    public static function render_settings() {
        self::check_cap();
        $s    = SEO_Pilot_Settings::all();
        $src  = SEO_Pilot_Settings::key_source();
        $name = SEO_Pilot_Settings::OPTION;
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1><?php esc_html_e( 'SEO Pilot settings', 'seo-pilot' ); ?></h1>
            <?php settings_errors( SEO_Pilot_Settings::OPTION ); SEO_Pilot_Util::render_flash(); ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'seo_pilot_settings_group' ); ?>
                <div class="seo-pilot-panel">
                    <h2><?php esc_html_e( 'AI provider', 'seo-pilot' ); ?></h2>
                    <p><?php esc_html_e( 'Uses an OpenAI-compatible Chat Completions endpoint. All requests are made by your server; the key never reaches the browser.', 'seo-pilot' ); ?></p>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="sp-endpoint"><?php esc_html_e( 'API base URL', 'seo-pilot' ); ?></label></th>
                            <td><input id="sp-endpoint" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://api.openai.com/v1/">
                            <p class="description"><?php esc_html_e( 'Must start with https:// (http:// is accepted only for localhost). Include the base path, commonly /v1/.', 'seo-pilot' ); ?></p></td></tr>
                        <tr><th scope="row"><label for="sp-model"><?php esc_html_e( 'Model', 'seo-pilot' ); ?></label></th>
                            <td><input id="sp-model" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="your-model-name"></td></tr>
                        <tr><th scope="row"><label for="sp-key"><?php esc_html_e( 'API key', 'seo-pilot' ); ?></label></th>
                            <td>
                            <?php if ( 'constant' === $src ) : ?>
                                <p><strong><?php esc_html_e( 'Set by the SEO_PILOT_API_KEY constant in wp-config.php.', 'seo-pilot' ); ?></strong> <?php esc_html_e( 'The database is not used for the key.', 'seo-pilot' ); ?></p>
                            <?php else : ?>
                                <input id="sp-key" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" placeholder="<?php echo esc_attr( 'database' === $src ? __( 'Saved — leave blank to keep the current key', 'seo-pilot' ) : __( 'Enter API key', 'seo-pilot' ) ); ?>">
                                <p class="description"><?php esc_html_e( 'Stored encrypted (when libsodium is available) in a non-autoloaded option, and never printed back. For better protection, define SEO_PILOT_API_KEY in wp-config.php instead. Do not share screenshots containing credentials.', 'seo-pilot' ); ?></p>
                                <?php if ( 'database' === $src ) : ?>
                                    <label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_api_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'seo-pilot' ); ?></label>
                                <?php endif; ?>
                            <?php endif; ?>
                            </td></tr>
                    </table>
                </div>
                <div class="seo-pilot-panel">
                    <h2><?php esc_html_e( 'What is shared with the provider', 'seo-pilot' ); ?></h2>
                    <p><?php esc_html_e( 'When you press "Generate" in the workspace, SEO Pilot sends the selected page\'s title, URL path, current SEO title and description, your keywords and audience notes, its heading outline, and its paragraph text (up to the character limit below) to the provider above. Nothing is sent for other pages, and nothing is sent in the background. The exact text is shown in the workspace before you send it.', 'seo-pilot' ); ?></p>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><?php esc_html_e( 'Confirmation', 'seo-pilot' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ack]" value="1" <?php checked( ! empty( $s['ack'] ) ); ?>> <?php esc_html_e( 'I understand page content is sent to this AI provider, and I am allowed to share it.', 'seo-pilot' ); ?></label>
                            <?php if ( ! empty( $s['ack_time'] ) ) : $u = get_userdata( (int) $s['ack_user'] ); ?>
                                <p class="description"><?php echo esc_html( sprintf( 'Confirmed by %1$s on %2$s.', $u ? $u->display_name : '#' . (int) $s['ack_user'], $s['ack_time'] ) ); ?></p>
                            <?php endif; ?></td></tr>
                        <tr><th scope="row"><label for="sp-max"><?php esc_html_e( 'Content limit (characters)', 'seo-pilot' ); ?></label></th>
                            <td><input id="sp-max" type="number" min="1000" max="12000" step="500" name="<?php echo esc_attr( $name ); ?>[max_chars]" value="<?php echo esc_attr( (string) $s['max_chars'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Paragraphs that do not fit are left out whole (never cut in half). 1,000–12,000.', 'seo-pilot' ); ?></p></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'Headings', 'seo-pilot' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[send_headings]" value="1" <?php checked( ! empty( $s['send_headings'] ) ); ?>> <?php esc_html_e( 'Include the heading outline', 'seo-pilot' ); ?></label></td></tr>
                        <tr><th scope="row"><label for="sp-rate"><?php esc_html_e( 'Requests per hour', 'seo-pilot' ); ?></label></th>
                            <td><input id="sp-rate" type="number" min="1" max="200" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>">
                            <p class="description"><?php echo esc_html( sprintf( 'Site-wide limit. %d remaining this hour.', SEO_Pilot_Rate_Limiter::remaining() ) ); ?></p></td></tr>
                        <tr><th scope="row"><label for="sp-timeout"><?php esc_html_e( 'Timeout (seconds)', 'seo-pilot' ); ?></label></th>
                            <td><input id="sp-timeout" type="number" min="10" max="120" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( (string) $s['timeout'] ); ?>"></td></tr>
                    </table>
                </div>
                <div class="seo-pilot-panel">
                    <h2><?php esc_html_e( 'Data', 'seo-pilot' ); ?></h2>
                    <label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( ! empty( $s['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete all SEO Pilot data when the plugin is deleted: recommendations, history, keywords, settings, and the SEO titles and meta descriptions that SEO Pilot itself stores.', 'seo-pilot' ); ?></label>
                    <p class="description"><?php esc_html_e( 'Off by default so the audit history survives re-installing the plugin.', 'seo-pilot' ); ?></p>
                </div>
                <?php submit_button( __( 'Save settings', 'seo-pilot' ) ); ?>
            </form>
        </div>
        <?php
    }

    /* -------------------------------------------------------------- history */

    public static function render_history() {
        self::check_cap();
        $per    = 25;
        $paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
        $total  = SEO_Pilot_History::count();
        $rows   = SEO_Pilot_History::query( 0, $per, ( $paged - 1 ) * $per );
        $pages  = (int) ceil( $total / $per );
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1><?php esc_html_e( 'SEO Pilot history', 'seo-pilot' ); ?></h1>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <p class="seo-pilot-intro"><?php esc_html_e( 'Every change SEO Pilot makes is recorded here: who, when, which page, which field, and the before and after values. Roll back from the page\'s workspace.', 'seo-pilot' ); ?></p>
            <div class="seo-pilot-panel">
            <?php if ( ! $rows ) : ?>
                <p><?php esc_html_e( 'No changes have been made yet.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <table class="widefat striped seo-pilot-history">
                    <thead><tr><th><?php esc_html_e( 'When', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Who', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Page', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Action', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Field', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Before → after', 'seo-pilot' ); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( $rows as $r ) : echo self::history_row( $r ); endforeach; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </tbody>
                </table>
                <?php if ( $pages > 1 ) : ?>
                    <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ); ?></div></div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** One history table row, fully escaped. Used by the History screen and the workspace. */
    public static function history_row( array $r ) {
        $u     = $r['user_id'] ? get_userdata( $r['user_id'] ) : false;
        $post  = get_post( $r['post_id'] );
        $label = array( 'seo_title' => 'SEO title', 'meta_description' => 'Meta description', 'post_content' => 'Post content', 'post_title' => 'Post title' );
        $short = function ( $v ) { $v = SEO_Pilot_Text::plain( (string) $v ); return SEO_Pilot_Text::len( $v ) > 90 ? SEO_Pilot_Text::sub( $v, 0, 90 ) . '…' : $v; };
        $action = $r['action'] . ( $r['reverted'] ? ' (rolled back)' : '' );
        ob_start();
        ?>
        <tr>
            <td><?php echo esc_html( $r['created_at'] ); ?></td>
            <td><?php echo esc_html( $u ? $u->display_name : __( 'unknown', 'seo-pilot' ) ); ?></td>
            <td><?php echo $post ? '<a href="' . esc_url( admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $r['post_id'] ) ) . '" dir="auto">' . esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) . '</a>' : esc_html( '#' . $r['post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <td><?php echo esc_html( $action ); ?></td>
            <td><?php echo esc_html( isset( $label[ $r['field'] ] ) ? $label[ $r['field'] ] : $r['field'] ); ?></td>
            <td dir="auto"><span class="seo-pilot-del"><?php echo esc_html( $short( $r['old_value'] ) ); ?></span> → <span class="seo-pilot-ins"><?php echo esc_html( $short( $r['new_value'] ) ); ?></span><?php if ( $r['note'] ) : ?><br><em><?php echo esc_html( $r['note'] ); ?></em><?php endif; ?></td>
        </tr>
        <?php
        return ob_get_clean();
    }
}
