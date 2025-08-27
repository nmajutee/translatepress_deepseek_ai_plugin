<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a per-language Primary Keyword metabox to supported post types
 * and auto-generates SEO title/description on save.
 */

class TRP_Primary_Keyword {

    protected $post_types = array( 'post', 'page' ); // add 'product' if WooCommerce installed

    public function __construct() {
        // include product post type if WooCommerce active
        if ( post_type_exists( 'product' ) ) {
            $this->post_types[] = 'product';
        }

        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
    }

    protected function get_languages() {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) {
            return array();
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        $langs = array();
        if ( $trp && method_exists( $trp, 'get_component' ) ) {
            $lang_component = $trp->get_component( 'languages' );
            if ( $lang_component && method_exists( $lang_component, 'get_all_language_codes' ) ) {
                $langs = (array) $lang_component->get_all_language_codes();
            }
        }
        // fallback: default-language only
        if ( empty( $langs ) ) {
            $settings = get_option( 'trp_settings', array() );
            $default = isset( $settings['default-language'] ) ? $settings['default-language'] : get_bloginfo( 'language' );
            $langs = array( $default );
        }
        return $langs;
    }

    public function add_meta_boxes() {
        foreach ( $this->post_types as $pt ) {
            add_meta_box(
                'trp_primary_keyword_metabox',
                __( 'TranslatePress — Primary Keywords (per language)', 'translatepress-multilingual' ),
                array( $this, 'render_metabox' ),
                $pt,
                'side',
                'default'
            );
        }
    }

    public function render_metabox( $post ) {
        wp_nonce_field( 'trp_primary_keyword_save', 'trp_primary_keyword_nonce' );
        $langs = $this->get_languages();
        echo '<p style="font-size:12px;margin:0 0 8px;">' . esc_html__( 'Provide a primary keyword for each language. The plugin will auto-generate SEO title & meta description from this keyword (you can override later).', 'translatepress-multilingual' ) . '</p>';

        foreach ( $langs as $lang_code ) {
            $meta_key = 'trp_primary_keyword_' . $lang_code;
            $value = get_post_meta( $post->ID, $meta_key, true );
            printf(
                '<label style="display:block;margin-top:6px;font-weight:600;">%s</label>',
                esc_html( $lang_code )
            );
            printf(
                '<input type="text" name="%s" value="%s" style="width:100%;" placeholder="%s"/>',
                esc_attr( $meta_key ),
                esc_attr( $value ),
                esc_attr__( 'Primary keyword for this language', 'translatepress-multilingual' )
            );
        }

        echo '<p style="font-size:12px;margin-top:6px;color:#666;">' . esc_html__( 'Generated SEO fields will use safe length limits. Enable generative LLM integration via filter "trp_use_llm_for_seo" to call an external generator (optional).', 'translatepress-multilingual' ) . '</p>';
    }

    public function save_post( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['trp_primary_keyword_nonce'] ) || ! wp_verify_nonce( $_POST['trp_primary_keyword_nonce'], 'trp_primary_keyword_save' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $langs = $this->get_languages();

        foreach ( $langs as $lang_code ) {
            $meta_key = 'trp_primary_keyword_' . $lang_code;
            if ( isset( $_POST[ $meta_key ] ) ) {
                $val = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );
                if ( $val === '' ) {
                    delete_post_meta( $post_id, $meta_key );
                } else {
                    update_post_meta( $post_id, $meta_key, $val );
                }

                // generate seo fields immediately when a keyword is present
                if ( ! empty( $val ) ) {
                    TRP_SEO_Generator::generate_and_save_for_post( $post_id, $lang_code, $val );
                }
            }
        }
    }
}

// instantiate
new TRP_Primary_Keyword();