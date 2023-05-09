<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Plugin admininstration page settings
 *
 * @author ALFASOFT
 */
class PluginAdmin {
    
    function __construct($post_type) {
        if ($post_type == $this->slug) {
            require_once( fleetman_dir() . 'utils/Taxonomies.php');
            require_once( fleetman_dir() . 'entities/Service.php');

            add_action('save_post', [$this, 'saveData'], 8, 2);
            add_action('manage_' . $this->slug . '_posts_columns', [$this, 'setupColumns'], 9, 2);
            add_action("add_meta_boxes_" . $this->slug, [$this, 'setupForm'], 10);
        }
    }
    
    private $taxonomy = 'services_category';
    private $slug = 'services';

    // show settings form
    public static function Settings() {
        include(plugin_dir() . "/Views/SettingsForm.php");
    }

    // manual importing page
    public static function Logs() {
        include(plugin_dir() . "/Views/Logs.php");
    }


    public function saveData($post_id = 0) {
        $post = get_post($post_id);
        $term_id = filter_input(INPUT_POST, $this->taxonomy);
        
        if($term_id != false && $post->post_status == 'publish'){
            Taxonomies::setCategory($post_id, (int) $term_id, $this->taxonomy);
            Service::save([
                'post_id'=>$post->ID, 'term_id'=>$term_id, 'name'=>$post->post_title
            ]);
        }

        return;
    }
    
    public static function selector($name, $label, $slugs = [], $id = null) {
        require_once( fleetman_dir() . 'utils/forms/SelectInput.php');
        require_once( fleetman_dir() . 'entities/Category.php');
        require_once( fleetman_dir() . 'entities/Service.php');
        
        $categories = [];
        foreach($slugs as $slug){
            $categories[] = Category::getBySlug($slug)->id;
        }
        $services = Service::getByCategories($categories);
        $select = SelectInput::create($name)->addEmpty($label)
                ->parseModel($services, 'id', 'name');
        if (null != $id) {
            $select->setValue($id);
        }
        return $select->parseInput();
    }
    
    
}