<?php
t_group( 'Activation, tables, settings' );

t_ok( is_plugin_active( 'seo-pilot/seo-pilot.php' ), 'plugin activates without fatal errors' );
t_ok( SEO_Pilot_Installer::tables_exist(), 'recommendation + history tables created' );
t_eq( SEO_PILOT_DB_VERSION, get_option( 'seo_pilot_db_version' ), 'db version recorded' );
t_ok( class_exists( 'SEO_Pilot' ) && is_callable( array( 'SEO_Pilot', 'instance' ) ), 'MVP entry point SEO_Pilot::instance() still exists' );

// Endpoint validation
list( $ok ) = SEO_Pilot_Settings::validate_endpoint( 'http://api.example.com/v1/' );
t_ok( ! $ok, 'plain http endpoint rejected' );
list( $ok ) = SEO_Pilot_Settings::validate_endpoint( 'http://localhost:11434/v1/' );
t_ok( $ok, 'http://localhost allowed for local models' );
list( $ok ) = SEO_Pilot_Settings::validate_endpoint( 'https://user:pw@api.example.com/v1/' );
t_ok( ! $ok, 'credentials in URL rejected' );
list( $ok ) = SEO_Pilot_Settings::validate_endpoint( 'ftp://api.example.com' );
t_ok( ! $ok, 'non-http scheme rejected' );

// API key handling
SEO_Pilot_Settings::store_key( 'sk-live-ABCDEFGH12345678' );
$stored = get_option( SEO_Pilot_Settings::SECRET_OPTION );
t_ok( false === strpos( $stored, 'ABCDEFGH' ), 'stored key does not contain the plain text' );
t_eq( 'sk-live-ABCDEFGH12345678', SEO_Pilot_Settings::api_key(), 'key decrypts correctly' );
global $wpdb;
$auto = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SEO_Pilot_Settings::SECRET_OPTION ) );
t_ok( in_array( $auto, array( 'no', 'off' ), true ), 'secret option is not autoloaded (' . $auto . ')' );
t_eq( 'database', SEO_Pilot_Settings::key_source(), 'key source reported' );
t_ok( false === strpos( SEO_Pilot_Util::redact( 'failed with Bearer sk-live-ABCDEFGH12345678 now' ), 'ABCDEFGH' ), 'redact() removes the key from text' );
SEO_Pilot_Settings::store_key( '' );
t_ok( ! SEO_Pilot_Settings::has_key(), 'key can be removed' );

// sanitize(): idempotent, validates, keeps key out of the settings array
update_option( SEO_Pilot_Settings::OPTION, array() );
$in = array( 'endpoint' => 'https://api.example.com/v1/', 'model' => ' m1 ', 'api_key' => 'sk-new-KEY-123456789', 'max_chars' => 99999, 'rate_limit' => 0, 'ack' => '1' );
$out1 = SEO_Pilot_Settings::sanitize( $in );
$out2 = SEO_Pilot_Settings::sanitize( $out1 );
t_eq( 12000, $out1['max_chars'], 'max_chars clamped' );
t_eq( 1, $out1['rate_limit'], 'rate_limit clamped' );
t_ok( ! array_key_exists( 'api_key', $out1 ), 'api key is never in the settings array' );
t_eq( $out1['endpoint'], $out2['endpoint'], 'sanitize is idempotent (WordPress may call it twice)' );
t_eq( 'sk-new-KEY-123456789', SEO_Pilot_Settings::api_key(), 'key saved through sanitize()' );
$bad = SEO_Pilot_Settings::sanitize( array( 'endpoint' => 'http://insecure.example.com' ) );
t_eq( '', $bad['endpoint'], 'insecure endpoint is not saved' );
SEO_Pilot_Settings::store_key( '' );
update_option( SEO_Pilot_Settings::OPTION, array() );
t_ok( '' !== SEO_Pilot_Settings::configuration_problem(), 'AI refuses to run when unconfigured' );
configure_ai( array( 'ack' => 0 ) );
t_ok( false !== strpos( SEO_Pilot_Settings::configuration_problem(), 'understand' ), 'AI refuses to run until the sharing acknowledgement is ticked' );
configure_ai();
t_eq( '', SEO_Pilot_Settings::configuration_problem(), 'configured + acknowledged' );

