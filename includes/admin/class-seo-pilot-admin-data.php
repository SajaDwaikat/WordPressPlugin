<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * "Search data" (CSV import of real search performance data) and "Report" (site-wide findings
 * and criteria, with CSV export) screens and their handlers.
 */
final class SEO_Pilot_Admin_Data {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
        add_action( 'admin_post_seo_pilot_import_search', array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_seo_pilot_delete_import', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_seo_pilot_export_report', array( __CLASS__, 'handle_export' ) );
    }

    public static function menu() {
        $cap = SEO_Pilot_Util::capability();
        add_submenu_page( 'seo-pilot', __( 'Report', 'seo-pilot' ), __( 'Report', 'seo-pilot' ), $cap, 'seo-pilot-report', array( __CLASS__, 'render_report' ) );
        add_submenu_page( 'seo-pilot', __( 'Search data', 'seo-pilot' ), __( 'Search data', 'seo-pilot' ), $cap, 'seo-pilot-search', array( __CLASS__, 'render_search' ) );
    }

    private static function gate() {
        if ( ! current_user_can( SEO_Pilot_Util::capability() ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'seo-pilot' ), '', array( 'response' => 403 ) ); }
    }

    /* ------------------------------------------------------------- handlers */

    public static function handle_import() {
        self::gate();
        check_admin_referer( 'seo_pilot_import_search' );
        $file = ( isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ) ? $_FILES['csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $fail = function ( $msg ) { SEO_Pilot_Util::flash( 'error', $msg ); SEO_Pilot_Util::redirect( 'seo-pilot-search' ); };
        if ( ! $file || ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) ) { $fail( 'Choose a CSV file to import.' ); }
        if ( UPLOAD_ERR_OK !== (int) $file['error'] ) { $fail( 'The file could not be uploaded (error ' . (int) $file['error'] . '). Check the server\'s upload size limit.' ); }
        if ( (int) $file['size'] > SEO_Pilot_Searchdata::MAX_BYTES ) { $fail( 'The file is larger than 5 MB.' ); }
        $ext = strtolower( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) { $fail( 'Only .csv files are accepted.' ); }
        if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) { $fail( 'The upload could not be verified.' ); }
        $csv = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $res = SEO_Pilot_Searchdata::import( (string) $csv, array(
            'label' => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
            'post_id' => isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
        ) );
        if ( is_wp_error( $res ) ) { $fail( $res->get_error_message() ); }
        SEO_Pilot_Util::flash( 'success', sprintf( 'Imported %d row(s) for %d page(s) (%s). %d row(s) from other hosts and %d that are not WordPress pages were skipped.', $res['rows'], $res['posts'], $res['kind'], $res['foreign'], $res['unresolved'] ) );
        SEO_Pilot_Util::redirect( 'seo-pilot-search' );
    }

    public static function handle_delete() {
        self::gate();
        $id = isset( $_POST['import_id'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', wp_unslash( $_POST['import_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        check_admin_referer( 'seo_pilot_delete_import_' . $id );
        SEO_Pilot_Searchdata::delete_import( $id );
        SEO_Pilot_Util::flash( 'success', 'The import and its data were deleted.' );
        SEO_Pilot_Util::redirect( 'seo-pilot-search' );
    }

    public static function handle_export() {
        self::gate();
        check_admin_referer( 'seo_pilot_export_report' );
        $csv = SEO_Pilot_Report::csv( SEO_Pilot_Audit_Runner::all_saved() );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="seo-pilot-report-' . gmdate( 'Y-m-d' ) . '.csv"' );
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; cells are formula-guarded in SEO_Pilot_Report::csv_cell().
        exit;
    }

    /* -------------------------------------------------------------- screens */

    public static function render_search() {
        self::gate();
        $imports = SEO_Pilot_Searchdata::imports();
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1><?php esc_html_e( 'Search data', 'seo-pilot' ); ?></h1>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <p class="seo-pilot-intro"><?php esc_html_e( 'SEO Pilot has no search volume, keyword difficulty, ranking or click-through data of its own, and never estimates them. If you import an export from Google Search Console (or another tool), the real numbers in that file are shown in the workspace, clearly labelled with the period you describe.', 'seo-pilot' ); ?></p>
            <div class="seo-pilot-panel">
                <h2><?php esc_html_e( 'Import a CSV export', 'seo-pilot' ); ?></h2>
                <p class="description"><?php esc_html_e( 'The file stays in your database; nothing is sent anywhere. Accepted: a Pages report (Page, Clicks, Impressions, Position), a Queries report for one page you choose below (Query, Clicks, Impressions, Position), or a combined Page + Query export. Column headers must be in English. The CTR column is ignored (it is recomputed). Search Console hides rare queries, so query rows are not complete totals.', 'seo-pilot' ); ?></p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="seo_pilot_import_search">
                    <?php wp_nonce_field( 'seo_pilot_import_search' ); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="sp-csv"><?php esc_html_e( 'CSV file', 'seo-pilot' ); ?></label></th><td><input id="sp-csv" type="file" name="csv" accept=".csv,text/csv"></td></tr>
                        <tr><th scope="row"><label for="sp-label"><?php esc_html_e( 'Period / source label', 'seo-pilot' ); ?></label></th><td><input id="sp-label" class="regular-text" type="text" name="label" maxlength="100" required placeholder="Search Console, last 3 months, exported 2026-10-01"><p class="description"><?php esc_html_e( 'Required. You are telling SEO Pilot what the file covers; it is displayed next to every number.', 'seo-pilot' ); ?></p></td></tr>
                        <tr><th scope="row"><label for="sp-post"><?php esc_html_e( 'Page ID (Queries report only)', 'seo-pilot' ); ?></label></th><td><input id="sp-post" type="number" min="1" name="post_id"><p class="description"><?php esc_html_e( 'Only needed when the file has no Page column. The ID appears in the editor URL (post=123).', 'seo-pilot' ); ?></p></td></tr>
                    </table>
                    <?php submit_button( __( 'Import', 'seo-pilot' ), 'primary', 'submit', false ); ?>
                </form>
            </div>
            <div class="seo-pilot-panel">
                <h2><?php esc_html_e( 'Imports', 'seo-pilot' ); ?></h2>
                <?php if ( ! $imports ) : ?><p><?php esc_html_e( 'Nothing imported yet.', 'seo-pilot' ); ?></p><?php else : ?>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Label', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Type', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Rows', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Pages', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Imported', 'seo-pilot' ); ?></th><th></th></tr></thead><tbody>
                    <?php foreach ( $imports as $i ) : ?>
                        <tr><td dir="auto"><?php echo esc_html( $i['label'] ); ?></td><td><?php echo esc_html( $i['kind'] ); ?></td><td><?php echo esc_html( (string) $i['rows'] ); ?></td><td><?php echo esc_html( (string) $i['posts'] ); ?></td><td><?php echo esc_html( $i['created_at'] ); ?></td>
                            <td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="seo_pilot_delete_import"><input type="hidden" name="import_id" value="<?php echo esc_attr( $i['id'] ); ?>"><?php wp_nonce_field( 'seo_pilot_delete_import_' . $i['id'] ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Delete', 'seo-pilot' ); ?></button></form></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p class="description"><?php esc_html_e( 'When a page appears in several imports, the most recent one is used.', 'seo-pilot' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function render_report() {
        self::gate();
        $audits = SEO_Pilot_Audit_Runner::all_saved();
        $rep    = $audits ? SEO_Pilot_Report::site_report( $audits ) : null;
        $st     = array( 'fail' => 'Fails', 'warn' => 'Needs attention', 'manual' => 'Needs a human', 'pass' => 'Pass' );
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1><?php esc_html_e( 'SEO Pilot report', 'seo-pilot' ); ?></h1>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <?php if ( ! $rep ) : ?>
                <p><?php esc_html_e( 'No audits yet. Run an audit from the Overview first.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <p class="seo-pilot-intro"><?php echo esc_html( sprintf( '%d page(s) audited. %d high, %d medium, %d low severity findings and %d informational; %d of all findings need a human to judge.', $rep['pages'], $rep['severity']['high'], $rep['severity']['medium'], $rep['severity']['low'], $rep['severity']['info'], $rep['needs_human'] ) ); ?>
                    <?php esc_html_e( 'This is a rule-based checklist summary, not a ranking prediction.', 'seo-pilot' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="seo_pilot_export_report"><?php wp_nonce_field( 'seo_pilot_export_report' ); ?><?php submit_button( __( 'Download all findings (CSV)', 'seo-pilot' ), 'secondary', 'submit', false ); ?></form>
                <div class="seo-pilot-panel"><h2><?php esc_html_e( 'By criterion (number of pages)', 'seo-pilot' ); ?></h2>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Criterion', 'seo-pilot' ); ?></th><?php foreach ( $st as $l ) { echo '<th>' . esc_html( $l ) . '</th>'; } ?></tr></thead><tbody>
                    <?php foreach ( $rep['criteria'] as $c ) : ?><tr><td><?php echo esc_html( $c['label'] ); ?></td><?php foreach ( array_keys( $st ) as $k ) { echo '<td>' . esc_html( (string) $c[ $k ] ) . '</td>'; } ?></tr><?php endforeach; ?>
                    </tbody></table></div>
                <div class="seo-pilot-panel"><h2><?php esc_html_e( 'Most common findings', 'seo-pilot' ); ?></h2>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Severity', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Finding', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Pages', 'seo-pilot' ); ?></th></tr></thead><tbody>
                    <?php foreach ( $rep['top_issues'] as $i ) : ?><tr><td><span class="seo-pilot-severity severity-<?php echo esc_attr( $i['severity'] ); ?>"><?php echo esc_html( strtoupper( $i['severity'] ) ); ?></span></td><td dir="auto"><?php echo esc_html( $i['what'] ); ?></td><td><?php echo esc_html( (string) $i['pages'] ); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                    <p class="description"><?php esc_html_e( 'The text shown is the first page\'s wording for that finding type.', 'seo-pilot' ); ?></p></div>
                <div class="seo-pilot-panel"><h2><?php esc_html_e( 'Pages to look at first', 'seo-pilot' ); ?></h2>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Page', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'High', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Medium', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Total', 'seo-pilot' ); ?></th></tr></thead><tbody>
                    <?php foreach ( $rep['worst_pages'] as $w ) : ?><tr><td dir="auto"><a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $w['post_id'] ) ); ?>"><?php echo esc_html( '' !== $w['title'] ? $w['title'] : __( '(Untitled)', 'seo-pilot' ) ); ?></a></td><td><?php echo esc_html( (string) $w['high'] ); ?></td><td><?php echo esc_html( (string) $w['medium'] ); ?></td><td><?php echo esc_html( (string) $w['total'] ); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
