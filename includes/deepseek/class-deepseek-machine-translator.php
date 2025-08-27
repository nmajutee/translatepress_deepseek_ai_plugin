<?php
/**
 * FILE: includes/deepseek/class-deepseek-machine-translator.php
 * PURPOSE: Deep Seek / OpenRouter-compatible machine translation engine.
 *
 * Notes:
 * - Reads API key and endpoint from trp_machine_translation_settings (deepseek_api_key, deepseek_api_endpoint).
 * - Uses Bearer Authorization by default. If your provider requires the key in the request body, adapt the body.
 * - Response parsing is tolerant to common shapes: { data: [{ translatedText: "..." }, ...] } or { translations: ["..."] }.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TRP_Deepseek_Machine_Translator extends TRP_Machine_Translator {

    // default placeholder (can be overridden per-site via settings)
    protected $default_endpoint = 'https://api.deepseek.example/v1/translate';

    /**
     * Send request to Deep Seek / OpenRouter API.
     *
     * Returns WP_Error on missing config or wp_remote_post response array on success.
     */
    public function send_request( $source_iso, $target_iso, $strings ) {
        $api_key = $this->get_api_key();

        // endpoint override from settings or default
        $endpoint = ! empty( $this->settings['trp_machine_translation_settings']['deepseek_api_endpoint'] )
            ? $this->settings['trp_machine_translation_settings']['deepseek_api_endpoint']
            : $this->default_endpoint;

        if ( empty( $api_key ) ) {
            return new WP_Error( 'trp_no_api_key', __( 'Deep Seek API key is not configured. Please add it in plugin settings.', 'translatepress-multilingual' ) );
        }

        if ( empty( $endpoint ) ) {
            return new WP_Error( 'trp_no_endpoint', __( 'Deep Seek API endpoint is not configured. Please add it in plugin settings.', 'translatepress-multilingual' ) );
        }

        // Default payload shape - adapt if your API requires different fields
        $body = array(
            'source' => $source_iso,
            'target' => $target_iso,
            'q'      => array_values( $strings ),
        );

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                // Use Bearer auth; do not hardcode API keys in code. Keys are read from settings.
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        );

        return wp_remote_post( $endpoint, $args );
    }

    /**
     * Translate an array of strings while preserving keys.
     */
    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code === null ) {
            $source_language_code = $this->settings['default-language'];
        }

        if ( empty( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }

        $source_iso = isset( $this->machine_translation_codes[ $source_language_code ] ) ? $this->machine_translation_codes[ $source_language_code ] : $source_language_code;
        $target_iso = isset( $this->machine_translation_codes[ $target_language_code ] ) ? $this->machine_translation_codes[ $target_language_code ] : $target_language_code;

        $translated_strings = array();

        // chunk payloads
        $chunks = array_chunk( $new_strings, 40, true );
        foreach ( $chunks as $chunk ) {

            $response = $this->send_request( $source_iso, $target_iso, $chunk );

            // logging
            $this->machine_translator_logger->log( array(
                'strings'     => serialize( $chunk ),
                'response'    => is_wp_error( $response ) ? $response->get_error_message() : ( is_array( $response ) ? wp_json_encode( $response ) : (string) $response ),
                'lang_source' => $source_iso,
                'lang_target' => $target_iso,
            ) );

            if ( is_wp_error( $response ) ) {
                // API not configured or network error -> abort
                return array();
            }

            $code = isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 0;
            if ( $code < 200 || $code >= 300 ) {
                return array();
            }

            $body = json_decode( $response['body'], true );
            if ( ! is_array( $body ) ) {
                return array();
            }

            // tolerant parsing: common shapes
            if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
                // shape: { data: [ { translatedText: "..." }, ... ] }
                $i = 0;
                foreach ( $chunk as $key => $orig ) {
                    if ( isset( $body['data'][ $i ]['translatedText'] ) ) {
                        $translated_strings[ $key ] = $body['data'][ $i ]['translatedText'];
                    } elseif ( isset( $body['data'][ $i ]['text'] ) ) {
                        $translated_strings[ $key ] = $body['data'][ $i ]['text'];
                    }
                    $i++;
                }
            } elseif ( isset( $body['translations'] ) && is_array( $body['translations'] ) ) {
                // legacy/simple shape: { translations: ['t1','t2'] }
                $i = 0;
                foreach ( $chunk as $key => $orig ) {
                    if ( isset( $body['translations'][ $i ] ) ) {
                        $translated_strings[ $key ] = $body['translations'][ $i ];
                    }
                    $i++;
                }
            } elseif ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
                // some APIs use { result: [ '...' ] }
                $i = 0;
                foreach ( $chunk as $key => $orig ) {
                    if ( isset( $body['result'][ $i ] ) ) {
                        $translated_strings[ $key ] = $body['result'][ $i ];
                    }
                    $i++;
                }
            } else {
                // unknown shape - abort gracefully
                return array();
            }

            // count quota and possibly stop (logger controls behaviour)
            $this->machine_translator_logger->count_towards_quota( $chunk );
            if ( $this->machine_translator_logger->quota_exceeded() ) {
                break;
            }
        }

        return $translated_strings;
    }

    public function test_request() {
        return $this->send_request( 'en', 'es', array( 'test' ) );
    }

    public function get_api_key() {
        return isset( $this->settings['trp_machine_translation_settings']['deepseek_api_key'] ) ? $this->settings['trp_machine_translation_settings']['deepseek_api_key'] : false;
    }
}