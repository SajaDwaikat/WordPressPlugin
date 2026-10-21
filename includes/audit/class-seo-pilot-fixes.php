<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Catalog of the fixes the Fix assistant can prepare, and the rules around them. Pure logic.
 *
 * Every fix has a risk class that decides whether it is ticked by default:
 *   A - no AI judgement (text the site owner already wrote, e.g. Media Library alt text)
 *   B - AI fills a field that is empty
 *   C - changes text a person already wrote (title/description/alt that exist)
 * Nothing here applies anything: it lists, explains and groups. Applying goes through Approval.
 */
final class SEO_Pilot_Fixes {

    public static function areas() {
        return array( 'title' => 'SEO title', 'meta' => 'Meta description', 'images' => 'Image alt text', 'language' => 'Multilingual' );
    }

    public static function risk_text( $risk ) {
        $m = array( 'A' => 'Low risk: uses text you already have.', 'B' => 'Fills an empty field with a draft you review.', 'C' => 'Replaces text a person wrote. Review it carefully.' );
        return isset( $m[ $risk ] ) ? $m[ $risk ] : '';
    }

    /**
     * @return array id => area, label, risk, gen ("ai:<task>" | "local:alt_library" | "ai:alt_vision"), findings[], needs[], rec_types[]
     */
    public static function defs() {
        return array(
            'meta_fill'     => array( 'area' => 'meta', 'label' => 'Write the missing meta description', 'risk' => 'B', 'gen' => 'ai:meta_description',
                'findings' => array( 'meta_missing' ), 'needs' => array( 'ai', 'writable_meta' ), 'rec_types' => array( 'meta_description' ) ),
            'meta_improve'  => array( 'area' => 'meta', 'label' => 'Rewrite the meta description', 'risk' => 'C', 'gen' => 'ai:meta_description',
                'findings' => array( 'meta_long', 'meta_short', 'duplicate_meta' ), 'needs' => array( 'ai', 'writable_meta' ), 'rec_types' => array( 'meta_description' ) ),
            'title_improve' => array( 'area' => 'title', 'label' => 'Rewrite the SEO title', 'risk' => 'C', 'gen' => 'ai:seo_title',
                'findings' => array( 'title_long', 'title_short', 'title_width_estimate', 'title_generic', 'title_near_duplicate', 'duplicate_title' ), 'needs' => array( 'ai', 'writable_title' ), 'rec_types' => array( 'seo_title' ) ),
            'alt_library'   => array( 'area' => 'images', 'label' => 'Use the Media Library alt text', 'risk' => 'A', 'gen' => 'local:alt_library',
                'findings' => array( 'alt_missing', 'alt_empty_in_link' ), 'needs' => array( 'library' ), 'rec_types' => array( 'alt_text' ) ),
            'alt_ai'        => array( 'area' => 'images', 'label' => 'Draft alt text with AI (reads the image)', 'risk' => 'B', 'gen' => 'ai:alt_vision',
                'findings' => array( 'alt_missing', 'alt_empty_in_link', 'alt_suspicious' ), 'needs' => array( 'images', 'alt_candidates' ), 'rec_types' => array( 'alt_text' ) ),
            'localize'      => array( 'area' => 'language', 'label' => 'Localize the title and description from the linked translation', 'risk' => 'C', 'gen' => 'ai:localize',
                'findings' => array( 'lang_title_identical', 'lang_meta_identical', 'lang_meta_mismatch' ), 'needs' => array( 'ai', 'source', 'writable_title', 'writable_meta' ), 'rec_types' => array( 'seo_title', 'meta_description', 'keywords_local' ) ),
        );
    }

    /** Why a need is not met, or '' when it is. */
    private static function unmet( $need, array $ctx ) {
        switch ( $need ) {
            case 'ai':             return ! empty( $ctx['ai_ready'] ) ? '' : ( ! empty( $ctx['ai_problem'] ) ? $ctx['ai_problem'] : 'The AI provider is not configured (Settings).' );
            case 'images':         return ! empty( $ctx['images_ready'] ) ? '' : ( ! empty( $ctx['images_problem'] ) ? $ctx['images_problem'] : 'Sending images to the AI provider is switched off (Settings → Images).' );
            case 'library':        return ! empty( $ctx['library_count'] ) ? '' : 'The Media Library has no alt text for the images that need it.';
            case 'alt_candidates': return ! empty( $ctx['alt_ai_count'] ) ? '' : 'No image here can be drafted: AI drafts need Media Library images without a usable alt text and without a pending or accepted entry.';
            case 'source':         return ! empty( $ctx['has_source'] ) ? '' : 'Needs a translation of this page in another language. Link one in the Language panel.';
            case 'writable_title': return ( ! isset( $ctx['title_writable'] ) || $ctx['title_writable'] ) ? '' : ( isset( $ctx['owner_label'] ) ? $ctx['owner_label'] : 'Another plugin' ) . ' manages the SEO title and SEO Pilot cannot write to it.';
            case 'writable_meta':  return ( ! isset( $ctx['meta_writable'] ) || $ctx['meta_writable'] ) ? '' : ( isset( $ctx['owner_label'] ) ? $ctx['owner_label'] : 'Another plugin' ) . ' manages the meta description and SEO Pilot cannot write to it.';
        }
        return '';
    }

