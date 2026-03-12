<?php
/**
 * Chuck Norris AI - Generate HTML report from scan_results (when no file was uploaded)
 * Usage: generate_report.php?task_id=1&token=HASH
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth_web.php';

$task_id = (int)($_GET['task_id'] ?? 0);
$token = $_GET['token'] ?? '';
$expected = defined('DASHBOARD_API_KEY') ? hash('sha256', DASHBOARD_API_KEY) : '';

if ($task_id <= 0 || $token !== $expected) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = getDb();
$task = $pdo->prepare('SELECT id, target_url, status, created_at, completed_at FROM tasks WHERE id = ?');
$task->execute([$task_id]);
$task = $task->fetch();
if (!$task) {
    http_response_code(404);
    exit('Task not found');
}

$result = $pdo->prepare('SELECT raw_output, findings_json, summary FROM scan_results WHERE task_id = ?');
$result->execute([$task_id]);
$result = $result->fetch();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Chuck Norris AI – Report #<?= (int)$task['id'] ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 0 auto; padding: 24px; background: #0d1117; color: #e6edf3; }
        h1 { color: #58a6ff; }
        pre { background: #161b22; padding: 16px; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; }
        .meta { color: #8b949e; margin-bottom: 24px; }
    </style>
</head>
<body>
    <h1>Chuck Norris AI – Pentest Report</h1>
    <div class="meta">
        <strong>Task ID:</strong> <?= (int)$task['id'] ?><br>
        <strong>Target:</strong> <?= htmlspecialchars($task['target_url']) ?><br>
        <strong>Status:</strong> <?= htmlspecialchars($task['status']) ?><br>
        <strong>Created:</strong> <?= htmlspecialchars($task['created_at']) ?><br>
        <strong>Completed:</strong> <?= htmlspecialchars($task['completed_at'] ?? '-') ?>
    </div>
    <?php if ($result && !empty($result['summary'])): ?>
    <h2>Summary</h2>
    <p><?= htmlspecialchars($result['summary']) ?></p>
    <?php endif; ?>
    <h2>Raw Output</h2>
    <pre><?= htmlspecialchars($result['raw_output'] ?? '(no output)') ?></pre>
</body>
</html>
