<?php
$db = new SQLite3(__DIR__ . '/limbo.db');
header('Content-Type: application/json');

if (isset($_GET['before'])) {
    $before = (int)$_GET['before'];
    $stmt = $db->prepare('SELECT * FROM cycles WHERE cycle < 9000 AND id < :before ORDER BY id DESC LIMIT 20');
    $stmt->bindValue(':before', $before, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    echo json_encode($rows);
} else {
    $row = $db->querySingle('SELECT * FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 1', true);
    echo json_encode($row ?: null);
}
