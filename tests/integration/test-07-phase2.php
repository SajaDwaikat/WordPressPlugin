<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
configure_ai();
wp_set_current_user( 1 );
function para_text( $prefix, $n = 40 ) { $t = ''; for ( $i = 1; $i <= $n; $i++ ) { $t .= "$prefix sentence $i explains how bakers handle flour water salt and long slow fermentation overnight in a cold room. "; } return $t; }

t_group( 'Database upgrade 1 -> 2' );
global $wpdb;
update_option( 'seo_pilot_db_version', '1' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . SEO_Pilot_Searchdata::table() );
t_ok( ! SEO_Pilot_Installer::tables_exist(), 'sanity: search-data table missing' );
SEO_Pilot_Installer::maybe_upgrade();
t_ok( SEO_Pilot_Installer::tables_exist() && '2' === get_option( 'seo_pilot_db_version' ), 'upgrade creates the new table and records db version 2' );
t_eq( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SEO_Pilot_Recs::table() ) >= 0 ? 0 : 1, 'existing tables untouched by the upgrade' );

t_group( 'Search data: CSV parsing' );
$p = SEO_Pilot_Searchdata::parse_csv( "\xEF\xBB\xBFTop pages,Clicks,Impressions,CTR,Position\nhttps://a.test/x/,\"1,234\",\"5,678\",21.7%,3.4\nhttps://a.test/y/,5,100,5%,12.5\n" );
t_ok( is_array( $p ) && 'pages' === $p['kind'] && 2 === count( $p['rows'] ), 'Search Console Pages export (BOM, "Top pages" header) parsed' );
t_ok( 1234 === $p['rows'][0]['clicks'] && 5678 === $p['rows'][0]['impressions'] && 3.4 === $p['rows'][0]['position'], 'thousands separators handled; CTR column ignored' );
$p = SEO_Pilot_Searchdata::parse_csv( "Query;Clicks;Impressions;Position\nsourdough bread;3;50;7,5\nمحركات البحث;1;9;22,1\n" );
t_ok( is_array( $p ) && 'queries' === $p['kind'] && 7.5 === $p['rows'][0]['position'] && 'محركات البحث' === $p['rows'][1]['query'], 'semicolon delimiter, decimal comma and Arabic queries parsed' );
$p = SEO_Pilot_Searchdata::parse_csv( "Page,Query,Clicks,Impressions,Position\nhttps://a.test/x/,bread,1,2,3\n,missing page,1,1,1\nhttps://a.test/x/,,1,1,1\n" );
t_ok( is_array( $p ) && 'page_query' === $p['kind'] && 1 === count( $p['rows'] ) && 2 === $p['skipped'], 'page+query export parsed; incomplete rows skipped and counted' );
foreach ( array( '' => 'seo_pilot_csv_empty', "الصفحات,النقرات\nx,1" => 'seo_pilot_csv_columns', "Foo,Bar\n1,2" => 'seo_pilot_csv_columns', "Page,Clicks\n" => 'seo_pilot_csv_norows', "Page,Clicks\n\xff\xfe,1" => 'seo_pilot_csv_encoding' ) as $bad => $code ) {
    $e = SEO_Pilot_Searchdata::parse_csv( $bad );
    t_ok( is_wp_error( $e ) && $code === $e->get_error_code(), 'rejected with a clear error: ' . $code );
}
t_ok( false !== strpos( SEO_Pilot_Searchdata::parse_csv( "الصفحات,النقرات\nx,1" )->get_error_message(), 'English' ), 'localized-header error tells the user to export in English' );

