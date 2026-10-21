<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rule-based search-intent estimate for English and Arabic. It is a heuristic over the
 * title, headings and keywords — NOT search data, and never reported with high confidence.
 */
final class SEO_Pilot_Intent {

    public static function labels() {
        return array( 'informational', 'navigational', 'commercial', 'transactional' );
    }

    /**
     * Wording that signals each intent. English and Arabic are built in; a site can add other languages with
     * the seo_pilot_intent_phrases filter (an array of intent label => list of phrases, in the same shape).
     */
    private static function phrases() {
        $built = self::builtin_phrases();
        if ( ! function_exists( 'apply_filters' ) ) { return $built; }
        $f = apply_filters( 'seo_pilot_intent_phrases', $built );
        if ( ! is_array( $f ) ) { return $built; }
        $out = array();
        foreach ( self::labels() as $label ) {
            $out[ $label ] = ( isset( $f[ $label ] ) && is_array( $f[ $label ] ) ) ? array_values( array_filter( $f[ $label ], 'is_string' ) ) : $built[ $label ];
        }
        return $out;
    }

    private static function builtin_phrases() {
        return array(
            'informational' => array( 'how to', 'how do', 'how does', 'how can', 'what is', 'what are', 'why', 'guide', 'tutorial', 'tips', 'explained', 'learn', 'examples', 'meaning', 'definition', 'steps', 'checklist', 'difference between',
                'كيف', 'ما هو', 'ما هي', 'ماذا', 'لماذا', 'دليل', 'شرح', 'طريقة', 'طرق', 'نصائح', 'تعريف', 'معنى', 'خطوات', 'الفرق بين' ),
            'commercial'    => array( 'best', 'top', 'vs', 'versus', 'review', 'reviews', 'comparison', 'compare', 'alternatives', 'pros and cons',
                'أفضل', 'مقارنة', 'مراجعة', 'بدائل', 'تقييم', 'مميزات وعيوب' ),
            'transactional' => array( 'buy', 'price', 'pricing', 'prices', 'order', 'discount', 'coupon', 'deal', 'shop', 'purchase', 'hire', 'book now', 'quote', 'free trial', 'sign up', 'subscribe', 'download',
                'شراء', 'اشتري', 'سعر', 'أسعار', 'اطلب', 'خصم', 'عرض سعر', 'تسوق', 'احجز', 'اشتراك', 'تحميل' ),
            'navigational'  => array( 'login', 'log in', 'sign in', 'contact', 'contact us', 'official', 'website', 'support', 'careers', 'about us', 'address', 'locations',
                'تسجيل الدخول', 'اتصل بنا', 'تواصل معنا', 'الموقع الرسمي', 'من نحن', 'الدعم', 'وظائف', 'العنوان', 'فروعنا' ),
        );
    }

    /**
     * @param string   $title     Post title.
     * @param string[] $headings  Heading texts.
     * @param string   $primary   Primary keyword (optional).
     * @param string[] $secondary Secondary keywords (optional).
     * @return array label, confidence, signals, scores, source, note
     */
    public static function estimate( $title, array $headings = array(), $primary = '', array $secondary = array() ) {
        $strong = trim( $title . ' ' . $primary . ' ' . implode( ' ', $secondary ) );
        $weak   = implode( ' ', $headings );
        $scores = array();
        $found  = array();
        foreach ( self::phrases() as $label => $list ) {
            $scores[ $label ] = 0;
            $found[ $label ]  = array();
            foreach ( $list as $phrase ) {
                $hit = false;
                if ( SEO_Pilot_Text::has_phrase( $strong, $phrase ) ) { $scores[ $label ] += 2; $hit = true; }
                if ( '' !== $weak && SEO_Pilot_Text::has_phrase( $weak, $phrase ) ) { $scores[ $label ] += 1; $hit = true; }
                if ( $hit ) { $found[ $label ][] = $phrase; }
            }
        }
        arsort( $scores );
        $keys   = array_keys( $scores );
        $top    = $keys[0];
        $second = $keys[1];
        $note   = 'Rule-based estimate from the title, headings and keywords. It is not search data and can be wrong; confirm the intent yourself.';

        if ( 0 === $scores[ $top ] ) {
            return array( 'label' => 'undetermined', 'confidence' => 'low', 'signals' => array(), 'scores' => $scores, 'source' => 'rule_based_estimate', 'note' => $note );
        }
        $confidence = ( $scores[ $top ] >= 2 && $scores[ $top ] - $scores[ $second ] >= 2 ) ? 'medium' : 'low';
        return array(
            'label'      => $top,
            'confidence' => $confidence,
            'signals'    => array_filter( $found ),
            'scores'     => $scores,
            'source'     => 'rule_based_estimate',
            'note'       => $note,
        );
    }

