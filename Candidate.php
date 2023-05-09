<?php

class Candidate
{
    private $client;
    private $EDUCATION;
    private $WORK_EXPERIENCE;
    private $LANGUAGE;
    private $WEBSITE;

    function __construct()
    {
        require_once(plugin_dir_path(__FILE__) . 'Educations.php');
        require_once(plugin_dir_path(__FILE__) . 'Work_experiences.php');
        require_once(plugin_dir_path(__FILE__) . 'Languages.php');
        require_once(plugin_dir_path(__FILE__) . 'Websites.php');

        $this->EDUCATION = new Education();
        $this->WORK_EXPERIENCE = new WorkExperience();
        $this->LANGUAGE = new Language();
        $this->WEBSITE = new Website();
        $this->client = new JobconvoApiClient();
    }

    public function send_cv_to_jobconvo($cv, $user_id = null) {

        log_info("Sending CV file to JobConvo");

        if (is_null($user_id)) {
            $user = wp_get_current_user();
            $user_id = $user->ID;
        }

        $pk = get_user_meta($user_id, 'pk')[0];

        $response = $this->client->updateCV(
            'pt/api/companycandidate/' . $pk, 
            get_attached_file($cv->ID)
        );

        log_info("Response: ");
        log_info(print_r($response,true));

    }

    public function processCV($cv_text) {
        $response = $this->client->Get('api/combo/resumeparse/', [
            'text' => $cv_text,
        ]);
        if($response) {
            log_info('Parsed CV acquired');
            return $response;
        } else {
            as_log_error('Failed to get parsed CV, aborting...');
            return false;
        }
    }

    public function updateByCV($data, $pk) {

        $result = [];

        log_info('Updating User Profile');
        $response = $this->Update($data['profile'], $pk[0]);

        $result['profile'] = $response;
        
        if (isset($data['educations'])) {
            log_info('Updating User Educations');
            try {
                foreach($data['educations'] as $education) {
                    $response = $this->EDUCATION->Create($pk[0], $education);
        
                    if($response) {
                        $result['educations'][] = [
                            'data' => $education,
                            'response' => $response
                        ];
                    } else {
                        $result['educations'][] = false;
                    }
        
                }
            } catch (\Throwable $th) {
                as_log_error(print_r($th,true));
            }
        }

        if (isset($data['work_experiences'])) {
            log_info('Updating User Work Experiences');
            try {
                foreach($data['work_experiences'] as $job) {
                    $response = $this->WORK_EXPERIENCE->Create($pk[0], $job);
        
                    if($response) {
                        $result['work_experiences'][] = [
                            'data' => $job,
                            'response' => $response
                        ];
                    } else {
                        $result['work_experiences'][] = false;
                    }
                }
            } catch (\Throwable $th) {
                as_log_error(print_r($th,true));
            }
        }

        if (isset($data['languages'])) {
            log_info('Updating User Languages');
            try {
                foreach($data['languages'] as $language) {
                    $response = $this->LANGUAGE->Create($pk[0], $language);
        
                    if($response) {
                        $result['languages'][] = [
                            'data' => $language,
                            'response' => $response
                        ];
                    } else {
                        $result['languages'][] = false;
                    }
                }
            } catch (\Throwable $th) {
                as_log_error(print_r($th,true));
            }
        }

        return $result;

    }

    public function create_or_update_websites($pk, $user_id, $websites) {

        $result = [];
        log_info('Websites found on Wordpress:');
        log_info(print_r($websites, true));
        
        foreach($websites as $key => $website) {
            if ( isset($website['id']) && $website['id'] ){
                log_info("Website {$website['id']} found on JobConvo, Updating...");
                $response = $this->WEBSITE->Update($pk, $website);
                $result[] = $response;
                log_info('Response:');
                log_info(print_r($response, true));
            } else {
                log_info("Website not found on JobConvo, Creating...");
                $response = $this->WEBSITE->Create($pk, $website);
                $result[] = $response;
                $key = intval($key) + 1;
                $meta_key = "field_{$key}_websiteID";

                if( isset($response['id']) ) {
                    // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
                    $_POST['user_id'] = $user_id;

                    $a = update_user_meta($user_id, $meta_key, $response['id']);
    
                    log_info('Website created:');
                    log_info(print_r($response,true));
                } else {
                    as_log_error('Website not created:');
                    as_log_error(print_r( $response,true ));
                }

            }
        }
        return $result;
    }

    public function create_or_update_languages($pk, $user_id, $languages) {

        $result = [];
        log_info('Languages found on Wordpress:');
        log_info(print_r($languages, true));

        // dump($languages);

        $userMeta = get_user_meta($user_id);
        
        foreach($languages as $key => $language) {
            if ( isset($language['id']) ){
                log_info("Language {$language['id']} found on JobConvo, Updating...");

                // Remove the idiom attribute if it has not changed, as it would generate a
                // "This language already exists!" error on JobConvo
                $JobConvolanguage = $this->LANGUAGE->Get($userMeta['pk'][0], $language['id']);
                if( $JobConvolanguage && $JobConvolanguage['idiom'] == $language['idiom'] ) {
                    unset($language['idiom']);
                }

                $response = $this->LANGUAGE->Update($pk, $language);
                $result[] = $response;
                log_info('Response:');
                log_info(print_r($response, true));
            } else {
                log_info("Language not found on JobConvo, Creating...");
                $response = $this->LANGUAGE->Create($pk, $language);
                $result[] = $response;
                $key = intval($key) + 1;
                $meta_key = "field_{$key}_languageID";


                if( isset($response['id']) ) {
                    // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
                    $_POST['user_id'] = $user_id;
    
                    $a = update_user_meta($user_id, $meta_key, $response['id']);
    
                    log_info('Language created:');
                    log_info(print_r($response,true));
                } else {
                    as_log_error('Language not created:');
                    as_log_error(print_r( $response,true ));
                }

            }
        }
        return $result;
    }

