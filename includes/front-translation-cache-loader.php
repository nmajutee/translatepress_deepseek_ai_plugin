<?php
/**
 * FILE: includes/front-translation-cache-loader.php
 * PURPOSE: Serve cached translated pages early in the request (template_redirect).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'template_redirect', function() {
    if ( is_admin() ) {
        return;
    }

    if ( ! class_exists( 'TRP_Translate_Press' ) ) {
        return;
    }

    $trp = TRP_Translate_Press::get_trp_instance();
    if ( ! $trp ) {
        return;
    }

    $settings = $trp->get_component( 'settings' )->get_settings();

    // Only proceed if machine translation is enabled
    if ( empty( $settings['trp_machine_translation_settings']['machine-translation'] ) || $settings['trp_machine_translation_settings']['machine-translation'] !== 'yes' ) {
        return;
    }

    // If Deep Seek is selected as engine, ensure a per-site API key is configured
    $engine = isset( $settings['trp_machine_translation_settings']['translation-engine'] ) ? $settings['trp_machine_translation_settings']['translation-engine'] : '';
    $api_key = isset( $settings['trp_machine_translation_settings']['deepseek_api_key'] ) ? $settings['trp_machine_translation_settings']['deepseek_api_key'] : '';

    if ( $engine === 'deepseek' && empty( $api_key ) ) {
        // No key configured for Deep Seek on this site — do not attempt to serve/translate
        return;
    }

    // Determine current language code (site-specific detection may vary)
    $language_code = isset( $_GET['lang'] ) ? sanitize_text_field( $_GET['lang'] ) : ( isset( $_COOKIE['trp_language'] ) ? sanitize_text_field( $_COOKIE['trp_language'] ) : $settings['default-language'] );

    // Build canonical URL for cache key (preserve query string)
    $scheme = is_ssl() ? 'https' : 'http';
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    // Ensure cache class is loaded
    if ( ! class_exists( 'TRP_Translated_Pages_Cache' ) ) {
        $file = TRP_PLUGIN_DIR . 'includes/class-translated-pages-cache.php';
        if ( file_exists( $file ) ) {
            include_once $file;
        } else {
            return;
        }
    }

    $cache = new TRP_Translated_Pages_Cache();
    $cached = $cache->get_cached_page( $url, $language_code );
    if ( $cached ) {
        echo $cached;
        exit;
    }

    // no cache -> let normal rendering happen; renderer will write cache after translation
}, 5 );