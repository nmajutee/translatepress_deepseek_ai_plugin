<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Local-only EDD plugin updater / license helper.
 *
 * This adapted file avoids external HTTP calls by default. Use the provided
 * filters to inject custom validation or local update metadata if needed.
 *
 * Filters available:
 * - trp_local_license_validator( $result_object, $license, $item_name )
 * - trp_local_license_deactivated ( action )
 * - trp_local_edd_api_request( $response, $_action, $_data, $instance )
 * - trp_local_edd_version_info( $version_info, $slug, $beta )
 * - trp_local_plugin_information( $info, $slug )
 * - trp_local_plugin_changelog( $changelog, $slug )
 */

if ( ! class_exists( 'TRP_EDD_SL_Plugin_Updater' ) ) {
    class TRP_EDD_SL_Plugin_Updater {

        private $api_url = '';
        private $api_data = array();
        private $name = '';
        private $slug = '';
        private $version = '';
        private $wp_override = false;
        private $cache_key = '';

        public function __construct( $_api_url, $_plugin_file, $_api_data = null ) {

            global $edd_plugin_data;

            $this->api_url     = trailingslashit( $_api_url );
            $this->api_data    = $_api_data;
            $this->name        = plugin_basename( $_plugin_file );
            $this->slug        = basename( $_plugin_file, '.php' );
            $this->version     = isset( $_api_data['version'] ) ? $_api_data['version'] : '';
            $this->wp_override = isset( $_api_data['wp_override'] ) ? (bool) $_api_data['wp_override'] : false;
            $this->beta        = ! empty( $this->api_data['beta'] ) ? true : false;
            $this->cache_key   = md5( serialize( $this->slug . ( isset( $this->api_data['license'] ) ? $this->api_data['license'] : '' ) . $this->beta ) );

            $edd_plugin_data[ $this->slug ] = $this->api_data;

            $this->init();
        }

        public function init() {
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
            add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
            remove_action( 'after_plugin_row_' . $this->name, 'wp_plugin_update_row', 10 );
            add_action( 'after_plugin_row_' . $this->name, array( $this, 'show_update_notification' ), 10, 2 );
            add_action( 'admin_init', array( $this, 'show_changelog' ) );
        }

        /**
         * Check for updates (local-only by default).
         */
        public function check_update( $_transient_data ) {

            global $pagenow;

            if ( ! is_object( $_transient_data ) ) {
                $_transient_data = new stdClass;
            }

            if ( 'plugins.php' == $pagenow && is_multisite() ) {
                return $_transient_data;
            }

            if ( ! empty( $_transient_data->response ) && ! empty( $_transient_data->response[ $this->name ] ) && false === $this->wp_override ) {
                return $_transient_data;
            }

            $version_info = $this->get_cached_version_info();

            if ( false === $version_info ) {
                // Allow local code to provide version info via filter.
                $version_info = apply_filters( 'trp_local_edd_version_info', false, $this->slug, $this->beta );
                if ( $version_info ) {
                    $this->set_version_info_cache( $version_info );
                }
            }

            if ( false !== $version_info && is_object( $version_info ) && isset( $version_info->new_version ) ) {

                if ( version_compare( $this->version, $version_info->new_version, '<' ) ) {
                    $_transient_data->response[ $this->name ] = $version_info;
                }

                $_transient_data->last_checked = current_time( 'timestamp' );
                $_transient_data->checked[ $this->name ] = $this->version;
            }

            return $_transient_data;
        }

        public function show_update_notification( $file, $plugin ) {

            if ( is_network_admin() ) {
                return;
            }

            if ( ! current_user_can( 'update_plugins' ) ) {
                return;
            }

            if ( ! is_multisite() ) {
                return;
            }

            if ( $this->name != $file ) {
                return;
            }

            remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ), 10 );

            $update_cache = get_site_transient( 'update_plugins' );
            $update_cache = is_object( $update_cache ) ? $update_cache : new stdClass();

            if ( empty( $update_cache->response ) || empty( $update_cache->response[ $this->name ] ) ) {

                $version_info = $this->get_cached_version_info();

                if ( false === $version_info ) {
                    $version_info = apply_filters( 'trp_local_edd_version_info', false, $this->slug, $this->beta );
                    $this->set_version_info_cache( $version_info );
                }

                if ( ! is_object( $version_info ) ) {
                    return;
                }

                if ( version_compare( $this->version, $version_info->new_version, '<' ) ) {
                    $update_cache->response[ $this->name ] = $version_info;
                }

                $update_cache->last_checked = current_time( 'timestamp' );
                $update_cache->checked[ $this->name ] = $this->version;

                set_site_transient( 'update_plugins', $update_cache );

            } else {
                $version_info = $update_cache->response[ $this->name ];
            }

            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );

            if ( ! empty( $update_cache->response[ $this->name ] ) && version_compare( $this->version, $version_info->new_version, '<' ) ) {

                $wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

                echo '<tr class="plugin-update-tr" id="' . esc_attr( $this->slug ) . '-update" data-slug="' . esc_attr( $this->slug ) . '" data-plugin="' . esc_attr( $this->slug . '/' . $file ) . '">';
                echo '<td colspan="3" class="plugin-update colspanchange">';
                echo '<div class="update-message notice inline notice-warning notice-alt">';

                $changelog_link = self_admin_url( 'index.php?edd_sl_action=view_plugin_changelog&plugin=' . rawurlencode( $this->name ) . '&slug=' . rawurlencode( $this->slug ) . '&TB_iframe=true&width=772&height=911' );

                if ( empty( $version_info->download_link ) ) {
                    printf(
                        __( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s.', 'easy-digital-downloads' ),
                        esc_html( $version_info->name ),
                        '<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
                        esc_html( $version_info->new_version ),
                        '</a>'
                    );
                } else {
                    printf(
                        __( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s or %5$supdate now%6$s.', 'easy-digital-downloads' ),
                        esc_html( $version_info->name ),
                        '<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
                        esc_html( $version_info->new_version ),
                        '</a>',
                        '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $this->name, 'upgrade-plugin_' . $this->name ) ) . '">',
                        '</a>'
                    );
                }

                do_action( "in_plugin_update_message-{$file}", $plugin, $version_info );

                echo '</div></td></tr>';
            }
        }

        public function plugins_api_filter( $_data, $_action = '', $_args = null ) {

            if ( 'plugin_information' != $_action ) {
                return $_data;
            }

            if ( ! isset( $_args->slug ) || ( $_args->slug != $this->slug ) ) {
                return $_data;
            }

            $local_info = apply_filters( 'trp_local_plugin_information', false, $this->slug );
            if ( $local_info && is_object( $local_info ) ) {
                return $local_info;
            }

            return $_data;
        }

        /**
         * Local-only API request shim. External calls disabled by default.
         */
        private function api_request( $_action, $_data ) {

            $response = apply_filters( 'trp_local_edd_api_request', false, $_action, $_data, $this );

            if ( false !== $response ) {
                return $response;
            }

            // Default: do not perform external HTTP calls from the plugin.
            return false;
        }

        public function show_changelog() {

            global $edd_plugin_data;

            if ( empty( $_REQUEST['edd_sl_action'] ) || 'view_plugin_changelog' != $_REQUEST['edd_sl_action'] ) {
                return;
            }

            if ( empty( $_REQUEST['plugin'] ) ) {
                return;
            }

            if ( empty( $_REQUEST['slug'] ) ) {
                return;
            }

            if ( ! current_user_can( 'update_plugins' ) ) {
                wp_die( __( 'You do not have permission to install plugin updates', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
            }

            $data = $edd_plugin_data[ $_REQUEST['slug'] ];
            $beta = ! empty( $data['beta'] ) ? true : false;
            $cache_key = md5( 'edd_plugin_' . sanitize_key( $_REQUEST['plugin'] ) . '_' . $beta . '_version_info' );
            $version_info = $this->get_cached_version_info( $cache_key );

            if ( false === $version_info ) {

                $local_changelog = apply_filters( 'trp_local_plugin_changelog', false, $_REQUEST['slug'] );

                if ( false !== $local_changelog && isset( $local_changelog['sections'] ) ) {
                    $version_info = (object) $local_changelog;
                } else {
                    $version_info = false;
                }

                $this->set_version_info_cache( $version_info, $cache_key );
            }

            if ( ! empty( $version_info ) && isset( $version_info->sections['changelog'] ) ) {
                echo '<div style="background:#fff;padding:10px;">' . $version_info->sections['changelog'] . '</div>';
            }

            exit;
        }

        public function get_cached_version_info( $cache_key = '' ) {

            if ( empty( $cache_key ) ) {
                $cache_key = $this->cache_key;
            }

            $cache = get_option( $cache_key );

            if ( empty( $cache['timeout'] ) || current_time( 'timestamp' ) > $cache['timeout'] ) {
                return false;
            }

            return json_decode( $cache['value'] );
        }

        public function set_version_info_cache( $value = '', $cache_key = '' ) {

            if ( empty( $cache_key ) ) {
                $cache_key = $this->cache_key;
            }

            $data = array(
                'timeout' => strtotime( '+3 hours', current_time( 'timestamp' ) ),
                'value'   => json_encode( $value ),
            );

            update_option( $cache_key, $data );
        }

        private function verify_ssl() {
            return (bool) apply_filters( 'edd_sl_api_request_verify_ssl', true, $this );
        }
    }
}

if ( ! class_exists( 'TRP_LICENSE_PAGE' ) ) {
    class TRP_LICENSE_PAGE {
        public function __construct() {
        }

        public function license_menu() {
            add_submenu_page(
                'TRPHidden',
                'TranslatePress License',
                'TRPHidden',
                'manage_options',
                'trp_license_key',
                array( $this, 'license_page' )
            );
        }

        public function license_page() {
            $license = get_option( 'trp_license_key' );
            $status  = get_option( 'trp_license_status' );
            $details = get_option( 'trp_license_details' );
            $action  = 'options.php';
            ob_start();
            require TRP_PLUGIN_DIR . 'partials/license-settings-page.php';
            echo ob_get_clean();
        }
    }
}

class TRP_Plugin_Updater {

    private $store_url = "https://translatepress.com";

    public function __construct() {
    }

    protected function get_option( $license_key_option ) {
        return get_option( $license_key_option );
    }

    protected function delete_option( $license_key_option ) {
        delete_option( $license_key_option );
    }

    protected function update_option( $license_key_option, $value ) {
        update_option( $license_key_option, $value );
    }

    protected function license_page_url() {
        return admin_url( 'admin.php?page=trp_license_key' );
    }

    public function edd_sanitize_license( $new ) {
        $new = sanitize_text_field( $new );
        $old = $this->get_option( 'trp_license_key' );
        if ( $old && $old != $new ) {
            $this->delete_option( 'trp_license_status' );
        }
        return $new;
    }

    /**
     * Local-only license check run with WP update checks.
     */
    public function check_license( $transient_data ) {

        $license = trim( $this->get_option( 'trp_license_key' ) );

        if ( $license ) {
            $license_status = trim( $this->get_option( 'trp_license_status' ) );

            if ( $license_status ) {
                $license_information_for_all_addons = get_option( 'trp_license_details', array() );
                $this->update_option( 'trp_license_details', $license_information_for_all_addons );
            } else {
                $validator_result = $this->local_validate_license( $license, isset( $GLOBALS['trp_plugin_item_name'] ) ? $GLOBALS['trp_plugin_item_name'] : '' );

                if ( is_object( $validator_result ) && isset( $validator_result->success ) && true === $validator_result->success ) {
                    $this->update_option( 'trp_license_status', isset( $validator_result->license ) ? $validator_result->license : 'valid' );
                    $this->update_option( 'trp_license_details', array( 'valid' => array( $validator_result ) ) );
                } else {
                    $this->update_option( 'trp_license_details', array( 'invalid' => array( (object) array( 'error' => 'missing' ) ) ) );
                }
            }
        } else {
            $license_information_for_all_addons['invalid'][] = (object) array( 'error' => 'missing' );
            $this->update_option( 'trp_license_details', $license_information_for_all_addons );
        }

        return $transient_data;
    }

    public function admin_activation_notices() {
        if ( isset( $_GET['trp_sl_activation'] ) && ! empty( $_GET['message'] ) ) {

            $message = urldecode( $_GET['message'] );

            switch ( $_GET['trp_sl_activation'] ) {
                case 'false':
                    $class = "error";
                    break;
                case 'true':
                default:
                    $class = "updated";
                    break;
            }

            ?>
            <div class="<?php echo esc_attr( $class ); ?>">
                <p><?php echo wp_kses_post( $message ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Handle activation form submission locally (no external HTTP).
     */
    public function activate_license() {

        if ( isset( $_POST['trp_edd_license_activate'] ) ) {

            if ( ! check_admin_referer( 'trp_license_nonce', 'trp_license_nonce' ) ) {
                return;
            }

            $license = $this->edd_sanitize_license( trim( $_POST['trp_license_key'] ) );
            $this->update_option( 'trp_license_key', $license );

            $message = array();
            $license_information_for_all_addons = array();

            $validator_result = $this->local_validate_license( $license, isset( $GLOBALS['trp_plugin_item_name'] ) ? $GLOBALS['trp_plugin_item_name'] : '' );

            if ( is_object( $validator_result ) && isset( $validator_result->success ) && true === $validator_result->success ) {
                $license_information_for_all_addons['valid'][] = $validator_result;
                $this->update_option( 'trp_license_details', $license_information_for_all_addons );
                $this->update_option( 'trp_license_status', isset( $validator_result->license ) ? $validator_result->license : 'valid' );

                wp_redirect( add_query_arg( array( 'trp_sl_activation' => 'true', 'message' => urlencode( __( 'You have successfully activated your license', 'translatepress-multilingual' ) ) ), $this->license_page_url() ) );
                exit();
            } else {
                $license_information_for_all_addons['invalid'][] = $validator_result;
                $this->update_option( 'trp_license_details', $license_information_for_all_addons );

                $message[] = __( 'Invalid license.', 'translatepress-multilingual' );
                $redirect = add_query_arg( array( 'trp_sl_activation' => 'false', 'message' => urlencode( implode( "<br/>", array_unique( $message ) ) ) ), $this->license_page_url() );

                wp_redirect( $redirect );
                exit();
            }
        }
    }

    /**
     * Handle deactivation form submission locally (no external HTTP).
     */
    public function deactivate_license() {

        if ( isset( $_POST['trp_edd_license_deactivate'] ) ) {

            if ( ! check_admin_referer( 'trp_license_nonce', 'trp_license_nonce' ) ) {
                return;
            }

            $license = trim( $this->get_option( 'trp_license_key' ) );

            // Local deactivation: clear stored status
            $this->delete_option( 'trp_license_status' );
            $this->update_option( 'trp_license_details', array() );

            do_action( 'trp_local_license_deactivated', $license );

            wp_redirect( $this->license_page_url() );
            exit();
        }
    }

    /**
     * Local license validator.
     *
     * Default: treat any non-empty license as valid. Override with filter.
     */
    protected function local_validate_license( $license, $item_name = '' ) {

        $result = (object) array(
            'success' => (bool) $license,
            'license' => $license ? 'valid' : 'invalid',
            'expires' => $license ? date( 'Y-m-d', strtotime( '+1 year' ) ) : '',
        );

        $result = apply_filters( 'trp_local_license_validator', $result, $license, $item_name );

        if ( ! is_object( $result ) ) {
            $result = (object) array( 'success' => false, 'license' => 'invalid' );
        }

        return $result;
    }
}