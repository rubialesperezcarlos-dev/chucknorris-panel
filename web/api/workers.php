<?php
/**
 * Chuck Norris AI - Workers API
 * Register, heartbeat, list workers
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

function api_workers_register(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $uuid = $input['uuid'] ?? null;
    $hostname = $input['hostname'] ?? gethostname();
    $ip = $input['ip'] ?? ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null);
    $ram_total_mb = (int)($input['ram_total_mb'] ?? 0);
    $ram_used_mb = (int)($input['ram_used_mb'] ?? 0);
    $cpu_usage_percent = (float)($input['cpu_usage_percent'] ?? 0);

    if (!$uuid || !preg_match('/^[a-f0-9\-]{36}$/i', $uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing uuid']);
        return;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id FROM workers WHERE uuid = ?');
    $stmt->execute([$uuid]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE workers SET hostname=?, ip_address=?, ram_total_mb=?, ram_used_mb=?, cpu_usage_percent=?, active_tasks=(SELECT COUNT(*) FROM tasks WHERE worker_id=workers.id AND status="running"), status="online", last_heartbeat_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([$hostname, $ip, $ram_total_mb, $ram_used_mb, $cpu_usage_percent, $existing['id']]);
        $worker_id = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO workers (uuid, hostname, ip_address, ram_total_mb, ram_used_mb, cpu_usage_percent, status, last_heartbeat_at) VALUES (?,?,?,?,?,?,"online",NOW())');
        $stmt->execute([$uuid, $hostname, $ip, $ram_total_mb, $ram_used_mb, $cpu_usage_percent]);
        $worker_id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['worker_id' => $worker_id, 'uuid' => $uuid]);
}

function api_workers_heartbeat(): void {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $worker_id = (int)($input['worker_id'] ?? 0);
    $ram_used_mb = (int)($input['ram_used_mb'] ?? 0);
    $cpu_usage_percent = (float)($input['cpu_usage_percent'] ?? 0);
    $active_tasks = (int)($input['active_tasks'] ?? 0);

    if ($worker_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid worker_id']);
        return;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('UPDATE workers SET ram_used_mb=?, cpu_usage_percent=?, active_tasks=?, last_heartbeat_at=NOW(), status="online", updated_at=NOW() WHERE id=?');
    $stmt->execute([$ram_used_mb, $cpu_usage_percent, $active_tasks, $worker_id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Worker not found']);
        return;
    }
    echo json_encode(['ok' => true]);
}

function api_workers_list(): void {
    requireAuth();
    $pdo = getDb();
    
    // Marcar como offline si no hay heartbeat en 60 segundos
    $pdo->exec("UPDATE workers SET status = 'offline' WHERE status = 'online' AND last_heartbeat_at < NOW() - INTERVAL 60 SECOND");
    
    // Eliminar workers sin heartbeat en 5 minutos (300 segundos)
    $pdo->exec("DELETE FROM workers WHERE last_heartbeat_at < NOW() - INTERVAL 300 SECOND");
    
    $stmt = $pdo->query('SELECT id, uuid, hostname, ip_address, ram_total_mb, ram_used_mb, cpu_usage_percent, active_tasks, status, last_heartbeat_at, registered_at FROM workers ORDER BY ram_total_mb DESC, active_tasks ASC');
    $workers = $stmt->fetchAll();
    echo json_encode(['workers' => $workers]);
}
