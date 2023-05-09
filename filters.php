<?php

add_filter('alfasoft_get_related_jobs', 'alfasoft_get_related_jobs', 10, 2);

function alfasoft_get_related_jobs($translations, $application) {

    $wpml_post_type = apply_filters(  'wpml_element_type', 'jobs');

    $post_language_info = apply_filters( 'wpml_element_language_details', null, [
    'element_id' => $application['job'],
    'element_type' => $wpml_post_type
    ] );

    if( $post_language_info->language_code == 'en' ) {
        $_job = get_post($application['job']);
        $translations = get_post_meta( get_post_meta( $_job, 'original_post_id' )[0], 'translations' )[0];
    } else {
        $translations = get_post_meta( $application['job'], 'translations' )[0];
    }

    return $translations;
}

add_filter('alfasoft_get_related_applications', 'alfasoft_get_related_applications', 10, 3);

function alfasoft_get_related_applications($applications, $original_application, $user_id) {

    $applications = get_user_meta($user_id, 'user_application');
    $translations = apply_filters('alfasoft_get_related_jobs', [], $original_application);

    foreach ($applications as $application) {
        if (in_array($application['job'],$translations)) {
            $applications[] = $application;
        }
    }

    return $applications;

}