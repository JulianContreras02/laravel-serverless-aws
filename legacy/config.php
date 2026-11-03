<?php
// Legacy example: NOT used by the Laravel application. See docs/migration-guide.md.

// [L1] Credentials hardcoded and committed with the code.
$db_host = 'localhost';
$db_user = 'shortener';
$db_pass = 'changeme';
$db_name = 'shortener';

$admin_password = 'changeme';
$base_url = 'http://short.example.com/';

// [L6] Logs go to a file on the server's disk.
$log_file = '/var/log/shortener/app.log';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die('Database error');
}

function app_log($message)
{
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND);
}