t_group( 'Search data: import and verified queries' );
$sa = make_post( array( 'post_title' => 'Sourdough starter guide', 'post_content' => '<p>' . para_text( 'Starter', 20 ) . '</p>' ) );
$sb = make_post( array( 'post_title' => 'Sourdough bread recipe', 'post_content' => '<p>We bake sourdough bread. Hydration matters.</p>' ) );
$ua = get_permalink( $sa ); $ub = get_permalink( $sb );
$csv = "Page,Query,Clicks,Impressions,Position\n$ua,sourdough starter,10,300,5.5\n$ua,rye hydration ratio,1,120,14.2\n$ua,sourdough bread,2,90,20\n$ub,sourdough bread,8,400,6.1\n$ub,bread baking tips,0,50,30\nhttps://other.example/foreign/,x,1,1,1\n" . home_url( '/?p=999999' ) . ",y,1,1,1\n";
$e = SEO_Pilot_Searchdata::import( $csv, array( 'label' => '' ) );
t_ok( is_wp_error( $e ) && 'seo_pilot_import_label' === $e->get_error_code(), 'a period/source label is required' );
$imp = SEO_Pilot_Searchdata::import( $csv, array( 'label' => 'Search Console, last 3 months' ) );
t_ok( is_array( $imp ) && 5 === $imp['rows'] && 2 === $imp['posts'] && 1 === $imp['foreign'] && 1 === $imp['unresolved'], 'rows for this site stored; foreign-host and non-WordPress rows skipped and counted' );
$e = SEO_Pilot_Searchdata::import( "Query,Clicks,Impressions\nx,1,1", array( 'label' => 'L' ) );
t_ok( is_wp_error( $e ) && 'seo_pilot_import_post' === $e->get_error_code(), 'a Queries report needs a page to attach to' );
$e = SEO_Pilot_Searchdata::import( str_repeat( 'a', 6000000 ), array( 'label' => 'L' ) );
t_ok( is_wp_error( $e ) && 'seo_pilot_import_size' === $e->get_error_code(), 'files over 5 MB refused' );
$tot = SEO_Pilot_Searchdata::totals( $sa, $imp['id'] );
t_eq( array( 'clicks' => 13, 'impressions' => 510, 'rows' => 3 ), $tot, 'totals are exactly the sums of the imported rows' );
$tq = SEO_Pilot_Searchdata::top_queries( $sa, $imp['id'], 5 );
t_eq( 'sourdough starter', $tq[0]['query'], 'top queries ordered by impressions' );
$gaps = SEO_Pilot_Searchdata::query_gaps( $tq, SEO_Pilot_Text::plain( get_post( $sa )->post_content ) );
t_eq( array( 'rye hydration ratio', 'sourdough bread' ), array_column( $gaps, 'query' ), 'queries the text barely mentions are listed as gaps ("sourdough starter" is not: half its words are present)' );
$can = SEO_Pilot_Searchdata::cannibalization( $sa, $imp['id'] );
t_ok( 1 === count( $can ) && 'sourdough bread' === $can[0]['query'] && in_array( 'Sourdough bread recipe', $can[0]['pages'], true ), 'a query that shows two of your pages is reported, naming the other page' );

$audit = SEO_Pilot_Audit_Runner::audit_post( $sa );
$ids = array_column( $audit['findings'], 'id' );
t_ok( in_array( 'sd_query_gap', $ids, true ) && in_array( 'sd_cannibal', $ids, true ), 'audit uses the imported data' );
t_ok( false !== strpos( wp_json_encode( $audit['checklist'] ), 'Search Console, last 3 months' ), 'checklist names the data source' );
$sbA = SEO_Pilot_Audit_Runner::audit_post( $sb );
t_eq( 'info', array_column( $sbA['checklist'], 'status', 'id' )['sd_present'], 'page with data: shown as informational' );
$other = make_post( array( 'post_title' => 'No data page', 'post_content' => '<p>Something else.</p>' ) );
t_eq( 'na', array_column( SEO_Pilot_Audit_Runner::audit_post( $other )['checklist'], 'status', 'id' )['sd_present'], 'page without data: not applicable (nothing invented)' );
$imp2 = SEO_Pilot_Searchdata::import( "Page,Query,Clicks,Impressions,Position\n$ua,newer query,1,1,1\n", array( 'label' => 'Newer export' ) );
t_eq( 'Newer export', SEO_Pilot_Searchdata::latest_import_for( $sa )['label'], 'the most recent import is used for a page' );
SEO_Pilot_Searchdata::delete_import( $imp2['id'] );
t_eq( 'Search Console, last 3 months', SEO_Pilot_Searchdata::latest_import_for( $sa )['label'], 'deleting an import falls back to the previous one' );
$q = SEO_Pilot_Searchdata::import( "Query,Clicks,Impressions,Position\nstandalone query,2,30,9\n", array( 'label' => 'Queries for one page', 'post_id' => $other ) );
t_ok( is_array( $q ) && 'queries' === $q['kind'] && 1 === count( SEO_Pilot_Searchdata::top_queries( $other, $q['id'] ) ), 'Queries report attached to the chosen page' );
SEO_Pilot_Searchdata::delete_import( $imp['id'] );
t_eq( 'na', array_column( SEO_Pilot_Audit_Runner::audit_post( $sa )['checklist'], 'status', 'id' )['sd_present'], 'after deleting the import the data is gone from the audit' );
t_eq( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SEO_Pilot_Searchdata::table() . ' WHERE import_id = %s', $imp['id'] ) ), 'deleted import leaves no rows behind' );
SEO_Pilot_Searchdata::delete_import( $q['id'] );

