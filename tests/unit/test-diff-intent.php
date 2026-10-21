<?php
t_group( 'Diff' );
$h = SEO_Pilot_Diff::html( 'a b c', 'a x c' );
t_ok( false !== strpos( $h, '<del class="seo-pilot-del">b</del>' ) && false !== strpos( $h, '<ins class="seo-pilot-ins">x</ins>' ), 'word-level del/ins' );
t_eq( 'same text', SEO_Pilot_Diff::html( 'same text', 'same text' ), 'identical input has no markup' );
t_ok( false === strpos( SEO_Pilot_Diff::html( 'x', '<script>alert(1)</script>' ), '<script>' ), 'diff output is escaped' );
$big_a = implode( ' ', range( 1, 1500 ) ); $big_b = implode( ' ', range( 2, 1501 ) );
t_ok( strlen( SEO_Pilot_Diff::html( $big_a, $big_b ) ) > 0, 'very large input falls back without exhausting memory' );
t_ok( SEO_Pilot_Diff::changed( 'a', 'b' ) && ! SEO_Pilot_Diff::changed( 'a', 'a' ), 'changed()' );

t_group( 'Intent estimate' );
t_eq( 'informational', SEO_Pilot_Intent::estimate( 'How to bake bread at home' )['label'], 'informational (English)' );
t_eq( 'transactional', SEO_Pilot_Intent::estimate( 'Buy running shoes - best price' )['label'], 'transactional beats commercial when stronger' );
t_eq( 'commercial', SEO_Pilot_Intent::estimate( 'Best laptops compared', array( 'Top picks', 'Review of each' ) )['label'], 'commercial' );
t_eq( 'navigational', SEO_Pilot_Intent::estimate( 'Contact us' )['label'], 'navigational' );
t_eq( 'informational', SEO_Pilot_Intent::estimate( 'كيف أبدأ التسويق الإلكتروني' )['label'], 'informational (Arabic)' );
t_eq( 'transactional', SEO_Pilot_Intent::estimate( 'اشتري هاتف بأفضل سعر' )['label'], 'transactional (Arabic)' );
t_eq( 'undetermined', SEO_Pilot_Intent::estimate( 'Our story' )['label'], 'no signals => undetermined' );
$e = SEO_Pilot_Intent::estimate( 'How to guide: why and what is' );
t_ok( 'high' !== $e['confidence'] && 'rule_based_estimate' === $e['source'], 'never high confidence; labelled as an estimate' );
t_ok( false !== strpos( $e['note'], 'not search data' ), 'estimate note says it is not search data' );
