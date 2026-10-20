<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reads stored post content (block or classic markup) into a structure the audit
 * engine can reason about, and performs the two narrow, exact edits the approval
 * workflow is allowed to make: replacing one paragraph, and adding a missing img alt.
 */
final class SEO_Pilot_Parser {

    /**
     * @return array headings[], paragraphs[], images[], links[], sections[], lists, tables, text, word_count, dom_ok
     */
    public static function parse( $html ) {
        $out = array(
            'headings' => array(), 'paragraphs' => array(), 'images' => array(), 'links' => array(),
            'sections' => array( array( 'heading' => null, 'level' => 0, 'words' => 0 ) ),
            'lists' => 0, 'tables' => 0, 'text' => '', 'word_count' => 0, 'dom_ok' => false,
        );
        $html              = (string) $html;
        $out['text']       = SEO_Pilot_Text::plain( $html );
        $out['word_count'] = SEO_Pilot_Text::word_count( $out['text'] );
        if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) { return $out; }

        $prev = libxml_use_internal_errors( true );
        $dom  = new DOMDocument();
        $ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="sp-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $ok ) { return $out; }
        $out['dom_ok'] = true;

        $xp    = new DOMXPath( $dom );
        $nodes = $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6|//p|//li[not(.//p)]|//blockquote[not(.//p)]|//img|//a|//ul|//ol|//table' );
        if ( ! $nodes ) { return $out; }

