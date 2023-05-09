<?php

class JobconvoApiClient
{
    private $url;
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $token;
    private $refreshToken;
    private $pluginName;

    function __construct()
    {
        $this->pluginName = plugin_name();
        // Load credentials stored in DB
        $this->GetCredentials();
    }

    public function UpdateCV($path, $file = null, $pk = null)
    {

        $_headers = $this->GetHeaderAuth();
        unset($_headers['Content-Type']);

        $headers = [];

        foreach ($_headers as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }


        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->url . $path . '/' . $pk,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => array('resume_file'=> new CURLFILE($file)),
            CURLOPT_HTTPHEADER => $headers
          ));

        $response = curl_exec($curl);
        
        curl_close($curl);

        return $response;

    }

    public function Get($path, $data = null)
    {
        // log_info('Getting data...');

        $response = $this->Request($this->url . $path, $data, 'GET', $this->GetHeaderAuth());

        if (isset($response['response']['code']) && ($response['response']['code'] == 200 || $response['response']['code'] == 201)) {
            // log_info(print_r($response['body'], true));

            return json_decode($response['body'], true);
        }

        return $response;
    }

    /**
     * It sends data to the API
     * 
     * @param path The path to the API endpoint you want to call.
     * @param data The data to be sent to the API.
     * 
     * @return The response from the API.
     */
    public function Send($path, $data)
    {
        // log_info('Sending data...');

        $response = $this->Request($this->url . $path, $data, 'POST', $this->GetHeaderAuth());

        if (isset($response['response']['code']) && $response['response']['code'] == 200) {
            return json_decode($response['body'], true);
        }

        return $response;
    }

    public function Update($path, $data, $content_type = 'application/json')
    {
        // log_info('Updating data...');

        $response = $this->Request($this->url . $path, $data, 'PATCH', $this->GetHeaderAuth($content_type));

        // log_info(print_r($response, true));

        if (isset($response['response']['code']) && ($response['response']['code'] == 200 || $response['response']['code'] == 201)) {
            return json_decode($response['body'], true);
        }

        return $response;
    }

    public function Delete($path)
    {
        // log_info('Delete record...');

        $response = $this->Request($this->url . $path, null, 'DELETE', $this->GetHeaderAuth());

        if (isset($response['response']['code']) && $response['response']['code'] == 200) {
            return json_decode($response['body'], true);
        }

        return $response;
    }

    public function generate_token()
    {
        log_info('Getting token...');

        $headers = array('Content-Type' => 'application/x-www-form-urlencoded');

        $body = [
            'grant_type' => 'password',
            'client_id' => $this->clientId, // 'eVAD4YRGgY6m9tQZGJvVjibKZnFNqKJwP0RQ4GJ1',
            'client_secret' => $this->clientSecret, // 'nDgP8DnltUznG6wkdDBKGfMN0bXzVRUw0fkFP1h3B40fb1bv0kGsSbfJgnMblbB0PqpEsYFr5AJGcuYJ8gNJo8me6KI2tK4gETM6qmLHQxpBzm08vKVt1fZuHByF5clk',
            'username' => $this->username, // 'development@alfasoft.pt',
            'password' => $this->password, // '5f#*4CM4gVqV6QQ'
        ];

        // $response = $this->Request($this->url . 'oauth/token/', $body, 'POST', $headers);
        $response = wp_remote_post($this->url . 'oauth/token/', [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body
            ]);
        // $response = json_decode($response,true);
        log_info('RESPONSE:');
        $response = json_decode($response['body'],true);
        log_info(print_r($response, true));

        if ($response) {
            // Save new token in DB
            $credentials = get_option("{$this->pluginName}_options");

            $credentials["{$this->pluginName}_token"] = $this->token = $response['access_token'];
            $credentials["{$this->pluginName}_refresh_token"] =  $this->refreshToken = $response['refresh_token'];

            update_option("{$this->pluginName}_options", $credentials);

            log_info('Token generated successfully.');

            return true;
        }


        log_info("Generate token error:");
        $this->logError($response);

        return false;
    }

    private function Request($url, $body = null, $method = 'GET', $headers = null)
    {
        log_info("JobconvoApiClient - Request method");
        log_info("METHOD - {$method}");
        log_info("URL - {$url}");

        $payload = array(
            'method' => $method
        );

        if ($headers) {
            $payload['headers'] = $headers;
        }

        if ($body) {
            $payload['body'] = $body;
        }

        try {

            if ($method == 'GET') {
                log_info("GET Methods enter: $method");
                
                // We use CURL directly for the parseCV request because it requires us to send a GET
                // with a JSON body (weird) and Wordpress doesn't seem to allow that
                if ( isset($payload['body']) && is_array($payload['body']) && array_key_exists('text', $payload['body']) ) {

                    $curl = curl_init();

                    // Formatting the headers the way curl expects
                    $dummy = [];
                    foreach($headers as $key => $value){
                        $dummy[] = "{$key}: {$value}";
                    }
                    $headers = $dummy;

                    curl_setopt_array($curl, array(
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 60,
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'GET',
                        CURLOPT_POSTFIELDS => wp_json_encode($body),
                        CURLOPT_HTTPHEADER => $headers,
                    ));

                    $response = curl_exec($curl);
                    $response = [
                        'response' => [
                            'body' => json_decode($response, true),
                            'code' => curl_getinfo($curl, CURLINFO_HTTP_CODE)
                            ]
                        ];
                    
                    curl_close($curl);

                    } else {
                    $response = wp_remote_get($url, $payload);
                }
            } else {
                log_info("wp_json_encode: " . wp_json_encode($body));
                $response = wp_safe_remote_post(
                    $url,
                    [
                        'timeout' => 120,
                        'headers'     => $headers,
                        'body'        => wp_json_encode($body),
                        'method'      => $method,
                        'data_format' => 'body',
                    ]
                );
            }
        } catch (\Throwable $th) {
            log_info(print_r($th->getMessage(), true));
        }

        $retries = 0;

        do {
            $retry = false;
            $retries++;

            if ($retries > 0) {
                as_log_warn('Retry request attempt ' . $retries);
            }

            if (!is_wp_error($response) && ($response['response']['code'] == 200 || $response['response']['code'] == 201)) {
                
                if( isset($response['response']['body']) ){
                    return $response['response']['body'];
                } else {
                    return json_decode($response['body'], true);
                }

            } else if (!is_wp_error($response) && $response['response']['code'] == 401) {
                as_log_error('Request unauthorized. Trying to generate new token...');
                $retry = true;
                $this->generate_token();
            } else if (!is_wp_error($response) && $response['response']['code'] == 400) {

                if( isset($response['response']['body']) ){
                    return [
                        'code' => $response['response']['code'],
                        'body' => $response['response']['body']
                    ];
                } else {
                    return [
                        'code' => $response['response']['code'],
                        'body' => json_decode($response['body'], true)
                    ];
                }

            } else if (is_wp_error($response) && strpos($response->get_error_message(), 'timed out') !== false && $retries < 3) {
                as_log_warn('Request timeout. Retrying in 5 seconds...');
                sleep(1);
                $retry = true;
            }
        } while ($retry && $retries < 2);


        log_info("Request error:");
        $this->logError($response);

        return false;
    }

    private function GetCredentials()
    {
        $credentials = get_option("{$this->pluginName}_options");

        if (!$credentials) {
            as_log_warn('No credentials found');
            return false;
        } else if ($this->url && $this->clientId && $this->clientSecret && $this->username && $this->password && $this->token) {
            as_log_warn('Credentials already loaded');
            return true;
        }

        $this->url = isset($credentials[ $this->pluginName . '_url']) ? $credentials[ $this->pluginName . '_url'] : 'https://test-homolog.jobconvo.com/';
        $this->clientId = isset($credentials[ $this->pluginName . '_client_id']) ? $credentials[ $this->pluginName . '_client_id'] : 'eVAD4YRGgY6m9tQZGJvVjibKZnFNqKJwP0RQ4GJ1';
        $this->clientSecret = isset($credentials[ $this->pluginName . '_client_secrect']) ? $credentials[ $this->pluginName . '_client_secrect'] : 'nDgP8DnltUznG6wkdDBKGfMN0bXzVRUw0fkFP1h3B40fb1bv0kGsSbfJgnMblbB0PqpEsYFr5AJGcuYJ8gNJo8me6KI2tK4gETM6qmLHQxpBzm08vKVt1fZuHByF5clk';
        $this->username = isset($credentials[ $this->pluginName . '_username']) ? $credentials[ $this->pluginName . '_username'] : 'development@alfasoft.pt';
        $this->password = isset($credentials[ $this->pluginName . '_password']) ? $credentials[ $this->pluginName . '_password'] : '5f#*4CM4gVqV6QQ';
        $this->token = isset($credentials[ $this->pluginName . '_token']) ? $credentials[ $this->pluginName . '_token'] : null;
        $this->refreshToken = isset($credentials[ $this->pluginName . '_refresh_token']) ? $credentials[ $this->pluginName . '_refresh_token'] : null;

        // check if url end with / or not and add if not exists
        if (substr($this->url, -1) != '/') {
            $this->url .= '/';
        }

        if (!$this->token) {
            // Authenticate and get token
            as_log_warn('Token not found, generating new one.');
            as_log_warn($this->token);
            $this->generate_token();
        }
    }

    private function GetHeaderAuth($content_type = 'application/json')
    {
        return [
            'Content-Type' => $content_type,
            'Authorization' => 'Bearer ' . $this->token,

        ];

        return array("Authorization: Bearer $this->token", "content-type: application/json");
    }

    function logError($response)
    {
        as_log_error("Can't acess Jobconvo API, error:");
        if (gettype($response) == 'array') {
            if (isset($response['response']['code'])) as_log_error("Code: {$response['response']['code']}");
            if (isset($response['response']['message'])) as_log_error("Message: {$response['response']['message']}");
            if (isset($response['body'])) as_log_error("Body details: " . print_r($response['body'], true));
        } else if (is_wp_error($response)) {
            as_log_error($response->get_error_code() . ' - ' . $response->get_error_message());
        } else {
            as_log_error(print_r($response, true));
        }
    }
}
