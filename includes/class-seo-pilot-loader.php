<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tiny class-map autoloader. Keeps the plugin modular without Composer.
 */
final class SEO_Pilot_Loader {
    private static $map = array(
        'SEO_Pilot'              => 'includes/class-seo-pilot.php',
        'SEO_Pilot_Util'         => 'includes/core/class-seo-pilot-util.php',
        'SEO_Pilot_Settings'     => 'includes/core/class-seo-pilot-settings.php',
        'SEO_Pilot_Installer'    => 'includes/core/class-seo-pilot-installer.php',
        'SEO_Pilot_Text'         => 'includes/audit/class-seo-pilot-text.php',
        'SEO_Pilot_Parser'       => 'includes/audit/class-seo-pilot-parser.php',
        'SEO_Pilot_Diff'         => 'includes/audit/class-seo-pilot-diff.php',
        'SEO_Pilot_Audit'        => 'includes/audit/class-seo-pilot-audit.php',
        'SEO_Pilot_Audit_Runner' => 'includes/audit/class-seo-pilot-audit-runner.php',
        'SEO_Pilot_Similarity'   => 'includes/audit/class-seo-pilot-similarity.php',
        'SEO_Pilot_Gap'          => 'includes/audit/class-seo-pilot-gap.php',
        'SEO_Pilot_Links'        => 'includes/audit/class-seo-pilot-links.php',
        'SEO_Pilot_Report'       => 'includes/audit/class-seo-pilot-report.php',
        'SEO_Pilot_Searchdata'   => 'includes/storage/class-seo-pilot-searchdata.php',
        'SEO_Pilot_Live_Check'   => 'includes/audit/class-seo-pilot-live-check.php',
        'SEO_Pilot_Intent'       => 'includes/intent/class-seo-pilot-intent.php',
        'SEO_Pilot_Compat'       => 'includes/compat/class-seo-pilot-compat.php',
        'SEO_Pilot_AI_Client'    => 'includes/ai/class-seo-pilot-ai-client.php',
        'SEO_Pilot_Prompt'       => 'includes/ai/class-seo-pilot-prompt.php',
        'SEO_Pilot_Validator'    => 'includes/ai/class-seo-pilot-validator.php',
        'SEO_Pilot_Rate_Limiter' => 'includes/ai/class-seo-pilot-rate-limiter.php',
        'SEO_Pilot_Recs'         => 'includes/storage/class-seo-pilot-recs.php',
        'SEO_Pilot_History'      => 'includes/storage/class-seo-pilot-history.php',
        'SEO_Pilot_Approval'     => 'includes/revisions/class-seo-pilot-approval.php',
        'SEO_Pilot_Admin'        => 'includes/admin/class-seo-pilot-admin.php',
        'SEO_Pilot_Admin_Data'   => 'includes/admin/class-seo-pilot-admin-data.php',
        'SEO_Pilot_Workspace'    => 'includes/admin/class-seo-pilot-workspace.php',
        'SEO_Pilot_Workspace_View' => 'includes/admin/class-seo-pilot-workspace-view.php',
    );

    public static function register() {
        spl_autoload_register( array( __CLASS__, 'load' ) );
    }

    public static function load( $class ) {
        if ( isset( self::$map[ $class ] ) ) {
            require_once SEO_PILOT_PATH . self::$map[ $class ];
        }
    }
}
