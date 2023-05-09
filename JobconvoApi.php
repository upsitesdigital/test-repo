<?php

class JobconvoAPI
{
    private $CANDIDACY;
    // Educations and Work Experiences classes are inside the CANDIDATE class
    private $CANDIDATE;
    private $JOB;
    private $IBM;
    function __construct()
    {
        require_once(plugin_dir_path(__FILE__) . 'Candidacy.php');
        require_once(plugin_dir_path(__FILE__) . 'Candidate.php');
        require_once(plugin_dir_path(__FILE__) . 'Job.php');
        require_once(plugin_dir_path(__FILE__) . 'IBM-api.php');

        $this->CANDIDATE = new Candidate();
        $this->CANDIDACY = new Candidacy();
        $this->JOB = new Job();
        $this->IBM = new IBM_Watson_API();
    }

    public function delete_user($user_id)
    {
        // IMPORTANT //
        // Info coming from Easy WP SMTP plugin
        // Plugin page if needed: https://br.wordpress.org/plugins/easy-wp-smtp/
        $smtp = get_option('swpsmtp_options');

        $wp_user = get_user_by('id', $user_id);
        $user = get_user_by('email', $wp_user->user_email);
        $pk = get_user_meta($user_id, 'pk');

        log_info("Deleting user with pk: {$pk[0]}"); 

        $result = $this->CANDIDATE->Delete($pk);

        if ($result){
            log_info('Candidate deleted successfully');

            $from_name = get_option('blogname');
            $from_email = get_option('admin_email');

            $headers = array('Content-Type: text/html; charset=UTF-8');
            $headers[] = "From: {$from_name} <{$from_email}>";
            
            $message = "<p>O usuário <strong>{$user->display_name}</strong> acabou de excluir sua conta, favor proceder com todos os parâmetros legais para finalizar sua exclusão.</p>";
            $message .= "<ul>";
            $message .= "<li>Login: {$user->user_login}</li>";
            $message .= "<li>Email: {$user->user_email}</li>";
            $message .= "</ul>";

            $mail_status = wp_mail( $from_email, 'Usuário excluído', $message, $headers );
            if ($mail_status){
                log_info('Mail sent successfully');
            } else {
                log_info('Mail could not be sent');
            }

        } else {
            as_log_error('Candidate not deleted');
        }
    }

    public function send_cv_to_jobconvo($cv, $user_id = null) {

        $this->CANDIDATE->send_cv_to_jobconvo($cv, $user_id);

    }

    /**
     * Function responsible to start the sync process.
     */
    public function sync()
    {
        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('Cron Sync Routine - BEGIN');
        log_info('*------------------------------------------------------------------------------------------------------*');
        
        // Sync all jobs
        self::sync_jobs(null);

        // Sync all candidates
        self::sync_candidates();

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('Cron Sync Routine - END');
        log_info('*------------------------------------------------------------------------------------------------------*');
    }

    // ####### Jobs - Begin ####### //

    /**
     * It gets all the jobs from JobConvo, and then it either updates or creates them in WordPress
     * 
     * @return the value of the variable .
     */
    private function sync_jobs()
    {
        global $wpdb;

        $page = 1;

        $jobs = [];
        // Itarate over all pages to get all jobs and store them in an array
        do {
            if (isset($response['results'])) {
                log_info('Found ' . count($response['results']) . ' jobs on page ' . ($page - 1));
                $jobs = array_merge($jobs, $response['results']);
            }

            log_info('Getting jobs page: ' . $page);

            $response = $this->JOB->Get('page=' . $page);
            $page++;
        } while (isset($response['results']) && count($response['results']) > 0);

        if (!$jobs) {
            log_info('No jobs found on JobConvo.');
            return;
        }

        log_info('Jobs found: ' . count($jobs));
        
        $pluginName = plugin_name();
        $partner_company = get_option( "{$pluginName}_options" )["{$pluginName}_partner_company"] ?? '';
        // Removing jobs with expired date or with custom status different from "Aberta" or coming from wrong partner_company
        foreach ($jobs as $key => $job) {
            $deadline = strtotime($job['deadline']);
            $now = strtotime(date('Y-m-d'));

            if ( ($deadline < $now || $job['custom_status'] != 'Aberta') || ( $partner_company && $job['partner_company'] != $partner_company ) ) {
                unset($jobs[$key]);
            }
        }

        log_info('Jobs not past deadline: ' . count($jobs));

        // Update or create jobs

        $translations = [];

        foreach ($jobs as $job) {
            log_info('Job ' . $job['pk']);

            $results = $wpdb->get_results(
                "SELECT * FROM {$wpdb->postmeta}
                WHERE meta_key = 'pk' AND meta_value = '{$job['pk']}'"
            );

            if ($results) {
                $this->update_job($results[0]->post_id, $job);
            } else {
                
                $job_id = $this->add_job($job);
                $job['wp_id'] = $job_id;

                // Check if WPML is active first
                if ( is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
                    $translations[] = $this->create_job_translation($job);
                }

            }
        }

        if ( !empty($translations) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
            log_info('Sending "Translations created" email');
            $this->mail_translations($translations);
        }

        // Get only ids from jobs coming from JobConvo
        // $job_ids = array_map(function ($job) {
        //     return $job['pk'];
        // }, $jobs);
        $job_ids = array_column($jobs, 'pk');
        log_info('Job ids: ' . implode(', ', $job_ids));

        $jobs_internal = $wpdb->get_results(
            "SELECT * FROM {$wpdb->postmeta}
            WHERE meta_key = 'pk'
            AND post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'jobs');"
        );

        $job_ids_internal = array_column($jobs_internal, 'meta_value');

        $jobs_delete = array_diff($job_ids_internal, $job_ids);

