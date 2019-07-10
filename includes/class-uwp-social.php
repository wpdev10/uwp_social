<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class UsersWP_Social {

    private static $instance;

    /**
     * Plugin Version
     */
    private $version = UWP_SOCIAL_VERSION;


    public static function get_instance() {
        if ( ! isset( self::$instance ) && ! ( self::$instance instanceof UsersWP_Social ) ) {
            self::$instance = new UsersWP_Social;
            self::$instance->setup_globals();
            self::$instance->includes();
            self::$instance->setup_actions();
        }

        return self::$instance;
    }

    private function __construct() {
        self::$instance = $this;
    }

    private function setup_globals() {

    }

    private function setup_actions() {
        if (class_exists( 'UsersWP' )) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
            add_action('login_enqueue_scripts', array($this, 'enqueue_styles'));
        }

        add_action('login_form_middle', array($this, 'login_form_botton'));
        add_action('uwp_social_fields', array($this, 'social_login_buttons_on_templates'), 30, 1);
        add_action('delete_user', array($this, 'delete_user_row'), 30, 1);
        add_action('uwp_social_after_wp_insert_user', array($this, 'admin_notification'), 10, 2);
        add_action('uwp_clear_user_php_session', 'uwp_social_destroy_session_data');
        add_action('wp_logout', 'uwp_social_destroy_session_data');
        add_action('login_form', 'uwp_social_login_buttons');

        do_action( 'uwp_social_setup_actions' );

        if(is_admin()){
            add_action( 'admin_init', array( $this, 'activation_redirect' ) );
            add_action('admin_init', array($this, 'automatic_upgrade'));
            add_filter( 'uwp_get_settings_pages', array( $this, 'uwp_socail_get_settings_pages' ), 10, 1 );
        }

        add_action( 'init', array($this, 'load_textdomain') );
    }

    public function enqueue_styles() {

        wp_enqueue_style( 'uwp_social_styles', UWP_SOCIAL_PLUGIN_URL . 'assets/css/styles.css', array(), $this->version, 'all' );

    }

    /**
     * Load the textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'uwp-social', false, basename( UWP_SOCIAL_PATH ) . '/languages' );
    }

    private function includes() {

        require_once UWP_SOCIAL_PATH . '/includes/helpers.php';
        require_once UWP_SOCIAL_PATH . '/includes/social.php';
        require_once UWP_SOCIAL_PATH . '/includes/widgets.php';
        require_once UWP_SOCIAL_PATH . '/includes/errors.php';
        require_once UWP_SOCIAL_PATH . '/includes/linking.php';

        do_action( 'uwp_social_include_files' );

        if ( ! is_admin() )
            return;

        do_action( 'uwp_social_include_admin_files' );

    }

    public function automatic_upgrade(){
        $uwp_social_version = get_option( 'uwp_social_db_version' );

        if ( empty($uwp_social_version) || ($uwp_social_version && version_compare( $uwp_social_version, '1.0.9', '<' )) ) {

            flush_rewrite_rules();

            update_option( 'uwp_social_db_version', UWP_SOCIAL_VERSION );
        }

        if( empty( get_option( 'uwp-social-authuri-notice-dismissed' ) ) ) {
            add_action('admin_notices', array($this, 'admin_notices'));
            add_action('admin_footer', array($this, 'admin_footer_js'));
            add_action('wp_ajax_nopriv_uwp_social_dismiss_authuri_notice', array($this, 'dismiss_notice'));
            add_action('wp_ajax_uwp_social_dismiss_authuri_notice', array($this, 'dismiss_notice'));
        }
    }

    public function uwp_socail_get_settings_pages($settings){
        $settings[] = include( UWP_SOCIAL_PATH . '/admin/class-uwp-settings-social.php' );
        return $settings;
    }

    /**
     * Redirect to the social settings page on activation.
     *
     * @since 1.0.0
     */
    public function activation_redirect() {
        // Bail if no activation redirect
        if ( !get_transient( '_uwp_social_activation_redirect' ) ) {
            return;
        }

        // Delete the redirect transient
        delete_transient( '_uwp_social_activation_redirect' );

        // Bail if activating from network, or bulk
        if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=userswp&tab=uwp-social' ) );
        exit;
    }

    public function login_form_botton($content){
        return $content.uwp_social_login_buttons_display();
    }

    public function social_login_buttons_on_templates($type) {
        if ($type == 'login' || $type == 'register') {
            uwp_social_login_buttons();
        }
    }

    public function delete_user_row($user_id) {
        if (!$user_id) {
            return;
        }

        global $wpdb;
        $social_table = $wpdb->base_prefix . 'uwp_social_profiles';
        $wpdb->query($wpdb->prepare("DELETE FROM {$social_table} WHERE user_id = %d", $user_id));
    }

    public function admin_notification( $user_id, $provider ) {
        //Get the user details
        $user = new WP_User($user_id);
        $user_login = stripslashes( $user->user_login );
        $profile_url = uwp_build_profile_tab_url($user_id);

        // The blogname option is escaped with esc_html on the way into the database
        // in sanitize_option we want to reverse this for the plain text arena of emails.
        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $message  = sprintf(__('New user registration on your site: %s', 'uwp-social'), $blogname        ) . "\r\n\r\n";
        $message .= sprintf(__('Username: %s'                          , 'uwp-social'), $user_login      ) . "\r\n";
        $message .= sprintf(__('Provider: %s'                          , 'uwp-social'), $provider        ) . "\r\n";
        $message .= sprintf(__('Profile: %s'                           , 'uwp-social'), $profile_url  ) . "\r\n";
        $message .= sprintf(__('Email: %s'                             , 'uwp-social'), $user->user_email) . "\r\n";

        $message = apply_filters('uwp_social_admin_notification_content', $message, $user_id, $provider);

        wp_mail(get_option('admin_email'), sprintf(__('[%s] New User Registration', 'uwp-social'), $blogname), $message);
    }

    public function admin_notices(){
        ?>
        <div class="notice error uwp-social-authuri-notice is-dismissible">
            <p><?php echo sprintf(__( '<strong>Breaking change: </strong> Authorized Redirect URI for all social login providers needs to be updated on apps. Go to %ssettings%s to get the new URI.', 'uwp-social' ), '<a href="'.admin_url( 'admin.php?page=userswp&tab=uwp-social' ).'">', '</a>'); ?></p>
        </div>
        <?php
    }

    public function admin_footer_js(){
        ?>
        <script type="text/javascript">
        jQuery(document).on( 'click', '.uwp-social-authuri-notice .notice-dismiss', function() {

            jQuery.ajax({
                url: ajaxurl,
                    data: {
                    action: 'uwp_social_dismiss_authuri_notice'
                }
            });

        });
        </script>
        <?php
    }

    public function dismiss_notice(){
        update_option( 'uwp-social-authuri-notice-dismissed', 1 );
        wp_die(1);
    }

}