    /**
     * Does the page structure plausibly serve the intent? Heuristic checklist rows;
     * items a machine cannot judge are returned with status "manual".
     *
     * @return array[] id, label, status (pass|warn|manual), detail
     */
    public static function alignment( $label, array $parsed, $text ) {
        $rows = array();
        $text = (string) $text;
        switch ( $label ) {
            case 'informational':
                $ok     = count( $parsed['headings'] ) >= 2 || $parsed['lists'] > 0;
                $rows[] = array( 'id' => 'intent_structure', 'label' => 'Informational page is organised into scannable sections', 'status' => $ok ? 'pass' : 'warn',
                    'detail' => $ok ? 'Headings or lists were found.' : 'Few headings and no lists were found; informational searchers usually scan for sections or steps.' );
                $rows[] = array( 'id' => 'intent_answer', 'label' => 'The introduction answers the main question directly', 'status' => 'manual', 'detail' => 'Needs a human reader.' );
                break;
            case 'commercial':
                $cue    = $parsed['tables'] > 0 || $parsed['lists'] > 0 || SEO_Pilot_Text::has_phrase( $text, 'vs' ) || SEO_Pilot_Text::has_phrase( $text, 'pros' ) || SEO_Pilot_Text::has_phrase( $text, 'مقارنة' );
                $rows[] = array( 'id' => 'intent_compare', 'label' => 'Options are compared in a structured way', 'status' => $cue ? 'pass' : 'warn',
                    'detail' => $cue ? 'A table, list or comparison wording was found.' : 'No table, list or comparison wording was found.' );
                $rows[] = array( 'id' => 'intent_fair', 'label' => 'The comparison is fair and its claims are supported', 'status' => 'manual', 'detail' => 'Needs a human reader.' );
                break;
            case 'transactional':
                $cta    = false;
                foreach ( array( 'buy', 'order', 'price', 'contact', 'quote', 'book', 'shop', 'اطلب', 'سعر', 'اشتري', 'احجز', 'تواصل', 'اتصل' ) as $w ) {
                    if ( SEO_Pilot_Text::has_phrase( $text, $w ) ) { $cta = true; break; }
                }
                $rows[] = array( 'id' => 'intent_cta', 'label' => 'A clear next step (buy, order, contact, book) is present', 'status' => $cta ? 'pass' : 'warn',
                    'detail' => $cta ? 'Action wording was found in the text.' : 'No action wording was found in the stored text (a button or form from a page builder would not be seen).' );
                $rows[] = array( 'id' => 'intent_trust', 'label' => 'Pricing, terms and trust details are easy to find', 'status' => 'manual', 'detail' => 'Needs a human reader.' );
                break;
            case 'navigational':
                $rows[] = array( 'id' => 'intent_nav', 'label' => 'Visitors reach the destination or contact details quickly', 'status' => 'manual', 'detail' => 'Needs a human reader.' );
                break;
            default:
                $rows[] = array( 'id' => 'intent_unknown', 'label' => 'Search intent is confirmed by a human', 'status' => 'manual', 'detail' => 'No intent could be estimated; choose one in the workspace.' );
        }
        return $rows;
    }
}