        if ($jobs_delete){
            log_info("Deleting jobs (" . count($results) . ") that are not on JobConvo anymore...");
            foreach($jobs_internal as $job){
                if( in_array( $job->meta_value, $jobs_delete ) )
                    // wp_delete_post($result->post_id, true); $result->post_id
                    // Hiding the job instead of deleting it, so it can be restored later.
                    // Status: publish|pending|draft|private|static|object|attachment|inherit|future|trash.
                    wp_update_post(array('ID' => $job->post_id, 'post_status' => 'draft'));
                    log_info('Job ' . $job->post_id . ' deleted.');
            }
        }

        // Delete jobs that are not on JobConvo anymore, by chucking the ids in chunks of 100
        // $chunks = array_chunk($job_ids, 100);
        // foreach ($chunks as $chunk) {
            // $results = $wpdb->get_results(
            //     "SELECT * FROM {$wpdb->postmeta}
            //     WHERE meta_key = 'pk' AND meta_value NOT IN ('" . implode("','", $chunk) . "')"
            // );

            // if ($results) {
            //     log_info("Deleting jobs (" . count($results) . ") that are not on JobConvo anymore...");
            //     foreach ($results as $result) {
            //         // wp_delete_post($result->post_id, true); $result->post_id
            //         // Hiding the job instead of deleting it, so it can be restored later.
            //         // Status: publish|pending|draft|private|static|object|attachment|inherit|future|trash.
            //         wp_update_post(array('ID' => $result->post_id, 'post_status' => 'draft'));
            //         log_info('Job ' . $result->post_id . ' deleted.');
            //     }
            // }
        // }

