<?php
/**
 * Chuck Norris AI - Download report (dashboard). Token = hash('sha256', DASHBOARD_API_KEY)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth_web.php';

$id = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';
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
header('Content-Type: ' . $row['mime_type']);
header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
header('Content-Length: ' . filesize($row['file_path']));
readfile($row['file_path']);
exit;
