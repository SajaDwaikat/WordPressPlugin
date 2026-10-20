<?php
t_group( 'Text utilities (Arabic + Latin)' );

t_eq( 5, SEO_Pilot_Text::len( 'مرحبا' ), 'len counts characters, not bytes (Arabic)' );
t_ok( strlen( 'مرحبا' ) > 5, 'sanity: strlen would count bytes' );
t_eq( 'Hello & world', SEO_Pilot_Text::plain( '<!-- wp:paragraph --><p>Hello &amp; <strong>world</strong></p><!-- /wp:paragraph -->' ), 'plain() strips block comments, tags, entities' );
t_eq( 'a b', SEO_Pilot_Text::plain( "<p>a</p><p>b</p>" ), 'plain() separates paragraphs with a space' );
t_eq( 'ar', SEO_Pilot_Text::language( 'هذا نص عربي طويل بما يكفي للكشف عن اللغة' ), 'language: Arabic' );
t_eq( 'latin', SEO_Pilot_Text::language( 'This is a long enough English sentence to detect.' ), 'language: Latin' );
t_eq( 'unknown', SEO_Pilot_Text::language( 'Hi' ), 'language: too short' );
t_eq( 'احمد', SEO_Pilot_Text::normalize( 'أَحْمَد' ), 'normalize removes diacritics and unifies alef' );
t_eq( '2024', SEO_Pilot_Text::normalize( '٢٠٢٤' ), 'normalize converts Arabic-Indic digits' );
t_eq( "today's", SEO_Pilot_Text::normalize( "Today’s" ), 'normalize unifies curly apostrophes' );

t_eq( 1, SEO_Pilot_Text::count_phrase( 'SEO is great, Seoul is far', 'seo' ), 'Latin phrase respects word boundaries' );
t_eq( 2, SEO_Pilot_Text::count_phrase( 'تحسين محركات البحث وتحسين الموقع', 'تحسين' ), 'Arabic phrase matches as substring' );
t_eq( 1, SEO_Pilot_Text::count_phrase( 'والتحسين مهم', 'تحسين' ), 'Arabic phrase matches through attached prefixes' );
t_eq( 0, SEO_Pilot_Text::count_phrase( '', 'x' ), 'empty haystack' );

t_eq( 3, count( SEO_Pilot_Text::sentences( 'One here. Two here! ماذا الآن؟ ' ) ) - 0, 'sentences split on . ! and Arabic ؟' );
t_eq( array( 'Short', false ), SEO_Pilot_Text::truncate( 'Short', 10 ), 'truncate leaves short text alone' );
$tr = SEO_Pilot_Text::truncate( 'alpha beta gamma delta epsilon', 14 );
t_ok( $tr[1] && SEO_Pilot_Text::len( $tr[0] ) <= 14, 'truncate cuts at the limit' );

$nums = SEO_Pilot_Text::numbers( 'Up 85% from 1,200 to ٣٠' );
t_ok( in_array( '85', $nums, true ) && in_array( '1,200', $nums, true ) && in_array( '30', $nums, true ), 'numbers() finds figures incl. Arabic-Indic digits' );

t_ok( SEO_Pilot_Text::is_generic_anchor( 'Click here!' ), 'generic anchor: click here' );
t_ok( SEO_Pilot_Text::is_generic_anchor( 'اضغط هنا' ), 'generic anchor: Arabic' );
t_ok( ! SEO_Pilot_Text::is_generic_anchor( 'our pricing guide' ), 'descriptive anchor is not generic' );
t_ok( SEO_Pilot_Text::looks_like_filename_alt( 'IMG_20240101.jpg' ), 'filename-like alt' );
t_ok( SEO_Pilot_Text::looks_like_filename_alt( 'DSC1234' ), 'camera-name alt' );
t_ok( ! SEO_Pilot_Text::looks_like_filename_alt( 'A red bicycle leaning on a wall' ), 'real alt is fine' );

$top = SEO_Pilot_Text::top_terms( SEO_Pilot_Text::words( 'apple apple apple banana the the the and apple' ), 2 );
t_eq( 'apple', key( $top ), 'top_terms ignores stopwords' );