    /**
     * Fixes that apply to an audited page.
     *
     * @param array $audit Saved audit (findings[]).
     * @param array $ctx   ai_ready, ai_problem, images_ready, images_problem, library_count, alt_ai_count, has_source,
     *                     title_writable, meta_writable, owner_label
     * @return array[] id, area, area_label, label, risk, risk_text, gen, rec_types, targets[] (finding ids present), reasons[] (finding texts),
     *                 available (bool), unavailable (string), checked (bool)
     */
    public static function for_audit( array $audit, array $ctx ) {
        $present = array();
        foreach ( isset( $audit['findings'] ) ? (array) $audit['findings'] : array() as $f ) { $present[ $f['id'] ] = $f['what']; }
        $areas = self::areas();
        $out   = array();
        foreach ( self::defs() as $id => $d ) {
            $targets = array_values( array_intersect( $d['findings'], array_keys( $present ) ) );
            if ( ! $targets ) { continue; }
            $why = '';
            foreach ( $d['needs'] as $need ) { $why = self::unmet( $need, $ctx ); if ( '' !== $why ) { break; } }
            $out[] = array(
                'id' => $id, 'area' => $d['area'], 'area_label' => $areas[ $d['area'] ], 'label' => $d['label'], 'risk' => $d['risk'], 'risk_text' => self::risk_text( $d['risk'] ),
                'gen' => $d['gen'], 'rec_types' => $d['rec_types'], 'targets' => $targets, 'reasons' => array_map( function ( $t ) use ( $present ) { return $present[ $t ]; }, $targets ),
                'available' => '' === $why, 'unavailable' => $why, 'checked' => '' === $why && in_array( $d['risk'], array( 'A', 'B' ), true ),
            );
        }
        $order = array_flip( array_keys( $areas ) );
        usort( $out, function ( $x, $y ) use ( $order ) { return ( $order[ $x['area'] ] <=> $order[ $y['area'] ] ) ?: strcmp( $x['risk'], $y['risk'] ); } );
        return $out;
    }

    /**
     * What to run for a set of chosen fixes. Localizing already produces a title and a description,
     * so the separate title/description tasks are dropped when it is chosen (they would overwrite each other).
     *
     * @param string[] $fix_ids
     * @return array ai_tasks[] (prompt task keys), library (bool), alt_vision (bool)
     */
    public static function plan_tasks( array $fix_ids ) {
        $defs = self::defs();
        $tasks = array(); $lib = false; $vision = false;
        foreach ( $fix_ids as $id ) {
            if ( ! isset( $defs[ $id ] ) ) { continue; }
            $gen = $defs[ $id ]['gen'];
            if ( 'ai:alt_vision' === $gen ) { $vision = true; }
            elseif ( 'local:alt_library' === $gen ) { $lib = true; }
            elseif ( 0 === strpos( $gen, 'ai:' ) ) { $tasks[] = substr( $gen, 3 ); }
        }
        $tasks = array_values( array_unique( $tasks ) );
        if ( in_array( 'localize', $tasks, true ) ) { $tasks = array_values( array_diff( $tasks, array( 'seo_title', 'meta_description' ) ) ); }
        return array( 'ai_tasks' => $tasks, 'library' => $lib, 'alt_vision' => $vision );
    }

    /** Finding ids the given recommendation types are meant to resolve (limited to those present before). */
    public static function targets_for_rec_types( array $types, array $before_ids ) {
        $map = array(
            'meta_description' => array( 'meta_missing', 'meta_long', 'meta_short', 'duplicate_meta', 'lang_meta_identical', 'lang_meta_mismatch' ),
            'seo_title'        => array( 'title_long', 'title_short', 'title_width_estimate', 'title_generic', 'title_near_duplicate', 'duplicate_title', 'lang_title_identical', 'lang_meta_mismatch' ),
            'alt_text'         => array( 'alt_missing', 'alt_suspicious', 'alt_empty_in_link', 'alt_quality', 'alt_duplicate' ),
        );
        $out = array();
        foreach ( $types as $t ) { if ( isset( $map[ $t ] ) ) { $out = array_merge( $out, $map[ $t ] ); } }
        return array_values( array_intersect( array_unique( $out ), $before_ids ) );
    }

    /**
     * Should a prepared proposal be ticked by default? Text a person wrote, or the site's own Media Library
     * text, is ticked unless it still asks for a check. AI text is ticked only when it fills an empty field and
     * nothing in it asks for a second look; an AI alt text always asks for a look at the image, so it is not.
     *
     * @param array $rec rec_type, source, original, proposed, flags (verify[], notes[], mode, decorative)
     */
    public static function rec_checked( array $rec ) {
        $flags = isset( $rec['flags'] ) ? (array) $rec['flags'] : array();
        if ( in_array( $rec['source'], array( 'user', 'local' ), true ) ) { return empty( $flags['verify'] ); }
        if ( ! empty( $flags['verify'] ) || ! empty( $flags['notes'] ) ) { return false; }
        if ( 'alt_text' === $rec['rec_type'] ) { return isset( $flags['mode'] ) && 'add' === $flags['mode']; }
        return '' === trim( (string) $rec['original'] );
    }
}
