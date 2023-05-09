<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require( dirname( __FILE__ ) . '/../../../wp-blog-header.php' );
require_once(plugin_dir_path(__FILE__) . 'functions.php');
require_once(plugin_dir_path(__FILE__) . 'AlfasoftJobconvo.php');
log_info('Cron job - Sync started');
AlfasoftJobconvo::sync();
?>