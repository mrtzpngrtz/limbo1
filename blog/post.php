<?php
define('POST_KEY', 'YOUR_POST_KEY'); // set a secret key here and match it in limbo.sh

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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('ALTER TABLE cycles ADD COLUMN temp TEXT');
$db->exec('ALTER TABLE cycles ADD COLUMN mark TEXT');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cycle = (int)($_POST['cycle'] ?? 0);
    $text  = trim($_POST['text'] ?? '');
    $temp  = trim($_POST['temp'] ?? '');
    $mark  = trim($_POST['mark'] ?? '');
    if ($text) {
        $stmt = $db->prepare('INSERT INTO cycles (cycle, text, temp, mark) VALUES (:cycle, :text, :temp, :mark)');
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->bindValue(':text',  $text,  SQLITE3_TEXT);
        $stmt->bindValue(':temp',  $temp,  SQLITE3_TEXT);
        $stmt->bindValue(':mark',  $mark,  SQLITE3_TEXT);
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