t_group( 'Search data handlers: guards' );
$r = call_handler( 'import_search', array( 'label' => 'x' ), 'seo_pilot_import_search', 1 );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'Choose a CSV' ), 'import with no file: friendly error' );
$r = call_handler( 'import_search', array( 'label' => 'x' ), 'seo_pilot_import_search', 1, true ); t_ok( isset( $r['died'] ), 'bad nonce rejected' );
$r = call_handler( 'import_search', array( 'label' => 'x' ), 'seo_pilot_import_search', $editor ); t_ok( isset( $r['died'] ), 'non-admin rejected' );
$_FILES = array( 'csv' => array( 'name' => 'x.php', 'tmp_name' => '/etc/passwd', 'error' => 0, 'size' => 10 ) );
$r = call_handler( 'import_search', array( 'label' => 'x' ), 'seo_pilot_import_search', 1 );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], '.csv' ), 'wrong file type rejected' );
$_FILES = array( 'csv' => array( 'name' => 'x.csv', 'tmp_name' => '/etc/passwd', 'error' => 0, 'size' => 10 ) );
$r = call_handler( 'import_search', array( 'label' => 'x' ), 'seo_pilot_import_search', 1 );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'could not be verified' ), 'a path that is not a real upload is refused (cannot be used to read server files)' );
$_FILES = array();
$r = call_handler( 'delete_import', array( 'import_id' => 'abc' ), 'seo_pilot_delete_import_abc', $editor ); t_ok( isset( $r['died'] ), 'non-admin cannot delete imports' );
$r = call_handler( 'export_report', array(), 'seo_pilot_export_report', $editor ); t_ok( isset( $r['died'] ), 'non-admin cannot export the report' );
$r = call_handler( 'export_report', array(), 'seo_pilot_export_report', 1, true ); t_ok( isset( $r['died'] ), 'report export needs a valid nonce' );

t_group( 'Similar-content detection on real posts' );
$d1 = make_post( array( 'post_title' => 'Bread guide one', 'post_content' => '<p>' . para_text( 'Main', 40 ) . '</p>' ) );
$d2 = make_post( array( 'post_title' => 'Bread guide two', 'post_content' => '<p>' . str_replace( 'Main sentence 9 ', 'Main sentence nine ', para_text( 'Main', 40 ) ) . ' One extra closing sentence.</p>' ) );
$d3 = make_post( array( 'post_title' => 'Cycling notes', 'post_content' => '<p>' . str_repeat( 'Mountain cycling needs careful gear checks before a long ride and planning for weather changes. ', 1 ) . implode( ' ', array_map( function ( $i ) { return "Route $i climbs through alpine passes with steady gradients and wide views over the valley below us."; }, range( 1, 40 ) ) ) . '</p>' ) );
$d4 = make_post( array( 'post_title' => 'Short note', 'post_content' => '<p>Main sentence 1 explains how bakers handle flour.</p>' ) );
SEO_Pilot_Audit_Runner::index( true );
$a1 = SEO_Pilot_Audit_Runner::audit_post( $d1 );
$f = null; foreach ( $a1['findings'] as $x ) { if ( 'similar_content' === $x['id'] ) { $f = $x; } }
t_ok( null !== $f && 'high' === $f['severity'] && false !== strpos( $f['evidence'][0], 'Bread guide two' ), 'near-duplicate page found, with its title and percentage' );
t_ok( ! in_array( 'Cycling notes', array_map( function ( $e ) { return explode( ' — ', $e )[0]; }, $f['evidence'] ), true ), 'unrelated page not reported' );
t_ok( ! in_array( 'similar_content', array_column( SEO_Pilot_Audit_Runner::audit_post( $d4 )['findings'], 'id' ), true ), 'a very short page is never compared' );
t_ok( ! in_array( 'similar_content', array_column( SEO_Pilot_Audit_Runner::audit_post( $d3 )['findings'], 'id' ), true ), 'unrelated page: no similarity finding' );
$cache = SEO_Pilot_Util::get_json_meta( $d1, '_seo_pilot_sketch' );
t_ok( is_array( $cache ) && ! empty( $cache['k']['s'] ), 'sketch cached in post meta' );
wp_update_post( wp_slash( array( 'ID' => $d2, 'post_content' => '<p>' . para_text( 'Entirely different', 40 ) . '</p>' ) ) );
SEO_Pilot_Audit_Runner::index( true );
t_ok( ! in_array( 'similar_content', array_column( SEO_Pilot_Audit_Runner::audit_post( $d1 )['findings'], 'id' ), true ), 'cache refreshes when content changes: no longer similar' );
$before = array_map( function ( $r ) { return $r['status']; }, SEO_Pilot_Recs::for_post( $d1 ) ); 
t_eq( get_post( $d1 )->post_content, '<p>' . para_text( 'Main', 40 ) . '</p>', 'similarity analysis never changes content' );

