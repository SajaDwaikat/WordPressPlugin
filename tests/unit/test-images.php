<?php
t_group( 'Image alt-text analysis' );

function img_row( array $o = array() ) {
    return array_merge( array( 'src' => 'https://example.com/uploads/a.jpg', 'has_alt' => true, 'alt' => 'A red bicycle leaning on a brick wall', 'context' => 'Our bikes',
        'in_link' => false, 'link_text' => '', 'caption' => '', 'attachment_id' => 0 ), $o );
}
function img_issue_ids( array $item ) { return array_map( function ( $i ) { return $i['id']; }, $item['issues'] ); }

/* ---- parser: the extra context ---- */
$html = '<h2>Gallery</h2><figure class="wp-block-image"><img class="wp-image-42" src="a.jpg" alt="Cat"><figcaption>A sleepy cat</figcaption></figure>'
    . '<a href="/shop"><img src="logo.png" alt=""></a><a href="/x"><img src="b.png" alt="">Read more</a><p><img src="c.png" width="10" height="20"></p>';
$p = SEO_Pilot_Parser::parse( $html );
t_eq( 4, count( $p['images'] ), 'parser sees all four images' );
t_eq( 42, $p['images'][0]['attachment_id'], 'parser reads the attachment id from the wp-image-N class' );
t_eq( 'A sleepy cat', $p['images'][0]['caption'], 'parser reads the figure caption' );
t_eq( 'Gallery', $p['images'][0]['context'], 'parser keeps the heading context' );
t_ok( $p['images'][1]['in_link'] && '' === $p['images'][1]['link_text'], 'image that is the only content of a link: in_link, no link text' );
t_ok( $p['images'][2]['in_link'] && 'Read more' === $p['images'][2]['link_text'], 'link text next to the image is captured' );
t_ok( ! $p['images'][3]['in_link'] && 0 === $p['images'][3]['attachment_id'], 'plain image is not in a link' );

/* ---- parser: replacing an existing alt ---- */
$c = '<img src="a.jpg" alt="IMG_1234"><img src="a.jpg" alt="Other"><img src="b.jpg" alt="IMG_1234"><img data-src="a.jpg" alt="IMG_1234">';
list( $out, $n ) = SEO_Pilot_Parser::replace_img_alt( $c, 'a.jpg', 'IMG_1234', 'A "red" bicycle $5 \\ new' );
t_eq( 2, $n, 'alt replaced on every image with that src AND that exact old alt (src or data-src)' );
t_ok( false !== strpos( $out, '<img src="a.jpg" alt="Other">' ) && false !== strpos( $out, '<img src="b.jpg" alt="IMG_1234">' ), 'other images are untouched' );
t_ok( false !== strpos( $out, 'alt="A &quot;red&quot; bicycle $5 \\ new"' ), 'new alt is escaped and special replacement characters are literal' );
list( $same, $n0 ) = SEO_Pilot_Parser::replace_img_alt( $c, 'a.jpg', 'Something else', 'x' );
t_eq( 0, $n0, 'replacement refused when the current alt is not what the suggestion was made against' );
t_eq( $c, $same, 'content unchanged on refusal' );
list( $e, $ne ) = SEO_Pilot_Parser::replace_img_alt( '<img src="a.jpg" alt="">', 'a.jpg', '', 'Now described' );
t_eq( 1, $ne, 'an empty alt can be replaced' );
list( $none, $nn ) = SEO_Pilot_Parser::replace_img_alt( '<img src="a.jpg">', 'a.jpg', '', 'x' );
t_eq( 0, $nn, 'replace never adds an alt attribute (that is set_img_alt)' );
list( $dec, $nd ) = SEO_Pilot_Parser::set_img_alt( '<img src="a.jpg">', 'a.jpg', '' );
t_ok( 1 === $nd && false !== strpos( $dec, 'alt=""' ), 'set_img_alt can write an empty (decorative) alt' );

