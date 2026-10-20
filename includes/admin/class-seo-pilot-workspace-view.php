<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rendering for the Content SEO list and the per-page workspace. Output only: no state changes here.
 * Everything printed is escaped; diff markup is passed through a strict allow-list.
 */
final class SEO_Pilot_Workspace_View {

    /* ---------------------------------------------------------------- helpers */

    private static function e( $s ) { return esc_html( (string) $s ); }

    private static function diff( $old, $new ) {
        return wp_kses( SEO_Pilot_Diff::html( (string) $old, (string) $new ), array( 'del' => array( 'class' => true ), 'ins' => array( 'class' => true ) ) );
    }

    private static function badge( $text, $class = '' ) {
        return '<span class="seo-pilot-badge ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
    }

    private static function form_open( $action, $post_id, $nonce_key = null, $class = '' ) {
        echo '<form method="post" class="' . esc_attr( $class ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="seo_pilot_' . esc_attr( $action ) . '">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
        wp_nonce_field( 'seo_pilot_' . ( $nonce_key ? $nonce_key : $action ) . '_' . $post_id );
    }

    private static function title_of( $post ) {
        $t = trim( wp_strip_all_tags( get_the_title( $post ) ) );
        return '' !== $t ? $t : __( '(Untitled)', 'seo-pilot' );
    }

    private static function status_label( $status ) {
        $m = array( 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending review', 'future' => 'Scheduled', 'private' => 'Private' );
        return isset( $m[ $status ] ) ? $m[ $status ] : $status;
    }

    public static function render() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        if ( $post_id ) { self::render_workspace( $post_id ); } else { self::render_list(); }
    }

    /* -------------------------------------------------------------------- list */

    private static function render_list() {
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $type   = isset( $_GET['ptype'] ) ? sanitize_key( wp_unslash( $_GET['ptype'] ) ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        // phpcs:enable
        $types    = SEO_Pilot_Util::content_post_types();
        $statuses = SEO_Pilot_Util::content_statuses();
        $q = new WP_Query( array(
            'post_type' => in_array( $type, $types, true ) ? $type : $types,
            'post_status' => in_array( $status, $statuses, true ) ? $status : $statuses,
            's' => $search, 'posts_per_page' => 20, 'paged' => $paged, 'orderby' => 'modified', 'order' => 'DESC',
        ) );
        if ( $q->posts ) { update_meta_cache( 'post', wp_list_pluck( $q->posts, 'ID' ) ); }
        ?>
        <div class="wrap seo-pilot-wrap">
            <h1><?php esc_html_e( 'Content SEO', 'seo-pilot' ); ?></h1>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <p class="seo-pilot-intro"><?php esc_html_e( 'Pick a page or post to audit its content, set a keyword, and review AI recommendations. Nothing is changed until you approve it.', 'seo-pilot' ); ?></p>
            <form method="get" class="seo-pilot-filters">
                <input type="hidden" name="page" value="seo-pilot-content">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search titles…', 'seo-pilot' ); ?>">
                <select name="ptype"><option value=""><?php esc_html_e( 'All types', 'seo-pilot' ); ?></option>
                    <?php foreach ( $types as $t ) : $o = get_post_type_object( $t ); ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $o ? $o->labels->name : $t ); ?></option><?php endforeach; ?>
                </select>
                <select name="status"><option value=""><?php esc_html_e( 'All statuses', 'seo-pilot' ); ?></option>
                    <?php foreach ( $statuses as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option><?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'seo-pilot' ), 'secondary', '', false ); ?>
            </form>
            <div class="seo-pilot-panel">
            <?php if ( ! $q->have_posts() ) : ?>
                <p><?php esc_html_e( 'No pages found.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <table class="widefat striped seo-pilot-list">
                    <thead><tr><th><?php esc_html_e( 'Title', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Type', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Status', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Findings', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Primary keyword', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Last audited', 'seo-pilot' ); ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $q->posts as $p ) :
                        $a   = SEO_Pilot_Audit_Runner::get_saved( $p->ID );
                        $kw  = (string) get_post_meta( $p->ID, SEO_Pilot_Audit_Runner::META_PRIMARY, true );
                        $url = admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $p->ID );
                        ?>
                        <tr>
                            <td dir="auto"><strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( self::title_of( $p ) ); ?></a></strong></td>
                            <td><?php echo esc_html( $p->post_type ); ?></td>
                            <td><?php echo esc_html( self::status_label( $p->post_status ) ); ?></td>
                            <td><?php if ( $a ) : $sv = $a['summary']['severity']; ?>
                                <?php foreach ( array( 'high', 'medium', 'low' ) as $sev ) : if ( $sv[ $sev ] ) : ?><span class="seo-pilot-severity severity-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( $sv[ $sev ] . ' ' . $sev ); ?></span> <?php endif; endforeach; ?>
                                <?php if ( ! $sv['high'] && ! $sv['medium'] && ! $sv['low'] ) { esc_html_e( 'None', 'seo-pilot' ); } ?>
                                <?php else : ?>—<?php endif; ?></td>
                            <td dir="auto"><?php echo '' !== $kw ? esc_html( $kw ) : '—'; ?></td>
                            <td><?php echo $a ? esc_html( $a['audited_at'] ) : '—'; ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open workspace', 'seo-pilot' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( $q->max_num_pages > 1 ) : ?>
                    <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages ) ) ); ?></div></div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* --------------------------------------------------------------- workspace */

    private static function render_workspace( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, SEO_Pilot_Util::content_post_types(), true ) ) { wp_die( esc_html__( 'That post is not available.', 'seo-pilot' ), '', array( 'response' => 404 ) ); }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { wp_die( esc_html__( 'You are not allowed to edit this post.', 'seo-pilot' ), '', array( 'response' => 403 ) ); }

        $audit = SEO_Pilot_Audit_Runner::get_saved( $post_id );
        if ( ! $audit ) { $audit = SEO_Pilot_Audit_Runner::audit_post( $post ); }
        $stale_audit = isset( $audit['content_hash'] ) && sha1( (string) $post->post_content ) !== $audit['content_hash'];
        $dir = $audit['rtl'] ? 'rtl' : 'auto';
        ?>
        <div class="wrap seo-pilot-wrap seo-pilot-workspace">
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-content' ) ); ?>">← <?php esc_html_e( 'All pages', 'seo-pilot' ); ?></a></p>
            <h1 dir="<?php echo esc_attr( $dir ); ?>"><?php echo esc_html( self::title_of( $post ) ); ?> <?php echo self::badge( self::status_label( $post->post_status ), 'status-' . $post->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h1>
            <p class="seo-pilot-urlline">
                <code dir="ltr"><?php echo esc_html( get_permalink( $post ) ); ?></code> ·
                <a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit in WordPress', 'seo-pilot' ); ?></a> ·
                <a href="<?php echo esc_url( 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo 'publish' === $post->post_status ? esc_html__( 'View page ↗', 'seo-pilot' ) : esc_html__( 'Preview ↗', 'seo-pilot' ); ?></a>
            </p>
            <?php SEO_Pilot_Util::render_flash(); ?>
            <?php if ( $stale_audit ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'The post has been edited since this audit ran. Refresh the audit to see current findings.', 'seo-pilot' ); ?></p></div><?php endif; ?>

            <?php
            self::section_fields( $post );
            self::section_page_settings( $post, $audit );
            self::section_assessment( $audit );
            self::section_findings( $audit );
            self::section_alt( $post, $audit );
            self::section_searchdata( $post );
            self::section_site( $post );
            self::section_ai( $post, $audit );
            self::section_recs( $post );
            self::section_apply( $post );
            self::section_live( $post );
            self::section_history( $post );
            ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------ sections */

    private static function section_fields( WP_Post $post ) {
        $st = SEO_Pilot_Compat::get( $post->ID, 'seo_title' );
        $md = SEO_Pilot_Compat::get( $post->ID, 'meta_description' );
        $active = SEO_Pilot_Compat::active_plugins();
        $owner  = SEO_Pilot_Compat::owner();
        $fk = SEO_Pilot_Compat::detected_focus_keyword( $post->ID );
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Current SEO fields and who owns them', 'seo-pilot' ); ?></h2>
            <?php if ( $active ) : ?>
                <p><?php echo esc_html( 'SEO plugin detected: ' . implode( ', ', array_map( array( 'SEO_Pilot_Compat', 'label' ), $active ) ) . '. SEO Pilot does not print its own SEO tags while another SEO plugin is active, and it writes to that plugin\'s fields only after you approve it.' ); ?></p>
                <?php if ( SEO_Pilot_Compat::has_conflict() ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'More than one SEO plugin is active. That commonly produces duplicate title, description or canonical tags; consider keeping only one. SEO Pilot treats the first one listed as the owner.', 'seo-pilot' ); ?></p></div><?php endif; ?>
            <?php else : ?>
                <p><?php esc_html_e( 'No other SEO plugin was detected, so SEO Pilot manages the SEO title and meta description for this site.', 'seo-pilot' ); ?></p>
            <?php endif; ?>
            <table class="widefat striped seo-pilot-fields">
                <thead><tr><th><?php esc_html_e( 'Field', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Current value', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Owner', 'seo-pilot' ); ?></th></tr></thead>
                <tbody>
                    <tr><td><?php esc_html_e( 'Page title', 'seo-pilot' ); ?></td><td dir="auto"><?php echo esc_html( self::title_of( $post ) ); ?></td><td>WordPress</td></tr>
                    <tr><td><?php esc_html_e( 'SEO title', 'seo-pilot' ); ?></td>
                        <td dir="auto"><?php echo '' !== $st['value'] ? esc_html( $st['value'] ) : '<em>' . esc_html__( 'Not set — the page title is used', 'seo-pilot' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php if ( $st['template_vars'] ) { echo ' ' . self::badge( 'uses template variables', 'muted' ); } // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><?php echo esc_html( $st['owner_label'] ); ?><?php echo $st['writable'] ? '' : ' ' . self::badge( 'read-only', 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
                    <tr><td><?php esc_html_e( 'Meta description', 'seo-pilot' ); ?></td>
                        <td dir="auto"><?php echo '' !== $md['value'] ? esc_html( $md['value'] ) : '<em>' . esc_html__( 'Not set', 'seo-pilot' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php if ( $md['template_vars'] ) { echo ' ' . self::badge( 'uses template variables', 'muted' ); } // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><?php echo esc_html( $md['owner_label'] ); ?><?php echo $md['writable'] ? '' : ' ' . self::badge( 'read-only', 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
                    <?php if ( '' !== $fk['value'] ) : ?><tr><td><?php esc_html_e( 'Focus keyword', 'seo-pilot' ); ?></td><td dir="auto"><?php echo esc_html( $fk['value'] ); ?></td><td><?php echo esc_html( SEO_Pilot_Compat::label( $fk['owner'] ) ); ?> <?php echo self::badge( 'read-only', 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if ( 'aioseo' === $owner ) : ?><p class="description"><?php esc_html_e( 'All in One SEO keeps its data in its own tables. SEO Pilot only reads it (and the read has not been verified against every AIOSEO version), so approved text must be pasted into AIOSEO by hand.', 'seo-pilot' ); ?></p><?php endif; ?>
            <p class="description"><?php esc_html_e( 'Canonical tags, robots directives, structured data and publication dates are never changed by SEO Pilot.', 'seo-pilot' ); ?></p>
        </div>
        <?php
    }

    private static function section_page_settings( WP_Post $post, array $audit ) {
        $set = SEO_Pilot_Audit_Runner::page_settings( $post->ID );
        $own = (string) get_post_meta( $post->ID, SEO_Pilot_Audit_Runner::META_PRIMARY, true );
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Keywords, intent and audience (optional)', 'seo-pilot' ); ?></h2>
            <p class="description"><?php esc_html_e( 'These notes guide the checks and the AI. Keywords are not required, and there is no keyword-density target.', 'seo-pilot' ); ?></p>
            <?php self::form_open( 'save_page', $post->ID, 'page' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="sp-primary"><?php esc_html_e( 'Primary keyword', 'seo-pilot' ); ?></label></th>
                        <td><input id="sp-primary" class="regular-text" dir="auto" type="text" name="primary" value="<?php echo esc_attr( $own ); ?>">
                        <?php if ( '' === $own && '' !== $set['primary'] ) : ?><p class="description"><?php echo esc_html( sprintf( 'Detected in %s: "%s" (used for the checks; type it here to set it in SEO Pilot).', $set['primary_source'], $set['primary'] ) ); ?></p><?php endif; ?></td></tr>
                    <tr><th scope="row"><label for="sp-secondary"><?php esc_html_e( 'Secondary keywords', 'seo-pilot' ); ?></label></th>
                        <td><textarea id="sp-secondary" class="large-text" dir="auto" rows="2" name="secondary"><?php echo esc_textarea( implode( "\n", $set['secondary'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'One per line or comma-separated.', 'seo-pilot' ); ?></p></td></tr>
                    <tr><th scope="row"><label for="sp-intent"><?php esc_html_e( 'Search intent', 'seo-pilot' ); ?></label></th>
                        <td><select id="sp-intent" name="intent"><option value=""><?php echo esc_html( sprintf( 'Estimate automatically (currently: %s)', isset( $audit['intent']['estimate']['label'] ) ? $audit['intent']['estimate']['label'] : $audit['intent']['label'] ) ); ?></option>
                            <?php foreach ( SEO_Pilot_Intent::labels() as $l ) : ?><option value="<?php echo esc_attr( $l ); ?>" <?php selected( $set['intent'], $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th scope="row"><label for="sp-audience"><?php esc_html_e( 'Target audience', 'seo-pilot' ); ?></label></th>
                        <td><input id="sp-audience" class="large-text" dir="auto" type="text" name="audience" value="<?php echo esc_attr( $set['audience'] ); ?>"></td></tr>
                </table>
                <?php submit_button( __( 'Save and refresh audit', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    private static function group_label( $g ) {
        $m = array( 'meta' => 'Titles, descriptions and indexing', 'structure' => 'Structure and headings', 'content' => 'Content', 'readability' => 'Readability', 'links' => 'Links', 'images' => 'Images', 'keywords' => 'Keywords', 'intent' => 'Search intent alignment', 'verification' => 'Facts to verify' );
        return isset( $m[ $g ] ) ? $m[ $g ] : $g;
    }

    private static function status_text( $s ) {
        $m = array( 'pass' => 'Pass', 'warn' => 'Needs attention', 'fail' => 'Fails', 'manual' => 'Needs a human', 'info' => 'Info', 'na' => 'Not applicable' );
        return isset( $m[ $s ] ) ? $m[ $s ] : $s;
    }

    private static function section_assessment( array $audit ) {
        $c = $audit['summary']['checks'];
        $groups = array();
        foreach ( $audit['checklist'] as $row ) { $groups[ $row['group'] ][] = $row; }
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Content checklist', 'seo-pilot' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="seo-pilot-inline">
                <input type="hidden" name="action" value="seo_pilot_audit_post"><input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $audit['post_id'] ); ?>">
                <?php wp_nonce_field( 'seo_pilot_audit_' . $audit['post_id'] ); ?>
                <?php submit_button( __( 'Refresh audit', 'seo-pilot' ), 'secondary small', 'submit', false ); ?>
            </form>
            <p><strong><?php echo esc_html( sprintf( '%d pass · %d need attention · %d fail · %d need a human · %d informational', $c['pass'], $c['warn'], $c['fail'], $c['manual'], $c['info'] ) ); ?></strong>
               <span class="description"> — <?php echo esc_html( sprintf( 'audited %s; about %d words; language detected: %s.', $audit['audited_at'], $audit['metrics']['word_count'], $audit['language'] ) ); ?></span></p>
            <p class="description"><?php echo esc_html( $audit['summary']['note'] ); ?></p>
            <h3><?php esc_html_e( 'Assessment by criterion', 'seo-pilot' ); ?></h3>
            <table class="widefat striped seo-pilot-criteria"><thead><tr><th><?php esc_html_e( 'Criterion', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Status', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'What influenced it', 'seo-pilot' ); ?></th></tr></thead><tbody>
            <?php foreach ( SEO_Pilot_Report::criteria( $audit ) as $crit ) : ?>
                <tr><td><?php echo esc_html( $crit['label'] ); ?></td>
                    <td><?php echo self::badge( self::status_text( $crit['status'] ), 'check-' . $crit['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <span class="description"><?php echo esc_html( $crit['total'] ? $crit['pass'] . ' of ' . $crit['total'] . ' checks pass' : '' ); ?></span></td>
                    <td dir="auto"><?php echo $crit['influenced_by'] ? esc_html( implode( ' · ', $crit['influenced_by'] ) ) : '<span class="description">' . esc_html__( 'All checks in this criterion pass (or none apply).', 'seo-pilot' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <p class="description"><?php esc_html_e( 'No single score is shown on purpose: a number would hide which check caused what, and no tool can predict rankings.', 'seo-pilot' ); ?></p>
            <details><summary><?php esc_html_e( 'Show every check and what influenced it', 'seo-pilot' ); ?></summary>
                <?php foreach ( $groups as $g => $rows ) : ?>
                    <h4><?php echo esc_html( self::group_label( $g ) ); ?></h4>
                    <ul class="seo-pilot-checklist">
                        <?php foreach ( $rows as $row ) : ?>
                            <li><?php echo self::badge( self::status_text( $row['status'] ), 'check-' . $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <span dir="auto"><?php echo esc_html( $row['label'] ); ?></span><?php if ( $row['detail'] ) : ?> <span class="description" dir="auto">— <?php echo esc_html( $row['detail'] ); ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </details>
        </div>
        <?php
    }

    private static function section_findings( array $audit ) {
        $intent = $audit['intent'];
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Prioritised findings', 'seo-pilot' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Each finding says what was detected, why it may matter, and what to do. Items marked "needs a human" cannot be judged by software.', 'seo-pilot' ); ?></p>
            <?php if ( ! $audit['findings'] ) : ?><p class="seo-pilot-ok"><?php esc_html_e( 'No findings from the current checks.', 'seo-pilot' ); ?></p><?php endif; ?>
            <?php foreach ( $audit['findings'] as $f ) : ?>
                <div class="seo-pilot-finding severity-border-<?php echo esc_attr( $f['severity'] ); ?>">
                    <p><span class="seo-pilot-severity severity-<?php echo esc_attr( $f['severity'] ); ?>"><?php echo esc_html( strtoupper( $f['severity'] ) ); ?></span>
                        <strong dir="auto"><?php echo esc_html( $f['what'] ); ?></strong>
                        <?php if ( $f['needs_human'] ) { echo ' ' . self::badge( 'needs a human', 'muted' ); } // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
                    <p class="seo-pilot-why"><em><?php esc_html_e( 'Why it may matter:', 'seo-pilot' ); ?></em> <?php echo esc_html( $f['why'] ); ?></p>
                    <p><em><?php esc_html_e( 'Suggested action:', 'seo-pilot' ); ?></em> <?php echo esc_html( $f['action'] ); ?></p>
                    <?php if ( $f['evidence'] && 'alt_missing' !== $f['id'] ) : ?>
                        <ul class="seo-pilot-evidence" dir="auto"><?php foreach ( $f['evidence'] as $ev ) : ?><li><?php echo esc_html( is_array( $ev ) ? wp_json_encode( $ev ) : $ev ); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <h3><?php esc_html_e( 'Search intent', 'seo-pilot' ); ?></h3>
            <p><?php echo esc_html( 'Intent used: ' . $intent['label'] . ' (' . ( 'user' === $intent['source'] ? 'set by you' : 'rule-based estimate, ' . $intent['confidence'] . ' confidence' ) . ').' ); ?>
               <?php if ( ! empty( $intent['signals'] ) ) { foreach ( $intent['signals'] as $k => $v ) { echo ' ' . esc_html( $k . ': ' . implode( ', ', $v ) . '.' ); } } ?></p>
            <p class="description"><?php esc_html_e( 'Estimates here come from wording rules and, if you request them, the AI. They are not search data. SEO Pilot has no search volume, keyword difficulty, ranking or click-through numbers; those need an external source such as Google Search Console or a keyword data provider.', 'seo-pilot' ); ?></p>
        </div>
        <?php
    }

    private static function section_alt( WP_Post $post, array $audit ) {
        $ev = array();
        foreach ( $audit['findings'] as $f ) { if ( 'alt_missing' === $f['id'] ) { $ev = $f['evidence']; } }
        if ( ! $ev ) { return; }
        $existing = array();
        foreach ( SEO_Pilot_Recs::for_post( $post->ID, array( 'accepted' ) ) as $r ) { if ( 'alt_text' === $r['rec_type'] ) { $existing[ substr( $r['target'], 4 ) ] = $r['proposed']; } }
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Image alt text — needs your input', 'seo-pilot' ); ?></h2>
            <p><?php esc_html_e( 'SEO Pilot cannot see your images and will not guess what they show. Describe each image in a short phrase (leave blank to skip). What you type is saved as accepted, but is only applied when you confirm in "Apply accepted changes".', 'seo-pilot' ); ?></p>
            <?php self::form_open( 'save_alt', $post->ID, 'alt' ); ?>
                <table class="widefat striped">
                    <?php foreach ( $ev as $e ) : ?>
                        <tr><td class="seo-pilot-img-src"><code dir="ltr"><?php echo esc_html( SEO_Pilot_Text::len( $e['src'] ) > 70 ? '…' . SEO_Pilot_Text::sub( $e['src'], -60 ) : $e['src'] ); ?></code>
                            <?php if ( '' !== $e['context'] ) : ?><br><span class="description" dir="auto"><?php echo esc_html( 'Near the heading: ' . $e['context'] ); ?></span><?php endif; ?></td>
                            <td><input type="text" class="large-text" dir="auto" maxlength="300" name="alt[<?php echo esc_attr( $e['hash'] ); ?>]" value="<?php echo esc_attr( isset( $existing[ $e['hash'] ] ) ? $existing[ $e['hash'] ] : '' ); ?>" aria-label="<?php esc_attr_e( 'Alt text', 'seo-pilot' ); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button( __( 'Save alt text as accepted', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    private static function section_searchdata( WP_Post $post ) {
        $imp = SEO_Pilot_Searchdata::latest_import_for( $post->ID );
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Search data (imported)', 'seo-pilot' ); ?></h2>
            <?php if ( ! $imp ) : ?>
                <p><?php esc_html_e( 'No search data has been imported for this page. SEO Pilot has no search volume, ranking or click-through numbers of its own and will not estimate them.', 'seo-pilot' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-search' ) ); ?>"><?php esc_html_e( 'Import a Search Console export', 'seo-pilot' ); ?></a></p>
            <?php else :
                $t = SEO_Pilot_Searchdata::totals( $post->ID, $imp['id'] );
                $q = SEO_Pilot_Searchdata::top_queries( $post->ID, $imp['id'], 10 );
                ?>
                <p><strong><?php esc_html_e( 'Verified data from your import', 'seo-pilot' ); ?></strong> — <span dir="auto"><?php echo esc_html( $imp['label'] ); ?></span>, <?php echo esc_html( 'imported ' . $imp['created_at'] ); ?>.
                    <?php echo esc_html( sprintf( '%d impressions and %d clicks in the imported rows.', $t['impressions'], $t['clicks'] ) ); ?></p>
                <?php if ( $q ) : ?>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Query', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Impressions', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Clicks', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Avg. position', 'seo-pilot' ); ?></th></tr></thead><tbody>
                    <?php foreach ( $q as $row ) : ?><tr><td dir="auto"><?php echo esc_html( $row['query'] ); ?></td><td><?php echo esc_html( (string) $row['impressions'] ); ?></td><td><?php echo esc_html( (string) $row['clicks'] ); ?></td><td><?php echo esc_html( (string) $row['position'] ); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <p class="description"><?php esc_html_e( 'These figures come from the file you imported, exactly as exported. They describe the period you labelled; they are not live, and impressions or position do not by themselves show that a page is doing well or badly.', 'seo-pilot' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function section_site( WP_Post $post ) {
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Related pages on this site (local analysis)', 'seo-pilot' ); ?></h2>
            <p><?php esc_html_e( 'Finds places where this page\'s own text already names another of your pages (so a link would be natural), pages that already mention this page without linking to it, and terms that closely related pages use but this page does not. It uses only your site\'s content, runs on your server, and sends nothing to an AI provider. Results appear below as recommendations; nothing changes until you approve it.', 'seo-pilot' ); ?></p>
            <?php self::form_open( 'analyse_site', $post->ID, 'analyse' ); submit_button( __( 'Analyse related pages', 'seo-pilot' ), 'secondary', 'submit', false ); echo '</form>'; ?>
        </div>
        <?php
    }

    private static function section_ai( WP_Post $post, array $audit ) {
        $problem = SEO_Pilot_Settings::configuration_problem();
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'AI recommendations', 'seo-pilot' ); ?></h2>
            <?php if ( '' !== $problem ) : ?>
                <p><?php echo esc_html( $problem ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'seo-pilot' ); ?></a></p>
            <?php else :
                $ctx   = SEO_Pilot_Workspace::prompt_context( $post, $audit, '' );
                $built = SEO_Pilot_Prompt::build( $ctx, array_keys( SEO_Pilot_Prompt::tasks() ) );
                ?>
                <?php /* translators: 1: provider host name, 2: model name. */ ?>
                <p><?php printf( esc_html__( 'Page content will be sent to %1$s (model %2$s) only when you press Generate. The AI proposes; nothing changes on the page.', 'seo-pilot' ), '<code>' . esc_html( SEO_Pilot_Settings::provider_host() ) . '</code>', '<code>' . esc_html( SEO_Pilot_Settings::get( 'model' ) ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
                <details><summary><?php echo esc_html( sprintf( 'Show exactly what will be sent (about %d characters, %d requests left this hour)', $built['sent_chars'], SEO_Pilot_Rate_Limiter::remaining() ) ); ?></summary>
                    <p class="description"><?php esc_html_e( 'The instructions that accompany this text are fixed by the plugin. Only the page text below, your keywords, and your optional note are page-specific. Unchecked tasks send less.', 'seo-pilot' ); ?></p>
                    <pre class="seo-pilot-preview" dir="auto"><?php echo esc_html( $built['preview'] ); ?></pre>
                    <?php if ( $built['truncated'] ) : ?><p class="description"><?php esc_html_e( 'Some paragraphs do not fit within the character limit and will be left out (and will not be rewritten).', 'seo-pilot' ); ?></p><?php endif; ?>
                </details>
                <?php self::form_open( 'generate', $post->ID, 'generate' ); ?>
                    <fieldset class="seo-pilot-tasks"><legend class="screen-reader-text"><?php esc_html_e( 'What to generate', 'seo-pilot' ); ?></legend>
                        <?php foreach ( SEO_Pilot_Prompt::tasks() as $key => $label ) : ?>
                            <label><input type="checkbox" name="tasks[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, array( 'seo_title', 'meta_description', 'rewrite', 'outline', 'topics' ), true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p><label for="sp-instr"><?php esc_html_e( 'Optional note for the AI (tone, things to avoid)', 'seo-pilot' ); ?></label><br>
                        <textarea id="sp-instr" class="large-text" dir="auto" rows="2" maxlength="500" name="instruction"></textarea></p>
                    <?php submit_button( __( 'Generate recommendations', 'seo-pilot' ), 'primary', 'submit', false ); ?>
                    <span class="description"> <?php esc_html_e( 'This can take up to a minute.', 'seo-pilot' ); ?></span>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------- recommendations */

    private static function section_recs( WP_Post $post ) {
        $all     = SEO_Pilot_Recs::for_post( $post->ID );
        $open    = array(); $guide = array(); $older = array();
        foreach ( array_reverse( $all ) as $r ) {
            if ( in_array( $r['status'], array( 'pending', 'accepted', 'rejected', 'stale' ), true ) ) {
                if ( SEO_Pilot_Recs::is_applicable( $r['rec_type'] ) ) { $open[] = $r; } else { $guide[] = $r; }
            } else { $older[] = $r; }
        }
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Recommendations to review', 'seo-pilot' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Edit any proposal, then accept or reject it. Accepting does not change the page; it only queues the change for the confirmation step below.', 'seo-pilot' ); ?></p>
            <?php if ( ! $open ) : ?><p><?php esc_html_e( 'No change proposals yet. Generate recommendations above, or enter alt text.', 'seo-pilot' ); ?></p><?php endif; ?>
            <?php foreach ( $open as $r ) { self::render_rec( $r ); } ?>
            <?php if ( $guide ) : ?>
                <h3><?php esc_html_e( 'Guidance (not applied automatically)', 'seo-pilot' ); ?></h3>
                <?php foreach ( $guide as $r ) { self::render_rec( $r ); } ?>
            <?php endif; ?>
            <?php if ( $older ) : ?>
                <details><summary><?php echo esc_html( sprintf( 'Earlier recommendations (%d)', count( $older ) ) ); ?></summary>
                    <ul><?php foreach ( $older as $r ) : ?><li><?php echo esc_html( SEO_Pilot_Recs::type_label( $r['rec_type'] ) . ' — ' . $r['status'] . ' — ' . $r['updated_at'] ); ?></li><?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_rec( array $r ) {
        $type  = $r['rec_type'];
        $app   = SEO_Pilot_Recs::is_applicable( $type );
        $open  = in_array( $r['status'], array( 'pending', 'accepted', 'rejected' ), true );
        $notes = array_merge( $r['flags']['verify'], $r['flags']['notes'] );
        ?>
        <div class="seo-pilot-rec rec-<?php echo esc_attr( $r['status'] ); ?>">
            <div class="seo-pilot-rec-head">
                <strong><?php echo esc_html( SEO_Pilot_Recs::type_label( $type ) ); ?></strong>
                <?php echo self::badge( ucfirst( $r['status'] ), 'rec-' . $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php echo self::badge( 'user' === $r['source'] ? 'written or edited by you' : ( 'local' === $r['source'] ? 'local analysis, no AI' : 'AI suggestion' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
            <?php if ( '' !== $r['rationale'] ) : ?><p dir="auto"><em><?php esc_html_e( 'Why:', 'seo-pilot' ); ?></em> <?php echo esc_html( $r['rationale'] ); ?></p><?php endif; ?>
            <?php if ( $notes ) : ?>
                <div class="seo-pilot-verify"><strong><?php esc_html_e( 'Check before accepting:', 'seo-pilot' ); ?></strong>
                    <ul><?php foreach ( $notes as $n ) : ?><li dir="auto"><?php echo esc_html( $n ); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php
            if ( 'stale' === $r['status'] ) { echo '<p class="seo-pilot-error">' . esc_html__( 'The original text changed after this suggestion was made, so it can no longer be applied safely.', 'seo-pilot' ) . '</p>'; }
            if ( $app ) { self::render_rec_applicable( $r, $open ); } else { self::render_rec_advisory( $r ); }
            self::render_rec_buttons( $r, $app, $open );
            ?>
        </div>
        <?php
    }

    private static function render_rec_applicable( array $r, $open ) {
        $type = $r['rec_type'];
        if ( 'alt_text' === $type ) {
            echo '<p dir="auto">' . esc_html__( 'Add alt text to', 'seo-pilot' ) . ' <code dir="ltr">' . esc_html( $r['original'] ) . '</code>: <strong>' . esc_html( $r['proposed'] ) . '</strong></p>';
            return;
        }
        $rtl = SEO_Pilot_Text::arabic_ratio( $r['proposed'] ) > 0.5 ? 'rtl' : 'auto';
        if ( 'internal_link' === $type && ! empty( $r['flags']['url'] ) ) {
            echo '<p dir="auto">' . esc_html__( 'Links to:', 'seo-pilot' ) . ' <strong>' . esc_html( isset( $r['flags']['target_title'] ) ? $r['flags']['target_title'] : '' ) . '</strong> <code dir="ltr">' . esc_html( $r['flags']['url'] ) . '</code></p>';
        }
        echo '<div class="seo-pilot-compare" dir="' . esc_attr( $rtl ) . '">';
        echo '<div><span class="seo-pilot-label">' . esc_html__( 'Original', 'seo-pilot' ) . '</span><div class="seo-pilot-box">' . ( '' !== $r['original'] ? esc_html( $r['original'] ) : '<em>' . esc_html__( '(empty)', 'seo-pilot' ) . '</em>' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<div><span class="seo-pilot-label">' . esc_html__( 'Changes', 'seo-pilot' ) . '</span><div class="seo-pilot-box">' . self::diff( $r['original'], $r['proposed'] ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>';
        if ( $open ) {
            $rows = in_array( $type, array( 'rewrite', 'internal_link' ), true ) ? 6 : 3;
            echo '<form method="post" class="seo-pilot-recform" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="seo_pilot_rec_action"><input type="hidden" name="rec_id" value="' . esc_attr( (string) $r['id'] ) . '">';
            wp_nonce_field( 'seo_pilot_rec_' . $r['id'] );
            echo '<label class="seo-pilot-label" for="sp-rec-' . esc_attr( (string) $r['id'] ) . '">' . esc_html__( 'Proposed (editable)', 'seo-pilot' ) . '</label>';
            echo '<textarea id="sp-rec-' . esc_attr( (string) $r['id'] ) . '" class="large-text" dir="' . esc_attr( $rtl ) . '" rows="' . (int) $rows . '" name="proposed">' . esc_textarea( $r['proposed'] ) . '</textarea>';
            if ( 'seo_title' === $type || 'meta_description' === $type ) {
                echo '<p class="description">' . esc_html( sprintf( '%d characters (guideline: %s).', SEO_Pilot_Text::len( $r['proposed'] ), 'seo_title' === $type ? 'about 60 or fewer' : 'about 160 or fewer' ) ) . '</p>';
            } else {
                echo '<p class="description">' . esc_html__( 'Only simple inline formatting and the original links are kept. Check that the meaning, facts and tone are unchanged.', 'seo-pilot' ) . '</p>';
            }
            self::rec_buttons_inner( $r, true );
            echo '</form>';
            return;
        }
    }

    private static function render_rec_advisory( array $r ) {
        $d = json_decode( $r['proposed'], true );
        if ( ! is_array( $d ) ) { return; }
        echo '<div class="seo-pilot-advice" dir="auto">';
        switch ( $r['rec_type'] ) {
            case 'outline':
                echo '<ul class="seo-pilot-outline">';
                foreach ( $d['items'] as $it ) {
                    echo '<li><strong>H2: ' . esc_html( $it['h2'] ) . '</strong>' . ( $it['new'] ? ' ' . self::badge( 'new section', 'muted' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
                    if ( '' !== $it['note'] ) { echo ' — ' . esc_html( $it['note'] ); }
                    if ( $it['h3'] ) { echo '<ul>'; foreach ( $it['h3'] as $h3 ) { echo '<li>H3: ' . esc_html( $h3 ) . '</li>'; } echo '</ul>'; }
                    echo '</li>';
                }
                echo '</ul>';
                break;
            case 'topics':
                if ( ! empty( $d['missing_topics'] ) ) { echo '<h4>' . esc_html__( 'Topics that may be missing', 'seo-pilot' ) . '</h4><ul>'; foreach ( $d['missing_topics'] as $t ) { echo '<li><strong>' . esc_html( $t['topic'] ) . '</strong>' . ( $t['why'] ? ' — ' . esc_html( $t['why'] ) : '' ) . '</li>'; } echo '</ul>'; }
                if ( ! empty( $d['audience_questions'] ) ) { echo '<h4>' . esc_html__( 'Questions the audience may ask', 'seo-pilot' ) . '</h4><ul>'; foreach ( $d['audience_questions'] as $t ) { echo '<li><strong>' . esc_html( $t['question'] ) . '</strong>' . ( $t['why'] ? ' — ' . esc_html( $t['why'] ) : '' ) . '</li>'; } echo '</ul>'; }
                break;
            case 'faq':
                echo '<ul>';
                foreach ( $d['items'] as $it ) { echo '<li><strong>' . esc_html( $it['q'] ) . '</strong><br>' . esc_html( $it['a'] ) . '</li>'; }
                echo '</ul>';
                break;
            case 'inbound_links':
                echo '<ul>';
                foreach ( $d['pages'] as $it ) { echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $it['id'] ) ) . '">' . esc_html( $it['title'] ) . '</a> — ' . esc_html__( 'mentions', 'seo-pilot' ) . ' "' . esc_html( $it['phrase'] ) . '"</li>'; }
                echo '</ul>';
                break;
            case 'site_gap':
                echo '<p>' . esc_html__( 'Terms used by related pages on your site and not found here (a page-count shows how many related pages use each):', 'seo-pilot' ) . '</p><p>';
                $parts = array();
                foreach ( $d['terms'] as $it ) { $parts[] = esc_html( $it['term'] ) . ' (' . (int) $it['pages'] . ')'; }
                echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- each part escaped above.
                echo '</p><p class="description">' . esc_html__( 'Compared with:', 'seo-pilot' ) . ' ';
                $rel = array();
                foreach ( $d['related'] as $it ) { $rel[] = esc_html( $it['title'] ) . ' (' . round( $it['score'] * 100 ) . '% similar vocabulary)'; }
                echo implode( ' · ', $rel ); // phpcs:ignore WordPress.Security.EscapeOutput -- each part escaped above.
                echo '. ' . esc_html__( 'Words are compared in their exact written form, so related word forms may be listed as missing. Add a term only if it genuinely belongs on this page.', 'seo-pilot' ) . '</p>';
                break;
            case 'refresh':
                echo '<ul>';
                foreach ( $d['items'] as $it ) { echo '<li>' . esc_html( $it['what'] ) . ( $it['verify'] ? ' <em>(' . esc_html__( 'verify:', 'seo-pilot' ) . ' ' . esc_html( $it['verify'] ) . ')</em>' : '' ) . '</li>'; }
                echo '</ul>';
                break;
        }
        echo '</div>';
    }

    private static function rec_buttons_inner( array $r, $applicable ) {
        echo '<p class="seo-pilot-buttons">';
        if ( $applicable ) {
            echo '<button type="submit" name="op" value="save" class="button">' . esc_html__( 'Save edit', 'seo-pilot' ) . '</button> ';
            echo '<button type="submit" name="op" value="accept" class="button button-primary">' . esc_html__( 'Accept', 'seo-pilot' ) . '</button> ';
        }
        echo '</p>';
    }

    private static function render_rec_buttons( array $r, $applicable, $open ) {
        $id = (int) $r['id'];
        // Accept/Save live inside the edit form above; the remaining buttons are small forms of their own.
        echo '<div class="seo-pilot-buttons">';
        $small = function ( $op, $label, $class = 'button' ) use ( $id ) {
            echo '<form method="post" class="seo-pilot-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="seo_pilot_rec_action"><input type="hidden" name="rec_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
            wp_nonce_field( 'seo_pilot_rec_' . $id );
            echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
        };
        if ( ! $applicable && $open && 'accepted' !== $r['status'] ) { $small( 'accept', __( 'Mark as noted', 'seo-pilot' ), 'button button-primary' ); }
        if ( $open && 'rejected' !== $r['status'] ) { $small( 'reject', $applicable ? __( 'Reject', 'seo-pilot' ) : __( 'Dismiss', 'seo-pilot' ) ); }
        if ( $open && 'pending' !== $r['status'] ) { $small( 'reopen', __( 'Move back to pending', 'seo-pilot' ) ); }
        if ( 'ai' === $r['source'] && ( $open || 'stale' === $r['status'] ) && SEO_Pilot_Settings::configuration_problem() === '' ) {
            $small( 'regenerate', __( 'Regenerate', 'seo-pilot' ) );
        }
        if ( 'stale' === $r['status'] ) { $small( 'reject', __( 'Reject', 'seo-pilot' ) ); }
        echo '</div>';
    }

    /* ----------------------------------------------------------------- apply */

    private static function section_apply( WP_Post $post ) {
        $plan = SEO_Pilot_Approval::plan( $post->ID );
        ?>
        <div class="seo-pilot-panel seo-pilot-apply">
            <h2><?php esc_html_e( 'Apply accepted changes', 'seo-pilot' ); ?></h2>
            <?php if ( ! $plan['changes'] && ! $plan['blocked'] ) : ?>
                <p><?php esc_html_e( 'Nothing is waiting. Accept at least one change proposal above to review it here.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'This is exactly what will change. Review it, then confirm.', 'seo-pilot' ); ?></p>
                <?php foreach ( $plan['changes'] as $c ) : ?>
                    <div class="seo-pilot-planitem">
                        <h4><?php echo esc_html( $c['label'] . ' — ' . $c['owner_label'] ); ?></h4>
                        <?php if ( 'post_content' === $c['field'] ) :
                            foreach ( $c['rec_ids'] as $rid ) : $r = SEO_Pilot_Recs::get( $rid ); if ( ! $r ) { continue; } ?>
                                <?php if ( 'alt_text' === $r['rec_type'] ) : ?>
                                    <div class="seo-pilot-box" dir="auto"><?php echo esc_html( 'Add alt="' . $r['proposed'] . '" to ' . $r['original'] ); ?></div>
                                <?php else : ?>
                                    <div class="seo-pilot-box" dir="auto"><?php echo self::diff( $r['original'], SEO_Pilot_Validator::clean_inline( $r['proposed'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="seo-pilot-box" dir="auto"><?php echo self::diff( $c['old'], $c['new'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                            <?php if ( $c['overwrites'] ) : ?><p class="seo-pilot-error"><?php echo esc_html( 'This replaces a value that already exists in ' . $c['owner_label'] . '.' ); ?></p><?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ( $plan['blocked'] as $b ) : ?><p class="seo-pilot-error"><?php echo esc_html( $b['message'] ); ?></p><?php endforeach; ?>
                <?php if ( $plan['changes'] ) : ?>
                    <?php self::form_open( 'apply', $post->ID, 'apply' ); ?>
                        <?php if ( $plan['live'] ) : ?>
                            <p><strong><?php esc_html_e( 'This post is live or scheduled.', 'seo-pilot' ); ?></strong></p>
                            <p><label><input type="radio" name="mode" value="live" checked> <?php esc_html_e( 'Update the live post now', 'seo-pilot' ); ?></label><br>
                               <label><input type="radio" name="mode" value="draft_copy"> <?php esc_html_e( 'Save as a new draft copy — the live post is not changed', 'seo-pilot' ); ?></label></p>
                            <p><label><input type="checkbox" name="confirm_live" value="1"> <?php esc_html_e( 'I have reviewed the changes above and I confirm that the live page should be updated.', 'seo-pilot' ); ?></label></p>
                        <?php else : ?>
                            <input type="hidden" name="mode" value="live">
                            <p class="description"><?php esc_html_e( 'This post is not published, so the changes apply to the draft only.', 'seo-pilot' ); ?></p>
                        <?php endif; ?>
                        <?php if ( $plan['requires_owner_confirm'] ) : ?>
                            <p><label><input type="checkbox" name="confirm_owner" value="1"> <?php echo esc_html( sprintf( 'I allow SEO Pilot to write to the SEO fields owned by %s for this post (not needed for a draft copy).', SEO_Pilot_Compat::label( SEO_Pilot_Compat::owner() ) ) ); ?></label></p>
                        <?php endif; ?>
                        <?php if ( ! $plan['revisions'] ) : ?><p class="description"><?php esc_html_e( 'WordPress revisions are off for this post, so rollback relies on SEO Pilot\'s own stored copy of the previous values.', 'seo-pilot' ); ?></p>
                        <?php else : ?><p class="description"><?php esc_html_e( 'A WordPress revision of the current content is saved first, and the before/after values are recorded in the history.', 'seo-pilot' ); ?></p><?php endif; ?>
                        <?php submit_button( __( 'Apply accepted changes', 'seo-pilot' ), 'primary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------- live check */

    private static function section_live( WP_Post $post ) {
        $live = get_transient( 'seo_pilot_live_' . $post->ID );
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'Check live output', 'seo-pilot' ); ?></h2>
            <?php if ( 'publish' !== $post->post_status ) : ?>
                <p class="description"><?php esc_html_e( 'Available for published pages. It fetches the page from this site and reports its title tag, meta description, canonical, robots tag and H1 count; it changes nothing.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Fetches the published page from this server (read-only) so you can confirm what search engines receive, and spot duplicate tags.', 'seo-pilot' ); ?></p>
                <?php self::form_open( 'live_check', $post->ID, 'live' ); submit_button( __( 'Check live output', 'seo-pilot' ), 'secondary', 'submit', false ); echo '</form>'; ?>
                <?php if ( is_array( $live ) ) :
                    $md = SEO_Pilot_Compat::get( $post->ID, 'meta_description' );
                    $st = SEO_Pilot_Compat::get( $post->ID, 'seo_title' );
                    $notes = SEO_Pilot_Live_Check::notes( $live, $md['template_vars'] ? '' : $md['value'], $st['template_vars'] ? '' : $st['value'] );
                    ?>
                    <p class="description"><?php echo esc_html( 'Fetched ' . $live['at'] . '.' ); ?></p>
                    <ul class="seo-pilot-livenotes"><?php foreach ( $notes as $n ) : ?><li class="live-<?php echo esc_attr( $n[0] ); ?>"><span dir="auto"><?php echo esc_html( $n[1] ); ?></span></li><?php endforeach; ?></ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------- history */

    private static function section_history( WP_Post $post ) {
        $rows   = SEO_Pilot_History::query( $post->ID, 12 );
        $groups = SEO_Pilot_History::open_groups( $post->ID, 5 );
        $live   = SEO_Pilot_Util::is_live_status( $post->post_status );
        ?>
        <div class="seo-pilot-panel">
            <h2><?php esc_html_e( 'History and rollback', 'seo-pilot' ); ?></h2>
            <?php if ( ! $rows ) : ?><p><?php esc_html_e( 'No changes have been made to this page by SEO Pilot.', 'seo-pilot' ); ?></p><?php else : ?>
                <?php foreach ( $groups as $gid => $grows ) :
                    $u = $grows[0]['user_id'] ? get_userdata( $grows[0]['user_id'] ) : false; ?>
                    <div class="seo-pilot-rollback">
                        <p><strong><?php echo esc_html( $grows[0]['created_at'] ); ?></strong> — <?php echo esc_html( $u ? $u->display_name : __( 'unknown user', 'seo-pilot' ) ); ?>:
                            <?php echo esc_html( implode( ', ', array_unique( array_map( function ( $r ) { return $r['field']; }, $grows ) ) ) ); ?></p>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="seo_pilot_rollback_group"><input type="hidden" name="group_id" value="<?php echo esc_attr( $gid ); ?>">
                            <?php wp_nonce_field( 'seo_pilot_rollback_' . $gid ); ?>
                            <?php if ( $live ) : ?><label><input type="checkbox" name="confirm_live" value="1"> <?php esc_html_e( 'I confirm the live page should be rolled back.', 'seo-pilot' ); ?></label><br><?php endif; ?>
                            <label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Restore even if the field was edited after SEO Pilot changed it (discards those newer edits).', 'seo-pilot' ); ?></label><br>
                            <?php submit_button( __( 'Roll back this change', 'seo-pilot' ), 'delete', 'submit', false ); ?>
                        </form>
                    </div>
                <?php endforeach; ?>
                <table class="widefat striped seo-pilot-history">
                    <thead><tr><th><?php esc_html_e( 'When', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Who', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Page', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Action', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Field', 'seo-pilot' ); ?></th><th><?php esc_html_e( 'Before → after', 'seo-pilot' ); ?></th></tr></thead>
                    <tbody><?php foreach ( $rows as $r ) { echo SEO_Pilot_Admin::history_row( $r ); } // phpcs:ignore WordPress.Security.EscapeOutput ?></tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
