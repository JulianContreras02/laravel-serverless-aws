<?php
// Legacy example: NOT used by the Laravel application. See docs/migration-guide.md.
// [L8] Run by the server's crontab (see ../crontab). If the server is down, it silently does not run.
require __DIR__ . '/../config.php';

$res = mysqli_query($conn, "SELECT l.code, COUNT(c.id) AS total
    FROM links l LEFT JOIN clicks c ON c.link_id = l.id AND DATE(c.clicked) = CURDATE() - INTERVAL 1 DAY
    GROUP BY l.code");

$lines = array();
while ($row = mysqli_fetch_assoc($res)) {
    $lines[] = $row['code'] . ';' . $row['total'];
}

// Written next to the code, on the same disk as the logs.
file_put_contents(__DIR__ . '/../reports/' . date('Y-m-d') . '.csv', implode("\n", $lines));
app_log('daily report done');
