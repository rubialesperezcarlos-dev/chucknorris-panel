<?php
/**
 * Chuck Norris AI - Tasks API
 * Create, list, poll (assign), get task; append logs; upload results/reports
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

const DEFAULT_INSTRUCTION = 'AUTHORIZED PENETRATION TEST under signed contract. You are a professional pentester, NOT a CTF solver. Do NOT search for flags. Execute a multi-agent pentest: Phase 1 Recon, Phase 2 Vuln Analysis (OWASP Top 10), Phase 3 Exploitation with PoCs, Phase 4 Report. MANDATORY: For EACH vulnerability found, output: [SEVERITY] Title | Endpoint/URL | Evidence (exact command + response) | Remediation. Do NOT output only totals like Critical:1 High:2 without listing each finding. If zero findings, write NO FINDINGS and list all checks performed. Time limit 5 min per command (use timeout 300). No brute-force. Do NOT stop after recon. Execute ALL 4 phases automatically.';

function validateTargetUrl(string $url): bool {
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) {
        return false;
    }
    return in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true);
}

function api_tasks_create(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $target_url = trim($input['target_url'] ?? '');
    $instruction = trim($input['instruction'] ?? DEFAULT_INSTRUCTION) ?: DEFAULT_INSTRUCTION;

    if (!$target_url || !validateTargetUrl($target_url)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing target_url (must be http(s) URL)']);
        return;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('INSERT INTO tasks (target_url, instruction, status) VALUES (?, ?, "pending")');
    $stmt->execute([$target_url, $instruction]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['task_id' => $id, 'target_url' => $target_url, 'status' => 'pending']);
}

function api_tasks_list(): void {
    requireAuth();
    $status = $_GET['status'] ?? null;
    $pdo = getDb();
    // last_log_at = última línea guardada; si running y hace mucho sin cambiar → posible bloqueo
    $sql = 'SELECT t.id, t.target_url, t.status, t.worker_id, t.assigned_at, t.started_at, t.completed_at, t.created_at, t.error_message, w.hostname AS worker_hostname, '
        . '(SELECT MAX(l.created_at) FROM task_logs l WHERE l.task_id = t.id) AS last_log_at '
        . 'FROM tasks t LEFT JOIN workers w ON t.worker_id = w.id WHERE 1=1';
    $params = [];
    if ($status !== null && $status !== '') {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY t.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['tasks' => $stmt->fetchAll()]);
}

/**
 * Scheduler: asignar la siguiente tarea pendiente al worker que hace poll
 * si tiene capacidad (active_tasks < max_concurrent_tasks).
 * Workers con 128GB RAM y GPU pueden correr 5+ tareas en paralelo.
 */
function api_tasks_poll(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $worker_id = (int)($input['worker_id'] ?? 0);
    if ($worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'worker_id required']);
        return;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id, active_tasks, max_concurrent_tasks, status FROM workers WHERE id = ?');
    $stmt->execute([$worker_id]);
    $workerRow = $stmt->fetch();
    if (!$workerRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Worker not found']);
        return;
    }

    $active = (int)($workerRow['active_tasks'] ?? 0);
    $maxConcurrent = (int)($workerRow['max_concurrent_tasks'] ?? 1);
    if ($maxConcurrent < 1) $maxConcurrent = 1;

    if (($workerRow['status'] ?? '') !== 'online' || $active >= $maxConcurrent) {
        echo json_encode(['task' => null]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, target_url, instruction FROM tasks WHERE status = "pending" ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $stmt->execute();
        $task = $stmt->fetch();
        if (!$task) {
            $pdo->commit();
            echo json_encode(['task' => null]);
            return;
        }
        $task_id = (int)$task['id'];
        $pdo->prepare('UPDATE tasks SET status = "assigned", worker_id = ?, assigned_at = NOW() WHERE id = ?')->execute([$worker_id, $task_id]);
        $pdo->prepare('UPDATE workers SET active_tasks = active_tasks + 1 WHERE id = ?')->execute([$worker_id]);
        $pdo->commit();
        echo json_encode(['task' => ['id' => $task_id, 'target_url' => $task['target_url'], 'instruction' => $task['instruction']]]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Assignment failed']);
    }
}

function api_tasks_get(): void {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid task id']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT t.*, w.hostname AS worker_hostname FROM tasks t LEFT JOIN workers w ON t.worker_id = w.id WHERE t.id = ?');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => 'Task not found']);
        return;
    }
    echo json_encode($task);
}