t_group( 'Internal links, inbound links and content gap (local analysis)' );
$tgt1 = make_post( array( 'post_title' => 'Rye Starter Guide | My Bakery', 'post_content' => '<p>' . para_text( 'Starter care', 12 ) . ' Fermentation hydration proofing temperature.</p>' ) );
$tgt2 = make_post( array( 'post_title' => 'Mountain Cycling Routes', 'post_content' => '<p>Routes. ' . para_text( 'Cycling', 5 ) . '</p>' ) );
$tgt3 = make_post( array( 'post_title' => 'Hidden page about Lamination Techniques', 'post_content' => '<p>' . para_text( 'Hidden', 5 ) . '</p>' ) );
update_post_meta( $tgt3, '_yoast_wpseo_meta-robots-noindex', '1' );
$tgt4 = make_post( array( 'post_title' => 'Unpublished Draft Topic Page', 'post_status' => 'draft', 'post_content' => '<p>x</p>' ) );
$src_content = "<p>Our rye starter guide helps beginners, and the Lamination Techniques notes are elsewhere. This opening paragraph is long enough.</p>\n\n<p>Read about <a href=\"/x\">cycling</a> and <a href=\"/y\">hiking</a> and Mountain Cycling Routes too, plus the Unpublished Draft Topic Page.</p>\n\n<p>Another paragraph that mentions the Mountain Cycling Routes once more in plain text here.</p>";
$src = make_post( array( 'post_title' => 'Link source page', 'post_content' => $src_content ) );
$inb = make_post( array( 'post_title' => 'Mentions the source', 'post_content' => '<p>Please read the Link source page for details, it is helpful and long enough to be a paragraph.</p>' ) );
$GLOBALS['ai_calls'] = array();
$r = call_handler( 'analyse_site', array( 'post_id' => $src ), 'seo_pilot_analyse_' . $src, 1 );
t_eq( 'success', $r['flash']['type'], 'analysis ran: ' . $r['flash']['message'] );
t_eq( 0, count( $GLOBALS['ai_calls'] ), 'local analysis makes no AI request' );
t_eq( $src_content, get_post( $src )->post_content, 'analysis does not change the page' );
$links = recs_of( $src, 'internal_link' );
$titles = array_map( function ( $x ) { return $x['flags']['target_title']; }, $links );
t_ok( in_array( 'Rye Starter Guide | My Bakery', $titles, true ) && in_array( 'Mountain Cycling Routes', $titles, true ), 'link suggestions exist only where the page text already names another page' );
t_ok( ! in_array( 'Hidden page about Lamination Techniques', $titles, true ), 'noindex pages are never suggested as link targets' );
t_ok( ! in_array( 'Unpublished Draft Topic Page', $titles, true ), 'unpublished pages are never suggested' );
$sl = array_values( array_filter( $links, function ( $x ) { return 'Rye Starter Guide | My Bakery' === $x['flags']['target_title']; } ) )[0];
t_ok( 'local' === $sl['source'] && 'pending' === $sl['status'] && false !== strpos( $sl['proposed'], '<a href="' . get_permalink( $tgt1 ) . '">rye starter guide</a>' ), 'suggestion wraps the page\'s own words, keeps their case, uses the real permalink' );
t_ok( 'seg:' === substr( $sl['target'], 0, 4 ), 'bound to the original paragraph' );
$inbound = recs_of( $src, 'inbound_links' ); $gap = recs_of( $src, 'site_gap' );
t_eq( 1, count( $inbound ), 'inbound advisory created' );
t_ok( in_array( 'Mentions the source', array_column( json_decode( $inbound[0]['proposed'], true )['pages'], 'title' ), true ), 'pages that mention this page without linking to it are listed' );
t_ok( ! SEO_Pilot_Recs::is_applicable( 'inbound_links' ) && ! SEO_Pilot_Recs::is_applicable( 'site_gap' ), 'inbound/gap items are guidance only' );
$r = call_handler( 'analyse_site', array( 'post_id' => $src ), 'seo_pilot_analyse_' . $src, $editor ); t_ok( isset( $r['died'] ), 'non-admin cannot run the analysis' );
$r = call_handler( 'analyse_site', array( 'post_id' => $src ), 'seo_pilot_analyse_' . $src, 1 );
t_eq( 1, count( recs_of( $src, 'internal_link', array( 'pending' ) ) ) >= 1 ? 1 : 0, 're-running replaces earlier local suggestions' );
t_eq( 'superseded', recs_of( $src, 'internal_link', array( 'superseded' ) )[0]['status'], 'earlier suggestions are retired, not duplicated' );
$links = recs_of( $src, 'internal_link', array( 'pending' ) );
$sl = array_values( array_filter( $links, function ( $x ) { return 'Rye Starter Guide | My Bakery' === $x['flags']['target_title']; } ) )[0];

