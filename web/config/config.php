<?php
/**
 * Chuck Norris AI - Global config
 */

require_once __DIR__ . '/database.php';

// API key can be sent as X-API-Key header or Authorization: Bearer <key>
define('API_KEY_HEADER', 'X-API-Key');
define('REPORTS_DIR', __DIR__ . '/../storage/reports');
define('LOG_STREAM_CHUNK_SIZE', 50);

// Dashboard: API key used by the web panel to call the API (must exist in api_keys table)
define('DASHBOARD_API_KEY', 'ChuckN0rr1s_S3cur3_K3y_2024_xK9mP2vL');

if (!is_dir(REPORTS_DIR)) {
    @mkdir(REPORTS_DIR, 0755, true);
}
