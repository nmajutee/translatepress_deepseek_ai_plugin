<?php
/**
 * FILE: includes/class-translated-pages-cache.php
 * PURPOSE: DB-backed full-page cache for translated HTML.
 * INTEGRATION NOTES:
 *  - Save this file exactly at the path above.
 *  - Include it in plugin bootstrap (e.g. add to load_dependencies() in `class-translate-press.php`):
 *      require_once TRP_PLUGIN_DIR . 'includes/class-translated-pages-cache.php';
 *  - When final translated HTML is produced, call set_cached_page(url, language, html).
 *  - Use invalidate_post($post_id) on `save_post` to clear cache for updated posts.
 *
 * LABELS in this file:
 *  - maybe_create_table() -> ensures table exists.
 *  - get_cached_page()    -> fetch cached HTML for URL+language.
 *  - set_cached_page()    -> insert/update cached HTML.
 *  - invalidate_post()    -> helper to clear a post's cache.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TRP_Translated_Pages_Cache {
    protected $db;
    protected $table;

    public function __construct(){
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $this->db->prefix . 'trp_translated_pages';
        $this->maybe_create_table();
    }

    // LABEL: maybe_create_table - creates wp_trp_translated_pages if missing
    protected function maybe_create_table(){
        if ( $this->db->get_var( "SHOW TABLES LIKE '{$this->table}'" ) !== $this->table ) {
            $charset_collate = $this->db->get_charset_collate();
            $sql = "CREATE TABLE `{$this->table}` (
                id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                url_hash varchar(64) NOT NULL,
                url text NOT NULL,
                language varchar(16) NOT NULL,
                content longtext NOT NULL,
                last_updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                UNIQUE KEY url_lang_unique (url_hash, language)
            ) {$charset_collate};";
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }
    }

    protected function url_hash( $url ){
        return hash( 'sha256', $url );
    }

    // LABEL: get_cached_page - returns cached HTML or null/false
    public function get_cached_page( $url, $language ){
        $uh = $this->url_hash( $url );
        $prepared = $this->db->prepare( "SELECT content FROM `{$this->table}` WHERE url_hash = %s AND language = %s LIMIT 1", $uh, $language );
        return $this->db->get_var( $prepared );
    }

    // LABEL: set_cached_page - insert or update cached HTML
    public function set_cached_page( $url, $language, $content ){
        $now = current_time( 'mysql' );
        $uh = $this->url_hash( $url );

        $existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM `{$this->table}` WHERE url_hash = %s AND language = %s LIMIT 1", $uh, $language ) );
        if ( $existing ) {
            $this->db->update(
                $this->table,
                array( 'content' => $content, 'last_updated' => $now, 'url' => $url ),
                array( 'id' => $existing ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $this->db->insert(
                $this->table,
                array( 'url_hash' => $uh, 'url' => $url, 'language' => $language, 'content' => $content, 'last_updated' => $now ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
        }
    }

    // LABEL: invalidate_url - remove cached entries for URL (any language)
    public function invalidate_url( $url ){
        $uh = $this->url_hash( $url );
        $this->db->delete( $this->table, array( 'url_hash' => $uh ), array( '%s' ) );
    }

    // LABEL: invalidate_post - helper to clear cache for a post permalink
    public function invalidate_post( $post_id ){
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $this->invalidate_url( $permalink );
        }
    }
}