        log_info('Jobs sync finished.');
    }

    private function add_job($job)
    {

        log_info('Job does not exist in WordPress, creating...');

        $interval = get_option( plugin_name() . '_options' )[ plugin_name() . '_translation_delete_interval' ];

        if( $interval == 0 ) {
            $status = 'draft';
        } else {
            $status = 'publish';
        }

        $args = [
            'post_author' => 1,
            'post_title' => $job['title'],
            'post_status' => $status,
            'post_type' => 'jobs',
            'post_name' => str_replace(' ', '', $job['internal_job_title']),
            'meta_input'   => array(
                'pk' => $job['pk'],
                'job_description' => $job['description'],
                'job_requirements' => $job['requirements'],
                'job_comments' => $job['annotation'],
                'job_ref_number' => $job['ref_number'],
            ),

        ];

        register_taxonomy('council', 'jobs');
        register_taxonomy('district', 'jobs');
        register_taxonomy('location', 'jobs');
        register_taxonomy('job_category', 'jobs');

        $council_id = term_exists($job['city'], 'council');
        $district_id = term_exists($job['state'], 'district');
        $location_id = term_exists($job['address'], 'location');
        $category_id = term_exists($job['department'], 'job_category');

        if ($council_id == null) {
            $council_id = wp_insert_term($job['city'], 'council');
        }

        if ($district_id == null) {
            $district_id = wp_insert_term($job['state'], 'district');
        }

        if ($location_id == null) {
            $location_id = wp_insert_term($job['address'], 'location');
        }

        if ($category_id == null) {
            $category_id = wp_insert_term($job['department'], 'job_category');
        }

        $id = wp_insert_post($args);
        wp_set_post_terms($id, array((int)$council_id['term_id']), 'council');
        wp_set_post_terms($id, array((int)$district_id['term_id']), 'district');
        wp_set_post_terms($id, array((int)$location_id['term_id']), 'location');
        wp_set_post_terms($id, array((int)$category_id['term_id']), 'job_category');

        return $id;
    }

    private function update_job($id, $job)
    {

        $args = [
            'ID' => $id,
            'post_author' => 1,
            'post_title' => $job['title'],
            'post_status' => 'publish',
            'post_type' => 'jobs',
            'post_name' => str_replace(' ', '', $job['internal_job_title']),
            'meta_input'   => array(
                'pk' => $job['pk'],
                'job_description' => $job['description'],
                'job_requirements' => $job['requirements'],
                'job_comments' => $job['annotation'],
                'job_ref_number' => $job['ref_number'],
            ),

        ];

        register_taxonomy('council', 'jobs');
        register_taxonomy('district', 'jobs');
        register_taxonomy('location', 'jobs');
        register_taxonomy('job_category', 'jobs');

        $council_id = term_exists($job['city'], 'council');
        $district_id = term_exists($job['state'], 'district');
        $location_id = term_exists($job['address'], 'location');
        $category_id = term_exists($job['department'], 'job_category');
        
        
        log_info('Setting job terms for ID ' . print_r($id, 1));
        
        log_info('Job ' . print_r($job, 1));

        if ($council_id == null) {
            $council_id = wp_insert_term($job['city'], 'council');
        }

        if ($district_id == null) {
            $district_id = wp_insert_term($job['state'], 'district');
        }

        if ($location_id == null) {
            $location_id = wp_insert_term($job['address'], 'location');
        }

        if ($category_id == null) {
            $category_id = wp_insert_term($job['department'], 'job_category');
        }
        
        if (is_wp_error($council_id)) {
            log_info('Council id is an error, aborting: ' . print_r($council_id, 1));
            return false;
        }
        
        if (is_wp_error($district_id)) {
            log_info('District id is an error, aborting: ' . print_r($district_id, 1));
            return false;
        }
        
        if (is_wp_error($location_id)) {
            log_info('Location id is an error, aborting: ' . print_r($location_id, 1));
            return false;
        }
        
        if (is_wp_error($category_id)) {
            log_info('Category id is an error, aborting: ' . print_r($category_id, 1));
            return false;
        }
        
        log_info('Setting job terms, council ' . print_r($council_id, 1));
        log_info('Setting job terms, district ' . print_r($district_id, 1));
        log_info('Setting job terms, location ' . print_r($location_id, 1));
        log_info('Setting job terms, category ' . print_r($category_id, 1));

        wp_set_post_terms($id, array((int)$council_id['term_id']), 'council');
        wp_set_post_terms($id, array((int)$district_id['term_id']), 'district');
        wp_set_post_terms($id, array((int)$location_id['term_id']), 'location');
        wp_set_post_terms($id, array((int)$category_id['term_id']), 'job_category');

        $original_job = get_post($id);
        $original_job_meta = get_post_meta($id);

        /* 
        Checking if there are any actual changes
        This is necessary to avoid too many unnecessary emails being sent
        */

        if( 
            $original_job->post_title == $job['title']
            && $original_job_meta['job_description'][0] == $job['description']
            && $original_job_meta['job_requirements'][0] == $job['requirements']
            && $original_job_meta['job_comments'][0] == $job['annotation']
            && $original_job_meta['job_ref_number'][0] == $job['ref_number'] 
        ) {
            log_info('No changes in this job, skipping...');
            wp_publish_post($id);
            return false;
        }

        log_info('Job exists in WordPress, updating...');
        
        wp_update_post($args);
        wp_publish_post($id);
    }

    private function remove_job($id)
    {
        wp_delete_post($id);

        // dump('Job Removido');
    }

    private function create_job_translation($job){

        log_info("Creating translation for job - {$job['pk']}");

        $wpml_post_type = apply_filters(  'wpml_element_type', 'jobs');
        $original_post_language_info = apply_filters( 'wpml_element_language_details', null, [
            'element_id' => $job['wp_id'],
            'element_type' => $wpml_post_type
        ] );

        $args = [
            'post_author' => 1,
            'post_title' => $this->IBM->get_translation($job['title']),
            'post_status' => 'draft',
            'post_type' => 'jobs',
            'post_name' => 'english-' . str_replace(' ', '', $job['internal_job_title']),
            'meta_input'   => array(
                'pk' => $job['pk'],
                'job_description' => $this->IBM->get_translation($job['description']),
                'job_requirements' => $this->IBM->get_translation($job['requirements']),
                'job_comments' => $this->IBM->get_translation($job['annotation']),
                'job_ref_number' => $job['ref_number'],
                'original_post_id' => $job['wp_id'],
                'language' => 'en'
            ),

        ];

        register_taxonomy('council', 'jobs');
        register_taxonomy('district', 'jobs');
        register_taxonomy('location', 'jobs');
        register_taxonomy('job_category', 'jobs');

        $council_id = term_exists($job['city'], 'council');
        $district_id = term_exists($job['state'], 'district');
        $location_id = term_exists($job['address'], 'location');
        $category_id = term_exists($job['department'], 'job_category');

        if ($council_id == null) {
            $council_id = wp_insert_term($job['city'], 'council');
        }

        if ($district_id == null) {
            $district_id = wp_insert_term($job['state'], 'district');
        }

        if ($location_id == null) {
            $location_id = wp_insert_term($job['address'], 'location');
        }

        if ($category_id == null) {
            $category_id = wp_insert_term($job['department'], 'job_category');
        }

        $translated_id = wp_insert_post($args);

        wp_set_post_terms($translated_id, array((int)$council_id['term_id']), 'council');
        wp_set_post_terms($translated_id, array((int)$district_id['term_id']), 'district');
        wp_set_post_terms($translated_id, array((int)$location_id['term_id']), 'location');
        wp_set_post_terms($translated_id, array((int)$category_id['term_id']), 'job_category');

        $languageArgs = array(
            'element_id'    => $translated_id,
            'element_type'  => $wpml_post_type,
            'trid'          => $original_post_language_info->trid,
            'language_code' => 'en',
            'source_language_code' => $original_post_language_info->language_code
        );

        do_action( 'wpml_set_element_language_details', $languageArgs );

        update_post_meta($job['wp_id'], 'translations', [
            'en' => $translated_id
        ]);

        log_info("Translation created with post_id: {$translated_id}");
        
        return [
            'id' => $translated_id,
            'title' => 'English-' . $job['title']
        ];
    }

    private function mail_translations($translations){

        $from_name = get_option('blogname');
        $from_email = get_option('admin_email');
        $mail_to = get_option( plugin_name() . '_options' )[ plugin_name() . '_translation_mail_to' ];

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = "From: {$from_name} <{$from_email}>";

        $message = "<p>Foram criadas traduções para novas ofertas de trabalho importadas</p>";
        $message .= "<p>Para revisar/publicar essas traduções, utilize esse <a href='" . get_site_url() . "/wp-admin/edit.php?post_status=publish&post_type=jobs'>link</a></p>";
        $message .= "<p>Traduções criadas:</p>";
        $message .= "<ul>";

        foreach ($translations as $translation) {
            $message .= "<li>{$translation['id']} - {$translation['title']} </li>";
        }

        $message .= "</ul>";

        
        $mail_status = wp_mail( $mail_to, 'Traduções criadas', $message, $headers );
        if ($mail_status){
            log_info('Mail sent successfully');
        } else {
            log_info('Mail could not be sent');
        }
    }

    // ####### Jobs - End ####### //

    // ####### Candidates - Begin ####### //

    /**
     * Sync candidates from Jobconvo with WordPress.
     */
    private function sync_candidates()
    {

        $users = get_users();

        foreach($users as $user) {
            $this->create_or_update_candidate($user);
        }

        /*
        Old method commented to avoid deletion, but old method was doing it wrong. It was updating from JobConvo to Wordpress, 
        the correct is from Wordpress to JobConvo. Old method was also incomplete
        */

        // $page = 1;

        // $candidates = [];
        // // Itarate over all pages to get all candidates and store them in an array
        // do {
        //     if (isset($response['results'])) {
        //         log_info('Found ' . count($response['results']) . ' candidates on page ' . ($page - 1));
        //         $candidates = array_merge($candidates, $response['results']);
        //     }

        //     log_info('Getting candidates page: ' . $page);

        //     $response = $this->CANDIDATE->Get('page=' . $page);
        //     $page++;
        //     if ($page > 2) {
        //         break;
        //     }
        // } while (isset($response['results']) && count($response['results']) > 0);

        // if (!$candidates) {
        //     log_info('No candidates found');

        //     return;
        // }

        // log_info('Candidates found: ' . count($candidates));

        // foreach ($candidates as $candidate) {
        //     $pk = $candidate['pk'];
        //     log_info('Checking candidate with PK: ' . $pk);

        //     $user = get_user_by('email', $candidate['profile']['email']);
        //     // $userMeta = get_user_meta($user->ID);

        //     // Check if candidate exists in WordPress
        //     if ($user) { // && strtotime($candidate['last_update']) < strtotime($results->meta_value)
        //         log_info('Candidate found on WP, updating:');
        //         $this->update_wp_user($user, $candidate);
        //     } else {
        //         log_info('Candidate not found on WP, creating...');
        //         $this->create_wp_user($candidate);
        //     }
        // }

        // log_info('Candidate sync finished.');
    }

    /**
     * Create or update a candidate on Jobconvo.
     * @param  Candidate $candidate WordPress user object.
     */
    public function create_or_update_candidate($user)
    {
        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('User Create/Update routine started');
        log_info('*------------------------------------------------------------------------------------------------------*');
        $result = null;

        // check if candidate exists
        $candidate = $this->CANDIDATE->GetByEmail($user->user_email);

        if ($candidate && isset($candidate['results']) && count($candidate['results']) > 0) {
            
            if (!empty(get_user_meta($user->ID, 'user_cv_file'))) {
                $cv = get_post( get_user_meta($user->ID, 'user_cv_file')[0] );
    
                $this->send_cv_to_jobconvo($cv, $user->ID);
            }

            $data = $this->compose_cadidate_data($user);

            $pk = $candidate['results'][0]['pk'] ?? null;
            log_info("User - {$user->user_email} and pk - {$pk} found on JobConvo, updating...");

            // Check Educations
            log_info('Checking User Educations');
            $result = $this->CANDIDATE->create_or_update_educations($pk, $user->ID, $data['educations']);

            log_info('Educations Now:');
            log_info(print_r( $this->compose_educations($user), true ));

            // Check Work Experiences
            log_info('Checking User Work Experiences');
            $result = $this->CANDIDATE->create_or_update_work_experiences($pk, $user->ID, $data['work_experiences']);

            log_info('Work Experiences Now:');
            log_info(print_r( $this->compose_work_experiences($user), true ));

            // Check Languages
            log_info('Checking User Languages');
            $result = $this->CANDIDATE->create_or_update_languages($pk, $user->ID, $data['languages']);

            log_info('Languages Now:');
            log_info(print_r( $this->compose_languages($user), true ));

            // Check Websites
            log_info('Checking User Websites');
            $result = $this->CANDIDATE->create_or_update_websites($pk, $user->ID, $data['websites']);

            log_info('Websites Now:');
            log_info(print_r( $this->compose_websites($user), true ));

            // Unset data already sent
            unset($data['websites']);
            unset($data['languages']);
            unset($data['educations']);
            unset($data['work_experiences']);

            // Check Job Applications
            $applications = get_user_meta($user->ID, 'user_application');

            foreach ($applications as $application) {
                if (!isset($application['pk'])) {
                    
                    $application_pk = $this->apply_for_job($user, get_post($application['job']));
                    $meta_id = get_user_meta_id('user_application',serialize($application));

                    if ($application_pk && $meta_id) {

                        $application['pk'] = $application_pk;
                        update_metadata_by_mid('user',$meta_id,$application);
                    }
                }
            }

            // Update candidate
            $result = $this->CANDIDATE->Update([
                'profile' => $data['profile'],
                'documents' => $data['documents'],
                // 'career_objectives' => $data['career_objectives']
            ], $pk);
            
            if($result){
                // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
                $_POST['user_id'] = $user->ID;

                update_user_meta($user->ID, 'pk', $pk);

                // Update User Documents
                $_result = $this->CANDIDATE->UpdateUserProfile($data['userProfile'], $pk);

                if($_result) {
                    log_info('Candidate updated.');
                } else {
                    log_info("Candidate updated partially, data not updated:");
                    log_info(print_r($_result,true));
                }
            } else {
                log_info('Candidate not updated.');
            }

        } else {
            log_info("User - {$user->user_email} not found on JobConvo, creating new one...");
            // Create user

            $names = explode(" ", $user->user_login, 2);

            if( get_user_meta($user->ID, 'first_name')[0] ) {
                $first_name = get_user_meta($user->ID, 'first_name')[0];
            } else { 
                $first_name = $names[0];
            }
    
            if( get_user_meta($user->ID, 'last_name')[0] ) {
                $last_name = get_user_meta($user->ID, 'last_name')[0];
            } else {
                $last_name = $names[1] ?? $names[0];
            }

            $data = [
                'profile' => [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $user->user_email
                ]
            ];

            $result = $this->CANDIDATE->Create($data);
            // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
            $_POST['user_id'] = $user->ID;

            if( isset($result['pk']) ) {
                update_user_meta($user->ID, 'pk', $result['pk']);
                log_info('Candidate created.');
            } else {
                as_log_error('Candidate not created.');
            }
        }

        log_info(print_r($result, true));

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('User Create/Update routine finished');
        log_info('*------------------------------------------------------------------------------------------------------*');
        return $result;
    }

    public function update_candidate_by_cv($user, $cv_text) {

        global $wp;

        $pk = get_user_meta($user->ID, 'pk');

        log_info('Sending CV Text to JobConvo for parsing');
    
        $response = $this->CANDIDATE->processCV($cv_text);

        if (!$response || $response['statusCode'] != 200 || !$response['body']) {
            wp_redirect( get_site_url() . "/" . $wp->request . "/?failed=unknown_parse" );
            exit;
        } else { 
            $parsedCV = $response['body'];

            // Removing empty fields from API parse response
            foreach ($parsedCV as $key => $field) {
                if (is_array($field)) {
                    foreach ($field as $_key => $_field) {
                        if (empty($_field)) {
                            unset( $field[$_key] );
                        }
                    }
                }

                if (empty($field)) {
                    unset($parsedCV[$key]);
                }

            }

        }

        if($parsedCV || !empty($parsedCV)) {
            log_info('CV Parsed succesfully!');
        } else {
            as_log_error('Failed to parse CV, aborting...');
            log_info('*------------------------------------------------------------------------------------------------------*');
            log_info('User Update By CV routine - END');
            log_info('*------------------------------------------------------------------------------------------------------*');

            wp_redirect( get_site_url() . "/" . $wp->request . "/?failed=unknown_parse" );
            exit;
        }

        $data = [];

        $data['profile'] = [
            'phone1' => array_key_exists('Contact_Phone', $parsedCV['Personal']) ? $parsedCV['Personal']['Contact_Phone'] : 00000000000,
            'phone2' => '00000000000',
        ];

        // These methods are commented because additional development will be made to be able to process a CV

        // Get Educations from CV
        $data['educations'] = [];
        $fields = [
            "level",
            "title",
            "school",
            "status",
            "start",
            "end"
        ];
        if (isset($parsedCV['Education'])) {
            foreach( $parsedCV['Education'] as $education ) {
                try {
                    $dummy = [];
                    if (isset($education['Education_RangeDate'])) {
                        $dates = explode( '-', $education['Education_RangeDate'] );
                        $start_date = date( 'Y-m-d', strtotime($dates[0]) );
                        $dummy['start'] = $start_date;
                        if( isset($dates[1]) ) {
                            $end_date = date( 'Y-m-d', strtotime($dates[1]) );
                            $dummy['end'] = $end_date;
                        }
                    }
        
                    if (isset($education['Education_Level'])) {
                        $level = $education['Education_Level'];
                        $dummy['level'] = $level;
                    }

                    if (isset($education['Education_Institute'])) {
                        $institute = $education['Education_Institute'];
                        $dummy['school'] = $institute;
                    }

                    if (isset($education['Education_Title'])) {
                        $title = $education['Education_Title'];
                        $dummy['title'] = $title;
                    }
                    
                    if( isset($dates[1]) ) {
                        $status = '1';
                        $dummy['status'] = $status;
                    } else {
                        $status = '2';
                        $dummy['status'] = $status;
                    }                
        
                    foreach ($fields as $field) {
                        if (!isset($dummy[$field])) {
                            $dummy[$field] = false;
                        }
                    }

                    $data['educations'][] = $dummy;
                } catch (\Exception $exception) {
                    dump($exception);
                    die;
                }
            }
        }

        // Get Work Experiences from CV
        $data['work_experiences'] = [];
        $fields = [
            "detail",
            "jobtitle",
            "employer",
            "start",
            "end"
        ];
        if (isset($parsedCV['Jobs'])) {
            foreach( $parsedCV['Jobs'] as $job ) {
                try {
                    $dummy = [];
                    
                    if (isset($job['Job_RangeDate'])) {
                        $dates = explode( '-', $job['Job_RangeDate'] );
                        $start_date = date( 'Y-m-d', strtotime($dates[0]));
                        $dummy['start'] = $start_date;
                        if( isset($dates[1]) ) {
                            $end_date = date( 'Y-m-d', strtotime($dates[1]));
                            $dummy['current'] = 2;
                            $dummy['end'] = $end_date;
                        } else {
                            $dummy['current'] = 1;
                        }

                    }

                    if (isset($job['Job_Description'])) {
                        $detail = $job['Job_Description'];
                        $dummy['detail'] = $detail;
                    }

                    if (isset($job['Job_Employer'])) {
                        $employer = $job['Job_Employer'];
                        $dummy['employer'] = $employer;
                    }

                    foreach ($fields as $field) {
                        if (!isset($dummy[$field])) {
                            $dummy[$field] = false;
                        }
                    }
        
                    $data['work_experiences'][] = $dummy;
                } catch (\Exception $exception) {
                    dump($exception);
                    die;
                }
            }
        }

        // Get Languages from CV
        $data['languages'] = [];
        $fields = [
            "idiom",
            "writing",
            "speaking",
            "reading"
        ];
        if (isset($parsedCV['Languages'])) {
            foreach( $parsedCV['Languages'] as $language ) {
                try {
                    $dummy = [];

                    if (isset($language['Language_Name'])) {
                        $idiom = $language['Language_Name'];
                        $dummy['idiom'] = $idiom;
                    }

                    if (isset($language['Language_Level'])) {
                        $level = $language['Language_Level'];
                        $dummy['writing'] = $level;
                        $dummy['speaking'] = $level;
                        $dummy['reading'] = $level;
                    }

                    $data['languages'][] = $dummy;
                } catch (\Exception $exception) {
                    dump($exception);
                    die;
                }
            }
        }

        log_info('Parsed CV data:');
        log_info(print_r($data,true));

        $response = $this->CANDIDATE->UpdateByCV($data, $pk);

        // Checking user profile

        global $wp;

        $validation = [
            'has_errors' => false
        ];

        foreach ( $response as $key => $data ) {
            if ($key != 'profile') {
                
                foreach ($data as $_data) {

                    if ($_data && $_data['response']['code'] > 201) {

                        $errors = [];
                        $validation['has_errors'] = true;

                        foreach ($_data['response']['body'] as $_key => $error) {
                            $errors[$_key] = $error[0];
                        }

                        $validation[$key][] = [
                            'data' => $_data['data'],
                            'errors' => $errors
                        ];

                    }

                }

            } else {
                $validation[$key] = $data;
            }
        }

        if($validation['has_errors']) {
            $meta_id = update_user_meta(
                wp_get_current_user()->ID,
                'profile_validation',
                $validation
            );

            if (isset($_POST['_wpnonce'])) {
                unset($_POST['_wpnonce']);
            }
            
            update_user_meta(
                wp_get_current_user()->ID,
                'form_fields',
                $_POST
            );

            wp_redirect( home_url( $wp->request ) . "?cv_validation={$meta_id}" );
            exit;
        } else {
            wp_redirect( home_url( $wp->request ) . "?updated=true" );
            exit;
        }

        
        if( isset($response['profile']) ){
            if( !$response['profile'] ) {
                as_log_error('User profile update from CV failed');
                wp_redirect( home_url( $wp->request ) . "?failed=cv_fail" );
                exit;
            }
        }

        // These methods are commented because additional development will be made to be able to process a CV

        // if( isset($response['educations']) ) {
        //     foreach( $response['educations'] as $edu ) {
        //         if( !$edu ) {
        //             as_log_error('User Educations update from CV failed');
        //             wp_redirect( home_url( $wp->request ) . "?failed=cv_fail" );
        //             exit;
        //         }
        //     }
        // }

        // if( isset($response['languages']) ) {
        //     foreach( $response['languages'] as $lan ) {
        //         if( !$lan ) {
        //             as_log_error('User Languages update from CV failed');
        //             wp_redirect( home_url( $wp->request ) . "?failed=cv_fail" );
        //             exit;
        //         }
        //     }
        // }

        // if( isset($response['work_experiences']) ) {
        //     foreach( $response['work_experiences'] as $wrk ) {
        //         if( !$wrk ) {
        //             as_log_error('User Work Experiences update from CV failed');
        //             wp_redirect( home_url( $wp->request ) . "?failed=cv_fail" );
        //             exit;
        //         }
        //     }
        // }

        // Stopped here because JobConvo is not processing the requests correctly,
        // making it impossible to continue as from now on, we depend on the response
        // from the requests to update the user data on wordpress

        log_info('*------------------------------------------------------------------------------------------------------*');
        log_info('User Update By CV routine - END');
        log_info('*------------------------------------------------------------------------------------------------------*');

    }

    public function delete_education($number){        
        $user = wp_get_current_user();
        // $pk = $this->CANDIDATE->GetByEmail($user->user_email)['results'][0]['pk'];
        $pk = get_user_meta($user->ID, 'pk')[0];

        $educations = $this->compose_educations($user);
        if(isset($educations[ intval($number) - 1 ])){
            $education = $educations[ intval($number) - 1 ];
            $this->CANDIDATE->delete_education($pk, $education);
        }
    }

    public function delete_work_experience($number){        
        $user = wp_get_current_user();
        // $pk = $this->CANDIDATE->GetByEmail($user->user_email)['results'][0]['pk'];
        $pk = get_user_meta($user->ID, 'pk')[0];

        $work_experiences = $this->compose_work_experiences($user);
        if(isset($work_experiences[ intval($number) - 1 ])){
            $work_experience = $work_experiences[ intval($number) - 1 ];
            $this->CANDIDATE->delete_work_experience($pk, $work_experience);
        }
    }

    public function delete_language($number){        
        $user = wp_get_current_user();
        // $pk = $this->CANDIDATE->GetByEmail($user->user_email)['results'][0]['pk'];
        $pk = get_user_meta($user->ID, 'pk')[0];

        $languages = $this->compose_languages($user);
        if(isset($languages[ intval($number) - 1 ])){
            $language = $languages[ intval($number) - 1 ];
            $this->CANDIDATE->delete_language($pk, $language);
        }
    }

    public function delete_website($number){        
        $user = wp_get_current_user();
        // $pk = $this->CANDIDATE->GetByEmail($user->user_email)['results'][0]['pk'];
        $pk = get_user_meta($user->ID, 'pk')[0];

        $websites = $this->compose_websites($user);
        log_info("Websites now:");
        log_info(print_r($websites,true));
        if(isset($websites[ intval($number) - 1 ])){
            $website = $websites[ intval($number) - 1 ];
            $this->CANDIDATE->delete_website($pk, $website);
        }
    }

    /**
     * Create a new WP user using the data from JobConvo API
     */
    private function create_wp_user($data)
    {
        $user = array(
            'user_login' => $data['profile']['email'],
            'user_pass' => wp_generate_password(),
            'user_email' => $data['profile']['email'],
            'first_name' => $data['profile']['first_name'],
            'last_name' => $data['profile']['last_name'],
            'display_name' => explode('@', $data['profile']['email'])[0],
            'role' => 'subscriber'
        );

        // Insert a new user into WP
        $user_id = wp_insert_user($user);

        add_user_meta($user_id, 'phone_number', $data['profile']['phone1']);
        add_user_meta($user_id, 'location', $data['profile']['address']);
        add_user_meta($user_id, 'user_vat_nr', $data['documents']['cpf']);

        log_info('User created with ID: ' . $user_id);
    }

    /**
     * Update a WP user using the data from JobConvo API
     */
    private function update_wp_user($user, $data)
    {
        $user_data = wp_update_user(array(
            'ID' => $user->ID,
            'first_name' => $data['profile']['first_name'],
            'last_name' => $data['profile']['last_name']
        ));

        // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
        $_POST['user_id'] = $user->ID;

        update_user_meta($user->ID, 'phone_number', $data['profile']['phone1']);
        update_user_meta($user->ID, 'location', $data['profile']['address']);
        update_user_meta($user->ID, 'user_vat_nr', $data['documents']['cpf']);

        if (is_wp_error($user_data)) {
            // There was an error; possibly this user doesn't exist.
            log_info('Error trying to update user data.');
        } else {
            // Success!
            log_info('User profile updated on DB.');
        }
    }

