<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local content audit. Pure analysis of the data in $page — no database or network access —
 * so it can be unit-tested. Every finding says what was detected, why it may matter, and the
 * recommended action. Nothing here claims a ranking effect, and nothing is judged on word
 * count alone.
 */
final class SEO_Pilot_Audit {
    const ENGINE_VERSION = '2';

    const ASSESSMENT_NOTE = 'This assessment is a rule-based checklist built from the checks listed below. It is not a Google ranking factor, not a prediction of ranking success, and not a replacement for editorial judgement. Items marked "needs a human" cannot be judged by software.';

    public static function severity_rank( $sev ) {
        $map = array( 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0 );
        return isset( $map[ $sev ] ) ? $map[ $sev ] : 0;
    }

    /**
     * @param array $page See keys read below; all are optional except content/title.
     * @return array Full audit result.
     */
    public static function analyze( array $page ) {
        $page = array_merge( array(
            'id' => 0, 'title' => '', 'url' => '', 'slug' => '', 'content' => '', 'post_type' => 'post', 'post_status' => 'publish', 'modified' => '',
            'seo_title' => array( 'value' => '', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
            'meta_description' => array( 'value' => '', 'owner_label' => 'SEO Pilot', 'template_vars' => false ),
            'orphan_meta' => '', 'keywords' => array( 'primary' => '', 'secondary' => array(), 'primary_source' => '' ),
            'intent_user' => '', 'audience' => '', 'noindex' => array( 'noindex' => false, 'source' => '' ), 'site_noindex' => false,
            'cannibal' => array(), 'duplicates' => array( 'title' => array(), 'meta' => array() ),
            'site_host' => '', 'link_resolver' => null, 'year' => (int) gmdate( 'Y' ),
            'similar' => array(), 'search_data' => null, 'site_name' => '', 'title_pool' => array(),
            'image_data' => array( 'by_id' => array(), 'featured' => null ),
            'lang_info' => array( 'code' => '', 'label' => '', 'dir' => 'ltr', 'source' => '', 'plugins' => array(), 'managed_by' => '', 'group' => '', 'declared' => false, 'members' => array(), 'site_languages' => array() ),
        ), $page );
        // Word lists (stopwords, intent wording) exist for English and Arabic. For another declared language the checks that
        // depend on them are skipped and reported as such, rather than run on the wrong lists.
        if ( ! array_key_exists( 'lists_ok', $page ) ) {
            $page['lists_ok'] = empty( $page['lang_info']['declared'] ) || SEO_Pilot_Text::lists_supported( $page['lang_info']['code'] );
        }

        $r      = array( 'findings' => array(), 'checklist' => array() );
        $parsed = SEO_Pilot_Parser::parse( $page['content'] );
        $text   = $parsed['text'];
        $wc     = $parsed['word_count'];
        $title  = trim( SEO_Pilot_Text::plain( $page['title'] ) );
        $words  = SEO_Pilot_Text::words( $text );
        $lang   = SEO_Pilot_Text::language( $text . ' ' . $title );

        $st_val    = (string) $page['seo_title']['value'];
        $md_val    = (string) $page['meta_description']['value'];
        $eff_title = '' !== $st_val ? $st_val : $title;

        self::check_indexing( $r, $page );
        self::check_meta( $r, $page, $title, $st_val, $md_val );
        self::check_structure( $r, $parsed, $wc, $eff_title, $text, $page['lists_ok'] );
        self::check_content( $r, $page, $parsed, $text, $wc, $words, $lang );
        self::check_readability( $r, $parsed, $text, $wc );
        $link_info = self::check_links( $r, $page, $parsed, $wc );
        $image_analysis = self::check_images( $r, $page, $parsed, $title );

        $kw     = $page['keywords'];
        $intent = self::check_keywords( $r, $page, $parsed, $text, $eff_title, $md_val, $kw );
        $title_analysis = self::check_title_analysis( $r, $page, $parsed, $text, $wc, $title, $st_val, $kw, $intent );
        $lang_info      = self::check_language( $r, $page, $text, $wc, $title, $st_val, $md_val, $kw );
        self::check_similar( $r, $page );
        self::check_search_data( $r, $page );

        // Intent alignment (heuristic).
        foreach ( SEO_Pilot_Intent::alignment( $intent['label'], $parsed, $text ) as $row ) {
            self::check( $r, 'intent', $row['id'], $row['label'], $row['status'], $row['detail'] );
            if ( 'warn' === $row['status'] ) {
                self::add( $r, $row['id'], 'low', 'keywords', $row['label'] . ' — not detected. ' . $row['detail'],
                    'Pages that serve the likely search intent tend to satisfy visitors better. This is a heuristic over stored text only.',
                    'Check the page against the intent you expect, and add what is missing only if it genuinely helps the reader.', array( 'needs_human' => true ) );
            }
        }

        usort( $r['findings'], function ( $a, $b ) {
            $d = self::severity_rank( $b['severity'] ) - self::severity_rank( $a['severity'] );
            return 0 !== $d ? $d : strcmp( $a['id'], $b['id'] );
        } );

        $counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'manual' => 0, 'info' => 0, 'na' => 0 );
        foreach ( $r['checklist'] as $c ) { $counts[ $c['status'] ]++; }
        $sev = array( 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 );
        foreach ( $r['findings'] as $f ) { $sev[ $f['severity'] ]++; }

        $sentences = SEO_Pilot_Text::sentences( $text );
        return array(
            'engine_version' => self::ENGINE_VERSION,
            'post_id'        => (int) $page['id'],
            'title'          => $title,
            'url'            => $page['url'],
            'audited_at'     => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            'language'       => $lang,
            'rtl'            => 'ar' === $lang,
            'metrics'        => array(
                'word_count' => $wc, 'paragraphs' => count( $parsed['paragraphs'] ), 'sentences' => count( $sentences ),
                'headings' => count( $parsed['headings'] ), 'images' => count( $parsed['images'] ),
                'internal_links' => $link_info['internal'], 'external_links' => $link_info['external'],
                'lists' => $parsed['lists'], 'tables' => $parsed['tables'], 'modified' => $page['modified'],
            ),
            'seo_fields'     => array(
                'seo_title' => array( 'value' => $st_val, 'effective' => $eff_title, 'owner_label' => $page['seo_title']['owner_label'] ),
                'meta_description' => array( 'value' => $md_val, 'owner_label' => $page['meta_description']['owner_label'] ),
            ),
            'intent'         => $intent,
            'title_analysis' => $title_analysis,
            'image_analysis' => $image_analysis,
            'lang_info'      => $lang_info,
            'findings'       => $r['findings'],
            'checklist'      => $r['checklist'],
            'summary'        => array( 'checks' => $counts, 'severity' => $sev, 'total_findings' => count( $r['findings'] ), 'note' => self::ASSESSMENT_NOTE ),
        );
    }

    /* ------------------------------------------------------------ builders */

    private static function add( array &$r, $id, $sev, $cat, $what, $why, $action, array $extra = array() ) {
        $r['findings'][] = array_merge( array(
            'id' => $id, 'severity' => $sev, 'category' => $cat, 'what' => $what, 'why' => $why, 'action' => $action,
            'needs_human' => false, 'evidence' => array(),
        ), $extra );
    }

