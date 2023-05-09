<?php

/*
  Plugin Name: Jobconvo API Integration by ALFASOFT
  Plugin URI:
  Description: Jobconvo API Integration
  Author: Alfasoft
  Version: DYNAMIC_APP_VERSION_DO_NOT_CHANGE
  Author URI: https://www.alfasoft.pt
 */

/**
 * Description of AlfasoftJobconvo
 *
 * @author Alfasoft
 */
class AlfasoftJobconvo
{

    public static $LogPath;

    /*
     * Plugin API/Action Reference
     * https://codex.wordpress.org/Plugin_API/Action_Reference
     */
    function __construct()
    {
        require_once(plugin_dir_path(__FILE__) . 'functions.php');
        require_once(plugin_dir_path(__FILE__) . 'JobconvoApiClient.php');
        require_once(plugin_dir_path(__FILE__) . 'JobconvoApi.php');
        require_once(plugin_dir_path(__FILE__) . 'DocumentAI.php');
        require_once(plugin_dir_path(__FILE__) . 'filters.php');

        add_action('google_service_account_update', [$this, 'update_google_service_account']);
        
        add_action('user_uploaded_cv', [$this, 'get_cv_info'] ) ;

        add_action('admin_menu', [$this, 'adminMenu'], 3);
        //load css files
        add_action('admin_enqueue_scripts', [$this, 'styles'], 1);
        //load js files
        add_action('admin_enqueue_scripts', [$this, 'scripts'], 1);

        //Action hook is fired once WordPress, all plugins, and the theme are fully loaded and instantiated.
        //Fires after init but before admin_init using Ajax calls
        add_action('wp_loaded', [$this, 'actions']);

        add_action('admin_init', [$this, 'registerSettings']);

        add_action('admin_init', [$this, 'blockAdminAccess']);

        add_filter( 'show_admin_bar', [$this, 'hideAdminBar'] );
        
        add_action('add_user_application_meta', [$this, 'user_register_application'], 10, 2);

        add_action('delete_user_meta', [$this, 'user_delete_application'], 10, 4);
        
        add_action('add_user_application_meta', [$this, 'user_sync_applications_metadata_in_translations'], 100, 2);
        
        add_action('edit_user_profile_update', [$this, 'update_user']);

        add_action('before_delete_education', [$this, 'delete_education']);

        add_action('before_delete_work_experience', [$this, 'delete_work_experience']);

        add_action('before_delete_language', [$this, 'delete_language']);

        add_action('before_delete_website', [$this, 'delete_website']);
        
        add_action('delete_user_account', [$this, 'delete_user_account']);

        add_action('wp_mail_failed', [$this, 'log_mailer_errors'], 10, 1);

        add_action( 'as_manual_sync', [$this, 'manual_sync'] );

        add_action( 'edit_post', [$this, 'notify_edit_translation'], 10, 2);

        add_filter( 'cron_schedules', [$this, 'add_custom_cron_schedules'] );

        add_action( 'as_cron_check_translations', [$this, 'check_drafted_translations'] );

        add_action( 'user_uploaded_cv', [$this, 'as_send_cv'] );

        add_action( 'draft_to_publish', [$this, 'store_job_last_publish_date'], 10 );

        if ( ! wp_next_scheduled( 'as_cron_check_translations' ) ) {
            wp_schedule_event( time(), 'daily', 'as_cron_check_translations' );
        }

        add_action( 'as_cron_full_sync', [$this, 'sync'] );

        if ( ! wp_next_scheduled( 'as_cron_full_sync' ) ) {
            wp_schedule_event( time(), 'ten_minutes', 'as_cron_full_sync' );
        }
    }

    public function add_custom_cron_schedules( $schedules ) { 
        $schedules['minute'] = array(
            'interval' => 60,
            'display'  => esc_html__( 'Every minute' ), );
        
        $schedules['ten_minutes'] = array(
            'interval' => 600,
            'display'  => esc_html__( 'Every 10 minutes' ), );
        return $schedules;
    }

    public function blockAdminAccess() {
        if ( is_admin() && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            wp_safe_redirect( home_url() );
            exit;
        }
    }

    public function hideAdminBar( $show ) {
        if ( ! current_user_can( 'administrator' ) ) {
            return false;
        }
    
        return $show;
    }

    public function update_google_service_account($data) {
        update_option( plugin_name() . '_google_documentai_service_account', $data );
    }

