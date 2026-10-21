<?php
t_group( 'Parser' );

$html = '<h2>Intro</h2><p>Some text with <a href="/about">our team</a> and <a href="https://other.com/x">other</a>.</p><img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="Cat"><h4>Deep</h4><ul><li>x</li></ul>';
$p = SEO_Pilot_Parser::parse( $html );
t_eq( 2, count( $p['headings'] ), 'headings counted' );
t_eq( 4, $p['headings'][1]['level'], 'heading level read' );
t_eq( 3, count( $p['images'] ), 'images counted' );
t_ok( ! $p['images'][0]['has_alt'] && $p['images'][1]['has_alt'] && $p['images'][2]['has_alt'], 'missing alt attribute vs empty alt distinguished' );
t_eq( 2, count( $p['links'] ), 'links counted' );
t_eq( 'our team', $p['links'][0]['text'], 'anchor text read' );
t_eq( 1, $p['lists'], 'lists counted' );
t_ok( $p['dom_ok'], 'DOM parsed' );

$ar = SEO_Pilot_Parser::parse( '<h2>مقدمة</h2><p>نص عربي بسيط.</p>' );
t_eq( 'مقدمة', $ar['headings'][0]['text'], 'Arabic heading survives DOM parsing (UTF-8)' );
t_eq( 4, $ar['word_count'], 'Arabic word count (heading + paragraph text)' );

$empty = SEO_Pilot_Parser::parse( '' );
t_eq( 0, $empty['word_count'], 'empty content' );

/* paragraph segments + replacement (classic markup) */
$classic = "<p>First paragraph here.</p>\n\n<h2>Heading</h2>\n\nPlain second paragraph with <a href=\"/x\">link</a>.\n\n<p>Third one.</p>";
$segs = SEO_Pilot_Parser::paragraph_segments( $classic );
t_eq( 3, count( $segs ), 'three paragraph segments (heading skipped)' );
t_eq( 'S2', $segs[1]['id'], 'segment ids are sequential' );
t_eq( 'Plain second paragraph with <a href="/x">link</a>.', $segs[1]['inner'], 'bare-text paragraph inner html' );

$new = SEO_Pilot_Parser::replace_paragraph( $classic, $segs[0]['hash'], 'Better first paragraph.' );
t_eq( "<p>Better first paragraph.</p>\n\n<h2>Heading</h2>\n\nPlain second paragraph with <a href=\"/x\">link</a>.\n\n<p>Third one.</p>", $new, 'only the targeted paragraph changes; wrapper kept' );

$new2 = SEO_Pilot_Parser::replace_paragraph( $classic, $segs[1]['hash'], 'Rewritten second.' );
t_ok( is_string( $new2 ) && false !== strpos( $new2, "\n\nRewritten second.\n\n" ), 'bare paragraph replaced without adding wrappers' );

$err = SEO_Pilot_Parser::replace_paragraph( $classic, sha1( 'nothing' ), 'x' );
t_ok( is_wp_error( $err ) && 'seo_pilot_segment_not_found' === $err->get_error_code(), 'changed/missing paragraph is refused' );

$dup = "<p>Same words here.</p>\n\n<p>Same words here.</p>";
$dsegs = SEO_Pilot_Parser::paragraph_segments( $dup );
$err2 = SEO_Pilot_Parser::replace_paragraph( $dup, $dsegs[0]['hash'], 'x' );
t_ok( is_wp_error( $err2 ) && 'seo_pilot_segment_ambiguous' === $err2->get_error_code(), 'duplicate paragraph text is refused as ambiguous' );

/* image alt insertion */
$c = '<img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="Cat"><img src="a.jpg">';
list( $out, $n ) = SEO_Pilot_Parser::set_img_alt( $c, 'a.jpg', 'A "quoted" <b>name</b>' );
t_eq( 2, $n, 'alt added to every matching image lacking alt' );
t_ok( false !== strpos( $out, 'alt="A &quot;quoted&quot; &lt;b&gt;name&lt;/b&gt;"' ), 'alt is HTML-escaped' );
t_ok( false !== strpos( $out, '<img src="b.jpg" alt="">' ) && false !== strpos( $out, '<img src="c.jpg" alt="Cat">' ), 'images that already have alt (even empty) are untouched' );

t_eq( array( '/x', 'https://a.com/?a=1&b=2' ), SEO_Pilot_Parser::hrefs( '<a href="/x">1</a><a class="c" href="https://a.com/?a=1&amp;b=2">2</a>' ), 'hrefs extracted and entity-decoded' );
