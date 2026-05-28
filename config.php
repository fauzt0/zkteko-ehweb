<?php
/**
 * ZKTeco Device Configuration
 *
 * Edit these values to match your environment before running any script.
 */

// ---------------------------------------------------------------------------
// ZKTeco Device Settings
// ---------------------------------------------------------------------------
define('ZKTECO_HOST', '192.168.0.114');        // IP address of your ZKTeco MB10-VL device
define('ZKTECO_PORT', 4370);                    // Default ZKTeco port (ZK protocol)
define('ZKTECO_TIMEOUT', 5);                    // Connection timeout in seconds
define('ZKTECO_DEVICE_MODEL', 'MB10-VL');       // Device model identifier

// ---------------------------------------------------------------------------
// Remote ERP (CodeIgniter 3) Settings
// ---------------------------------------------------------------------------
define('ERP_API_URL', 'https://your-erp-domain.com/api/attendance/sync');
define('ERP_API_KEY', 'your-api-key-here');     // Shared secret – must match CI3 controller
define('ERP_API_SECRET', 'your-api-secret-here'); // HMAC secret (optional)

// ---------------------------------------------------------------------------
// cURL Settings
// ---------------------------------------------------------------------------
define('CURL_TIMEOUT', 15);                     // Max seconds to wait for API response
define('CURL_CONNECT_TIMEOUT', 5);              // Max seconds for connection establishment

// ---------------------------------------------------------------------------
// Logging Settings
// ---------------------------------------------------------------------------
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_ENABLED', true);
define('DEBUG_MODE', true);                     // Set false in production

// ---------------------------------------------------------------------------
// Composer Autoload
// ---------------------------------------------------------------------------
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// ---------------------------------------------------------------------------
// Ensure log directory exists
// ---------------------------------------------------------------------------
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}
