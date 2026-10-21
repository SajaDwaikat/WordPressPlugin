<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Detailed SEO title analysis. Pure logic: no WordPress state, so it is unit-tested on its own.
 *
 * Every check carries a "basis" so the editor can tell what kind of statement it is:
 *   measured  - counted or compared by this code (characters, matches, overlap).
 *   estimate  - an approximation (pixel width). Fonts and Google's limits vary, so never a rule.
 *   heuristic - a rule of thumb about clarity or relevance. A prompt to look, not a requirement.
 * Qualitative advice (is the title compelling, what to write instead) is NOT produced here; that is
 * the optional AI "title review" task.
 */
final class SEO_Pilot_Title {
    /** Approximate display limit of a desktop result title, in pixels at 20px Arial. Widely quoted, not official. */
    const PX_LIMIT = 580;
    /** Relative uncertainty of the width estimate. */
    const PX_SPREAD = 0.10;

    const BASIS_MEASURED  = 'measured';
    const BASIS_ESTIMATE  = 'estimate';
    const BASIS_HEURISTIC = 'heuristic';

    /* ------------------------------------------------------------ width estimate */

    /** Arial advance widths per 1000 units for printable ASCII. */
    private static function ascii_widths() {
        static $w = null;
        if ( null !== $w ) { return $w; }
        $w = array(
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
            '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584,
            '>' => 584, '?' => 556, '@' => 1015, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333, '{' => 334,
            '|' => 260, '}' => 334, '~' => 584,
            'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500,
            'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611,
            'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222,
            'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278,
            'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
        );
        foreach ( str_split( '0123456789' ) as $d ) { $w[ $d ] = 556; }
        return $w;
    }

    /**
     * Width in pixels of one character at 20px. Latin letters use Arial metrics; Arabic letters use an
     * average (letters join, so widths vary a lot); wide scripts count as a full em.
     */
    private static function char_units( $ch ) {
        $ascii = self::ascii_widths();
        if ( isset( $ascii[ $ch ] ) ) { return $ascii[ $ch ]; }
        if ( 1 === preg_match( '/^\p{Arabic}$/u', $ch ) ) {
            return 1 === preg_match( '/^\p{Mn}$/u', $ch ) ? 0 : 500; // diacritics add no width
        }
        if ( 1 === preg_match( '/^[\x{2013}\x{2014}]$/u', $ch ) ) { return '–' === $ch ? 556 : 1000; }
        if ( 1 === preg_match( '/^[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]$/u', $ch ) ) { return 1000; }
        if ( 1 === preg_match( '/^\p{Mn}$/u', $ch ) ) { return 0; }
        if ( 1 === preg_match( '/^\p{So}$/u', $ch ) ) { return 1000; } // emoji and symbols
        return 556; // accented Latin, Cyrillic, Greek and the rest: close to an average letter
    }

    /**
     * @return array low, mid, high pixel estimates, and the width of each character (for cut-off positions).
     */
    public static function estimate_width( $title ) {
        $chars = preg_split( '//u', (string) $title, -1, PREG_SPLIT_NO_EMPTY );
        $chars = $chars ? $chars : array();
        $per   = array();
        $total = 0.0;
        foreach ( $chars as $c ) {
            $px      = self::char_units( $c ) * 20 / 1000;
            $per[]   = $px;
            $total  += $px;
        }
        return array(
            'mid'  => (int) round( $total ),
            'low'  => (int) round( $total * ( 1 - self::PX_SPREAD ) ),
            'high' => (int) round( $total * ( 1 + self::PX_SPREAD ) ),
            'chars' => $chars,
            'per'  => $per,
        );
    }

    /** The part of the title that fits in the limit by the mid estimate, with an ellipsis when cut. */
    public static function cut_preview( $title, $limit = null ) {
        $limit = $limit ? $limit : self::PX_LIMIT;
        $e     = self::estimate_width( $title );
        if ( $e['mid'] <= $limit ) { return array( (string) $title, false ); }
        $acc = 0.0; $out = '';
        foreach ( $e['chars'] as $i => $c ) {
            if ( $acc + $e['per'][ $i ] > $limit - 12 ) { break; } // leave room for the ellipsis
            $acc += $e['per'][ $i ];
            $out .= $c;
        }
        return array( rtrim( $out ) . '…', true );
    }

    /* ------------------------------------------------------------ text helpers */

