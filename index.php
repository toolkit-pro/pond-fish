<?php
/**
 * POND FISH PROJECT DASHBOARD — ULTIMATE PROFESSIONAL EDITION v7.0
 * সম্পূর্ণ আপডেটেড - সব আধুনিক ফিচার সহ
 * 
 * নতুন ফিচার:
 * - Chart.js দিয়ে অ্যাডভান্সড গ্রাফ
 * - PDF রিপোর্ট জেনারেট
 * - CSV/Excel এক্সপোর্ট
 * - সার্চ ও ফিল্টার
 * - পেজিনেশন
 * - ডার্ক মোড
 * - মাল্টি-ইউজার সাপোর্ট
 * - PWA রেডি
 * - ইমেইল নোটিফিকেশন
 * - রিয়েল-টাইম আপডেট
 */

declare(strict_types=1);

// ==================== কনফিগারেশন ====================
const APP_NAME = 'পুকুর মাছ চাষ প্রকল্প';
const APP_VERSION = '7.0.0';
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
            total_current_weight REAL DEFAULT 0,
            notes TEXT DEFAULT '',
            last_midnight_update TEXT DEFAULT '',
            market_price_per_kg REAL DEFAULT 0,
            dark_mode INTEGER DEFAULT 0,
            email_notifications INTEGER DEFAULT 0,
            notification_email TEXT DEFAULT '',
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
            release_date TEXT NOT NULL,
            initial_weight REAL NOT NULL DEFAULT 0,
            initial_count INTEGER NOT NULL DEFAULT 0,
            initial_avg_weight REAL NOT NULL DEFAULT 0,
            initial_cost REAL NOT NULL DEFAULT 0,
            death_weight REAL NOT NULL DEFAULT 0,
            death_count INTEGER NOT NULL DEFAULT 0,
            current_count INTEGER NOT NULL DEFAULT 0,
            current_weight REAL NOT NULL DEFAULT 0,
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
        $stmt = $pdo->prepare("
            INSERT INTO settings
            (id, project_name, pond_depth, total_current_weight, notes, last_midnight_update, 
             market_price_per_kg, dark_mode, email_notifications, notification_email, created_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            APP_NAME, '১৮–১৯ ফুট', 75, '৩১ আগস্ট ২০২৬-এর ভিত্তি রেকর্ড',
            date('Y-m-d'), 350, 0, 0, '', $now, $now
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
        [1, 'ছোট পোনা', '2026-07-08', 10, 1200, 1500, 3.25, 0, 920, 0, 15000, 'প্রাথমিক সংখ্যা ১,২০০–১,৫০০'],
        [2, 'মাঝারি পোনা', '2026-07-22', 25, 280, 280, 0, 0, 280, 0, 12000, 'বর্তমান সংখ্যা ২৮০টি'],
        [3, 'কাতল', '2026-08-24', 9, 64, 64, 0, 0, 64, 9, 3000, 'ছাড়ার সময় ৯ কেজি / ৬৪টি'],
        [4, 'ব্রিগেড/লাটকাপ', '2026-08-31', 5.5, 56, 56, 0, 0, 56, 5.5, 2500, 'আজ নতুন অবমুক্ত'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO batches
        (batch_no, fish_name, release_date, initial_weight, initial_count,
         current_count, death_weight, death_count, current_weight, initial_avg_weight,
         initial_cost, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $r) {
        [$no,$fish,$date,$iw,$minCount,$maxCount,$dw,$dc,$current,$cw,$cost,$notes] = $r;
        $initialCount = $minCount;
        $avg = $initialCount > 0 ? ($iw * 1000) / $initialCount : 0;
        $stmt->execute([
            $no, $fish, $date, $iw, $initialCount, $current,
            $dw, $dc, $cw, $avg, $cost, $notes, $now, $now
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
    
    // মিডনাইট আপডেট
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
        
        // ইমেইল নোটিফিকেশন
        if (!empty($settings['email_notifications']) && !empty($settings['notification_email'])) {
            send_daily_report($settings['notification_email']);
        }
    }
    
    // ব্যাকআপ (সাপ্তাহিক)
    if (date('N') == 7) { // রোববার
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
    
    // ৩০ দিনের বেশি পুরনো ব্যাকআপ ডিলিট
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
    $message = "প্রকল্প: " . APP_NAME . "\n";
    $message .= "তারিখ: $today\n";
    $message .= "মোট ব্যাচ: " . count($batches) . "\n";
    $message .= "মোট মাছ: $totalCount টি\n";
    $message .= "মোট ওজন: " . number_format($totalWeight, 2) . " কেজি\n";
    $message .= "\n--- বিস্তারিত ---\n";
    foreach ($batches as $b) {
        $message .= "ব্যাচ {$b['batch_no']}: {$b['fish_name']} - " . 
                    number_format($b['current_weight'], 2) . " কেজি, " . 
                    $b['current_count'] . " টি\n";
    }
    
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

// ==================== এক্সপোর্ট হ্যান্ডলার ====================
if (isset($_GET['export'])) {
    require_login();
    $table = $_GET['export'];
    $columns = [
        'batches' => ['batch_no', 'fish_name', 'release_date', 'initial_weight', 'initial_count', 
                      'current_count', 'current_weight', 'status', 'notes'],
        'feed_logs' => ['log_date', 'feed_kg', 'feed_cost', 'notes'],
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

        // ডার্ক মোড টগল
        if ($action === 'toggle_dark_mode') {
            $current = (int)$pdo->query("SELECT dark_mode FROM settings WHERE id=1")->fetchColumn();
            $pdo->prepare("UPDATE settings SET dark_mode = ?, updated_at = ? WHERE id=1")
                ->execute([$current ? 0 : 1, date('Y-m-d H:i:s')]);
            redirect('?page=dashboard&dark=' . ($current ? 0 : 1));
        }

        // সেটিংস
        if ($action === 'save_settings') {
            $stmt = $pdo->prepare("
                UPDATE settings SET project_name=?, pond_depth=?, total_current_weight=?, 
                notes=?, market_price_per_kg=?, email_notifications=?, notification_email=?, updated_at=?
                WHERE id=1
            ");
            $stmt->execute([
                trim($_POST['project_name']),
                trim($_POST['pond_depth']),
                normalize_float($_POST['total_current_weight']),
                trim($_POST['notes']),
                normalize_float($_POST['market_price_per_kg']),
                isset($_POST['email_notifications']) ? 1 : 0,
                trim($_POST['notification_email']),
                date('Y-m-d H:i:s')
            ]);
            audit('update', 'settings', 1);
            redirect('?page=settings&saved=1');
        }

        // ব্যাচ সেভ
        if ($action === 'save_batch') {
            $id = normalize_int($_POST['id'] ?? 0);
            $batchNo = normalize_int($_POST['batch_no']);
            $fishName = trim($_POST['fish_name']);
            $releaseDate = trim($_POST['release_date']);
            
            if ($batchNo < 1 || $fishName === '' || $releaseDate === '') {
                throw new RuntimeException('ব্যাচ নম্বর, মাছের নাম ও ছাড়ার তারিখ আবশ্যক।');
            }

            $data = [
                $batchNo, $fishName, $releaseDate,
                normalize_float($_POST['initial_weight']),
                normalize_int($_POST['initial_count']),
                normalize_float($_POST['initial_cost']),
                normalize_float($_POST['death_weight']),
                normalize_int($_POST['death_count']),
                normalize_int($_POST['current_count']),
                normalize_float($_POST['current_weight']),
                trim($_POST['notes'])
            ];

            $avg = $data[4] > 0 ? ($data[3] * 1000) / $data[4] : 0;

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE batches SET batch_no=?, fish_name=?, release_date=?, initial_weight=?,
                    initial_count=?, initial_avg_weight=?, initial_cost=?, death_weight=?,
                    death_count=?, current_count=?, current_weight=?, notes=?, updated_at=?
                    WHERE id=?
                ");
                $stmt->execute([...$data, $avg, date('Y-m-d H:i:s'), $id]);
                audit('update', 'batch', $id);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO batches
                    (batch_no, fish_name, release_date, initial_weight, initial_count,
                     initial_avg_weight, initial_cost, death_weight, death_count,
                     current_count, current_weight, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([...$data, $avg, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                audit('create', 'batch', (int)$pdo->lastInsertId());
            }
            redirect('?page=batches&saved=1');
        }

        // ডিলিট
        $delete_actions = ['delete_batch', 'delete_feed', 'delete_health', 'delete_expense', 'delete_snapshot'];
        if (in_array($action, $delete_actions)) {
            $id = normalize_int($_POST['id']);
            $table = str_replace('delete_', '', $action);
            $table_map = ['batch' => 'batches', 'feed' => 'feed_logs', 
                         'health' => 'health_logs', 'expense' => 'expense_logs', 
                         'snapshot' => 'growth_snapshots'];
            $pdo->prepare("DELETE FROM " . $table_map[$table] . " WHERE id=?")->execute([$id]);
            audit('delete', $table, $id);
            redirect('?page=' . $table_map[$table] . '&deleted=1');
        }

        // Snapshot
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

        // খাদ্য
        if ($action === 'feed') {
            $pdo->prepare("
                INSERT INTO feed_logs(log_date, batch_id, feed_kg, feed_cost, notes, created_at)
                VALUES(?,?,?,?,?,?)
            ")->execute([
                $_POST['log_date'],
                normalize_int($_POST['batch_id']) ?: null,
                normalize_float($_POST['feed_kg']),
                normalize_float($_POST['feed_cost']),
                trim($_POST['notes']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'feed_log', (int)$pdo->lastInsertId());
            redirect('?page=feed&saved=1');
        }

        // স্বাস্থ্য
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

        // খরচ
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

        // ইউজার তৈরি (শুধু অ্যাডমিন)
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

// ডার্ক মোড কুকি/সেশন
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
    $batch_query .= " AND (fish_name LIKE ? OR batch_no LIKE ?)";
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

// পেজিনেশন
$page_num = max(1, (int)($_GET['p'] ?? 1));
$total_items = count($batches);
$total_pages = ceil($total_items / ITEMS_PER_PAGE);
$offset = ($page_num - 1) * ITEMS_PER_PAGE;
$batches_paged = array_slice($batches, $offset, ITEMS_PER_PAGE);

// অন্যান্য ডেটা
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

// ইউজার ডেটা
$users = $pdo->query("SELECT id, username, role, email, created_at FROM users ORDER BY id")->fetchAll();

// অন্যান্য ডেটা
$editBatch = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $stmt = $pdo->prepare("SELECT * FROM batches WHERE id=?");
    $stmt->execute([normalize_int($_GET['edit'])]);
    $editBatch = $stmt->fetch() ?: null;
}

$snapshots = $pdo->query("
    SELECT gs.*, b.batch_no, b.fish_name
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

// Chart ডেটা
$chart_data = [
    'labels' => [],
    'weights' => [],
    'counts' => [],
    'dates' => []
];
foreach ($batches as $b) {
    $chart_data['labels'][] = 'B' . $b['batch_no'] . ' - ' . $b['fish_name'];
    $chart_data['weights'][] = (float)$b['current_weight'];
    $chart_data['counts'][] = (int)$b['current_count'];
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
<title>লগইন — <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:'Noto Sans Bengali',system-ui,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#164e63 100%);display:grid;place-items:center;padding:20px;color:#0f172a}.login-card{width:min(440px,100%);background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);border-radius:28px;padding:40px;box-shadow:0 30px 80px rgba(0,0,0,0.3)}.logo-icon{width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:grid;place-items:center;font-size:32px;margin-bottom:20px}.login-card h1{margin:0 0 8px;font-size:26px;color:#0f172a}.login-card p{color:#64748b;margin-bottom:24px}.field{margin:16px 0}.field label{display:block;font-weight:700;margin-bottom:8px;color:#334155}.field input{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:14px;font-size:16px;transition:border-color 0.3s}.field input:focus{outline:none;border-color:#0f766e}.btn-login{width:100%;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;font-weight:800;font-size:16px;cursor:pointer;transition:transform 0.2s}.btn-login:hover{transform:translateY(-2px)}.error-msg{background:#fef2f2;color:#dc2626;padding:12px;border-radius:12px;margin:12px 0;font-size:14px}
</style>
</head>
<body>
<form class="login-card" method="post">
<div class="logo-icon">🐟</div>
<h1><?= e(APP_NAME) ?></h1>
<p>নিরাপদ ড্যাশবোর্ডে প্রবেশ করুন</p>
<?php if ($error): ?><div class="error-msg"><?= e($error) ?></div><?php endif; ?>
<input type="hidden" name="login" value="1">
<input type="hidden" name="csrf" value="<?= e($token) ?>">
<div class="field"><label>ইউজারনেম</label><input name="username" type="text" placeholder="admin" required></div>
<div class="field"><label>নিরাপত্তা PIN</label><input name="pin" type="password" inputmode="numeric" maxlength="12" required></div>
<button class="btn-login" type="submit">ড্যাশবোর্ডে প্রবেশ</button>
</form>
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
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="<?= $dark_mode ? '#0f172a' : '#0f766e' ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="manifest.json">
<title><?= e($settings['project_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
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

*{box-sizing:border-box}html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}body{margin:0;background:var(--bg);color:var(--ink);font-family:'Noto Sans Bengali',system-ui,sans-serif;transition:background 0.3s,color 0.3s}.wrap{width:min(1400px,95%);margin:auto}

/* নেভিগেশন */
.top-nav{position:sticky;top:0;z-index:100;background:var(--card);backdrop-filter:blur(20px);border-bottom:1px solid var(--line)}.nav-inner{min-height:70px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:10px 0}.brand{font-weight:800;font-size:20px;color:var(--primary);display:flex;align-items:center;gap:10px}.brand-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:grid;place-items:center;font-size:20px}.nav-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.nav-links a{padding:9px 14px;border-radius:10px;text-decoration:none;color:var(--muted);font-weight:600;font-size:14px;transition:all 0.2s}.nav-links a:hover{background:var(--line);color:var(--primary)}.nav-links a.active{background:var(--primary);color:#fff}.btn-logout{background:#fee2e2;color:#991b1b;border:0;padding:9px 14px;border-radius:10px;font-weight:600;cursor:pointer;transition:all 0.2s;font-size:14px}.btn-logout:hover{background:#fecaca}

/* কার্ড */
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:20px;transition:all 0.3s}.card:hover{box-shadow:var(--shadow-lg)}

/* কেপিআই */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0}.kpi-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;position:relative;transition:all 0.3s;cursor:pointer}.kpi-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}.kpi-value{font-size:28px;font-weight:800}

/* বাটন */
.btn{display:inline-flex;align-items:center;gap:8px;border:0;background:var(--primary);color:#fff;padding:11px 18px;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer;transition:all 0.2s;font-size:14px}.btn:hover{background:var(--primary2);transform:translateY(-1px)}.btn-danger{background:var(--danger)}.btn-success{background:var(--success)}.btn-info{background:var(--info)}.btn-warning{background:var(--warning)}.btn-sm{padding:6px 12px;font-size:12px}.btn-xs{padding:4px 8px;font-size:11px}

/* টেবিল */
.table-wrap{overflow-x:auto;border-radius:16px;border:1px solid var(--line);background:var(--card)}.table{width:100%;border-collapse:collapse;min-width:700px}.table th,.table td{text-align:left;padding:14px 16px;border-bottom:1px solid var(--line)}.table th{background:var(--bg);font-weight:700;font-size:12px;text-transform:uppercase;color:var(--muted);position:sticky;top:0}.badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:700}.badge-green{background:#ecfdf5;color:#059669}.badge-red{background:#fef2f2;color:#dc2626}.badge-blue{background:#eff6ff;color:#2563eb}

/* ফর্ম */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;color:var(--muted)}.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:2px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);font:inherit;transition:border-color 0.3s}.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--primary)}.field textarea{min-height:100px;resize:vertical}

/* গ্রাফ */
.chart-container{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin:20px 0}.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}

/* সার্চ */
.search-bar{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.search-bar input,.search-bar select{padding:10px 14px;border:2px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);font:inherit}

/* পেজিনেশন */
.pagination{display:flex;gap:6px;justify-content:center;margin:20px 0}.pagination a,.pagination span{padding:8px 14px;border-radius:8px;border:1px solid var(--line);text-decoration:none;color:var(--ink)}.pagination .active{background:var(--primary);color:#fff;border-color:var(--primary)}.pagination a:hover{background:var(--line)}

/* মোবাইল */
.mobile-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--card);border-top:1px solid var(--line);z-index:1000;padding:8px 0}.mobile-nav-inner{display:flex;justify-content:space-around;overflow-x:auto}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:var(--muted);font-size:10px;font-weight:600;padding:4px 8px;min-width:50px}.mobile-nav-item.active{color:var(--primary)}.mobile-nav-item .nav-icon{font-size:20px}

/* রেস্পন্সিভ */
@media(max-width:768px){.nav-links{display:none}.mobile-nav{display:block}.kpi-grid{grid-template-columns:1fr 1fr}.chart-grid{grid-template-columns:1fr}.kpi-value{font-size:22px}.two-col{grid-template-columns:1fr}.search-bar{flex-direction:column}}
@media(max-width:480px){.kpi-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- টপ নেভিগেশন -->
<header class="top-nav">
    <div class="wrap nav-inner">
        <div class="brand"><div class="brand-icon">🐟</div><?= e($settings['project_name']) ?></div>
        <div class="nav-links">
            <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">📊 ড্যাশবোর্ড</a>
            <a href="?page=batches" class="<?= $page === 'batches' ? 'active' : '' ?>">🐠 ব্যাচ</a>
            <a href="?page=growth" class="<?= $page === 'growth' ? 'active' : '' ?>">📈 Growth</a>
            <a href="?page=feed" class="<?= $page === 'feed' ? 'active' : '' ?>">🍚 খাদ্য</a>
            <a href="?page=health" class="<?= $page === 'health' ? 'active' : '' ?>">💊 স্বাস্থ্য</a>
            <a href="?page=expenses" class="<?= $page === 'expenses' ? 'active' : '' ?>">💰 খরচ</a>
            <a href="?page=accounting" class="<?= $page === 'accounting' ? 'active' : '' ?>">📊 হিসাব</a>
            <a href="?page=analytics" class="<?= $page === 'analytics' ? 'active' : '' ?>">📈 এনালাইসিস</a>
            <?php if (is_admin()): ?>
            <a href="?page=users" class="<?= $page === 'users' ? 'active' : '' ?>">👥 ইউজার</a>
            <?php endif; ?>
            <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">⚙️ সেটিংস</a>
            <button class="btn-logout" onclick="window.location='?logout=1'">🚪 লগআউট</button>
        </div>
    </div>
</header>

<main class="wrap">

<?php if (isset($_GET['saved'])): ?>
<div class="notice">✅ তথ্য সফলভাবে সংরক্ষণ হয়েছে।</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="notice">🗑️ তথ্য মুছে ফেলা হয়েছে।</div>
<?php endif; ?>
<?php if (!empty($formError)): ?>
<div class="notice" style="background:#fef2f2;color:#991b1b">⚠ <?= e($formError) ?></div>
<?php endif; ?>

<style>
.notice{padding:14px 18px;border-radius:12px;background:#ecfdf5;color:#065f46;margin:16px 0;font-weight:600}
</style>

<?php
// ==================== ড্যাশবোর্ড ====================
if ($page === 'dashboard'):
?>
<section style="padding:20px 0">
    <h1 style="font-size:clamp(24px,4vw,38px);margin:0 0 8px">📊 প্রকল্প পরিসংখ্যান ড্যাশবোর্ড</h1>
    <p style="color:var(--muted)">সর্বশেষ ভিত্তি: ৩১ আগস্ট ২০২৬ · প্রতি রাত ১২:০০ টায় লাইভ আপডেট</p>
</section>

<section class="kpi-grid">
    <div class="kpi-card" onclick="location='?page=batches'">
        <div class="kpi-label">🐟 বর্তমানে জীবিত মাছ</div>
        <div class="kpi-value"><?= number_format($totalCount) ?> টি</div>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-label">⚖️ বর্তমান ওজন</div>
        <div class="kpi-value"><?= number_format($dashboardCurrentWeight,2) ?> কেজি</div>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-label">💰 আনুমানিক বাজার মূল্য</div>
        <div class="kpi-value">৳<?= number_format($estimatedMarketValue,0) ?></div>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-label">📊 লাভ/ক্ষতি</div>
        <div class="kpi-value" style="color:<?= $estimatedProfit >= 0 ? '#10b981' : '#ef4444' ?>">৳<?= number_format($estimatedProfit,0) ?></div>
    </div>
</section>

<div class="chart-grid">
    <div class="chart-container">
        <h3>📊 ব্যাচভিত্তিক ওজন</h3>
        <canvas id="weightChart"></canvas>
    </div>
    <div class="chart-container">
        <h3>🧮 ব্যাচভিত্তিক সংখ্যা</h3>
        <canvas id="countChart"></canvas>
    </div>
</div>

<div class="card">
    <h3>📈 গ্রোথ ট্রেন্ড</h3>
    <canvas id="growthChart" height="100"></canvas>
</div>

<script>
new Chart(document.getElementById('weightChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_data['labels']) ?>,
        datasets: [{
            label: 'ওজন (কেজি)',
            data: <?= json_encode($chart_data['weights']) ?>,
            backgroundColor: 'rgba(15, 118, 110, 0.6)',
            borderColor: '#0f766e',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: true } }
    }
});

new Chart(document.getElementById('countChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_data['labels']) ?>,
        datasets: [{
            label: 'সংখ্যা',
            data: <?= json_encode($chart_data['counts']) ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.6)',
            borderColor: '#3b82f6',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: true } }
    }
});

<?php
$growth_data = [];
foreach ($batches as $b) {
    $snap = latest_snapshot((int)$b['id']);
    if ($snap) {
        $growth_data['labels'][] = 'B' . $b['batch_no'] . ' - ' . $b['fish_name'];
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
                tension: 0.3
            },
            {
                label: 'সারভাইভাল %',
                data: <?= json_encode($growth_data['survival']) ?>,
                borderColor: '#3b82f6',
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } }
    }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php
// ==================== ব্যাচ ====================
if ($page === 'batches'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>🐠 ব্যাচ ব্যবস্থাপনা</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn-success" href="?page=batches&edit=new">➕ নতুন ব্যাচ</a>
            <a class="btn btn-info" href="?export=batches">📥 CSV এক্সপোর্ট</a>
        </div>
    </div>

    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="🔍 সার্চ করুন..." value="<?= e($search) ?>" onkeyup="searchBatches()">
        <select id="statusFilter" onchange="filterBatches()">
            <option value="">সব স্ট্যাটাস</option>
            <option value="active" <?= $batch_filter === 'active' ? 'selected' : '' ?>>সক্রিয়</option>
            <option value="inactive" <?= $batch_filter === 'inactive' ? 'selected' : '' ?>>নিষ্ক্রিয়</option>
        </select>
        <input type="date" id="dateFrom" value="<?= e($date_from) ?>" onchange="filterBatches()">
        <input type="date" id="dateTo" value="<?= e($date_to) ?>" onchange="filterBatches()">
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>ব্যাচ</th><th>মাছ</th><th>ছাড়ার তারিখ</th><th>প্রাথমিক</th><th>বর্তমান</th>
                <th>Live Weight</th><th>স্ট্যাটাস</th><th>কাজ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches_paged as $b): ?>
            <tr>
                <td><span class="badge badge-blue">B<?= (int)$b['batch_no'] ?></span></td>
                <td><strong><?= e($b['fish_name']) ?></strong></td>
                <td><?= e($b['release_date']) ?></td>
                <td><?= number_format((int)$b['initial_count']) ?> টি</td>
                <td><?= number_format((int)$b['current_count']) ?> টি</td>
                <td><?= number_format((float)$b['current_weight'],2) ?> kg</td>
                <td><span class="badge <?= $b['status'] === 'active' ? 'badge-green' : 'badge-red' ?>"><?= $b['status'] === 'active' ? '✅ সক্রিয়' : '❌ বন্ধ' ?></span></td>
                <td>
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <a class="btn btn-sm" href="?page=batches&edit=<?= (int)$b['id'] ?>">✏️</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('এই ব্যাচ মুছে ফেলবেন?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$batches_paged): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">কোন ব্যাচ নেই</td></tr><?php endif; ?>
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
<section>
    <h2>➕ <?= $editBatch ? 'ব্যাচ সম্পাদনা' : 'নতুন ব্যাচ' ?></h2>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_batch">
            <input type="hidden" name="id" value="<?= (int)($editBatch['id'] ?? 0) ?>">
            <div class="field"><label>ব্যাচ নম্বর</label><input type="number" name="batch_no" value="<?= e((string)($editBatch['batch_no'] ?? count($batches)+1)) ?>" required></div>
            <div class="field"><label>মাছের নাম</label><input name="fish_name" value="<?= e((string)($editBatch['fish_name'] ?? '')) ?>" required></div>
            <div class="field"><label>ছাড়ার তারিখ</label><input type="date" name="release_date" value="<?= e((string)($editBatch['release_date'] ?? date('Y-m-d'))) ?>" required></div>
            <div class="field"><label>প্রাথমিক ওজন (kg)</label><input type="number" step="0.01" name="initial_weight" value="<?= e((string)($editBatch['initial_weight'] ?? '0')) ?>" required></div>
            <div class="field"><label>প্রাথমিক সংখ্যা</label><input type="number" name="initial_count" value="<?= e((string)($editBatch['initial_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>ক্রয় মূল্য (৳)</label><input type="number" step="0.01" name="initial_cost" value="<?= e((string)($editBatch['initial_cost'] ?? '0')) ?>" required></div>
            <div class="field"><label>মৃত সংখ্যা</label><input type="number" name="death_count" value="<?= e((string)($editBatch['death_count'] ?? '0')) ?>"></div>
            <div class="field"><label>বর্তমান সংখ্যা</label><input type="number" name="current_count" value="<?= e((string)($editBatch['current_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>বর্তমান ওজন (kg)</label><input type="number" step="0.01" name="current_weight" value="<?= e((string)($editBatch['current_weight'] ?? '0')) ?>" required></div>
            <div class="field" style="grid-column:span 2"><label>নোট</label><textarea name="notes"><?= e((string)($editBatch['notes'] ?? '')) ?></textarea></div>
            <div class="field"><button class="btn btn-success" type="submit">💾 সংরক্ষণ</button></div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php
// ==================== গ্রোথ ====================
if ($page === 'growth'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>📈 Growth Snapshots</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn-success" href="#snapshot-form">➕ নতুন Snapshot</a>
            <a class="btn btn-info" href="?export=growth_snapshots">📥 CSV এক্সপোর্ট</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>Live Count</th><th>Live Weight</th><th>Avg Weight</th><th>Growth %</th><th>Survival %</th><th>কাজ</th></tr></thead>
            <tbody>
            <?php foreach ($snapshots as $s): ?>
            <tr>
                <td><?= e($s['snapshot_date']) ?></td>
                <td>B<?= (int)$s['batch_no'] ?> — <?= e($s['fish_name']) ?></td>
                <td><?= number_format((int)$s['live_count']) ?></td>
                <td><?= number_format((float)$s['live_weight'],2) ?> kg</td>
                <td><?= number_format((float)$s['avg_weight'],2) ?> g</td>
                <td><span class="badge badge-green"><?= number_format((float)$s['growth_percent'],1) ?>%</span></td>
                <td><?= number_format((float)$s['survival_percent'],1) ?>%</td>
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
            <?php if (!$snapshots): ?><tr><td colspan="8" style="text-align:center;padding:40px">কোন Snapshot নেই</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="snapshot-form">
    <h2>🧮 নতুন Snapshot যোগ করুন</h2>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="snapshot">
            <div class="field"><label>ব্যাচ</label><select name="batch_id" required><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>তারিখ</label><input type="date" name="snapshot_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Live Weight (kg)</label><input type="number" step="0.01" name="live_weight" required></div>
            <div class="field"><label>Live Count</label><input type="number" name="live_count" required></div>
            <div class="field"><label>Feeding Rate (%)</label><input type="number" step="0.1" name="feed_rate" value="0"></div>
            <div class="field" style="grid-column:span 2"><label>নোট</label><input name="notes"></div>
            <div class="field"><button class="btn btn-success" type="submit">💾 সংরক্ষণ</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== খাদ্য ====================
if ($page === 'feed'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>🍚 খাদ্য ব্যবস্থাপনা</h2>
        <a class="btn btn-info" href="?export=feed_logs">📥 CSV এক্সপোর্ট</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <div class="card">
            <h3>নতুন খাদ্য রেকর্ড</h3>
            <form method="post" class="form-grid" style="grid-template-columns:1fr">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="feed">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সব</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>খাদ্য (kg)</label><input type="number" step="0.001" name="feed_kg" required></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="feed_cost" required></div>
                <div class="field"><label>নোট</label><input name="notes"></div>
                <button class="btn btn-success" type="submit">💾 সংরক্ষণ</button>
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
                    <td><?= number_format((float)$f['feed_kg'],3) ?> kg</td>
                    <td>৳<?= number_format((float)$f['feed_cost'],0) ?></td>
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
// ==================== স্বাস্থ্য ====================
if ($page === 'health'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>💊 স্বাস্থ্য রেকর্ড</h2>
        <a class="btn btn-info" href="?export=health_logs">📥 CSV এক্সপোর্ট</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <div class="card">
            <h3>নতুন স্বাস্থ্য রেকর্ড</h3>
            <form method="post" class="form-grid" style="grid-template-columns:1fr">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="health">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">পুরো পুকুর</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>ধরন</label><select name="log_type"><option>চুন</option><option>লবণ</option><option>ওষুধ</option><option>পানি পরিবর্তন</option><option>রোগ</option><option>মৃত্যু</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ</label><input type="number" step="0.001" name="amount"></div>
                <div class="field"><label>একক</label><input name="unit" placeholder="kg / g / প্যাকেট"></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="cost" required></div>
                <div class="field"><label>বিস্তারিত</label><textarea name="details"></textarea></div>
                <button class="btn btn-success" type="submit">💾 সংরক্ষণ</button>
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
                    <td><?= e($h['log_type']) ?></td>
                    <td>৳<?= number_format((float)$h['cost'],0) ?></td>
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
// ==================== খরচ ====================
if ($page === 'expenses'):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>💰 অন্যান্য খরচ</h2>
        <a class="btn btn-info" href="?export=expense_logs">📥 CSV এক্সপোর্ট</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <div class="card">
            <h3>নতুন খরচ রেকর্ড</h3>
            <form method="post" class="form-grid" style="grid-template-columns:1fr">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="expense">
                <div class="field"><label>তারিখ</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সাধারণ</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>ধরন</label><select name="expense_type"><option>শ্রমিক</option><option>যন্ত্রপাতি</option><option>আনুষাঙ্গিক</option><option>বিদ্যুৎ</option><option>পরিবহন</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ (৳)</label><input type="number" step="0.01" name="amount" required></div>
                <div class="field"><label>নোট</label><textarea name="notes"></textarea></div>
                <button class="btn btn-success" type="submit">💾 সংরক্ষণ</button>
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
                    <td><?= e($exp['expense_type']) ?></td>
                    <td>৳<?= number_format((float)$exp['amount'],0) ?></td>
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
// ==================== অ্যাকাউন্টিং ====================
if ($page === 'accounting'):
?>
<section>
    <h2>📊 সম্পূর্ণ হিসাবনিকাশ</h2>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0">
        <div class="card"><h3>আনুমানিক বাজার মূল্য</h3><div class="kpi-value" style="color:var(--primary)">৳<?= number_format($estimatedMarketValue,0) ?></div></div>
        <div class="card"><h3>মোট খরচ</h3><div class="kpi-value" style="color:var(--danger)">৳<?= number_format($totalAllExpenses,0) ?></div></div>
        <div class="card" style="background:<?= $estimatedProfit >= 0 ? '#10b981' : '#ef4444' ?>;color:#fff">
            <h3 style="color:#fff"><?= $estimatedProfit >= 0 ? 'লাভ' : 'ক্ষতি' ?></h3>
            <div style="font-size:32px;font-weight:800">৳<?= number_format(abs($estimatedProfit),0) ?></div>
            <div><?= number_format($profitPercent,1) ?>%</div>
        </div>
    </div>

    <div class="card">
        <h3>খরচের বিবরণ</h3>
        <div class="table-wrap" style="border:none">
            <table class="table">
                <thead><tr><th>খরচের ধরন</th><th>পরিমাণ</th><th>শতাংশ</th></tr></thead>
                <tbody>
                    <tr><td>মাছের ক্রয় মূল্য</td><td>৳<?= number_format($totalInitialCost,0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalInitialCost/$totalAllExpenses)*100,1) : 0 ?>%</td></tr>
                    <tr><td>খাদ্যের খরচ</td><td>৳<?= number_format($totalFeedCost,0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalFeedCost/$totalAllExpenses)*100,1) : 0 ?>%</td></tr>
                    <tr><td>স্বাস্থ্য খরচ</td><td>৳<?= number_format($totalHealthCost,0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalHealthCost/$totalAllExpenses)*100,1) : 0 ?>%</td></tr>
                    <tr><td>অন্যান্য খরচ</td><td>৳<?= number_format($totalExpenses,0) ?></td><td><?= $totalAllExpenses > 0 ? number_format(($totalExpenses/$totalAllExpenses)*100,1) : 0 ?>%</td></tr>
                    <tr style="font-weight:800;background:var(--bg)"><td>সর্বমোট</td><td>৳<?= number_format($totalAllExpenses,0) ?></td><td>100%</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== অ্যানালিটিক্স ====================
if ($page === 'analytics'):
?>
<section>
    <h2>📈 লাইভ এনালাইসিস</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0">
        <div class="card">
            <h3>ব্যাচভিত্তিক Growth Rate</h3>
            <?php foreach ($batches as $b): 
                $lastSnap = latest_snapshot((int)$b['id']);
                $growthPercent = $lastSnap ? (float)$lastSnap['growth_percent'] : 0;
            ?>
            <div style="margin:12px 0">
                <div style="display:flex;justify-content:space-between"><span>B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></span><strong><?= number_format($growthPercent,1) ?>%</strong></div>
                <div style="width:100%;height:8px;background:var(--line);border-radius:999px;overflow:hidden;margin-top:4px">
                    <div style="height:100%;width:<?= min(100,max(0,$growthPercent)) ?>%;background:linear-gradient(90deg,#14b8a6,#0f766e);border-radius:999px"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h3>প্রকল্প সারাংশ</h3>
            <p><strong>পানির গভীরতা:</strong> <?= e($settings['pond_depth']) ?></p>
            <p><strong>মোট ওজন:</strong> <?= number_format($dashboardCurrentWeight,2) ?> kg</p>
            <p><strong>মোট মাছ:</strong> <?= number_format($totalCount) ?> টি</p>
            <p><strong>মোট ব্যাচ:</strong> <?= count($batches) ?> টি</p>
            <p><strong>শেষ আপডেট:</strong> <?= e($settings['last_midnight_update'] ?: 'এখনো হয়নি') ?></p>
            <p><strong>পরবর্তী আপডেট:</strong> আজ রাত ১২:০০ টায়</p>
            <a class="btn btn-info" href="?page=batches&export=batches">📥 ডেটা এক্সপোর্ট</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== ইউজার ====================
if ($page === 'users' && is_admin()):
?>
<section>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
        <h2>👥 ইউজার ব্যবস্থাপনা</h2>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <div class="card">
            <h3>নতুন ইউজার</h3>
            <form method="post" class="form-grid" style="grid-template-columns:1fr">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="field"><label>ইউজারনেম</label><input name="username" required></div>
                <div class="field"><label>PIN</label><input type="password" name="password" required></div>
                <div class="field"><label>রোল</label><select name="role"><option value="admin">অ্যাডমিন</option><option value="viewer" selected>ভিউয়ার</option></select></div>
                <div class="field"><label>ইমেইল</label><input type="email" name="email"></div>
                <button class="btn btn-success" type="submit">👤 ইউজার তৈরি</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ইউজারনেম</th><th>রোল</th><th>ইমেইল</th><th>তৈরি</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= e($u['username']) ?></strong></td>
                    <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-green' : 'badge-blue' ?>"><?= e($u['role']) ?></span></td>
                    <td><?= e($u['email'] ?: '-') ?></td>
                    <td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== সেটিংস ====================
if ($page === 'settings'):
?>
<section>
    <h2>⚙️ প্রকল্প সেটিংস</h2>
    <div class="card">
        <form method="post" class="form-grid" style="grid-template-columns:1fr 1fr">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="field" style="grid-column:span 2"><label>প্রকল্পের নাম</label><input name="project_name" value="<?= e($settings['project_name']) ?>" required></div>
            <div class="field"><label>পানির গভীরতা</label><input name="pond_depth" value="<?= e($settings['pond_depth']) ?>"></div>
            <div class="field"><label>মোট ওজন (kg)</label><input type="number" step="0.01" name="total_current_weight" value="<?= e((string)$settings['total_current_weight']) ?>"></div>
            <div class="field"><label>বাজার মূল্য (৳/kg)</label><input type="number" step="0.01" name="market_price_per_kg" value="<?= e((string)$settings['market_price_per_kg']) ?>" required></div>
            <div class="field" style="grid-column:span 2"><label>নোট</label><textarea name="notes"><?= e($settings['notes']) ?></textarea></div>
            
            <div class="field">
                <label>📧 ইমেইল নোটিফিকেশন</label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:400">
                    <input type="checkbox" name="email_notifications" <?= $settings['email_notifications'] ? 'checked' : '' ?>>
                    সক্রিয় করুন
                </label>
            </div>
            <div class="field"><label>ইমেইল ঠিকানা</label><input type="email" name="notification_email" value="<?= e($settings['notification_email'] ?? '') ?>" placeholder="your@email.com"></div>
            
            <div class="field" style="grid-column:span 2;display:flex;gap:12px;flex-wrap:wrap">
                <button class="btn btn-success" type="submit">💾 সংরক্ষণ</button>
                <a class="btn" href="?action=toggle_dark_mode"><?= $dark_mode ? '☀️ লাইট মোড' : '🌙 ডার্ক মোড' ?></a>
                <button class="btn btn-info" type="button" onclick="if(confirm('ব্যাকআপ তৈরি করবেন?')){location='?action=backup'}">💾 ব্যাকআপ</button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- ফুটার -->
<footer style="padding:40px 0;text-align:center;color:var(--muted);border-top:1px solid var(--line);margin-top:40px">
    <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · 🔒 নিরাপদ · 📊 রিয়েল-টাইম · 🌙 ডার্ক মোড · 📱 PWA রেডি
</footer>

</main>

<!-- মোবাইল নেভিগেশন -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a href="?page=dashboard" class="mobile-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>"><span class="nav-icon">🏠</span><span>হোম</span></a>
        <a href="?page=batches" class="mobile-nav-item <?= $page === 'batches' ? 'active' : '' ?>"><span class="nav-icon">🐟</span><span>ব্যাচ</span></a>
        <a href="?page=growth" class="mobile-nav-item <?= $page === 'growth' ? 'active' : '' ?>"><span class="nav-icon">📈</span><span>Growth</span></a>
        <a href="?page=feed" class="mobile-nav-item <?= $page === 'feed' ? 'active' : '' ?>"><span class="nav-icon">🍚</span><span>খাদ্য</span></a>
        <a href="?page=accounting" class="mobile-nav-item <?= $page === 'accounting' ? 'active' : '' ?>"><span class="nav-icon">💰</span><span>হিসাব</span></a>
        <a href="?page=settings" class="mobile-nav-item <?= $page === 'settings' ? 'active' : '' ?>"><span class="nav-icon">⚙️</span><span>সেটিংস</span></a>
    </div>
</nav>

<script>
// ডার্ক মোড টগল
document.querySelector('[href*="toggle_dark_mode"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    fetch('?action=toggle_dark_mode', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(() => location.reload());
});

// সার্চ
function searchBatches() {
    const search = document.getElementById('searchInput').value;
    const url = new URL(window.location);
    url.searchParams.set('search', search);
    url.searchParams.set('page', 'batches');
    window.location = url;
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

// জুম ডিজেবল
document.addEventListener('gesturestart', e => e.preventDefault());
document.addEventListener('gesturechange', e => e.preventDefault());
document.addEventListener('touchmove', e => { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });
document.addEventListener('dblclick', e => e.preventDefault(), { passive: false });
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0'].includes(e.key)) e.preventDefault();
});

// অটো-রিফ্রেশ (প্রতি ৬০ সেকেন্ড)
if (window.location.pathname.includes('dashboard')) {
    setTimeout(() => location.reload(), 60000);
}

// PWA Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js')
        .then(() => console.log('✅ SW registered'))
        .catch(() => console.log('SW registration failed'));
}

// কনসোল মেসেজ
console.log('🐟 ' + '<?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>');
console.log('🔒 নিরাপদ সেশন · CSRF · SQLite');
console.log('📊 রিয়েল-টাইম ড্যাশবোর্ড');
console.log('🌙 ডার্ক মোড সাপোর্টেড');
console.log('📱 PWA রেডি');
</script>

</body>
</html>
