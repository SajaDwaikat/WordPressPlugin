<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Never trusts the model. Parses its JSON, keeps only expected keys, sanitises every string,
 * removes links that were not in the original paragraph, and flags figures and URLs that do
 * not appear in the page so a person can verify them before approving anything.
 */
final class SEO_Pilot_Validator {

    public static function extract_json( $raw ) {
        $raw = trim( (string) $raw );
        $raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw );
        $s   = strpos( $raw, '{' );
        $e   = strrpos( $raw, '}' );
        if ( false === $s || false === $e || $e <= $s ) { return null; }
        $data = json_decode( substr( $raw, $s, $e - $s + 1 ), true );
        return is_array( $data ) ? $data : null;
    }

    public static function allowed_inline() {
        return array(
            'a' => array( 'href' => true, 'title' => true, 'rel' => true ),
            'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'u' => array(),
            'br' => array(), 'sup' => array(), 'sub' => array(), 'code' => array(),
        );
    }

    private static function txt( $v ) {
        return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
    }

    private static function verify_list( $v ) {
        $out = array();
        foreach ( (array) $v as $x ) {
            if ( ! is_scalar( $x ) ) { continue; }
            $x = SEO_Pilot_Text::sub( self::txt( $x ), 0, 200 );
            if ( '' !== $x ) { $out[] = $x; }
            if ( count( $out ) >= 5 ) { break; }
        }
        return $out;
    }

    /** Sanitised inline HTML for a paragraph body (no <p> wrapper). */
    public static function clean_inline( $html ) {
        $html = (string) $html;
        $html = preg_replace( '/^\s*<p[^>]*>(.*)<\/p>\s*$/is', '$1', $html );
        return trim( wp_kses( $html, self::allowed_inline() ) );
    }

    /** Remove anchors whose href was not in the original paragraph; keep their text. @return array [html, removed] */
    public static function strip_new_links( $proposed, $original, array $extra_allowed = array() ) {
        $allowed = array_merge( SEO_Pilot_Parser::hrefs( $original ), $extra_allowed );
        $removed = 0;
        $out     = preg_replace_callback(
            '/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            function ( $m ) use ( $allowed, &$removed ) {
                if ( in_array( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ), $allowed, true ) ) { return $m[0]; }
                $removed++;
                return $m[3];
            },
            $proposed
        );
        return array( null === $out ? $proposed : $out, $removed );
    }

    /** Figures and URLs in $proposed that are absent from $source (both plain text). */
    private static function figure_notes( $proposed, $source ) {
        $notes = array();
        $new   = array_values( array_diff( SEO_Pilot_Text::numbers( $proposed ), SEO_Pilot_Text::numbers( $source ) ) );
        if ( $new ) { $notes[] = 'Contains figure(s) not found in the page text: ' . implode( ', ', array_slice( $new, 0, 6 ) ) . '. Confirm or remove.'; }
        $urls = array_values( array_diff( SEO_Pilot_Text::urls( $proposed ), SEO_Pilot_Text::urls( $source ) ) );
        if ( $urls ) { $notes[] = 'Contains URL(s) not found in the page: ' . implode( ', ', array_slice( $urls, 0, 3 ) ) . '. Confirm or remove.'; }
        return $notes;
    }

    private static function rec( $type, $target, $original, $proposed, $reason, array $verify, array $notes = array() ) {
        return array(
            'rec_type' => $type, 'target' => $target, 'original' => (string) $original, 'proposed' => (string) $proposed,
            'rationale' => SEO_Pilot_Text::sub( self::txt( $reason ), 0, 500 ),
            'flags' => array( 'verify' => $verify, 'notes' => $notes ),
        );
    }

    /**
     * @param string $raw Model output.
     * @param array  $ctx tasks[], segments (id => segment), content_text, seo_title, meta_description, title
     * @return array|WP_Error [ recs[], warnings[] ]
     */
    public static function parse( $raw, array $ctx ) {
        $data = self::extract_json( $raw );
        if ( null === $data ) {
            return new WP_Error( 'seo_pilot_ai_invalid_response', 'The AI answer could not be read as JSON. Try again, choose fewer tasks, or use a model that follows JSON instructions reliably.' );
        }
        $tasks    = $ctx['tasks'];
        $source   = $ctx['content_text'] . ' ' . $ctx['title'] . ' ' . $ctx['seo_title'] . ' ' . $ctx['meta_description'];
        $recs     = array();
        $warnings = array();

        // SEO title and meta description.
        $fields = array( 'seo_title' => array( 200, 60, 'The title is longer than about 60 characters and may be shortened in search results.' ),
                         'meta_description' => array( 400, 160, 'The description is longer than about 160 characters and may be truncated in search results.' ) );
        foreach ( $fields as $key => $cfg ) {
            if ( ! in_array( $key, $tasks, true ) || ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) { continue; }
            $p = isset( $data[ $key ]['proposed'] ) ? self::txt( $data[ $key ]['proposed'] ) : '';
            if ( '' === $p ) { continue; }
            if ( SEO_Pilot_Text::len( $p ) > $cfg[0] ) { $warnings[] = 'A proposed ' . str_replace( '_', ' ', $key ) . ' was discarded because it was far too long.'; continue; }
            $current = isset( $ctx[ $key ] ) ? (string) $ctx[ $key ] : '';
            if ( SEO_Pilot_Text::normalize( $p ) === SEO_Pilot_Text::normalize( $current ) ) { continue; }
            $notes = self::figure_notes( $p, $source );
            if ( SEO_Pilot_Text::len( $p ) > $cfg[1] ) { $notes[] = $cfg[2]; }
            $recs[] = self::rec( $key, '', $current, $p, isset( $data[ $key ]['reason'] ) ? $data[ $key ]['reason'] : '', self::verify_list( isset( $data[ $key ]['verify'] ) ? $data[ $key ]['verify'] : array() ), $notes );
        }

        // Paragraph rewrites.
        if ( in_array( 'rewrite', $tasks, true ) && isset( $data['rewrites'] ) && is_array( $data['rewrites'] ) ) {
            $n = 0;
            foreach ( $data['rewrites'] as $item ) {
                if ( ! is_array( $item ) || empty( $item['segment'] ) || ! isset( $item['proposed_html'] ) ) { continue; }
                $id = (string) $item['segment'];
                if ( ! isset( $ctx['segments'][ $id ] ) ) { $warnings[] = 'A rewrite for an unknown paragraph (' . self::txt( $id ) . ') was ignored.'; continue; }
                if ( ++$n > SEO_Pilot_Prompt::MAX_REWRITES ) { $warnings[] = 'Extra rewrites beyond ' . SEO_Pilot_Prompt::MAX_REWRITES . ' were ignored.'; break; }
                $seg      = $ctx['segments'][ $id ];
                $proposed = self::clean_inline( is_scalar( $item['proposed_html'] ) ? (string) $item['proposed_html'] : '' );
                list( $proposed, $removed ) = self::strip_new_links( $proposed, $seg['inner'] );
                $p_plain = SEO_Pilot_Text::plain( $proposed );
                if ( '' === $p_plain ) { continue; }
                if ( SEO_Pilot_Text::ws( $proposed ) === SEO_Pilot_Text::ws( $seg['inner'] ) ) { continue; }
                $o_len = SEO_Pilot_Text::len( $seg['text'] );
                $p_len = SEO_Pilot_Text::len( $p_plain );
                if ( $p_len > $o_len * 3 + 200 ) { $warnings[] = 'The rewrite for ' . $id . ' was discarded: it is much longer than the original and may add content.'; continue; }
                $notes = self::figure_notes( $p_plain, $source );
                if ( $removed ) { $notes[] = $removed . ' link(s) the AI tried to add were removed because they were not in the original paragraph.'; }
                $missing = array_diff( SEO_Pilot_Parser::hrefs( $seg['inner'] ), SEO_Pilot_Parser::hrefs( $proposed ) );
                if ( $missing ) { $notes[] = 'The rewrite drops ' . count( $missing ) . ' link(s) that are in the original. Check before accepting.'; }
                if ( $o_len > 80 && $p_len < $o_len * 0.4 ) { $notes[] = 'The rewrite is much shorter than the original; check that nothing important was lost.'; }
                $recs[] = self::rec( 'rewrite', 'seg:' . $seg['hash'], $seg['inner'], $proposed, isset( $item['reason'] ) ? $item['reason'] : '', self::verify_list( isset( $item['verify'] ) ? $item['verify'] : array() ), $notes );
            }
        }

        // Advisory outputs (not applied automatically; the editor uses them as guidance).
        if ( in_array( 'outline', $tasks, true ) && isset( $data['outline']['items'] ) && is_array( $data['outline']['items'] ) ) {
            $items = array();
            foreach ( array_slice( $data['outline']['items'], 0, 12 ) as $it ) {
                if ( ! is_array( $it ) ) { continue; }
                $h2 = isset( $it['h2'] ) ? SEO_Pilot_Text::sub( self::txt( $it['h2'] ), 0, 150 ) : '';
                if ( '' === $h2 ) { continue; }
                $h3 = array();
                foreach ( array_slice( isset( $it['h3'] ) ? (array) $it['h3'] : array(), 0, 6 ) as $x ) { $x = SEO_Pilot_Text::sub( self::txt( $x ), 0, 150 ); if ( '' !== $x ) { $h3[] = $x; } }
                $items[] = array( 'h2' => $h2, 'h3' => $h3, 'note' => isset( $it['note'] ) ? SEO_Pilot_Text::sub( self::txt( $it['note'] ), 0, 200 ) : '', 'new' => ! empty( $it['new'] ) );
            }
            if ( $items ) {
                $recs[] = self::rec( 'outline', '', '', wp_json_encode( array( 'items' => $items ) ), isset( $data['outline']['reason'] ) ? $data['outline']['reason'] : '', array() );
            }
        }
        if ( in_array( 'topics', $tasks, true ) && isset( $data['topics'] ) && is_array( $data['topics'] ) ) {
            $mt = array(); $aq = array();
            foreach ( array_slice( isset( $data['topics']['missing_topics'] ) ? (array) $data['topics']['missing_topics'] : array(), 0, 10 ) as $x ) {
                if ( is_array( $x ) && ! empty( $x['topic'] ) ) { $mt[] = array( 'topic' => SEO_Pilot_Text::sub( self::txt( $x['topic'] ), 0, 200 ), 'why' => isset( $x['why'] ) ? SEO_Pilot_Text::sub( self::txt( $x['why'] ), 0, 300 ) : '' ); }
            }
            foreach ( array_slice( isset( $data['topics']['audience_questions'] ) ? (array) $data['topics']['audience_questions'] : array(), 0, 10 ) as $x ) {
                if ( is_array( $x ) && ! empty( $x['question'] ) ) { $aq[] = array( 'question' => SEO_Pilot_Text::sub( self::txt( $x['question'] ), 0, 250 ), 'why' => isset( $x['why'] ) ? SEO_Pilot_Text::sub( self::txt( $x['why'] ), 0, 300 ) : '' ); }
            }
            if ( $mt || $aq ) {
                $recs[] = self::rec( 'topics', '', '', wp_json_encode( array( 'missing_topics' => $mt, 'audience_questions' => $aq ) ), 'Topics and questions that may be worth covering, if they fit the page\'s purpose and audience.', array() );
            }
        }
        if ( in_array( 'faq', $tasks, true ) && isset( $data['faq'] ) && is_array( $data['faq'] ) ) {
            if ( empty( $data['faq']['useful'] ) ) {
                $warnings[] = 'The AI judged that an FAQ would not genuinely help this page, so none was created.';
            } else {
                $items = array(); $verify = array();
                foreach ( array_slice( isset( $data['faq']['items'] ) ? (array) $data['faq']['items'] : array(), 0, 8 ) as $x ) {
                    if ( ! is_array( $x ) || empty( $x['q'] ) ) { continue; }
                    $a = isset( $x['a'] ) ? SEO_Pilot_Text::sub( self::txt( $x['a'] ), 0, 600 ) : '';
                    if ( '' === $a ) { $a = 'NEEDS HUMAN INPUT'; }
                    $items[] = array( 'q' => SEO_Pilot_Text::sub( self::txt( $x['q'] ), 0, 200 ), 'a' => $a );
                    foreach ( self::figure_notes( $a, $source ) as $n ) { $verify[ $n ] = true; }
                    if ( false !== stripos( $a, 'NEEDS HUMAN INPUT' ) ) { $verify['Some answers need a person to supply them from reliable information.'] = true; }
                }
                if ( $items ) {
                    $recs[] = self::rec( 'faq', '', '', wp_json_encode( array( 'items' => $items ) ), isset( $data['faq']['reason'] ) ? $data['faq']['reason'] : '', array_keys( $verify ) );
                }
            }
        }
        if ( in_array( 'refresh', $tasks, true ) && isset( $data['refresh']['items'] ) && is_array( $data['refresh']['items'] ) ) {
            $items = array();
            foreach ( array_slice( $data['refresh']['items'], 0, 8 ) as $x ) {
                if ( is_array( $x ) && ! empty( $x['what'] ) ) { $items[] = array( 'what' => SEO_Pilot_Text::sub( self::txt( $x['what'] ), 0, 300 ), 'verify' => isset( $x['verify'] ) ? SEO_Pilot_Text::sub( self::txt( $x['verify'] ), 0, 300 ) : '' ); }
            }
            if ( $items ) {
                $recs[] = self::rec( 'refresh', '', '', wp_json_encode( array( 'items' => $items ) ), 'Points a person should check or update. The AI cannot know whether anything is actually out of date.', array() );
            }
        }

        foreach ( array_slice( isset( $data['warnings'] ) ? (array) $data['warnings'] : array(), 0, 5 ) as $w ) {
            $w = is_scalar( $w ) ? SEO_Pilot_Text::sub( self::txt( $w ), 0, 200 ) : '';
            if ( '' !== $w ) { $warnings[] = 'AI note: ' . $w; }
        }
        return array( 'recs' => $recs, 'warnings' => $warnings );
    }
}
