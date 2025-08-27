<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds Primary Keyword field to taxonomy term add/edit screens and auto-generates SEO meta.
 * Use for categories, tags, product_cat, product_tag.
 */

class TRP_Term_Primary_Keyword {

    protected $taxonomies = array( 'category', 'post_tag' );

    public function __construct() {
        if ( taxonomy_exists( 'product_cat' ) ) {
            $this->taxonomies[] = 'product_cat';
        }
        if ( taxonomy_exists( 'product_tag' ) ) {
            $this->taxonomies[] = 'product_tag';
        }

        foreach ( $this->taxonomies as $tax ) {
            add_action( $tax . '_add_form_fields', array( $this, 'add_term_field' ), 10, 2 );
            add_action( $tax . '_edit_form_fields', array( $this, 'edit_term_field' ), 10, 2 );
            add_action( 'created_' . $tax, array( $this, 'save_term' ), 10, 2 );
            add_action( 'edited_' . $tax, array( $this, 'save_term' ), 10, 2 );
        }
    }

    protected function get_languages() {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) {
            return array();
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        if ( $trp ) {
            $lang_component = $trp->get_component( 'languages' );
            if ( $lang_component && method_exists( $lang_component, 'get_all_language_codes' ) ) {
                return (array) $lang_component->get_all_language_codes();
            }
        }
        return array();
    }

    public function add_term_field( $taxonomy ) {
        $langs = $this->get_languages();
        echo '<div class="form-field"><label>' . esc_html__( 'Primary Keywords (per language)', 'translatepress-multilingual' ) . '</label>';
        foreach ( $langs as $code ) {
            printf(
                '<input type="text" name="trp_primary_keyword_%s" placeholder="%s" style="width:100%;margin-bottom:6px;"/>',
                esc_attr( $code ),
                esc_attr( $code . ' ' . __( 'keyword', 'translatepress-multilingual' ) )
            );
        }
        echo '<p class="description">' . esc_html__( 'Optional: primary keyword per language. SEO title and description will be auto-generated.', 'translatepress-multilingual' ) . '</p></div>';
    }

    public function edit_term_field( $term, $taxonomy ) {
        $langs = $this->get_languages();
        echo '<tr class="form-field"><th scope="row"><label>' . esc_html__( 'Primary Keywords (per language)', 'translatepress-multilingual' ) . '</label></th><td>';
        foreach ( $langs as $code ) {
            $meta_key = 'trp_term_primary_keyword_' . $code;
            $value = get_term_meta( $term->term_id, $meta_key, true );
            printf(
                '<input type="text" name="%s" value="%s" placeholder="%s" style="width:100%;margin-bottom:6px;"/>',
                esc_attr( 'trp_primary_keyword_' . $code ),
                esc_attr( $value ),
                esc_attr( $code . ' ' . __( 'keyword', 'translatepress-multilingual' ) )
            );
        }
        echo '<p class="description">' . esc_html__( 'Optional: primary keyword per language. SEO title and description will be auto-generated.', 'translatepress-multilingual' ) . '</p></td></tr>';
    }

    public function save_term( $term_id ) {
        $langs = $this->get_languages();
        foreach ( $langs as $code ) {
            $field = 'trp_primary_keyword_' . $code;
            if ( isset( $_POST[ $field ] ) ) {
                $val = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( $val === '' ) {
                    delete_term_meta( $term_id, 'trp_term_primary_keyword_' . $code );
                } else {
                    update_term_meta( $term_id, 'trp_term_primary_keyword_' . $code, $val );
                    TRP_SEO_Generator::generate_and_save_for_term( $term_id, $code, $val );
                }
            }
        }
    }
}

// instantiate
new TRP_Term_Primary_Keyword();