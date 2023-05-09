<?php 

    if (isset($_POST['get_logs']) && isset($_POST['logs_accordion']) && $_POST['get_logs']) {
        $file = __DIR__ . '/../log/' . $_POST['logs_accordion'];
        $f = fopen($file, 'r');
        $log_lines = [];

        $title = '';
        $counter = 0;

        while(! feof($f)) {

            $line = fgets($f);
            $parts = explode(']',$line);

            if (count($parts) != 3) {
                continue;
            }

            if (str_contains( $parts[2], "BEGIN")) {
                $title = str_replace('[','',$parts[0]) . " - " . trim(str_replace(" - BEGIN\n", '', $parts[2]));
            }
            
            if ($title) {
                $log_lines[$title][] = $line;
            } else {
                $log_lines[$counter][] = $line;
            }

            if (str_contains( $parts[2], "END")) {
                $title = '';
                $counter++;
            }

        }
        fclose($f);
    }


?>

<div class="jobconvo-container logs">
    <h2 class="jobconvo-title">Jobconvo API Integration - Logs</h2>
    <?php
    $logs = AlfasoftJobconvo::Logs();
    ?>

    <form method="post" class="card">
        <label for="logs_accordion">Log date:</label>
        <select name="logs_accordion" id="logs_accordion">
            <option value="" disabled selected>Select a log</option>
            <?php foreach ($logs as $month => $log_files) { ?>
                <optgroup label="<?php echo $month ?>">
                    <?php foreach ($log_files as $log) : ?>
                        <option value="<?php echo $log['path']; ?>"><?php echo $log['name']; ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php } ?>
        </select>

        <input type="hidden" name="get_logs" value="1">
        <?php submit_button('Get log') ?>
        <?php if (isset($file)) : ?>
            <a class="button button-primary" href="<?php echo plugin_url() . "log/" . $_POST['logs_accordion'] ?>" download> Download log file</a>
        <?php endif;?>

        <?php if (!empty($log_lines)) : ?>
            <div class="card" style="width:70%">
                <?php foreach($log_lines as $key => $log_line) : ?>

                    <button class="accordion" style="width:100%"><?php echo $key ?></button>
                    <div class="panel" style="display:none">

                        <?php foreach ($log_line as $sub_log_line) : ?>

                            <p style="word-wrap:break-word;margin:1px 0"><?php echo $sub_log_line ?></p>
                            
                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </form>

    <script>
        jQuery(document).ready(function(){

            jQuery('.accordion').each(function(){

                jQuery(this).on('click', function(e){
                    e.preventDefault();
                    jQuery(this).next().toggle();
                });

            });

        });
    </script>

    <div class="card">
        <label for="logs">Log date:</label>
        <select name="logs" id="logs">
            <option value="" disabled selected>Select a log</option>
            <?php foreach ($logs as $month => $log_files) { ?>
                <optgroup label="<?php echo $month ?>">
                    <?php foreach ($log_files as $log) : ?>
                        <option value="<?php echo $log['path']; ?>"><?php echo $log['name']; ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php } ?>
        </select>
        <script>
            jQuery(document).ready(function($) {
                // When user selects a log, load the log file content into the textarea.
                $('#logs').on('change', function() {
                    var log_date = $(this).val();

                    $(this).prop('disabled', true);
                    $('#logContent').val('data loading...');

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>', // Since WP 2.8 ajaxurl is always defined and points to admin-ajax.php
                        type: 'POST',
                        data: {
                            'action': 'logs_action_callback', // This is our PHP function below
                            'date': log_date // This is the variable we are sending via AJAX
                        },
                        success: function(data) {
                            // This outputs the result of the ajax request (The Callback)
                            $('#logContent').val(data);
                        },
                        error: function(errorThrown) {
                            console.log(errorThrown);
                        },
                        complete: function() {
                            $('#logs').prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <div class="log-content">
            <label for="logContent">Content:</label>
            <textarea name="logContent" id="logContent" cols="30" rows="10" style="width: 100%;" readonly></textarea>
        </div>
    </div>

    <?php include_once 'footer.php'; ?>
</div>