t_group( 'Rate limiter' );
configure_ai( array( 'rate_limit' => 2 ) );
t_ok( true === SEO_Pilot_Rate_Limiter::check(), 'first request allowed' );
SEO_Pilot_Rate_Limiter::record(); SEO_Pilot_Rate_Limiter::record();
$rl = SEO_Pilot_Rate_Limiter::check();
t_ok( is_wp_error( $rl ) && 'seo_pilot_rate_limited' === $rl->get_error_code(), 'third request in the hour is refused' );
t_eq( 0, SEO_Pilot_Rate_Limiter::remaining(), 'remaining reported' );
configure_ai();

t_group( 'Migration from the 0.1.0 MVP' );
$p1 = make_post( array( 'post_title' => 'Legacy page', 'post_content' => '<p>Legacy content.</p>' ) );
update_post_meta( $p1, '_seo_pilot_pending_suggestion', array( 'post_id' => $p1, 'title' => 'Legacy AI title', 'meta_description' => 'Legacy AI description for this page.', 'created_at' => '2026-01-01 00:00:00' ) );
update_post_meta( $p1, '_seo_pilot_last_snapshot', array( 'title' => 'Old title', 'meta_description' => '', 'saved_at' => '2026-01-01 00:00:00' ) );
update_post_meta( $p1, '_seo_pilot_meta_description', 'Current legacy description' );
update_option( SEO_Pilot_Settings::OPTION, array( 'provider' => 'openai_compatible', 'endpoint' => 'https://ai.example.test/v1/', 'model' => 'old-model', 'api_key' => 'sk-legacy-KEY-123456789' ) );
update_option( 'seo_pilot_last_audit', array( 'ran_at' => 'x', 'count' => 1, 'items' => array( $p1 => array( 'issues' => array() ) ) ) );
delete_option( SEO_Pilot_Settings::SECRET_OPTION );
delete_option( SEO_Pilot_Installer::MIGRATED_OPTION );
SEO_Pilot_Installer::maybe_upgrade();
t_eq( 'sk-legacy-KEY-123456789', SEO_Pilot_Settings::api_key(), 'legacy API key moved to the secret option' );
t_ok( ! array_key_exists( 'api_key', (array) get_option( SEO_Pilot_Settings::OPTION ) ), 'legacy API key removed from the settings array' );
t_eq( 'old-model', SEO_Pilot_Settings::get( 'model' ), 'other legacy settings preserved' );
t_eq( 0, (int) SEO_Pilot_Settings::get( 'ack' ), 'sharing acknowledgement must be given again after upgrade' );
$recs = SEO_Pilot_Recs::for_post( $p1, array( 'pending' ) );
t_eq( 2, count( $recs ), 'pending suggestion converted to two reviewable recommendations' );
t_ok( '' === get_post_meta( $p1, '_seo_pilot_pending_suggestion', true ), 'legacy pending meta removed' );
$hist = SEO_Pilot_History::group( 'legacy-' . $p1 );
t_eq( 2, count( $hist ), 'legacy snapshot became rollback-able history (title + description)' );
t_ok( '' === get_post_meta( $p1, '_seo_pilot_last_snapshot', true ), 'legacy snapshot meta removed' );
t_ok( false === get_option( 'seo_pilot_last_audit' ), 'incompatible 0.1.0 audit result discarded' );
SEO_Pilot_Installer::maybe_upgrade();
t_eq( 2, count( SEO_Pilot_Recs::for_post( $p1, array( 'pending' ) ) ), 'migration is idempotent' );
configure_ai();
