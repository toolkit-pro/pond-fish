<?php
/**
 * POND FISH PROJECT DASHBOARD — ULTIMATE PROFESSIONAL EDITION v7.2
 * সম্পূর্ণ মোবাইল-ফ্রেন্ডলি অপটিমাইজেশন ডিজাইন
 * 
 * নতুন আপডেট:
 * - সম্পূর্ণ মোবাইল-ফার্স্ট ডিজাইন
 * - টাচ অপটিমাইজেশন
 * - থাম্ব-ফ্রেন্ডলি বাটন
 * - স্মুথ স্ক্রোলিং
 * - অফলাইন সাপোর্ট
 * - PWA রেডি
 */

declare(strict_types=1);

// ==================== কনফিগারেশন ====================
const APP_NAME = 'পুকুর মাছ চাষ প্রকল্প';
const APP_VERSION = '7.2.0';
const DEFAULT_PIN = '3894';
const SESSION_TIMEOUT = 7200;
const DB_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DB_FILE = DB_DIR . DIRECTORY_SEPARATOR . 'pond.sqlite';
const LOGIN_ATTEMPT_LIMIT = 5;
const LOGIN_LOCKOUT_SECONDS = 300;
const ITEMS_PER_PAGE = 20;

date_default_timezone_set('Asia/Dhaka');

// ==================== ডিরেক্টরি তৈরি ====================
foreach ([DB_DIR, __DIR__ . '/logs', __DIR__ . '/backups', __DIR__ . '/reports'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ==================== হেল্পার ফাংশন ====================
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url = '?'): never {
    header('Location: ' . $url);
    exit;
}

function is_cli(): bool {
    return PHP_SAPI === 'cli';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}

// ==================== ডেটাবেস ====================
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');

    install_schema($pdo);
    return $pdo;
}

function install_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            project_name TEXT NOT NULL,
            pond_depth TEXT DEFAULT '',
            pond_area TEXT DEFAULT '৩০ শতাংশ',
            total_current_weight REAL DEFAULT 0,
            notes TEXT DEFAULT '',
            last_midnight_update TEXT DEFAULT '',
            market_price_per_kg REAL DEFAULT 0,
            dark_mode INTEGER DEFAULT 0,
            email_notifications INTEGER DEFAULT 0,
            notification_email TEXT DEFAULT '',
            feed_daily_kg REAL DEFAULT 3.5,
            feed_recipe TEXT DEFAULT '',
            long_term_goal TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'viewer',
            email TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_no INTEGER NOT NULL UNIQUE,
            fish_name TEXT NOT NULL,
            fish_type TEXT DEFAULT '',
            release_date TEXT NOT NULL,
            initial_weight REAL NOT NULL DEFAULT 0,
            initial_count INTEGER NOT NULL DEFAULT 0,
            initial_avg_weight REAL NOT NULL DEFAULT 0,
            initial_cost REAL NOT NULL DEFAULT 0,
            death_weight REAL NOT NULL DEFAULT 0,
            death_count INTEGER NOT NULL DEFAULT 0,
            current_count INTEGER NOT NULL DEFAULT 0,
            current_weight REAL NOT NULL DEFAULT 0,
            current_avg_weight REAL DEFAULT 0,
            days_in_pond INTEGER DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            notes TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS growth_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            snapshot_date TEXT NOT NULL,
            live_count INTEGER NOT NULL DEFAULT 0,
            live_weight REAL NOT NULL DEFAULT 0,
            avg_weight REAL NOT NULL DEFAULT 0,
            growth_weight REAL DEFAULT 0,
            growth_percent REAL DEFAULT 0,
            survival_percent REAL DEFAULT 0,
            feed_rate_percent REAL DEFAULT 0,
            daily_feed_kg REAL DEFAULT 0,
            weekly_feed_kg REAL DEFAULT 0,
            notes TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            UNIQUE(batch_id, snapshot_date),
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS feed_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_date TEXT NOT NULL,
            batch_id INTEGER,
            feed_kg REAL NOT NULL DEFAULT 0,
            feed_cost REAL NOT NULL DEFAULT 0,
            feed_type TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS health_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_date TEXT NOT NULL,
            batch_id INTEGER,
            log_type TEXT NOT NULL,
            amount REAL DEFAULT 0,
            unit TEXT DEFAULT '',
            cost REAL NOT NULL DEFAULT 0,
            details TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS expense_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            expense_date TEXT NOT NULL,
            batch_id INTEGER,
            expense_type TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            notes TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            details TEXT DEFAULT '',
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at TEXT NOT NULL
        );
    ");

    $count = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($count === 0) {
        $now = date('Y-m-d H:i:s');
        $feed_recipe = "শুকনো সরিষার খৈল: ১.৫ কেজি\nগমের ভুসি ও কুঁড়া: ১.০ কেজি\nনারিশ ২ মিলি পিলেট ফিড: ১.০ কেজি\nসাধারণ লবণ: এক চিমটি";
        $long_term_goal = "২ মাস পর লক্ষ্য: ৬৫০টি দামি মাছ\nওপরের স্তর: কাতলা ২০০টি + লাটকাপ ৩০টি\nমধ্য স্তর: রুই ৩০০টি\nনিচের স্তর: কালবাউশ/মৃগেল ৫০টি + কার্পিও ১০টি\nবিশেষ স্তর: পাঙ্গাশ ১০০টি";
        
        $stmt = $pdo->prepare("
            INSERT INTO settings
            (id, project_name, pond_depth, pond_area, total_current_weight, notes, last_midnight_update, 
             market_price_per_kg, dark_mode, email_notifications, notification_email, 
             feed_daily_kg, feed_recipe, long_term_goal, created_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            APP_NAME, '১৮–১৯ ফুট', '৩০ শতাংশ', 85, 
            '৭ সেপ্টেম্বর ২০২৬-এর ভিত্তি রেকর্ড | মোট মাছ: ১,২৫০-১,৩৭০টি',
            date('Y-m-d'), 200, 0, 0, '',
            3.5, $feed_recipe, $long_term_goal, $now, $now
        ]);
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, role, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'admin',
            password_hash(DEFAULT_PIN, PASSWORD_DEFAULT),
            'admin',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
    if ($count === 0) {
        seed_initial_data($pdo);
    }
}