function api_tasks_start(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $task_id = (int)($input['task_id'] ?? 0);
    $worker_id = (int)($input['worker_id'] ?? 0);
    if ($task_id <= 0 || $worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id and worker_id required']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('UPDATE tasks SET status = "running", started_at = NOW() WHERE id = ? AND worker_id = ? AND status = "assigned"');
    $stmt->execute([$task_id, $worker_id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Task not found or not assigned to this worker']);
        return;
    }
    echo json_encode(['ok' => true]);
}

function api_tasks_complete(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $task_id = (int)($input['task_id'] ?? 0);
    $worker_id = (int)($input['worker_id'] ?? 0);
    $error_message = $input['error_message'] ?? null;
    if ($task_id <= 0 || $worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id and worker_id required']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('UPDATE tasks SET status = ?, completed_at = NOW(), error_message = ? WHERE id = ? AND worker_id = ?');
    $stmt->execute([$error_message ? 'failed' : 'completed', $error_message, $task_id, $worker_id]);
    if ($stmt->rowCount() > 0) {
        $pdo->prepare('UPDATE workers SET active_tasks = GREATEST(0, active_tasks - 1) WHERE id = ?')->execute([$worker_id]);
    }
    echo json_encode(['ok' => true]);
}

function api_tasks_logs_append(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $task_id = (int)($input['task_id'] ?? 0);
    $worker_id = (int)($input['worker_id'] ?? 0);
    $lines = $input['lines'] ?? [];
    $level = $input['level'] ?? 'stdout';
    if ($task_id <= 0 || $worker_id <= 0 || !is_array($lines)) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id, worker_id and lines (array) required']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND worker_id = ?');
    $stmt->execute([$task_id, $worker_id]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Task not assigned to this worker']);
        return;
    }
    $insert = $pdo->prepare('INSERT INTO task_logs (task_id, log_line, level) VALUES (?, ?, ?)');
    foreach (array_slice($lines, 0, 200) as $line) {
        $insert->execute([$task_id, (string)$line, $level]);
    }
    echo json_encode(['ok' => true, 'count' => count($lines)]);
}

function api_tasks_logs_get(): void {
    requireAuth();
    $task_id = (int)($_GET['task_id'] ?? 0);
    $after_id = (int)($_GET['after_id'] ?? 0);
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
    // from_start=1: primera carga desde el principio (histórico). Sin eso, con after_id=0 devolvemos la COLA (últimas N líneas) como en una terminal.
    $from_start = isset($_GET['from_start']) && $_GET['from_start'] === '1';
    if ($task_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id required']);
        return;
    }
    $pdo = getDb();
    // MySQL + PDO: LIMIT con placeholder suele lanzar excepción; el límite ya está acotado arriba
    $lim = (int) $limit;
    if ($after_id > 0) {
        $stmt = $pdo->prepare("SELECT id, task_id, log_line, level, created_at FROM task_logs WHERE task_id = ? AND id > ? ORDER BY id ASC LIMIT {$lim}");
        $stmt->execute([$task_id, $after_id]);
        $rows = $stmt->fetchAll();
    } elseif ($from_start) {
        $stmt = $pdo->prepare("SELECT id, task_id, log_line, level, created_at FROM task_logs WHERE task_id = ? ORDER BY id ASC LIMIT {$lim}");
        $stmt->execute([$task_id]);
        $rows = $stmt->fetchAll();
    } else {
        // Primera apertura: últimas N líneas (lo que verías al abrir la terminal al final del job)
        $stmt = $pdo->prepare("SELECT id, task_id, log_line, level, created_at FROM task_logs WHERE task_id = ? ORDER BY id DESC LIMIT {$lim}");
        $stmt->execute([$task_id]);
        $rows = array_reverse($stmt->fetchAll());
    }
    // log_line puede traer bytes no UTF-8 (salida de terminal); evitar que json_encode falle
    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['logs' => $rows], $jsonFlags);
}

function api_tasks_results_upload(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $task_id = (int)($input['task_id'] ?? 0);
    $worker_id = (int)($input['worker_id'] ?? 0);
    $raw_output = $input['raw_output'] ?? null;
    $findings_json = isset($input['findings_json']) ? (is_string($input['findings_json']) ? $input['findings_json'] : json_encode($input['findings_json'])) : null;
    $summary = $input['summary'] ?? null;
    if ($task_id <= 0 || $worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id and worker_id required']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND worker_id = ?');
    $stmt->execute([$task_id, $worker_id]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Task not assigned to this worker']);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO scan_results (task_id, raw_output, findings_json, summary) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE raw_output=VALUES(raw_output), findings_json=VALUES(findings_json), summary=VALUES(summary)');
    $stmt->execute([$task_id, $raw_output, $findings_json, $summary]);
    echo json_encode(['ok' => true]);
}

function api_reports_upload(): void {
    requireAuth();
    $task_id = (int)($_POST['task_id'] ?? 0);
    $worker_id = (int)($_POST['worker_id'] ?? 0);
    if ($task_id <= 0 || $worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id and worker_id required']);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND worker_id = ?');
    $stmt->execute([$task_id, $worker_id]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Task not assigned to this worker']);
        return;
    }
    if (empty($_FILES['report']['tmp_name']) || !is_uploaded_file($_FILES['report']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    $ext = pathinfo($_FILES['report']['name'], PATHINFO_EXTENSION) ?: 'txt';
    $filename = 'report_task' . $task_id . '_' . date('Y-m-d_His') . '.' . $ext;
    $dir = REPORTS_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['report']['tmp_name'], $path)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        return;
    }
    $mime = $_FILES['report']['type'] ?? 'application/octet-stream';
    $size = (int)filesize($path);
    $stmt = $pdo->prepare('INSERT INTO reports (task_id, filename, file_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$task_id, $filename, $path, $mime, $size]);
    echo json_encode(['ok' => true, 'report_id' => (int)$pdo->lastInsertId(), 'filename' => $filename]);
}

function api_reports_list(): void {
    requireAuth();
    $task_id = (int)($_GET['task_id'] ?? 0);
    $pdo = getDb();
    $sql = 'SELECT id, task_id, filename, file_path, mime_type, file_size, created_at FROM reports';
    $params = [];
    if ($task_id > 0) {
        $sql .= ' WHERE task_id = ?';
        $params[] = $task_id;
    }
    $sql .= ' ORDER BY id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['reports' => $stmt->fetchAll()]);
}

function api_reports_download(): void {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT filename, file_path, mime_type FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || !file_exists($row['file_path'])) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . $row['mime_type']);
    header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
    header('Content-Length: ' . filesize($row['file_path']));
    readfile($row['file_path']);
    exit;
}