    public function create_or_update_educations($pk, $user_id, $educations) {

        $result = [];
        log_info('Educations found on Wordpress:');
        log_info(print_r($educations, true));
        
        foreach($educations as $key => $education) {
            if ( isset($education['id']) ){
                log_info("Education {$education['id']} found on JobConvo, Updating...");
                $response = $this->EDUCATION->Update($pk, $education);
                $result[] = $response;
                log_info('Response:');
                log_info(print_r($response, true));
            } else {
                log_info("Education not found on JobConvo, Creating...");
                $response = $this->EDUCATION->Create($pk, $education);
                $result[] = $response;
                $key = intval($key) + 1;
                $meta_key = "field_{$key}_educationID";

                if( isset($response['id']) ) {
                    // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
                    $_POST['user_id'] = $user_id;
    
                    $a = update_user_meta($user_id, $meta_key, $response['id']);
    
                    log_info('Education created:');
                    log_info(print_r($response,true));
                } else {
                    as_log_error('Education not created:');
                    as_log_error(print_r( $response,true ));
                }
                
            }
        }
        return $result;
    }

    public function create_or_update_work_experiences($pk, $user_id, $work_experiences) {

        $result = [];
        log_info('Work Experiences found on Wordpress:');
        log_info(print_r($work_experiences, true));
        
        foreach($work_experiences as $key => $work) {
            if ( isset($work['id']) ){
                log_info("Work Experience {$work['id']} found on JobConvo, Updating...");
                $response = $this->WORK_EXPERIENCE->Update($pk, $work);
                $result[] = $response;
                log_info('Response:');
                log_info(print_r($response, true));
            } else {
                log_info("Work Experience not found on JobConvo, Creating...");
                $response = $this->WORK_EXPERIENCE->Create($pk, $work);
                $result[] = $response;

                $key = intval($key) + 1;
                $meta_key = "field_{$key}_workID";

                if( isset($response['id']) ) {
                    // Setting user id on $_POST because sitepress-multilingual-cms requires it on User update hook
                    $_POST['user_id'] = $user_id;
                    
                    $a = update_user_meta($user_id, $meta_key, $response['id']);
    
                    log_info('Work Experience created:');
                    log_info(print_r($response,true));
                } else {
                    as_log_error('Work Experience not created:');
                    as_log_error(print_r( $response,true ));
                }
            }
        }
        return $result;
    }

    public function delete_education($pk, $education){
        $result = $this->EDUCATION->Delete($pk, $education);
        log_info("Deleting Education {$education['id']}");
        log_info(print_r($result, true));
    }

    public function delete_work_experience($pk, $work_experience){
        $result = $this->WORK_EXPERIENCE->Delete($pk, $work_experience);
        log_info("Deleting work_experience {$work_experience['id']}");
        log_info(print_r($result, true));
    }

    public function delete_language($pk, $language){
        $result = $this->LANGUAGE->Delete($pk, $language);
        log_info("Deleting language {$language['id']}");
        log_info(print_r($result, true));
    }

    public function delete_website($pk, $website){
        $result = $this->WEBSITE->Delete($pk, $website);
        log_info("Deleting website {$website['id']}");
        log_info(print_r($result, true));
    }

    public function Create($data)
    {
        // Ignoring for now
        log_info('Candidate - Creating...');

        $result = $this->client->Send('pt-br/api/companycandidate/', $data);

        if ($result) {
            return $result;
        }

        return null;
    }

    public function UpdateUserProfile($data, $pk)
    {
        log_info("Candidate id: $pk - Updating ...");

        $response = $this->client->Update("pt-br/api/userprofile/$pk/", $data);

        if ($response) {
            return $response;
        }

        return null;
    }

    public function Update($data, $pk)
    {
        log_info("Candidate id: $pk - Updating ...");

        $result = $this->client->Update("pt-br/api/companycandidate/$pk/", $data);

        if ($result) {
            return $result;
        }

        return null;
    }

    public function Get($params = null)
    {
        return $this->client->Get('/api/candidate' . ($params ? '?' . $params : ''));
    }

    public function GetByUser($user)
    {
        $result = $this->client->Get('/api/candidate?pk=' . $user->pk);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function GetByEmail($email)
    {
        log_info("Get candidate by email - {$email}");

        $result = $this->client->Get('pt-br/api/companycandidate/?candidate=' . $email);

        if ($result) {
            return $result;
        }

        return null;
    }

    public function Delete($pk)
    {
        $result = $this->client->Delete('pt-br/api/companycandidate?pk=' . $pk[0]);

        if ($result && isset($result['detail'])) {
            return true;
        }

        return false;
    }
}
