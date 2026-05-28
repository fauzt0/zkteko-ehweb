<?php

namespace ZKTeco;

use Mithun\PhpZkteco\Libs\ZKTeco;
use Throwable;

/**
 * ZKTecoCommunication — Wrapper around the 0mithun/php-zkteco library.
 *
 * Provides clean, error-handled methods for connecting to a ZKTeco device
 * and extracting attendance logs, user data, and device information.
 */
class ZKTecoCommunication
{
    private ZKTeco $device;
    private Logger $logger;
    private string $ip;
    private int $port;
    private int $timeout;
    private bool $connected = false;

    /**
     * @param string $ip      Device IP address
     * @param int    $port    Device port (default: 4370)
     * @param int    $timeout Connection timeout in seconds (default: 5)
     * @param Logger $logger  Logger instance
     */
    public function __construct(
        string $ip,
        int $port = 4370,
        int $timeout = 5,
        Logger $logger
    ) {
        $this->ip      = $ip;
        $this->port    = $port;
        $this->timeout = $timeout;
        $this->logger  = $logger;
        $this->device  = $this->createDevice();
    }

    /**
     * Create the underlying ZKTeco library instance.
     *
     * ZKTeco devices use the ZK protocol over UDP on port 4370 by default.
     * Using UDP means the constructor only creates a socket without attempting
     * to connect — actual connection happens in connect() and returns false
     * gracefully if the device is unreachable.
     */
    private function createDevice(): ZKTeco
    {
        try {
            return new ZKTeco(
                $this->ip,
                $this->port,
                false,      // $shouldPing — disabled; handled inside connect()
                $this->timeout,
                0,          // $password — no password by default
                'udp'       // $protocol — ZKTeco uses UDP for the ZK protocol
            );
        } catch (Throwable $e) {
            $this->logger->error("Failed to initialise ZKTeco library for {$this->ip}:{$this->port} — " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Open a connection to the device.
     *
     * @return bool True on success, false on failure
     */
    public function connect(): bool
    {
        try {
            $this->logger->info("Attempting UDP connection to {$this->ip}:{$this->port}");

            $result = $this->device->connect();

            if ($result === true) {
                $this->connected = true;
                $this->logger->info("Connected to {$this->ip}:{$this->port}");
            } else {
                $this->connected = false;
                $this->logger->error("Failed to connect to {$this->ip}:{$this->port}");
            }

            return $result;
        } catch (Throwable $e) {
            $this->connected = false;
            $this->logger->error("Connection exception to {$this->ip}:{$this->port} — " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gracefully disconnect from the device.
     */
    public function disconnect(): void
    {
        try {
            if ($this->connected) {
                $this->device->disconnect();
                $this->logger->info("Disconnected from {$this->ip}:{$this->port}");
            }
        } catch (Throwable $e) {
            $this->logger->warn("Disconnect exception: " . $e->getMessage());
        } finally {
            $this->connected = false;
        }
    }

    /**
     * Check whether the device is currently connected.
     *
     * @return bool
     */
    public function isDeviceConnected(): bool
    {
        // The underlying library doesn't expose a persistent connection check,
        // so we rely on our tracked state and attempt a lightweight ping.
        try {
            $pingResult = $this->device->ping(false); // false = don't throw
            return $pingResult !== false;
        } catch (Throwable $e) {
            $this->logger->debug("Ping check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve device information.
     *
     * @return array|null Associative array with device details, or null on failure
     */
    public function getDeviceInfo(): ?array
    {
        try {
            $serialNumber  = $this->device->serialNumber();
            $deviceName    = $this->device->deviceName();
            $version       = $this->device->version();
            $deviceTime    = $this->device->getTime();
            $memoryInfo    = $this->device->getMemoryInfo();

            $info = [
                'serial_number'  => $serialNumber !== false ? $serialNumber : null,
                'device_name'    => $deviceName !== false ? $deviceName : null,
                'firmware_version' => $version !== false ? $version : null,
                'device_time'    => $deviceTime !== false ? $deviceTime : null,
                'ip_address'     => $this->ip,
                'port'           => $this->port,
                'protocol'       => 'tcp',
            ];

            if ($memoryInfo !== false && is_object($memoryInfo)) {
                $info['user_count']   = $memoryInfo->userCounts ?? 0;
                $info['user_capacity'] = $memoryInfo->userCapacity ?? 0;
                $info['log_count']    = $memoryInfo->logCounts ?? 0;
                $info['log_capacity'] = $memoryInfo->logCapacity ?? 0;
            }

            $this->logger->info("Device info retrieved: " . json_encode($info));
            return $info;
        } catch (Throwable $e) {
            $this->logger->error("Failed to get device info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve attendance logs from the device.
     *
     * Each record contains:
     *   - uid:         int
     *   - user_id:     int
     *   - state:       int (0=check-in, 1=check-out, etc.)
     *   - record_time: string "Y-m-d H:i:s"
     *   - type:        int
     *
     * @return array|null Array of attendance records, or null on failure
     */
    public function getAttendanceLogs(): ?array
    {
        try {
            $this->logger->info("Fetching attendance logs from {$this->ip}");

            $logs = $this->device->getAttendances();

            if (!is_array($logs)) {
                $this->logger->warn("getAttendances returned non-array, treating as empty");
                return [];
            }

            // Normalise keys for consistency
            $normalised = [];
            foreach ($logs as $record) {
                $normalised[] = [
                    'uid'         => (int) ($record['uid'] ?? 0),
                    'user_id'     => (int) ($record['user_id'] ?? 0),
                    'state'       => (int) ($record['state'] ?? 0),
                    'record_time' => $record['record_time'] ?? '',
                    'type'        => (int) ($record['type'] ?? 0),
                ];
            }

            $this->logger->info("Retrieved " . count($normalised) . " attendance records");
            return $normalised;
        } catch (Throwable $e) {
            $this->logger->error("Failed to get attendance logs: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve enrolled users from the device.
     *
     * Each record contains:
     *   - uid:       int
     *   - user_id:   int (string-based ID)
     *   - name:      string
     *   - role:      int
     *   - password:  string
     *   - card_no:   string
     *
     * @return array|null Array of user records, or null on failure
     */
    public function getUsers(): ?array
    {
        try {
            $this->logger->info("Fetching users from {$this->ip}");

            $users = $this->device->getUsers();

            if (!is_array($users)) {
                $this->logger->warn("getUsers returned non-array, treating as empty");
                return [];
            }

            // Re-index as a sequential array
            $normalised = [];
            foreach ($users as $user) {
                $normalised[] = [
                    'uid'      => (int) ($user['uid'] ?? 0),
                    'user_id'  => $user['user_id'] ?? 0,
                    'name'     => $user['name'] ?? '',
                    'role'     => (int) ($user['role'] ?? 0),
                    'password' => $user['password'] ?? '',
                    'card_no'  => $user['card_no'] ?? '',
                ];
            }

            $this->logger->info("Retrieved " . count($normalised) . " users");
            return $normalised;
        } catch (Throwable $e) {
            $this->logger->error("Failed to get users: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the current device time.
     *
     * @return string|null Time in "Y-m-d H:i:s" format, or null on failure
     */
    public function getDeviceTime(): ?string
    {
        try {
            $time = $this->device->getTime();
            return $time !== false ? $time : null;
        } catch (Throwable $e) {
            $this->logger->error("Failed to get device time: " . $e->getMessage());
            return null;
        }
    }
}