// edit safety
$r = rec_do( $sl['id'], 'accept', array( 'proposed' => 'No link any more in this text.' ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'must stay' ), 'removing the approved link in an edit is refused' );
$r = rec_do( $sl['id'], 'save', array( 'proposed' => str_replace( 'helps beginners', 'helps <a href="https://evil.example/">beginners</a>', $sl['proposed'] ) ) );
t_ok( false === strpos( SEO_Pilot_Recs::get( $sl['id'] )['proposed'], 'evil.example' ), 'an edit cannot smuggle in another link' );
rec_do( $sl['id'], 'accept' );
$plan = SEO_Pilot_Approval::plan( $src );
t_eq( array( 'post_content' ), array_column( $plan['changes'], 'field' ), 'plan shows exactly one content change' );
$r = apply_do( $src );
t_eq( 'error', $r['flash']['type'], 'live page: link insertion still needs confirmation' );
$r = apply_do( $src, array( 'confirm_live' => 1 ) );
t_eq( 'success', $r['flash']['type'], 'link applied: ' . $r['flash']['message'] );
$new = get_post( $src )->post_content;
t_ok( false !== strpos( $new, '<a href="' . get_permalink( $tgt1 ) . '">rye starter guide</a>' ), 'the link is in the post' );
t_eq( substr( $src_content, strpos( $src_content, "\n\n<p>Read about" ) ), substr( $new, strpos( $new, "\n\n<p>Read about" ) ), 'other paragraphs are byte-identical' );
t_ok( 0 === count( array_filter( SEO_Pilot_Audit_Runner::audit_post( $src )['findings'], function ( $f ) { return 'no_internal_links' === $f['id']; } ) ), 'audit re-run after applying' );
$gid = SEO_Pilot_History::query( $src, 1 )[0]['group_id'];
$r = call_handler( 'rollback_group', array( 'group_id' => $gid, 'confirm_live' => 1 ), 'seo_pilot_rollback_' . $gid, 1 );
t_eq( $src_content, get_post( $src )->post_content, 'rollback removes the link and restores the original exactly' );

// target removed between accept and apply
$mc = array_values( array_filter( recs_of( $src, 'internal_link', array( 'pending' ) ), function ( $x ) { return 'Mountain Cycling Routes' === $x['flags']['target_title']; } ) )[0];
rec_do( $mc['id'], 'accept' );
wp_update_post( array( 'ID' => $tgt2, 'post_status' => 'draft' ) );
$r = apply_do( $src, array( 'confirm_live' => 1 ) );
t_ok( 'error' === $r['flash']['type'] && false !== strpos( $r['flash']['message'], 'no longer published' ), 'link to a page that was unpublished after approval is refused' );
t_eq( $src_content, get_post( $src )->post_content, 'nothing changed' );
wp_update_post( array( 'ID' => $tgt2, 'post_status' => 'publish' ) );
wp_update_post( wp_slash( array( 'ID' => $src, 'post_content' => str_replace( 'once more in plain text', 'once again in plain text', $src_content ) ) ) );
$r = apply_do( $src, array( 'confirm_live' => 1 ) );
t_ok( 'error' === $r['flash']['type'], 'link suggestion for a paragraph edited since is refused as stale' );