    /**
     * It updates the post meta field 'last_publish_date' with the current time whenever a post is
     * published
     * 
     * @param post The post object.
     */
    public function store_job_last_publish_date($job) {
        update_post_meta($job->ID, 'last_publish_date', current_time('mysql'));
    }

    public function get_cv_info($cv_id){

        // If Google Credentials fail, return with error message
        if( !get_option( plugin_name() . '_google_documentai_service_account' ) ) {
            wp_redirect( add_query_arg(['failed' => 'true'], get_permalink()) );
            exit;
        }

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('User Update By CV routine - BEGIN');
        log_info('*------------------------------------------------------------------------------------------------------*');
        
        log_info('Sending CV file to Google Document AI');
        $document_ai = new AS_Google_DocumentAI();

        $cv_text = $document_ai->processDocument($cv_id);

        if($cv_text) {
            log_info('CV Text from Document AI acquired succesfuly!');
        } else { 
            as_log_error('Failed to acquire CV Text from Document AI, aborting...');
            log_info('*------------------------------------------------------------------------------------------------------*');
            log_info('User Update By CV routine - END');
            log_info('*------------------------------------------------------------------------------------------------------*');
            return false;
        }

        $this->update_user_by_cv($cv_text);

    }

    public function check_drafted_translations(){

        if (!is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' )) {
            return false;
        }

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('CRON JOB - Checking drafted translations - BEGIN');
        log_info('*------------------------------------------------------------------------------------------------------*');

        $posts = get_posts([
            'post_type' => 'jobs',
            'post_status' => 'draft',
            'posts_per_page' => -1,
        ]);

        $interval = get_option( plugin_name() . '_options' )[ plugin_name() . '_translation_delete_interval' ];

        log_info('Interval for deleting drafted translations defined in system: ' . $interval . ' days');

        foreach ($posts as $post) {
            $data = get_post_meta($post->ID);

            // Ignore jobs that are not translations
            if(isset($data['original_post_id'])){
                log_info( "Checking Translation {$post->ID}" );

                $post_parent = $data['original_post_id'][0];
                
                if ( get_post_meta($post_parent, 'last_publish_date', true) ) {
                    $post_date = get_post_meta($post_parent, 'last_publish_date', true);
                } else {
                    $post_date = $post->post_date;
                }
                
                $diff = (new DateTime($post_date))->diff(new DateTime( date( 'Y-m-d H:i:s' ) ))->days;
                
                if($diff > $interval){
                    log_info("Translation is in draft for {$diff} days, reverting original job to draft state");
                    $args = [
                        'ID' => $post_parent,
                        'post_status' => 'draft'
                    ];
                    wp_update_post($args);
                } else {
                    log_info("Translation is in draft for {$diff} days, doing nothing");
                }
            }
        }
        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('CRON JOB - Checking drafted translations - END');
        log_info('*------------------------------------------------------------------------------------------------------*');
    }

    public function notify_edit_translation($post_id, $post){

        if( $post->post_type == 'jobs' ) {
            return false;
        }

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info("Job Offer - {$post_id} updated, notifying admin - BEGIN");
        log_info('*------------------------------------------------------------------------------------------------------*');

        $from_name = get_option('blogname');
        $from_email = get_option('admin_email');
        $mail_to = get_option( plugin_name() . '_options' )[ plugin_name() . '_translation_mail_to' ];

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = "From: {$from_name} <{$from_email}>";

        $message = "<h2>A seguinte oferta de trabalho foi atualizada, favor, revisar a oferta e suas traduções:</h2>";
        $message.= "<a href='" . get_edit_post_link($post_id) . "'>{$post->post_title}</a>";

        $mail_status = wp_mail( $mail_to, 'Uma oferta de trabalho foi atualizada', $message, $headers );
        if ($mail_status){
            log_info('Mail sent successfully');
        } else {
            log_info('Mail could not be sent');
        }

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info("Job Offer - {$post_id} updated, notifying admin - END");
        log_info('*------------------------------------------------------------------------------------------------------*');
    }

