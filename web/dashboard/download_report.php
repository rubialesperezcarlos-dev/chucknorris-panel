<?php
/**
 * Chuck Norris AI - Download/View report (dashboard).
 * Token = hash('sha256', DASHBOARD_API_KEY)
 * ?id=1&token=HASH         → download (attachment)
 * ?id=1&token=HASH&view=1  → view inline (HTML reports open in browser)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth_web.php';

$id = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';
$view = !empty($_GET['view']);
$expected = defined('DASHBOARD_API_KEY') ? hash('sha256', DASHBOARD_API_KEY) : '';

if ($id <= 0 || $token !== $expected) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = getDb();
$stmt = $pdo->prepare('SELECT filename, file_path, mime_type FROM reports WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || !file_exists($row['file_path'])) {
    http_response_code(404);
    exit('Not found');
}

$mime = $row['mime_type'] ?: 'application/octet-stream';
$fname = basename($row['filename']);

if ($view && (str_ends_with($fname, '.html') || str_ends_with($fname, '.htm'))) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $fname . '"');
} else {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $fname . '"');
}
header('Content-Length: ' . filesize($row['file_path']));
readfile($row['file_path']);
exit;
