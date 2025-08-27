<?php

/**
 * Class TRP_Translation_Render
 *
 * Translates pages.
 */
class TRP_Translation_Render{
    protected $settings;
    protected $machine_translator;
    /* @var TRP_Query */
    protected $trp_query;
    /* @var TRP_Url_Converter */
    protected $url_converter;
    /* @var TRP_Translation_Manager */
    protected $translation_manager;

    /**
     * TRP_Translation_Render constructor.
     *
     * @param array $settings       Settings options.
     */
    public function __construct( $settings ){
        $this->settings = $settings;
    }

    /**
     * Start Output buffer to translate page.
     */
    public function start_output_buffer(){
        global $TRP_LANGUAGE;

        //when we check if is an ajax request in frontend we also set proper REQUEST variables and language global so we need to run this for every buffer
        $ajax_on_frontend = TRP_Translation_Manager::is_ajax_on_frontend();//TODO refactor this function si it just checks and does not set variables

        if( ( is_admin() && !$ajax_on_frontend ) || trp_is_translation_editor( 'true' ) ){
            return;//we have two cases where we don't do anything: we are on the admin side and we are not in an ajax call or we are in the left side of the translation editor
        }
        else {
            mb_http_output("UTF-8");
            if ( $TRP_LANGUAGE == $this->settings['default-language'] && !trp_is_translation_editor() ) {
                ob_start(array($this, 'clear_trp_tags'), 4096);//on default language when we are not in editor we just need to clear any trp tags that could still be present
            } else {
                ob_start(array($this, 'translate_page'));//everywhere else translate the page
            }
        }
    }

    /**
     * Function to hide php errors and notice and instead log them in debug.log so we don't store the notice strings inside the db if WP_DEBUG is on
     */
    public function trp_debug_mode_off(){
        if ( WP_DEBUG ) {
            ini_set('display_errors', 0);
            ini_set('log_errors', 1);
            ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
        }
    }

    /**
     * Forces the language to be the first non default one in the preview translation editor.
     * We're doing this because we need the ID's.
     * Otherwise we're just returning the global $TRP_LANGUAGE
     *
     * @return string       Language code.
     */
    protected function force_language_in_preview(){
        global $TRP_LANGUAGE;
        if ( in_array( $TRP_LANGUAGE, $this->settings['translation-languages'] ) ) {
            if ( $TRP_LANGUAGE == $this->settings['default-language']  ){
                // in the translation editor we need a different language then the default because we need string ID's.
                // so we're forcing it to the first translation language because if it's the default, we're just returning the $output
                if ( isset( $_REQUEST['trp-edit-translation'] ) && $_REQUEST['trp-edit-translation'] == 'preview' )  {
                    if( count( $this->settings['translation-languages'] ) > 1 ){
                        foreach ($this->settings['translation-languages'] as $language) {
                            if ($language != $TRP_LANGUAGE) {
                                // return the first language not default. only used for preview mode
                                return $language;
                            }
                        }
                    }
                    else{
                        return $TRP_LANGUAGE;
                    }
                }
            }else {
                return $TRP_LANGUAGE;
            }
        }
        return false;
    }

    /**
     * Trim strings.
     * This function is kept for backwards compatibility for earlier versions of SEO Pack Add-on
     *
     * @deprecated
     * @param string $string      Raw string.
     * @return string           Trimmed string.
     */
    public function full_trim( $string ) {
        return trp_full_trim( $string );
    }

    /**
     * Preview mode string category name for give node type.
     *
     * @param string $current_node_type         Node type.
     * @return string                           Category name.
     */
    protected function get_node_type_category( $current_node_type ){
        $trp = TRP_Translate_Press::get_trp_instance();
        if ( ! $this->translation_manager ) {
            $this->translation_manager = $trp->get_component( 'translation_manager' );
        }
        $string_groups = $this->translation_manager->string_groups();

        $node_type_categories = apply_filters( 'trp_node_type_categories', array(
            $string_groups['metainformation'] => array( 'meta_desc', 'page_title' ),
            $string_groups['images']          => array( 'image_src' )
        ));

        foreach( $node_type_categories as $category_name => $node_groups ){
            if ( in_array( $current_node_type, $node_groups ) ){
                return $category_name;
            }
        }

        return $string_groups['stringlist'];
    }