    public function render_settings_field($args)
    {
        $name = explode('[', $args['name'])[0];
        if ($args['wp_data'] == 'option') {
            $wp_data_value = get_option($name);
        } elseif ($args['wp_data'] == 'post_meta') {
            $wp_data_value = get_post_meta($args['post_id'], $name, true);
        }

        switch ($args['type']) {
            case 'input':
                $value = ($args['value_type'] == 'serialized') ? serialize($wp_data_value) : $wp_data_value;

                if ($args['subtype'] != 'checkbox') {
                    $prependStart = (isset($args['prepend_value'])) ? '<div class="input-prepend"> <span class="add-on">' . $args['prepend_value'] . '</span>' : '';
                    $prependEnd = (isset($args['prepend_value'])) ? '</div>' : '';
                    $step = (isset($args['step'])) ? 'step="' . $args['step'] . '"' : '';
                    $min = (isset($args['min'])) ? 'min="' . $args['min'] . '"' : '';
                    $max = (isset($args['max'])) ? 'max="' . $args['max'] . '"' : '';
                    if (isset($args['disabled'])) {
                        // hide the actual input bc if it was just a disabled input the informaiton saved in the database would be wrong - bc it would pass empty values and wipe the actual information
                        echo $prependStart . '<input type="' . $args['subtype'] . '" id="' . $args['id'] . '_disabled" ' . $step . ' ' . $max . ' ' . $min . ' name="' . $args['name'] . '_disabled" size="40" disabled value="' . esc_attr($value[$args['id']]) . '" /><input type="hidden" id="' . $args['id'] . '" ' . $step . ' ' . $max . ' ' . $min . ' name="' . $args['name'] . '" size="40" value="' . esc_attr($value[$args['id']]) . '" />' . $prependEnd;
                    } else {
                        echo $prependStart . '<input type="' . $args['subtype'] . '" id="' . $args['id'] . '" "' . $args['required'] . '" ' . $step . ' ' . $max . ' ' . $min . ' name="' . $args['name'] . '" size="40" value="' . esc_attr($value[$args['id']]) . '" />' . $prependEnd;
                    }
                    /*<input required="required" '.$disabled.' type="number" step="any" id="'.$this->plugin_name.'_cost2" name="'.$this->plugin_name.'_cost2" value="' . esc_attr( $cost ) . '" size="25" /><input type="hidden" id="'.$this->plugin_name.'_cost" step="any" name="'.$this->plugin_name.'_cost" value="' . esc_attr( $cost ) . '" />*/
                } else {
                    $checked = ($value[$args['id']]) ? 'checked' : '';
                    echo '<input type="' . $args['subtype'] . '" id="' . $args['id'] . '" "' . $args['required'] . '" name="' . $args['name'] . '" size="40" value="1" ' . $checked . ' />';
                }
                break;
            default:
                # code...
                break;
        }
    }

