<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Image alt-text analysis. Pure logic: no WordPress state, so it is unit-tested on its own.
 *
 * Input is the image list produced by SEO_Pilot_Parser::parse() plus, optionally, data the
 * WordPress side looked up (Media Library alt text, file sizes). Each issue has a basis:
 *   measured  - found by comparing or counting (no alt attribute, empty alt inside a link, duplicates).
 *   heuristic - a rule of thumb (long alt, "image of ...", generic wording, repeated words).
 * Alt text is first an accessibility field; nothing here treats it as a place for keywords.
 */
final class SEO_Pilot_Images {
    const ALT_LONG   = 125;
    const LARGE_FILE = 500000; // bytes; only used where WordPress recorded a size

    public static function hash( $src ) {
        return SEO_Pilot_Parser::src_hash( $src );
    }

    private static function issue( $id, $basis, $level, $text ) {
        return array( 'id' => $id, 'basis' => $basis, 'level' => $level, 'text' => $text );
    }

    /**
     * Quality problems of an existing, non-empty alt text.
     *
     * @return array[] issue rows (id, basis, level, text)
     */
    public static function alt_flags( $alt, $page_title = '', $caption = '' ) {
        $alt = trim( (string) $alt );
        $out = array();
        if ( '' === $alt ) { return $out; }
        $n = SEO_Pilot_Text::normalize( $alt );

        if ( SEO_Pilot_Text::looks_like_filename_alt( $alt ) ) {
            $out[] = self::issue( 'filename', 'heuristic', 'review', 'The alt text looks like a file name, which does not describe the picture.' );
        }
        $len = SEO_Pilot_Text::len( $alt );
        if ( $len > self::ALT_LONG ) {
            $out[] = self::issue( 'too_long', 'heuristic', 'review', sprintf( 'The alt text is %d characters. Screen readers read all of it; about %d or fewer is a common guideline. Put long explanations in the page text or a caption.', $len, self::ALT_LONG ) );
        }
        if ( 1 === preg_match( '/^(image|picture|photo|photograph|graphic|screenshot|icon)\s+(of|showing|for)\b/u', $n ) || 1 === preg_match( '/^(صور[ةه]?|لقط[ةه])\s/u', $n ) ) {
            $out[] = self::issue( 'redundant_prefix', 'heuristic', 'review', 'It starts with "image of", "photo of" or similar. Screen readers already announce that this is an image, so the words add nothing.' );
        }
        $generic = array( 'image', 'photo', 'picture', 'graphic', 'banner', 'img', 'thumbnail', 'screenshot', 'icon', 'صورة', 'صور', 'لقطة', 'شعار' );
        foreach ( $generic as $g ) {
            if ( $n === SEO_Pilot_Text::normalize( $g ) ) {
                $out[] = self::issue( 'generic', 'heuristic', 'review', 'The alt text is a single generic word and does not say what the image shows.' );
                break;
            }
        }
        if ( SEO_Pilot_Text::urls( $alt ) || 1 === preg_match( '/\bwww\./i', $alt ) ) {
            $out[] = self::issue( 'has_url', 'heuristic', 'review', 'The alt text contains a web address. Alt text should describe the image, not advertise a link.' );
        }
        // Stuffing pattern: one meaningful word three or more times, or a long list of comma-separated phrases.
        $stop = SEO_Pilot_Text::stopwords();
        $freq = array();
        foreach ( SEO_Pilot_Text::words( $n ) as $w ) {
            if ( SEO_Pilot_Text::len( $w ) >= 3 && ! isset( $stop[ $w ] ) ) { $freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1; }
        }
        $repeat = $freq ? max( $freq ) : 0;
        $parts  = preg_split( '/[,،|;]+/u', $alt, -1, PREG_SPLIT_NO_EMPTY );
        if ( $repeat >= 3 || ( is_array( $parts ) && count( $parts ) >= 5 ) ) {
            $out[] = self::issue( 'repeated_words', 'heuristic', 'review', 'The alt text repeats the same words or reads like a list of keywords. Write one natural description instead.' );
        }
        $t = SEO_Pilot_Text::normalize( $page_title );
        if ( '' !== $t && SEO_Pilot_Text::len( $t ) >= 3 && $n === $t ) {
            $out[] = self::issue( 'same_as_title', 'measured', 'review', 'The alt text is identical to the page title, so it probably does not describe this particular image.' );
        }
        $c = SEO_Pilot_Text::normalize( $caption );
        if ( '' !== $c && $n === $c ) {
            $out[] = self::issue( 'same_as_caption', 'measured', 'info', 'The alt text is identical to the visible caption, so screen readers read the same text twice.' );
        }
        return $out;
    }

