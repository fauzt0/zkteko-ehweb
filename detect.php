<?php
/**
 * ZKTeco Device Detection and Verification API
 *
 * Endpoint: /api/zkteco/detect.php
 *
 * Actions:
 *   POST detect   - Detect and connect to device, return info
 *   POST verify   - Verify device connectivity
 *   POST get-logs - Retrieve attendance logs from device
 *   GET  status   - Check API health status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

use ZKTeco\Logger;
use ZKTeco\ZKTecoCommunication;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function sendResponse(bool $success, string $message, mixed $data = null, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$logger = new Logger(LOG_DIR, LOG_ENABLED, DEBUG_MODE);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
} else {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? 'detect';
}

$logger->log("API Request: {$method} — Action: {$action}");

// ---------------------------------------------------------------------------
// Route
// ---------------------------------------------------------------------------
try {
    match ($action) {
        'detect'   => handleDetect($logger),
        'verify'   => handleVerify($logger),
        'get-logs' => handleGetLogs($logger),
        'status'   => handleStatus(),
        default    => sendResponse(false, "Unknown action: {$action}", null, 400),
    };
} catch (\Throwable $e) {
    $logger->error("API Error: " . $e->getMessage());
    sendResponse(false, "Internal error: " . $e->getMessage(), null, 500);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function handleDetect(Logger $logger): void
{
    $logger->info("Starting device detection...");

    $comm = new ZKTecoCommunication(ZKTECO_HOST, ZKTECO_PORT, ZKTECO_TIMEOUT, $logger);

    if (!$comm->connect()) {
        sendResponse(false, "Failed to connect to ZKTeco device at " . ZKTECO_HOST . ":" . ZKTECO_PORT, null, 503);
    }

    $deviceInfo = $comm->getDeviceInfo();

    if ($deviceInfo === null) {
        $comm->disconnect();
        sendResponse(false, "Failed to retrieve device information", null, 503);
    }

    $logger->info("Device detected successfully");

    $response = [
        'device_info' => $deviceInfo,
        'status'      => 'connected',
        'host'        => ZKTECO_HOST,
        'port'        => ZKTECO_PORT,
        'model'       => ZKTECO_DEVICE_MODEL,
    ];

    $comm->disconnect();
    sendResponse(true, "ZKTeco device detected successfully", $response);
}

function handleVerify(Logger $logger): void
{
    $logger->info("Starting device verification...");

    $comm = new ZKTecoCommunication(ZKTECO_HOST, ZKTECO_PORT, ZKTECO_TIMEOUT, $logger);

    if (!$comm->connect()) {
        sendResponse(false, "Device verification failed: Cannot connect", null, 503);
    }

    if (!$comm->isDeviceConnected()) {
        $comm->disconnect();
        sendResponse(false, "Device verification failed: Device not responding", null, 503);
    }

    $deviceInfo = $comm->getDeviceInfo();

    if ($deviceInfo === null) {
        $comm->disconnect();
        sendResponse(false, "Device verification failed: Cannot retrieve device info", null, 503);
    }

    $logger->info("Device verification successful");

    $response = [
        'device_info'          => $deviceInfo,
        'connection_status'    => 'verified',
        'verification_time'    => date('Y-m-d H:i:s'),
        'ready_for_sync'      => true,
    ];

    $comm->disconnect();
    sendResponse(true, "Device verification successful", $response);
}

function handleGetLogs(Logger $logger): void
{
    $logger->info("Fetching attendance logs...");

    $comm = new ZKTecoCommunication(ZKTECO_HOST, ZKTECO_PORT, ZKTECO_TIMEOUT, $logger);

    if (!$comm->connect()) {
        sendResponse(false, "Failed to connect to device", null, 503);
    }

    $logs = $comm->getAttendanceLogs();

    if ($logs === null) {
        $comm->disconnect();
        sendResponse(false, "Failed to retrieve attendance logs", null, 503);
    }

    $logger->info("Retrieved " . count($logs) . " attendance logs");

    $response = [
        'logs'           => $logs,
        'total_records'  => count($logs),
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];

    $comm->disconnect();
    sendResponse(true, "Attendance logs retrieved successfully", $response);
}

function handleStatus(): void
{
    $status = [
        'api_status'            => 'online',
        'zkteco_host'           => ZKTECO_HOST,
        'zkteco_port'           => ZKTECO_PORT,
        'zkteco_model'          => ZKTECO_DEVICE_MODEL,
        'erp_api_configured'    => !empty(ERP_API_URL) && ERP_API_URL !== 'https://your-erp-domain.com/api/attendance/sync',
        'logging_enabled'       => LOG_ENABLED,
        'debug_mode'            => DEBUG_MODE,
        'composer_loaded'       => class_exists('\Mithun\PhpZkteco\Libs\ZKTeco'),
        'timestamp'             => date('Y-m-d H:i:s'),
    ];

    sendResponse(true, "API is operational", $status);
}