    function registerSettings()
    {
        $pluginName = plugin_name();

        // register options to be handled
        register_setting($pluginName, "{$pluginName}_options");

        // register a new section in the plugin page
        add_settings_section(
            "{$pluginName}_section_credentials",
            __('API credentials', $pluginName),
            false,
            $pluginName
        );

        // register a new field in the "wporg_section_credentials" section, inside the plugin page
        add_settings_field(
            "{$pluginName}_URL", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('URL', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_credentials",
            [
                'label_for' => "{$pluginName}_url",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_url",
                'name'      => "{$pluginName}_options[{$pluginName}_url]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option'
            ]
        );

        add_settings_field(
            "{$pluginName}_Username", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Username', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_credentials",
            [
                'label_for' => "{$pluginName}_username",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_username",
                'name'      => "{$pluginName}_options[{$pluginName}_username]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option'
            ]
        );

        add_settings_field(
            "{$pluginName}_Password", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Password', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_credentials",
            [
                'label_for' => "{$pluginName}_password",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_password",
                'name'      => "{$pluginName}_options[{$pluginName}_password]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option'
            ]
        );

        add_settings_field(
            "{$pluginName}_ClientID", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Client ID', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_credentials",
            [
                'label_for' => "{$pluginName}_client_id",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_client_id",
                'name'      => "{$pluginName}_options[{$pluginName}_client_id]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option'
            ]
        );

        add_settings_field(
            "{$pluginName}_ClientSecrect", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Client Secrect', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_credentials",
            [
                'label_for' => "{$pluginName}_client_secrect",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_client_secrect",
                'name'      => "{$pluginName}_options[{$pluginName}_client_secrect]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option'
            ]
        );




        add_settings_section(
            "{$pluginName}_section_translations_settings",
            __('Translations Settings', $pluginName),
            false,
            $pluginName
        );

        add_settings_field(
            "{$pluginName}_translation_delete_interval", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Days to delete "Draft" translation', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_translations_settings",
            [
                'label_for' => "{$pluginName}_translation_delete_interval",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'number',
                'id'    => "{$pluginName}_translation_delete_interval",
                'name'      => "{$pluginName}_options[{$pluginName}_translation_delete_interval]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option',
                'min' => 1,
                'step' => 1
            ]
        );

        add_settings_field(
            "{$pluginName}_translation_mail_to", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Email to notify of created translations', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_translations_settings",
            [
                'label_for' => "{$pluginName}_translation_mail_to",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'email',
                'id'    => "{$pluginName}_translation_mail_to",
                'name'      => "{$pluginName}_options[{$pluginName}_translation_mail_to]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option',
            ]
        );

        add_settings_section(
            "{$pluginName}_section_syncing_settings",
            __('Syncing Settings', $pluginName),
            false,
            $pluginName
        );

        add_settings_field(
            "{$pluginName}_partner_company", // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __('Company to sync job offers', $pluginName),
            [$this, "render_settings_field"],
            $pluginName,
            "{$pluginName}_section_syncing_settings",
            [
                'label_for' => "{$pluginName}_partner_company",
                'class' => "{$pluginName}_row",
                'type'      => 'input',
                'subtype'   => 'text',
                'id'    => "{$pluginName}_partner_company",
                'name'      => "{$pluginName}_options[{$pluginName}_partner_company]",
                'required' => 'true',
                'get_options_list' => '',
                'value_type' => 'normal',
                'wp_data' => 'option',
            ]
        );
    }

    function adminMenu()
    {
        //define errors if on admin area
        self::$LogPath = jobconvo_log_setup();

        //just log when save
        add_action('save_post', 'log_post_saved', 0);

        // add capabilities to roles, so we can see the menu
        add_cap('jobconvo-editor', ['administrator', 'editor']);

        $menus = include(plugin_dir_path(__FILE__) . 'config/admin_menu.php');

        foreach ($menus as $menu) {

            add_menu_page(
                $menu['page_title'],
                $menu['menu_title'],
                $menu['capability'],
                $menu['slug'],
                [$this, $menu['function']],
                $menu['icon_url'],
                $menu['position']
            );

            if (isset($menu['submenu'])) {
                foreach ($menu['submenu'] as $submenu) {
                    add_submenu_page(
                        $menu['slug'],
                        $submenu['page_title'],
                        $submenu['menu_title'],
                        $submenu['capability'],
                        $submenu['slug'],
                        [$this, $submenu['function']],
                        $submenu['position']
                    );
                }
            }
        }
    }

    // menu function for showing settings page
    function adminSettings()
    {
        require_once(plugin_dir_path(__FILE__) . 'admin/PluginAdmin.php');
        PluginAdmin::Settings();
    }

    // Menu function for showing sync page
    function adminLogs()
    {
        require_once(plugin_dir_path(__FILE__) . 'admin/PluginAdmin.php');
        PluginAdmin::Logs();
    }

    function styles()
    {
        wp_enqueue_style('bootstrap-base',  plugin_dir_url(__FILE__) . 'admin/css/styles.css');
    }

    function scripts()
    {
        wp_enqueue_script('mediaelement', plugins_url('wp-mediaelement.min.js', __FILE__), ['jquery'], '4.8.2', true);
    }

    /**
     * AJAX requests should use wp-admin/admin-ajax.php. admin-ajax.php can handle requests for users not logged in.
     * The wp_loaded action fires after init but before admin_init.
     * Front-End: init -> widgets_init -> wp_loaded
     * Admin: init -> widgets_init -> wp_loaded -> admin_menu -> admin_init
     */
    function actions()
    {
        //ajax call
        if (null != filter_input(INPUT_GET, 'select_times', FILTER_SANITIZE_STRING)) {
            require_once(plugin_dir_path(__FILE__) . 'ajax/TimeUtils.php');
            echo TimeUtils::getSelectTimes();
            die();
        }
        if (null != filter_input(INPUT_GET, 'reservation', FILTER_SANITIZE_STRING)) {
            self::$LogPath =  asoft_log();
            require_once(plugin_dir_path(__FILE__) . 'ajax/ReservationAjaxUtils.php');
            echo ReservationAjaxUtils::boot();
            die();
        }
        if (null != filter_input(INPUT_GET, 'vehicle', FILTER_SANITIZE_STRING)) {
            require_once(plugin_dir_path(__FILE__) . 'ajax/VehicleJUtils.php');
            echo VehicleJUtils::select();
            die();
        }
        if (null != filter_input(INPUT_GET, 'availability', FILTER_SANITIZE_STRING)) {
            require_once(plugin_dir_path(__FILE__) . 'ajax/VehicleJUtils.php');
            echo VehicleJUtils::getAvailabilityArray();
            die();
        }
    }

    public function cron_sync_jobs(){
        log_info('Iniciando Sincronização de Ofertas de Emprego');
        $this->sync();
        log_info('Sincronização de Ofertas de Emprego finalizada');
    }

    public function manual_sync(){
        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('Manual Synchronization Started');
        log_info('*------------------------------------------------------------------------------------------------------*');
        
        $this->sync();
        
        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('Manual Synchronization Finished');
        log_info('*------------------------------------------------------------------------------------------------------*');    
    }

    public function user_register_application($application, $application_id){
        
        $user_application = get_metadata_by_mid('user',$application_id);

        $user = get_user_by('ID',$user_application->user_id);
        $user_meta = get_user_meta($user->ID, 'user_application');
        $job = get_job($user_application->meta_value['job']);
        $job_meta = get_post_meta($job->ID);
        // dump($user);
        // dump($user_meta);
        // dump($job);
        // dump($job_meta);
        // dump($_POST);
        // die();

        $pk = (new JobConvoAPI())->apply_for_job($user, $job);

        if ($pk) {
            $user_application->meta_value['pk'] = $pk;
            update_metadata_by_mid('user',$application_id, $user_application->meta_value);
        }
    }

    public function user_delete_application($meta_ids, $object_id, $meta_key, $meta_value){
        
        if ($meta_key == 'user_application') {
            if (isset($meta_value['pk'])) {
                $pk = $meta_value['pk'];
            } else {
                return false;
            }

            (new JobConvoAPI())->delete_job_application($pk);

        }
    }

    /**
     * It takes the application data from the current language and creates a new application for each
     * translation of the job post
     * 
     * @param application The application data that is being saved.
     */
    public function user_sync_applications_metadata_in_translations($application, $application_id) {

        if (!is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' )) {
            return false;
        }
        
        $user_application = get_metadata_by_mid('user',$application_id);
        $user = get_user_by('ID',$user_application->user_id);
        $application = $user_application->meta_value;

        log_info("Syncing application for job {$application['job']} between all languages");
        log_info("Original application: " . json_encode($application));

        $translations = apply_filters('alfasoft_get_related_jobs', [], $application);

        foreach($translations as $key => $value) {

            if($key == $application['language']) {
                continue;
            } else {

                $newdata = [
                    'job' => $value,
                    'date' => $application['date'],
                    'status' => $application['status'],
                    'language' => $key,
                    'sync' => true,
                ];

                if( isset($application['pk']) ) {
                    $newdata['pk'] = $application['pk'];
                }

                if( isset($application['found_at']) ) {
                    $newdata['found_at'] = $application['found_at'];
                }

                add_user_meta($user->ID, 'user_application', $newdata);
                log_info("Application synced for language: {$key}");
            }

        }

    }

    public function as_send_cv( $cv_id ) {

        $cv = get_post($cv_id);

        (new JobConvoAPI())->send_cv_to_jobconvo( $cv );

    }

    public function update_user(){
        $user = wp_get_current_user();
        // $usermeta = get_user_meta($user->ID);
        
        (new JobConvoAPI())->create_or_update_candidate($user);
    }

    public function update_user_by_cv($cv_text){
        $user = wp_get_current_user();
        (new JobConvoAPI())->update_candidate_by_cv($user, $cv_text);
    }

    public function delete_education($number){
        (new JobConvoAPI())->delete_education($number);
    }

    public function delete_work_experience($number){
        (new JobConvoAPI())->delete_work_experience($number);
    }

    public function delete_language($number){
        (new JobConvoAPI())->delete_language($number);
    }

    public function delete_user_account($user_id){
        (new JobConvoAPI())->delete_user($user_id);
    }

    public function delete_website($number){
        log_info("Hook for deleting website {$number}");
        (new JobConvoAPI())->delete_website($number);
    }

    public static function Logs()
    {
        return get_log_date_files() ?? [];
    }

    public static function setLog($name = '')
    {
        self::$LogPath =  asoft_log($name);
    }

    public static function log_mailer_errors( $wp_error ){
        as_mailer_log("Mailer Error: " . $wp_error->get_error_message() ."\n");
    }

    /**
     * Endpoint for call by cron job to sync data
     */
    public static function sync()
    {
        (new JobconvoAPI())->sync();
    }

}

/**
 * Installer
 */
function AlfasoftJobconvo_Setup()
{
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    require_once(plugin_dir_path(__FILE__) . 'Installer.php');

    Installer::Run("AlfasoftJobconvo");
}
register_activation_hook(__FILE__, 'AlfasoftJobconvo_Setup');

new AlfasoftJobconvo();