    /** Very light stemming for Latin words: plurals and common endings. Arabic is matched as substring elsewhere. */
    public static function light_stem( $w ) {
        $w = (string) $w;
        if ( SEO_Pilot_Text::contains_arabic( $w ) ) { return $w; }
        foreach ( array( 'ing', 'ed', 's' ) as $suf ) {
            $n = strlen( $suf );
            if ( strlen( $w ) - $n >= 4 && substr( $w, -$n ) === $suf ) { $w = substr( $w, 0, -$n ); break; }
        }
        if ( strlen( $w ) > 4 && 'e' === substr( $w, -1 ) ) { $w = substr( $w, 0, -1 ); } // guide / guides, bake / baked
        return $w;
    }

    /** Distinct content words of a title (stopwords and very short words removed), normalised. */
    public static function content_terms( $text ) {
        $stop = SEO_Pilot_Text::stopwords();
        $out  = array();
        foreach ( SEO_Pilot_Text::words( $text ) as $w ) {
            $n = SEO_Pilot_Text::normalize( $w );
            if ( SEO_Pilot_Text::len( $n ) >= 3 && ! isset( $stop[ $n ] ) ) { $out[ $n ] = true; }
        }
        return array_keys( $out );
    }

    /** Token set used for near-duplicate comparison: word => true (stemmed). */
    public static function tokens( $title ) {
        $set = array();
        foreach ( self::content_terms( $title ) as $t ) { $set[ self::light_stem( $t ) ] = true; }
        return $set;
    }

    private static function jaccard( array $a, array $b ) {
        if ( ! $a || ! $b ) { return 0.0; }
        $inter = count( array_intersect_key( $a, $b ) );
        $union = count( $a ) + count( $b ) - $inter;
        return $union ? $inter / $union : 0.0;
    }

    /**
     * Other titles that are not identical but share most of their words.
     *
     * @param array $tokens Token set of this title.
     * @param array $pool   id => array( label, key (normalised full title), tokens )
     * @return array[] id, title, score (0-1), highest first, at most $limit
     */
    public static function near_duplicates( array $tokens, $own_key, array $pool, $self_id, $min = 0.75, $limit = 5 ) {
        if ( count( $tokens ) < 3 ) { return array(); }
        $out = array();
        foreach ( $pool as $id => $row ) {
            if ( (int) $id === (int) $self_id || $row['key'] === $own_key || count( $row['tokens'] ) < 3 ) { continue; }
            $s = self::jaccard( $tokens, $row['tokens'] );
            if ( $s >= $min ) { $out[] = array( 'id' => (int) $id, 'title' => $row['label'], 'score' => round( $s, 2 ) ); }
        }
        usort( $out, function ( $x, $y ) { return $y['score'] <=> $x['score']; } );
        return array_slice( $out, 0, $limit );
    }

    /** Is a term present in a list of already-stemmed hay words (or, for Arabic, inside the hay text)? */
    private static function term_hit( $term, array $hay_stems, $hay_text ) {
        if ( SEO_Pilot_Text::contains_arabic( $term ) ) { return SEO_Pilot_Text::has_phrase( $hay_text, $term ); }
        return isset( $hay_stems[ self::light_stem( $term ) ] );
    }

    private static function stem_set( $text ) {
        $set = array();
        foreach ( SEO_Pilot_Text::words( $text ) as $w ) { $set[ self::light_stem( SEO_Pilot_Text::normalize( $w ) ) ] = true; }
        return $set;
    }

