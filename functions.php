<?php

// function for setting up log definitions
function jobconvo_log_setup($name = '')
{
    // Show all errors and log all errors
    $logFolder = dirname(__FILE__) . "/log/" . date("Ym") . "/";
    if ( defined( 'DOING_CRON' ) ) {
        $ext = '.cron.log';
    } else {
        $ext = '.log';
    }
    $logPath = $logFolder . date("Ymd") . $name . $ext;
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    ini_set("log_errors", 1);
    ini_set("error_log", $logPath);

    // Create log directory if not exists
    if (!is_dir($logFolder)) {
        mkdir($logFolder, 0777, true);
    }
    return $logPath;
}

// Setting up a different file for mail logging to avoid  polluting the log files
function jobconvo_mail_log_setup($name = '')
{
    // Show all errors and log all errors
    $logFolder = dirname(__FILE__) . "/log/" . date("Ym") . "/";
    $logPath = $logFolder . date("Ymd") . $name . "_mail.log";
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    ini_set("log_errors", 1);
    ini_set("error_log", $logPath);

    // Create log directory if not exists
    if (!is_dir($logFolder)) {
        mkdir($logFolder, 0777, true);
    }
    return $logPath;
}

/**
 * Takes in a variable and prints it out in a readable format
 * 
 * @param data The data to be dumped.
 */
function dump($data){
    if(is_array($data) || is_object($data)){

        ?>
        <style>
            pre{
                background-color: #000;
                color: #fff !important;
                padding: 10px 20px;
            }

        </style>
        <?php

        echo "<br/>".
        "<pre>";
        print_r($data); 
        echo "</pre>";

    } else {
        if($data == false) {
            ?>
            <style>
                pre{
                    background-color: #000;
                    color: #fff !important;
                    padding: 10px 20px;
                }
    
            </style>
            <?php
            echo "<pre>";
            echo "false";
            echo "</pre>";
        } elseif($data == null) {
            ?>
            <style>
                pre{
                    background-color: #000;
                    color: #fff !important;
                    padding: 10px 20px;
                }
    
            </style>
            <?php
            echo "<pre>";
            echo "null";
            echo "</pre>";
        } else{
            ?>
            <style>
                pre{
                    background-color: #000;
                    color: #fff !important;
                    padding: 10px 20px;
                }
    
            </style>
            <?php
            echo "<pre>";
            echo $data;
            echo "</pre>";
        }
    }
}

/**
 * It returns a post object if the post type is 'jobs' and false if it's not
 * 
 * @param id The ID of the job you want to get.
 * 
 * @return the post type of the job.
 */
function get_job($id){
    $job = get_post($id);

    return get_post_type($job) == 'jobs' ? $job : false;
}

// Logger function to store messages regarding the plugin
function log_info($message)
{
    error_log('[' . date('Y-m-d H:i:s') . '][INFO] ' . $message . "\n", 3, jobconvo_log_setup());
}

// Logger function to store messages regarding the plugin
function as_log_warn($message)
{
    error_log('[' . date('Y-m-d H:i:s') . '][WARN] ' . $message . "\n", 3, jobconvo_log_setup());
}

// Logger function to store messages regarding the plugin
function as_log_error($message)
{
    error_log('[' . date('Y-m-d H:i:s') . '][ERROR] ' . $message . "\n", 3, jobconvo_log_setup());
}

function as_mailer_log($message)
{
    error_log('[' . date('Y-m-d H:i:s') . '][ERROR] ' . $message . "\n", 3, jobconvo_mail_log_setup());
}

// Email sending feature. Allows notifications to plugin administrator
function log_email($subject, $text)
{
    $headers = 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= 'From: no-reply@' . str_replace('http://', '', get_site_url()) . "\r\n";
    //$headers .= 'Reply-to: ' . $arr['email'] . "\r\n";
    //$headers .= 'Bcc: ' . self::to() . "\r\n";

    mail(log_email_to(), $subject, $text, $headers);
}

function log_email_to()
{
    $text = file_get_contents(plugin_dir_path(__FILE__) . 'configs/admin_email.txt');
    $emails = array_filter(explode("\n", $text), function ($k) {
        return strlen($k) > 1;
    });
    return implode(',', $emails);
}

function plugin_dir()
{
    return plugin_dir_path(__FILE__);
}

function plugin_url()
{
    return plugin_dir_url(__FILE__);
}

function log_post_saved()
{
    $post = get_post();
    if (get_post_status() == false) {
        log_info('Post auto-draft');
    } else {
        log_info('Post ID ' . $post->ID . ' saved, status is ' . $post->post_status . ' , parent is ' . $post->post_parent);
    }
}

function plugin_name()
{
    return basename(dirname(__FILE__));
}

function tablePrefix()
{
    global $wpdb;
    return $wpdb->prefix . plugin_name() . "_";
}