    /**
     * String description to be used in preview mode dropdown list of strings.
     *
     * @param object $current_node          Current node.
     * @return string                       Node description.
     */
    protected function get_node_description( $current_node ){
        $node_type_descriptions = apply_filters( 'trp_node_type_descriptions',
            array(
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'name',
                    'value'         => 'description',
                    'description'   => __( 'Description', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'property',
                    'value'         => 'og:title',
                    'description'   => __( 'OG Title', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'property',
                    'value'         => 'og:site_name',
                    'description'   => __( 'OG Site Name', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'property',
                    'value'         => 'og:description',
                    'description'   => __( 'OG Description', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'name',
                    'value'         => 'twitter:title',
                    'description'   => __( 'Twitter Title', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'meta_desc',
                    'attribute'     => 'name',
                    'value'         => 'twitter:description',
                    'description'   => __( 'Twitter Description', 'translatepress-multilingual' )
                ),
                array(
                    'type'          => 'page_title',
                    'description'   => __( 'Page Title', 'translatepress-multilingual' )
                ),

            ));

        foreach( $node_type_descriptions as $node_type_description ){
            if ( isset( $node_type_description['attribute'] )) {
                $attribute = $node_type_description['attribute'];
            }
            if ( $current_node['type'] == $node_type_description['type'] &&
                (
                    ( isset( $node_type_description['attribute'] ) && isset( $current_node['node']->$attribute ) && $current_node['node']->$attribute == $node_type_description['value'] ) ||
                    ( ! isset( $node_type_description['attribute'] ) )
                )
            ) {
                return $node_type_description['description'];
            }
        }

        return '';

    }

    /**
     * Specific trim made for translation block string
     *
     * Problem especially for nbsp; which gets saved like that in DB. Then, in translation-render, the string arrives with nbsp; rendered to actual space character.
     * Used before inserting in db, and when trying to match on translation-render.
     *
     * @param $string
     *
     * @return string
     */
    public function trim_translation_block( $string ){
        return preg_replace('/\s+/', ' ', wp_strip_all_tags ( html_entity_decode( htmlspecialchars_decode( trp_full_trim( $string ), ENT_QUOTES ) ) ));
    }

    /**
     * Recursive function that checks if a DOM node contains certain tags or not
     * @param $row
     * @param $tags
     * @return bool
     */
    public function check_children_for_tags( $row, $tags ){
        foreach( $row->children as $child ){
            if( in_array( $child->tag, $tags ) ){
                return true;
            }
            else{
                $this->check_children_for_tags( $child, $tags );
            }
        }
    }

    /**
     * Return translation block if matches any existing translation block from db
     *
     * Return null if not found
     *
     * @param $row
     * @param $all_existing_translation_blocks
     * @param $merge_rules
     *
     * @return bool
     */
    public function find_translation_block( $row, $all_existing_translation_blocks, $merge_rules ){
        if ( in_array( $row->tag, $merge_rules['top_parents'] ) ){
            //$row->innertext is very intensive on dom nodes that have a lot of children so we try here to eliminate as many as possible here
            // the ideea is that if a dom node contains any top parent tags for blocks it can't be a block itself so we skip it
            $skip = $this->check_children_for_tags( $row, $merge_rules['top_parents'] );
            if( !$skip ) {
                $trimmed_inner_text = $this->trim_translation_block($row->innertext);
                foreach ($all_existing_translation_blocks as $existing_translation_block) {
                    if ($existing_translation_block->trimmed_original == $trimmed_inner_text) {
                        return $existing_translation_block;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Function that translates the content excerpt and post title in the REST API
     * @param $response
     * @return mixed
     */
    public function handle_rest_api_translations($response){
        if ( isset( $response->data ) ) {
            if ( isset( $response->data['title'] ) && isset( $response->data['title']['rendered'] ) ) {
                $response->data['title']['rendered'] = $this->translate_page( $response->data['title']['rendered'] );
            }
            if ( isset( $response->data['excerpt'] ) && isset( $response->data['excerpt']['rendered'] ) ) {
                $response->data['excerpt']['rendered'] = $this->translate_page( $response->data['excerpt']['rendered'] );
            }
            if ( isset( $response->data['content'] ) && isset( $response->data['content']['rendered'] ) ) {
                $response->data['content']['rendered'] = $this->translate_page( $response->data['content']['rendered'] );
            }
        }
        return $response;
    }

    /**
     * Apply translation filters for REST API response
     */
    public function add_callbacks_for_translating_rest_api(){
        $post_types = get_post_types();
        foreach ( $post_types as $post_type ) {
            add_filter( 'rest_prepare_'. $post_type, array( $this, 'handle_rest_api_translations' ) );
        }
    }

    /**
     * Finding translateable strings and replacing with translations.
     *
     * Method called for output buffer.
     *
     * @param string $output        Entire HTML page as string.
     * @return string               Translated HTML page.
     */
    public function translate_page( $output ){
        if ( apply_filters( 'trp_stop_translating_page', false, $output ) ){
            return $output;
        }

        global $trp_editor_notices;

        /* replace our special tags so we have valid html */
        $output = str_replace('#!trpst#', '<', $output);
        $output = str_replace('#!TRPST#', '<', $output);
        $output = str_replace('#!trpen#', '>', $output);
        $output = str_replace('#!TRPEN#', '>', $output);

        $output = apply_filters('trp_before_translate_content', $output);

        if ( strlen( $output ) < 1 || $output == false ){
            return $output;
        }

        if ( ! $this->url_converter ) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $this->url_converter = $trp->get_component('url_converter');
        }

        /* make sure we only translate on the rest_prepare_$post_type filter in REST requests and not the whole json */
        if( strpos( $this->url_converter->cur_page_url(), get_rest_url() ) !== false && strpos( current_filter(), 'rest_prepare_' ) !== 0){
            $trpremoved = $this->remove_trp_html_tags( $output );
            return $trpremoved;
        }

        global $TRP_LANGUAGE;
        $language_code = $this->force_language_in_preview();
        if ($language_code === false) {
            return $output;
        }
        if ( $language_code == $this->settings['default-language'] ){
            // Don't translate regular strings (non-gettext) when we have no other translation languages except default language ( count( $this->settings['publish-languages'] ) > 1 )
            $translate_normal_strings = false;
        }else{
            $translate_normal_strings = true;
        }

        $preview_mode = isset( $_REQUEST['trp-edit-translation'] ) && $_REQUEST['trp-edit-translation'] == 'preview';

        $json_array = json_decode( $output, true );
        /* If we have a json response we need to parse it and only translate the nodes that contain html
         *
         * Removed is_ajax_on_frontend() check because we need to capture custom ajax events.
         * Decided that if $output is json decodable it's a good enough check to handle it this way.
         * We have necessary checks so that we don't get to this point when is_admin(), or when language is not default.
         */
        if( $json_array && $json_array != $output ) {
            /* if it's one of our own ajax calls don't do nothing */
            if ( ! empty( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'trp_' ) === 0 && $_REQUEST['action'] != 'trp_split_translation_block' ) {
                return $output;
            }

            //check if we have a json response
            if ( ! empty( $json_array ) ) {
                if( is_array( $json_array ) ) {
                    array_walk_recursive($json_array, array($this, 'translate_json'));
                }else {
                    $json_array = $this->translate_page($json_array);
                }
            }

            return trp_safe_json_encode( $json_array );
        }

        /**
         * Tries to fix the HTML document. It is off by default. Use at own risk.
         * Solves the problem where a duplicate attribute inside a tag causes the plugin to remove the duplicated attribute and all the other attributes to the right of the it.
         */
        if( apply_filters( 'trp_try_fixing_invalid_html', false ) ) {
            if( class_exists('DOMDocument') ) {
                $dom = new DOMDocument();
                $dom->encoding = 'utf-8';

                libxml_use_internal_errors(true);//so no warnings will show up for invalid html
                $dom->loadHTML(utf8_decode($output), LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
                $output = $dom->saveHTML();
            }
        }

        $no_translate_attribute = 'data-no-translation';

        $translateable_strings = array();
        $skip_machine_translating_strings = array();
        $nodes = array();

        $trp = TRP_Translate_Press::get_trp_instance();
        if ( ! $this->trp_query ) {
            $this->trp_query = $trp->get_component( 'query' );
        }
        if ( ! $this->translation_manager ) {
            $this->translation_manager = $trp->get_component( 'translation_manager' );
        }

        $html = TranslatePress\str_get_html($output, true, true, TRP_DEFAULT_TARGET_CHARSET, false, TRP_DEFAULT_BR_TEXT, TRP_DEFAULT_SPAN_TEXT);
        if ( $html === false ){
            $trpremoved = $this->remove_trp_html_tags( $output );
            return $trpremoved;
        }

        $count_translation_blocks = 0;
        if ( $translate_normal_strings ) {
            $all_existing_translation_blocks = $this->trp_query->get_all_translation_blocks( $language_code );
            // trim every translation block original now, to avoid over-calling trim function later
            foreach ( $all_existing_translation_blocks as $key => $existing_tb ) {
                $all_existing_translation_blocks[ $key ]->trimmed_original = $this->trim_translation_block( $all_existing_translation_blocks[ $key ]->original );
            }

            /* Try to find if there are any blocks in the output for translation.
             * If the output is an actual html page, use only the innertext of body tag
             * Else use the entire output (ex. the output is from JSON REST API content, or just a string)
             */
            $html_body = $html->find('body', 0 );
            $output_to_translate = ( $html_body ) ?  $html_body->innertext : $output;

            $trimmed_html_body = $this->trim_translation_block( $output_to_translate );
            foreach( $all_existing_translation_blocks as $key => $existing_translation_block ){
                if (  strpos( $trimmed_html_body, $existing_translation_block->trimmed_original ) === false ){
                    unset($all_existing_translation_blocks[$key] );//if it isn't present remove it, this way we don't look for them on pages that don't contain blocks
                }
            }
            $count_translation_blocks = count( $all_existing_translation_blocks );//see here how many remain on the current page

            $merge_rules = $this->translation_manager->get_merge_rules();
        }

        /**
         * When we are in the translation editor: Intercept the trp-gettext that was wrapped around all the gettext texts, grab the attribute data-trpgettextoriginal
         * which contains the original translation id and move it to the parent node if the parent node only contains that string then remove the  wrap trp-gettext, otherwise replace it with another tag.
         * Also set a no-translation attribute.
         * When we are in a live translation case: Intercept the trp-gettext that was wrapped around all the gettext texts, set a no-translation attribute to the parent node if the parent node only contains that string
         * then remove the  wrap trp-gettext, otherwise replace the wrap with another tag and do the same to it
         * We identified two cases: the wrapper trp-gettext can be as a node in the dome or ot can be inside a html attribute ( for example value )
         * and we need to treat them differently
         */

        /* store the nodes in arrays so we can sort the $trp_rows which contain trp-gettext nodes from the DOM according to the number of children and we process the simplest first */
        $trp_rows = array();
        $trp_attr_rows = array();
        foreach ( $html->find("*[!nuartrebuisaexiteatributulasta]") as $k => $row ){
            if( $row->hasAttribute('data-trpgettextoriginal') ){
                $trp_rows[count( $row->children )][] = $row;
            }
            else{
                if( $row->nodetype !== 5 && $row->nodetype !== 3 )//add all tags that are not root or text, text nodes can't have attributes
                    $trp_attr_rows[] = $row;

                if ( $translate_normal_strings && $count_translation_blocks > 0 ) {
                    $translation_block = $this->find_translation_block( $row, $all_existing_translation_blocks, $merge_rules );
                    if ( $translation_block ) {
                        $existing_classes = $row->getAttribute( 'class' );
                        if ( $translation_block->block_type == 1 ) {
                            $found_inner_translation_block = false;
                            foreach ( $row->children() as $child ) {
                                if ( $this->find_translation_block( $child, array( $translation_block ), $merge_rules ) != null ) {
                                    $found_inner_translation_block = true;
                                    break;
                                }
                            }
                            if ( ! $found_inner_translation_block ) {
                                // make sure we find it later exactly the way it is in DB
                                $row->innertext = $translation_block->original;
                                $row->setAttribute( 'class', $existing_classes . ' translation-block' );
                            }
                        } else if ( $preview_mode && $translation_block->block_type == 2 && $translation_block->status != 0 ) {
                            // refactor to not do this for each
                            $row->setAttribute( 'data-trp-translate-id', $translation_block->id );
                            $row->setAttribute( 'data-trp-translate-id-deprecated', $translation_block->id );
                            $row->setAttribute( 'class', $existing_classes . 'trp-deprecated-tb' );
                        }
                    }
                }
            }
        }

        /* sort them here ascending by key where the key is the number of children */
        /* here we add support for gettext inside gettext */
        ksort($trp_rows);
        foreach( $trp_rows as $level ){
            foreach( $level as $row ){
                $original_gettext_translation_id = $row->getAttribute('data-trpgettextoriginal');
                /* Parent node has no other children and no other innertext besides the current node */
                if( count( $row->parent()->children ) == 1 && $row->parent()->innertext == $row->outertext ){
                    $row->outertext = $row->innertext();
                    $row->parent()->setAttribute($no_translate_attribute, '');
                    // we are in the editor
                    if (isset($_REQUEST['trp-edit-translation']) && $_REQUEST['trp-edit-translation'] == 'preview') {
                        //move up the data-trpgettextoriginal attribute
                        $row->parent()->setAttribute('data-trpgettextoriginal', $original_gettext_translation_id);
                    }
                }
                else{
                    $row->outertext = '<trp-wrap class="trp-wrap" data-no-translation';
                    if (isset($_REQUEST['trp-edit-translation']) && $_REQUEST['trp-edit-translation'] == 'preview') {
                        $row->outertext .= ' data-trpgettextoriginal="'. $original_gettext_translation_id .'"';
                    }
                    $row->outertext .= '>'.$row->innertext().'</trp-wrap>';
                }
            }
        }

        foreach( $trp_attr_rows as $row ){
            $all_attributes = $row->getAllAttributes();
            if( !empty( $all_attributes ) ) {
                foreach ($all_attributes as $attr_name => $attr_value) {
                    if (strpos($attr_value, 'trp-gettext ') !== false) {
                        //if we have json content in the value of the attribute, we don't do anything. The trp-wrap will be removed later in the code
                        if (is_array($json_array = json_decode( html_entity_decode( $attr_value, ENT_QUOTES ), true ) ) ) {
                            continue;
                        }

                        // convert to a node
                        $node_from_value = TranslatePress\str_get_html(html_entity_decode(htmlspecialchars_decode($attr_value, ENT_QUOTES)), true, true, TRP_DEFAULT_TARGET_CHARSET, false, TRP_DEFAULT_BR_TEXT, TRP_DEFAULT_SPAN_TEXT);
                        if ( $node_from_value === false ){
                            continue;
                        }
                        foreach ($node_from_value->find('trp-gettext') as $nfv_row) {
                            $nfv_row->outertext = $nfv_row->innertext();
                            $saved_node_from_value = $node_from_value->save();

                            // attributes of these tags are not handled well by the parser so don't escape them [see iss6264]
                            if ( $row->tag != 'script' && $row->tag != 'style' ){
                                $saved_node_from_value = esc_attr($saved_node_from_value);
                            }

                            $row->setAttribute($attr_name, $saved_node_from_value );
                            $row->setAttribute($no_translate_attribute . '-' . $attr_name, '');
                            // we are in the editor
                            if (isset($_REQUEST['trp-edit-translation']) && $_REQUEST['trp-edit-translation'] == 'preview') {
                                $original_gettext_translation_id = $nfv_row->getAttribute('data-trpgettextoriginal');
                                $row->setAttribute('data-trpgettextoriginal-' . $attr_name, $original_gettext_translation_id);
                            }

                        }
                    }
                }
            }
        }


        if ( ! $translate_normal_strings ) {
            /* save it as a string */
            $trpremoved = $html->save();
            /* perform preg replace on the remaining trp-gettext tags */
            $trpremoved = $this->remove_trp_html_tags($trpremoved );
            return $trpremoved;
        }

        $no_translate_selectors = apply_filters( 'trp_no_translate_selectors', array( '#wpadminbar' ), $TRP_LANGUAGE );

        /*
         * process the types of strings we can currently have: no-translate, translation-block, text, input, textarea, etc.
         */

        foreach ( $no_translate_selectors as $no_translate_selector ){
            foreach ( $html->find( $no_translate_selector ) as $k => $row ){
                $row->setAttribute( $no_translate_attribute, '' );
            }
        }
        foreach ( $html->find('.translation-block') as $row ){
            $trimmed_string = trp_full_trim($row->innertext);
            $parent = $row->parent();
            if( $trimmed_string!=""
                && $parent->tag!="script"
                && $parent->tag!="style"
                && $parent->tag != 'title'
                && strpos($row->outertext,'[vc_') === false
                && !is_numeric($trimmed_string)
                && !preg_match('/^\d+%$/',$trimmed_string)
                && !$this->has_ancestor_attribute( $row, $no_translate_attribute ) )
            {
                array_push( $translateable_strings, $trimmed_string );
                array_push( $nodes, array('node' => $row, 'type' => 'block'));
            }
        }

        foreach ( $html->find('trptext') as $row ){
            $outertext = $row->outertext;
            $parent = $row->parent();
            $trimmed_string = trp_full_trim($outertext);
            if( $trimmed_string!=""
                && $parent->tag!="script"
                && $parent->tag!="style"
                && $parent->tag != 'title'
                && strpos($outertext,'[vc_') === false
                && !is_numeric($trimmed_string)
                && !preg_match('/^\d+%$/',$trimmed_string)
                && !$this->has_ancestor_attribute( $row, $no_translate_attribute )
                && !$this->has_ancestor_class( $row, 'translation-block') )
            {
                // $translateable_strings array needs to be in sync in $nodes array
                array_push( $translateable_strings, $trimmed_string );
                if( $parent->tag == 'button') {
                    array_push($nodes, array('node' => $row, 'type' => 'button'));
                }
                else {
                    if ( $parent->tag == 'option' ) {
                        array_push( $nodes, array( 'node' => $row, 'type' => 'option' ) );
                    } else {
                        array_push( $nodes, array( 'node' => $row, 'type' => 'text' ) );
                    }
                }
            }
        }
        //set up general links variables
        $home_url = home_url();
        $admin_url = admin_url();
        $wp_login_url = wp_login_url();

        $node_accessors = $this->get_node_accessors();
        foreach( $node_accessors as $node_accessor_key => $node_accessor ){
            if ( isset( $node_accessor['selector'] ) ){
                foreach ( $html->find( $node_accessor['selector'] ) as $k => $row ){
                    $current_node_accessor_selector = $node_accessor['accessor'];
                    $trimmed_string = trp_full_trim($row->$current_node_accessor_selector);
                    if ( $current_node_accessor_selector === 'href' ) {
                        $translate_href = ( $this->is_external_link( $trimmed_string, $home_url ) || $this->url_converter->url_is_file( $trimmed_string ) );
                        $translate_href = apply_filters( 'trp_translate_this_href', $translate_href, $row, $TRP_LANGUAGE );
                        $trimmed_string = ( $translate_href ) ? $trimmed_string : '';
                    }

                    if( $trimmed_string!=""
                        && !is_numeric($trimmed_string)
                        && !preg_match('/^\d+%$/',$trimmed_string)
                        && !$this->has_ancestor_attribute( $row, $no_translate_attribute )
                        && !$this->has_ancestor_attribute( $row, $no_translate_attribute . '-' . $current_node_accessor_selector )
                        && !$this->has_ancestor_class( $row, 'translation-block')
                        && $row->tag != 'link' )
                    {
                        $entity_decoded_trimmed_string = html_entity_decode( $trimmed_string );
                        array_push( $translateable_strings, $entity_decoded_trimmed_string );
                        array_push( $nodes, array( 'node'=>$row, 'type' => $node_accessor_key ) );
                        if ( ! apply_filters( 'trp_allow_machine_translation_for_string', true, $entity_decoded_trimmed_string, $current_node_accessor_selector, $node_accessor ) ){
                            array_push( $skip_machine_translating_strings, $entity_decoded_trimmed_string );
                        }
                    }
                }
            }
        }

        $translateable_information = array( 'translateable_strings' => $translateable_strings, 'nodes' => $nodes );
        $translateable_information = apply_filters( 'trp_translateable_strings', $translateable_information, $html, $no_translate_attribute, $TRP_LANGUAGE, $language_code, $this );
        $translateable_strings = $translateable_information['translateable_strings'];
        $nodes = $translateable_information['nodes'];

        $translated_strings = $this->process_strings( $translateable_strings, $language_code, null, $skip_machine_translating_strings );

        do_action('trp_translateable_information', $translateable_information, $translated_strings, $language_code);

        if ( $preview_mode ) {
            $translated_string_ids = $this->trp_query->get_string_ids($translateable_strings, $language_code);
        }

        foreach ( $nodes as $i => $node ) {
            $translation_available = isset( $translated_strings[$i] );
            if ( ! ( $translation_available || $preview_mode ) || !isset( $node_accessors [$nodes[$i]['type']] )){
                continue;
            }
            $current_node_accessor = $node_accessors[ $nodes[$i]['type'] ];
            $accessor = $current_node_accessor[ 'accessor' ];
            if ( $translation_available && isset( $current_node_accessor ) && ! ( $preview_mode && ( $this->settings['default-language'] == $TRP_LANGUAGE ) ) ) {

                $translateable_string = $translateable_strings[$i];

                if ( $current_node_accessor[ 'attribute' ] ){
                    $translateable_string = $this->maybe_correct_translatable_string( $translateable_string, $nodes[$i]['node']->getAttribute( $accessor ) );
                    $nodes[$i]['node']->setAttribute( $accessor, str_replace( $translateable_string, esc_attr( $translated_strings[$i] ), $nodes[$i]['node']->getAttribute( $accessor ) ) );
                    do_action( 'trp_set_translation_for_attribute', $nodes[$i]['node'], $accessor, $translated_strings[$i] );
                }else{
                    $translateable_string = $this->maybe_correct_translatable_string( $translateable_string, $nodes[$i]['node']->$accessor );
                    $nodes[$i]['node']->$accessor = str_replace( $translateable_string, $translated_strings[$i], $nodes[$i]['node']->$accessor );
                }

            }

            if ( $preview_mode ) {
                if ( $accessor == 'outertext' && $nodes[$i]['type'] != 'button' ) {
                    $outertext_details = '<translate-press data-trp-translate-id="' . $translated_string_ids[$translateable_strings[$i]]->id . '" data-trp-node-group="' . $this->get_node_type_category( $nodes[$i]['type'] ) . '"';
                    if ( $this->get_node_description( $nodes[$i] ) ) {
                        $outertext_details .= ' data-trp-node-description="' . $this->get_node_description($nodes[$i] ) . '"';
                    }
                    $outertext_details .= '>' . $nodes[$i]['node']->outertext . '</translate-press>';
                    $nodes[$i]['node']->outertext = $outertext_details;
                } else {
                    if( $nodes[$i]['type'] == 'button' || $nodes[$i]['type'] == 'option' ){
                        $nodes[$i]['node'] = $nodes[$i]['node']->parent();
                    }
                    $nodes[$i]['node']->setAttribute('data-trp-translate-id-' . $accessor, $translated_string_ids[ $translateable_strings[$i] ]->id );
                    $nodes[$i]['node']->setAttribute('data-trp-node-group-' . $accessor, $this->get_node_type_category( $nodes[$i]['type'] ) );

                    if ( $this->get_node_description( $nodes[$i] ) ) {
                        $nodes[$i]['node']->setAttribute('data-trp-node-description-' . $accessor, $this->get_node_description($nodes[$i]));
                    }

                }
            }

        }

        // We need to save here in order to access the translated links too.
        if( apply_filters('tp_handle_custom_links_in_translation_blocks', false) ) {
            $html_string = $html->save();
            $html = TranslatePress\str_get_html($html_string, true, true, TRP_DEFAULT_TARGET_CHARSET, false, TRP_DEFAULT_BR_TEXT, TRP_DEFAULT_SPAN_TEXT);
            if ( $html === false ){
                return $html_string;
            }
        }


        // force custom links to have the correct language
        foreach( $html->find('a[href!="#"]') as $a_href)  {
            $a_href->href = apply_filters( 'trp_href_from_translated_page', $a_href->href, $this->settings['default-language'] );

            $url = $a_href->href;

            $url = $this->maybe_is_local_url($url, $home_url);

            $is_external_link = $this->is_external_link( $url, $home_url );
            $is_admin_link = $this->is_admin_link($url, $admin_url, $wp_login_url);

            if( $preview_mode && ! $is_external_link ){
                $a_href->setAttribute( 'data-trp-original-href', $url );
            }

            if ( $TRP_LANGUAGE != $this->settings['default-language'] && $this->settings['force-language-to-custom-links'] == 'yes' && !$is_external_link && $this->url_converter->get_lang_from_url_string( $url ) == null && !$is_admin_link && strpos($url, '#TRPLINKPROCESSED') === false ){
                $a_href->href = apply_filters( 'trp_force_custom_links', $this->url_converter->get_url_for_language( $TRP_LANGUAGE, $url ), $url, $TRP_LANGUAGE, $a_href );
                $url = $a_href->href;
            }

            if( $preview_mode && ( $is_external_link || $this->is_different_language( $url ) || $is_admin_link ) ) {
                $a_href->setAttribute( 'data-trp-unpreviewable', 'trp-unpreviewable' );
            }

            $a_href->href = str_replace('#TRPLINKPROCESSED', '', $a_href->href);
        }

        // pass the current language in forms where the action does not contain the language
        // based on this we're filtering wp_redirect to include the proper URL when returning to the current page.
        foreach ( $html->find('form') as $k => $row ){
            $row->setAttribute( 'data-trp-original-action', $row->action );
            $row->innertext .= apply_filters( 'trp_form_inputs', '<input type="hidden" name="trp-form-language" value="'. $this->settings['url-slugs'][$TRP_LANGUAGE] .'"/>', $TRP_LANGUAGE, $this->settings['url-slugs'][$TRP_LANGUAGE] );
            $form_action = $row->action;

            $is_external_link = $this->is_external_link( $form_action, $home_url );
            $is_admin_link = $this->is_admin_link($form_action, $admin_url, $wp_login_url );

            if ( !empty($form_action)
                && $this->settings['force-language-to-custom-links'] == 'yes'
                && !$is_external_link
                && !$is_admin_link
                && strpos($form_action, '#TRPLINKPROCESSED') === false)
            {
                $row->action =  $this->url_converter->get_url_for_language( $TRP_LANGUAGE, $form_action );
            }
            $row->action = str_replace('#TRPLINKPROCESSED', '', $row->action);
        }

        foreach ( $html->find('link') as $link ){
            $link->href = str_replace('#TRPLINKPROCESSED', '', $link->href);
        }

        // Append an html table containing the errors
        $trp_editor_notices = apply_filters( 'trp_editor_notices', $trp_editor_notices );
        if ( trp_is_translation_editor('preview') && $trp_editor_notices != '' ){
            $body = $html->find('body', 0 );
            $body->innertext = '<div data-no-translation class="trp-editor-notices">' . $trp_editor_notices . "</div>" . $body->innertext;
        }
        $final_html = $html->save();

        /* perform preg replace on the remaining trp-gettext tags */
        $final_html = $this->remove_trp_html_tags( $final_html );

        /*
         * Insert DB cache write here: persist final translated HTML for fast serving by front loader.
         * Skip writing when in preview/editor mode.
         */
        if ( empty( $preview_mode ) ) {
            // Build canonical URL (prefer url_converter->cur_page_url if available)
            $url = ( method_exists( $this->url_converter, 'cur_page_url' ) ) ? $this->url_converter->cur_page_url() : ( ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

            if ( class_exists( 'TRP_Translated_Pages_Cache' ) ) {
                try {
                    $cache = new TRP_Translated_Pages_Cache();
                    $cache->set_cached_page( $url, $TRP_LANGUAGE, $final_html );
                } catch ( Exception $e ) {
                    // don't break page rendering on cache failures
                }
            }
        }

        return apply_filters( 'trp_translated_html', $final_html, $TRP_LANGUAGE, $language_code, $preview_mode );
    }

    /*
     * Adjust translatable string so that it must match the content of the node value
     *
     * We use str_replace method in order to preserve any existent spacing before or after the string.
     * If the encoding of the node is not the same as the translatable string then the string won't match so try applying htmlentities.
     * If that doesn't work either, just forget about any possible before and after spaces.
     *
     */
    public function maybe_correct_translatable_string( $translatable_string, $node_value ){
        if ( strpos ( $node_value, $translatable_string ) === false ){
            $translatable_string = htmlentities( $translatable_string );
            if ( strpos ( $node_value, $translatable_string ) === false ){
                $translatable_string = $node_value;
            }
        }
        return $translatable_string;
    }

    /*
     * Update other image attributes (srcset) with the translated image
     *
     * Hooked to trp_set_translation_for_attribute
     */
    public function translate_image_srcset_attributes( $node, $accessor, $translated_string ){
        if( $accessor === 'src' ) {
            $srcset = $node->getAttribute( 'srcset' );
            $datasrcset = $node->getAttribute( 'data-srcset' );
            if ( $srcset || $datasrcset ) {
                $attachment_id = attachment_url_to_postid( $translated_string );
                if ( $attachment_id ) {
                    $translated_srcset = '';
                    if ( function_exists( 'wp_get_attachment_image_srcset' ) ) {
                        // get width of the image in order, to set the largest possible size for srcset
                        $meta_data = wp_get_attachment_metadata( $attachment_id );
                        $width = ( $meta_data && isset( $meta_data['width'] ) ) ? $meta_data['width'] : 'large';
                        $translated_srcset = wp_get_attachment_image_srcset( $attachment_id, $width );
                    }
                    if ( $srcset ){
                        $node->setAttribute( 'srcset', $translated_srcset );
                    }
                    if ( $datasrcset ){
                        $node->setAttribute( 'data-srcset', $translated_srcset );
                    }
                } else {
                    $node->setAttribute( 'srcset', '' );
                    $node->setAttribute( 'data-srcset', '' );
                }
            }
            if ( $node->getAttribute( 'data-src' ) ) {
                $node->setAttribute( 'data-src', $translated_string );
            }
        }
    }

    }

    /* ... rest of class methods remain unchanged ... */