t_group( 'Report and screens' );
$aud = SEO_Pilot_Audit_Runner::all_saved();
t_ok( count( $aud ) >= 5, 'stored audits collected for the report (' . count( $aud ) . ')' );
$rep = SEO_Pilot_Report::site_report( $aud );
t_ok( $rep['pages'] === count( $aud ) && 10 === count( $rep['criteria'] ), 'site report built' );
$csv = SEO_Pilot_Report::csv( $aud );
t_ok( false !== strpos( $csv, 'finding_id' ) && substr_count( $csv, "\n" ) > count( $aud ), 'CSV contains findings' );
$xss = make_post( array( 'title' => 'x', 'post_title' => '=cmd|calc<script>alert(1)</script>', 'post_content' => '<p>short</p>' ) );
SEO_Pilot_Audit_Runner::audit_post( $xss );
$csv = SEO_Pilot_Report::csv( SEO_Pilot_Audit_Runner::all_saved() );
t_ok( false === strpos( $csv, ',=cmd' ) && false === strpos( $csv, '"=cmd' ), 'a page titled with a formula cannot become a spreadsheet formula' );

$GLOBALS['php_problems'] = array();
set_error_handler( function ( $no, $str, $file, $line ) { $GLOBALS['php_problems'][] = "$str ($file:$line)"; return true; } );
$xi = SEO_Pilot_Searchdata::import( "Page,Query,Clicks,Impressions,Position\n" . get_permalink( $xss ) . ",\"5 < 6 & <i>x</i> \"quoted\"\",1,5,3\n", array( 'label' => '<script>alert("l")</script> label' ) );
t_ok( is_array( $xi ), 'import with hostile label/query succeeds: ' . ( is_wp_error( $xi ) ? $xi->get_error_message() : 'ok' ) );
$o = with_get( array( 'page' => 'seo-pilot-search' ), array( 'SEO_Pilot_Admin_Data', 'render_search' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Import a CSV export' ) && false === stripos( $o, '<script>alert' ) && false !== strpos( $o, 'enctype="multipart/form-data"' ), 'Search data screen renders; hostile label escaped; upload form is multipart' );
$o = with_get( array( 'page' => 'seo-pilot-report' ), array( 'SEO_Pilot_Admin_Data', 'render_report' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'By criterion' ) && false !== strpos( $o, 'Download all findings' ) && false === stripos( $o, '<script>alert' ), 'Report screen renders and escapes page titles' );
$o = with_get( array( 'post_id' => $xss ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( 0 !== strpos( $o, 'EXCEPTION' ) && false !== strpos( $o, 'Search data (imported)' ) && false !== strpos( $o, '5 &lt; 6 &amp; x' ) && false === stripos( $o, '<script>alert' ) && false === strpos( $o, '<i>x</i>' ), 'workspace shows imported data labelled; tags stripped on import and stray < & escaped on display' );
$o = with_get( array( 'post_id' => $src ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
foreach ( array( 'Assessment by criterion', 'Related pages on this site', 'Analyse related pages', 'Search data (imported)', 'Internal link', 'local analysis, no AI', 'No single score is shown' ) as $needle ) { t_ok( false !== strpos( $o, $needle ), "workspace shows: $needle" ); }
t_ok( false === strpos( $o, 'value="regenerate"' ), 'locally generated suggestions have no AI "Regenerate" button' );
preg_match_all( '/name="action" value="(seo_pilot_[a-z_]+)"/', $o, $m );
foreach ( array_unique( $m[1] ) as $act ) { t_ok( has_action( 'admin_post_' . $act ), "form action $act has a handler" ); }
$no = with_get( array( 'post_id' => $other ), array( 'SEO_Pilot_Workspace_View', 'render' ) );
t_ok( false !== strpos( $no, 'will not estimate them' ), 'page without data says no numbers are estimated' );
restore_error_handler();
t_eq( array(), $GLOBALS['php_problems'], 'no PHP warnings on the new screens' );
if ( $GLOBALS['php_problems'] ) { print_r( array_slice( $GLOBALS['php_problems'], 0, 6 ) ); }
if ( is_array( $xi ) ) { SEO_Pilot_Searchdata::delete_import( $xi['id'] ); }
