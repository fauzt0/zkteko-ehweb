<?php
/**
 * ZKTeco Device Test Script
 *
 * Run this from the command line to test the connection to your ZKTeco device
 * WITHOUT sending any data to the remote API.
 *
 * Usage:
 *   php test_device.php
 *   php test_device.php --ip 192.168.1.150
 *   php test_device.php --ip 192.168.1.150 --port 4370 --timeout 10
 */

require_once __DIR__ . '/config.php';

use ZKTeco\Logger;
use ZKTeco\ZKTecoCommunication;

// ── Parse CLI options ─────────────────────────────────────────────────────
$options = getopt('', ['ip::', 'port::', 'timeout::', 'help']);
$testIp      = $options['ip']      ?: ZKTECO_HOST;
$testPort    = (int)($options['port']    ?: ZKTECO_PORT);
$testTimeout = (int)($options['timeout'] ?: ZKTECO_TIMEOUT);

if (isset($options['help'])) {
    echo "Usage: php test_device.php [options]\n";
    echo "  --ip IP        Device IP address (default: " . ZKTECO_HOST . ")\n";
    echo "  --port PORT    Device port (default: " . ZKTECO_PORT . ")\n";
    echo "  --timeout SEC  Connection timeout in seconds (default: " . ZKTECO_TIMEOUT . ")\n";
    echo "  --help         Show this help\n";
    exit(0);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────
$logger = new Logger(LOG_DIR, true, true);

echo "\n";
echo str_repeat('═', 60) . "\n";
echo "  ZKTeco MB10-VL — Local Connection Test\n";
echo str_repeat('═', 60) . "\n\n";
echo "  Target:  {$testIp}:{$testPort}\n";
echo "  Timeout: {$testTimeout}s\n";
echo "  Log dir: " . LOG_DIR . "\n";
echo "\n";

// ── 1. Connection test ───────────────────────────────────────────────────
echo "  [1/5] Connecting to device... ";

$comm = new ZKTecoCommunication($testIp, $testPort, $testTimeout, $logger);

$start = microtime(true);
$connected = $comm->connect();
$elapsed = round((microtime(true) - $start) * 1000);

if (!$connected) {
    echo "✗ FAILED ({$elapsed}ms)\n\n";
    echo "  ─── Possible causes ──────────────────────────\n";
    echo "  • Device is powered off\n";
    echo "  • Wrong IP address (check with: ping {$testIp})\n";
    echo "  • Wrong port (default ZKTeco port is 4370)\n";
    echo "  • Firewall blocking TCP port {$testPort}\n";
    echo "  • Device is on a different subnet\n";
    echo "  • Network cable disconnected\n";
    echo "  ───────────────────────────────────────────────\n\n";
    echo "  Quick checks:\n";
    echo "    ping {$testIp}\n";
    echo "    nmap -p {$testPort} {$testIp}\n";
    echo "    telnet {$testIp} {$testPort}\n";
    echo "\n";
    exit(1);
}

echo "✓ OK ({$elapsed}ms)\n";

// ── 2. Device info ────────────────────────────────────────────────────────
echo "  [2/5] Retrieving device info... ";

$info = $comm->getDeviceInfo();

if ($info === null) {
    echo "✗ FAILED\n";
    $comm->disconnect();
    exit(1);
}

echo "✓ OK\n\n";
echo "  ─── Device Information ───────────────────────\n";
printf("  %-20s %s\n", "Serial Number:",  $info['serial_number']   ?? 'N/A');
printf("  %-20s %s\n", "Device Name:",    $info['device_name']     ?? 'N/A');
printf("  %-20s %s\n", "Firmware:",       $info['firmware_version'] ?? 'N/A');
printf("  %-20s %s\n", "Device Time:",    $info['device_time']     ?? 'N/A');
printf("  %-20s %s\n", "IP Address:",     $info['ip_address']      ?? 'N/A');
printf("  %-20s %s\n", "Port:",           $info['port']            ?? 'N/A');
printf("  %-20s %s\n", "Users:",          ($info['user_count'] ?? '?') . ' / ' . ($info['user_capacity'] ?? '?'));
printf("  %-20s %s\n", "Attendance Logs:", ($info['log_count'] ?? '?') . ' / ' . ($info['log_capacity'] ?? '?'));
echo "  ───────────────────────────────────────────────\n\n";

// ── 3. Users ──────────────────────────────────────────────────────────────
echo "  [3/5] Retrieving enrolled users... ";

$users = $comm->getUsers();

if ($users === null) {
    echo "✗ FAILED\n";
    $comm->disconnect();
    exit(1);
}

echo "✓ OK — " . count($users) . " user(s) found\n";

if (!empty($users)) {
    echo "\n  ─── Users (first 10) ──────────────────────────\n";
    printf("  %-8s %-10s %-24s %-6s\n", 'UID', 'User ID', 'Name', 'Role');
    echo "  " . str_repeat('─', 52) . "\n";
    $count = 0;
    foreach ($users as $user) {
        if ($count++ >= 10) {
            echo "  ... and " . (count($users) - 10) . " more\n";
            break;
        }
        printf("  %-8d %-10s %-24s %-6d\n",
            $user['uid'],
            $user['user_id'],
            mb_substr($user['name'], 0, 24),
            $user['role']
        );
    }
    echo "  ───────────────────────────────────────────────\n\n";
}

// ── 4. Attendance logs ────────────────────────────────────────────────────
echo "  [4/5] Retrieving attendance logs... ";

$logs = $comm->getAttendanceLogs();

if ($logs === null) {
    echo "✗ FAILED\n";
    $comm->disconnect();
    exit(1);
}

echo "✓ OK — " . count($logs) . " record(s) found\n";

if (!empty($logs)) {
    echo "\n  ─── Attendance Records (first 10) ─────────────\n";
    printf("  %-8s %-10s %-8s %-22s %-6s\n", 'UID', 'User ID', 'State', 'Timestamp', 'Type');
    echo "  " . str_repeat('─', 58) . "\n";
    $count = 0;
    foreach ($logs as $log) {
        if ($count++ >= 10) {
            echo "  ... and " . (count($logs) - 10) . " more\n";
            break;
        }
        printf("  %-8d %-10d %-8d %-22s %-6d\n",
            $log['uid'],
            $log['user_id'],
            $log['state'],
            $log['record_time'],
            $log['type']
        );
    }
    echo "  ───────────────────────────────────────────────\n";
    echo "\n  Total records on device: " . count($logs) . "\n\n";
} else {
    echo "  (no attendance records on device yet)\n\n";
}

// ── 5. Device time ────────────────────────────────────────────────────────
echo "  [5/5] Reading device clock... ";

$deviceTime = $comm->getDeviceTime();
$localTime  = date('Y-m-d H:i:s');

if ($deviceTime === null) {
    echo "✗ FAILED\n";
} else {
    echo "✓ OK\n";
    echo "\n  ─── Time Comparison ──────────────────────────\n";
    printf("  %-20s %s\n", "Device time:", $deviceTime);
    printf("  %-20s %s\n", "Server time:",  $localTime);
    echo "  ───────────────────────────────────────────────\n\n";
}

// ── Disconnect ────────────────────────────────────────────────────────────
$comm->disconnect();
echo "  Device disconnected cleanly.\n\n";

// ── Summary ───────────────────────────────────────────────────────────────
echo str_repeat('═', 60) . "\n";
echo "  ✅ All local tests passed!\n";
echo "  Your ZKTeco device is reachable and responsive.\n";
echo "\n";
echo "  Next steps:\n";
echo "   1. Edit config.php and set ERP_API_URL to your CI3 endpoint\n";
echo "   2. Run full sync: php -r \"\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['argv'][1]='action=sync'; include 'sync.php';\"\n";
echo "   3. Or via curl: curl -X POST http://YOUR-SERVER/api/zkteco/sync.php \\\n";
echo "                    -H 'Content-Type: application/json' \\\n";
echo "                    -d '{\"action\":\"sync\"}'\n";
echo str_repeat('═', 60) . "\n";
echo "\n";