/* ---- alt quality flags ---- */
$ids = function ( $alt, $title = '', $cap = '' ) { return array_map( function ( $i ) { return $i['id']; }, SEO_Pilot_Images::alt_flags( $alt, $title, $cap ) ); };
t_eq( array(), $ids( 'A red bicycle leaning on a brick wall' ), 'a good alt text raises nothing' );
t_ok( in_array( 'filename', $ids( 'IMG_20240101.jpg' ), true ), 'file-name alt' );
t_ok( in_array( 'too_long', $ids( str_repeat( 'long description ', 12 ) ), true ), 'over 125 characters' );
t_ok( in_array( 'redundant_prefix', $ids( 'Image of a red bicycle' ), true ), '"image of" prefix' );
t_ok( in_array( 'redundant_prefix', $ids( 'صورة لدراجة حمراء' ), true ), 'Arabic "صورة" prefix' );
t_ok( in_array( 'generic', $ids( 'Photo' ), true ), 'generic single word' );
t_ok( in_array( 'has_url', $ids( 'Great bikes at https://shop.example.com now' ), true ), 'URL in alt' );
t_ok( in_array( 'repeated_words', $ids( 'bike shop bike store bike repair bike' ), true ), 'word repeated three times' );
t_ok( in_array( 'repeated_words', $ids( 'bikes, cheap bikes, buy bikes, best bikes, bike shop' ), true ), 'long keyword list' );
t_ok( in_array( 'same_as_title', $ids( 'Our Bikes', 'our bikes' ), true ), 'alt identical to the page title' );
t_ok( in_array( 'same_as_caption', $ids( 'A sleepy cat', '', 'A sleepy cat' ), true ), 'alt identical to the caption' );
t_eq( array(), $ids( '' ), 'empty alt has no quality flags (it is judged elsewhere)' );
t_eq( 'heuristic', SEO_Pilot_Images::alt_flags( 'Photo' )[0]['basis'], 'quality flags are labelled as rules of thumb' );

/* ---- analysis ---- */
$r = SEO_Pilot_Images::analyze( array( img_row( array( 'has_alt' => false, 'alt' => '' ) ) ) );
t_eq( 'missing', $r['items'][0]['kind'], 'no alt attribute: missing' );
t_ok( $r['items'][0]['needs_attention'] && 1 === $r['counts']['missing'], 'missing counts as needing attention' );
$r = SEO_Pilot_Images::analyze( array( img_row( array( 'alt' => '' ) ) ) );
t_eq( 'decorative', $r['items'][0]['kind'], 'empty alt outside a link is treated as decorative' );
t_ok( ! $r['items'][0]['needs_attention'] && 1 === $r['counts']['decorative'], 'decorative needs nothing' );
$r = SEO_Pilot_Images::analyze( array( img_row( array( 'alt' => '', 'in_link' => true, 'link_text' => '' ) ) ) );
t_eq( 'empty_in_link', $r['items'][0]['kind'], 'empty alt as the only content of a link is a problem' );
t_ok( in_array( 'empty_in_link', img_issue_ids( $r['items'][0] ), true ) && $r['items'][0]['needs_attention'], 'it needs attention' );
$r = SEO_Pilot_Images::analyze( array( img_row( array( 'alt' => '', 'in_link' => true, 'link_text' => 'Pricing' ) ) ) );
t_eq( 'decorative', $r['items'][0]['kind'], 'empty alt inside a link that has text is fine' );

$r = SEO_Pilot_Images::analyze( array( img_row( array( 'src' => '1.jpg', 'alt' => 'Shop front' ) ), img_row( array( 'src' => '2.jpg', 'alt' => 'Shop front' ) ) ) );
t_ok( in_array( 'duplicate', img_issue_ids( $r['items'][0] ), true ) && in_array( 'duplicate', img_issue_ids( $r['items'][1] ), true ), 'same alt on two different images is flagged on both' );
$r = SEO_Pilot_Images::analyze( array( img_row( array( 'src' => '1.jpg' ) ), img_row( array( 'src' => '1.jpg' ) ) ) );
t_eq( 1, count( $r['items'] ), 'the same image with the same alt twice is one row' );
t_ok( ! in_array( 'duplicate', img_issue_ids( $r['items'][0] ), true ), 'and is not a duplicate-alt problem' );

$data = array( 'by_id' => array( 7 => array( 'library_alt' => 'Library description', 'filesize' => 900000 ), 8 => array( 'library_alt' => 'Different', 'filesize' => 1000 ) ),
    'featured' => array( 'id' => 9, 'alt' => '', 'filesize' => 0 ) );
$r = SEO_Pilot_Images::analyze( array(
    img_row( array( 'src' => 'm.jpg', 'has_alt' => false, 'alt' => '', 'attachment_id' => 7 ) ),
    img_row( array( 'src' => 'n.jpg', 'alt' => 'Page wording', 'attachment_id' => 8 ) ),
), array( 'image_data' => $data ) );
$a = img_issue_ids( $r['items'][0] );
t_ok( in_array( 'library_available', $a, true ) && 'Library description' === $r['items'][0]['library_alt'], 'a missing alt with Media Library text is noted and the text is kept' );
t_ok( in_array( 'large_file', $a, true ), 'a large file recorded by WordPress is noted' );
t_ok( in_array( 'library_differs', img_issue_ids( $r['items'][1] ), true ) && ! $r['items'][1]['needs_attention'], 'a differing library alt is informational only' );
t_eq( 9, $r['featured']['id'], 'featured image is carried through' );

$r = SEO_Pilot_Images::analyze( array( img_row( array( 'src' => '' ) ), img_row() ) );
t_eq( 1, count( $r['items'] ), 'images without a src are ignored' );
t_eq( 'a.jpg', SEO_Pilot_Images::label( 'https://example.com/wp-content/uploads/2024/05/a.jpg?x=1' ), 'label shows the file name' );

/* ---- audit integration ---- */
function img_audit( $body, array $extra = array() ) {
    return SEO_Pilot_Audit::analyze( array_merge( array( 'id' => 1, 'title' => 'Our bikes', 'url' => 'https://example.com/b/', 'content' => $body, 'post_type' => 'post', 'site_host' => 'example.com', 'year' => 2026 ), $extra ) );
}
$a = img_audit( '<p>Text</p><img src="a.jpg"><img src="b.jpg" alt="IMG_1"><a href="/x"><img src="c.jpg" alt=""></a><img src="d.jpg" alt="Photo of the bike shop">' );
$f = finding_ids( $a );
t_ok( in_array( 'alt_missing', $f, true ) && in_array( 'alt_suspicious', $f, true ), 'original findings are kept' );
t_ok( in_array( 'alt_empty_in_link', $f, true ), 'new finding: linked image with empty alt' );
t_ok( in_array( 'alt_quality', $f, true ), 'new finding: alt quality' );
t_eq( 'fail', check_status( $a, 'alt_text' ), 'checklist: missing alt still fails' );
t_ok( isset( $a['image_analysis']['items'] ) && 4 === count( $a['image_analysis']['items'] ), 'audit stores the image analysis' );
$ev = null; foreach ( $a['findings'] as $x ) { if ( 'alt_missing' === $x['id'] ) { $ev = $x['evidence'][0]; } }
t_ok( isset( $ev['src'], $ev['hash'], $ev['context'] ), 'alt_missing evidence keeps its original shape' );
$a = img_audit( '<p>Text</p><img src="a.jpg" alt="A red bicycle leaning on a wall"><img src="b.jpg" alt="">' );
t_eq( 'pass', check_status( $a, 'alt_text' ), 'good alt plus a decorative image passes' );
$a = img_audit( '<img src="a.jpg" alt="Shop front"><img src="b.jpg" alt="Shop front">' );
t_ok( in_array( 'alt_duplicate', finding_ids( $a ), true ) && 'warn' === check_status( $a, 'alt_text' ), 'duplicate alt warns' );
$a = img_audit( '<img src="a.jpg" alt="A bicycle">', array( 'image_data' => array( 'by_id' => array(), 'featured' => array( 'id' => 5, 'alt' => '' ) ) ) );
t_ok( in_array( 'featured_alt_missing', finding_ids( $a ), true ), 'featured image without library alt is reported (info)' );
t_eq( 'info', (function () use ( $a ) { foreach ( $a['findings'] as $x ) { if ( 'featured_alt_missing' === $x['id'] ) { return $x['severity']; } } return null; })(), 'and is only informational' );

/* ---- AI drafts: validator and prompt ---- */
$map = array(
    'I1' => array( 'src' => 'a.jpg', 'hash' => SEO_Pilot_Images::hash( 'a.jpg' ), 'has_alt' => false, 'alt' => '', 'kind' => 'missing', 'caption' => '' ),
    'I2' => array( 'src' => 'b.jpg', 'hash' => SEO_Pilot_Images::hash( 'b.jpg' ), 'has_alt' => true, 'alt' => 'IMG_5', 'kind' => 'has_alt', 'caption' => '' ),
    'I3' => array( 'src' => 'c.jpg', 'hash' => SEO_Pilot_Images::hash( 'c.jpg' ), 'has_alt' => true, 'alt' => '', 'kind' => 'empty_in_link', 'caption' => '' ),
    'I4' => array( 'src' => 'd.jpg', 'hash' => SEO_Pilot_Images::hash( 'd.jpg' ), 'has_alt' => false, 'alt' => '', 'kind' => 'missing', 'caption' => '' ),
);
$ai = wp_json_encode( array( 'images' => array(
    array( 'id' => 'I1', 'alt' => 'Image of a red bicycle with 3 gears', 'decorative' => false, 'reason' => 'shows the product', 'verify' => array() ),
    array( 'id' => 'I2', 'alt' => 'A <b>blue</b> kettle on a stove', 'decorative' => false, 'reason' => 'r', 'verify' => array( 'check colour' ) ),
    array( 'id' => 'I3', 'alt' => '', 'decorative' => true, 'reason' => 'looks like a logo', 'verify' => array() ),
    array( 'id' => 'I4', 'alt' => '', 'decorative' => false, 'reason' => 'cannot tell', 'verify' => array() ),
    array( 'id' => 'I9', 'alt' => 'unknown id', 'decorative' => false ),
), 'warnings' => array() ) );
$p = SEO_Pilot_Validator::parse_alt( $ai, array( 'images' => $map, 'page_title' => 'Our bikes', 'context_text' => 'Our bikes' ) );
t_ok( ! is_wp_error( $p ), 'alt drafts parse' );
t_eq( 2, count( $p['recs'] ), 'one draft per usable image: unknown id, undecidable image and decorative-in-link are skipped' );
$r1 = $p['recs'][0];
t_ok( 'alt_text' === $r1['rec_type'] && 'img:' . $map['I1']['hash'] === $r1['target'] && 'a.jpg' === $r1['original'], 'draft targets the right image' );
t_eq( 'add', $r1['flags']['mode'], 'missing alt: add mode' );
t_ok( ! empty( array_filter( $r1['flags']['notes'], function ( $n ) { return false !== strpos( $n, 'image of' ); } ) ), 'a redundant "image of" opening is flagged for the editor' );
t_ok( ! empty( array_filter( $r1['flags']['notes'], function ( $n ) { return false !== strpos( $n, 'figure' ); } ) ), 'a number not found in the page context is flagged' );
t_ok( false !== strpos( $r1['flags']['verify'][0], 'compare it with the image' ), 'every draft asks the editor to compare it with the image' );
$r2 = $p['recs'][1];
t_eq( 'replace', $r2['flags']['mode'], 'existing poor alt: replace mode' );
t_eq( 'IMG_5', $r2['flags']['old_alt'], 'the alt being replaced is recorded' );
t_eq( 'A blue kettle on a stove', $r2['proposed'], 'markup in a draft is stripped' );
t_ok( ! empty( array_filter( $p['warnings'], function ( $w ) { return false !== strpos( $w, 'only content of a link' ); } ) ), 'decorative suggestion for a linked image is refused with a warning' );
$dec = SEO_Pilot_Validator::parse_alt( wp_json_encode( array( 'images' => array( array( 'id' => 'I1', 'alt' => '', 'decorative' => true, 'reason' => 'divider' ) ) ) ), array( 'images' => $map ) );
t_ok( true === $dec['recs'][0]['flags']['decorative'] && '' === $dec['recs'][0]['proposed'], 'decorative draft has an empty proposal and the decorative flag' );
t_ok( is_wp_error( SEO_Pilot_Validator::parse_alt( 'not json', array( 'images' => $map ) ) ), 'invalid JSON is rejected' );

$built = SEO_Pilot_Prompt::build_alt( array( 'page_title' => 'Our bikes', 'language' => 'ar', 'explain_lang' => 'en' ), array(
    'I1' => array( 'id' => 'I1', 'data_url' => 'data:image/jpeg;base64,AAAA', 'context' => 'Gallery', 'caption' => '', 'in_link' => true, 'link_text' => '', 'current_alt' => '' ),
) );
t_eq( 'system', $built['messages'][0]['role'], 'alt request has a system message' );
t_ok( is_array( $built['messages'][1]['content'] ) && 'image_url' === $built['messages'][1]['content'][2]['type'], 'user message is a list of parts that includes the image' );
$ptxt = $built['messages'][1]['content'][0]['text'];
t_ok( false !== strpos( $ptxt, 'Gallery' ) && false !== strpos( $ptxt, 'only content of the link' ), 'section heading and link context are included' );
t_ok( false === stripos( $ptxt, 'keyword' ), 'keywords are never part of the alt request' );
t_ok( false !== strpos( $built['messages'][0]['content'], 'Arabic' ) && false !== strpos( $built['messages'][0]['content'], 'untrusted' ), 'language and untrusted-data rules are in the system prompt' );
