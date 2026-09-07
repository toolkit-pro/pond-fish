<?php
/**
 * সার্ভার হেলথ চেকিং স্ক্রিপ্ট
 * পুকুর মাছ চাষ প্রকল্প - অটোমেটিক সার্ভার মনিটরিং
 * Version: 1.0.0
 * 
 * ব্যবহার: 
 * - ব্রাউজারে: https://pond-fish.onrender.com/server_check.php
 * - CLI: php server_check.php
 */

// ==================== কনফিগারেশন ====================
define('APP_NAME', 'পুকুর মাছ চাষ প্রকল্প');
define('DB_PATH', __DIR__ . '/data/pond.sqlite');
define('LOG_FILE', __DIR__ . '/logs/server_check.log');
define('CHECK_INTERVAL', 60); // সেকেন্ড

// লগ ডিরেক্টরি তৈরি
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// ==================== লগ ফাংশন ====================
function logCheck($message, $status = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] [$status] $message\n";
    file_put_contents(LOG_FILE, $log, FILE_APPEND);
    
    // কনসোলে প্রিন্ট (যদি CLI হয়)
    if (PHP_SAPI === 'cli') {
        echo $log;
    }
}

// ==================== ডেটাবেস চেক ====================
function checkDatabase() {
    try {
        if (!file_exists(DB_PATH)) {
            return ['status' => 'ERROR', 'message' => 'ডেটাবেস ফাইল পাওয়া যায়নি!'];
        }
        
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // টেবিল চেক
        $tables = ['settings', 'batches', 'growth_snapshots', 'feed_logs', 'health_logs', 'expense_logs', 'audit_logs'];
        $missing = [];
        
        foreach ($tables as $table) {
            $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            if (!$result->fetch()) {
                $missing[] = $table;
            }
        }
        
        if (!empty($missing)) {
            return ['status' => 'WARNING', 'message' => 'নিম্নলিখিত টেবিল পাওয়া যায়নি: ' . implode(', ', $missing)];
        }
        
        // ব্যাচ কাউন্ট চেক
        $count = (int)$pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
        
        return [
            'status' => 'OK', 
            'message' => "ডেটাবেস সচল আছে। মোট ব্যাচ: $count টি",
            'data' => [
                'tables' => count($tables),
                'batches' => $count,
                'size' => round(filesize(DB_PATH) / 1024, 2) . ' KB'
            ]
        ];
    } catch (PDOException $e) {
        return ['status' => 'ERROR', 'message' => 'ডেটাবেস ত্রুটি: ' . $e->getMessage()];
    }
}

// ==================== সেশন চেক ====================
function checkSession() {
    try {
        session_start();
        $_SESSION['test'] = 'working';
        $session_id = session_id();
        session_write_close();
        
        return [
            'status' => 'OK',
            'message' => 'সেশন সচল আছে',
            'data' => [
                'session_id' => substr($session_id, 0, 20) . '...',
                'session_status' => session_status()
            ]
        ];
    } catch (Exception $e) {
        return ['status' => 'ERROR', 'message' => 'সেশন ত্রুটি: ' . $e->getMessage()];
    }
}

// ==================== ক্রন জব চেক ====================
function checkCron() {
    $cron_log = __DIR__ . '/logs/cron.log';
    $last_run = null;
    $status = 'WARNING';
    $message = 'ক্রন জব এখনো রান হয়নি';
    
    if (file_exists($cron_log)) {
        $last_run = filemtime($cron_log);
        $hours_ago = round((time() - $last_run) / 3600, 1);
        
        if ($hours_ago < 24) {
            $status = 'OK';
            $message = "ক্রন জব সচল আছে। শেষ রান: $hours_ago ঘন্টা আগে";
        } else {
            $message = "ক্রন জব $hours_ago ঘন্টা আগে রান হয়েছে (এখনো চালু নেই)";
        }
    }
    
    return [
        'status' => $status,
        'message' => $message,
        'data' => [
            'last_run' => $last_run ? date('Y-m-d H:i:s', $last_run) : 'কখনো নয়',
            'log_exists' => file_exists($cron_log),
            'log_size' => file_exists($cron_log) ? round(filesize($cron_log) / 1024, 2) . ' KB' : 'N/A'
        ]
    ];
}

// ==================== পারমিশন চেক ====================
function checkPermissions() {
    $dirs = ['data', 'logs'];
    $issues = [];
    
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            $issues[] = "$dir ডিরেক্টরি নেই";
            continue;
        }
        
        if (!is_writable($path)) {
            $issues[] = "$dir ডিরেক্টরি রাইটেবল নয়";
        }
    }
    
    if (empty($issues)) {
        return ['status' => 'OK', 'message' => 'সব ডিরেক্টরি রাইটেবল'];
    }
    
    return ['status' => 'WARNING', 'message' => implode(', ', $issues)];
}

