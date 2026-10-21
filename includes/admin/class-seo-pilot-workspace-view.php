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
            self::section_language( $post, $audit );
            self::section_fixes( $post, $audit );
            self::section_assessment( $audit );
            self::section_title( $audit );
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
        $m = array( 'meta' => 'Titles, descriptions and indexing', 'structure' => 'Structure and headings', 'content' => 'Content', 'readability' => 'Readability', 'links' => 'Links', 'images' => 'Images', 'keywords' => 'Keywords', 'intent' => 'Search intent alignment', 'verification' => 'Facts to verify', 'language' => 'Language and translations' );
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

    /** Before → after for one proposed change, as escaped HTML. */
    private static function fix_change_html( array $r ) {
        if ( 'alt_text' === $r['rec_type'] ) {
            $deco = ! empty( $r['flags']['decorative'] );
            $img  = '<code dir="ltr">' . esc_html( SEO_Pilot_Images::label( $r['original'] ) ) . '</code> ';
            if ( $deco ) { return $img . esc_html__( 'mark as decorative (empty alt text)', 'seo-pilot' ); }
            if ( isset( $r['flags']['mode'] ) && 'replace' === $r['flags']['mode'] ) {
                $old = isset( $r['flags']['old_alt'] ) ? (string) $r['flags']['old_alt'] : '';
                return $img . '<del class="seo-pilot-del">' . esc_html( '' === $old ? __( '(empty)', 'seo-pilot' ) : $old ) . '</del> → <ins class="seo-pilot-ins">' . esc_html( $r['proposed'] ) . '</ins>';
            }
            return $img . esc_html__( 'add alt text:', 'seo-pilot' ) . ' <ins class="seo-pilot-ins">' . esc_html( $r['proposed'] ) . '</ins>';
        }
        return '' === trim( $r['original'] ) ? '<em>' . esc_html__( '(empty)', 'seo-pilot' ) . '</em> → <ins class="seo-pilot-ins">' . esc_html( $r['proposed'] ) . '</ins>' : self::diff( $r['original'], $r['proposed'] );
    }

    private static function verdict_badge( $verdict ) {
        $m = array( 'verified' => array( 'Verified', 'check-pass' ), 'partial' => array( 'Partly improved', 'check-warn' ), 'not_resolved' => array( 'Not resolved', 'check-warn' ), 'regressed' => array( 'Check this', 'check-fail' ), 'nothing' => array( 'Nothing to compare', 'muted' ) );
        $v = isset( $m[ $verdict ] ) ? $m[ $verdict ] : $m['nothing'];
        return self::badge( $v[0], $v[1] );
    }

    /**
     * The Fix assistant (Assisted mode): 1. choose fixes, 2. review the proposed changes, 3. apply the ticked ones and read the
     * before/after check. Every step is a separate button; the AI is called only by "Prepare", and the page only changes on "Apply".
     */
    private static function section_fixes( WP_Post $post, array $audit ) {
        $ctx    = SEO_Pilot_Workspace::fix_context( $post, $audit );
        $fixes  = SEO_Pilot_Fixes::for_audit( $audit, $ctx );
        $last   = get_transient( 'seo_pilot_fixrun_' . $post->ID );
        $props  = array();
        foreach ( SEO_Pilot_Recs::for_post( $post->ID, array( 'pending', 'accepted' ) ) as $r ) {
            if ( in_array( $r['rec_type'], array( 'seo_title', 'meta_description', 'alt_text' ), true ) ) { $props[] = $r; }
        }
        $live   = SEO_Pilot_Util::is_live_status( $post->post_status );
        $third  = SEO_Pilot_Compat::is_third_party( SEO_Pilot_Compat::owner() );
        $areas  = array();
        foreach ( $fixes as $fx ) { $areas[ $fx['area_label'] ][] = $fx; }
        ?>
        <div class="seo-pilot-panel seo-pilot-fixes">
            <h2><?php esc_html_e( 'Fix assistant', 'seo-pilot' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Finds problems this plugin can help fix, prepares proposed changes, and applies the ones you tick. Then it re-runs the audit and tells you whether the problems are gone. Nothing changes on the page until you press "Apply selected changes", and every change can be rolled back.', 'seo-pilot' ); ?></p>

            <?php if ( is_array( $last ) ) : $v = $last['verify']; $open = SEO_Pilot_History::open_groups( $post->ID, 10 ); ?>
                <h3><?php esc_html_e( 'Result of the last run', 'seo-pilot' ); ?> <?php echo self::verdict_badge( $v['verdict'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h3>
                <p><?php echo esc_html( SEO_Pilot_Verify::message( $v ) ); ?>
                    <span class="description"><?php echo esc_html( sprintf( ' Applied %s at %s.', implode( ', ', array_map( 'strval', $last['fields'] ) ), $last['at'] ) ); ?></span></p>
                <p class="description"><?php echo esc_html( sprintf( 'Findings by severity, before → after: high %d → %d, medium %d → %d, low %d → %d.',
                    $v['severity_before']['high'], $v['severity_after']['high'], $v['severity_before']['medium'], $v['severity_after']['medium'], $v['severity_before']['low'], $v['severity_after']['low'] ) ); ?></p>
                <ul class="seo-pilot-livenotes">
                    <?php foreach ( $v['resolved'] as $f ) : ?><li class="live-ok"><?php echo esc_html( 'Solved: ' . $f['what'] ); ?></li><?php endforeach; ?>
                    <?php foreach ( $v['improved'] as $f ) : ?><li class="live-ok"><?php echo esc_html( sprintf( 'Smaller: %s (%d → %d)', $f['what'], $f['from'], $f['to'] ) ); ?></li><?php endforeach; ?>
                    <?php foreach ( $v['remaining'] as $f ) : ?><li class="live-warn"><?php echo esc_html( 'Still reported: ' . $f['what'] ); ?></li><?php endforeach; ?>
                    <?php foreach ( array_merge( $v['new'], $v['worsened'] ) as $f ) : ?><li class="live-warn"><?php echo esc_html( 'New or larger (' . $f['severity'] . '): ' . $f['what'] ); ?></li><?php endforeach; ?>
                </ul>
                <?php if ( isset( $open[ $last['group_id'] ] ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="seo-pilot-rollback">
                        <input type="hidden" name="action" value="seo_pilot_rollback_group"><input type="hidden" name="group_id" value="<?php echo esc_attr( $last['group_id'] ); ?>">
                        <?php wp_nonce_field( 'seo_pilot_rollback_' . $last['group_id'] ); ?>
                        <?php if ( $live ) : ?><label><input type="checkbox" name="confirm_live" value="1"> <?php esc_html_e( 'I confirm the live page should be rolled back.', 'seo-pilot' ); ?></label><br><?php endif; ?>
                        <?php submit_button( __( 'Roll back this run', 'seo-pilot' ), 'regressed' === $v['verdict'] ? 'primary' : 'delete', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
                <hr>
            <?php endif; ?>

            <h3><?php esc_html_e( '1. Fixes available for this page', 'seo-pilot' ); ?></h3>
            <?php if ( ! $fixes ) : ?>
                <p><?php esc_html_e( 'None of this page\'s current findings has a fix the assistant can prepare. Findings that need a person (for example content or language decisions) are listed in the checklist below.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <?php self::form_open( 'fix_prepare', $post->ID, 'fixprep' ); ?>
                    <?php foreach ( $areas as $label => $list ) : ?>
                        <fieldset class="seo-pilot-fixgroup"><legend><strong><?php echo esc_html( $label ); ?></strong></legend>
                            <?php foreach ( $list as $fx ) : ?>
                                <p>
                                    <label><input type="checkbox" name="fix[]" value="<?php echo esc_attr( $fx['id'] ); ?>" <?php checked( $fx['checked'] ); disabled( ! $fx['available'] ); ?>> <?php echo esc_html( $fx['label'] ); ?></label>
                                    <?php echo self::badge( 'Class ' . $fx['risk'], 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    <br><span class="description" dir="auto"><?php echo esc_html( 'Because: ' . implode( ' · ', $fx['reasons'] ) ); ?></span>
                                    <br><span class="description"><?php echo esc_html( $fx['available'] ? $fx['risk_text'] : 'Not available: ' . $fx['unavailable'] ); ?></span>
                                </p>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e( 'Ticked by default: fixes that use text you already have or fill an empty field. Fixes that replace text a person wrote are left unticked. AI drafts are requested only when you press the button below and use the AI settings you confirmed.', 'seo-pilot' ); ?></p>
                    <?php submit_button( __( 'Prepare selected fixes', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
                </form>
            <?php endif; ?>

            <h3><?php esc_html_e( '2. Proposed changes', 'seo-pilot' ); ?></h3>
            <?php if ( ! $props ) : ?>
                <p class="description"><?php esc_html_e( 'Nothing is waiting. Prepare fixes above, and the proposals appear here.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <?php self::form_open( 'fix_apply', $post->ID, 'fixapply' ); ?>
                    <table class="widefat striped seo-pilot-fixtable">
                        <thead><tr><th scope="col"><?php esc_html_e( 'Apply', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Change', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Check before applying', 'seo-pilot' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $props as $r ) : $notes = array_merge( $r['flags']['verify'], $r['flags']['notes'] ); ?>
                            <tr>
                                <td><input type="checkbox" name="rec[]" value="<?php echo esc_attr( (string) $r['id'] ); ?>" id="sp-fx-<?php echo esc_attr( (string) $r['id'] ); ?>" <?php checked( SEO_Pilot_Fixes::rec_checked( $r ) ); ?>>
                                    <label class="screen-reader-text" for="sp-fx-<?php echo esc_attr( (string) $r['id'] ); ?>"><?php echo esc_html( 'Apply: ' . SEO_Pilot_Recs::type_label( $r['rec_type'] ) ); ?></label></td>
                                <td dir="auto"><strong><?php echo esc_html( SEO_Pilot_Recs::type_label( $r['rec_type'] ) ); ?></strong>
                                    <?php echo self::badge( 'user' === $r['source'] ? 'written by you' : ( 'local' === $r['source'] ? 'from your Media Library, no AI' : 'AI draft' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    <br><?php echo self::fix_change_html( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></td>
                                <td dir="auto"><?php if ( $notes ) : ?><ul class="seo-pilot-evidence"><?php foreach ( $notes as $n ) : ?><li><?php echo esc_html( $n ); ?></li><?php endforeach; ?></ul><?php else : ?><span class="description">—</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e( 'To edit a proposal or reject it, use "Recommendations to review" further down; the same proposals appear there.', 'seo-pilot' ); ?></p>
                    <h3><?php esc_html_e( '3. Apply and verify', 'seo-pilot' ); ?></h3>
                    <p><label><input type="radio" name="mode" value="live" checked> <?php esc_html_e( 'Update this page now', 'seo-pilot' ); ?></label><br>
                       <label><input type="radio" name="mode" value="draft_copy"> <?php esc_html_e( 'Save as a new draft copy instead (the page is not changed, so there is nothing to verify)', 'seo-pilot' ); ?></label></p>
                    <?php if ( $live ) : ?><p><label><input type="checkbox" name="confirm_live" value="1"> <?php esc_html_e( 'This page is live. I have reviewed the changes above and I confirm it should be updated.', 'seo-pilot' ); ?></label></p><?php endif; ?>
                    <?php if ( $third ) : ?><p><label><input type="checkbox" name="confirm_owner" value="1"> <?php echo esc_html( sprintf( '%s controls the SEO title and description. I allow SEO Pilot to write to its fields.', SEO_Pilot_Compat::label( SEO_Pilot_Compat::owner() ) ) ); ?></label></p><?php endif; ?>
                    <?php submit_button( __( 'Apply selected changes', 'seo-pilot' ), 'primary', 'submit', false ); ?>
                    <span class="description"> <?php esc_html_e( 'A WordPress revision is saved first, the change is logged in History, and the audit is re-run to check the result.', 'seo-pilot' ); ?></span>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function lang_source_text( array $li ) {
        switch ( $li['source'] ) {
            case 'polylang': return __( 'set in Polylang', 'seo-pilot' );
            case 'wpml':     return __( 'set in WPML', 'seo-pilot' );
            case 'filter':   return __( 'provided by custom code', 'seo-pilot' );
            case 'manual':   return __( 'set by you in SEO Pilot', 'seo-pilot' );
            default:         return __( 'not set for this page: the site language is assumed', 'seo-pilot' );
        }
    }

    private static function section_language( WP_Post $post, array $audit ) {
        $li      = SEO_Pilot_Lang::for_post( $post->ID );
        $plugins = $li['plugins'];
        $manual  = '' === $li['managed_by'];
        $langs   = SEO_Pilot_Lang::site_languages();
        // Nothing to show on a single-language site that has not set anything up.
        if ( ! $langs && ! $plugins && ! $li['declared'] ) {
            ?>
            <div class="seo-pilot-panel seo-pilot-language">
                <h2><?php esc_html_e( 'Language and translations', 'seo-pilot' ); ?></h2>
                <p class="description"><?php esc_html_e( 'This site has no languages set up. If you publish pages in more than one language, add them under SEO Pilot → Settings → Languages; this panel then lets you set each page\'s language and link its translations.', 'seo-pilot' ); ?></p>
            </div>
            <?php
            return;
        }
        $used = array( SEO_Pilot_Hreflang::lang_part( $li['code'] ) => true );
        foreach ( $li['members'] as $mc => $m ) { $used[ SEO_Pilot_Hreflang::lang_part( $mc ) ] = true; }
        $candidates = array();
        if ( $manual ) {
            $exclude = array_merge( array( $post->ID ), SEO_Pilot_Lang::translation_ids( $li ) );
            foreach ( get_posts( array( 'post_type' => SEO_Pilot_Util::content_post_types(), 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC', 'post__not_in' => $exclude, 'no_found_rows' => true ) ) as $cp ) {
                $own = SEO_Pilot_Hreflang::normalize_code( (string) get_post_meta( $cp->ID, SEO_Pilot_Lang::META_LANG, true ) );
                $candidates[] = array( 'id' => $cp->ID, 'title' => self::title_of( $cp ), 'lang' => $own );
            }
        }
        ?>
        <div class="seo-pilot-panel seo-pilot-language">
            <h2><?php esc_html_e( 'Language and translations', 'seo-pilot' ); ?></h2>
            <p><strong><?php echo esc_html( sprintf( '%s (%s)', $li['label'], $li['code'] ) ); ?></strong>
                <span class="description"> — <?php echo esc_html( self::lang_source_text( $li ) . '; ' . ( 'rtl' === $li['dir'] ? 'right to left' : 'left to right' ) ); ?></span></p>
            <?php if ( $plugins ) : ?>
                <p class="description"><?php echo esc_html( sprintf( '%s is active. Languages and translations are managed there; SEO Pilot reads them and checks the result. Use "Check live output" below to verify the hreflang tags the plugin prints.', implode( ', ', array_map( array( 'SEO_Pilot_Lang', 'plugin_label' ), $plugins ) ) ) ); ?></p>
                <?php if ( ! $li['declared'] ) : ?>
                    <p class="description"><?php esc_html_e( 'This plugin translates pages on the fly instead of keeping separate pages, so SEO Pilot only sees the original-language page here. Edit the translated titles and descriptions in the plugin\'s own translation editor; "Check live output" can fetch the page in each language.', 'seo-pilot' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( $li['members'] ) : ?>
                <table class="widefat striped seo-pilot-trans">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Language', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Page', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'seo-pilot' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'SEO title', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Keyword', 'seo-pilot' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $li['members'] as $mc => $m ) : ?>
                        <tr>
                            <td><?php echo esc_html( SEO_Pilot_Lang::label( $mc ) . ' (' . $mc . ')' ); ?></td>
                            <td dir="auto"><a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-content&post_id=' . (int) $m['id'] ) ); ?>"><?php echo esc_html( $m['title'] ); ?></a></td>
                            <td><?php echo esc_html( self::status_label( $m['status'] ) ); ?></td>
                            <td dir="auto"><?php echo '' !== $m['seo_title'] ? esc_html( $m['seo_title'] ) : '<span class="description">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td dir="auto"><?php echo '' !== $m['keyword'] ? esc_html( $m['keyword'] ) : '<span class="description">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $manual ) : ?>
                <h3><?php esc_html_e( 'This page\'s language', 'seo-pilot' ); ?></h3>
                <?php self::form_open( 'save_lang', $post->ID, 'lang', 'seo-pilot-inline' ); ?>
                    <label class="screen-reader-text" for="sp-lang"><?php esc_html_e( 'Language of this page', 'seo-pilot' ); ?></label>
                    <?php if ( $langs ) : ?>
                        <select id="sp-lang" name="lang">
                            <option value=""><?php esc_html_e( '— not set —', 'seo-pilot' ); ?></option>
                            <?php $cur = 'manual' === $li['source'] ? $li['code'] : ''; $listed = false; foreach ( $langs as $l ) : $listed = $listed || $l['code'] === $cur; ?>
                                <option value="<?php echo esc_attr( $l['code'] ); ?>" <?php selected( $cur, $l['code'] ); ?>><?php echo esc_html( SEO_Pilot_Lang::label( $l['code'] ) . ' (' . $l['code'] . ')' ); ?></option>
                            <?php endforeach; ?>
                            <?php if ( '' !== $cur && ! $listed ) : ?><option value="<?php echo esc_attr( $cur ); ?>" selected><?php echo esc_html( $cur ); ?></option><?php endif; ?>
                        </select>
                    <?php else : ?>
                        <input id="sp-lang" type="text" name="lang" dir="ltr" class="small-text" maxlength="12" value="<?php echo esc_attr( 'manual' === $li['source'] ? $li['code'] : '' ); ?>" placeholder="ar">
                    <?php endif; ?>
                    <?php submit_button( __( 'Save language', 'seo-pilot' ), 'secondary small', 'submit', false ); ?>
                </form>

                <?php if ( 'manual' === $li['source'] ) : ?>
                    <h3><?php esc_html_e( 'Translations', 'seo-pilot' ); ?></h3>
                    <?php
                    $any = false;
                    foreach ( $langs as $l ) :
                        if ( isset( $used[ SEO_Pilot_Hreflang::lang_part( $l['code'] ) ] ) ) { continue; }
                        $any = true;
                        $fid = 'sp-link-' . preg_replace( '/[^a-z0-9]/i', '', $l['code'] );
                        self::form_open( 'link_trans', $post->ID, 'linktrans', 'seo-pilot-inline' ); ?>
                            <input type="hidden" name="lang" value="<?php echo esc_attr( $l['code'] ); ?>">
                            <label for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( 'The ' . SEO_Pilot_Lang::label( $l['code'] ) . ' version of this page:' ); ?></label>
                            <select id="<?php echo esc_attr( $fid ); ?>" name="other_id">
                                <?php foreach ( $candidates as $c ) : if ( '' !== $c['lang'] && SEO_Pilot_Hreflang::lang_part( $c['lang'] ) !== SEO_Pilot_Hreflang::lang_part( $l['code'] ) ) { continue; } ?>
                                    <option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php submit_button( __( 'Link', 'seo-pilot' ), 'secondary small', 'submit', false ); ?>
                        </form>
                    <?php endforeach; ?>
                    <?php if ( ! $any && ! $langs ) : ?><p class="description"><?php esc_html_e( 'Add your languages under Settings → Languages to link translations.', 'seo-pilot' ); ?></p><?php endif; ?>
                    <?php if ( $li['members'] ) : ?>
                        <?php self::form_open( 'unlink_trans', $post->ID, 'unlinktrans', 'seo-pilot-inline' ); ?>
                            <?php submit_button( __( 'Remove this page from its translation group', 'seo-pilot' ), 'link-delete small', 'submit', false ); ?>
                        </form>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e( 'Linking does not change either page. Choosing a page that has no language yet gives it the language shown. A page can only be linked to one page per language.', 'seo-pilot' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            $tags = array();
            if ( $manual && ! SEO_Pilot_Lang::owns_hreflang() ) {
                $tags = SEO_Pilot_Hreflang::tags( SEO_Pilot_Lang::indexable_members( $li ), SEO_Pilot_Lang::default_language() );
            }
            ?>
            <h3><?php esc_html_e( 'hreflang', 'seo-pilot' ); ?></h3>
            <?php if ( SEO_Pilot_Lang::owns_hreflang() ) : ?>
                <p class="description"><?php esc_html_e( 'Printed by your translation plugin. SEO Pilot does not print hreflang while such a plugin is active.', 'seo-pilot' ); ?></p>
            <?php elseif ( ! SEO_Pilot_Settings::get( 'hreflang_output', 0 ) ) : ?>
                <p class="description"><?php echo esc_html( $tags ? 'SEO Pilot would print the tags below for this page, but hreflang output is switched off in Settings → Languages.' : 'hreflang output is switched off (Settings → Languages), and this page has no published, indexable translation to list.' ); ?></p>
            <?php else : ?>
                <p class="description"><?php echo esc_html( $tags ? 'SEO Pilot prints these tags on this page:' : 'Nothing is printed for this page: it needs at least one published, indexable translation.' ); ?></p>
            <?php endif; ?>
            <?php if ( $tags ) : ?><pre class="seo-pilot-preview" dir="ltr"><?php echo esc_html( SEO_Pilot_Hreflang::render( $tags ) ); ?></pre><?php endif; ?>
        </div>
        <?php
    }

    private static function basis_label( $basis ) {
        $m = array(
            'measured'  => __( 'Measured by the plugin', 'seo-pilot' ),
            'estimate'  => __( 'Estimate', 'seo-pilot' ),
            'heuristic' => __( 'Rule of thumb', 'seo-pilot' ),
        );
        return isset( $m[ $basis ] ) ? $m[ $basis ] : $basis;
    }

    private static function section_title( array $audit ) {
        $ta = isset( $audit['title_analysis'] ) && is_array( $audit['title_analysis'] ) ? $audit['title_analysis'] : null;
        ?>
        <div class="seo-pilot-panel seo-pilot-title-analysis">
            <h2><?php esc_html_e( 'Title analysis', 'seo-pilot' ); ?></h2>
            <?php if ( ! $ta ) : ?>
                <p><?php esc_html_e( 'This audit was created before title analysis existed. Use "Refresh audit" above to see it.', 'seo-pilot' ); ?></p>
            <?php elseif ( empty( $ta['analyzed'] ) && empty( $ta['checks'] ) ) : ?>
                <p><?php esc_html_e( 'There is no title to analyse yet.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Each line says how it was produced. "Measured" means counted or compared by code. "Estimate" is an approximation. "Rule of thumb" is a prompt to look, not a requirement: no tool knows how Google will treat your title.', 'seo-pilot' ); ?></p>
                <p dir="auto"><strong><?php echo esc_html( $ta['title'] ); ?></strong>
                    <span class="description"> — <?php echo esc_html( 'seo_title' === $ta['source'] ? __( 'the SEO title', 'seo-pilot' ) : __( 'the page title (no separate SEO title is set)', 'seo-pilot' ) ); ?></span></p>
                <?php if ( ! empty( $ta['metrics'] ) ) : ?>
                    <p class="description"><?php echo esc_html( sprintf( '%d characters; estimated width about %d–%d px (a typical desktop result shows about %d px).', $ta['metrics']['chars'], $ta['metrics']['px_low'], $ta['metrics']['px_high'], $ta['metrics']['px_limit'] ) ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $ta['opportunities'] ) ) : ?>
                    <h3><?php esc_html_e( 'Worth a look', 'seo-pilot' ); ?></h3>
                    <ul class="seo-pilot-opportunities">
                        <?php foreach ( $ta['opportunities'] as $o ) : ?>
                            <li dir="auto"><?php echo self::badge( self::basis_label( $o['basis'] ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $o['text'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ( ! empty( $ta['analyzed'] ) ) : ?>
                    <p><?php esc_html_e( 'None of the checks below flagged anything.', 'seo-pilot' ); ?></p>
                <?php endif; ?>
                <details><summary><?php esc_html_e( 'Show every title check', 'seo-pilot' ); ?></summary>
                    <table class="widefat striped">
                        <thead><tr><th scope="col"><?php esc_html_e( 'Check', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'How it was produced', 'seo-pilot' ); ?></th><th scope="col"><?php esc_html_e( 'Detail', 'seo-pilot' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $ta['checks'] as $c ) : ?>
                            <tr>
                                <td dir="auto"><?php echo esc_html( $c['label'] ); ?></td>
                                <td><?php echo self::badge( self::status_text( $c['status'] ), 'check-' . $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><?php echo esc_html( self::basis_label( $c['basis'] ) ); ?></td>
                                <td dir="auto"><?php echo esc_html( $c['detail'] ); ?>
                                    <?php if ( ! empty( $c['evidence'] ) ) : ?><ul class="seo-pilot-evidence"><?php foreach ( $c['evidence'] as $ev ) : ?><li><?php echo esc_html( $ev ); ?></li><?php endforeach; ?></ul><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <p class="description"><?php esc_html_e( 'For a qualitative review (clarity, specificity, alternative wording) tick "Title review" under AI recommendations. That part is written by the AI model and is advice only.', 'seo-pilot' ); ?></p>
            <?php endif; ?>
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

    private static function issue_label( $basis ) {
        return 'measured' === $basis ? __( 'Measured', 'seo-pilot' ) : ( 'heuristic' === $basis ? __( 'Rule of thumb', 'seo-pilot' ) : $basis );
    }

    private static function section_alt( WP_Post $post, array $audit ) {
        $ia = isset( $audit['image_analysis'] ) && is_array( $audit['image_analysis'] ) ? $audit['image_analysis'] : null;
        if ( ! $ia ) {
            echo '<div class="seo-pilot-panel"><h2>' . esc_html__( 'Images and alt text', 'seo-pilot' ) . '</h2><p>' . esc_html__( 'This audit was created before the image tools existed. Use "Refresh audit" above to see them.', 'seo-pilot' ) . '</p></div>';
            return;
        }
        if ( empty( $ia['items'] ) && empty( $ia['featured'] ) ) { return; }
        $c     = $ia['counts'];
        $rows  = array_values( array_filter( $ia['items'], function ( $it ) { return ! empty( $it['needs_attention'] ); } ) );
        $existing = array(); $deco_saved = array();
        foreach ( SEO_Pilot_Recs::for_post( $post->ID, array( 'accepted' ) ) as $r ) {
            if ( 'alt_text' === $r['rec_type'] ) { $existing[ substr( $r['target'], 4 ) ] = $r['proposed']; if ( ! empty( $r['flags']['decorative'] ) ) { $deco_saved[ substr( $r['target'], 4 ) ] = true; } }
        }
        $has_lib = false;
        foreach ( $rows as $it ) { if ( in_array( $it['kind'], array( 'missing', 'empty_in_link' ), true ) && '' !== $it['library_alt'] ) { $has_lib = true; } }
        $ai_problem = SEO_Pilot_Settings::images_problem();
        $ai_count   = '' === $ai_problem ? count( SEO_Pilot_Alt_AI::candidates( $post, $audit ) ) : 0;
        ?>
        <div class="seo-pilot-panel seo-pilot-images">
            <h2><?php esc_html_e( 'Images and alt text', 'seo-pilot' ); ?></h2>
            <p><strong><?php echo esc_html( sprintf( '%d image(s): %d without alt text, %d linked with empty alt, %d to review, %d decorative (empty alt on purpose), %d look fine.', $c['images'], $c['missing'], $c['empty_in_link'], $c['review'], $c['decorative'], $c['ok'] ) ); ?></strong></p>
            <p class="description"><?php esc_html_e( 'Alt text is first an accessibility field: it tells people who cannot see an image what it shows. Keep it short and specific, and do not use it for keywords. "Measured" lines were found by comparing or counting; "Rule of thumb" lines are prompts to look, not requirements.', 'seo-pilot' ); ?></p>
            <?php if ( ! empty( $ia['featured'] ) ) : ?>
                <p><?php echo esc_html( '' === $ia['featured']['alt'] ? __( 'The featured image has no alt text in the Media Library. Add it in Media → Library; SEO Pilot does not edit Media Library entries.', 'seo-pilot' ) : __( 'The featured image has alt text in the Media Library.', 'seo-pilot' ) ); ?></p>
            <?php endif; ?>
            <?php if ( ! $rows ) : ?>
                <p><?php esc_html_e( 'No alt-text problems were found in this page\'s content.', 'seo-pilot' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Write the alt text yourself, or mark an image decorative if it adds no information. What you save is stored as accepted, but is only applied when you confirm in "Apply accepted changes".', 'seo-pilot' ); ?></p>
                <?php self::form_open( 'save_alt', $post->ID, 'alt' ); ?>
                    <table class="widefat striped seo-pilot-imgtable">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e( 'Image', 'seo-pilot' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'What was found', 'seo-pilot' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'New alt text', 'seo-pilot' ); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $rows as $it ) :
                            $h = $it['hash'];
                            $in_id = 'sp-alt-' . substr( $h, 0, 12 );
                            ?>
                            <tr>
                                <td class="seo-pilot-img-src">
                                    <img class="seo-pilot-thumb" src="<?php echo esc_url( $it['src'] ); ?>" alt="" loading="lazy" width="80">
                                    <code dir="ltr"><?php echo esc_html( SEO_Pilot_Images::label( $it['src'] ) ); ?></code>
                                    <?php if ( '' !== $it['context'] ) : ?><br><span class="description" dir="auto"><?php echo esc_html( 'Under the heading: ' . $it['context'] ); ?></span><?php endif; ?>
                                    <?php if ( '' !== $it['caption'] ) : ?><br><span class="description" dir="auto"><?php echo esc_html( 'Caption: ' . $it['caption'] ); ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <p dir="auto"><?php echo esc_html( $it['has_alt'] ? ( '' === $it['alt'] ? __( 'Current alt text: empty', 'seo-pilot' ) : 'Current alt text: "' . $it['alt'] . '"' ) : __( 'Current alt text: none (no alt attribute)', 'seo-pilot' ) ); ?></p>
                                    <ul class="seo-pilot-evidence">
                                        <?php foreach ( $it['issues'] as $is ) : ?>
                                            <li dir="auto"><?php echo self::badge( self::issue_label( $is['basis'] ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $is['text'] ); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td>
                                    <label class="screen-reader-text" for="<?php echo esc_attr( $in_id ); ?>"><?php echo esc_html( 'New alt text for ' . SEO_Pilot_Images::label( $it['src'] ) ); ?></label>
                                    <input id="<?php echo esc_attr( $in_id ); ?>" type="text" class="large-text" dir="auto" maxlength="300" name="alt[<?php echo esc_attr( $h ); ?>]" value="<?php echo esc_attr( isset( $existing[ $h ] ) ? $existing[ $h ] : '' ); ?>">
                                    <?php if ( 'empty_in_link' === $it['kind'] ) : ?>
                                        <p class="description"><?php esc_html_e( 'Describe where the link goes. This image cannot be marked decorative because the link has no other text.', 'seo-pilot' ); ?></p>
                                    <?php else : ?>
                                        <label><input type="checkbox" name="decorative[<?php echo esc_attr( $h ); ?>]" value="1" <?php checked( isset( $deco_saved[ $h ] ) ); ?>> <?php esc_html_e( 'Decorative: adds no information (saves an empty alt)', 'seo-pilot' ); ?></label>
                                    <?php endif; ?>
                                    <?php if ( '' !== $it['library_alt'] ) : ?><p class="description" dir="auto"><?php echo esc_html( 'Media Library alt text: "' . $it['library_alt'] . '"' ); ?></p><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><?php submit_button( __( 'Save alt text as accepted', 'seo-pilot' ), 'secondary', 'submit', false ); ?></p>
                </form>
                <?php if ( $has_lib ) : ?>
                    <?php self::form_open( 'alt_library', $post->ID, 'altlib' ); ?>
                        <p class="description"><?php esc_html_e( 'Some of these images already have alt text in your Media Library. This proposes that text for review. No AI is used and nothing is sent anywhere.', 'seo-pilot' ); ?></p>
                        <?php submit_button( __( 'Suggest Media Library alt text', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
                <h3><?php esc_html_e( 'AI draft (optional)', 'seo-pilot' ); ?></h3>
                <?php if ( '' !== $ai_problem ) : ?>
                    <p class="description"><?php echo esc_html( $ai_problem ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-pilot-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'seo-pilot' ); ?></a></p>
                <?php elseif ( ! $ai_count ) : ?>
                    <p class="description"><?php esc_html_e( 'No image here can be drafted: AI drafts need Media Library images that have no usable alt text and no pending or accepted entry yet.', 'seo-pilot' ); ?></p>
                <?php else : ?>
                    <?php self::form_open( 'generate_alt', $post->ID, 'altai' ); ?>
                        <p><?php echo esc_html( sprintf( '%1$d image(s) from this page will be resized and sent to %2$s (model %3$s) when you press the button, with the page title, section heading, caption and link text. Your keywords are not sent. The AI writes drafts only; you review each one.', $ai_count, SEO_Pilot_Settings::provider_host(), SEO_Pilot_Settings::get( 'model', '' ) ) ); ?></p>
                        <?php submit_button( __( 'Draft alt text with AI', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
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
                        <?php foreach ( SEO_Pilot_Prompt::tasks() as $key => $label ) : if ( 'localize' === $key && empty( $ctx['localize_source'] ) ) { continue; } ?>
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
            $deco    = ! empty( $r['flags']['decorative'] );
            $replace = isset( $r['flags']['mode'] ) && 'replace' === $r['flags']['mode'];
            $old     = isset( $r['flags']['old_alt'] ) ? (string) $r['flags']['old_alt'] : '';
            echo '<p dir="auto"><img class="seo-pilot-thumb" src="' . esc_url( $r['original'] ) . '" alt="" loading="lazy" width="80"> <code dir="ltr">' . esc_html( SEO_Pilot_Images::label( $r['original'] ) ) . '</code></p>';
            if ( $deco ) {
                echo '<p dir="auto"><strong>' . esc_html__( 'Mark as decorative (empty alt text).', 'seo-pilot' ) . '</strong></p>';
            } elseif ( $replace ) {
                echo '<p dir="auto">' . esc_html__( 'Replace alt text', 'seo-pilot' ) . ' <del class="seo-pilot-del">' . esc_html( '' === $old ? __( '(empty)', 'seo-pilot' ) : $old ) . '</del> → <ins class="seo-pilot-ins">' . esc_html( $r['proposed'] ) . '</ins></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- every part escaped.
            } else {
                echo '<p dir="auto">' . esc_html__( 'Add alt text:', 'seo-pilot' ) . ' <strong>' . esc_html( $r['proposed'] ) . '</strong></p>';
            }
            if ( $open ) {
                echo '<form method="post" class="seo-pilot-recform" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="seo_pilot_rec_action"><input type="hidden" name="rec_id" value="' . esc_attr( (string) $r['id'] ) . '">';
                wp_nonce_field( 'seo_pilot_rec_' . $r['id'] );
                echo '<label class="seo-pilot-label" for="sp-rec-' . esc_attr( (string) $r['id'] ) . '">' . esc_html__( 'Alt text (editable; leave empty only for a decorative image)', 'seo-pilot' ) . '</label>';
                echo '<textarea id="sp-rec-' . esc_attr( (string) $r['id'] ) . '" class="large-text" dir="auto" rows="2" name="proposed">' . esc_textarea( $r['proposed'] ) . '</textarea>';
                echo '<p class="description">' . esc_html( sprintf( '%d characters (a common guideline is about 125 or fewer). Describe what the image shows for someone who cannot see it; do not add keywords.', SEO_Pilot_Text::len( $r['proposed'] ) ) ) . '</p>';
                self::rec_buttons_inner( $r, true );
                echo '</form>';
            }
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
            case 'keywords_local':
                if ( ! empty( $d['primary'] ) ) { echo '<p><strong>' . esc_html__( 'Primary keyword idea:', 'seo-pilot' ) . '</strong> <span dir="auto">' . esc_html( $d['primary'] ) . '</span></p>'; }
                if ( ! empty( $d['secondary'] ) ) {
                    echo '<p><strong>' . esc_html__( 'Secondary ideas:', 'seo-pilot' ) . '</strong></p><ul>';
                    foreach ( $d['secondary'] as $k ) { echo '<li dir="auto">' . esc_html( $k ) . '</li>'; }
                    echo '</ul>';
                }
                if ( ! empty( $d['reason'] ) ) { echo '<p dir="auto">' . esc_html( $d['reason'] ) . '</p>'; }
                echo '<p class="description">' . esc_html__( 'These are not applied. Enter the phrases you decide to use under Page settings.', 'seo-pilot' ) . '</p>';
                break;
            case 'title_review':
                if ( ! empty( $d['critique'] ) ) { echo '<p>' . esc_html( $d['critique'] ) . '</p>'; }
                if ( ! empty( $d['alternatives'] ) ) {
                    echo '<h4>' . esc_html__( 'Alternative titles (AI suggestions)', 'seo-pilot' ) . '</h4><ul>';
                    foreach ( $d['alternatives'] as $alt ) {
                        echo '<li><strong>' . esc_html( $alt['title'] ) . '</strong> <span class="description">(' . esc_html( sprintf( '%d characters', SEO_Pilot_Text::len( $alt['title'] ) ) ) . ')</span>';
                        if ( '' !== $alt['reason'] ) { echo '<br>' . esc_html( $alt['reason'] ); }
                        echo '</li>';
                    }
                    echo '</ul><p class="description">' . esc_html__( 'These are not applied. To use one, tick "SEO title" under AI recommendations (or edit the SEO title proposal) and accept it.', 'seo-pilot' ) . '</p>';
                }
                break;
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
                                    <div class="seo-pilot-box" dir="auto"><?php
                                        $alt_deco = ! empty( $r['flags']['decorative'] );
                                        if ( isset( $r['flags']['mode'] ) && 'replace' === $r['flags']['mode'] ) {
                                            echo esc_html( 'Change alt="' . ( isset( $r['flags']['old_alt'] ) ? $r['flags']['old_alt'] : '' ) . '" to alt="' . ( $alt_deco ? '' : $r['proposed'] ) . '"' . ( $alt_deco ? ' (decorative)' : '' ) . ' on ' . $r['original'] );
                                        } else {
                                            echo esc_html( 'Add alt="' . ( $alt_deco ? '' : $r['proposed'] ) . '"' . ( $alt_deco ? ' (decorative)' : '' ) . ' to ' . $r['original'] );
                                        }
                                    ?></div>
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
                <?php self::form_open( 'live_check', $post->ID, 'live' ); ?>
                    <?php submit_button( __( 'Check live output', 'seo-pilot' ), 'secondary', 'submit', false ); ?>
                    <button type="submit" name="deep" value="1" class="button"><?php esc_html_e( 'Check live output and its other-language pages', 'seo-pilot' ); ?></button>
                    <span class="description"><?php esc_html_e( 'The second button also fetches up to 5 pages listed in this page\'s hreflang tags (this site only) to check that they link back.', 'seo-pilot' ); ?></span>
                </form>
                <?php if ( is_array( $live ) ) :
                    $md = SEO_Pilot_Compat::get( $post->ID, 'meta_description' );
                    $st = SEO_Pilot_Compat::get( $post->ID, 'seo_title' );
                    $notes = SEO_Pilot_Live_Check::notes( $live, $md['template_vars'] ? '' : $md['value'], $st['template_vars'] ? '' : $st['value'] );
                    $li    = SEO_Pilot_Lang::for_post( $post->ID );
                    $mem   = array();
                    foreach ( SEO_Pilot_Lang::indexable_members( $li ) as $im ) { if ( $im['code'] !== $li['code'] ) { $mem[ $im['code'] ] = $im['url']; } }
                    $mode  = SEO_Pilot_Lang::owns_hreflang() ? 'plugin' : ( SEO_Pilot_Settings::get( 'hreflang_output', 0 ) ? 'seo_pilot' : 'none' );
                    $multi = ( $li['declared'] || ! empty( $live['hreflang'] ) || $li['plugins'] );
                    $lnotes = $multi ? SEO_Pilot_Live_Check::lang_notes( $live, array( 'url' => $live['url'], 'code' => $li['code'], 'members' => $mem, 'mode' => $mode, 'expects' => (bool) $mem ) ) : array();
                    $rnotes = ! empty( $live['alternates'] ) ? SEO_Pilot_Live_Check::reciprocity_notes( $live['url'], $live['alternates'] ) : array();
                    ?>
                    <p class="description"><?php echo esc_html( 'Fetched ' . $live['at'] . '.' ); ?></p>
                    <ul class="seo-pilot-livenotes"><?php foreach ( $notes as $n ) : ?><li class="live-<?php echo esc_attr( $n[0] ); ?>"><span dir="auto"><?php echo esc_html( $n[1] ); ?></span></li><?php endforeach; ?></ul>
                    <?php if ( $lnotes || $rnotes ) : ?>
                        <h3><?php esc_html_e( 'Language and hreflang', 'seo-pilot' ); ?></h3>
                        <ul class="seo-pilot-livenotes"><?php foreach ( array_merge( $lnotes, $rnotes ) as $n ) : ?><li class="live-<?php echo esc_attr( $n[0] ); ?>"><span dir="auto"><?php echo esc_html( $n[1] ); ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
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