/**
 * It takes a WordPress user object and returns an array with the data needed to create a candidate in
 * the API
 * 
 * @param user the user object
 * 
 * @return an array with the user's data.
 */
    private function compose_cadidate_data($user)
    {
        $userMeta = get_user_meta($user->ID);
        $names = explode(" ", $user->user_login, 2);
        $educations = $this->compose_educations($user);
        $work_experiences = $this->compose_work_experiences($user);
        $languages = $this->compose_languages($user);
        $websites = $this->compose_websites($user);

        if( get_user_meta($user->ID, 'first_name')[0] ) {
            $first_name = get_user_meta($user->ID, 'first_name')[0];
        } else { 
            $first_name = $names[0];
        }

        if( get_user_meta($user->ID, 'last_name')[0] ) {
            $last_name = get_user_meta($user->ID, 'last_name')[0];
        } else {
            $last_name = $names[1] ?? $names[0];
        }

        if( isset($userMeta['social_status'][0]) ) {
            switch ($userMeta['social_status'][0]) {
                case 'solteiro':
                    $social_status = '1';
                    break;
                
                case 'casado':
                    $social_status = '2';
                    break;
                
                case 'divorciado':
                    $social_status = '3';
                    break;

                    default:
                    $social_status = '1';
                    break;
            }
        } else {
            // By default, consider new users "Solteiro"
            $social_status = '1';
        }


        /*
        Portuguese Address mapping in JobConvo:
            neighbourhood = Morada
            address = nome da rua, número da rua
            addressnumber = número da casa
            cep = código postal
            complement = piso-porta
        */

        $street_name = isset($userMeta['street_name'][0]) ? $userMeta['street_name'][0] : '';
        // $street_number = isset($userMeta['street_number'][0]) ? $userMeta['street_number'][0] : '';
        // $address = "{$street_name}-{$street_number}";

        $house_floor = isset($userMeta['house_floor'][0]) ? $userMeta['house_floor'][0] : '';
        $house_door = isset($userMeta['house_door'][0]) ? $userMeta['house_door'][0] : '';
        $complement = "{$house_floor}-{$house_door}";

        return [
            'userProfile' => [
                'first_name' => preg_replace("/[^a-zA-Z]/", "", $first_name),
                'last_name' => preg_replace("/[^a-zA-Z]/", "", $last_name),
                'full_name' =>  $user->user_login,
                'country_code' => $userMeta['country_code'][0] ?? '351',
                'area_code' => '0',
                'phone1' => $userMeta['phone_number'][0] ?? '00000000000',
                'phone2' => '00000000000',
                'social_status' => $social_status,
                'born_sex' => $userMeta['gender'][0] ?? '4',
            ],
            'profile' => [
                'email' => $user->user_email,
                'birthday' => $userMeta['birthday'][0] ?? null,

                // Social Status
                // 1 - Solteiro
                // 2 - Casado
                // 3 - Outro

                // 'rais' => null,
                // 'photo' => null,
                // 'public_place_type' => null,
                'address' => $street_name,
                'adddressnumber' => $userMeta['house_number'][0] ?? null,
                'complement' => $complement,
                'neighbourhood' => $userMeta['neighbourhood'][0] ?? null,
                // 'state' => null,
                'city' => $userMeta['city'][0] ?? null,
                'country' => $userMeta['country'][0] ?? "Portugal",
                // 'latitude' => null,
                // 'longitude' => null,
                'cep' => $userMeta['zip_code'][0] ?? null,
                // 'user_language' => "pt-pt",
                // 'pesquisa_vagas' => false,
                // 'last_changed_email' => null,
                // 'telegram_confirmation' => null,
                // 'telegram_confirmed' => null,
                // 'telegram_chat_id' => null,
                // 'telegram_refused' => false,
                // 'complete_cv' => false
            ],
            'documents' => [

                // CPF = Contribuinte
                // RG = Número de identificação (NIF)
                // PIS = NISS

                // 'cpf' => $userMeta['user_vat_nr'][0] ?? '',
                'doctype' => isset( $userMeta['doctype'] ) ? $userMeta['doctype'] : null,
                'rg' => $userMeta['user_vat_nr'][0] ?? null,
                // 'pis' => null,
                'not_brazilian' => true
            ],
            // 'career_objectives' => [
            //     'career_objective' => $userMeta['professional_objective'][0] ?? '',
            //     'range_of_experience' => ''
            // ],
            'work_experiences' => $work_experiences,
            'educations' => $educations,
            'languages' => $languages,
            'websites' => $websites,
        ];
    }

    private function compose_websites($user) {

        $websites = [];
        $userMeta = get_user_meta($user->ID);

        // Work_experiences Terms to look for on the User Meta
        $website_terms = array(
            '_website',
            '_weburl',
            '_websiteID'
        );

        $dummy = [];
        foreach($userMeta as $key => $value){
            
            foreach($website_terms as $term){

                if( strpos($key, $term) !== false && explode( '_', $key )[0] == 'field' ){
                    $dummy[$key] = $value;
                }

            }
        }

        foreach($dummy as $key => $value){
            $num = (explode('_',$key)[1]) - 1;
            $pos = explode('_',$key)[2];

            $pos = $pos == 'weburl' ? 'link' : $pos;
            $pos = $pos == 'websiteID' ? 'id' : $pos;

            // If URL does not include the scheme, prepend it, as it is required in JobConvo
            if($pos == 'link'){
                if( substr( $value[0], 0, 8) != 'https://' && substr( $value[0], 0, 7) != 'http://'){
                    $value[0] = 'https://' . $value[0];
                }
            }

            $websites[$num][$pos] = $value[0];
        }

        return($websites);
    }

    private function compose_languages($user) {

        $languages = [];
        $userMeta = get_user_meta($user->ID);

        // Work_experiences Terms to look for on the User Meta
        $language_terms = array(
            '_language',
            '_writing',
            '_speaking',
            '_reading',
            '_languageID'
        );

        $dummy = [];
        foreach($userMeta as $key => $value){
            
            foreach($language_terms as $term){

                if( strpos($key, $term) !== false && explode( '_', $key )[0] == 'field' ){
                    $dummy[$key] = $value;
                }

            }
        }

        foreach($dummy as $key => $value){
            $num = (explode('_',$key)[1]) - 1;
            $pos = explode('_',$key)[2];

            $pos = $pos == 'language' ? 'idiom' : $pos;
            $pos = $pos == 'languageID' ? 'id' : $pos;

            $languages[$num][$pos] = $value[0];
        }

        return($languages);
    }

    private function compose_work_experiences($user) {

        $work_experiences = [];
        $userMeta = get_user_meta($user->ID);

        // Work_experiences Terms to look for on the User Meta
        $work_experience_terms = array(
            '_workID',
            '_jobrole',
            '_jobcompany',
            '_jobdescription',
            '_jobstart',
            '_jobend',
        );

        $dummy = [];
        foreach($userMeta as $key => $value){
            
            foreach($work_experience_terms as $term){

                if( strpos($key, $term) !== false && explode( '_', $key )[0] == 'field' ){
                    $dummy[$key] = $value;
                }

            }
        }

        foreach($dummy as $key => $value){
            $num = (explode('_',$key)[1]) - 1;
            $pos = explode('_',$key)[2];

            // Ignore the job end attribute if empty
            /*  
                If we send this field to Jobconvo with empty value
                it won't work. In the case of no end date, it expects
                to not receive the end field, instead of receiving it
                with an empty value
            */ 
            if ($pos == 'jobend' && !$value[0]){
                continue;
            }

            $pos = $pos == 'workID' ? 'id' : $pos;
            $pos = $pos == 'jobrole' ? 'jobtitle' : $pos;
            $pos = $pos == 'jobdescription' ? 'detail' : $pos;
            $pos = $pos == 'jobcompany' ? 'employer' : $pos;
            $pos = $pos == 'jobstart' ? 'start' : $pos;
            $pos = $pos == 'jobend' ? 'end' : $pos;
            $work_experiences[$num][$pos] = $value[0];
        }

        return($work_experiences);
    }

    private function compose_educations($user) {

        $educations = [];
        $userMeta = get_user_meta($user->ID);

        // Educations Terms to look for on the User Meta
        $education_terms = array(
            '_educationID',
            '_academiclevel',
            '_title',
            '_school',
            '_area',
            '_status',
            '_start',
            '_end'
        );

        $dummy = [];
        foreach($userMeta as $key => $value){
            
            foreach($education_terms as $term){

                if( strpos($key, $term) !== false && explode( '_', $key )[0] == 'field' ){
                    $dummy[$key] = $value;
                }

            }
        }

        foreach($dummy as $key => $value){
            $num = (explode('_',$key)[1]) - 1;
            $pos = explode('_',$key)[2];

            $pos = $pos == 'educationID' ? 'id' : $pos;
            $pos = $pos == 'academiclevel' ? 'level' : $pos;

            // Ignores the end date if empty
            if($pos == 'end' && $value[0] == '') {
                continue;
            }

            $educations[$num][$pos] = $value[0];
        }

        return($educations);
    }

    // ####### Candidates - End ####### //


    // ####### Candidacies - Begin ####### //

    /**
     * The function applies for a job by registering a new candidacy for a user and returns the
     * candidacy ID if successful.
     * 
     * @param Wc_User $user The user object contains information about the user who is applying for the job,
     * such as their ID and email address.
     * @param WP_Post $job The  parameter is an object that represents a job post. It is used to retrieve
     * the job's metadata, such as the job reference number, which is then used to compose the data for
     * the candidacy application.
     * 
     * @return either the primary key of the newly created candidacy if it was registered successfully,
     * or false if it was not registered successfully.
     */
    public function apply_for_job($user, $job){

        $job_meta = get_post_meta($job->ID);

        log_info("Registering new Candidacy for user - {$user->ID} on Job - {$job_meta['pk'][0]}");

        $data = self::compose_applicance_data($user->data->user_email, $job_meta['job_ref_number'][0]);

        $result = $this->CANDIDACY->Create($data);
        
        if (isset($result['pk'])) {
            log_info('Candidacy Registered Successfully');
            return $result['pk'];
        } else {
            log_info('Candidacy Registered Successfully');
            return false;
        }
    }

    public function delete_job_application($pk){
        $result = $this->CANDIDACY->Delete($pk);
    }

    private function compose_applicance_data($email, $ref_number)
    {
        return [
            'email' => $email,
            'ref_number' => $ref_number
        ];
    }
}
