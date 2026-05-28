<?php

namespace ZKTeco;

use Throwable;

/**
 * ERPSync — Formats attendance data as JSON and sends it to a remote
 * CodeIgniter 3 API endpoint via cURL POST.
 */
class ERPSync
{
    private string $apiUrl;
    private string $apiKey;
    private string $apiSecret;
    private Logger $logger;
    private string $lastSyncFile;

    /** @var int cURL response timeout in seconds */
    private int $curlTimeout;

    /** @var int cURL connection timeout in seconds */
    private int $curlConnectTimeout;

    /**
     * @param string $apiUrl    Remote CodeIgniter 3 API endpoint URL
     * @param string $apiKey    API key sent in the X-API-Key header
     * @param string $apiSecret HMAC secret (optional, appended to payload for signing)
     * @param Logger $logger    Logger instance
     * @param int    $curlTimeoutSeconds        Response timeout
     * @param int    $curlConnectTimeoutSeconds Connection timeout
     */
    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $apiSecret,
        Logger $logger,
        int $curlTimeoutSeconds = 15,
        int $curlConnectTimeoutSeconds = 5
    ) {
        $this->apiUrl              = rtrim($apiUrl, '/');
        $this->apiKey              = $apiKey;
        $this->apiSecret           = $apiSecret;
        $this->logger              = $logger;
        $this->curlTimeout         = $curlTimeoutSeconds;
        $this->curlConnectTimeout  = $curlConnectTimeoutSeconds;
        $this->lastSyncFile        = dirname(__DIR__) . '/last_sync.txt';
    }

    /**
     * Prepare the JSON payload that will be sent to the CodeIgniter 3 API.
     *
     * @param array  $logs         Array of attendance records
     * @param string $deviceSerial Device serial number (optional)
     *
     * @return array Associative array with the payload structure
     */
    public function preparePayload(array $logs, string $deviceSerial = ''): array
    {
        $records = [];

        foreach ($logs as $log) {
            $records[] = [
                'uid'         => (int) ($log['uid'] ?? 0),
                'user_id'     => (int) ($log['user_id'] ?? 0),
                'state'       => (int) ($log['state'] ?? 0),
                'record_time' => $log['record_time'] ?? '',
                'type'        => (int) ($log['type'] ?? 0),
            ];
        }

        $payload = [
            'api_key'       => $this->apiKey,
            'device_serial' => $deviceSerial,
            'records'       => $records,
        ];

        // Optionally add HMAC signature for integrity
        if (!empty($this->apiSecret)) {
            $payload['signature'] = $this->generateSignature($payload);
        }

        return $payload;
    }

    /**
     * Send attendance data to the remote CodeIgniter 3 API.
     *
     * @param array  $logs         Array of attendance records
     * @param string $deviceSerial Device serial number (optional)
     *
     * @return array [
     *   'success'       => bool,
     *   'message'       => string,
     *   'synced_records'=> int,
     *   'http_code'     => int,
     *   'response_body' => string|null,
     * ]
     */
    public function syncAttendanceData(array $logs, string $deviceSerial = ''): array
    {
        if (empty($logs)) {
            return [
                'success'        => true,
                'message'        => 'No records to sync',
                'synced_records' => 0,
                'http_code'      => 0,
                'response_body'  => null,
            ];
        }

        $payload = $this->preparePayload($logs, $deviceSerial);

        $this->logger->info("Sending " . count($logs) . " records to {$this->apiUrl}");

        return $this->sendPost($payload);
    }

    /**
     * Perform the actual cURL POST request.
     *
     * @param array $payload The JSON-serialisable payload
     *
     * @return array Response with success flag and details
     */
    private function sendPost(array $payload): array
    {
        $jsonPayload = json_encode($payload);

        if ($jsonPayload === false) {
            $this->logger->error("JSON encoding failed: " . json_last_error_msg());
            return [
                'success'        => false,
                'message'        => 'JSON encoding error: ' . json_last_error_msg(),
                'synced_records' => 0,
                'http_code'      => 0,
                'response_body'  => null,
            ];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
                'X-API-Key: ' . $this->apiKey,
                'User-Agent: ZKTeco-Bridge/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->curlTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->curlConnectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => false,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrno    = curl_errno($ch);

        curl_close($ch);

        // Handle cURL-level errors (timeout, DNS, connection refused, etc.)
        if ($curlErrno !== 0) {
            $this->logger->error("cURL error ({$curlErrno}): {$curlError}");
            return [
                'success'        => false,
                'message'        => "cURL error: {$curlError}",
                'synced_records' => 0,
                'http_code'      => 0,
                'response_body'  => null,
            ];
        }

        // Handle HTTP-level errors
        if ($httpCode >= 400) {
            $this->logger->warn("API returned HTTP {$httpCode}: " . substr($responseBody, 0, 500));
            return [
                'success'        => false,
                'message'        => "API returned HTTP {$httpCode}",
                'synced_records' => 0,
                'http_code'      => $httpCode,
                'response_body'  => $responseBody,
            ];
        }

        // Save the last sync timestamp
        $this->saveLastSync(date('Y-m-d H:i:s'));

        $syncedCount = count($payload['records'] ?? []);
        $this->logger->info("Successfully synced {$syncedCount} records (HTTP {$httpCode})");

        return [
            'success'        => true,
            'message'        => 'Sync completed successfully',
            'synced_records' => $syncedCount,
            'http_code'      => $httpCode,
            'response_body'  => $responseBody,
        ];
    }

    /**
     * Generate an HMAC-SHA256 signature for payload integrity.
     *
     * @param array $payload The payload to sign
     *
     * @return string Hex-encoded HMAC signature
     */
    private function generateSignature(array $payload): string
    {
        $data = json_encode($payload['records'] ?? []);
        return hash_hmac('sha256', $data, $this->apiSecret);
    }

    /**
     * Read the last sync timestamp from the local file.
     *
     * @return string|null Timestamp or null if no sync has ever been performed
     */
    public function getLastSync(): ?string
    {
        if (file_exists($this->lastSyncFile)) {
            $content = trim(file_get_contents($this->lastSyncFile));
            return $content !== '' ? $content : null;
        }
        return null;
    }

    /**
     * Write the last sync timestamp to the local file.
     *
     * @param string $timestamp
     */
    private function saveLastSync(string $timestamp): void
    {
        $dir = dirname($this->lastSyncFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->lastSyncFile, $timestamp, LOCK_EX);
    }
}
