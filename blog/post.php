<?php
$_cfg = require __DIR__ . '/config.php';
define('POST_KEY', $_cfg['post_key']);

if (($_POST['key'] ?? '') !== POST_KEY) {
    http_response_code(403);
    exit('forbidden');
}

$db = new SQLite3(__DIR__ . '/limbo.db');
$db->exec('CREATE TABLE IF NOT EXISTS cycles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cycle INTEGER,
    text TEXT,
    temp TEXT,
    mark TEXT,
    image TEXT,
    cam_desc TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('ALTER TABLE cycles ADD COLUMN temp TEXT');
$db->exec('ALTER TABLE cycles ADD COLUMN mark TEXT');
$db->exec('ALTER TABLE cycles ADD COLUMN image TEXT');
$db->exec('ALTER TABLE cycles ADD COLUMN cam_desc TEXT');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cycle = (int)($_POST['cycle'] ?? 0);
    $text  = trim($_POST['text'] ?? '');
    $temp  = trim($_POST['temp'] ?? '');
    $mark  = trim($_POST['mark'] ?? '');
    $image    = trim($_POST['image'] ?? '');
    $cam_desc = trim($_POST['cam_desc'] ?? '');
    if ($text) {
        $stmt = $db->prepare('INSERT INTO cycles (cycle, text, temp, mark, image, cam_desc) VALUES (:cycle, :text, :temp, :mark, :image, :cam_desc)');
        $stmt->bindValue(':cycle',    $cycle,    SQLITE3_INTEGER);
        $stmt->bindValue(':text',     $text,     SQLITE3_TEXT);
        $stmt->bindValue(':temp',     $temp,     SQLITE3_TEXT);
        $stmt->bindValue(':mark',     $mark,     SQLITE3_TEXT);
        $stmt->bindValue(':image',    $image,    SQLITE3_TEXT);
        $stmt->bindValue(':cam_desc', $cam_desc, SQLITE3_TEXT);
        $stmt->execute();
        http_response_code(200);
        echo 'ok';
    } else {
        http_response_code(400);
        echo 'empty';
    }
} else {
    http_response_code(405);
}
