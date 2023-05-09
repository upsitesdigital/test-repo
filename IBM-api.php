<?php

class IBM_Watson_API {

    const URL = "https://api.us-south.language-translator.watson.cloud.ibm.com/instances/4b72da34-eceb-4383-b41a-f18d394e35e4/v3/translate?version=2018-05-01";
    const APIKEY = "onFrmq2gxOTAKhnVaIgmq9-LhXG6dsdzKXhJYQyKqEC8";

    public function __construct(){

    }

    /**
     * It sends a POST request to the Watson API with the text to be translated and the language codes
     * of the source and target languages
     * 
     * @param text The text to be translated.
     * @param from The language code of the source text.
     * @param to The language you want to translate to.
     * 
     * @return The response is a JSON object
     */
    public function get_translation($text, $from = 'pt', $to = 'en'){

        if(!$text){
            return '';
        }

        log_info("Accessing IBM Watson translation API");
        log_info("Translating text: {$text}");

        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( 'apikey' . ':' . self::APIKEY ),
        );

        $data = json_encode([
            'text' => $text,
            'model_id' => explode('-', $from)[0] . '-' . explode('-', $to)[0]
        ]);

        $response = wp_remote_post( self::URL, array(
            'headers' => $headers,
            'body' => $data,
        ) );

        if( $response && !is_wp_error($response) ){
            log_info("Translated text: " . json_decode($response['body'], true)['translations'][0]['translation']);
        } elseif( is_wp_error($response) ) {
            as_log_error("Translation failed: " . $response->get_error_message());
            return $text;
        }


        return json_decode($response['body'], true)['translations'][0]['translation'];

    }

}