    /**
     * @param array[] $images Parser::parse()['images'] (src, has_alt, alt, context, in_link, link_text, attachment_id, caption)
     * @param array   $opts   page_title, image_data ( by_id[id] => library_alt, filesize ; featured => id, alt )
     * @return array items[], featured (or null), counts
     */
    public static function analyze( array $images, array $opts = array() ) {
        $opts  = array_merge( array( 'page_title' => '', 'image_data' => array() ), $opts );
        $data  = array_merge( array( 'by_id' => array(), 'featured' => null ), (array) $opts['image_data'] );
        $items = array();
        $seen  = array();

        foreach ( $images as $img ) {
            $src = isset( $img['src'] ) ? (string) $img['src'] : '';
            if ( '' === $src ) { continue; }
            $img = array_merge( array( 'has_alt' => false, 'alt' => '', 'context' => '', 'in_link' => false, 'link_text' => '', 'attachment_id' => 0, 'caption' => '' ), $img );
            $key = self::hash( $src ) . '|' . ( $img['has_alt'] ? 'a:' . $img['alt'] : 'none' );
            if ( isset( $seen[ $key ] ) ) { continue; } // the same image twice with the same alt is one row
            $seen[ $key ] = true;

            $aid = (int) $img['attachment_id'];
            $lib = ( $aid && isset( $data['by_id'][ $aid ] ) ) ? $data['by_id'][ $aid ] : array();
            $lib_alt = isset( $lib['library_alt'] ) ? trim( (string) $lib['library_alt'] ) : '';
            $size    = isset( $lib['filesize'] ) ? (int) $lib['filesize'] : 0;

            $issues = array();
            $kind   = 'ok';
            if ( ! $img['has_alt'] ) {
                $kind     = 'missing';
                $issues[] = self::issue( 'missing', 'measured', 'fix', 'No alt attribute at all. Screen readers fall back to the file name.' );
            } elseif ( '' === $img['alt'] ) {
                if ( $img['in_link'] && '' === trim( (string) $img['link_text'] ) ) {
                    $kind     = 'empty_in_link';
                    $issues[] = self::issue( 'empty_in_link', 'measured', 'fix', 'The image is the only content of a link and its alt text is empty, so the link has no accessible name.' );
                } else {
                    $kind = 'decorative';
                }
            } else {
                $kind  = 'has_alt';
                $issues = self::alt_flags( $img['alt'], $opts['page_title'], $img['caption'] );
            }
            if ( $size > self::LARGE_FILE ) {
                $issues[] = self::issue( 'large_file', 'heuristic', 'info', sprintf( 'The image file is about %s. Large files slow a page down; compressing or resizing may help. (Size as recorded by WordPress.)', self::format_bytes( $size ) ) );
            }
            if ( '' !== $lib_alt && ( 'missing' === $kind || 'empty_in_link' === $kind ) ) {
                $issues[] = self::issue( 'library_available', 'measured', 'info', 'The Media Library already has alt text for this image: "' . $lib_alt . '".' );
            } elseif ( '' !== $lib_alt && 'has_alt' === $kind && SEO_Pilot_Text::normalize( $lib_alt ) !== SEO_Pilot_Text::normalize( $img['alt'] ) ) {
                $issues[] = self::issue( 'library_differs', 'measured', 'info', 'The Media Library alt text differs from the one used on this page. That is fine if this page needs its own wording.' );
            }

            $items[] = array(
                'hash' => self::hash( $src ), 'src' => $src, 'has_alt' => (bool) $img['has_alt'], 'alt' => (string) $img['alt'], 'kind' => $kind,
                'context' => (string) $img['context'], 'caption' => (string) $img['caption'], 'in_link' => (bool) $img['in_link'], 'link_text' => (string) $img['link_text'],
                'attachment_id' => $aid, 'library_alt' => $lib_alt, 'filesize' => $size, 'issues' => $issues,
            );
        }

        // Same alt text on different images.
        $by_alt = array();
        foreach ( $items as $i => $it ) {
            if ( 'has_alt' !== $it['kind'] ) { continue; }
            $by_alt[ SEO_Pilot_Text::normalize( $it['alt'] ) ][] = $i;
        }
        foreach ( $by_alt as $alt_key => $idxs ) {
            $srcs = array();
            foreach ( $idxs as $i ) { $srcs[ $items[ $i ]['hash'] ] = true; }
            if ( count( $srcs ) < 2 ) { continue; }
            foreach ( $idxs as $i ) {
                $items[ $i ]['issues'][] = self::issue( 'duplicate', 'measured', 'review', sprintf( 'The same alt text is used on %d different images on this page. Each image should have its own description.', count( $srcs ) ) );
            }
        }

        $counts = array( 'images' => count( $items ), 'missing' => 0, 'empty_in_link' => 0, 'decorative' => 0, 'review' => 0, 'ok' => 0 );
        foreach ( $items as $i => $it ) {
            $attention = false; $review = false;
            foreach ( $it['issues'] as $is ) {
                if ( 'fix' === $is['level'] ) { $attention = true; }
                if ( 'review' === $is['level'] ) { $review = true; }
            }
            $items[ $i ]['needs_attention'] = $attention || $review;
            if ( 'missing' === $it['kind'] ) { $counts['missing']++; }
            elseif ( 'empty_in_link' === $it['kind'] ) { $counts['empty_in_link']++; }
            elseif ( 'decorative' === $it['kind'] ) { $counts['decorative']++; }
            if ( $review && ! $attention ) { $counts['review']++; }
            if ( ! $attention && ! $review ) { $counts['ok']++; }
        }

        $featured = null;
        if ( is_array( $data['featured'] ) && ! empty( $data['featured']['id'] ) ) {
            $featured = array(
                'id' => (int) $data['featured']['id'], 'alt' => trim( (string) $data['featured']['alt'] ),
                'filesize' => isset( $data['featured']['filesize'] ) ? (int) $data['featured']['filesize'] : 0,
            );
        }
        return array( 'items' => array_slice( $items, 0, 60 ), 'featured' => $featured, 'counts' => $counts );
    }

    public static function format_bytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes >= 1048576 ) { return round( $bytes / 1048576, 1 ) . ' MB'; }
        return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
    }

    /** Short readable name for an image source (the file name), for lists. */
    public static function label( $src ) {
        $path = (string) parse_url( (string) $src, PHP_URL_PATH );
        $base = basename( $path );
        return '' !== $base ? $base : (string) $src;
    }
}
