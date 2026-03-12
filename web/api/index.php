<?php
/**
 * Chuck Norris AI - REST API Router
 * Usage: /api/index.php/workers/register, /api/index.php/tasks/create, etc.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Path sin query string: si no, "tasks/logs/get?task_id=1" rompe $action (queda "get?task_id=1")
$path = $_GET['path'] ?? '';
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#/api/(?:index\.php/)?([^?]+)#', $reqUri, $m)) {
    $path = $m[1];
}
$path = trim($path, '/');
$segments = $path ? explode('/', $path) : [];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/workers.php';
require_once __DIR__ . '/tasks.php';

$method = $_SERVER['REQUEST_METHOD'];
$route = $segments[0] ?? '';
$sub = $segments[1] ?? '';
$action = $segments[2] ?? '';

try {
    if ($route === 'workers') {
        if ($sub === 'register' && $method === 'POST') {
            api_workers_register();
        } elseif ($sub === 'heartbeat' && $method === 'POST') {
            api_workers_heartbeat();
        } elseif ($sub === 'list' && $method === 'GET') {
            api_workers_list();
        } elseif ($sub === 'config' && $method === 'POST') {
            api_workers_config();
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
        return;
    }

    if ($route === 'tasks') {
        if ($sub === 'create' && $method === 'POST') {
            api_tasks_create();
        } elseif ($sub === 'list' && $method === 'GET') {
            api_tasks_list();
        } elseif ($sub === 'poll' && $method === 'POST') {
            api_tasks_poll();
        } elseif ($sub === 'start' && $method === 'POST') {
            api_tasks_start();
        } elseif ($sub === 'complete' && $method === 'POST') {
            api_tasks_complete();
        } elseif ($sub === 'get' && $method === 'GET') {
            api_tasks_get();
        } elseif ($sub === 'logs' && $action === 'append' && $method === 'POST') {
            api_tasks_logs_append();
        } elseif ($sub === 'logs' && $action === 'get' && $method === 'GET') {
            api_tasks_logs_get();
        } elseif ($sub === 'results' && $action === 'upload' && $method === 'POST') {
            api_tasks_results_upload();
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
        return;
    }

    if ($route === 'reports') {
        if ($sub === 'upload' && $method === 'POST') {
            api_reports_upload();
        } elseif ($sub === 'list' && $method === 'GET') {
            api_reports_list();
        } elseif ($sub === 'download' && $method === 'GET') {
            api_reports_download();
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'path' => $path]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