    private static function check( array &$r, $group, $id, $label, $status, $detail = '' ) {
        $r['checklist'][] = array( 'id' => $id, 'group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail );
    }

    private static function limit_list( array $items, $n = 5 ) {
        return array_slice( array_values( $items ), 0, $n );
    }

    /* ------------------------------------------------------------ indexing */

    private static function check_indexing( array &$r, array $page ) {
        if ( $page['site_noindex'] ) {
            self::add( $r, 'site_noindex', 'high', 'indexing', 'WordPress is set to discourage search engines from indexing this site.',
                'While this is on, no page on the site is asking to be indexed, so content work will not show up in search.',
                'If the site is live, a person with the right access should review Settings → Reading. SEO Pilot never changes this setting.', array( 'needs_human' => true ) );
            self::check( $r, 'meta', 'indexable', 'Page can be indexed', 'fail', 'Site-wide "discourage search engines" is on.' );
        } elseif ( ! empty( $page['noindex']['noindex'] ) ) {
            self::add( $r, 'page_noindex', 'high', 'indexing', 'This page is marked noindex by ' . $page['noindex']['source'] . '.',
                'A noindex page asks search engines to leave it out of results, so content optimization will not affect its search visibility.',
                'Confirm the noindex is intentional. SEO Pilot only reports robots settings and never changes them.', array( 'needs_human' => true ) );
            self::check( $r, 'meta', 'indexable', 'Page can be indexed', 'fail', 'Marked noindex by ' . $page['noindex']['source'] . '.' );
        } else {
            self::check( $r, 'meta', 'indexable', 'Page can be indexed', 'pass', 'No noindex setting was found in the fields SEO Pilot can read.' );
        }
    }

    /* ---------------------------------------------------------------- meta */

    private static function check_meta( array &$r, array $page, $title, $st_val, $md_val ) {
        if ( '' === $title ) {
            self::add( $r, 'title_empty', 'high', 'meta', 'The page title is empty.', 'Every page needs a title that names what it is about; visitors and search engines rely on it.', 'Add a descriptive title in the WordPress editor.' );
            self::check( $r, 'meta', 'title_present', 'Page has a title', 'fail', 'The title is empty.' );
        } else {
            self::check( $r, 'meta', 'title_present', 'Page has a title', 'pass' );
        }

        $measured = '' !== $st_val ? $st_val : $title;
        $source   = '' !== $st_val ? 'SEO title' : 'page title (no separate SEO title is set)';
        if ( '' !== $st_val && $page['seo_title']['template_vars'] ) {
            self::check( $r, 'meta', 'title_length', 'SEO title length is reasonable', 'manual', 'The SEO title uses template variables, so its final length can only be seen on the rendered page.' );
        } elseif ( '' !== $measured ) {
            $len = SEO_Pilot_Text::len( $measured );
            if ( $len > 60 ) {
                self::add( $r, 'title_long', 'low', 'meta', sprintf( 'The %s is %d characters.', $source, $len ),
                    'Search results can shorten long titles. The limit is pixel-based and only roughly 60 characters, so this is a guideline, not a rule.',
                    'Consider tightening the title and keeping the main idea near the start.' );
                self::check( $r, 'meta', 'title_length', 'SEO title length is reasonable', 'warn', $len . ' characters (guideline: about 60 or fewer).' );
            } elseif ( $len < 15 ) {
                self::add( $r, 'title_short', 'low', 'meta', sprintf( 'The %s is only %d characters.', $source, $len ),
                    'A very short title may not give searchers enough context about the page.', 'Check whether the title describes the page clearly.', array( 'needs_human' => true ) );
                self::check( $r, 'meta', 'title_length', 'SEO title length is reasonable', 'warn', $len . ' characters.' );
            } else {
                self::check( $r, 'meta', 'title_length', 'SEO title length is reasonable', 'pass', $len . ' characters.' );
            }
        }

        if ( '' === $st_val ) {
            self::check( $r, 'meta', 'seo_title_explicit', 'A specific SEO title is set', 'info', 'WordPress uses the page title by default, which is often fine.' );
        } else {
            self::check( $r, 'meta', 'seo_title_explicit', 'A specific SEO title is set', 'pass', 'Managed by ' . $page['seo_title']['owner_label'] . '.' );
        }

        if ( '' === $md_val ) {
            self::add( $r, 'meta_missing', 'medium', 'meta', 'No meta description is set.',
                'Search engines may generate a snippet from the page instead. A specific description can make the snippet more relevant, although search engines may still rewrite it.',
                'Write a short, accurate summary of the page. SEO Pilot can draft one for your approval.' );
            self::check( $r, 'meta', 'meta_present', 'Meta description is set', 'fail', 'Missing.' );
        } else {
            self::check( $r, 'meta', 'meta_present', 'Meta description is set', 'pass', 'Managed by ' . $page['meta_description']['owner_label'] . '.' );
            if ( $page['meta_description']['template_vars'] ) {
                self::check( $r, 'meta', 'meta_length', 'Meta description length is reasonable', 'manual', 'Uses template variables; check the rendered page.' );
            } else {
                $len = SEO_Pilot_Text::len( $md_val );
                if ( $len > 160 ) {
                    self::add( $r, 'meta_long', 'low', 'meta', sprintf( 'The meta description is %d characters.', $len ),
                        'Snippets are cut at a display width, not a fixed count, so long descriptions may be truncated.', 'Consider shortening it so the key message comes first.' );
                    self::check( $r, 'meta', 'meta_length', 'Meta description length is reasonable', 'warn', $len . ' characters (guideline: about 160 or fewer).' );
                } elseif ( $len < 70 ) {
                    self::add( $r, 'meta_short', 'low', 'meta', sprintf( 'The meta description is only %d characters.', $len ),
                        'A very short description may leave useful space unused.', 'Check whether it summarises the page well.', array( 'needs_human' => true ) );
                    self::check( $r, 'meta', 'meta_length', 'Meta description length is reasonable', 'warn', $len . ' characters.' );
                } else {
                    self::check( $r, 'meta', 'meta_length', 'Meta description length is reasonable', 'pass', $len . ' characters.' );
                }
            }
        }

        if ( '' !== $page['orphan_meta'] ) {
            self::add( $r, 'meta_orphaned', 'medium', 'meta', 'SEO Pilot holds a meta description that is not being output because another SEO plugin controls this field.',
                'The stored text has no effect on the live page, so the page may still lack a description.',
                'Review the stored text in the workspace and, if you approve it, apply it to the SEO plugin\'s field.', array( 'needs_human' => true, 'evidence' => array( $page['orphan_meta'] ) ) );
        }

        // Duplicates across the site (exact match on the effective value).
        foreach ( array( 'title' => 'SEO title', 'meta' => 'meta description' ) as $k => $label ) {
            $dups = isset( $page['duplicates'][ $k ] ) ? $page['duplicates'][ $k ] : array();
            if ( $dups ) {
                self::add( $r, 'duplicate_' . $k, 'meta' === $k ? 'medium' : 'low', 'meta', sprintf( 'The same %s is used on %d other page(s).', $label, count( $dups ) ),
                    'Pages that share a ' . $label . ' are harder to tell apart in search results and may compete with each other.',
                    'Decide whether the pages should differ; if so, give each its own ' . $label . '. Similar pages may also deserve a content review.',
                    array( 'needs_human' => true, 'evidence' => self::limit_list( array_map( function ( $d ) { return $d['title']; }, $dups ) ) ) );
                self::check( $r, 'meta', $k . '_unique', ucfirst( $label ) . ' is unique across the site', 'warn', 'Shared with ' . count( $dups ) . ' other page(s).' );
            } else {
                self::check( $r, 'meta', $k . '_unique', ucfirst( $label ) . ' is unique across the site', 'pass', 'No exact duplicates among audited pages.' );
            }
        }
    }

    /* ------------------------------------------------------ language */

    /**
     * Language and translation checks. All read-only and all heuristics about stored text: a person
     * decides. Uses the language model (declared by a translation plugin or by the editor) when there
     * is one; otherwise it only looks at the text.
     *
     * @return array Language info stored on the audit (no text sketches).
     */
    private static function check_language( array &$r, array $page, $text, $wc, $title, $st_val, $md_val, array $kw ) {
        $li       = $page['lang_info'];
        $code     = $li['code'];
        $declared = ! empty( $li['declared'] );
        $name     = '' !== $li['label'] ? $li['label'] : $code;
        $script   = SEO_Pilot_Hreflang::script_class( $code );
        $ratio    = SEO_Pilot_Text::arabic_ratio( $text );
        $multi    = $declared || count( (array) $li['site_languages'] ) > 1 || ! empty( $li['plugins'] );

        // 1. Does the text match the language the page is declared to be in?
        if ( $declared && '' !== $script && $wc >= 30 ) {
            $bad = ( 'arabic' === $script && $ratio < 0.15 ) || ( 'latin' === $script && $ratio >= 0.5 );
            if ( $bad ) {
                self::add( $r, 'lang_content_mismatch', 'medium', 'language', sprintf( 'The page is set to %s but its text looks like %s.', $name, 'arabic' === $script ? 'a different language (mostly Latin letters)' : 'Arabic script' ),
                    'A page whose text does not match its language setting is often an untranslated copy or a page assigned to the wrong language. Search engines may show it to the wrong audience.',
                    'Open the page and check whether it was translated, or whether its language setting is wrong. Mixed-language pages can be legitimate.', array( 'needs_human' => true ) );
            }
            self::check( $r, 'language', 'lang_content', 'Page text matches the page language', $bad ? 'warn' : 'pass', $bad ? 'Language set: ' . $name . '; text script suggests otherwise.' : 'Language set: ' . $name . '.' );
        } elseif ( $multi && ! $declared ) {
            self::check( $r, 'language', 'lang_content', 'Page text matches the page language', 'info', 'No language was set for this page, so the site language is assumed. Set it in the Language panel (or in your translation plugin).' );
        }

        // 2. Are the SEO title and meta description in the page's language (script level only)?
        $expect = $declared ? $script : ( $wc >= 30 ? ( $ratio >= 0.5 ? 'arabic' : ( $ratio < 0.15 ? 'latin' : '' ) ) : '' );
        if ( '' !== $expect ) {
            $off = array();
            foreach ( array( 'SEO title' => array( $st_val, ! empty( $page['seo_title']['template_vars'] ) ), 'meta description' => array( $md_val, ! empty( $page['meta_description']['template_vars'] ) ) ) as $label => $pair ) {
                list( $val, $tv ) = $pair;
                if ( '' === trim( $val ) || $tv || (int) preg_match_all( '/\p{L}/u', $val ) < 8 ) { continue; }
                $rr = SEO_Pilot_Text::arabic_ratio( $val );
                if ( ( 'arabic' === $expect && $rr < 0.15 ) || ( 'latin' === $expect && $rr >= 0.5 ) ) { $off[] = $label; }
            }
            if ( $off ) {
                self::add( $r, 'lang_meta_mismatch', 'low', 'language', 'The ' . implode( ' and ', $off ) . ' ' . ( count( $off ) > 1 ? 'are' : 'is' ) . ' not in the same script as the page text.',
                    'Titles and descriptions are shown in search results for this page. If they are in another language than the page, searchers see a mismatch.',
                    'Check whether they were copied from another language version and need localising.', array( 'needs_human' => true, 'evidence' => $off ) );
            }
            self::check( $r, 'language', 'lang_meta', 'SEO title and description are in the page language', $off ? 'warn' : 'pass', $off ? implode( ', ', $off ) . ' differ in script.' : '' );
        }

        if ( $declared && empty( $page['lists_ok'] ) ) {
            self::check( $r, 'language', 'lang_lists', 'Word lists exist for this language', 'info', 'SEO Pilot has built-in word lists for English and Arabic only. For ' . $name . ' the word-frequency, title-alignment and near-duplicate-title checks are skipped; keyword checks still work. A site can add words with the seo_pilot_stopwords filter.' );
        }

        // 3. Translations of this page.
        $own_title = '' !== $st_val ? $st_val : $title;
        $same_t = array(); $same_m = array(); $same_k = array(); $copy = array();
        $own_sketch = $li['members'] ? SEO_Pilot_Similarity::sketch( $text ) : array();
        foreach ( $li['members'] as $mc => $m ) {
            $mn = ( SEO_Pilot_Hreflang::name( $mc ) ? SEO_Pilot_Hreflang::name( $mc ) : $mc );
            $mt = '' !== $m['seo_title'] ? $m['seo_title'] : $m['title'];
            if ( '' !== trim( $own_title ) && SEO_Pilot_Text::normalize( $mt ) === SEO_Pilot_Text::normalize( $own_title ) ) { $same_t[] = $mn; }
            if ( '' !== trim( $md_val ) && '' !== $m['meta'] && SEO_Pilot_Text::normalize( $m['meta'] ) === SEO_Pilot_Text::normalize( $md_val ) ) { $same_m[] = $mn; }
            if ( '' !== trim( (string) $kw['primary'] ) && '' !== $m['keyword'] && SEO_Pilot_Text::normalize( $m['keyword'] ) === SEO_Pilot_Text::normalize( (string) $kw['primary'] ) ) { $same_k[] = $mn; }
            if ( ! empty( $m['sketch'] ) && $own_sketch ) {
                $sim = SEO_Pilot_Similarity::jaccard( $own_sketch, $m['sketch'] );
                if ( $sim >= 0.5 ) { $copy[] = $mn . ' (' . round( $sim * 100 ) . '% similar text)'; }
            }
        }
        if ( $same_t ) {
            self::add( $r, 'lang_title_identical', 'low', 'language', 'The title is identical to its ' . implode( ' and ', $same_t ) . ' version.',
                'Translated pages normally need their own localised title. Brand names and loanwords can be identical, so this only asks you to look.',
                'If the page is meant to serve a different language audience, write a title in that language.', array( 'needs_human' => true, 'evidence' => $same_t ) );
        }
        if ( $same_m ) {
            self::add( $r, 'lang_meta_identical', 'low', 'language', 'The meta description is identical to its ' . implode( ' and ', $same_m ) . ' version.',
                'A description copied between languages is shown to searchers who read the other language.', 'Write a description in this page\'s language.', array( 'needs_human' => true, 'evidence' => $same_m ) );
        }
        if ( $same_k ) {
            self::add( $r, 'lang_keyword_identical', 'info', 'language', 'The primary keyword is identical to its ' . implode( ' and ', $same_k ) . ' version.',
                'People search in their own language and with their own wording, so a keyword usually needs localising, not translating word for word. Brand names are the exception.',
                'Choose the phrase people in this language would actually use.', array( 'needs_human' => true, 'evidence' => $same_k ) );
        }
        if ( $copy ) {
            self::add( $r, 'lang_untranslated_copy', 'medium', 'language', 'The text is very similar to its translation: ' . implode( ' and ', $copy ) . '.',
                'Two language versions with near-identical text are usually an untranslated copy. This compares stored text only and can be wrong for pages that share quotations or code.',
                'Open both pages and check that one of them is really translated.', array( 'needs_human' => true, 'evidence' => $copy ) );
        }
        if ( $li['members'] || in_array( $li['managed_by'], array( 'polylang', 'wpml' ), true ) ) {
            $have = array( SEO_Pilot_Hreflang::lang_part( $code ) );
            foreach ( $li['members'] as $mc => $m ) { $have[] = SEO_Pilot_Hreflang::lang_part( $mc ); }
            $missing = array();
            foreach ( (array) $li['site_languages'] as $sc ) {
                if ( ! in_array( SEO_Pilot_Hreflang::lang_part( $sc ), $have, true ) ) { $missing[] = SEO_Pilot_Hreflang::name( $sc ) ? SEO_Pilot_Hreflang::name( $sc ) : $sc; }
            }
            if ( $missing ) {
                self::add( $r, 'lang_missing_translation', 'info', 'language', 'No translation was found in: ' . implode( ', ', $missing ) . '.',
                    'Not every page needs every language. This only lists the languages in your Settings that this page does not have a version for.',
                    'Create or link a translation only where it makes sense for your audience.', array( 'evidence' => $missing ) );
            }
            self::check( $r, 'language', 'lang_translations', 'Translations are linked', $li['members'] ? 'pass' : 'info', $li['members'] ? count( $li['members'] ) . ' translation(s) linked.' : 'No translation is linked to this page.' );
        }

        // Stored on the audit: no text sketches.
        $out = $li;
        foreach ( $out['members'] as $mc => $m ) { unset( $out['members'][ $mc ]['sketch'] ); }
        return $out;
    }

    /* ------------------------------------------------------ title analysis */

    /**
     * Runs the detailed title analysis (SEO_Pilot_Title) and surfaces only the few results that add
     * something the older checks do not: a width-based cut-off on a title that passes the character
     * rule, near-duplicate titles, and placeholder-style titles. The full report is stored on the audit.
     */
    private static function check_title_analysis( array &$r, array $page, array $parsed, $text, $wc, $title, $st_val, array $kw, array $intent ) {
        $ta = SEO_Pilot_Title::analyze( array(
            'title' => $title, 'seo_title' => $st_val, 'template_vars' => ! empty( $page['seo_title']['template_vars'] ), 'site_name' => $page['site_name'],
            'primary' => $kw['primary'], 'secondary' => $kw['secondary'], 'headings' => $parsed['headings'], 'text' => $text, 'word_count' => $wc,
            'intent_label' => isset( $intent['label'] ) ? $intent['label'] : '', 'exact_duplicates' => isset( $page['duplicates']['title'] ) ? count( $page['duplicates']['title'] ) : 0,
            'pool' => $page['title_pool'], 'self_id' => $page['id'], 'lists_ok' => $page['lists_ok'],
        ) );
        if ( ! $ta['analyzed'] ) { return $ta; }

        $by = array();
        foreach ( $ta['checks'] as $c ) { $by[ $c['id'] ] = $c; }

        if ( isset( $by['width'] ) ) {
            $chars = $ta['metrics']['chars'];
            self::check( $r, 'meta', 'title_width', 'Title fits the estimated display width', $by['width']['status'], $by['width']['detail'] );
            if ( 'warn' === $by['width']['status'] && $chars <= 60 ) {
                self::add( $r, 'title_width_estimate', 'low', 'meta', sprintf( 'The title is only %d characters but is estimated at about %d px wide.', $chars, $ta['metrics']['px_mid'] ),
                    'Search results shorten titles by width, not by character count, so a title with many wide letters can be cut before 60 characters. The width is an estimate and the real limit varies.',
                    'Consider moving the most important words to the start of the title.', array( 'needs_human' => true, 'evidence' => $by['width']['evidence'] ) );
            }
        }
        if ( isset( $by['dup_near'] ) ) {
            self::check( $r, 'meta', 'title_near_dup', 'Title is not almost identical to another', $by['dup_near']['status'], $by['dup_near']['detail'] );
            if ( 'warn' === $by['dup_near']['status'] ) {
                self::add( $r, 'title_near_duplicate', 'low', 'meta', sprintf( '%d other page title(s) share most of their words with this title.', count( $ta['near_duplicates'] ) ),
                    'Pages whose titles are almost the same are hard to tell apart in search results and may compete with each other.',
                    'Decide whether the pages serve different purposes; if so, give each a title that says how it differs.', array( 'needs_human' => true, 'evidence' => $by['dup_near']['evidence'] ) );
            }
        }
        if ( isset( $by['clarity'] ) && 'info' === $by['clarity']['status'] && false !== strpos( $by['clarity']['detail'], 'generic placeholder' ) ) {
            self::add( $r, 'title_generic', 'low', 'meta', 'The title looks like a generic placeholder.',
                'A title such as "Home" or "Untitled" tells searchers nothing about the page.', 'Write a title that names what the page is about.', array( 'needs_human' => true ) );
        }
        return $ta;
    }

    /* ----------------------------------------------------------- structure */

    private static function check_structure( array &$r, array $parsed, $wc, $eff_title, $text, $lists_ok = true ) {
        $h  = $parsed['headings'];
        $h1 = 0;
        foreach ( $h as $x ) { if ( 1 === $x['level'] ) { $h1++; } }

        if ( $h1 > 0 ) {
            $sev = $h1 > 1 ? 'medium' : 'low';
            self::add( $r, $h1 > 1 ? 'multiple_h1' : 'h1_in_content', $sev, 'structure',
                sprintf( 'The stored content contains %d H1 heading(s).', $h1 ),
                'Most themes already print the page title as the H1, so an H1 inside the content can create a second one. Some themes do not, so this needs a look at the rendered page.',
                'Check the rendered page; if the theme already outputs an H1, change the heading in the content to an H2.', array( 'needs_human' => true ) );
            self::check( $r, 'structure', 'h1', 'Only one H1 on the rendered page', 'manual', 'An H1 exists in the content; verify against the theme.' );
        } else {
            self::check( $r, 'structure', 'h1', 'Only one H1 on the rendered page', 'info', 'No H1 in the content, which is normal when the theme prints the title as H1.' );
        }

        // Level jumps, with the page title acting as level 1.
        $prev = 1; $skips = array(); $empty = 0; $seen = array(); $dups = array();
        foreach ( $h as $x ) {
            if ( '' === $x['text'] ) { $empty++; }
            if ( $x['level'] > 1 && $x['level'] - $prev > 1 ) { $skips[] = 'H' . $prev . ' → H' . $x['level'] . ': ' . $x['text']; }
            if ( $x['level'] > 1 ) { $prev = $x['level']; }
            $n = SEO_Pilot_Text::normalize( $x['text'] );
            if ( '' !== $n ) {
                if ( isset( $seen[ $n ] ) ) { $dups[ $x['text'] ] = true; }
                $seen[ $n ] = true;
            }
        }
        if ( $skips ) {
            self::add( $r, 'heading_skip', 'low', 'structure', 'Heading levels skip a step (' . count( $skips ) . ' place(s)).',
                'A logical hierarchy (H2, then H3 beneath it) helps readers and assistive technology follow the page.',
                'Review the headings listed and use the next level down where a section is a sub-section.', array( 'evidence' => self::limit_list( $skips ) ) );
            self::check( $r, 'structure', 'heading_order', 'Headings follow a logical hierarchy', 'warn', count( $skips ) . ' skipped level(s).' );
        } else {
            self::check( $r, 'structure', 'heading_order', 'Headings follow a logical hierarchy', $h ? 'pass' : 'na', $h ? '' : 'No headings in the content.' );
        }
        if ( $empty ) {
            self::add( $r, 'heading_empty', 'medium', 'structure', $empty . ' heading(s) have no text.', 'Empty headings confuse screen readers and add nothing for readers.', 'Remove them or add text.' );
        }
        if ( $dups ) {
            self::add( $r, 'heading_duplicate', 'low', 'structure', 'The same heading text is used more than once.', 'Repeated headings make sections harder to tell apart.',
                'Check whether each section can have its own specific heading.', array( 'evidence' => self::limit_list( array_keys( $dups ) ) ) );
        }

        $sub = 0;
        foreach ( $h as $x ) { if ( $x['level'] >= 2 && $x['level'] <= 3 ) { $sub++; } }
        if ( $wc >= 400 && 0 === $sub ) {
            self::add( $r, 'no_subheadings', 'medium', 'structure', sprintf( 'The page has about %d words and no H2/H3 sub-headings.', $wc ),
                'Long text with no sub-headings is hard to scan, and readers often look for the section they need.', 'Consider whether natural sections could get descriptive sub-headings.', array( 'needs_human' => true ) );
            self::check( $r, 'structure', 'subheadings', 'Longer content is divided by sub-headings', 'warn', 'No H2/H3 found.' );
        } else {
            self::check( $r, 'structure', 'subheadings', 'Longer content is divided by sub-headings', $wc >= 400 ? 'pass' : 'na', $wc >= 400 ? $sub . ' sub-heading(s).' : 'Content is short.' );
        }

        $long = array();
        foreach ( $parsed['sections'] as $s ) {
            if ( $s['words'] > 400 ) { $long[] = ( $s['heading'] ? $s['heading'] : '(introduction)' ) . ' — ' . $s['words'] . ' words'; }
        }
        if ( $long ) {
            self::add( $r, 'long_section', 'low', 'structure', count( $long ) . ' section(s) run past 400 words without a heading.', 'Very long unbroken sections can be hard to follow.',
                'Check whether they can be split under clearer sub-headings.', array( 'evidence' => self::limit_list( $long ), 'needs_human' => true ) );
        }

        // Title ↔ headings/introduction alignment.
        $tw = array();
        $stop = SEO_Pilot_Text::stopwords();
        foreach ( SEO_Pilot_Text::words( $eff_title ) as $w ) {
            $n = SEO_Pilot_Text::normalize( $w );
            if ( SEO_Pilot_Text::len( $n ) >= 3 && ! isset( $stop[ $n ] ) ) { $tw[ $n ] = true; }
        }
        $tw = array_keys( $tw );
        if ( ! $lists_ok ) {
            self::check( $r, 'structure', 'title_alignment', 'Title, headings and introduction describe the same topic', 'manual', 'No word list for this page\'s language, so filler words cannot be told apart from content words. Compare them yourself.' );
        } elseif ( count( $tw ) >= 2 && $wc >= 100 ) {
            $hay = implode( ' ', array_map( function ( $x ) { return $x['text']; }, $h ) ) . ' ' . SEO_Pilot_Text::first_words( $text, 120 );
            $hit = 0;
            foreach ( $tw as $w ) { if ( SEO_Pilot_Text::has_phrase( $hay, $w ) ) { $hit++; } }
            if ( $hit / count( $tw ) < 0.5 ) {
                self::add( $r, 'title_content_mismatch', 'low', 'structure', 'Few of the title\'s main words appear in the headings or the opening of the page.',
                    'Titles, headings and the introduction usually describe the same topic; a gap can mean the title promises something the page does not open with. Different word forms (for example plurals) are not matched, so this may be a false alarm.',
                    'Read the title next to the introduction and confirm they describe the same thing.', array( 'needs_human' => true ) );
                self::check( $r, 'structure', 'title_alignment', 'Title, headings and introduction describe the same topic', 'warn', $hit . ' of ' . count( $tw ) . ' title words found.' );
            } else {
                self::check( $r, 'structure', 'title_alignment', 'Title, headings and introduction describe the same topic', 'pass', $hit . ' of ' . count( $tw ) . ' title words found.' );
            }
        } else {
            self::check( $r, 'structure', 'title_alignment', 'Title, headings and introduction describe the same topic', 'manual', 'Not enough text to compare.' );
        }
    }

    /* ------------------------------------------------------------- content */

    private static function check_content( array &$r, array $page, array $parsed, $text, $wc, array $words, $lang ) {
        if ( 0 === $wc ) {
            self::add( $r, 'empty_content', 'medium', 'content', 'No text was found in the stored post content.',
                'The audit only reads the content saved with the post. Pages built with a page builder, shortcodes or custom fields may keep their text elsewhere, so this may not mean the page is empty.',
                'Check the rendered page. If text lives outside the post content, this audit cannot assess it.', array( 'needs_human' => true ) );
            self::check( $r, 'content', 'substance', 'Content is complete and useful for its purpose', 'manual', 'No stored text to assess.' );
        } else {
            $limit = 'page' === $page['post_type'] ? 100 : 150;
            if ( $wc < $limit ) {
                self::add( $r, 'short_content', 'low', 'content', sprintf( 'The stored text is short (about %d words).', $wc ),
                    'Short pages are often perfectly fine (contact pages, simple announcements). What matters is whether the page fully serves its purpose, not its length.',
                    'Ask whether a visitor with this page\'s intent would find what they need. Add detail only where it genuinely helps.', array( 'needs_human' => true ) );
            }
            self::check( $r, 'content', 'substance', 'Content is complete and useful for its purpose', 'manual', 'Usefulness and completeness need a human reader. About ' . $wc . ' words of stored text.' );
        }

        // Repeated sentences.
        $seen = array(); $rep = array();
        foreach ( SEO_Pilot_Text::sentences( $text ) as $s ) {
            if ( SEO_Pilot_Text::word_count( $s ) < 7 ) { continue; }
            $n = SEO_Pilot_Text::normalize( preg_replace( '/[\p{P}\p{S}]+/u', ' ', $s ) );
            $n = SEO_Pilot_Text::ws( $n );
            if ( isset( $seen[ $n ] ) ) { $rep[ $s ] = true; }
            $seen[ $n ] = true;
        }
        if ( $rep ) {
            self::add( $r, 'repeated_sentences', 'medium', 'content', count( $rep ) . ' sentence(s) appear more than once.', 'Repeated sentences add length without adding information.',
                'Remove or reword the repeats.', array( 'evidence' => self::limit_list( array_map( function ( $s ) { return SEO_Pilot_Text::sub( $s, 0, 140 ); }, array_keys( $rep ) ) ) ) );
            self::check( $r, 'content', 'repetition', 'Text avoids needless repetition', 'warn', count( $rep ) . ' repeated sentence(s).' );
        } else {
            $top_warn = false;
            if ( $wc >= 250 && $page['lists_ok'] ) {
                $top = SEO_Pilot_Text::top_terms( $words, 3 );
                if ( $top ) {
                    $first = key( $top );
                    $cnt   = current( $top );
                    if ( $cnt >= 12 && $cnt / $wc >= 0.04 ) {
                        $top_warn = true;
                        $ev = array();
                        foreach ( $top as $t => $c ) { $ev[] = $t . ' ×' . $c; }
                        self::add( $r, 'term_repetition', 'low', 'content', 'The word "' . $first . '" makes up a large share of the text.',
                            'Frequent repetition of one term can read unnaturally. There is no ideal frequency; this only flags unusually heavy use.',
                            'Read the text aloud and check whether more specific or varied wording would help.', array( 'evidence' => $ev, 'needs_human' => true ) );
                    }
                }
            }
            self::check( $r, 'content', 'repetition', 'Text avoids needless repetition', $top_warn ? 'warn' : 'pass', $page['lists_ok'] ? '' : 'Repeated sentences were checked; the word-frequency check was skipped because SEO Pilot has no word list for this language.' );
        }

        // Generic filler (English-only phrase list). Only run on pages that are, or may be, in English.
        $filler_lang = ! empty( $page['lang_info']['declared'] ) ? 'en' === SEO_Pilot_Hreflang::lang_part( $page['lang_info']['code'] ) : ( 'latin' === $lang || 'mixed' === $lang );
        if ( $filler_lang ) {
            $fillers = array( "in today's fast-paced world", 'in this day and age', 'it goes without saying', 'needless to say', 'at the end of the day', 'it is important to note that', "it's important to note that", 'as we all know', 'the fact of the matter', 'without further ado', 'in the realm of', 'a testament to' );
            $found   = array();
            foreach ( $fillers as $f ) { if ( SEO_Pilot_Text::has_phrase( $text, $f ) ) { $found[] = $f; } }
            if ( $found ) {
                self::add( $r, 'filler_phrases', 'low', 'content', 'Generic filler phrases were found (English phrase list).', 'Stock phrases rarely add information and can make a page feel generic.',
                    'Consider replacing them with a specific statement, or removing them.', array( 'evidence' => $found ) );
            }
            self::check( $r, 'content', 'filler', 'Free of generic filler phrases (English only)', $found ? 'warn' : 'pass', $found ? count( $found ) . ' found.' : '' );
        } else {
            self::check( $r, 'content', 'filler', 'Free of generic filler phrases (English only)', 'na', 'The filler-phrase check only covers English.' );
        }

        // Freshness signals — facts found in the text, never claims about when it was last updated.
        $norm  = SEO_Pilot_Text::normalize( $text );
        preg_match_all( '/\b(19[9]\d|20[0-4]\d)\b/', $norm, $ym );
        $years = array();
        foreach ( array_unique( $ym[1] ) as $y ) { if ( (int) $y <= $page['year'] - 2 ) { $years[] = $y; } }
        sort( $years );
        $rel   = array();
        foreach ( array( 'latest', 'currently', 'this year', 'recently', 'nowadays', 'up to date', 'up-to-date', 'حاليا', 'الان', 'هذا العام', 'هذه السنة', 'مؤخرا', 'احدث' ) as $w ) {
            if ( SEO_Pilot_Text::has_phrase( $text, $w ) ) { $rel[] = $w; }
        }
        if ( $years || $rel ) {
            $parts = array();
            if ( $years ) { $parts[] = 'mentions the year(s) ' . implode( ', ', $years ); }
            if ( $rel ) { $parts[] = 'uses time-relative wording (' . implode( ', ', $rel ) . ')'; }
            self::add( $r, 'freshness_signals', 'info', 'verification', 'The text ' . implode( ' and ', $parts ) . '.',
                'Statements tied to a date or to "now" can go out of date. SEO Pilot cannot tell whether they are still accurate' . ( '' !== $page['modified'] ? '; WordPress records the last modification as ' . $page['modified'] . ', which does not prove the content is current.' : '.' ),
                'A person should check these statements against current, reliable sources before any refresh.', array( 'needs_human' => true ) );
            self::check( $r, 'verification', 'freshness', 'Dated or time-relative statements are verified', 'manual', 'Signals found; verify them.' );
        } else {
            self::check( $r, 'verification', 'freshness', 'Dated or time-relative statements are verified', 'pass', 'No dated or time-relative wording was detected (this does not prove the content is current).' );
        }

        // Statements that look like factual claims.
        $claims = array();
        foreach ( SEO_Pilot_Text::sentences( $text ) as $s ) {
            $n = SEO_Pilot_Text::normalize( $s );
            if ( preg_match( '/\d+(?:[.,]\d+)?\s?%/', $n ) || preg_match( '/\d\s?(million|billion|thousand|مليون|مليار|الف)/u', $n )
                || preg_match( '/\b(studies show|research shows|according to|survey|statistics)\b|وفقا ل|دراسة|دراسات|احصائيات/u', $n ) ) {
                $claims[] = SEO_Pilot_Text::sub( $s, 0, 160 );
            }
        }
        if ( $claims ) {
            self::add( $r, 'claims_to_verify', 'info', 'verification', count( $claims ) . ' statement(s) look like statistics, studies or sourced claims.',
                'Figures and "studies show" statements need a reliable source. SEO Pilot cannot check them' . ( 0 === count( $parsed['links'] ) ? ', and the page contains no links at all.' : '.' ),
                'Confirm each one against its source and link or cite it where appropriate.', array( 'needs_human' => true, 'evidence' => self::limit_list( $claims ) ) );
            self::check( $r, 'verification', 'claims', 'Statistics and sourced claims are verified', 'manual', count( $claims ) . ' statement(s) to verify.' );
        } else {
            self::check( $r, 'verification', 'claims', 'Statistics and sourced claims are verified', 'pass', 'No statistic-like statements were detected.' );
        }
    }

    /* --------------------------------------------------------- readability */

    private static function check_readability( array &$r, array $parsed, $text, $wc ) {
        $sent = SEO_Pilot_Text::sentences( $text );
        if ( count( $sent ) < 5 ) {
            self::check( $r, 'readability', 'sentences', 'Sentences are a comfortable length', 'na', 'Too little text to measure.' );
        } else {
            $lens = array_map( array( 'SEO_Pilot_Text', 'word_count' ), $sent );
            $avg  = array_sum( $lens ) / count( $lens );
            $long = 0;
            foreach ( $lens as $l ) { if ( $l > 35 ) { $long++; } }
            $ratio = $long / count( $lens );
            if ( $avg > 28 || $ratio >= 0.2 ) {
                self::add( $r, 'long_sentences', 'low', 'readability', sprintf( 'Sentences average %.0f words and %d%% are over 35 words.', $avg, round( $ratio * 100 ) ),
                    'Very long sentences are harder to follow. This is a language-neutral heuristic (it does not use English syllable formulas), so treat it as a prompt to read, not a verdict.',
                    'Look for long sentences that could be split.', array( 'needs_human' => true ) );
                self::check( $r, 'readability', 'sentences', 'Sentences are a comfortable length', 'warn', sprintf( 'Average %.0f words.', $avg ) );
            } else {
                self::check( $r, 'readability', 'sentences', 'Sentences are a comfortable length', 'pass', sprintf( 'Average %.0f words.', $avg ) );
            }
        }
        $longp = 0;
        foreach ( $parsed['paragraphs'] as $p ) { if ( $p['words'] > 150 ) { $longp++; } }
        if ( $longp ) {
            self::add( $r, 'long_paragraphs', 'low', 'readability', $longp . ' paragraph(s) run past 150 words.', 'Dense blocks of text are hard to scan, especially on mobile screens.', 'Consider breaking them up where the topic shifts.' );
            self::check( $r, 'readability', 'paragraphs', 'Paragraphs are easy to scan', 'warn', $longp . ' long paragraph(s).' );
        } else {
            self::check( $r, 'readability', 'paragraphs', 'Paragraphs are easy to scan', count( $parsed['paragraphs'] ) ? 'pass' : 'na' );
        }
    }

    /* --------------------------------------------------------------- links */

    private static function classify_link( $href, $site_host ) {
        $href = trim( $href );
        if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $href ) ) { return 'skip'; }
        $host = parse_url( $href, PHP_URL_HOST );
        $path = (string) parse_url( $href, PHP_URL_PATH );
        if ( preg_match( '#/wp-content/#', $path ) || preg_match( '/\.(pdf|zip|docx?|xlsx?|pptx?|jpe?g|png|gif|webp|svg|mp[34]|csv)$/i', $path ) ) { return 'file'; }
        if ( ! $host ) { return preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ? 'skip' : 'internal'; }
        $norm = function ( $h ) { return preg_replace( '/^www\./', '', strtolower( $h ) ); };
        return ( '' !== $site_host && $norm( $host ) === $norm( $site_host ) ) ? 'internal' : 'external';
    }

    private static function check_links( array &$r, array $page, array $parsed, $wc ) {
        $internal = array(); $external = 0; $generic = array(); $empty = 0;
        foreach ( $parsed['links'] as $l ) {
            $kind = self::classify_link( $l['href'], $page['site_host'] );
            if ( 'internal' === $kind ) { $internal[ $l['href'] ] = $l; }
            elseif ( 'external' === $kind ) { $external++; }
            if ( 'skip' === $kind ) { continue; }
            if ( '' === $l['text'] && ! $l['has_img'] ) { $empty++; }
            elseif ( '' !== $l['text'] && SEO_Pilot_Text::is_generic_anchor( $l['text'] ) ) { $generic[] = $l['text'] . ' → ' . $l['href']; }
        }
        $n_int = count( $internal );

        if ( 0 === $n_int && $wc >= 150 ) {
            self::add( $r, 'no_internal_links', 'low', 'links', 'No links to other pages on this site were found in the content.',
                'Internal links help visitors find related pages and help search engines understand how pages relate.',
                'Consider linking to genuinely related pages where it helps the reader. (Automatic link suggestions arrive in a later version.)', array( 'needs_human' => true ) );
            self::check( $r, 'links', 'internal_links', 'Content links to related pages', 'warn', 'None found.' );
        } else {
            self::check( $r, 'links', 'internal_links', 'Content links to related pages', $wc >= 150 ? 'pass' : 'na', $n_int . ' internal link(s).' );
        }

        if ( $generic ) {
            self::add( $r, 'generic_anchor', 'low', 'links', count( $generic ) . ' link(s) use generic anchor text such as "click here".', 'Descriptive anchor text tells readers and search engines what the linked page is about.',
                'Reword the anchor text to describe the destination.', array( 'evidence' => self::limit_list( $generic ) ) );
        }
        if ( $empty ) {
            self::add( $r, 'empty_anchor', 'low', 'links', $empty . ' link(s) have no anchor text.', 'A link with no text or image gives no clue where it leads, and is unusable with a screen reader.', 'Add anchor text or an image with alt text.' );
        }
        self::check( $r, 'links', 'anchor_text', 'Link anchor text is descriptive', ( $generic || $empty ) ? 'warn' : ( $parsed['links'] ? 'pass' : 'na' ) );

        // Internal link targets (verified locally only; no HTTP requests are made here).
        $resolver = $page['link_resolver'];
        if ( is_callable( $resolver ) && $n_int > 0 ) {
            $unpub = array(); $unknown = array(); $checked = 0;
            foreach ( $internal as $href => $l ) {
                if ( ++$checked > 40 ) { break; }
                $res = call_user_func( $resolver, $href );
                if ( 'unpublished' === $res ) { $unpub[] = $href; }
                elseif ( 'unknown' === $res ) { $unknown[] = $href; }
            }
            if ( $unpub ) {
                self::add( $r, 'link_unpublished', 'high', 'links', count( $unpub ) . ' internal link(s) point to content that is not published.', 'Visitors who follow these links will not reach a live page.',
                    'Update or remove the links, or publish the target if that was intended.', array( 'evidence' => self::limit_list( $unpub ) ) );
            }
            if ( $unknown ) {
                self::add( $r, 'link_unverified', 'low', 'links', count( $unknown ) . ' internal link(s) could not be matched to any known WordPress content.',
                    'They may be valid (an archive, a custom route, a redirect) or broken. This check reads only the site\'s own content database and sends no HTTP requests, so it cannot confirm they work.',
                    'Open the listed links to confirm they load.', array( 'evidence' => self::limit_list( $unknown ), 'needs_human' => true ) );
            }
            self::check( $r, 'links', 'link_targets', 'Internal links point to live pages', $unpub ? 'fail' : ( $unknown ? 'manual' : 'pass' ),
                $unpub ? count( $unpub ) . ' point to unpublished content.' : ( $unknown ? count( $unknown ) . ' could not be verified.' : 'All matched published content.' ) );
        } else {
            self::check( $r, 'links', 'link_targets', 'Internal links point to live pages', $n_int ? 'manual' : 'na', $n_int ? 'Could not be verified here.' : '' );
        }
        return array( 'internal' => $n_int, 'external' => $external );
    }

    /* -------------------------------------------------------------- images */

    /**
     * Image alt-text checks, built on SEO_Pilot_Images. The two original findings (alt_missing,
     * alt_suspicious) keep their ids and evidence shape; the newer ones are added beside them.
     *
     * @return array The image analysis, stored on the audit as image_analysis.
     */
    private static function check_images( array &$r, array $page, array $parsed, $title ) {
        $ia = SEO_Pilot_Images::analyze( $parsed['images'], array( 'page_title' => $title, 'image_data' => $page['image_data'] ) );

        $missing = array(); $susp = array(); $in_link = array(); $quality = array(); $dups = array(); $large = array();
        foreach ( $ia['items'] as $it ) {
            $name = SEO_Pilot_Images::label( $it['src'] );
            foreach ( $it['issues'] as $is ) {
                switch ( $is['id'] ) {
                    case 'missing':       $missing[] = array( 'src' => $it['src'], 'hash' => $it['hash'], 'context' => $it['context'] ); break;
                    case 'filename':      $susp[] = $it['alt']; break;
                    case 'empty_in_link': $in_link[] = $name; break;
                    case 'duplicate':     $dups[ $it['alt'] ] = true; break;
                    case 'large_file':    $large[] = $name . ' (' . SEO_Pilot_Images::format_bytes( $it['filesize'] ) . ')'; break;
                    case 'too_long': case 'redundant_prefix': case 'generic': case 'has_url': case 'repeated_words': case 'same_as_title':
                        $quality[] = $name . ': ' . $is['text'];
                        break;
                }
            }
        }
        $decor = $ia['counts']['decorative'];

        if ( $missing ) {
            self::add( $r, 'alt_missing', 'medium', 'images', count( $missing ) . ' image(s) have no alt attribute.',
                'Alt text describes an image to people who cannot see it and to search engines. An image with no alt attribute at all gives them nothing.',
                'Describe what each image shows (or mark purely decorative images with an empty alt). SEO Pilot does not guess what a picture shows; you can write the text yourself, reuse the Media Library text, or, if you enabled it in Settings, ask a vision-capable AI model for a draft to review.',
                array( 'needs_human' => true, 'evidence' => array_slice( $missing, 0, 20 ) ) );
        }
        if ( $susp ) {
            self::add( $r, 'alt_suspicious', 'low', 'images', count( $susp ) . ' image(s) have alt text that looks like a file name.', 'File names such as IMG_1234 do not describe the picture.', 'Replace them with a short description of what the image shows.', array( 'evidence' => self::limit_list( $susp ) ) );
        }
        if ( $in_link ) {
            self::add( $r, 'alt_empty_in_link', 'medium', 'images', count( $in_link ) . ' linked image(s) have empty alt text and no other link text.',
                'When an image is the only content of a link, its alt text is what a screen reader announces as the link\'s purpose. With an empty alt the link has no name.',
                'Describe where the link goes or what it does (for example "Read our pricing guide"), not just what the picture looks like.', array( 'needs_human' => true, 'evidence' => self::limit_list( $in_link, 8 ) ) );
        }
        if ( $quality ) {
            self::add( $r, 'alt_quality', 'low', 'images', count( $quality ) . ' alt text issue(s) worth reviewing.',
                'Good alt text is short, specific and written for people who cannot see the image. These patterns are rules of thumb, not requirements.',
                'Read each alt text and rewrite it only if it does not describe the image well.', array( 'needs_human' => true, 'evidence' => self::limit_list( $quality, 8 ) ) );
        }
        if ( $dups ) {
            self::add( $r, 'alt_duplicate', 'low', 'images', count( $dups ) . ' alt text(s) are used on more than one image.',
                'Different images usually need different descriptions; the same text repeated gives screen-reader users no way to tell them apart.',
                'Give each image its own description of what it shows.', array( 'needs_human' => true, 'evidence' => self::limit_list( array_keys( $dups ) ) ) );
        }
        if ( $large ) {
            self::add( $r, 'image_large_file', 'info', 'images', count( $large ) . ' image file(s) are large.',
                'Large image files can slow a page. This uses the size WordPress recorded for the upload, so it only covers images that have one.',
                'Consider compressing or resizing them before upload. SEO Pilot does not change image files.', array( 'evidence' => self::limit_list( $large ) ) );
        }
        if ( is_array( $ia['featured'] ) && '' === $ia['featured']['alt'] ) {
            self::add( $r, 'featured_alt_missing', 'info', 'images', 'The featured image has no alt text in the Media Library.',
                'Themes, feeds and social previews can use the featured image, and its alt text comes from the Media Library entry.',
                'Open the image in Media → Library and add alt text there. SEO Pilot does not edit Media Library entries.' );
        }

        $bad    = $missing || $in_link;
        $warn   = $susp || $quality || $dups;
        $status = $bad ? 'fail' : ( $warn ? 'warn' : ( $parsed['images'] ? 'pass' : 'na' ) );
        self::check( $r, 'images', 'alt_text', 'Images have meaningful alt text', $status, $decor ? $decor . ' image(s) intentionally have empty alt text.' : '' );
        return $ia;
    }

    /* ------------------------------------------------------ similar content */

    private static function check_similar( array &$r, array $page ) {
        $sim = (array) $page['similar'];
        if ( ! $sim ) {
            self::check( $r, 'content', 'similar', 'Text is not largely shared with another page', 'pass', 'No page with a large overlap was found among the pages compared (pages under about 80 words are not compared).' );
            return;
        }
        $top = $sim[0];
        $pct = (int) round( $top['score'] * 100 );
        $ev  = array();
        foreach ( $sim as $s ) { $ev[] = $s['title'] . ' — about ' . (int) round( $s['score'] * 100 ) . '% similar'; }
        self::add( $r, 'similar_content', $top['score'] >= 0.8 ? 'high' : 'medium', 'content',
            sprintf( '%d other page(s) share a large part of this page\'s text (closest: "%s", about %d%%).', count( $sim ), $top['title'], $pct ),
            'Pages with largely the same text compete with each other and can be treated as duplicates, which may dilute each one. The percentage is an estimate from comparing short word sequences, and shared template text or quotations can raise it.',
            'Read both pages and decide: give each a distinct purpose, merge them (with a redirect set up by someone who manages redirects), or keep both if the overlap is intentional. SEO Pilot never sets canonicals or redirects.',
            array( 'needs_human' => true, 'evidence' => $ev ) );
        self::check( $r, 'content', 'similar', 'Text is not largely shared with another page', 'warn', 'About ' . $pct . '% overlap with "' . $top['title'] . '".' );
    }

    /* ----------------------------------------------------------- search data */

    private static function check_search_data( array &$r, array $page ) {
        $sd = $page['search_data'];
        if ( ! is_array( $sd ) ) {
            self::check( $r, 'search_data', 'sd_present', 'Imported search data is available', 'na', 'None imported. SEO Pilot has no search volume, ranking or click-through data of its own; import a Search Console export under SEO Pilot → Search data.' );
            return;
        }
        $label = $sd['label'] . ', imported ' . $sd['created_at'];
        self::check( $r, 'search_data', 'sd_present', 'Imported search data is available', 'info', 'From your import (' . $label . '): ' . (int) $sd['totals']['impressions'] . ' impressions, ' . (int) $sd['totals']['clicks'] . ' clicks in the imported rows.' );
        if ( ! empty( $sd['gaps'] ) ) {
            $ev = array();
            foreach ( $sd['gaps'] as $g ) { $ev[] = $g['query'] . ' — ' . $g['impressions'] . ' impressions, average position ' . $g['position']; }
            self::add( $r, 'sd_query_gap', 'low', 'search_data', count( $sd['gaps'] ) . ' imported queries that show this page in results are barely mentioned in its text (from your imported data: ' . $label . ').',
                'These are real queries for which the page appeared in results, according to your export. Impressions do not mean the page ranks well or satisfies the search; this only shows where the text may not address what people typed.',
                'Check whether the page genuinely answers each query; add or reword content only where it helps the reader.', array( 'needs_human' => true, 'evidence' => $ev ) );
        }
        self::check( $r, 'search_data', 'sd_gap', 'Text addresses the queries that already show the page', ! empty( $sd['gaps'] ) ? 'warn' : 'pass', ! empty( $sd['gaps'] ) ? count( $sd['gaps'] ) . ' queries barely mentioned.' : 'Imported queries are reflected in the text.' );
        if ( ! empty( $sd['cannibal'] ) ) {
            $ev = array();
            foreach ( $sd['cannibal'] as $c ) { $ev[] = $c['query'] . ' — also shown for: ' . implode( ', ', $c['pages'] ); }
            self::add( $r, 'sd_cannibal', 'medium', 'search_data', count( $sd['cannibal'] ) . ' imported queries show this page and another page of your site (from your imported data: ' . $label . ').',
                'When several of your pages appear for the same query, search engines must choose between them, which can split attention. This is real data from your export, but appearing together is not proof that either page is hurt.',
                'For each query decide which page should serve it, and differentiate or consolidate the others. This is an editorial decision.', array( 'needs_human' => true, 'evidence' => $ev ) );
        }
        self::check( $r, 'search_data', 'sd_cannibal', 'Queries are not shared with other pages of the site', ! empty( $sd['cannibal'] ) ? 'warn' : 'pass', ! empty( $sd['cannibal'] ) ? count( $sd['cannibal'] ) . ' shared queries.' : '' );
    }

    /* ------------------------------------------------------------ keywords */

    private static function check_keywords( array &$r, array $page, array $parsed, $text, $eff_title, $md_val, array $kw ) {
        $primary   = trim( (string) $kw['primary'] );
        $secondary = array_values( array_filter( array_map( 'trim', (array) $kw['secondary'] ) ) );
        $heads     = array_map( function ( $x ) { return $x['text']; }, $parsed['headings'] );
        $est       = SEO_Pilot_Intent::estimate( $page['title'], $heads, $primary, $secondary );

        $intent = $est;
        if ( in_array( $page['intent_user'], SEO_Pilot_Intent::labels(), true ) ) {
            $intent = array_merge( $est, array( 'label' => $page['intent_user'], 'confidence' => 'set by you', 'source' => 'user' ) );
            $intent['estimate'] = array( 'label' => $est['label'], 'confidence' => $est['confidence'] );
        }

        if ( '' === $primary ) {
            self::check( $r, 'keywords', 'kw_set', 'A primary keyword is chosen (optional)', 'info', 'None set; keyword checks were skipped. Keywords are optional.' );
            return $intent;
        }
        self::check( $r, 'keywords', 'kw_set', 'A primary keyword is chosen (optional)', 'pass', $primary . ( $kw['primary_source'] && 'seo_pilot' !== $kw['primary_source'] ? ' (read from ' . $kw['primary_source'] . ')' : '' ) );

        $in_title = SEO_Pilot_Text::has_phrase( $eff_title, $primary );
        if ( ! $in_title ) {
            self::add( $r, 'kw_missing_title', 'medium', 'keywords', 'The primary keyword "' . $primary . '" is not in the title.', 'The title is one of the strongest signals about what a page covers. This is a guideline: a natural title may use a close variant.',
                'Consider whether the title can name the topic naturally. Do not force the exact phrase.', array( 'needs_human' => true ) );
        }
        self::check( $r, 'keywords', 'kw_title', 'Title names the primary topic', $in_title ? 'pass' : 'warn' );

        if ( '' !== $md_val ) {
            $in = SEO_Pilot_Text::has_phrase( $md_val, $primary );
            if ( ! $in ) {
                self::add( $r, 'kw_missing_meta', 'low', 'keywords', 'The meta description does not contain the primary keyword.', 'Search engines can bold matching words in a snippet, which can help it stand out. They may also rewrite the snippet.', 'Mention the topic naturally if it fits.' );
            }
            self::check( $r, 'keywords', 'kw_meta', 'Meta description mentions the primary topic', $in ? 'pass' : 'warn' );
        }

        $intro = SEO_Pilot_Text::first_words( $text, 100 );
        $in    = SEO_Pilot_Text::has_phrase( $intro, $primary );
        if ( ! $in && $parsed['word_count'] >= 100 ) {
            self::add( $r, 'kw_missing_intro', 'low', 'keywords', 'The primary keyword is not in the first 100 words.', 'Telling readers early what the page is about helps them confirm they are in the right place.', 'Check that the opening makes the topic clear.', array( 'needs_human' => true ) );
        }
        self::check( $r, 'keywords', 'kw_intro', 'Opening makes the primary topic clear', $parsed['word_count'] < 100 ? 'na' : ( $in ? 'pass' : 'warn' ) );

        if ( $parsed['headings'] ) {
            $in_h = false;
            foreach ( $parsed['headings'] as $x ) { if ( $x['level'] >= 2 && SEO_Pilot_Text::has_phrase( $x['text'], $primary ) ) { $in_h = true; } }
            if ( ! $in_h ) {
                self::add( $r, 'kw_missing_headings', 'low', 'keywords', 'No H2/H3 heading mentions the primary keyword.', 'Headings signal the subtopics a page covers.', 'Only add it where a heading would naturally use it.', array( 'needs_human' => true ) );
            }
            self::check( $r, 'keywords', 'kw_headings', 'A sub-heading covers the primary topic', $in_h ? 'pass' : 'warn' );
        }

        $count = SEO_Pilot_Text::count_phrase( $text, $primary );
        if ( 0 === $count && $parsed['word_count'] > 0 ) {
            self::add( $r, 'kw_missing_body', 'medium', 'keywords', 'The primary keyword never appears in the body text.', 'If the page is meant to cover this topic, readers (and search engines) expect to see it discussed. A close variant may be used instead.', 'Check whether the topic is covered; use natural wording.', array( 'needs_human' => true ) );
        }
        $total = max( 1, $parsed['word_count'] );
        if ( $count >= 12 && $count / $total >= 0.05 ) {
            self::add( $r, 'kw_overuse', 'medium', 'keywords', 'The primary keyword appears ' . $count . ' times, which is unusually frequent.', 'Very heavy repetition of a phrase can read as unnatural. There is no target density; this only flags extreme use.', 'Read the text aloud and vary the wording where it feels forced.', array( 'needs_human' => true ) );
        }
        self::check( $r, 'keywords', 'kw_body', 'Primary topic is covered naturally in the text', 0 === $count ? 'warn' : ( $count >= 12 && $count / $total >= 0.05 ? 'warn' : 'pass' ), $count . ' mention(s).' );

        $miss = array();
        foreach ( $secondary as $s ) { if ( ! SEO_Pilot_Text::has_phrase( $text . ' ' . $eff_title, $s ) ) { $miss[] = $s; } }
        if ( $miss ) {
            self::add( $r, 'secondary_missing', 'low', 'keywords', count( $miss ) . ' secondary keyword(s) do not appear in the text.', 'Related terms can show whether subtopics are covered.', 'Add a section only if the topic genuinely belongs on this page.', array( 'evidence' => $miss, 'needs_human' => true ) );
        }
        if ( $secondary ) { self::check( $r, 'keywords', 'kw_secondary', 'Secondary topics are covered', $miss ? 'warn' : 'pass', $miss ? count( $miss ) . ' missing.' : '' ); }

        if ( $page['cannibal'] ) {
            self::add( $r, 'kw_cannibalization', 'medium', 'keywords', count( $page['cannibal'] ) . ' other page(s) target the same primary keyword.',
                'Pages aiming at the same phrase can compete with each other in search results.', 'Decide which page should own the topic; differentiate the others or consider merging. This is an editorial decision.',
                array( 'needs_human' => true, 'evidence' => self::limit_list( array_map( function ( $d ) { return $d['title']; }, $page['cannibal'] ) ) ) );
            self::check( $r, 'keywords', 'kw_unique', 'Primary keyword is not shared with other pages', 'warn', count( $page['cannibal'] ) . ' other page(s).' );
        } else {
            self::check( $r, 'keywords', 'kw_unique', 'Primary keyword is not shared with other pages', 'pass', 'Compared only with keywords entered in SEO Pilot.' );
        }
        return $intent;
    }
}