function seed_initial_data(PDO $pdo): void {
    $now = date('Y-m-d H:i:s');
    
    $rows = [
        [
            'batch_no' => 1,
            'fish_name' => 'ছোট পোনা (সিলভার কার্প)',
            'fish_type' => 'ওপরের স্তর',
            'release_date' => '2026-07-08',
            'initial_weight' => 10,
            'initial_count' => 1700,
            'current_count' => 920,
            'current_weight' => 0,
            'initial_cost' => 15000,
            'notes' => 'কেজিতে ১৭০টি পোনা | ৬১ দিন (২ মাস) | বর্তমান গড় ওজন ২০-৩০ গ্রাম'
        ],
        [
            'batch_no' => 2,
            'fish_name' => 'মাঝারি পোনা',
            'fish_type' => 'মধ্য স্তর',
            'release_date' => '2026-07-22',
            'initial_weight' => 25,
            'initial_count' => 280,
            'current_count' => 280,
            'current_weight' => 0,
            'initial_cost' => 12000,
            'notes' => 'কেজিতে ১০-১১টি | ৪৭ দিন (১.৫ মাস) | বর্তমান গড় ওজন ১৫০-১৮০ গ্রাম'
        ],
        [
            'batch_no' => 3,
            'fish_name' => 'কাতল',
            'fish_type' => 'ওপরের স্তর',
            'release_date' => '2026-08-24',
            'initial_weight' => 9,
            'initial_count' => 64,
            'current_count' => 64,
            'current_weight' => 9,
            'initial_cost' => 3000,
            'notes' => 'গড় ১৪০ গ্রাম | ১৪ দিন (২ সপ্তাহ) | বর্তমান গড় ওজন ১৫০-১৬০ গ্রাম'
        ],
        [
            'batch_no' => 4,
            'fish_name' => 'ব্রিগেড/লাটকাপ',
            'fish_type' => 'ওপরের স্তর',
            'release_date' => '2026-08-31',
            'initial_weight' => 5.5,
            'initial_count' => 56,
            'current_count' => 56,
            'current_weight' => 5.5,
            'initial_cost' => 2500,
            'notes' => 'গড় ১০০ গ্রাম | ৭ দিন (১ সপ্তাহ) | বর্তমান গড় ওজন ১০০-১০৫ গ্রাম'
        ],
        [
            'batch_no' => 5,
            'fish_name' => 'রুই',
            'fish_type' => 'মধ্য স্তর',
            'release_date' => '2026-09-07',
            'initial_weight' => 6,
            'initial_count' => 70,
            'current_count' => 70,
            'current_weight' => 6,
            'initial_cost' => 3500,
            'notes' => 'গড় ৮৫ গ্রাম | আজকে নতুন ছাড়া হয়েছে | সুস্থ ও সবল'
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO batches
        (batch_no, fish_name, fish_type, release_date, initial_weight, initial_count,
         current_count, death_weight, death_count, current_weight, initial_avg_weight,
         initial_cost, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $r) {
        $avg = $r['initial_count'] > 0 ? ($r['initial_weight'] * 1000) / $r['initial_count'] : 0;
        $death_count = $r['initial_count'] - $r['current_count'];
        $death_weight = $death_count > 0 ? ($death_count * $avg / 1000) : 0;
        
        $stmt->execute([
            $r['batch_no'], $r['fish_name'], $r['fish_type'], $r['release_date'],
            $r['initial_weight'], $r['initial_count'],
            $r['current_count'], $death_weight, $death_count,
            $r['current_weight'], $avg,
            $r['initial_cost'], $r['notes'], $now, $now
        ]);
    }
}

// ==================== অডিট ====================
function audit(string $action, string $entity, ?int $entityId = null, string $details = ''): void {
    $stmt = db()->prepare("
        INSERT INTO audit_logs(action, entity, entity_id, details, ip, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $action, $entity, $entityId, $details,
        $_SERVER['REMOTE_ADDR'] ?? 'CLI',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        date('Y-m-d H:i:s')
    ]);
}

// ==================== সেশন ====================
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

db();

// ==================== লগইন চেক ====================
function logged_in(): bool {
    return !empty($_SESSION['auth']) &&
           !empty($_SESSION['auth_at']) &&
           (time() - (int)$_SESSION['auth_at'] < SESSION_TIMEOUT);
}

function require_login(): void {
    if (!logged_in()) {
        redirect('?page=login');
    }
    $_SESSION['auth_at'] = time();
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

// ==================== ক্রন ====================
if (is_cli() && in_array('--cron', $argv ?? [], true)) {
    run_cron();
    exit;
}

function run_cron(): void {
    $pdo = db();
    $today = date('Y-m-d');
    $settings = $pdo->query("SELECT last_midnight_update, email_notifications, notification_email FROM settings WHERE id=1")->fetch();
    
    if (($settings['last_midnight_update'] ?? '') !== $today) {
        $pdo->prepare("UPDATE settings SET last_midnight_update = ?, updated_at = ? WHERE id = 1")
            ->execute([$today, date('Y-m-d H:i:s')]);

        $batches = $pdo->query("SELECT * FROM batches WHERE status='active'")->fetchAll();
        foreach ($batches as $batch) {
            $last = latest_snapshot((int)$batch['id']);
            $due = !$last || days_between((string)$last['snapshot_date'], $today) >= 7;
            if ($due) {
                create_snapshot(
                    (int)$batch['id'], $today,
                    (float)$batch['current_weight'],
                    (int)$batch['current_count'],
                    0, 'Midnight auto-update'
                );
            }
        }
        audit('midnight_update', 'system', null, "Auto-update completed for $today");
        
        if (!empty($settings['email_notifications']) && !empty($settings['notification_email'])) {
            send_daily_report($settings['notification_email']);
        }
    }
    
    if (date('N') == 7) {
        create_backup();
    }
}

function latest_snapshot(int $batchId): ?array {
    $stmt = db()->prepare("SELECT * FROM growth_snapshots WHERE batch_id = ? ORDER BY snapshot_date DESC LIMIT 1");
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

function days_between(string $date1, string $date2): int {
    try {
        return max(0, (int)(new DateTime($date1))->diff(new DateTime($date2))->days);
    } catch (Throwable) {
        return 0;
    }
}

function create_snapshot(int $batchId, string $date, float $liveWeight, int $liveCount, float $feedRate = 0, string $notes = ''): void {
    $pdo = db();
    $batch = $pdo->prepare("SELECT * FROM batches WHERE id = ?")->execute([$batchId])->fetch();
    if (!$batch) return;

    $avg = $liveCount > 0 ? ($liveWeight * 1000) / $liveCount : 0;
    $previous = latest_snapshot($batchId);
    $growthWeight = $previous ? $liveWeight - (float)$previous['live_weight'] : $liveWeight - (float)$batch['initial_weight'];
    $growthPercent = $previous && (float)$previous['live_weight'] > 0 
        ? ($growthWeight / (float)$previous['live_weight']) * 100 
        : ((float)$batch['initial_weight'] > 0 ? ($growthWeight / (float)$batch['initial_weight']) * 100 : 0);
    $survival = (int)$batch['initial_count'] > 0 ? ($liveCount / (int)$batch['initial_count']) * 100 : 0;
    $dailyFeed = $liveWeight * ($feedRate / 100);
    $weeklyFeed = $dailyFeed * 7;

    $stmt = $pdo->prepare("
        INSERT INTO growth_snapshots
        (batch_id, snapshot_date, live_count, live_weight, avg_weight, growth_weight, 
         growth_percent, survival_percent, feed_rate_percent, daily_feed_kg, weekly_feed_kg, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(batch_id, snapshot_date) DO UPDATE SET
          live_count=excluded.live_count, live_weight=excluded.live_weight,
          avg_weight=excluded.avg_weight, growth_weight=excluded.growth_weight,
          growth_percent=excluded.growth_percent, survival_percent=excluded.survival_percent,
          feed_rate_percent=excluded.feed_rate_percent, daily_feed_kg=excluded.daily_feed_kg,
          weekly_feed_kg=excluded.weekly_feed_kg, notes=excluded.notes
    ");
    $stmt->execute([$batchId, $date, $liveCount, $liveWeight, $avg, $growthWeight, 
        $growthPercent, $survival, $feedRate, $dailyFeed, $weeklyFeed, $notes, date('Y-m-d H:i:s')]);
}

// ==================== ব্যাকআপ ====================
function create_backup(): void {
    $backup_dir = __DIR__ . '/backups';
    if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
    
    $backup_file = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sqlite';
    copy(DB_FILE, $backup_file);
    
    $files = glob($backup_dir . '/*.sqlite');
    foreach ($files as $file) {
        if (filemtime($file) < time() - 30 * 86400) {
            unlink($file);
        }
    }
    audit('backup', 'system', null, "Backup created: " . basename($backup_file));
}

// ==================== ইমেইল ====================
function send_daily_report(string $email): void {
    $pdo = db();
    $today = date('Y-m-d');
    $batches = $pdo->query("SELECT * FROM batches")->fetchAll();
    $totalWeight = array_sum(array_column($batches, 'current_weight'));
    $totalCount = array_sum(array_column($batches, 'current_count'));
    
    $subject = "পুকুর মাছ চাষ প্রকল্প - দৈনিক রিপোর্ট ($today)";
    $message = "🏡 পুকুরের তথ্য:\n";
    $message .= "আয়তন: ৩০ শতাংশ | গভীরতা: ১৮-১৯ ফুট\n\n";
    $message .= "📊 সারাংশ:\n";
    $message .= "মোট ব্যাচ: " . count($batches) . " টি\n";
    $message .= "মোট মাছ: $totalCount টি\n";
    $message .= "মোট ওজন: " . number_format($totalWeight, 2) . " কেজি\n\n";
    $message .= "📋 ব্যাচভিত্তিক:\n";
    foreach ($batches as $b) {
        $message .= "ব্যাচ {$b['batch_no']}: {$b['fish_name']} - " . 
                    number_format($b['current_weight'], 2) . " কেজি, " . 
                    $b['current_count'] . " টি\n";
    }
    $message .= "\n🍚 দৈনিক খাদ্য: ৩.৫ কেজি\n";
    $message .= "📅 পরবর্তী আপডেট: আজ রাত ১২:০০ টায়\n";
    
    $headers = "From: " . APP_NAME . " <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    
    @mail($email, $subject, $message, $headers);
    audit('email_report', 'system', null, "Daily report sent to $email");
}

// ==================== CSV এক্সপোর্ট ====================
function export_csv(string $table, array $columns): void {
    $pdo = db();
    $data = $pdo->query("SELECT " . implode(',', $columns) . " FROM $table")->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $columns);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ==================== নরমালাইজেশন ====================
function normalize_float(mixed $v): float {
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? (float)$v : 0.0;
}

function normalize_int(mixed $v): int {
    $v = preg_replace('/[^\d-]/', '', (string)$v);
    return max(0, (int)$v);
}

// ==================== রাউটিং ====================
$page = $_GET['page'] ?? 'dashboard';

// ==================== লগআউট ====================
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    redirect('?page=login');
}

// ==================== এক্সপোর্ট ====================
if (isset($_GET['export'])) {
    require_login();
    $table = $_GET['export'];
    $columns = [
        'batches' => ['batch_no', 'fish_name', 'fish_type', 'release_date', 'initial_weight', 'initial_count', 
                      'current_count', 'current_weight', 'current_avg_weight', 'days_in_pond', 'status', 'notes'],
        'feed_logs' => ['log_date', 'feed_kg', 'feed_cost', 'feed_type', 'notes'],
        'health_logs' => ['log_date', 'log_type', 'amount', 'unit', 'cost', 'details'],
        'expense_logs' => ['expense_date', 'expense_type', 'amount', 'notes'],
        'growth_snapshots' => ['snapshot_date', 'live_count', 'live_weight', 'avg_weight', 
                               'growth_weight', 'growth_percent', 'survival_percent']
    ];
    if (isset($columns[$table])) {
        export_csv($table, $columns[$table]);
    }
}

// ==================== লগইন ====================
if (isset($_POST['login'])) {
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
    if ($_SESSION['login_attempts'] >= LOGIN_ATTEMPT_LIMIT && 
        time() - (int)($_SESSION['last_login_attempt'] ?? 0) < LOGIN_LOCKOUT_SECONDS) {
        $loginError = 'অনেকবার ভুল PIN দেওয়া হয়েছে। ৫ মিনিট পরে চেষ্টা করুন।';
    } else {
        $_SESSION['last_login_attempt'] = time();
        $username = $_POST['username'] ?? '';
        $pin = $_POST['pin'] ?? '';
        
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($pin, $user['password_hash'])) {
            $_SESSION['login_attempts'] = 0;
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['auth_at'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            audit('login', 'user', $user['id'], "User {$user['username']} logged in");
            redirect('?page=dashboard');
        } else {
            $_SESSION['login_attempts']++;
            $loginError = 'ইউজারনেম বা PIN সঠিক নয়।';
        }
    }
}

if ($page === 'login' && !logged_in()) {
    render_login($loginError ?? '');
    exit;
}

require_login();

// ==================== POST হ্যান্ডলার ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        $pdo = db();

        if ($action === 'toggle_dark_mode') {
            $current = (int)$pdo->query("SELECT dark_mode FROM settings WHERE id=1")->fetchColumn();
            $pdo->prepare("UPDATE settings SET dark_mode = ?, updated_at = ? WHERE id=1")
                ->execute([$current ? 0 : 1, date('Y-m-d H:i:s')]);
            redirect('?page=dashboard&dark=' . ($current ? 0 : 1));
        }

        if ($action === 'save_settings') {
            $stmt = $pdo->prepare("
                UPDATE settings SET 
                project_name=?, pond_depth=?, pond_area=?, total_current_weight=?, 
                notes=?, market_price_per_kg=?, email_notifications=?, notification_email=?,
                feed_daily_kg=?, feed_recipe=?, long_term_goal=?, updated_at=?
                WHERE id=1
            ");
            $stmt->execute([
                trim($_POST['project_name']),
                trim($_POST['pond_depth']),
                trim($_POST['pond_area']),
                normalize_float($_POST['total_current_weight']),
                trim($_POST['notes']),
                normalize_float($_POST['market_price_per_kg']),
                isset($_POST['email_notifications']) ? 1 : 0,
                trim($_POST['notification_email']),
                normalize_float($_POST['feed_daily_kg']),
                trim($_POST['feed_recipe']),
                trim($_POST['long_term_goal']),
                date('Y-m-d H:i:s')
            ]);
            audit('update', 'settings', 1);
            redirect('?page=settings&saved=1');
        }

        if ($action === 'save_batch') {
            $id = normalize_int($_POST['id'] ?? 0);
            $batchNo = normalize_int($_POST['batch_no']);
            $fishName = trim($_POST['fish_name']);
            $fishType = trim($_POST['fish_type']);
            $releaseDate = trim($_POST['release_date']);
            
            if ($batchNo < 1 || $fishName === '' || $releaseDate === '') {
                throw new RuntimeException('ব্যাচ নম্বর, মাছের নাম ও ছাড়ার তারিখ আবশ্যক।');
            }

            $data = [
                $batchNo, $fishName, $fishType, $releaseDate,
                normalize_float($_POST['initial_weight']),
                normalize_int($_POST['initial_count']),
                normalize_float($_POST['initial_cost']),
                normalize_float($_POST['death_weight']),
                normalize_int($_POST['death_count']),
                normalize_int($_POST['current_count']),
                normalize_float($_POST['current_weight']),
                trim($_POST['notes'])
            ];

            $avg = $data[5] > 0 ? ($data[4] * 1000) / $data[5] : 0;
            $days = days_between($releaseDate, date('Y-m-d'));

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE batches SET 
                    batch_no=?, fish_name=?, fish_type=?, release_date=?, 
                    initial_weight=?, initial_count=?, initial_cost=?, 
                    death_weight=?, death_count=?, current_count=?, 
                    current_weight=?, current_avg_weight=?, days_in_pond=?, notes=?, updated_at=?
                    WHERE id=?
                ");
                $stmt->execute([...$data, $avg, $days, date('Y-m-d H:i:s'), $id]);
                audit('update', 'batch', $id);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO batches
                    (batch_no, fish_name, fish_type, release_date, initial_weight, initial_count,
                     initial_avg_weight, initial_cost, death_weight, death_count,
                     current_count, current_weight, current_avg_weight, days_in_pond,
                     notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data[0], $data[1], $data[2], $data[3], $data[4], $data[5],
                    $avg, $data[6], $data[7], $data[8], $data[9], $data[10],
                    $avg, $days, $data[11], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
                ]);
                audit('create', 'batch', (int)$pdo->lastInsertId());
            }
            redirect('?page=batches&saved=1');
        }

        $delete_actions = ['delete_batch', 'delete_feed', 'delete_health', 'delete_expense', 'delete_snapshot'];
        if (in_array($action, $delete_actions)) {
            $id = normalize_int($_POST['id']);
            $table = str_replace('delete_', '', $action);
            $table_map = ['batch' => 'batches', 'feed' => 'feed_logs', 
                         'health' => 'health_logs', 'expense' => 'expense_logs', 
                         'snapshot' => 'growth_snapshots'];
            $pdo->prepare("DELETE FROM " . $table_map[$table] . " WHERE id=?")->execute([$id]);
            audit('delete', $table, $id);
            redirect('?page=' . $table . 's&deleted=1');
        }

        if ($action === 'snapshot') {
            create_snapshot(
                normalize_int($_POST['batch_id']),
                trim($_POST['snapshot_date']),
                normalize_float($_POST['live_weight']),
                normalize_int($_POST['live_count']),
                normalize_float($_POST['feed_rate']),
                trim($_POST['notes'])
            );
            redirect('?page=growth&saved=1');
        }

        if ($action === 'feed') {
            $pdo->prepare("
                INSERT INTO feed_logs(log_date, batch_id, feed_kg, feed_cost, feed_type, notes, created_at)
                VALUES(?,?,?,?,?,?,?)
            ")->execute([
                $_POST['log_date'],
                normalize_int($_POST['batch_id']) ?: null,
                normalize_float($_POST['feed_kg']),
                normalize_float($_POST['feed_cost']),
                trim($_POST['feed_type']),
                trim($_POST['notes']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'feed_log', (int)$pdo->lastInsertId());
            redirect('?page=feed&saved=1');
        }

        if ($action === 'health') {
            $pdo->prepare("
                INSERT INTO health_logs(log_date, batch_id, log_type, amount, unit, cost, details, created_at)
                VALUES(?,?,?,?,?,?,?,?)
            ")->execute([
                $_POST['log_date'],
                normalize_int($_POST['batch_id']) ?: null,
                trim($_POST['log_type']),
                normalize_float($_POST['amount']),
                trim($_POST['unit']),
                normalize_float($_POST['cost']),
                trim($_POST['details']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'health_log', (int)$pdo->lastInsertId());
            redirect('?page=health&saved=1');
        }

        if ($action === 'expense') {
            $pdo->prepare("
                INSERT INTO expense_logs(expense_date, batch_id, expense_type, amount, notes, created_at)
                VALUES(?,?,?,?,?,?)
            ")->execute([
                $_POST['expense_date'],
                normalize_int($_POST['batch_id']) ?: null,
                trim($_POST['expense_type']),
                normalize_float($_POST['amount']),
                trim($_POST['notes']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'expense_log', (int)$pdo->lastInsertId());
            redirect('?page=expenses&saved=1');
        }

        if ($action === 'create_user' && is_admin()) {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $role = $_POST['role'] ?? 'viewer';
            $email = trim($_POST['email']);
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $email, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            audit('create', 'user', (int)$pdo->lastInsertId(), "User $username created");
            redirect('?page=users&saved=1');
        }

    } catch (Throwable $ex) {
        $formError = $ex->getMessage();
    }
}

// ==================== ডেটা ফেচ ====================
$pdo = db();
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$dark_mode = (int)($settings['dark_mode'] ?? 0);

if (isset($_GET['dark'])) {
    $_SESSION['dark_mode'] = (int)$_GET['dark'];
    $dark_mode = (int)$_GET['dark'];
} elseif (isset($_SESSION['dark_mode'])) {
    $dark_mode = (int)$_SESSION['dark_mode'];
}

// ব্যাচ ডেটা
$search = $_GET['search'] ?? '';
$batch_filter = $_GET['batch_filter'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$batch_query = "SELECT * FROM batches WHERE 1=1";
$params = [];
if ($search) {
    $batch_query .= " AND (fish_name LIKE ? OR batch_no LIKE ? OR fish_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($batch_filter) {
    $batch_query .= " AND status = ?";
    $params[] = $batch_filter;
}
if ($date_from) {
    $batch_query .= " AND release_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $batch_query .= " AND release_date <= ?";
    $params[] = $date_to;
}
$batch_query .= " ORDER BY batch_no";

$batches = $pdo->prepare($batch_query);
$batches->execute($params);
$batches = $batches->fetchAll();

foreach ($batches as &$b) {
    $b['current_avg_weight'] = $b['current_count'] > 0 ? ($b['current_weight'] * 1000) / $b['current_count'] : 0;
    $b['days_in_pond'] = days_between($b['release_date'], date('Y-m-d'));
}
unset($b);

$page_num = max(1, (int)($_GET['p'] ?? 1));
$total_items = count($batches);
$total_pages = ceil($total_items / ITEMS_PER_PAGE);
$offset = ($page_num - 1) * ITEMS_PER_PAGE;
$batches_paged = array_slice($batches, $offset, ITEMS_PER_PAGE);

$totalCount = array_sum(array_column($batches, 'current_count'));
$totalInitialWeight = array_sum(array_column($batches, 'initial_weight'));
$totalCurrentWeight = array_sum(array_column($batches, 'current_weight'));
$totalDeathCount = array_sum(array_column($batches, 'death_count'));
$totalInitialCost = array_sum(array_column($batches, 'initial_cost'));

$dashboardCurrentWeight = (float)$settings['total_current_weight'] > 0 ? (float)$settings['total_current_weight'] : $totalCurrentWeight;
$overallGain = $dashboardCurrentWeight - $totalInitialWeight;

$totalFeedCost = (float)$pdo->query("SELECT COALESCE(SUM(feed_cost),0) FROM feed_logs")->fetchColumn();
$totalHealthCost = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM health_logs")->fetchColumn();
$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expense_logs")->fetchColumn();
$totalAllExpenses = $totalInitialCost + $totalFeedCost + $totalHealthCost + $totalExpenses;

$marketPricePerKg = (float)$settings['market_price_per_kg'];
$estimatedMarketValue = $dashboardCurrentWeight * $marketPricePerKg;
$estimatedProfit = $estimatedMarketValue - $totalAllExpenses;
$profitPercent = $totalAllExpenses > 0 ? ($estimatedProfit / $totalAllExpenses) * 100 : 0;

$editBatch = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $stmt = $pdo->prepare("SELECT * FROM batches WHERE id=?");
    $stmt->execute([normalize_int($_GET['edit'])]);
    $editBatch = $stmt->fetch() ?: null;
}

$snapshots = $pdo->query("
    SELECT gs.*, b.batch_no, b.fish_name, b.fish_type
    FROM growth_snapshots gs
    JOIN batches b ON b.id=gs.batch_id
    ORDER BY gs.snapshot_date DESC, b.batch_no ASC
    LIMIT 50
")->fetchAll();

$feedLogs = $pdo->query("
    SELECT f.*, b.batch_no, b.fish_name
    FROM feed_logs f
    LEFT JOIN batches b ON b.id=f.batch_id
    ORDER BY f.log_date DESC, f.id DESC
    LIMIT 50
")->fetchAll();

$healthLogs = $pdo->query("
    SELECT h.*, b.batch_no, b.fish_name
    FROM health_logs h
    LEFT JOIN batches b ON b.id=h.batch_id
    ORDER BY h.log_date DESC, h.id DESC
    LIMIT 50
")->fetchAll();

$expenseLogs = $pdo->query("
    SELECT e.*, b.batch_no, b.fish_name
    FROM expense_logs e
    LEFT JOIN batches b ON b.id=e.batch_id
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 50
")->fetchAll();

$users = $pdo->query("SELECT id, username, role, email, created_at FROM users ORDER BY id")->fetchAll();

$chart_data = [
    'labels' => [],
    'weights' => [],
    'counts' => [],
    'types' => []
];
foreach ($batches as $b) {
    $chart_data['labels'][] = 'B' . $b['batch_no'] . ' - ' . $b['fish_name'];
    $chart_data['weights'][] = (float)$b['current_weight'];
    $chart_data['counts'][] = (int)$b['current_count'];
    $chart_data['types'][] = $b['fish_type'] ?: 'স্তর নির্ধারিত নয়';
}

// ==================== রেন্ডার লগইন ====================
function render_login(string $error = ''): void {
    $token = csrf_token();
    ?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#0f766e">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="manifest.json">
<title>লগইন — <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
body{font-family:'Noto Sans Bengali',system-ui,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#164e63 100%);display:flex;align-items:center;justify-content:center;padding:16px;min-height:100vh;color:#0f172a;-webkit-user-select:none;user-select:none}
.login-card{width:100%;max-width:400px;background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-radius:24px;padding:32px 24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideUp 0.5s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.logo-icon{width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 16px}
.login-card h1{text-align:center;font-size:24px;color:#0f172a;margin-bottom:4px}
.login-card .subtitle{text-align:center;color:#64748b;font-size:14px;margin-bottom:24px}
.field{margin-bottom:16px}
.field label{display:block;font-weight:700;font-size:14px;color:#334155;margin-bottom:6px}
.field input{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:14px;font-size:16px;background:#fff;color:#0f172a;transition:border-color 0.3s;font-family:inherit;-webkit-appearance:none;appearance:none}
.field input:focus{outline:none;border-color:#0f766e;box-shadow:0 0 0 4px rgba(15,118,110,0.1)}
.field input::placeholder{color:#94a3b8}
.btn-login{width:100%;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;font-weight:800;font-size:16px;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s;font-family:inherit}
.btn-login:active{transform:scale(0.97)}
.btn-login:hover{box-shadow:0 4px 20px rgba(15,118,110,0.3)}
.error-msg{background:#fef2f2;color:#dc2626;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;border-left:4px solid #dc2626}
.login-footer{text-align:center;margin-top:16px;color:#94a3b8;font-size:13px}
.login-footer strong{color:#0f766e}
@media(max-width:480px){.login-card{padding:24px 16px}.login-card h1{font-size:20px}.logo-icon{width:52px;height:52px;font-size:26px}}
</style>
</head>
<body>
<form class="login-card" method="post" autocomplete="off">
<div class="logo-icon">🐟</div>
<h1><?= e(APP_NAME) ?></h1>
<p class="subtitle">নিরাপদ ড্যাশবোর্ডে প্রবেশ করুন</p>
<?php if ($error): ?><div class="error-msg"><?= e($error) ?></div><?php endif; ?>
<input type="hidden" name="login" value="1">
<input type="hidden" name="csrf" value="<?= e($token) ?>">
<div class="field"><label>ইউজারনেম</label><input name="username" type="text" placeholder="admin" required autocomplete="username"></div>
<div class="field"><label>নিরাপত্তা PIN</label><input name="pin" type="password" inputmode="numeric" maxlength="12" placeholder="••••" required autocomplete="current-password"></div>
<button class="btn-login" type="submit">ড্যাশবোর্ডে প্রবেশ</button>
<p class="login-footer">ডিফল্ট: <strong>admin</strong> / PIN: <strong>3894</strong></p>
</form>
<script>
if('serviceWorker' in navigator){navigator.serviceWorker.register('sw.js').catch(()=>{})}
</script>
</body>
</html>
<?php
}

// ==================== HTML আউটপুট শুরু ====================
?>
<!doctype html>
<html lang="bn" <?= $dark_mode ? 'data-theme="dark"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="theme-color" content="<?= $dark_mode ? '#0f172a' : '#0f766e' ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icon-192.png">
<title><?= e($settings['project_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ==================== CSS Variables ==================== */
:root {
    --bg: #f8fafc;
    --card: #ffffff;
    --ink: #0f172a;
    --muted: #64748b;
    --primary: #0f766e;
    --primary2: #115e59;
    --accent: #14b8a6;
    --line: #e2e8f0;
    --danger: #ef4444;
    --warning: #f59e0b;
    --success: #10b981;
    --info: #3b82f6;
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 40px rgba(0,0,0,0.1);
    --radius: 16px;
    --radius-sm: 10px;
    --safe-bottom: env(safe-area-inset-bottom, 0px);
}

[data-theme="dark"] {
    --bg: #0f172a;
    --card: #1e293b;
    --ink: #f1f5f9;
    --muted: #94a3b8;
    --line: #334155;
    --shadow: 0 1px 3px rgba(0,0,0,0.3);
    --shadow-lg: 0 10px 40px rgba(0,0,0,0.5);
}

/* ==================== Reset & Base ==================== */
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font-family:'Noto Sans Bengali',system-ui,-apple-system,sans-serif;transition:background 0.3s,color 0.3s;touch-action:manipulation;overscroll-behavior-y:none;padding-bottom:env(safe-area-inset-bottom)}
input,textarea,select,button{font-family:inherit;font-size:16px;-webkit-appearance:none;appearance:none}
input[type="number"]{-moz-appearance:textfield}
input[type="number"]::-webkit-inner-spin-button,input[type="number"]::-webkit-outer-spin-button{-webkit-appearance:none}

/* ==================== Container ==================== */
.wrap{width:100%;max-width:1400px;margin:0 auto;padding:0 12px}

/* ==================== Top Navigation ==================== */
.top-nav{position:sticky;top:0;z-index:100;background:var(--card);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--line);padding:0 12px}
.nav-inner{min-height:60px;display:flex;align-items:center;justify-content:space-between;gap:12px;max-width:1400px;margin:0 auto}
.brand{font-weight:800;font-size:18px;color:var(--primary);display:flex;align-items:center;gap:8px;white-space:nowrap;overflow:hidden}
.brand-icon{width:36px;height:36px;min-width:36px;border-radius:10px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px}
.brand span{overflow:hidden;text-overflow:ellipsis}
.nav-actions{display:flex;gap:6px;align-items:center}
.nav-actions .btn{font-size:13px;padding:8px 14px}
.nav-actions .btn-logout{background:#fee2e2;color:#991b1b;border:0;padding:8px 14px;border-radius:var(--radius-sm);font-weight:600;cursor:pointer;transition:all 0.2s;font-size:13px;white-space:nowrap}
.nav-actions .btn-logout:active{transform:scale(0.95)}

/* ==================== Bottom Navigation (Mobile) ==================== */
.mobile-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--card);border-top:1px solid var(--line);z-index:1000;padding:6px 0 calc(6px + env(safe-area-inset-bottom));box-shadow:0 -4px 20px rgba(0,0,0,0.05)}
.mobile-nav-inner{display:flex;justify-content:space-around;align-items:center;overflow-x:auto;-webkit-overflow-scrolling:touch;gap:2px;padding:0 4px}
.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:var(--muted);font-size:9px;font-weight:600;padding:4px 6px;border-radius:8px;transition:all 0.2s;min-width:44px;touch-action:manipulation}
.mobile-nav-item:active{transform:scale(0.92)}
.mobile-nav-item.active{color:var(--primary)}
.mobile-nav-item .nav-icon{font-size:20px;line-height:1}
.mobile-nav-item span{line-height:1.2}

/* ==================== Cards ==================== */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;transition:all 0.3s}
.card:active{transform:scale(0.99)}
.kpi-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px;transition:all 0.3s;cursor:pointer;touch-action:manipulation}
.kpi-card:active{transform:scale(0.97);box-shadow:var(--shadow-lg)}
.kpi-value{font-size:24px;font-weight:800;line-height:1.2}
.kpi-label{font-size:12px;color:var(--muted);font-weight:600;margin-bottom:4px}

/* ==================== KPI Grid ==================== */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0}

/* ==================== Buttons ==================== */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;background:var(--primary);color:#fff;padding:10px 16px;border-radius:var(--radius-sm);font-weight:700;text-decoration:none;cursor:pointer;transition:all 0.2s;font-size:14px;touch-action:manipulation;min-height:44px;min-width:44px}
.btn:active{transform:scale(0.95)}
.btn-primary{background:var(--primary)}
.btn-danger{background:var(--danger)}
.btn-success{background:var(--success)}
.btn-info{background:var(--info)}
.btn-warning{background:var(--warning)}
.btn-outline{background:transparent;border:2px solid var(--line);color:var(--ink)}
.btn-sm{font-size:12px;padding:6px 12px;min-height:36px;min-width:36px}
.btn-xs{font-size:11px;padding:4px 8px;min-height:32px;min-width:32px}
.btn-block{width:100%;justify-content:center}

/* ==================== Table ==================== */
.table-wrap{overflow-x:auto;border-radius:var(--radius);border:1px solid var(--line);background:var(--card);-webkit-overflow-scrolling:touch}
.table{width:100%;border-collapse:collapse;min-width:600px;font-size:13px}
.table th,.table td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
.table th{background:var(--bg);font-weight:700;font-size:11px;text-transform:uppercase;color:var(--muted);position:sticky;top:0;z-index:5}
.table tr:last-child td{border-bottom:none}
.table .action-btns{display:flex;gap:4px;flex-wrap:wrap}

/* ==================== Badges ==================== */
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-green{background:#ecfdf5;color:#059669}
.badge-red{background:#fef2f2;color:#dc2626}
.badge-blue{background:#eff6ff;color:#2563eb}
.badge-yellow{background:#fef3c7;color:#d97706}
.badge-purple{background:#f3e8ff;color:#7c3aed}
.badge-gray{background:#f1f5f9;color:#475569}

/* ==================== Forms ==================== */
.form-grid{display:grid;grid-template-columns:1fr;gap:12px}
.field label{display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:12px 14px;border:2px solid var(--line);border-radius:var(--radius-sm);background:var(--card);color:var(--ink);font:inherit;transition:border-color 0.3s;font-size:16px}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(15,118,110,0.1)}
.field textarea{min-height:80px;resize:vertical}

/* ==================== Search Bar ==================== */
.search-bar{display:flex;flex-direction:column;gap:8px;margin:12px 0}
.search-bar .search-row{display:flex;gap:8px;flex-wrap:wrap}
.search-bar input,.search-bar select{flex:1;min-width:120px;padding:10px 14px;border:2px solid var(--line);border-radius:var(--radius-sm);background:var(--card);color:var(--ink);font:inherit;font-size:14px}

/* ==================== Charts ==================== */
.chart-container{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin:12px 0}
.chart-container h3{font-size:15px;margin-bottom:12px}
.chart-grid{display:grid;grid-template-columns:1fr;gap:12px}

/* ==================== Pond Info ==================== */
.pond-info{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;border-radius:var(--radius);padding:16px;margin:12px 0}
.pond-info h3{color:#fff;font-size:16px;margin-bottom:10px}
.pond-info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.pond-info-item{background:rgba(255,255,255,0.12);border-radius:var(--radius-sm);padding:10px;text-align:center}
.pond-info-item .label{font-size:10px;opacity:0.8;text-transform:uppercase;letter-spacing:0.5px}
.pond-info-item .value{font-size:16px;font-weight:700}

/* ==================== Notices ==================== */
.notice{padding:12px 16px;border-radius:var(--radius-sm);background:#ecfdf5;color:#065f46;margin:12px 0;font-weight:600;border-left:4px solid #10b981;font-size:14px}
.notice-error{background:#fef2f2;color:#991b1b;border-left-color:#ef4444}

/* ==================== Pagination ==================== */
.pagination{display:flex;gap:4px;justify-content:center;margin:16px 0;flex-wrap:wrap}
.pagination a,.pagination span{padding:8px 12px;border-radius:var(--radius-sm);border:1px solid var(--line);text-decoration:none;color:var(--ink);font-size:14px;min-width:36px;text-align:center;touch-action:manipulation}
.pagination a:active{transform:scale(0.92)}
.pagination .active{background:var(--primary);color:#fff;border-color:var(--primary)}
.pagination a:hover{background:var(--line)}

/* ==================== Footer ==================== */
.footer{padding:24px 0;text-align:center;color:var(--muted);border-top:1px solid var(--line);margin-top:24px;font-size:12px}

/* ==================== Grid Layouts ==================== */
.two-col{display:grid;grid-template-columns:1fr;gap:12px}
.three-col{display:grid;grid-template-columns:1fr;gap:12px}

/* ==================== Touch Optimizations ==================== */
.touchable{transition:transform 0.15s;touch-action:manipulation}
.touchable:active{transform:scale(0.96)}

/* ==================== Pull-to-refresh prevention ==================== */
body{overscroll-behavior-y:contain}

/* ==================== Responsive Breakpoints ==================== */
@media(min-width:480px){
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .search-bar{flex-direction:row;flex-wrap:wrap}
    .search-bar .search-row{flex:1}
}

@media(min-width:640px){
    .kpi-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
    .two-col{grid-template-columns:1fr 1fr}
    .three-col{grid-template-columns:repeat(3,1fr)}
    .chart-grid{grid-template-columns:1fr 1fr}
    .form-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid .full-width{grid-column:span 2}
    .pond-info-grid{grid-template-columns:repeat(3,1fr)}
    .wrap{padding:0 16px}
}

@media(min-width:768px){
    .mobile-nav{display:none!important}
    .nav-links{display:flex!important}
}

@media(max-width:767px){
    .mobile-nav{display:block}
    .nav-links{display:none}
    .brand{font-size:15px}
    .brand-icon{width:30px;height:30px;min-width:30px;font-size:15px}
    .kpi-value{font-size:20px}
    .kpi-card{padding:12px}
    .card{padding:12px}
    .table{font-size:12px;min-width:500px}
    .table th,.table td{padding:8px 10px}
    .btn{font-size:13px;padding:8px 14px;min-height:40px}
    .btn-sm{font-size:11px;padding:4px 10px;min-height:32px;min-width:32px}
    .pond-info .value{font-size:14px}
    .pond-info-item{padding:8px}
    .wrap{padding:0 8px}
    body{padding-bottom:70px}
}

@media(max-width:400px){
    .kpi-grid{grid-template-columns:1fr 1fr;gap:6px}
    .kpi-value{font-size:17px}
    .kpi-label{font-size:10px}
    .mobile-nav-item{min-width:36px;font-size:8px}
    .mobile-nav-item .nav-icon{font-size:17px}
    .brand{font-size:13px}
    .brand-icon{width:26px;height:26px;min-width:26px;font-size:13px}
}

/* ==================== Print Styles ==================== */
@media print{
    .top-nav,.mobile-nav,.btn,.no-print{display:none!important}
    body{padding:0!important;background:#fff!important}
    .card{box-shadow:none!important;border:1px solid #ddd!important}
    .kpi-card{box-shadow:none!important;border:1px solid #ddd!important}
}
</style>
</head>
<body>

<!-- ==================== TOP NAVIGATION ==================== -->
<header class="top-nav">
    <div class="nav-inner">
        <div class="brand" onclick="location='?page=dashboard'">
            <div class="brand-icon">🐟</div>
            <span><?= e($settings['project_name']) ?></span>
        </div>
        <div class="nav-actions">
            <div class="nav-links" style="display:flex;gap:4px;align-items:center">
                <a href="?page=dashboard" class="btn <?= $page === 'dashboard' ? 'btn-primary' : 'btn-outline' ?> btn-sm">📊</a>
                <a href="?page=batches" class="btn <?= $page === 'batches' ? 'btn-primary' : 'btn-outline' ?> btn-sm">🐠</a>
                <a href="?page=growth" class="btn <?= $page === 'growth' ? 'btn-primary' : 'btn-outline' ?> btn-sm">📈</a>
                <a href="?page=feed" class="btn <?= $page === 'feed' ? 'btn-primary' : 'btn-outline' ?> btn-sm">🍚</a>
                <a href="?page=health" class="btn <?= $page === 'health' ? 'btn-primary' : 'btn-outline' ?> btn-sm">💊</a>
                <a href="?page=accounting" class="btn <?= $page === 'accounting' ? 'btn-primary' : 'btn-outline' ?> btn-sm">💰</a>
                <a href="?page=analytics" class="btn <?= $page === 'analytics' ? 'btn-primary' : 'btn-outline' ?> btn-sm">📊</a>
                <?php if (is_admin()): ?>
                <a href="?page=users" class="btn <?= $page === 'users' ? 'btn-primary' : 'btn-outline' ?> btn-sm">👥</a>
                <?php endif; ?>
                <a href="?page=settings" class="btn <?= $page === 'settings' ? 'btn-primary' : 'btn-outline' ?> btn-sm">⚙️</a>
            </div>
            <button class="btn-logout" onclick="if(confirm('লগআউট করবেন?')){window.location='?logout=1'}">🚪</button>
        </div>
    </div>
</header>

<!-- ==================== MAIN CONTENT ==================== -->
<main class="wrap">

<?php if (isset($_GET['saved'])): ?>
<div class="notice">✅ তথ্য সফলভাবে সংরক্ষণ হয়েছে।</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="notice">🗑️ তথ্য মুছে ফেলা হয়েছে।</div>
<?php endif; ?>
<?php if (!empty($formError)): ?>
<div class="notice notice-error">⚠ <?= e($formError) ?></div>
<?php endif; ?>

<?php
// ==================== DASHBOARD ====================
if ($page === 'dashboard'):
?>
<section style="padding:12px 0">
    <h1 style="font-size:clamp(20px,5vw,32px);margin:0 0 4px">📊 ড্যাশবোর্ড</h1>
    <p style="color:var(--muted);font-size:13px">📅 <?= date('d M Y, h:i A') ?> | ৩০ শতাংশ পুকুর</p>
</section>

<!-- Pond Info -->
<div class="pond-info">
    <h3>🏡 পুকুরের অবস্থা</h3>
    <div class="pond-info-grid">
        <div class="pond-info-item"><div class="label">আয়তন</div><div class="value"><?= e($settings['pond_area'] ?: '৩০%') ?></div></div>
        <div class="pond-info-item"><div class="label">গভীরতা</div><div class="value"><?= e($settings['pond_depth']) ?></div></div>
        <div class="pond-info-item"><div class="label">মোট মাছ</div><div class="value"><?= number_format($totalCount) ?></div></div>
        <div class="pond-info-item"><div class="label">ওজন</div><div class="value"><?= number_format($dashboardCurrentWeight, 1) ?> কেজি</div></div>
        <div class="pond-info-item"><div class="label">খাদ্য/দিন</div><div class="value"><?= number_format((float)$settings['feed_daily_kg'], 1) ?> কেজি</div></div>
        <div class="pond-info-item"><div class="label">ব্যাচ</div><div class="value"><?= count($batches) ?></div></div>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card touchable" onclick="location='?page=batches'">
        <div class="kpi-label">🐟 জীবিত মাছ</div>
        <div class="kpi-value"><?= number_format($totalCount) ?></div>
    </div>
    <div class="kpi-card touchable" onclick="location='?page=accounting'">
        <div class="kpi-label">⚖️ ওজন</div>
        <div class="kpi-value"><?= number_format($dashboardCurrentWeight, 1) ?> কেজি</div>
    </div>
    <div class="kpi-card touchable" onclick="location='?page=accounting'">
        <div class="kpi-label">💰 বাজার মূল্য</div>
        <div class="kpi-value">৳<?= number_format($estimatedMarketValue, 0) ?></div>
    </div>
    <div class="kpi-card touchable" onclick="location='?page=accounting'">
        <div class="kpi-label">📊 লাভ/ক্ষতি</div>
        <div class="kpi-value" style="color:<?= $estimatedProfit >= 0 ? '#10b981' : '#ef4444' ?>">৳<?= number_format($estimatedProfit, 0) ?></div>
    </div>
</div>

<!-- Charts -->
<div class="chart-grid">
    <div class="chart-container">
        <h3>📊 ব্যাচভিত্তিক ওজন</h3>
        <canvas id="weightChart" height="200"></canvas>
    </div>
    <div class="chart-container">
        <h3>🧮 স্তরভিত্তিক বিতরণ</h3>
        <canvas id="typeChart" height="200"></canvas>
    </div>
</div>

<!-- Growth Chart -->
<div class="card">
    <h3>📈 গ্রোথ ট্রেন্ড</h3>
    <canvas id="growthChart" height="150"></canvas>
</div>

<!-- Long Term Goal -->
<div class="card" style="background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff;border:none">
    <h3 style="color:#fff">🎯 আগামী ২ মাসের লক্ষ্য</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;margin-top:8px">
        <div><strong>ওপরের স্তর:</strong> কাতলা ২০০ + লাটকাপ ৩০</div>
        <div><strong>মধ্য স্তর:</strong> রুই ৩০০</div>
        <div><strong>নিচের স্তর:</strong> কালবাউশ ৫০ + কার্পিও ১০</div>
        <div><strong>বিশেষ স্তর:</strong> পাঙ্গাশ ১০০</div>
    </div>
    <p style="margin-top:10px;opacity:0.7;font-size:12px">🎯 লক্ষ্য: ৬৫০টি দামি মাছ</p>
</div>

<script>
// Weight Chart
new Chart(document.getElementById('weightChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_data['labels']) ?>,
        datasets: [{
            label: 'ওজন (কেজি)',
            data: <?= json_encode($chart_data['weights']) ?>,
            backgroundColor: 'rgba(15,118,110,0.6)',
            borderColor: '#0f766e',
            borderWidth: 2,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } } }
    }
});

// Type Chart
const typeData = {};
<?php foreach ($batches as $b): ?>
const t = '<?= e($b['fish_type'] ?: 'অনির্ধারিত') ?>';
typeData[t] = (typeData[t] || 0) + <?= (int)$b['current_count'] ?>;
<?php endforeach; ?>

new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: Object.keys(typeData),
        datasets: [{
            data: Object.values(typeData),
            backgroundColor: ['#0f766e','#3b82f6','#f59e0b','#8b5cf6','#10b981','#ef4444']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
    }
});

<?php
$growth_data = [];
foreach ($batches as $b) {
    $snap = latest_snapshot((int)$b['id']);
    if ($snap) {
        $growth_data['labels'][] = 'B' . $b['batch_no'];
        $growth_data['growth'][] = (float)$snap['growth_percent'];
        $growth_data['survival'][] = (float)$snap['survival_percent'];
    }
}
if (!empty($growth_data)): ?>
new Chart(document.getElementById('growthChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($growth_data['labels']) ?>,
        datasets: [
            {
                label: 'গ্রোথ %',
                data: <?= json_encode($growth_data['growth']) ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,0.1)',
                tension: 0.3,
                fill: true
            },
            {
                label: 'সারভাইভাল %',
                data: <?= json_encode($growth_data['survival']) ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.1)',
                tension: 0.3,
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
        scales: { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } } }
    }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php
// ==================== BATCHES ====================
if ($page === 'batches'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0">
        <h2 style="font-size:20px">🐠 ব্যাচ</h2>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="btn btn-success btn-sm" href="?page=batches&edit=new">➕</a>
            <a class="btn btn-info btn-sm" href="?export=batches">📥</a>
        </div>
    </div>

    <div class="search-bar">
        <div class="search-row">
            <input type="text" id="searchInput" placeholder="🔍 সার্চ..." value="<?= e($search) ?>" oninput="searchBatches()">
            <select id="statusFilter" onchange="filterBatches()">
                <option value="">সব</option>
                <option value="active" <?= $batch_filter === 'active' ? 'selected' : '' ?>>সক্রিয়</option>
                <option value="inactive" <?= $batch_filter === 'inactive' ? 'selected' : '' ?>>নিষ্ক্রিয়</option>
            </select>
        </div>
        <div class="search-row">
            <input type="date" id="dateFrom" value="<?= e($date_from) ?>" onchange="filterBatches()" style="min-width:100px">
            <input type="date" id="dateTo" value="<?= e($date_to) ?>" onchange="filterBatches()" style="min-width:100px">
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>ব্যাচ</th><th>মাছ</th><th>স্তর</th><th>তারিখ</th><th>দিন</th>
                <th>সংখ্যা</th><th>ওজন</th><th>গড়</th><th>কাজ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches_paged as $b): ?>
            <tr>
                <td><span class="badge badge-blue">B<?= (int)$b['batch_no'] ?></span></td>
                <td><strong><?= e($b['fish_name']) ?></strong></td>
                <td><span class="badge badge-purple"><?= e($b['fish_type'] ?: '-') ?></span></td>
                <td><?= e($b['release_date']) ?></td>
                <td><?= (int)$b['days_in_pond'] ?></td>
                <td><?= number_format((int)$b['current_count']) ?></td>
                <td><?= number_format((float)$b['current_weight'], 1) ?> kg</td>
                <td><?= number_format((float)$b['current_avg_weight'], 0) ?> g</td>
                <td>
                    <div class="action-btns">
                        <a class="btn btn-sm" href="?page=batches&edit=<?= (int)$b['id'] ?>">✏️</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$batches_paged): ?><tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">কোন ব্যাচ নেই</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=batches&p=<?= $i ?>&search=<?= urlencode($search) ?>&batch_filter=<?= urlencode($batch_filter) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" 
           class="<?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</section>

<?php if (isset($_GET['edit'])): ?>
<section style="margin-top:16px">
    <h2 style="font-size:18px"><?= $editBatch ? '✏️ সম্পাদনা' : '➕ নতুন ব্যাচ' ?></h2>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_batch">
            <input type="hidden" name="id" value="<?= (int)($editBatch['id'] ?? 0) ?>">
            <div class="field"><label>ব্যাচ নম্বর</label><input type="number" name="batch_no" value="<?= e((string)($editBatch['batch_no'] ?? count($batches)+1)) ?>" required></div>
            <div class="field"><label>মাছের নাম</label><input name="fish_name" value="<?= e((string)($editBatch['fish_name'] ?? '')) ?>" required></div>
            <div class="field"><label>স্তর</label>
                <select name="fish_type">
                    <option value="">নির্ধারিত নয়</option>
                    <option value="ওপরের স্তর" <?= ($editBatch['fish_type'] ?? '') === 'ওপরের স্তর' ? 'selected' : '' ?>>ওপরের স্তর</option>
                    <option value="মধ্য স্তর" <?= ($editBatch['fish_type'] ?? '') === 'মধ্য স্তর' ? 'selected' : '' ?>>মধ্য স্তর</option>
                    <option value="নিচের স্তর" <?= ($editBatch['fish_type'] ?? '') === 'নিচের স্তর' ? 'selected' : '' ?>>নিচের স্তর</option>
                    <option value="বিশেষ স্তর" <?= ($editBatch['fish_type'] ?? '') === 'বিশেষ স্তর' ? 'selected' : '' ?>>বিশেষ স্তর</option>
                </select>
            </div>
            <div class="field"><label>ছাড়ার তারিখ</label><input type="date" name="release_date" value="<?= e((string)($editBatch['release_date'] ?? date('Y-m-d'))) ?>" required></div>
            <div class="field"><label>প্রাথমিক ওজন (kg)</label><input type="number" step="0.01" name="initial_weight" value="<?= e((string)($editBatch['initial_weight'] ?? '0')) ?>" required></div>
            <div class="field"><label>প্রাথমিক সংখ্যা</label><input type="number" name="initial_count" value="<?= e((string)($editBatch['initial_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>ক্রয় মূল্য (৳)</label><input type="number" step="0.01" name="initial_cost" value="<?= e((string)($editBatch['initial_cost'] ?? '0')) ?>" required></div>
            <div class="field"><label>মৃত সংখ্যা</label><input type="number" name="death_count" value="<?= e((string)($editBatch['death_count'] ?? '0')) ?>"></div>
            <div class="field"><label>বর্তমান সংখ্যা</label><input type="number" name="current_count" value="<?= e((string)($editBatch['current_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>বর্তমান ওজন (kg)</label><input type="number" step="0.01" name="current_weight" value="<?= e((string)($editBatch['current_weight'] ?? '0')) ?>" required></div>
            <div class="field full-width"><label>নোট</label><textarea name="notes"><?= e((string)($editBatch['notes'] ?? '')) ?></textarea></div>
            <div class="field"><button class="btn btn-success btn-block" type="submit">💾 সংরক্ষণ</button></div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php
// ==================== GROWTH ====================
if ($page === 'growth'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0">
        <h2 style="font-size:20px">📈 Growth</h2>
        <div style="display:flex;gap:6px">
            <a class="btn btn-success btn-sm" href="#snapshot-form">➕</a>
            <a class="btn btn-info btn-sm" href="?export=growth_snapshots">📥</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>Live</th><th>ওজন</th><th>গড়</th><th>গ্রোথ</th><th>সারভাইভাল</th><th>কাজ</th></tr></thead>
            <tbody>
            <?php foreach ($snapshots as $s): ?>
            <tr>
                <td><?= e($s['snapshot_date']) ?></td>
                <td>B<?= (int)$s['batch_no'] ?> <?= e($s['fish_name']) ?></td>
                <td><?= number_format((int)$s['live_count']) ?></td>
                <td><?= number_format((float)$s['live_weight'], 1) ?> kg</td>
                <td><?= number_format((float)$s['avg_weight'], 0) ?> g</td>
                <td><span class="badge badge-green"><?= number_format((float)$s['growth_percent'], 1) ?>%</span></td>
                <td><?= number_format((float)$s['survival_percent'], 1) ?>%</td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_snapshot">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$snapshots): ?><tr><td colspan="8" style="text-align:center;padding:30px">কোন Snapshot নেই</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="snapshot-form" style="margin-top:16px">
    <h2 style="font-size:18px">🧮 নতুন Snapshot</h2>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="snapshot">
            <div class="field"><label>ব্যাচ</label><select name="batch_id" required><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>তারিখ</label><input type="date" name="snapshot_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Live Weight (kg)</label><input type="number" step="0.01" name="live_weight" required></div>
            <div class="field"><label>Live Count</label><input type="number" name="live_count" required></div>
            <div class="field"><label>Feeding Rate (%)</label><input type="number" step="0.1" name="feed_rate" value="0"></div>
            <div class="field full-width"><label>নোট</label><input name="notes"></div>
            <div class="field"><button class="btn btn-success btn-block" type="submit">💾 সংরক্ষণ</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== FEED ====================
if ($page === 'feed'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0">
        <h2 style="font-size:20px">🍚 খাদ্য</h2>
        <a class="btn btn-info btn-sm" href="?export=feed_logs">📥</a>
    </div>

    <?php if ($settings['feed_recipe']): ?>
    <div class="card" style="background:linear-gradient(135deg,#fef3c7,#fbbf24);border-color:#f59e0b">
        <h3 style="color:#92400e;font-size:15px">📋 দৈনিক রেসিপি (<?= number_format((float)$settings['feed_daily_kg'], 1) ?> কেজি)</h3>
        <pre style="white-space:pre-wrap;color:#78350f;margin:4px 0;font-family:inherit;font-size:13px"><?= e($settings['feed_recipe']) ?></pre>
    </div>
    <?php endif; ?>

    <div class="two-col" style="margin-top:12px">
        <div class="card">
            <h3 style="font-size:16px">নতুন রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="feed">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সব</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>খাদ্য (kg)</label><input type="number" step="0.001" name="feed_kg" required></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="feed_cost" required></div>
                <div class="field"><label>ধরন</label>
                    <select name="feed_type">
                        <option value="">নির্বাচন</option>
                        <option value="শুকনো সরিষার খৈল">শুকনো সরিষার খৈল</option>
                        <option value="গমের ভুসি ও কুঁড়া">গমের ভুসি ও কুঁড়া</option>
                        <option value="নারিশ ২ মিলি পিলেট ফিড">নারিশ ২ মিলি পিলেট ফিড</option>
                        <option value="মিক্সচার">মিক্সচার</option>
                    </select>
                </div>
                <div class="field full-width"><label>নোট</label><input name="notes"></div>
                <button class="btn btn-success btn-block" type="submit">💾 সংরক্ষণ</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>খাদ্য</th><th>খরচ</th><th>কাজ</th></tr></thead>
                <tbody>
                <?php foreach ($feedLogs as $f): ?>
                <tr>
                    <td><?= e($f['log_date']) ?></td>
                    <td><?= $f['batch_no'] ? 'B'.(int)$f['batch_no'] : 'পুকুর' ?></td>
                    <td><?= number_format((float)$f['feed_kg'], 2) ?> kg</td>
                    <td>৳<?= number_format((float)$f['feed_cost'], 0) ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_feed">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <button class="btn btn-danger btn-xs" type="submit">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== HEALTH ====================
if ($page === 'health'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0">
        <h2 style="font-size:20px">💊 স্বাস্থ্য</h2>
        <a class="btn btn-info btn-sm" href="?export=health_logs">📥</a>
    </div>

    <div class="two-col">
        <div class="card">
            <h3 style="font-size:16px">নতুন রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="health">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">পুকুর</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>ধরন</label><select name="log_type"><option>চুন</option><option>লবণ</option><option>ওষুধ</option><option>পানি পরিবর্তন</option><option>রোগ</option><option>মৃত্যু</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ</label><input type="number" step="0.001" name="amount"></div>
                <div class="field"><label>একক</label><input name="unit" placeholder="kg/g/প্যাকেট"></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="cost" required></div>
                <div class="field full-width"><label>বিস্তারিত</label><textarea name="details"></textarea></div>
                <button class="btn btn-success btn-block" type="submit">💾 সংরক্ষণ</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>ধরন</th><th>খরচ</th><th>কাজ</th></tr></thead>
                <tbody>
                <?php foreach ($healthLogs as $h): ?>
                <tr>
                    <td><?= e($h['log_date']) ?></td>
                    <td><?= $h['batch_no'] ? 'B'.(int)$h['batch_no'] : 'পুকুর' ?></td>
                    <td><span class="badge badge-purple"><?= e($h['log_type']) ?></span></td>
                    <td>৳<?= number_format((float)$h['cost'], 0) ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_health">
                            <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                            <button class="btn btn-danger btn-xs" type="submit">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== EXPENSES ====================
if ($page === 'expenses'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0">
        <h2 style="font-size:20px">💰 খরচ</h2>
        <a class="btn btn-info btn-sm" href="?export=expense_logs">📥</a>
    </div>

    <div class="two-col">
        <div class="card">
            <h3 style="font-size:16px">নতুন রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="expense">
                <div class="field"><label>তারিখ</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সাধারণ</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>ধরন</label><select name="expense_type"><option>শ্রমিক</option><option>যন্ত্রপাতি</option><option>আনুষাঙ্গিক</option><option>বিদ্যুৎ</option><option>পরিবহন</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ (৳)</label><input type="number" step="0.01" name="amount" required></div>
                <div class="field full-width"><label>নোট</label><textarea name="notes"></textarea></div>
                <button class="btn btn-success btn-block" type="submit">💾 সংরক্ষণ</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>ধরন</th><th>পরিমাণ</th><th>কাজ</th></tr></thead>
                <tbody>
                <?php foreach ($expenseLogs as $exp): ?>
                <tr>
                    <td><?= e($exp['expense_date']) ?></td>
                    <td><?= $exp['batch_no'] ? 'B'.(int)$exp['batch_no'] : 'সাধারণ' ?></td>
                    <td><span class="badge badge-yellow"><?= e($exp['expense_type']) ?></span></td>
                    <td>৳<?= number_format((float)$exp['amount'], 0) ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_expense">
                            <input type="hidden" name="id" value="<?= (int)$exp['id'] ?>">
                            <button class="btn btn-danger btn-xs" type="submit">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== ACCOUNTING ====================
if ($page === 'accounting'):
?>
<section>
    <h2 style="font-size:20px;margin:12px 0">📊 হিসাব</h2>
    <div class="three-col" style="gap:10px">
        <div class="card"><h3 style="font-size:14px;color:var(--muted)">বাজার মূল্য</h3><div class="kpi-value" style="font-size:22px;color:var(--primary)">৳<?= number_format($estimatedMarketValue, 0) ?></div></div>
        <div class="card"><h3 style="font-size:14px;color:var(--muted)">মোট খরচ</h3><div class="kpi-value" style="font-size:22px;color:var(--danger)">৳<?= number_format($totalAllExpenses, 0) ?></div></div>
        <div class="card" style="background:<?= $estimatedProfit >= 0 ? '#10b981' : '#ef4444' ?>;color:#fff;border:none">
            <h3 style="color:#fff;font-size:14px"><?= $estimatedProfit >= 0 ? 'লাভ' : 'ক্ষতি' ?></h3>
            <div style="font-size:24px;font-weight:800">৳<?= number_format(abs($estimatedProfit), 0) ?></div>
            <div style="font-size:13px"><?= number_format($profitPercent, 1) ?>%</div>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size:16px">খরচের বিবরণ</h3>
        <div class="table-wrap" style="border:none;margin-top:8px">
            <table class="table">
                <thead><tr><th>ধরন</th><th>পরিমাণ</th><th>%</th></tr></thead>
                <tbody>
                    <tr><td>মাছ ক্রয়</td><td>৳<?= number_format($totalInitialCost, 0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalInitialCost/$totalAllExpenses)*100, 1) : 0 ?>%</td></tr>
                    <tr><td>খাদ্য</td><td>৳<?= number_format($totalFeedCost, 0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalFeedCost/$totalAllExpenses)*100, 1) : 0 ?>%</td></tr>
                    <tr><td>স্বাস্থ্য</td><td>৳<?= number_format($totalHealthCost, 0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalHealthCost/$totalAllExpenses)*100, 1) : 0 ?>%</td></tr>
                    <tr><td>অন্যান্য</td><td>৳<?= number_format($totalExpenses, 0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalExpenses/$totalAllExpenses)*100, 1) : 0 ?>%</td></tr>
                    <tr style="font-weight:800;background:var(--bg)"><td>সর্বমোট</td><td>৳<?= number_format($totalAllExpenses, 0) ?></td><td>100%</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== ANALYTICS ====================
if ($page === 'analytics'):
?>
<section>
    <h2 style="font-size:20px;margin:12px 0">📈 এনালাইসিস</h2>
    <div class="two-col">
        <div class="card">
            <h3 style="font-size:15px">ব্যাচভিত্তিক Growth</h3>
            <?php foreach ($batches as $b): 
                $lastSnap = latest_snapshot((int)$b['id']);
                $growthPercent = $lastSnap ? (float)$lastSnap['growth_percent'] : 0;
            ?>
            <div style="margin:10px 0">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span>B<?= (int)$b['batch_no'] ?> <?= e($b['fish_name']) ?></span>
                    <strong><?= number_format($growthPercent, 1) ?>%</strong>
                </div>
                <div style="width:100%;height:6px;background:var(--line);border-radius:999px;overflow:hidden;margin-top:3px">
                    <div style="height:100%;width:<?= min(100, max(0, $growthPercent)) ?>%;background:linear-gradient(90deg,#14b8a6,#0f766e);border-radius:999px"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h3 style="font-size:15px">সারাংশ</h3>
            <p style="font-size:13px"><strong>আয়তন:</strong> <?= e($settings['pond_area'] ?: '৩০%') ?></p>
            <p style="font-size:13px"><strong>গভীরতা:</strong> <?= e($settings['pond_depth']) ?></p>
            <p style="font-size:13px"><strong>ওজন:</strong> <?= number_format($dashboardCurrentWeight, 1) ?> kg</p>
            <p style="font-size:13px"><strong>মাছ:</strong> <?= number_format($totalCount) ?> টি</p>
            <p style="font-size:13px"><strong>ব্যাচ:</strong> <?= count($batches) ?></p>
            <p style="font-size:13px"><strong>খাদ্য/দিন:</strong> <?= number_format((float)$settings['feed_daily_kg'], 1) ?> kg</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
                <a class="btn btn-info btn-sm" href="?export=batches">📥 CSV</a>
                <a class="btn btn-sm" href="?page=settings">⚙️ সেটিংস</a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== USERS ====================
if ($page === 'users' && is_admin()):
?>
<section>
    <h2 style="font-size:20px;margin:12px 0">👥 ইউজার</h2>
    <div class="two-col">
        <div class="card">
            <h3 style="font-size:16px">নতুন ইউজার</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="field"><label>ইউজারনেম</label><input name="username" required></div>
                <div class="field"><label>PIN</label><input type="password" name="password" required></div>
                <div class="field"><label>রোল</label><select name="role"><option value="admin">অ্যাডমিন</option><option value="viewer" selected>ভিউয়ার</option></select></div>
                <div class="field"><label>ইমেইল</label><input type="email" name="email"></div>
                <button class="btn btn-success btn-block" type="submit">👤 তৈরি</button>
            </form>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ইউজারনেম</th><th>রোল</th><th>ইমেইল</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= e($u['username']) ?></strong></td>
                    <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-green' : 'badge-blue' ?>"><?= e($u['role']) ?></span></td>
                    <td><?= e($u['email'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== SETTINGS ====================
if ($page === 'settings'):
?>
<section>
    <h2 style="font-size:20px;margin:12px 0">⚙️ সেটিংস</h2>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="field full-width"><label>প্রকল্পের নাম</label><input name="project_name" value="<?= e($settings['project_name']) ?>" required></div>
            <div class="field"><label>আয়তন</label><input name="pond_area" value="<?= e($settings['pond_area'] ?: '৩০ শতাংশ') ?>"></div>
            <div class="field"><label>পানির গভীরতা</label><input name="pond_depth" value="<?= e($settings['pond_depth']) ?>"></div>
            <div class="field"><label>মোট ওজন (kg)</label><input type="number" step="0.01" name="total_current_weight" value="<?= e((string)$settings['total_current_weight']) ?>"></div>
            <div class="field"><label>বাজার মূল্য (৳/kg)</label><input type="number" step="0.01" name="market_price_per_kg" value="<?= e((string)$settings['market_price_per_kg']) ?>" required></div>
            <div class="field"><label>দৈনিক খাদ্য (kg)</label><input type="number" step="0.1" name="feed_daily_kg" value="<?= e((string)($settings['feed_daily_kg'] ?? 3.5)) ?>"></div>
            <div class="field full-width"><label>🍚 খাদ্য রেসিপি</label><textarea name="feed_recipe" rows="4"><?= e($settings['feed_recipe'] ?? "শুকনো সরিষার খৈল: ১.৫ কেজি\nগমের ভুসি ও কুঁড়া: ১.০ কেজি\nনারিশ ২ মিলি পিলেট ফিড: ১.০ কেজি\nসাধারণ লবণ: এক চিমটি") ?></textarea></div>
            <div class="field full-width"><label>🎯 দীর্ঘমেয়াদী লক্ষ্য</label><textarea name="long_term_goal" rows="4"><?= e($settings['long_term_goal'] ?? "২ মাস পর লক্ষ্য: ৬৫০টি দামি মাছ\nওপরের স্তর: কাতলা ২০০টি + লাটকাপ ৩০টি\nমধ্য স্তর: রুই ৩০০টি\nনিচের স্তর: কালবাউশ/মৃগেল ৫০টি + কার্পিও ১০টি\nবিশেষ স্তর: পাঙ্গাশ ১০০টি") ?></textarea></div>
            <div class="field full-width"><label>নোট</label><textarea name="notes"><?= e($settings['notes']) ?></textarea></div>
            <div class="field"><label>📧 ইমেইল নোটিফিকেশন</label><label style="display:flex;align-items:center;gap:8px;font-weight:400"><input type="checkbox" name="email_notifications" <?= $settings['email_notifications'] ? 'checked' : '' ?>> সক্রিয়</label></div>
            <div class="field"><label>ইমেইল ঠিকানা</label><input type="email" name="notification_email" value="<?= e($settings['notification_email'] ?? '') ?>" placeholder="your@email.com"></div>
            <div class="field full-width" style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-success" type="submit">💾 সংরক্ষণ</button>
                <a class="btn" href="?action=toggle_dark_mode"><?= $dark_mode ? '☀️ লাইট' : '🌙 ডার্ক' ?></a>
                <button class="btn btn-info" type="button" onclick="if(confirm('ব্যাকআপ?')){location='?action=backup'}">💾 ব্যাকআপ</button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- ==================== FOOTER ==================== -->
<footer class="footer">
    <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · 🔒 নিরাপদ · 📊 রিয়েল-টাইম · 📱 মোবাইল-ফ্রেন্ডলি
</footer>

</main>

<!-- ==================== MOBILE BOTTOM NAV ==================== -->
<nav class="mobile-nav" role="navigation">
    <div class="mobile-nav-inner">
        <a href="?page=dashboard" class="mobile-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">🏠</span><span>হোম</span>
        </a>
        <a href="?page=batches" class="mobile-nav-item <?= $page === 'batches' ? 'active' : '' ?>">
            <span class="nav-icon">🐟</span><span>ব্যাচ</span>
        </a>
        <a href="?page=growth" class="mobile-nav-item <?= $page === 'growth' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span><span>Growth</span>
        </a>
        <a href="?page=feed" class="mobile-nav-item <?= $page === 'feed' ? 'active' : '' ?>">
            <span class="nav-icon">🍚</span><span>খাদ্য</span>
        </a>
        <a href="?page=accounting" class="mobile-nav-item <?= $page === 'accounting' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span><span>হিসাব</span>
        </a>
        <a href="?page=settings" class="mobile-nav-item <?= $page === 'settings' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span><span>সেটিংস</span>
        </a>
    </div>
</nav>

<!-- ==================== SCRIPTS ==================== -->
<script>
// ====== Dark Mode Toggle ======
document.querySelector('[href*="toggle_dark_mode"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    fetch('?action=toggle_dark_mode', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(() => location.reload());
});

// ====== Search Functions ======
let searchTimeout;
function searchBatches() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const search = document.getElementById('searchInput').value;
        const url = new URL(window.location);
        url.searchParams.set('search', search);
        url.searchParams.set('page', 'batches');
        window.location = url;
    }, 300);
}

function filterBatches() {
    const status = document.getElementById('statusFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const url = new URL(window.location);
    if (status) url.searchParams.set('batch_filter', status);
    else url.searchParams.delete('batch_filter');
    if (dateFrom) url.searchParams.set('date_from', dateFrom);
    else url.searchParams.delete('date_from');
    if (dateTo) url.searchParams.set('date_to', dateTo);
    else url.searchParams.delete('date_to');
    url.searchParams.set('page', 'batches');
    window.location = url;
}

// ====== Touch Feedback ======
document.querySelectorAll('.touchable, .btn, .kpi-card, .mobile-nav-item').forEach(el => {
    el.addEventListener('touchstart', function() {
        this.style.opacity = '0.7';
    }, { passive: true });
    el.addEventListener('touchend', function() {
        this.style.opacity = '1';
    }, { passive: true });
});

// ====== Disable Zoom ======
document.addEventListener('gesturestart', e => e.preventDefault());
document.addEventListener('gesturechange', e => e.preventDefault());
document.addEventListener('touchmove', e => { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });
document.addEventListener('dblclick', e => e.preventDefault(), { passive: false });
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0'].includes(e.key)) e.preventDefault();
});

// ====== Auto-refresh Dashboard ======
if (window.location.search.includes('page=dashboard')) {
    setTimeout(() => location.reload(), 60000);
}

// ====== Service Worker (PWA) ======
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

// ====== Console Info ======
console.log('🐟 <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>');
console.log('🏡 ৩০ শতাংশ পুকুর | গভীরতা: ১৮-১৯ ফুট');
console.log('📊 মোট: <?= number_format($totalCount) ?> মাছ | <?= number_format($dashboardCurrentWeight, 1) ?> কেজি');
console.log('🍚 দৈনিক খাদ্য: <?= number_format((float)$settings['feed_daily_kg'], 1) ?> কেজি');
console.log('📱 সম্পূর্ণ মোবাইল-ফ্রেন্ডলি ডিজাইন');
</script>

</body>
</html>