    /** Character offset of the first occurrence of a phrase in a title, or -1. Uses the same normalisation as matching. */
    private static function offset_of( $title, $phrase ) {
        $h = SEO_Pilot_Text::normalize( $title );
        $p = SEO_Pilot_Text::normalize( $phrase );
        if ( '' === $p || '' === $h ) { return -1; }
        if ( SEO_Pilot_Text::contains_arabic( $p ) ) {
            $h   = SEO_Pilot_Text::fold_arabic( $h ); // same folding as the matching, so the position agrees with has_phrase()
            $p   = SEO_Pilot_Text::fold_arabic( $p );
            $pos = function_exists( 'mb_strpos' ) ? mb_strpos( $h, $p, 0, 'UTF-8' ) : strpos( $h, $p );
            return false === $pos ? -1 : (int) $pos;
        }
        if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $p, '/' ) . '(?![\p{L}\p{N}])/u', $h, $m, PREG_OFFSET_CAPTURE ) ) {
            return SEO_Pilot_Text::len( substr( $h, 0, $m[0][1] ) );
        }
        return -1;
    }

    /* ------------------------------------------------------------ analysis */

    private static function row( $id, $label, $basis, $status, $detail = '', array $evidence = array() ) {
        return array( 'id' => $id, 'label' => $label, 'basis' => $basis, 'status' => $status, 'detail' => $detail, 'evidence' => $evidence );
    }

    /**
     * @param array $in title (page title), seo_title (explicit value or ''), template_vars (bool), site_name,
     *                  primary, secondary[], headings[] (level,text), text (plain body), word_count,
     *                  intent_label, exact_duplicates (int), pool (see near_duplicates), self_id
     * @return array analysed, title, source, metrics, checks[], opportunities[], near_duplicates[]
     */
    public static function analyze( array $in ) {
        $in = array_merge( array(
            'title' => '', 'seo_title' => '', 'template_vars' => false, 'site_name' => '', 'primary' => '', 'secondary' => array(),
            'headings' => array(), 'text' => '', 'word_count' => 0, 'intent_label' => '', 'exact_duplicates' => 0, 'pool' => array(), 'self_id' => 0, 'lists_ok' => true,
        ), $in );

        $explicit = trim( (string) $in['seo_title'] );
        $title    = '' !== $explicit ? $explicit : trim( (string) $in['title'] );
        $source   = '' !== $explicit ? 'seo_title' : 'page_title';
        $out      = array( 'analyzed' => false, 'title' => $title, 'source' => $source, 'metrics' => array(), 'checks' => array(), 'opportunities' => array(), 'near_duplicates' => array() );

        if ( '' === $title ) { return $out; }
        if ( '' !== $explicit && $in['template_vars'] ) {
            $out['checks'][] = self::row( 'template', 'Title measured', self::BASIS_MEASURED, 'manual', 'The SEO title uses template variables (for example %%title%%), so its final text can only be judged on the rendered page.' );
            return $out;
        }
        $out['analyzed'] = true;

        $chars = SEO_Pilot_Text::len( $title );
        $w     = self::estimate_width( $title );
        $out['metrics'] = array( 'chars' => $chars, 'px_low' => $w['low'], 'px_mid' => $w['mid'], 'px_high' => $w['high'], 'px_limit' => self::PX_LIMIT );

        /* 1. Length and estimated width */
        $detail = sprintf( '%d characters; roughly %d–%d px wide (estimate; a typical desktop result shows about %d px).', $chars, $w['low'], $w['high'], self::PX_LIMIT );
        if ( $w['low'] > self::PX_LIMIT ) {
            list( $preview ) = self::cut_preview( $title );
            $out['checks'][] = self::row( 'width', 'Estimated width fits a search result', self::BASIS_ESTIMATE, 'warn', $detail . ' It will probably be shortened.', array( 'Likely shown as: ' . $preview ) );
        } elseif ( $w['high'] > self::PX_LIMIT ) {
            $out['checks'][] = self::row( 'width', 'Estimated width fits a search result', self::BASIS_ESTIMATE, 'info', $detail . ' It is close to the limit and may be shortened.' );
        } else {
            $out['checks'][] = self::row( 'width', 'Estimated width fits a search result', self::BASIS_ESTIMATE, 'pass', $detail );
        }

        /* 2. Keyword presence, position, repetition */
        $primary = trim( (string) $in['primary'] );
        if ( '' === $primary ) {
            $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_MEASURED, 'na', 'No primary keyword is set for this page, so keyword checks were skipped. Keywords are optional.' );
        } else {
            $count = SEO_Pilot_Text::count_phrase( $title, $primary );
            if ( $count > 0 ) {
                $off = self::offset_of( $title, $primary );
                $rel = ( $off >= 0 && $chars > 0 ) ? $off / $chars : 0;
                $where = $rel <= 0.3 ? 'near the start' : ( $rel <= 0.6 ? 'in the middle' : 'near the end' );
                // Does the keyword sit in the part that would be cut?
                $kw_cut = false;
                if ( $w['mid'] > self::PX_LIMIT && $off >= 0 ) {
                    $acc = 0.0;
                    for ( $i = 0; $i < $off + SEO_Pilot_Text::len( $primary ) && $i < count( $w['per'] ); $i++ ) { $acc += $w['per'][ $i ]; }
                    $kw_cut = $acc > self::PX_LIMIT;
                }
                if ( $kw_cut ) {
                    $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_ESTIMATE, 'warn', 'The keyword "' . $primary . '" is present but sits ' . $where . ', where the title will probably be cut off.' );
                } else {
                    $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_MEASURED, 'pass', 'The keyword "' . $primary . '" is present, ' . $where . ' of the title.' );
                }
                if ( $count > 1 ) {
                    $out['checks'][] = self::row( 'keyword_repeat', 'Keyword is not repeated', self::BASIS_MEASURED, 'warn', 'The keyword appears ' . $count . ' times in the title. Repeating it rarely helps and can read as stuffing.' );
                }
            } else {
                $terms = self::content_terms( $primary );
                $hay   = self::stem_set( $title );
                $found = array();
                foreach ( $terms as $t ) { if ( self::term_hit( $t, $hay, $title ) ) { $found[] = $t; } }
                if ( count( $terms ) > 1 && count( $found ) === count( $terms ) ) {
                    $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_MEASURED, 'info', 'All the words of "' . $primary . '" appear, but not together as that phrase. This can be fine; check that the title still reads naturally.' );
                } elseif ( $found ) {
                    $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_MEASURED, 'warn', 'Only part of "' . $primary . '" appears in the title (' . implode( ', ', $found ) . ').' );
                } else {
                    $out['checks'][] = self::row( 'keyword', 'Primary keyword in the title', self::BASIS_MEASURED, 'warn', 'The keyword "' . $primary . '" does not appear in the title. Only change this if the title can name the topic naturally.' );
                }
            }
        }

        /* 3. Brand / site name share */
        $site = trim( (string) $in['site_name'] );
        if ( '' !== $site && SEO_Pilot_Text::len( $site ) >= 3 && SEO_Pilot_Text::has_phrase( $title, $site ) && SEO_Pilot_Text::normalize( $title ) !== SEO_Pilot_Text::normalize( $site ) ) {
            $share = (int) round( 100 * SEO_Pilot_Text::len( $site ) / max( 1, $chars ) );
            $out['checks'][] = self::row( 'brand', 'Site name does not crowd the title', self::BASIS_MEASURED, $share >= 35 ? 'info' : 'pass',
                'The site name "' . $site . '" is about ' . $share . '% of the title.' . ( $share >= 35 ? ' A long site name leaves less room for what the page is about.' : '' ) );
        }

        /* 4. Clarity and specificity (heuristics) */
        $norm      = SEO_Pilot_Text::normalize( $title );
        $generic   = array( 'home', 'homepage', 'home page', 'untitled', 'welcome', 'blog', 'page', 'new page', 'new post', 'test', 'sample page', 'hello world', 'index', 'default',
            'الرئيسية', 'الصفحة الرئيسية', 'بدون عنوان', 'مرحبا', 'تجربة', 'صفحة جديدة' );
        $terms_all = self::content_terms( $title );
        $flags     = array();
        if ( in_array( $norm, $generic, true ) ) { $flags[] = 'The title is a generic placeholder-style word.'; }
        elseif ( count( $terms_all ) < 2 && $chars < 25 ) { $flags[] = 'The title is very short and has few specific words.'; }
        $letters = (int) preg_match_all( '/\p{Lu}|\p{Ll}/u', $title );
        $upper   = (int) preg_match_all( '/\p{Lu}/u', $title );
        if ( $letters >= 8 && $upper / $letters > 0.7 ) { $flags[] = 'Most of the letters are capitals.'; }
        if ( substr_count( $title, '!' ) >= 2 || preg_match( '/[!?]{2,}/', $title ) ) { $flags[] = 'It uses repeated exclamation or question marks.'; }
        if ( preg_match_all( '/[|\x{2013}\x{2014}:»•~]/u', $title ) >= 3 ) { $flags[] = 'It has several separators, which can make it look like a list of keywords.'; }
        if ( preg_match( '/\p{So}/u', $title ) ) { $flags[] = 'It contains emoji or symbols, which search engines may not display.'; }
        $out['checks'][] = self::row( 'clarity', 'Title reads as specific and clear', self::BASIS_HEURISTIC, $flags ? 'info' : 'pass',
            $flags ? 'Things to look at: ' . implode( ' ', $flags ) : 'No common clarity problems were detected. This does not mean the title is good; it only means none of these simple patterns matched.' );

        /* 5. Alignment with headings, opening and body */
        $wc = (int) $in['word_count'];
        $tt = $terms_all;
        if ( ! $in['lists_ok'] ) {
            $out['checks'][] = self::row( 'alignment', 'Title matches the page content', self::BASIS_HEURISTIC, 'manual', 'SEO Pilot has no word list for this page\'s language, so it cannot tell content words from filler words. Compare the title with the headings and opening yourself.' );
        } elseif ( count( $tt ) >= 2 && $wc >= 100 ) {
            $head_text = implode( ' ', array_map( function ( $h ) { return $h['text']; }, (array) $in['headings'] ) );
            $open_text = SEO_Pilot_Text::first_words( $in['text'], 120 );
            $sets      = array( 'headings' => $head_text, 'opening' => $open_text, 'body' => (string) $in['text'] );
            $cover     = array(); $missing_all = array();
            foreach ( $sets as $name => $hay_text ) {
                $stems = self::stem_set( $hay_text ); $hit = 0;
                foreach ( $tt as $t ) { if ( self::term_hit( $t, $stems, $hay_text ) ) { $hit++; } else if ( 'body' === $name ) { $missing_all[] = $t; } }
                $cover[ $name ] = $hit / count( $tt );
            }
            $heads_open = max( $cover['headings'], $cover['opening'] );
            $detail = sprintf( '%d of %d main title words appear in the headings, %d in the opening, %d in the body (plural and Arabic prefix forms are matched).',
                round( $cover['headings'] * count( $tt ) ), count( $tt ), round( $cover['opening'] * count( $tt ) ), round( $cover['body'] * count( $tt ) ) );
            if ( $cover['body'] < 0.5 ) {
                $out['checks'][] = self::row( 'alignment', 'Title matches the page content', self::BASIS_HEURISTIC, 'warn', $detail . ' Most of the title\'s words are not used in the page text.', $missing_all );
            } elseif ( $heads_open < 0.5 ) {
                $out['checks'][] = self::row( 'alignment', 'Title matches the page content', self::BASIS_HEURISTIC, 'info', $detail . ' The topic is in the text but not named in the headings or the opening.', $missing_all );
            } else {
                $out['checks'][] = self::row( 'alignment', 'Title matches the page content', self::BASIS_HEURISTIC, 'pass', $detail );
            }
        } else {
            $out['checks'][] = self::row( 'alignment', 'Title matches the page content', self::BASIS_HEURISTIC, 'manual', 'There is not enough text or title wording to compare.' );
        }

        /* 6. Search-intent cue (heuristic; uses the rule-based intent estimate) */
        $page_intent = (string) $in['intent_label'];
        if ( '' !== $page_intent && 'undetermined' !== $page_intent ) {
            $t_est = SEO_Pilot_Intent::estimate( $title );
            if ( 'undetermined' === $t_est['label'] ) {
                $out['checks'][] = self::row( 'intent', 'Title signals the page\'s purpose', self::BASIS_HEURISTIC, 'info',
                    'The page looks ' . $page_intent . ' but the title has no wording that signals it (for example "how to" or "guide" for informational pages, "price" or "buy" for transactional ones). This is a rule-based guess, not search data.' );
            } elseif ( $t_est['label'] !== $page_intent ) {
                $out['checks'][] = self::row( 'intent', 'Title signals the page\'s purpose', self::BASIS_HEURISTIC, 'info',
                    'The title\'s wording suggests ' . $t_est['label'] . ' intent while the page is estimated or set as ' . $page_intent . '. Check that they agree.' );
            } else {
                $out['checks'][] = self::row( 'intent', 'Title signals the page\'s purpose', self::BASIS_HEURISTIC, 'pass', 'The title\'s wording matches the page\'s ' . $page_intent . ' intent.' );
            }
        }

        /* 7. Duplicates */
        $exact = (int) $in['exact_duplicates'];
        $out['checks'][] = self::row( 'dup_exact', 'Title is unique across the site', self::BASIS_MEASURED, $exact ? 'warn' : 'pass',
            $exact ? 'The same title is used on ' . $exact . ' other page(s).' : 'No other audited page has exactly this title.' );
        if ( $in['pool'] && $in['lists_ok'] ) {
            $near = self::near_duplicates( self::tokens( $title ), SEO_Pilot_Text::normalize( $title ), (array) $in['pool'], (int) $in['self_id'] );
            $out['near_duplicates'] = $near;
            if ( $near ) {
                $out['checks'][] = self::row( 'dup_near', 'Title is not almost identical to another', self::BASIS_MEASURED, 'warn',
                    count( $near ) . ' other title(s) share most of their words with this one. Pages with near-identical titles can be hard to tell apart in search results.',
                    array_map( function ( $n ) { return $n['title'] . ' (' . round( $n['score'] * 100 ) . '% shared words)'; }, $near ) );
            } else {
                $out['checks'][] = self::row( 'dup_near', 'Title is not almost identical to another', self::BASIS_MEASURED, 'pass', 'No other title shares most of its words with this one.' );
            }
        }

        /* Opportunities: plain statements derived from the checks above, in priority order. */
        foreach ( $out['checks'] as $c ) {
            if ( in_array( $c['status'], array( 'warn', 'info' ), true ) ) {
                $out['opportunities'][] = array( 'id' => $c['id'], 'basis' => $c['basis'], 'text' => $c['detail'] );
            }
        }
        return $out;
    }
}
