<?php

use Google\Cloud\DocumentAI\V1\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\RawDocument;

class AS_Google_DocumentAI {

    public function __construct(){
        require 'vendor/autoload.php';

        try {
            $this->client = new DocumentProcessorServiceClient([
                'apiEndpoint' => 'eu-documentai.googleapis.com',
                'credentials' => get_option( plugin_name() . '_google_documentai_service_account' )
            ]);
        } catch (\Throwable $th) {
            as_log_error("Failed to load Google DocumentAI:");
            as_log_error(print_r($th,true));
            wp_redirect( add_query_arg(['failed' => 'true'], get_permalink()) );
            exit;
        }



    }

    /**
     * It takes a file path as an argument, and returns the text extracted from the file
     * 
     * @param file The file path of the document you want to process.
     * 
     * @return The text of the document.
     */
    public function processDocument($file) {
        
        try {

            $formattedParent = $this->client->locationName( get_option('google_cloud_project_id') , get_option('google_cloud_region'));
                
            $pagedResponse = $this->client->listProcessors($formattedParent);

            $name = '';
            foreach( $pagedResponse->iterateAllElements() as $el ) {
                if(method_exists($el, 'getState')){
                    if($el->getState()){
                        $name = $el->getName();
                        break;
                    }
                }
            }
            
            $file_64 = file_get_contents( get_attached_file($file) );
            $file_mimeType = get_post_mime_type($file);

            $document = new RawDocument();
            $document->setContent( $file_64 );
            $document->setMimeType( $file_mimeType );

            $response = $this->client->processDocument($name, [
                'rawDocument' => $document,
                'skipHumanReview' => true
            ]);

            // dump($response);
        } finally {
            $this->client->close();
            return $response->getDocument()->getText();
        }

    }

}