<?php
// Legacy example: NOT used by the Laravel application. See docs/migration-guide.md.
require __DIR__ . '/../config.php';

// [L7] Admin login stored in session files on the server's disk.
session_start();

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action == 'login') {
    if ($_POST['password'] == $admin_password) {
        $_SESSION['admin'] = true;
    }
    header('Location: index.php?action=admin');
    exit;
}

if ($action == 'create' && !empty($_SESSION['admin'])) {
    // [L12] Check-then-insert: two requests can pick the same code at the same time.
    do {
        $code = substr(md5(rand()), 0, 6);
        $res = mysqli_query($conn, "SELECT id FROM links WHERE code = '$code'");
    } while (mysqli_num_rows($res) > 0);

    // [L3] SQL built by concatenation: one missed escape away from an injection.
    $url = mysqli_real_escape_string($conn, $_POST['url']);
    mysqli_query($conn, "INSERT INTO links (code, url, hits, created) VALUES ('$code', '$url', 0, NOW())");
    app_log("created $code");
    echo 'Short link: ' . $base_url . $code;
    exit;
}

if ($action == 'stats' && !empty($_SESSION['admin'])) {
    $code = mysqli_real_escape_string($conn, $_GET['code']);
    // [L5] Stats grouped from every raw click on every page view.
    $res = mysqli_query($conn, "SELECT DATE(c.clicked) AS day, COUNT(*) AS total
        FROM clicks c JOIN links l ON l.id = c.link_id
        WHERE l.code = '$code' GROUP BY DATE(c.clicked) ORDER BY day");
    while ($row = mysqli_fetch_assoc($res)) {
        echo $row['day'] . ': ' . $row['total'] . "<br>\n";
    }
    exit;
}

if (isset($_GET['c'])) {
    $code = mysqli_real_escape_string($conn, $_GET['c']);
    // [L11] Default case-insensitive collation: "aB3x" and "ab3x" are the same link.
    $res = mysqli_query($conn, "SELECT id, url FROM links WHERE code = '$code'");
    $link = mysqli_fetch_assoc($res);

    if (!$link) {
        header('HTTP/1.0 404 Not Found');
        echo 'Not found';
        exit;
    }

    // [L4] Every click rewrites the same row: a popular link serializes all its visitors.
    mysqli_query($conn, "UPDATE links SET hits = hits + 1 WHERE id = " . $link['id']);
    $referer = isset($_SERVER['HTTP_REFERER']) ? mysqli_real_escape_string($conn, $_SERVER['HTTP_REFERER']) : '';
    $ip = $_SERVER['REMOTE_ADDR'];
    // Full referrer URL and visitor IP stored forever.
    mysqli_query($conn, "INSERT INTO clicks (link_id, clicked, referer, ip) VALUES ({$link['id']}, NOW(), '$referer', '$ip')");

    // [L10] Permanent redirect: browsers cache it, so later clicks are never counted.
    header('Location: ' . $link['url'], true, 301);
    exit;
}

echo '<form method="post" action="index.php?action=login"><input type="password" name="password"><button>Login</button></form>';
