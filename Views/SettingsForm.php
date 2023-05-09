<?php

if (isset($_POST['manual_sync'])) {
    do_action('as_manual_sync');
    wp_safe_redirect( get_permalink() );
}

if ( isset($_POST['google_update_data']) ) {

    if( isset($_FILES['google_service_account']) && $_FILES['google_service_account']['error'] == 0 ) {
        $content = json_decode( file_get_contents($_FILES['google_service_account']['tmp_name'] ), true );
        
        if( is_array($content) ){
            update_option( plugin_name() . '_google_documentai_service_account', $content );
        }
    }

    if( isset($_POST['google_cloud_project_id']) ) {
        update_option('google_cloud_project_id', $_POST['google_cloud_project_id']);
    }

    if( isset($_POST['google_cloud_region']) ) {
        update_option('google_cloud_region', $_POST['google_cloud_region']);
    }

    wp_safe_redirect( get_permalink() );
}


?>

<div class="jobconvo-container">
    <h2 class="title">Jobconvo API Integration - Settings</h2>
    <div class="card">
        <form method="post" action="options.php">
            <table class="form-table">
                <?php
                settings_fields(plugin_name());
                do_settings_sections(plugin_name());
                ?>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    
    
    <div class="card">
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="google_update_data" value="1">
            <h2 class="title">Google Document AI Credentials</h2>
           
            <table class="form-table" role="presentation">
                <tbody>
                    
                    <tr class="knower-jobconvo-api-wordpress-plugin_row">
                        <th scope="row">
                            <label for="google_cloud_project_id">Google Cloud Project ID</label>
                        </th>
                        <td>
                            <input type="text" name="google_cloud_project_id" id="google_cloud_project_id" value="<?php echo get_option('google_cloud_project_id') ?>">
                        </td>
                    </tr>
                    
                    <tr class="knower-jobconvo-api-wordpress-plugin_row">
                        <th scope="row">
                            <label for="google_cloud_region">Google Cloud Region (Set on processors)</label>
                        </th>
                        <td>
                            <select name="google_cloud_region" id="google_cloud_region">
                                <option value="eu" <?php if(get_option('google_cloud_region') == 'eu'){ echo 'selected'; } ?> >Eu</option>
                                <option value="us" <?php if(get_option('google_cloud_region') == 'us'){ echo 'selected'; } ?>>Us</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr class="knower-jobconvo-api-wordpress-plugin_row">
                        <th scope="row">
                            <label for="google_service_account">Google Service Account (JSON file)</label>
                        </th>
                        <td>
                            <input type="file" name="google_service_account" id="google_service_account">
                        </td>
                    </tr>

                </tbody>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>


    <div class="card">
        <form action="" method="post">
            <input type="hidden" name="manual_sync" value="1">
            <h2 class="title">Manual Sync</h2>
            <?php submit_button('Sync'); ?>
        </form>
    </div>

    <?php include_once 'footer.php'; ?>
</div>