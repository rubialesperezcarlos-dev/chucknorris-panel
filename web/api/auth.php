<?php
/**
 * Chuck Norris AI - API authentication
 * Validates X-API-Key or Authorization: Bearer <key> against api_keys table
 */

require_once __DIR__ . '/../config/config.php';

function getApiKeyFromRequest(): ?string {
    $key = null;
    
    // Método 1: getallheaders() - buscar case-insensitive
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if ($headers) {
        // Normalizar keys a minúsculas
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        if (!empty($headersLower['x-api-key'])) {
            $key = trim($headersLower['x-api-key']);
        } elseif (!empty($headersLower['authorization'])) {
            if (preg_match('/Bearer\s+(.+)$/i', $headersLower['authorization'], $m)) {
                $key = trim($m[1]);
            }
        }
    }
    
    // Método 2: $_SERVER (Apache convierte headers a HTTP_X_API_KEY)
    if (!$key && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $key = trim($_SERVER['HTTP_X_API_KEY']);
    }
    if (!$key && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $key = trim($m[1]);
        }
    }
    
    return $key ?: null;
}

function validateApiKey(?string $key): bool {
    if (!$key) {
        return false;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT 1 FROM api_keys WHERE api_key = ? LIMIT 1');
    $stmt->execute([$key]);
    return (bool) $stmt->fetch();
}

function requireAuth(): void {
    $key = getApiKeyFromRequest();
    if (!validateApiKey($key)) {
        header('Content-Type: application/json');
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Invalid or missing API key']);
        exit;
    }
}
