<?php
/**
 * ZKTeco Attendance Sync Endpoint
 *
 * Synchronises attendance data from ZKTeco device to a remote CodeIgniter 3 API.
 * Endpoint: /api/zkteco/sync.php
 *
 * Actions:
 *   POST/GET sync      - Full sync: device → local → CI3 API
 *   POST/GET sync-test - Dry-run: fetch logs, preview payload, no POST
 *   GET  last-sync     - Get last sync timestamp
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

use ZKTeco\Logger;
use ZKTeco\ZKTecoCommunication;
use ZKTeco\ERPSync;

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
    $action = $_GET['action'] ?? 'sync';
} else {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? 'sync';
}

$logger->log("Sync Request: {$method} — Action: {$action}");

// ---------------------------------------------------------------------------
// Route
// ---------------------------------------------------------------------------
try {
    match ($action) {
        'sync'      => handleSync($logger),
        'sync-test' => handleSyncTest($logger),
        'last-sync' => handleLastSync($logger),
        default     => sendResponse(false, "Unknown action: {$action}", null, 400),
    };
} catch (\Throwable $e) {
    $logger->error("Sync Error: " . $e->getMessage());
    sendResponse(false, "Internal error: " . $e->getMessage(), null, 500);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * Full synchronisation: connect to device → fetch logs → POST to CI3 API.
 */
function handleSync(Logger $logger): void
{
    $logger->info("Starting attendance synchronisation...");

    // ── Step 1: Connect to device ────────────────────────────────────────
    $comm = new ZKTecoCommunication(ZKTECO_HOST, ZKTECO_PORT, ZKTECO_TIMEOUT, $logger);

    if (!$comm->connect()) {
        sendResponse(false, "Failed to connect to ZKTeco device at " . ZKTECO_HOST, null, 503);
    }

    $logger->info("Connected to ZKTeco device");

    // ── Step 2: Get device serial for the payload ─────────────────────────
    $deviceInfo = $comm->getDeviceInfo();
    $deviceSerial = $deviceInfo['serial_number'] ?? '';

    // ── Step 3: Retrieve attendance logs ──────────────────────────────────
    $logs = $comm->getAttendanceLogs();

    $comm->disconnect();

    if ($logs === null) {
        sendResponse(false, "Failed to retrieve attendance logs from device", null, 503);
    }

    $logger->info("Retrieved " . count($logs) . " attendance records from device");

    if (empty($logs)) {
        sendResponse(true, "No new attendance records to sync", [
            'synced_records' => 0,
            'status'         => 'completed',
        ]);
    }

    // ── Step 4: Send to ERP via cURL ─────────────────────────────────────
    $erpSync = new ERPSync(
        ERP_API_URL,
        ERP_API_KEY,
        ERP_API_SECRET,
        $logger,
        CURL_TIMEOUT,
        CURL_CONNECT_TIMEOUT
    );

    $syncResult = $erpSync->syncAttendanceData($logs, $deviceSerial);

    if (!$syncResult['success']) {
        $logger->error("Synchronisation to ERP failed: " . $syncResult['message']);
        sendResponse(false, $syncResult['message'], $syncResult, 502);
    }

    $logger->info("Synchronisation completed. Synced {$syncResult['synced_records']} records");
    sendResponse(true, "Attendance data synchronised successfully", $syncResult);
}

/**
 * Test sync (dry-run): fetch logs and show payload preview without POSTing.
 */
function handleSyncTest(Logger $logger): void
{
    $logger->info("Starting test synchronisation (dry-run)...");

    $comm = new ZKTecoCommunication(ZKTECO_HOST, ZKTECO_PORT, ZKTECO_TIMEOUT, $logger);

    if (!$comm->connect()) {
        sendResponse(false, "Failed to connect to ZKTeco device", null, 503);
    }

    $deviceInfo = $comm->getDeviceInfo();
    $deviceSerial = $deviceInfo['serial_number'] ?? '';
    $logs = $comm->getAttendanceLogs();

    $comm->disconnect();

    if ($logs === null) {
        sendResponse(false, "Failed to retrieve attendance logs", null, 503);
    }

    $erpSync = new ERPSync(ERP_API_URL, ERP_API_KEY, ERP_API_SECRET, $logger);
    $payload = $erpSync->preparePayload($logs, $deviceSerial);

    $logger->info("Test sync: Would send " . count($logs) . " records to ERP");

    $response = [
        'total_records'   => count($logs),
        'dry_run'         => true,
        'erp_url'         => ERP_API_URL,
        'device_serial'   => $deviceSerial,
        'first_5_records' => array_slice($logs, 0, 5),
        'payload_preview' => [
            'api_key'       => substr($payload['api_key'], 0, 8) . '…',
            'device_serial' => $payload['device_serial'],
            'record_count'  => count($payload['records']),
        ],
    ];

    sendResponse(true, "Test synchronisation completed (dry-run, no data sent)", $response);
}

/**
 * Get the last synchronisation timestamp.
 */
function handleLastSync(Logger $logger): void
{
    $erpSync = new ERPSync(ERP_API_URL, ERP_API_KEY, ERP_API_SECRET, $logger);
    $lastSync = $erpSync->getLastSync();

    $response = [
        'last_sync_time'  => $lastSync ?? 'Never',
        'current_time'    => date('Y-m-d H:i:s'),
        'erp_configured'  => !empty(ERP_API_URL) && ERP_API_URL !== 'https://your-erp-domain.com/api/attendance/sync',
    ];

    sendResponse(true, "Last synchronisation info retrieved", $response);
}
