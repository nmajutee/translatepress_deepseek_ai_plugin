<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TRP_SEO_Generator
 * - Generates SEO title and meta description from a provided primary keyword (per-language).
 * - Applies safe length limits and simple templates.
 * - Fires filter 'trp_use_llm_for_seo' (bool) and 'trp_llm_generate_seo' for optional LLM usage.
 */

class TRP_SEO_Generator {

    // Title and description length limits
    const TITLE_MAX = 60;
    const DESC_MAX  = 155;

    // Public helpers used by metabox/term classes
    public static function generate_and_save_for_post( $post_id, $lang_code, $keyword ) {
        $title = self::generate_title( $keyword, $lang_code, $post_id );
        $desc  = self::generate_description( $keyword, $lang_code, $post_id );

        update_post_meta( $post_id, 'trp_seo_title_' . $lang_code, $title );
        update_post_meta( $post_id, 'trp_seo_description_' . $lang_code, $desc );
    }

    public static function generate_and_save_for_term( $term_id, $lang_code, $keyword ) {
        $title = self::generate_title( $keyword, $lang_code, $term_id, 'term' );
        $desc  = self::generate_description( $keyword, $lang_code, $term_id, 'term' );

        update_term_meta( $term_id, 'trp_seo_title_' . $lang_code, $title );
        update_term_meta( $term_id, 'trp_seo_description_' . $lang_code, $desc );
    }

    public static function generate_title( $keyword, $lang_code, $object_id = 0, $object_type = 'post' ) {
        $keyword = self::normalize_keyword( $keyword );
        // Allow LLM/generative override
        if ( apply_filters( 'trp_use_llm_for_seo', false, $keyword, $lang_code, $object_id, $object_type ) ) {
            $out = apply_filters( 'trp_llm_generate_seo', array(
                'type' => 'title',
                'keyword' => $keyword,
                'language' => $lang_code,
                'object_id' => $object_id,
                'object_type' => $object_type,
            ) );
            if ( is_string( $out ) && $out !== '' ) {
                return self::truncate_title( $out );
            }
        }

        $site = get_bloginfo( 'name' );
        // Prefer "Keyword - Site Name"
        $title = $keyword . ' - ' . $site;
        return self::truncate_title( $title );
    }

    public static function generate_description( $keyword, $lang_code, $object_id = 0, $object_type = 'post' ) {
        $keyword = self::normalize_keyword( $keyword );

        if ( apply_filters( 'trp_use_llm_for_seo', false, $keyword, $lang_code, $object_id, $object_type ) ) {
            $out = apply_filters( 'trp_llm_generate_seo', array(
                'type' => 'description',
                'keyword' => $keyword,
                'language' => $lang_code,
                'object_id' => $object_id,
                'object_type' => $object_type,
            ) );
            if ( is_string( $out ) && $out !== '' ) {
                return self::truncate_description( $out );
            }
        }

        // Simple template optimized for keyword inclusion + CTA
        $site = get_bloginfo( 'name' );
        $desc = sprintf( '%s — %s. %s', $keyword, sprintf( __( 'Find expert resources and practical tips on %s', 'translatepress-multilingual' ), $keyword ), $site );
        return self::truncate_description( $desc );
    }

    // Normalizes the keyword: trim, strip tags
    protected static function normalize_keyword( $keyword ) {
        $keyword = trim( wp_strip_all_tags( $keyword ) );
        // Keep length reasonable
        if ( mb_strlen( $keyword ) > 120 ) {
            $keyword = mb_substr( $keyword, 0, 120 );
        }
        return $keyword;
    }

    protected static function truncate_title( $title ) {
        $title = trim( wp_strip_all_tags( $title ) );
        if ( mb_strlen( $title ) <= self::TITLE_MAX ) {
            return $title;
        }
        // attempt to keep keyword start, cut at word boundary
        $trunc = mb_substr( $title, 0, self::TITLE_MAX - 1 );
        $trunc = preg_replace( '/\s+\S*$/u', '', $trunc );
        return rtrim( $trunc ) . '…';
    }

    protected static function truncate_description( $desc ) {
        $desc = trim( wp_strip_all_tags( $desc ) );
        if ( mb_strlen( $desc ) <= self::DESC_MAX ) {
            return $desc;
        }
        $trunc = mb_substr( $desc, 0, self::DESC_MAX - 3 );
        $trunc = preg_replace( '/\s+\S*$/u', '', $trunc );
        return rtrim( $trunc ) . '...';
    }
}