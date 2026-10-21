<?php
t_group( 'Audit: similar content and imported search data' );
$page = function ( array $o = array() ) {
    return array_merge( array( 'id' => 1, 'title' => 'Sourdough guide', 'content' => '<h2>Overview</h2><p>' . str_repeat( 'We bake sourdough bread every morning with care and patience. ', 14 ) . '</p>', 'site_host' => 'example.com', 'year' => 2026,
        'meta_description' => array( 'value' => 'A practical guide to baking sourdough bread at home with a few simple tools and patience.', 'owner_label' => 'x', 'template_vars' => false ) ), $o );
};
$a = SEO_Pilot_Audit::analyze( $page( array( 'similar' => array( array( 'id' => 2, 'title' => 'Bread basics', 'score' => 0.86 ), array( 'id' => 3, 'title' => 'Loaf notes', 'score' => 0.55 ) ) ) ) );
$f = null; foreach ( $a['findings'] as $x ) { if ( 'similar_content' === $x['id'] ) { $f = $x; } }
t_ok( null !== $f && 'high' === $f['severity'] && $f['needs_human'], '>=80% overlap: high severity, needs a human' );
t_ok( false !== strpos( $f['why'], 'estimate' ) && false !== strpos( $f['action'], 'never sets canonicals' ), 'finding is honest that it is an estimate and that no canonical/redirect is set' );
t_eq( 2, count( $f['evidence'] ), 'all similar pages listed' );
$a = SEO_Pilot_Audit::analyze( $page( array( 'similar' => array( array( 'id' => 3, 'title' => 'Loaf notes', 'score' => 0.55 ) ) ) ) );
foreach ( $a['findings'] as $x ) { if ( 'similar_content' === $x['id'] ) { t_eq( 'medium', $x['severity'], '50-80% overlap: medium' ); } }
$a = SEO_Pilot_Audit::analyze( $page() );
t_eq( 'pass', check_status( $a, 'similar' ), 'no similar pages: check passes' );
t_eq( 'na', check_status( $a, 'sd_present' ), 'no search data: shown as not applicable, never invented' );
t_ok( false !== strpos( wp_json_encode_compat( $a['checklist'] ), 'no search volume' ), 'checklist says SEO Pilot has no search data of its own' );

$sd = array( 'label' => 'Search Console, last 3 months', 'created_at' => '2026-10-01 10:00:00', 'totals' => array( 'clicks' => 12, 'impressions' => 340, 'rows' => 5 ),
    'gaps' => array( array( 'query' => 'rye starter ratio', 'impressions' => 120, 'clicks' => 1, 'position' => 14.2 ) ),
    'cannibal' => array( array( 'query' => 'sourdough bread', 'impressions' => 200, 'pages' => array( 'Other page' ) ) ) );
$a = SEO_Pilot_Audit::analyze( $page( array( 'search_data' => $sd ) ) );
$ids = finding_ids( $a );
t_ok( in_array( 'sd_query_gap', $ids, true ) && in_array( 'sd_cannibal', $ids, true ), 'imported-data findings raised' );
foreach ( $a['findings'] as $x ) {
    if ( 'sd_query_gap' === $x['id'] ) { t_ok( false !== strpos( $x['what'], 'imported data' ) && false !== strpos( $x['what'], 'Search Console, last 3 months' ), 'finding names the data source and period' ); t_ok( false !== strpos( $x['why'], 'do not mean the page ranks' ), 'does not equate impressions with rankings' ); }
}
t_eq( 'info', check_status( $a, 'sd_present' ), 'data present is informational' );

t_group( 'Report: criteria and CSV' );
$a = SEO_Pilot_Audit::analyze( $page( array( 'content' => '<h2>A</h2><p>t</p><h4>Skip</h4><img src="x.jpg">', 'title' => '' ) ) );
$crit = SEO_Pilot_Report::criteria( $a );
$by = array(); foreach ( $crit as $c ) { $by[ $c['key'] ] = $c; }
t_eq( 11, count( $crit ), 'all criteria listed' );
t_eq( 'warn', $by['structure']['status'], 'heading skip makes Heading structure "warn"' );
t_ok( false !== strpos( implode( ' ', $by['structure']['influenced_by'] ), 'logical hierarchy' ), 'criterion lists exactly which check influenced it' );
t_eq( 'fail', $by['images']['status'], 'missing alt makes Images "fail"' );
t_eq( 'fail', $by['meta']['status'], 'empty title makes Title/meta "fail"' );
t_ok( isset( $by['verify'] ) && in_array( $by['verify']['status'], array( 'pass', 'manual' ), true ), 'verification criterion present' );
$a2 = SEO_Pilot_Audit::analyze( $page() );
$agg = SEO_Pilot_Report::site_report( array( $a, $a2 ) );
t_eq( 2, $agg['pages'], 'site report counts pages' );
t_ok( $agg['severity']['high'] >= 1 && ! empty( $agg['top_issues'] ) && ! empty( $agg['worst_pages'] ), 'site report aggregates severity, top issues and worst pages' );
t_eq( 1, $agg['worst_pages'][0]['post_id'] === $a['post_id'] ? 1 : 0, 'worst page sorted first' );

t_eq( "'=SUM(A1)", SEO_Pilot_Report::csv_cell( '=SUM(A1)' ), 'CSV: formula cell neutralised' );
t_eq( "'+1", SEO_Pilot_Report::csv_cell( '+1' ), 'CSV: plus neutralised' );
t_eq( "'@x", SEO_Pilot_Report::csv_cell( '@x' ), 'CSV: at-sign neutralised' );
t_eq( 'plain', SEO_Pilot_Report::csv_cell( 'plain' ), 'CSV: normal text untouched' );
$a3 = $a; $a3['title'] = '=HYPERLINK("http://evil","x")'; $a3['findings'][0]['what'] = "Line one\nمرحبا, \"quoted\"";
$csv = SEO_Pilot_Report::csv( array( $a3 ) );
t_ok( 0 === strpos( $csv, "\xEF\xBB\xBF" ), 'CSV starts with a BOM for Excel/Arabic' );
t_ok( false === strpos( $csv, ',=HYPERLINK' ) && false !== strpos( $csv, "'=HYPERLINK" ), 'CSV: formula in a page title is neutralised' );
$fh = fopen( 'php://temp', 'r+' ); fwrite( $fh, substr( $csv, 3 ) ); rewind( $fh ); $hdr = fgetcsv( $fh ); $row = fgetcsv( $fh );
t_eq( 13, count( $hdr ), 'CSV header columns' ); t_eq( 13, count( $row ), 'CSV rows parse back to the same width (quotes/newlines/Arabic escaped correctly)' );
t_ok( false !== strpos( $row[7], 'مرحبا' ), 'Arabic survives CSV round trip' );

function wp_json_encode_compat( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }
