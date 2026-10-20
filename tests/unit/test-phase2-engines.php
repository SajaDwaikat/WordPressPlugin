<?php
t_group( 'Similarity (near-duplicate detection)' );
$base = ''; for ( $i = 1; $i <= 40; $i++ ) { $base .= "Sentence number $i talks about baking sourdough bread with flour water and salt in a warm kitchen. "; }
$a = SEO_Pilot_Similarity::sketch( $base );
t_ok( count( $a['s'] ) > 20, 'sketch built for long text' );
t_eq( 1.0, SEO_Pilot_Similarity::jaccard( $a, SEO_Pilot_Similarity::sketch( $base ) ), 'identical text = 1.0' );
$edited = str_replace( 'Sentence number 7 talks', 'Sentence number 7 now speaks', $base );
$j = SEO_Pilot_Similarity::jaccard( $a, SEO_Pilot_Similarity::sketch( $edited ) );
t_ok( $j > 0.8 && $j < 1.0, 'one sentence changed: still highly similar (' . $j . ')' );
$other = ''; for ( $i = 1; $i <= 40; $i++ ) { $other .= "Item $i describes cycling routes through mountain passes with careful gear checks before every long ride today. "; }
t_ok( SEO_Pilot_Similarity::jaccard( $a, SEO_Pilot_Similarity::sketch( $other ) ) < 0.1, 'unrelated text is not similar' );
$short = SEO_Pilot_Similarity::sketch( 'Too short to compare.' );
t_eq( array(), $short['s'], 'short text is not sketched (avoids false positives)' );
t_eq( 0.0, SEO_Pilot_Similarity::jaccard( $short, $a ), 'short text never matches' );
$arA = ''; for ( $i = 1; $i <= 40; $i++ ) { $arA .= "الفقرة رقم $i تتحدث عن تحسين محركات البحث للمواقع الصغيرة والمتوسطة بطريقة واضحة ومبسطة. "; }
$arB = $arA . ' جملة إضافية واحدة في النهاية.';
t_ok( SEO_Pilot_Similarity::jaccard( SEO_Pilot_Similarity::sketch( $arA ), SEO_Pilot_Similarity::sketch( $arB ) ) > 0.8, 'Arabic near-duplicates detected' );
$found = SEO_Pilot_Similarity::find_similar( $a, array( 1 => array( 'title' => 'Self', 'sketch' => $a ), 2 => array( 'title' => 'Near', 'sketch' => SEO_Pilot_Similarity::sketch( $edited ) ), 3 => array( 'title' => 'Far', 'sketch' => SEO_Pilot_Similarity::sketch( $other ) ) ), 1 );
t_eq( 1, count( $found ), 'find_similar excludes self and unrelated pages' );
t_eq( 'Near', $found[0]['title'], 'find_similar returns the near-duplicate' );

t_group( 'Content gap (site-internal)' );
$target = 'We bake sourdough bread with flour and water every morning in our small bakery.';
$others = array(
    1 => 'Sourdough starter care: feed the starter daily with flour and water, watch hydration and fermentation temperature closely. Sourdough fermentation needs patience.',
    2 => 'Baking sourdough bread: hydration, fermentation and proofing decide the crumb. Proofing baskets help shape dough; fermentation overnight in the fridge.',
    3 => 'Mountain cycling routes with gear checks and long climbs through alpine passes.',
);
$rel = SEO_Pilot_Gap::related( $target, $others, 5, 0.1 );
t_ok( isset( $rel[1] ) && isset( $rel[2] ) && ! isset( $rel[3] ), 'related pages chosen by shared vocabulary; unrelated page excluded' );
$miss = array_column( SEO_Pilot_Gap::missing_terms( $target, array( $others[1], $others[2] ) ), 'term' );
t_ok( in_array( 'fermentation', $miss, true ) && in_array( 'hydration', $miss, true ), 'terms used by related pages but absent here are listed' );
t_ok( ! in_array( 'sourdough', $miss, true ) && ! in_array( 'flour', $miss, true ), 'terms the page already uses are not listed' );
t_eq( 1.0, round( SEO_Pilot_Gap::cosine( array( 'a' => 1 ), array( 'a' => 3 ) ), 2 ), 'cosine of parallel vectors' );

t_group( 'Internal link phrase insertion' );
$n = SEO_Pilot_Links::link_phrase( 'Read our <strong>sourdough starter guide</strong> and the Sourdough Starter Guide again.', 'sourdough starter guide', 'https://x.test/guide/' );
t_eq( 'Read our <strong><a href="https://x.test/guide/">sourdough starter guide</a></strong> and the Sourdough Starter Guide again.', $n, 'first occurrence linked, case preserved, tags intact' );
t_eq( null, SEO_Pilot_Links::link_phrase( 'See <a href="/a">sourdough starter guide</a> here.', 'sourdough starter guide', '/b/' ), 'text already inside a link is never re-linked' );
t_eq( 'See <a href="/a">x</a> and <a href="/b/">sourdough starter guide</a>.', SEO_Pilot_Links::link_phrase( 'See <a href="/a">x</a> and sourdough starter guide.', 'sourdough starter guide', '/b/' ), 'a free occurrence after an existing link is used' );
t_eq( null, SEO_Pilot_Links::link_phrase( 'Resourdough starter guides', 'sourdough starter guide', '/b/' ), 'no match inside a longer word' );
t_eq( null, SEO_Pilot_Links::link_phrase( 'text', 'abc', '/b/' ), 'too-short phrases are refused' );
$q = SEO_Pilot_Links::link_phrase( 'عن تحسين محركات البحث اليوم', 'تحسين محركات البحث', 'https://x.test/seo/' );
t_ok( null !== $q && false !== strpos( $q, '<a href="https://x.test/seo/">تحسين محركات البحث</a>' ), 'Arabic phrase linked' );
t_ok( false !== strpos( SEO_Pilot_Links::link_phrase( 'A phrase here.', 'phrase here', 'https://x.test/?a=1&b="2"' ), 'href="https://x.test/?a=1&amp;b=&quot;2&quot;"' ), 'URL is attribute-escaped' );

$segs = array(
    array( 'id' => 'S1', 'hash' => 'h1', 'inner' => 'We love baking and our sourdough starter guide helps beginners.' ),
    array( 'id' => 'S2', 'hash' => 'h2', 'inner' => 'Read about <a href="/x">cycling</a> and <a href="/y">hiking</a> and cycling routes too.' ),
    array( 'id' => 'S3', 'hash' => 'h3', 'inner' => 'Our favourite Mountain Cycling Routes are listed elsewhere.' ),
);
$targets = array(
    array( 'id' => 10, 'title' => 'Sourdough Starter Guide | My Bakery', 'url' => 'https://x.test/starter/', 'primary' => '', 'secondary' => array() ),
    array( 'id' => 11, 'title' => 'Mountain Cycling Routes', 'url' => 'https://x.test/routes/', 'primary' => 'cycling routes', 'secondary' => array() ),
    array( 'id' => 12, 'title' => 'Not mentioned anywhere', 'url' => 'https://x.test/none/', 'primary' => '', 'secondary' => array() ),
    array( 'id' => 13, 'title' => 'Hiking page already linked', 'url' => 'https://x.test/y/', 'primary' => 'hiking', 'secondary' => array() ),
);
$sug = SEO_Pilot_Links::suggest( $segs, array( 'https://x.test/y/' ), $targets );
t_eq( 2, count( $sug ), 'two real opportunities found; unmentioned and already-linked pages skipped' );
t_eq( 'sourdough starter guide', strtolower( $sug[0]['phrase'] ), 'title (minus " | Site") used as the anchor because it already appears in the text' );
t_ok( false !== strpos( $sug[0]['proposed'], '<a href="https://x.test/starter/">sourdough starter guide</a>' ), 'suggestion keeps the page\'s own wording' );
t_ok( $sug[1]['segment_hash'] !== $sug[0]['segment_hash'], 'one new link per paragraph' );
t_eq( 'S3', $sug[1]['segment_id'], 'paragraph that already has two links is not used' );