// ==================== PHP কনফিগ চেক ====================
function checkPHPConfig() {
    $checks = [
        'extension_loaded_pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'extension_loaded_sqlite3' => extension_loaded('sqlite3'),
        'extension_loaded_session' => extension_loaded('session'),
        'ini_get_session_auto_start' => ini_get('session.auto_start'),
        'ini_get_display_errors' => ini_get('display_errors'),
        'php_version' => PHP_VERSION,
    ];
    
    $all_ok = true;
    $issues = [];
    
    if (!$checks['extension_loaded_pdo_sqlite']) {
        $issues[] = 'PDO SQLite এক্সটেনশন নেই';
        $all_ok = false;
    }
    
    if (!$checks['extension_loaded_sqlite3']) {
        $issues[] = 'SQLite3 এক্সটেনশন নেই';
        $all_ok = false;
    }
    
    return [
        'status' => $all_ok ? 'OK' : 'ERROR',
        'message' => $all_ok ? 'PHP কনফিগ সঠিক' : implode(', ', $issues),
        'data' => $checks
    ];
}

// ==================== অটোমেটিক টাস্ক চেক ====================
function checkAutoTasks() {
    $tasks = [];
    
    // 1. মিডনাইট আপডেট চেক
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $last_update = $pdo->query("SELECT last_midnight_update FROM settings WHERE id=1")->fetchColumn();
        $tasks['midnight_update'] = [
            'status' => $last_update ? 'OK' : 'WARNING',
            'message' => $last_update ? "শেষ আপডেট: $last_update" : 'এখনো আপডেট হয়নি'
        ];
    } catch (Exception $e) {
        $tasks['midnight_update'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
    
    // 2. অডিট লগ চেক
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        $last = $pdo->query("SELECT created_at FROM audit_logs ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        $tasks['audit_log'] = [
            'status' => $count > 0 ? 'OK' : 'WARNING',
            'message' => $count > 0 ? "মোট $count টি লগ, শেষ: $last" : 'কোন অডিট লগ নেই'
        ];
    } catch (Exception $e) {
        $tasks['audit_log'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
    
    // 3. গ্রোথ স্ন্যাপশট চেক
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM growth_snapshots")->fetchColumn();
        $last = $pdo->query("SELECT snapshot_date FROM growth_snapshots ORDER BY snapshot_date DESC LIMIT 1")->fetchColumn();
        $tasks['snapshots'] = [
            'status' => $count > 0 ? 'OK' : 'WARNING',
            'message' => $count > 0 ? "মোট $count টি স্ন্যাপশট, শেষ: $last" : 'কোন স্ন্যাপশট নেই'
        ];
    } catch (Exception $e) {
        $tasks['snapshots'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
    
    return $tasks;
}

// ==================== মেইন চেক ফাংশন ====================
function runServerCheck() {
    logCheck('=' . str_repeat('=', 60));
    logCheck('সার্ভার হেলথ চেক শুরু');
    
    $results = [
        'timestamp' => date('Y-m-d H:i:s'),
        'server' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'php_version' => PHP_VERSION,
        'checks' => []
    ];
    
    // সব চেক রান
    $checks = [
        'database' => checkDatabase(),
        'session' => checkSession(),
        'cron' => checkCron(),
        'permissions' => checkPermissions(),
        'php_config' => checkPHPConfig(),
        'auto_tasks' => checkAutoTasks()
    ];
    
    $all_ok = true;
    foreach ($checks as $name => $result) {
        $results['checks'][$name] = $result;
        if ($result['status'] === 'ERROR') {
            $all_ok = false;
        }
        logCheck("[$name] {$result['status']} - {$result['message']}");
    }
    
    $results['overall_status'] = $all_ok ? '✅ সব ঠিক আছে' : '⚠️ কিছু সমস্যা আছে';
    
    logCheck("সার্ভার হেলথ চেক শেষ - {$results['overall_status']}");
    logCheck(str_repeat('=', 70));
    
    return $results;
}

// ==================== আউটপুট জেনারেট ====================
function renderOutput($results) {
    // যদি CLI হয়
    if (PHP_SAPI === 'cli') {
        echo "\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  🐟 সার্ভার হেলথ চেক রিপোর্ট\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  সময়: {$results['timestamp']}\n";
        echo "  সার্ভার: {$results['server']}\n";
        echo "  PHP ভার্সন: {$results['php_version']}\n";
        echo "  স্ট্যাটাস: {$results['overall_status']}\n";
        echo "───────────────────────────────────────────────────────────────\n";
        
        foreach ($results['checks'] as $name => $check) {
            $icon = $check['status'] === 'OK' ? '✅' : ($check['status'] === 'WARNING' ? '⚠️' : '❌');
            echo "  $icon $name: {$check['message']}\n";
            
            if (isset($check['data']) && is_array($check['data'])) {
                foreach ($check['data'] as $key => $value) {
                    if (is_scalar($value)) {
                        echo "     • $key: $value\n";
                    }
                }
            }
        }
        echo "═══════════════════════════════════════════════════════════════\n\n";
        return;
    }
    
    // HTML আউটপুট
    ?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>সার্ভার হেলথ চেক - পুকুর মাছ চাষ প্রকল্প</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Noto Sans Bengali', system-ui, sans-serif;
            background: #f0fdfa;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #0f766e, #14b8a6);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header .meta {
            opacity: 0.9;
            font-size: 14px;
        }
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 14px;
            margin: 10px 0;
        }
        .status-ok {
            background: #d1fae5;
            color: #065f46;
        }
        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #e2e8f0;
        }
        .card.ok {
            border-left-color: #10b981;
        }
        .card.warning {
            border-left-color: #f59e0b;
        }
        .card.error {
            border-left-color: #ef4444;
        }
        .card h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 18px;
        }
        .card .message {
            color: #475569;
            margin-bottom: 8px;
        }
        .card .details {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            font-family: monospace;
            margin-top: 8px;
        }
        .card .details .item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .card .details .item:last-child {
            border-bottom: none;
        }
        .refresh-btn {
            background: #0f766e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 20px;
        }
        .refresh-btn:hover {
            background: #115e59;
            transform: translateY(-2px);
        }
        .footer {
            text-align: center;
            color: #64748b;
            padding: 20px;
            font-size: 13px;
        }
        .auto-refresh {
            color: #64748b;
            font-size: 13px;
            margin-top: 8px;
        }
        @media (max-width: 640px) {
            .header h1 {
                font-size: 22px;
            }
            .card {
                padding: 16px;
            }
            .card .details .item {
                flex-direction: column;
                padding: 6px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🐟 সার্ভার হেলথ চেক</h1>
            <div class="meta">
                সময়: <?= htmlspecialchars($results['timestamp']) ?> | 
                সার্ভার: <?= htmlspecialchars($results['server']) ?> | 
                PHP: <?= htmlspecialchars($results['php_version']) ?>
            </div>
            <div class="status-badge <?= $results['overall_status'] === '✅ সব ঠিক আছে' ? 'status-ok' : 'status-warning' ?>">
                <?= $results['overall_status'] ?>
            </div>
            <div class="auto-refresh">🔄 প্রতি ৬০ সেকেন্ডে অটো-রিফ্রেশ</div>
        </div>

        <?php foreach ($results['checks'] as $name => $check): 
            $icon = $check['status'] === 'OK' ? '✅' : ($check['status'] === 'WARNING' ? '⚠️' : '❌');
            $card_class = strtolower($check['status']);
        ?>
        <div class="card <?= $card_class ?>">
            <h3><?= $icon ?> <?= ucfirst(str_replace('_', ' ', $name)) ?></h3>
            <div class="message"><?= htmlspecialchars($check['message']) ?></div>
            <?php if (isset($check['data']) && is_array($check['data']) && !empty($check['data'])): ?>
            <div class="details">
                <?php foreach ($check['data'] as $key => $value): 
                    if (is_scalar($value)):
                ?>
                <div class="item">
                    <span><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?></span>
                    <span><strong><?= htmlspecialchars((string)$value) ?></strong></span>
                </div>
                <?php endif; endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button class="refresh-btn" onclick="location.reload()">🔄 রিফ্রেশ করুন</button>
        
        <div class="footer">
            পুকুর মাছ চাষ প্রকল্প v6.0.0 | 
            <a href="?page=dashboard" style="color: #0f766e;">ড্যাশবোর্ডে ফিরে যান</a>
        </div>
    </div>

    <script>
        // অটো-রিফ্রেশ প্রতি 60 সেকেন্ডে
        setTimeout(function() {
            location.reload();
        }, 60000);
        
        // কনসোলে লাইভ চেক
        console.log('🐟 সার্ভার হেলথ চেক সক্রিয়');
        console.log('⏰ পরবর্তী রিফ্রেশ: 60 সেকেন্ড');
    </script>
</body>
</html>
    <?php
}

// ==================== রান ====================
$results = runServerCheck();
renderOutput($results);

// যদি CLI হয়, এক্সিট কোড সেট করুন
if (PHP_SAPI === 'cli') {
    $has_error = false;
    foreach ($results['checks'] as $check) {
        if ($check['status'] === 'ERROR') {
            $has_error = true;
            break;
        }
    }
    exit($has_error ? 1 : 0);
}
