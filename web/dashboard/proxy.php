<?php
/**
 * Chuck Norris AI - Dashboard API proxy (adds API key server-side)
 * Devuelve SIEMPRE JSON para que el dashboard no reciba HTML/502 sin parsear.
 */

ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth_web.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$path = isset($_GET['path']) ? (string) $_GET['path'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$key = defined('DASHBOARD_API_KEY') ? DASHBOARD_API_KEY : '';

if ($path === '' || $key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing path or API key not configured']);
    exit;
}

// Base del API según dónde esté el dashboard (evita rutas rotas)
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$apiBase = str_replace('/dashboard', '/api', $base);
if ($apiBase === $base) {
    // Si no hay '/dashboard' en la ruta, asumir /api junto al script
    $apiBase = rtrim(dirname($base), '/') . '/api';
    if ($apiBase === '/api') {
        $apiBase = dirname($base) . '/api';
    }
}

// path puede venir como "tasks/logs/get?task_id=1&..." (todo en un solo parámetro)
$path = ltrim($path, '/');
// Evitar doble slash
$requestPath = $apiBase . '/' . $path;
if (strpos($requestPath, '//') !== false) {
    $requestPath = preg_replace('#/+#', '/', $requestPath);
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
$scheme = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';

// Primera opción: misma URL que usaría el navegador
$fullUrl = $scheme . '://' . $host . $requestPath;

$headers = [
    'X-API-Key: ' . $key,
    'Content-Type: application/json',
];
$body = ($method === 'POST' || $method === 'PUT') ? file_get_contents('php://input') : null;

function proxy_send_json(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function proxy_request_curl(string $url, string $method, array $headers, ?string $body): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'errno' => -1, 'error' => 'cURL no disponible', 'http' => 0, 'body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $responseBody = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($responseBody === false) {
        return ['ok' => false, 'errno' => $errno, 'error' => $error ?: 'curl_exec failed', 'http' => $http, 'body' => ''];
    }
    return ['ok' => true, 'errno' => 0, 'error' => '', 'http' => $http, 'body' => (string) $responseBody];
}

$altUrl = $scheme . '://127.0.0.1' . $requestPath;
if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] !== 80 && (int) $_SERVER['SERVER_PORT'] !== 443) {
    $altUrl = $scheme . '://127.0.0.1:' . (int) $_SERVER['SERVER_PORT'] . $requestPath;
}

// 1) Intentar con HTTP_HOST
$result = proxy_request_curl($fullUrl, $method, $headers, $body);

// 2) Si falla la conexión, probar 127.0.0.1 (mismo servidor; evita problemas con localhost/IPv6)
if (!$result['ok'] && $result['errno'] !== 0) {
    $result = proxy_request_curl($altUrl, $method, $headers, $body);
}

// 3) Sin cURL: file_get_contents como respaldo
if (!function_exists('curl_init') || (!$result['ok'] && $result['http'] === 0)) {
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);
    $responseBody = @file_get_contents($fullUrl, false, $ctx);
    if ($responseBody === false && $fullUrl !== ($altUrl ?? '')) {
        $responseBody = @file_get_contents($altUrl ?? $fullUrl, false, $ctx);
    }
    if ($responseBody !== false) {
        $code = 200;
        if (!empty($http_response_header[0]) && preg_match('/ (\d{3}) /', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        $result = ['ok' => true, 'http' => $code, 'body' => $responseBody];
    }
}

if (!$result['ok'] && ($result['http'] ?? 0) === 0) {
    proxy_send_json(502, [
        'error' => 'No se pudo conectar con la API',
        'detail' => $result['error'] ?? 'request failed',
        'url_tried' => $fullUrl,
    ]);
}

$httpCode = $result['http'] ?? 200;
$bodyOut = $result['body'] ?? '';

$trim = ltrim($bodyOut);
$isJson = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));

// Si no es JSON, reintentar vía index.php?path=... (por si mod_rewrite no aplica en peticiones internas)
if (!$isJson && $httpCode >= 400) {
    $pathOnly = $path;
    $extraQuery = '';
    if (($qPos = strpos($path, '?')) !== false) {
        $pathOnly = substr($path, 0, $qPos);
        $extraQuery = substr($path, $qPos + 1);
    }
    $indexQuery = 'path=' . rawurlencode($pathOnly);
    if ($extraQuery !== '') {
        $indexQuery .= '&' . $extraQuery;
    }
    $indexUrl = $scheme . '://' . $host . $apiBase . '/index.php?' . $indexQuery;
    $retry = proxy_request_curl($indexUrl, $method, $headers, $body);
    $retryTrim = ltrim($retry['body'] ?? '');
    if ($retry['ok'] && $retryTrim !== '' && ($retryTrim[0] === '{' || $retryTrim[0] === '[')) {
        $result = $retry;
        $httpCode = $result['http'];
        $bodyOut = $result['body'];
        $trim = $retryTrim;
        $isJson = true;
    }
}

// Cuerpo vacío con error HTTP
if (!$isJson && $trim === '' && $httpCode >= 400) {
    proxy_send_json($httpCode, [
        'error' => 'La API respondió vacío',
        'detail' => 'HTTP ' . $httpCode,
        'url_tried' => $fullUrl,
    ]);
}

// Si el cuerpo sigue sin ser JSON, devolver error en JSON (el dashboard ya puede mostrarlo)
if (!$isJson && $trim !== '') {
    proxy_send_json($httpCode >= 400 ? $httpCode : 502, [
        'error' => 'La API no devolvió JSON',
        'detail' => 'HTTP ' . $httpCode . ' — revisa /api/.htaccess y que la URL base sea correcta',
        'preview' => mb_substr(strip_tags($bodyOut), 0, 200),
        'url_tried' => $fullUrl,
    ]);
}

http_response_code($httpCode);
echo $bodyOut;