// returns true if table exists, false otherwise
function tableExists($tableName)
{
    global $wpdb;
    return ($wpdb->get_var("show tables like '{$tableName}'") == $tableName);
}

function createTable($sql)
{
    require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// check if a record exists in a table
function recordExists($table, $field, $value)
{
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `{$field}` = %s LIMIT 1",
            $value
        )
    );

    return ($row != null);
}

function add_cap($capability, $roles = [])
{
    foreach ($roles as $role) {
        $r = get_role($role);
        $r->add_cap($capability);
    }
}

function get_log_date_files()
{
    $dir = dirname(__FILE__) . "/log/";

    // Get all files in the directory recursively and return them as an array
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    $files = array();

    foreach ($rii as $file) {
        if ($file->isDir()) {
            continue;
        }

        
        // Get filename without extension
        $filename = $file->getBasename('.log');

        if (str_contains($filename, 'mail')) {
            continue;
        }

        // Convert to date format
        $filename = substr($filename, 0, 4) . '-' . substr($filename, 4, 2) . '-' . substr($filename, 6, 2);
        $month = date('F', strtotime($filename));
        
        $files[$month][] = [
            'path' => str_replace($dir, '', $file->getPathname()),
            'name' => $filename
        ];
    }

    return $files;
}

function get_log_file_content_by_date($file_name)
{
    log_info('Getting log file content for ' . $file_name);
    $file_path = dirname(__FILE__) . "/log/" . $file_name;

    if (file_exists($file_path)) {
        return file_get_contents($file_path);
    }

    return "";
}

// returns the content of a log file for a given filename called by Ajax
function logs_action_callback()
{
    $date = null;
    $result = null;

    if (isset($_POST)) {
        $date = $_POST['date'];
    } else if (isset($_GET)) {
        $date = $_GET['date'];
    }

    if (defined('DOING_AJAX') && DOING_AJAX) {
        $result = get_log_file_content_by_date($date);
        if ($result) {
            echo $result;
        }
    }

    wp_die(); // this is required to terminate immediately and return a proper response
}
add_action('wp_ajax_logs_action_callback', 'logs_action_callback');
add_action('wp_ajax_nopriv_logs_action_callback', 'logs_action_callback');

add_action('user_register', 'jobconvo_create_candidate');
// New user registration hook
// This is where we will create a new user in JobConvo after user registration
function jobconvo_create_candidate($user_id)
{
    log_info('New user registered with ID: ' . $user_id);

    try {
        // Get user info
        $user = get_userdata($user_id);

        if ($user) {
            // call the method reponsible to create the user in the API
            (new JobconvoApi())->create_or_update_candidate($user);
        } else {
            log_info('New user not found');
        }
    } catch (\Throwable $th) {
        log_info('Error creating user on JobConvo: ' . $th->getMessage());
    }
}

add_action('profile_update', 'candidate_profile_update', 10, 2);
function candidate_profile_update($user_id, $old_user_data)
{
    if (!$user_id) {
        log_info('User ID not provided');
        return;
    }

    if (!$_POST) {
        // Data comming from cron job, ignore
        return;
    }

    try {
        // Get user info
        $user = get_userdata($user_id);

        if ($user) {
            // call the method reponsible to create the user in the API
            (new JobconvoApi())->create_or_update_candidate($user);
        } else {
            log_info('New user not found');
        }
    } catch (\Throwable $th) {
        log_info('Error creating user on JobConvo: ' . $th->getMessage());
    }
}

// Function responsible to start the sync with Jobconvo API triggered by a cron job
function jobconvo_sync_candidates()
{
    log_info('Starting sync with JobConvo');

    try {
        // call the method reponsible to create the user in the API
        (new JobconvoApi())->sync();
    } catch (\Throwable $th) {
        log_info('Error syncing candidates on JobConvo: ' . $th->getMessage());
    }
}

/**
 * Retrieves the user meta ID from the WordPress database based on the provided meta
 * key and value.
 * 
 * @param string meta_key The meta key is a string that identifies the specific user meta data that is being
 * searched for. It is used to retrieve a specific piece of information about a user, such as their
 * email address or their first name.
 * @param mixed meta_value The value of the user meta data that you want to search for.
 * 
 * @return mixed either the `umeta_id` value from the `usermeta` table in the WordPress database if a
 * matching row is found with the given `meta_key` and `meta_value`, or it is returning `false` if no
 * matching row is found.
 */
function get_user_meta_id($meta_key, $meta_value) {

    global $wpdb;

    if( !is_serialized( $meta_value ) ) {
        $meta_value = maybe_serialize($meta_value);
    }

    $sql = "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key = '{$meta_key}' AND meta_value = '{$meta_value}'";

    $id = $wpdb->get_var($sql);

    if ($id) {
        return $id;
    } else {
        return false;
    }

}