        $current_heading = '';
        foreach ( $nodes as $node ) {
            $name = strtolower( $node->nodeName );
            if ( preg_match( '/^h([1-6])$/', $name, $m ) ) {
                $text                 = SEO_Pilot_Text::ws( $node->textContent );
                $out['headings'][]    = array( 'level' => (int) $m[1], 'text' => $text );
                $out['sections'][]    = array( 'heading' => $text, 'level' => (int) $m[1], 'words' => 0 );
                $current_heading      = $text;
            } elseif ( 'p' === $name || 'li' === $name || 'blockquote' === $name ) {
                $text  = SEO_Pilot_Text::ws( $node->textContent );
                $words = SEO_Pilot_Text::word_count( $text );
                $out['sections'][ count( $out['sections'] ) - 1 ]['words'] += $words;
                if ( 'p' === $name && $words > 0 ) {
                    $out['paragraphs'][] = array( 'text' => $text, 'words' => $words );
                }
            } elseif ( 'img' === $name ) {
                $src = trim( (string) $node->getAttribute( 'src' ) );
                if ( '' === $src ) { $src = trim( (string) $node->getAttribute( 'data-src' ) ); }
                $out['images'][] = array(
                    'src'     => $src,
                    'has_alt' => $node->hasAttribute( 'alt' ),
                    'alt'     => trim( (string) $node->getAttribute( 'alt' ) ),
                    'context' => $current_heading,
                );
            } elseif ( 'a' === $name ) {
                $text    = SEO_Pilot_Text::ws( $node->textContent );
                $has_img = $node->getElementsByTagName( 'img' )->length > 0;
                $out['links'][] = array(
                    'href'    => trim( (string) $node->getAttribute( 'href' ) ),
                    'text'    => $text,
                    'has_img' => $has_img,
                    'rel'     => strtolower( (string) $node->getAttribute( 'rel' ) ),
                );
            } elseif ( 'ul' === $name || 'ol' === $name ) {
                $out['lists']++;
            } elseif ( 'table' === $name ) {
                $out['tables']++;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ paragraph segments */

    /**
     * Paragraphs that can be rewritten. Each has a stable hash of its inner HTML so an
     * approved rewrite can be re-located later, and refused if the paragraph changed.
     *
     * @return array[] id, mode, raw, inner, text, hash, words, open, close, block
     */
    public static function paragraph_segments( $content ) {
        $content = (string) $content;
        $segs    = array();
        if ( function_exists( 'has_blocks' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_block' ) && has_blocks( $content ) ) {
            self::collect_blocks( parse_blocks( $content ), $segs );
        } else {
            foreach ( preg_split( '/\n\s*\n/', $content ) as $part ) {
                $part = trim( $part );
                if ( '' === $part ) { continue; }
                if ( preg_match( '/^<(h[1-6]|ul|ol|table|blockquote|figure|div|pre|hr|iframe|form|section|aside|nav|script|style|img|!--)\b/i', $part ) ) { continue; }
                if ( preg_match( '/^<p([^>]*)>(.*)<\/p>$/is', $part, $m ) ) {
                    $open = '<p' . $m[1] . '>'; $inner = $m[2]; $close = '</p>';
                } else {
                    $open = ''; $inner = $part; $close = '';
                }
                self::push_segment( $segs, 'classic', $part, $inner, $open, $close, null );
            }
        }
        foreach ( $segs as $i => &$s ) { $s['id'] = 'S' . ( $i + 1 ); }
        unset( $s );
        return $segs;
    }

    private static function collect_blocks( array $blocks, array &$segs ) {
        foreach ( $blocks as $b ) {
            if ( isset( $b['blockName'] ) && 'core/paragraph' === $b['blockName'] && empty( $b['innerBlocks'] ) ) {
                if ( preg_match( '/^(\s*<p[^>]*>)(.*)(<\/p>\s*)$/is', (string) $b['innerHTML'], $m ) ) {
                    self::push_segment( $segs, 'block', serialize_block( $b ), $m[2], $m[1], $m[3], $b );
                }
            } elseif ( ! empty( $b['innerBlocks'] ) ) {
                self::collect_blocks( $b['innerBlocks'], $segs );
            }
        }
    }

    private static function push_segment( array &$segs, $mode, $raw, $inner, $open, $close, $block ) {
        $text  = SEO_Pilot_Text::plain( $inner );
        $words = SEO_Pilot_Text::word_count( $text );
        if ( 0 === $words ) { return; }
        $segs[] = array(
            'id' => '', 'mode' => $mode, 'raw' => $raw, 'inner' => $inner, 'text' => $text,
            'hash' => self::hash_inner( $inner ), 'words' => $words, 'open' => $open, 'close' => $close, 'block' => $block,
        );
    }

    public static function hash_inner( $inner ) {
        return sha1( SEO_Pilot_Text::ws( $inner ) );
    }

    /**
     * Replace exactly one paragraph (located by the hash of its original inner HTML).
     *
     * @return string|WP_Error The new content, or an error if the paragraph moved, changed, or is ambiguous.
     */
    public static function replace_paragraph( $content, $hash, $new_inner ) {
        $content = (string) $content;
        $matches = array();
        foreach ( self::paragraph_segments( $content ) as $s ) {
            if ( $s['hash'] === $hash ) { $matches[] = $s; }
        }
        if ( 0 === count( $matches ) ) {
            return new WP_Error( 'seo_pilot_segment_not_found', 'The original paragraph is no longer in the post (it was edited or removed since the suggestion was made).' );
        }
        if ( count( $matches ) > 1 || 1 !== substr_count( $content, $matches[0]['raw'] ) ) {
            return new WP_Error( 'seo_pilot_segment_ambiguous', 'The same paragraph text appears more than once, so it cannot be replaced safely.' );
        }
        $seg = $matches[0];
        if ( 'block' === $seg['mode'] ) {
            $b                 = $seg['block'];
            $new_html          = $seg['open'] . $new_inner . $seg['close'];
            $b['innerHTML']    = $new_html;
            $b['innerContent'] = array( $new_html );
            $new_raw           = serialize_block( $b );
        } else {
            $new_raw = $seg['open'] . $new_inner . $seg['close'];
        }
        $pos = strpos( $content, $seg['raw'] );
        return substr_replace( $content, $new_raw, $pos, strlen( $seg['raw'] ) );
    }

    /* ------------------------------------------------------------------ image alt */

    public static function src_hash( $src ) {
        return sha1( (string) $src );
    }

    /**
     * Add an alt attribute to <img> tags that have none and whose src matches.
     * Images that already carry an alt (even an empty, decorative one) are never touched.
     *
     * @return array [new_content, number_of_images_changed]
     */
    public static function set_img_alt( $content, $src, $alt ) {
        $count = 0;
        $esc   = htmlspecialchars( (string) $alt, ENT_QUOTES, 'UTF-8' );
        $new   = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ( $m ) use ( $src, $esc, &$count ) {
                $tag = $m[0];
                if ( preg_match( '/\salt\s*=/i', $tag ) ) { return $tag; }
                if ( ! preg_match( '/\s(?:data-)?src\s*=\s*(["\'])(.*?)\1/is', $tag, $sm ) ) { return $tag; }
                if ( html_entity_decode( $sm[2], ENT_QUOTES, 'UTF-8' ) !== $src ) { return $tag; }
                $count++;
                return preg_replace( '/^<img\b/i', '<img alt="' . $esc . '"', $tag, 1 );
            },
            (string) $content
        );
        return array( null === $new ? (string) $content : $new, $count );
    }

    /** All hrefs inside an HTML fragment. */
    public static function hrefs( $html ) {
        preg_match_all( '/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/is', (string) $html, $m );
        return array_map( function ( $h ) { return html_entity_decode( $h, ENT_QUOTES, 'UTF-8' ); }, $m[2] );
    }
}
