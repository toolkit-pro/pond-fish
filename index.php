<?php
/**
 * POND FISH PROJECT DASHBOARD — ULTIMATE PROFESSIONAL EDITION
 * All-in-one PHP application
 * Version: 6.0.0
 *
 * Requirements:
 * - PHP 8.1+
 * - PDO + SQLite extension
 * - Writable data/ directory
 *
 * Features:
 * - Zoom disabled for all devices
 * - Complete CRUD operations for all data
 * - Professional UI/UX design
 * - Mobile-first responsive design
 * - Separate page navigation system
 * - Live analytics with auto-update
 * - Complete accounting system
 * - Advanced security features
 *
 * Cron:
 * php /path/to/index.php --cron
 * Or schedule at 00:00 daily for midnight updates
 */

declare(strict_types=1);

const APP_NAME = 'পুকুর মাছ চাষ প্রকল্প';
const APP_VERSION = '6.0.0';
const DEFAULT_PIN = '3894';
const SESSION_TIMEOUT = 7200; // 2 hours
const DB_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DB_FILE = DB_DIR . DIRECTORY_SEPARATOR . 'pond.sqlite';
const LOGIN_ATTEMPT_LIMIT = 5;
const LOGIN_LOCKOUT_SECONDS = 300;

date_default_timezone_set('Asia/Dhaka');

if (!is_dir(DB_DIR)) {
    @mkdir(DB_DIR, 0750, true);
}

/**
 * Output escaping helper
 */
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect helper
 */
function redirect(string $url = '?'): never {
    header('Location: ' . $url);
    exit;
}

/**
 * CLI check
 */
function is_cli(): bool {
    return PHP_SAPI === 'cli';
}

/**
 * Database connection
 */
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

/**
 * Install database schema
 */
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
            created_at TEXT NOT NULL
        );
    ");

    $count = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($count === 0) {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO settings
            (id, project_name, pond_depth, total_current_weight, notes, last_midnight_update, market_price_per_kg, created_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            APP_NAME,
            '১৮–১৯ ফুট',
            75,
            '৩১ আগস্ট ২০২৬-এর ভিত্তি রেকর্ড',
            date('Y-m-d'),
            350,
            $now,
            $now
        ]);
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
    if ($count === 0) {
        seed_initial_data($pdo);
    }
}

/**
 * Seed initial data
 */
function seed_initial_data(PDO $pdo): void {
    $now = date('Y-m-d H:i:s');

    $rows = [
        [1, 'ছোট পোনা', '2026-07-08', 10, 1200, 1500, 3.25, 0, 920, 0, 15000, 'প্রাথমিক সংখ্যা ১,২০০–১,৫০০; মৃত ওজন আনুমানিক ৩–৩.৫ কেজি'],
        [2, 'মাঝারি পোনা', '2026-07-22', 25, 280, 280, 0, 0, 280, 0, 12000, 'বর্তমান সংখ্যা ২৮০টি চূড়ান্ত'],
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

/**
 * CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * CSRF check
 */
function check_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}

/**
 * Audit log
 */
function audit(string $action, string $entity, ?int $entityId = null, string $details = ''): void {
    $stmt = db()->prepare("
        INSERT INTO audit_logs(action, entity, entity_id, details, ip, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$action, $entity, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? 'CLI', date('Y-m-d H:i:s')]);
}

/**
 * Check login
 */
function logged_in(): bool {
    return !empty($_SESSION['auth']) &&
           !empty($_SESSION['auth_at']) &&
           (time() - (int)$_SESSION['auth_at'] < SESSION_TIMEOUT);
}

/**
 * Require login
 */
function require_login(): void {
    if (!logged_in()) {
        redirect('?page=login');
    }
    $_SESSION['auth_at'] = time();
}

/**
 * Authenticate PIN
 */
function authenticate_pin(string $pin): bool {
    $hash = $_SESSION['pin_hash'] ?? null;
    if (!$hash) {
        $hash = password_hash(DEFAULT_PIN, PASSWORD_DEFAULT);
        $_SESSION['pin_hash'] = $hash;
    }
    return password_verify($pin, $hash);
}

/**
 * Normalize float
 */
function normalize_float(mixed $v): float {
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? (float)$v : 0.0;
}

/**
 * Normalize int
 */
function normalize_int(mixed $v): int {
    $v = preg_replace('/[^\d-]/', '', (string)$v);
    return max(0, (int)$v);
}

/**
 * Calculate average
 */
function calculate_avg(float $weightKg, int $count): float {
    return $count > 0 ? ($weightKg * 1000) / $count : 0;
}

/**
 * Days between
 */
function days_between(string $date1, string $date2): int {
    try {
        return max(0, (int)(new DateTime($date1))->diff(new DateTime($date2))->days);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Latest snapshot
 */
function latest_snapshot(int $batchId): ?array {
    $stmt = db()->prepare("SELECT * FROM growth_snapshots WHERE batch_id = ? ORDER BY snapshot_date DESC, id DESC LIMIT 1");
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

/**
 * Create snapshot
 */
function create_snapshot(int $batchId, string $date, float $liveWeight, int $liveCount, float $feedRate = 0, string $notes = ''): void {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new RuntimeException('ব্যাচ পাওয়া যায়নি।');
    }

    $avg = calculate_avg($liveWeight, $liveCount);
    $previous = latest_snapshot($batchId);

    $growthWeight = 0;
    $growthPercent = 0;
    if ($previous) {
        $growthWeight = $liveWeight - (float)$previous['live_weight'];
        if ((float)$previous['live_weight'] > 0) {
            $growthPercent = ($growthWeight / (float)$previous['live_weight']) * 100;
        }
    } else {
        $growthWeight = $liveWeight - (float)$batch['initial_weight'];
        if ((float)$batch['initial_weight'] > 0) {
            $growthPercent = ($growthWeight / (float)$batch['initial_weight']) * 100;
        }
    }

    $survival = (int)$batch['initial_count'] > 0
        ? ($liveCount / (int)$batch['initial_count']) * 100
        : 0;

    $dailyFeed = $liveWeight * ($feedRate / 100);
    $weeklyFeed = $dailyFeed * 7;

    $stmt = $pdo->prepare("
        INSERT INTO growth_snapshots
        (batch_id, snapshot_date, live_count, live_weight, avg_weight,
         growth_weight, growth_percent, survival_percent, feed_rate_percent,
         daily_feed_kg, weekly_feed_kg, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(batch_id, snapshot_date) DO UPDATE SET
          live_count=excluded.live_count,
          live_weight=excluded.live_weight,
          avg_weight=excluded.avg_weight,
          growth_weight=excluded.growth_weight,
          growth_percent=excluded.growth_percent,
          survival_percent=excluded.survival_percent,
          feed_rate_percent=excluded.feed_rate_percent,
          daily_feed_kg=excluded.daily_feed_kg,
          weekly_feed_kg=excluded.weekly_feed_kg,
          notes=excluded.notes
    ");

    $stmt->execute([
        $batchId, $date, $liveCount, $liveWeight, $avg,
        $growthWeight, $growthPercent, $survival, $feedRate,
        $dailyFeed, $weeklyFeed, $notes, date('Y-m-d H:i:s')
    ]);

    audit('snapshot', 'batch', $batchId, "Snapshot: $date");
}

/**
 * Midnight auto-update
 */
function run_midnight_update(): void {
    $pdo = db();
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("UPDATE settings SET last_midnight_update = ?, updated_at = ? WHERE id = 1");
    $stmt->execute([$today, date('Y-m-d H:i:s')]);

    $batches = $pdo->query("SELECT * FROM batches WHERE status='active' ORDER BY batch_no")->fetchAll();
    
    foreach ($batches as $batch) {
        $last = latest_snapshot((int)$batch['id']);
        $due = !$last || days_between((string)$last['snapshot_date'], $today) >= 7;
        
        if ($due) {
            create_snapshot(
                (int)$batch['id'],
                $today,
                (float)$batch['current_weight'],
                (int)$batch['current_count'],
                0,
                'Midnight auto-update'
            );
        }
    }
    
    audit('midnight_update', 'system', null, "Auto-update completed for $today");
}

/**
 * Cron handler
 */
function run_cron(): void {
    $pdo = db();
    $today = date('Y-m-d');
    $hour = (int)date('H');
    
    $settings = $pdo->query("SELECT last_midnight_update FROM settings WHERE id=1")->fetch();
    $lastUpdate = $settings['last_midnight_update'] ?? '';
    
    if ($lastUpdate !== $today && ($hour >= 0 && $hour < 23)) {
        run_midnight_update();
        echo "Midnight update completed for {$today}\n";
    } else {
        echo "Midnight update already completed today or not yet due.\n";
    }
}

// ==================== SESSION INIT ====================
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

db();

// ==================== CRON ====================
if (is_cli() && in_array('--cron', $argv ?? [], true)) {
    run_cron();
    exit;
}

// ==================== PAGE ROUTING ====================
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

// ==================== LOGOUT ====================
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    redirect('?page=login');
}

// ==================== LOGIN ====================
if (isset($_POST['login'])) {
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;

    if ($_SESSION['login_attempts'] >= LOGIN_ATTEMPT_LIMIT && time() - (int)($_SESSION['last_login_attempt'] ?? 0) < LOGIN_LOCKOUT_SECONDS) {
        $loginError = 'অনেকবার ভুল PIN দেওয়া হয়েছে। ৫ মিনিট পরে চেষ্টা করুন।';
    } else {
        $_SESSION['last_login_attempt'] = time();
        if (authenticate_pin((string)($_POST['pin'] ?? ''))) {
            $_SESSION['login_attempts'] = 0;
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['auth_at'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit('login', 'system', null, 'Successful login');
            redirect('?page=dashboard');
        } else {
            $_SESSION['login_attempts']++;
            $loginError = 'PIN সঠিক নয়।';
        }
    }
}

if ($page === 'login' && !logged_in()) {
    render_login($loginError ?? '');
    exit;
}

require_login();

// ==================== POST HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_settings') {
            $stmt = db()->prepare("
                UPDATE settings
                SET project_name=?, pond_depth=?, total_current_weight=?, notes=?, market_price_per_kg=?, updated_at=?
                WHERE id=1
            ");
            $stmt->execute([
                trim((string)$_POST['project_name']),
                trim((string)$_POST['pond_depth']),
                normalize_float($_POST['total_current_weight']),
                trim((string)$_POST['notes']),
                normalize_float($_POST['market_price_per_kg']),
                date('Y-m-d H:i:s')
            ]);
            audit('update', 'settings', 1);
            redirect('?page=settings&saved=1');
        }

        if ($action === 'save_batch') {
            $id = normalize_int($_POST['id'] ?? 0);
            $batchNo = normalize_int($_POST['batch_no'] ?? 0);
            $fishName = trim((string)$_POST['fish_name']);
            $releaseDate = trim((string)$_POST['release_date']);
            $initialWeight = normalize_float($_POST['initial_weight']);
            $initialCount = normalize_int($_POST['initial_count']);
            $initialCost = normalize_float($_POST['initial_cost']);
            $deathWeight = normalize_float($_POST['death_weight']);
            $deathCount = normalize_int($_POST['death_count']);
            $currentCount = normalize_int($_POST['current_count']);
            $currentWeight = normalize_float($_POST['current_weight']);
            $notes = trim((string)$_POST['notes']);

            if ($batchNo < 1 || $fishName === '' || $releaseDate === '') {
                throw new RuntimeException('ব্যাচ নম্বর, মাছের নাম ও ছাড়ার তারিখ আবশ্যক।');
            }

            $avg = calculate_avg($initialWeight, $initialCount);
            $pdo = db();

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE batches SET
                      batch_no=?, fish_name=?, release_date=?, initial_weight=?,
                      initial_count=?, initial_avg_weight=?, initial_cost=?, death_weight=?,
                      death_count=?, current_count=?, current_weight=?, notes=?,
                      updated_at=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $batchNo,$fishName,$releaseDate,$initialWeight,$initialCount,$avg,
                    $initialCost,$deathWeight,$deathCount,$currentCount,$currentWeight,$notes,
                    date('Y-m-d H:i:s'),$id
                ]);
                audit('update', 'batch', $id, $fishName);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO batches
                    (batch_no, fish_name, release_date, initial_weight, initial_count,
                     initial_avg_weight, initial_cost, death_weight, death_count, current_count,
                     current_weight, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $batchNo,$fishName,$releaseDate,$initialWeight,$initialCount,$avg,
                    $initialCost,$deathWeight,$deathCount,$currentCount,$currentWeight,$notes,
                    date('Y-m-d H:i:s'),date('Y-m-d H:i:s')
                ]);
                audit('create', 'batch', (int)$pdo->lastInsertId(), $fishName);
            }
            redirect('?page=batches&saved=1');
        }

        if ($action === 'delete_batch') {
            $id = normalize_int($_POST['id']);
            $stmt = db()->prepare("DELETE FROM batches WHERE id=?");
            $stmt->execute([$id]);
            audit('delete', 'batch', $id);
            redirect('?page=batches&deleted=1');
        }

        if ($action === 'delete_feed') {
            $id = normalize_int($_POST['id']);
            $stmt = db()->prepare("DELETE FROM feed_logs WHERE id=?");
            $stmt->execute([$id]);
            audit('delete', 'feed_log', $id);
            redirect('?page=feed&deleted=1');
        }

        if ($action === 'delete_health') {
            $id = normalize_int($_POST['id']);
            $stmt = db()->prepare("DELETE FROM health_logs WHERE id=?");
            $stmt->execute([$id]);
            audit('delete', 'health_log', $id);
            redirect('?page=health&deleted=1');
        }

        if ($action === 'delete_expense') {
            $id = normalize_int($_POST['id']);
            $stmt = db()->prepare("DELETE FROM expense_logs WHERE id=?");
            $stmt->execute([$id]);
            audit('delete', 'expense_log', $id);
            redirect('?page=expenses&deleted=1');
        }

        if ($action === 'delete_snapshot') {
            $id = normalize_int($_POST['id']);
            $stmt = db()->prepare("DELETE FROM growth_snapshots WHERE id=?");
            $stmt->execute([$id]);
            audit('delete', 'snapshot', $id);
            redirect('?page=growth&deleted=1');
        }

        if ($action === 'snapshot') {
            create_snapshot(
                normalize_int($_POST['batch_id']),
                trim((string)$_POST['snapshot_date']),
                normalize_float($_POST['live_weight']),
                normalize_int($_POST['live_count']),
                normalize_float($_POST['feed_rate']),
                trim((string)$_POST['notes'])
            );
            redirect('?page=growth&saved=1');
        }

        if ($action === 'feed') {
            $stmt = db()->prepare("
                INSERT INTO feed_logs(log_date,batch_id,feed_kg,feed_cost,notes,created_at)
                VALUES(?,?,?,?,?,?)
            ");
            $stmt->execute([
                $_POST['log_date'],
                normalize_int($_POST['batch_id']) ?: null,
                normalize_float($_POST['feed_kg']),
                normalize_float($_POST['feed_cost']),
                trim((string)$_POST['notes']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'feed_log', (int)db()->lastInsertId());
            redirect('?page=feed&saved=1');
        }

        if ($action === 'health') {
            $stmt = db()->prepare("
                INSERT INTO health_logs(log_date,batch_id,log_type,amount,unit,cost,details,created_at)
                VALUES(?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $_POST['log_date'],
                normalize_int($_POST['batch_id']) ?: null,
                trim((string)$_POST['log_type']),
                normalize_float($_POST['amount']),
                trim((string)$_POST['unit']),
                normalize_float($_POST['cost']),
                trim((string)$_POST['details']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'health_log', (int)db()->lastInsertId());
            redirect('?page=health&saved=1');
        }

        if ($action === 'expense') {
            $stmt = db()->prepare("
                INSERT INTO expense_logs(expense_date,batch_id,expense_type,amount,notes,created_at)
                VALUES(?,?,?,?,?,?)
            ");
            $stmt->execute([
                $_POST['expense_date'],
                normalize_int($_POST['batch_id']) ?: null,
                trim((string)$_POST['expense_type']),
                normalize_float($_POST['amount']),
                trim((string)$_POST['notes']),
                date('Y-m-d H:i:s')
            ]);
            audit('create', 'expense_log', (int)db()->lastInsertId());
            redirect('?page=expenses&saved=1');
        }
    } catch (Throwable $ex) {
        $formError = $ex->getMessage();
    }
}

// ==================== DATA FETCH ====================
$pdo = db();
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$batches = $pdo->query("SELECT * FROM batches ORDER BY batch_no")->fetchAll();

$totalCount = 0;
$totalInitialWeight = 0;
$totalCurrentWeight = 0;
$totalDeathCount = 0;
$totalInitialCost = 0;
foreach ($batches as $b) {
    $totalCount += (int)$b['current_count'];
    $totalInitialWeight += (float)$b['initial_weight'];
    $totalCurrentWeight += (float)$b['current_weight'];
    $totalDeathCount += (int)$b['death_count'];
    $totalInitialCost += (float)$b['initial_cost'];
}

if ((float)$settings['total_current_weight'] > 0) {
    $dashboardCurrentWeight = (float)$settings['total_current_weight'];
} else {
    $dashboardCurrentWeight = $totalCurrentWeight;
}

$overallGain = $dashboardCurrentWeight - $totalInitialWeight;
$overallGainPercent = $totalInitialWeight > 0 ? ($overallGain / $totalInitialWeight) * 100 : 0;

// Calculate total expenses
$totalFeedCost = (float)$pdo->query("SELECT COALESCE(SUM(feed_cost),0) FROM feed_logs")->fetchColumn();
$totalHealthCost = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM health_logs")->fetchColumn();
$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expense_logs")->fetchColumn();
$totalAllExpenses = $totalInitialCost + $totalFeedCost + $totalHealthCost + $totalExpenses;

// Calculate market value
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

// ==================== RENDER LOGIN ====================
function render_login(string $error = ''): void {
    $token = csrf_token();
    ?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="format-detection" content="telephone=no">
<title>লগইন — <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:'Noto Sans Bengali',system-ui,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#164e63 100%);display:grid;place-items:center;padding:20px;color:#0f172a;touch-action:manipulation;-webkit-user-select:none;user-select:none}.login-card{width:min(440px,100%);background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);border-radius:28px;padding:40px;box-shadow:0 30px 80px rgba(0,0,0,0.3)}.logo-icon{width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:grid;place-items:center;font-size:32px;margin-bottom:20px}.login-card h1{margin:0 0 8px;font-size:26px;color:#0f172a}.login-card p{color:#64748b;margin-bottom:24px}.field{margin:16px 0}.field label{display:block;font-weight:700;margin-bottom:8px;color:#334155}.field input{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:14px;font-size:18px;letter-spacing:5px;transition:border-color 0.3s;-webkit-user-select:text;user-select:text}.field input:focus{outline:none;border-color:#0f766e}.btn-login{width:100%;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;font-weight:800;font-size:16px;cursor:pointer;transition:transform 0.2s}.btn-login:hover{transform:translateY(-2px)}.error-msg{background:#fef2f2;color:#dc2626;padding:12px;border-radius:12px;margin:12px 0;font-size:14px}
@media(max-width:480px){.login-card{padding:24px}.login-card h1{font-size:22px}}
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
<div class="field"><label>নিরাপত্তা PIN</label><input name="pin" type="password" inputmode="numeric" maxlength="12" autocomplete="current-password" required></div>
<button class="btn-login" type="submit">ড্যাশবোর্ডে প্রবেশ</button>
</form>
</body>
</html>
<?php
}

// ==================== MAIN LAYOUT ====================
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#0f766e">
<title><?= e($settings['project_name']) ?> — <?= match($page) {
    'dashboard' => 'ড্যাশবোর্ড',
    'batches' => 'ব্যাচ',
    'growth' => 'Growth Analysis',
    'feed' => 'খাদ্য ব্যবস্থাপনা',
    'health' => 'স্বাস্থ্য রেকর্ড',
    'expenses' => 'খরচ হিসাব',
    'analytics' => 'এনালাইসিস',
    'accounting' => 'হিসাবনিকাশ',
    'settings' => 'সেটিংস',
    default => 'ড্যাশবোর্ড'
} ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#f8fafc;--card:#ffffff;--ink:#0f172a;--muted:#64748b;--primary:#0f766e;--primary2:#115e59;--accent:#14b8a6;--line:#e2e8f0;--danger:#ef4444;--warning:#f59e0b;--success:#10b981;--info:#3b82f6;--shadow:0 1px 3px rgba(0,0,0,0.1),0 1px 2px rgba(0,0,0,0.06);--shadow-lg:0 10px 40px rgba(0,0,0,0.1)}
*{box-sizing:border-box}html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;-webkit-tap-highlight-color:transparent}body{margin:0;background:var(--bg);color:var(--ink);font-family:'Noto Sans Bengali',system-ui,sans-serif;touch-action:manipulation;-webkit-user-select:none;user-select:none;overscroll-behavior-y:contain}
input,textarea,select{-webkit-user-select:text;user-select:text}
.wrap{width:min(1400px,95%);margin:auto}
/* Mobile Bottom Navigation */
.mobile-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--line);z-index:1000;padding:8px 0;box-shadow:0 -4px 20px rgba(0,0,0,0.1);padding-bottom:env(safe-area-inset-bottom)}
.mobile-nav-inner{display:flex;justify-content:space-around;align-items:center;overflow-x:auto;-webkit-overflow-scrolling:touch}
.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:var(--muted);font-size:10px;font-weight:600;padding:4px 8px;border-radius:8px;transition:all 0.3s;white-space:nowrap;min-width:50px}
.mobile-nav-item.active{color:var(--primary)}
.mobile-nav-item .nav-icon{font-size:20px}
/* Top Navigation */
.top-nav{position:sticky;top:0;z-index:100;background:rgba(255,255,255,0.95);backdrop-filter:blur(20px);border-bottom:1px solid var(--line)}
.nav-inner{min-height:70px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.brand{font-weight:800;font-size:20px;color:var(--primary);display:flex;align-items:center;gap:10px}
.brand-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:grid;place-items:center;font-size:20px}
.nav-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.nav-links a{padding:9px 14px;border-radius:10px;text-decoration:none;color:#334155;font-weight:600;font-size:14px;transition:all 0.2s}
.nav-links a:hover{background:#f1f5f9;color:var(--primary)}
.nav-links a.active{background:var(--primary);color:#fff}
.btn-logout{background:#fee2e2;color:#991b1b;border:0;padding:9px 14px;border-radius:10px;font-weight:600;cursor:pointer;transition:all 0.2s;font-size:14px}
.btn-logout:hover{background:#fecaca}
/* Hero Section */
.hero{padding:30px 0 20px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}
.hero h1{font-size:clamp(24px,4vw,38px);margin:0 0 8px;font-weight:800}
.hero .subtitle{color:var(--muted);font-size:15px}
.live-badge{display:inline-flex;align-items:center;gap:8px;background:#ecfdf5;color:#059669;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:700}
.live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.3}}
/* Cards */
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:20px;transition:box-shadow 0.3s}
.card:hover{box-shadow:var(--shadow-lg)}
/* KPI Cards */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0}
.kpi-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;position:relative;overflow:hidden;transition:all 0.3s;cursor:pointer}
.kpi-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.kpi-icon{position:absolute;top:15px;right:15px;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:20px}
.kpi-label{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px}
.kpi-value{font-size:28px;font-weight:800;margin-top:8px;color:var(--ink)}
.kpi-change{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;margin-top:8px;padding:4px 8px;border-radius:999px}
.kpi-change.positive{background:#ecfdf5;color:#059669}
.kpi-change.negative{background:#fef2f2;color:#dc2626}
/* Section */
.section{margin:30px 0}
.section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.section-header h2{font-size:22px;font-weight:800;margin:0}
/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;background:var(--primary);color:#fff;padding:11px 18px;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer;transition:all 0.2s;font-size:14px}
.btn:hover{background:var(--primary2);transform:translateY(-1px)}
.btn-outline{background:#e2e8f0;color:#334155}
.btn-outline:hover{background:#cbd5e1}
.btn-danger{background:var(--danger)}
.btn-success{background:var(--success)}
.btn-info{background:var(--info)}
.btn-warning{background:var(--warning)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-xs{padding:4px 8px;font-size:11px}
/* Table */
.table-wrap{overflow-x:auto;border-radius:16px;border:1px solid var(--line);background:#fff;-webkit-overflow-scrolling:touch}
.table{width:100%;border-collapse:collapse;min-width:900px}
.table th,.table td{text-align:left;padding:14px 16px;border-bottom:1px solid var(--line)}
.table th{font-size:12px;text-transform:uppercase;color:var(--muted);background:#f8fafc;font-weight:700;letter-spacing:0.5px;position:sticky;top:0;z-index:10}
.table tbody tr:hover{background:#f8fafc}
.badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#ecfdf5;color:#059669}
.badge-yellow{background:#fef3c7;color:#d97706}
.badge-red{background:#fef2f2;color:#dc2626}
.badge-blue{background:#eff6ff;color:#2563eb}
/* Form */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;color:#334155}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:2px solid #e2e8f0;border-radius:12px;background:#fff;font:inherit;transition:border-color 0.3s}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--primary)}
.field textarea{min-height:100px;resize:vertical}
/* Chart */
.chart-container{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin:20px 0}
.chart-bars{display:flex;align-items:end;gap:12px;height:200px;padding-top:20px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.chart-bar{flex:1;min-width:30px;background:linear-gradient(180deg,#14b8a6,#0f766e);border-radius:8px 8px 4px 4px;position:relative;transition:all 0.3s;cursor:pointer}
.chart-bar:hover{opacity:0.8;transform:scaleY(1.02)}
.chart-bar .bar-label{position:absolute;bottom:100%;font-size:10px;color:var(--muted);white-space:nowrap;transform:translateX(-50%);left:50%;padding-bottom:4px;font-weight:600}
.chart-bar .bar-value{position:absolute;top:100%;font-size:10px;color:var(--muted);white-space:nowrap;transform:translateX(-50%);left:50%;padding-top:4px}
/* Notice */
.notice{padding:14px 18px;border-radius:12px;background:#ecfdf5;color:#065f46;margin:16px 0;display:flex;align-items:center;gap:10px;font-weight:600}
.notice-error{background:#fef2f2;color:#991b1b}
/* Two column */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.three-col{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
/* Progress bar */
.progress-bar{width:100%;height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:8px}
.progress-fill{height:100%;border-radius:999px;transition:width 0.3s}
/* Footer */
.footer{padding:40px 0;color:var(--muted);text-align:center;border-top:1px solid var(--line);margin-top:40px}
/* Profit/Loss Card */
.pl-card{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border-radius:16px;padding:20px;text-align:center}
.pl-card.loss{background:linear-gradient(135deg,#ef4444,#dc2626)}
.pl-card .pl-value{font-size:32px;font-weight:800;margin:8px 0}
/* Action buttons in table */
.action-btns{display:flex;gap:4px;flex-wrap:wrap}
/* Responsive Design */
@media(max-width:768px){
    .nav-inner{flex-direction:column;padding:12px 0}
    .nav-links{display:none}
    .mobile-nav{display:block}
    .hero{flex-direction:column;align-items:flex-start}
    .kpi-grid{grid-template-columns:1fr 1fr}
    .kpi-value{font-size:22px}
    .two-col,.three-col{grid-template-columns:1fr}
    .form-grid{grid-template-columns:1fr}
    .table{min-width:600px}
    .section{padding-bottom:60px}
    .footer{padding-bottom:80px}
    .chart-bars{height:150px}
    .pl-card .pl-value{font-size:24px}
}
@media(max-width:480px){
    .kpi-grid{grid-template-columns:1fr}
    .kpi-value{font-size:20px}
    .hero h1{font-size:24px}
    .section-header h2{font-size:18px}
    .btn{padding:9px 14px;font-size:13px}
    .card{padding:14px}
}
</style>
</head>
<body>

<!-- Top Navigation -->
<header class="top-nav">
    <div class="wrap nav-inner">
        <div class="brand">
            <div class="brand-icon">🐟</div>
            <?= e($settings['project_name']) ?>
        </div>
        <div class="nav-links">
            <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">ড্যাশবোর্ড</a>
            <a href="?page=batches" class="<?= $page === 'batches' ? 'active' : '' ?>">ব্যাচ</a>
            <a href="?page=growth" class="<?= $page === 'growth' ? 'active' : '' ?>">Growth</a>
            <a href="?page=feed" class="<?= $page === 'feed' ? 'active' : '' ?>">খাদ্য</a>
            <a href="?page=health" class="<?= $page === 'health' ? 'active' : '' ?>">স্বাস্থ্য</a>
            <a href="?page=expenses" class="<?= $page === 'expenses' ? 'active' : '' ?>">খরচ</a>
            <a href="?page=accounting" class="<?= $page === 'accounting' ? 'active' : '' ?>">হিসাব</a>
            <a href="?page=analytics" class="<?= $page === 'analytics' ? 'active' : '' ?>">এনালাইসিস</a>
            <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">সেটিংস</a>
            <button class="btn-logout" onclick="window.location='?logout=1'">লগআউট</button>
        </div>
    </div>
</header>

<main class="wrap">

<?php if (isset($_GET['saved'])): ?><div class="notice">✓ তথ্য সফলভাবে সংরক্ষণ হয়েছে।</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="notice">✓ তথ্য মুছে ফেলা হয়েছে।</div><?php endif; ?>
<?php if (!empty($formError)): ?><div class="notice notice-error">⚠ <?= e($formError) ?></div><?php endif; ?>

<?php
// ==================== DASHBOARD PAGE ====================
if ($page === 'dashboard'):
?>
<section class="hero" id="dashboard">
    <div>
        <h1>প্রকল্প পরিসংখ্যান ড্যাশবোর্ড</h1>
        <div class="subtitle">সর্বশেষ ভিত্তি: ৩১ আগস্ট ২০২৬ · প্রতি রাত ১২:০০ টায় লাইভ আপডেট</div>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <span class="live-badge"><span class="live-dot"></span> লাইভ আপডেট সক্রিয়</span>
        <a class="btn" href="?page=batches&edit=new">＋ নতুন ব্যাচ</a>
        <a class="btn btn-outline" href="?page=growth">＋ Growth Snapshot</a>
    </div>
</section>

<section class="kpi-grid">
    <div class="kpi-card" onclick="location='?page=batches'">
        <div class="kpi-icon" style="background:#ecfdf5">🐟</div>
        <div class="kpi-label">বর্তমানে জীবিত মাছ</div>
        <div class="kpi-value"><?= number_format($totalCount) ?> টি</div>
        <span class="kpi-change positive">▲ সক্রিয়</span>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-icon" style="background:#eff6ff">⚖️</div>
        <div class="kpi-label">Current Live Biomass</div>
        <div class="kpi-value"><?= number_format($dashboardCurrentWeight,2) ?> kg</div>
        <span class="kpi-change positive">▲ <?= number_format($overallGain,2) ?> kg</span>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-icon" style="background:#fef3c7">💰</div>
        <div class="kpi-label">আনুমানিক বাজার মূল্য</div>
        <div class="kpi-value">৳<?= number_format($estimatedMarketValue,0) ?></div>
        <span class="kpi-change positive">৳<?= number_format($marketPricePerKg,0) ?>/kg</span>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-icon" style="background:#fef2f2">📊</div>
        <div class="kpi-label">মোট খরচ</div>
        <div class="kpi-value">৳<?= number_format($totalAllExpenses,0) ?></div>
        <span class="kpi-change negative">সর্বমোট</span>
    </div>
    <div class="kpi-card" onclick="location='?page=accounting'">
        <div class="kpi-icon" style="background:#f0fdf4">💹</div>
        <div class="kpi-label">লাভ/ক্ষতি</div>
        <div class="kpi-value" style="color:<?= $estimatedProfit >= 0 ? '#10b981' : '#ef4444' ?>">৳<?= number_format($estimatedProfit,0) ?></div>
        <span class="kpi-change <?= $estimatedProfit >= 0 ? 'positive' : 'negative' ?>"><?= $estimatedProfit >= 0 ? '▲' : '▼' ?> <?= number_format($profitPercent,1) ?>%</span>
    </div>
</section>

<section class="chart-container">
    <div class="section-header">
        <h2>📊 ব্যাচভিত্তিক বর্তমান অবস্থা</h2>
        <span class="live-badge"><span class="live-dot"></span> রিয়েল-টাইম</span>
    </div>
    <div class="chart-bars">
        <?php foreach ($batches as $b): 
            $h = max(8,min(100,(float)$b['current_weight'] / max(1,$dashboardCurrentWeight) * 100)); 
        ?>
        <div class="chart-bar" style="height:<?= $h ?>%">
            <span class="bar-label">B<?= (int)$b['batch_no'] ?></span>
            <span class="bar-value"><?= number_format((float)$b['current_weight'],1) ?>kg</span>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="section-header">
        <h2>🌊 প্রকল্প ভিত্তি</h2>
        <a class="btn btn-info" href="?page=accounting">বিস্তারিত হিসাব দেখুন</a>
    </div>
    <div class="two-col">
        <div class="card">
            <p><strong>পানির গভীরতা:</strong> <?= e($settings['pond_depth']) ?></p>
            <p><strong>মোট বর্তমান Biomass:</strong> <?= number_format($dashboardCurrentWeight,2) ?> kg</p>
            <p><strong>মোট জীবিত মাছ:</strong> <?= number_format($totalCount) ?> টি</p>
        </div>
        <div class="card">
            <p><strong>মোট ব্যাচ:</strong> <?= count($batches) ?> টি</p>
            <p><strong>মোট প্রাথমিক ওজন:</strong> <?= number_format($totalInitialWeight,2) ?> kg</p>
            <p><strong>মোট মৃত্যু:</strong> <?= number_format($totalDeathCount) ?> টি</p>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== BATCHES PAGE ====================
if ($page === 'batches'):
?>
<section class="section" id="batches">
    <div class="section-header">
        <h2>🐠 ব্যাচভিত্তিক মাস্টার রেকর্ড</h2>
        <a class="btn" href="?page=batches&edit=new">＋ নতুন ব্যাচ</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ব্যাচ</th><th>মাছ</th><th>ছাড়ার তারিখ</th><th>প্রাথমিক ওজন</th><th>প্রাথমিক সংখ্যা</th><th>ক্রয় মূল্য</th><th>মৃত</th><th>বর্তমান</th><th>Live Weight</th><th>কাজ</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
            <tr>
                <td><span class="badge badge-green">ব্যাচ <?= (int)$b['batch_no'] ?></span></td>
                <td><strong><?= e($b['fish_name']) ?></strong></td>
                <td><?= e($b['release_date']) ?></td>
                <td><?= number_format((float)$b['initial_weight'],2) ?> kg</td>
                <td><?= number_format((int)$b['initial_count']) ?></td>
                <td>৳<?= number_format((float)$b['initial_cost'],0) ?></td>
                <td><?= number_format((int)$b['death_count']) ?> টি</td>
                <td><strong><?= number_format((int)$b['current_count']) ?></strong></td>
                <td><?= number_format((float)$b['current_weight'],2) ?> kg</td>
                <td>
                    <div class="action-btns">
                        <a class="btn btn-outline btn-sm" href="?page=batches&edit=<?= (int)$b['id'] ?>">✏️ এডিট</a>
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
            </tbody>
        </table>
    </div>
</section>

<section class="section" id="batch-form">
    <div class="section-header">
        <h2>➕ <?= $editBatch ? 'ব্যাচ তথ্য সংশোধন' : 'নতুন ব্যাচ যুক্ত করুন' ?></h2>
        <?php if ($editBatch): ?><a class="btn btn-outline" href="?page=batches">Cancel</a><?php endif; ?>
    </div>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_batch">
            <input type="hidden" name="id" value="<?= (int)($editBatch['id'] ?? 0) ?>">
            <div class="field"><label>ব্যাচ নম্বর</label><input type="number" name="batch_no" min="1" value="<?= e((string)($editBatch['batch_no'] ?? (count($batches)+1))) ?>" required></div>
            <div class="field"><label>মাছের নাম</label><input name="fish_name" value="<?= e((string)($editBatch['fish_name'] ?? '')) ?>" required></div>
            <div class="field"><label>ছাড়ার তারিখ</label><input type="date" name="release_date" value="<?= e((string)($editBatch['release_date'] ?? date('Y-m-d'))) ?>" required></div>
            <div class="field"><label>প্রাথমিক ওজন (kg)</label><input type="number" step="0.01" name="initial_weight" value="<?= e((string)($editBatch['initial_weight'] ?? '0')) ?>" required></div>
            <div class="field"><label>প্রাথমিক সংখ্যা</label><input type="number" name="initial_count" value="<?= e((string)($editBatch['initial_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>ক্রয় মূল্য (৳)</label><input type="number" step="0.01" name="initial_cost" value="<?= e((string)($editBatch['initial_cost'] ?? '0')) ?>" required></div>
            <div class="field"><label>মৃত ওজন (kg)</label><input type="number" step="0.01" name="death_weight" value="<?= e((string)($editBatch['death_weight'] ?? '0')) ?>"></div>
            <div class="field"><label>মৃত সংখ্যা</label><input type="number" name="death_count" value="<?= e((string)($editBatch['death_count'] ?? '0')) ?>"></div>
            <div class="field"><label>বর্তমান জীবিত সংখ্যা</label><input type="number" name="current_count" value="<?= e((string)($editBatch['current_count'] ?? '0')) ?>" required></div>
            <div class="field"><label>বর্তমান Live Weight (kg)</label><input type="number" step="0.01" name="current_weight" value="<?= e((string)($editBatch['current_weight'] ?? '0')) ?>"></div>
            <div class="field" style="grid-column:span 2"><label>নোট</label><textarea name="notes"><?= e((string)($editBatch['notes'] ?? '')) ?></textarea></div>
            <div class="field" style="display:flex;gap:8px;align-items:end">
                <button class="btn btn-success" type="submit"><?= $editBatch ? 'তথ্য আপডেট করুন' : 'ব্যাচ যুক্ত করুন' ?></button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== GROWTH PAGE ====================
if ($page === 'growth'):
?>
<section class="section" id="growth">
    <div class="section-header">
        <h2>📈 ৭ দিনের Growth Analysis</h2>
        <a class="btn" href="#snapshot">＋ Snapshot যোগ করুন</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>Live Count</th><th>Live Weight</th><th>Live Avg</th><th>Growth</th><th>Growth %</th><th>Survival</th><th>কাজ</th></tr></thead>
            <tbody>
            <?php foreach ($snapshots as $s): ?>
            <tr>
                <td><?= e($s['snapshot_date']) ?></td>
                <td>B<?= (int)$s['batch_no'] ?> — <?= e($s['fish_name']) ?></td>
                <td><?= number_format((int)$s['live_count']) ?></td>
                <td><?= number_format((float)$s['live_weight'],2) ?> kg</td>
                <td><?= number_format((float)$s['avg_weight'],2) ?> g</td>
                <td><?= number_format((float)$s['growth_weight'],2) ?> kg</td>
                <td><span class="badge badge-green"><?= number_format((float)$s['growth_percent'],1) ?>%</span></td>
                <td><?= number_format((float)$s['survival_percent'],1) ?>%</td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('এই Snapshot মুছে ফেলবেন?')">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_snapshot">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$snapshots): ?><tr><td colspan="9">এখনো Growth Snapshot তৈরি হয়নি।</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="section" id="snapshot">
    <div class="section-header">
        <h2>🧮 Growth Snapshot যোগ করুন</h2>
    </div>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="snapshot">
            <div class="field"><label>ব্যাচ</label><select name="batch_id" required><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Snapshot Date</label><input type="date" name="snapshot_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Live Weight (kg)</label><input type="number" step="0.01" name="live_weight" required></div>
            <div class="field"><label>Live Count</label><input type="number" name="live_count" required></div>
            <div class="field"><label>Feeding Rate (%)</label><input type="number" step="0.1" name="feed_rate" value="0"></div>
            <div class="field" style="grid-column:span 2"><label>নোট</label><input name="notes"></div>
            <div class="field"><button class="btn btn-success">💾 Snapshot সংরক্ষণ</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== FEED PAGE ====================
if ($page === 'feed'):
?>
<section class="section" id="feed">
    <div class="section-header">
        <h2>🍚 খাদ্য ব্যবস্থাপনা</h2>
    </div>
    <div class="two-col">
        <div class="card">
            <h3 style="margin-top:0">নতুন খাদ্য রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="feed">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সব/পুকুর</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>খাদ্য (kg)</label><input type="number" step="0.001" name="feed_kg" required></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="feed_cost" required></div>
                <div class="field"><label>নোট</label><input name="notes"></div>
                <div class="field"><button class="btn btn-success">🍚 খাদ্য রেকর্ড</button></div>
            </form>
        </div>
        <div class="card">
            <h3 style="margin-top:0">সাম্প্রতিক খাদ্য রেকর্ড</h3>
            <div class="table-wrap" style="border:none">
                <table class="table" style="min-width:500px">
                    <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>খাদ্য</th><th>খরচ</th><th>কাজ</th></tr></thead>
                    <tbody>
                    <?php foreach($feedLogs as $f): ?>
                    <tr>
                        <td><?= e($f['log_date']) ?></td>
                        <td><?= $f['batch_no'] ? 'B'.(int)$f['batch_no'] : 'পুকুর' ?></td>
                        <td><?= number_format((float)$f['feed_kg'],3) ?> kg</td>
                        <td>৳<?= number_format((float)$f['feed_cost'],0) ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('এই রেকর্ড মুছে ফেলবেন?')">
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
    </div>
</section>
<?php endif; ?>

<?php
// ==================== HEALTH PAGE ====================
if ($page === 'health'):
?>
<section class="section" id="health">
    <div class="section-header">
        <h2>🩺 রোগ/পানি/চুন/লবণ/ওষুধ রেকর্ড</h2>
    </div>
    <div class="two-col">
        <div class="card">
            <h3 style="margin-top:0">নতুন স্বাস্থ্য রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="health">
                <div class="field"><label>তারিখ</label><input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">পুরো পুকুর</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>ধরন</label><select name="log_type"><option>চুন</option><option>লবণ</option><option>ওষুধ</option><option>পানি পরিবর্তন</option><option>রোগ/লক্ষণ</option><option>মৃত্যু</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ</label><input type="number" step="0.001" name="amount"></div>
                <div class="field"><label>একক</label><input name="unit" placeholder="kg / g / প্যাকেট"></div>
                <div class="field"><label>খরচ (৳)</label><input type="number" step="0.01" name="cost" required></div>
                <div class="field" style="grid-column:span 2"><label>বিস্তারিত</label><textarea name="details"></textarea></div>
                <div class="field"><button class="btn btn-success">💊 রেকর্ড সংরক্ষণ</button></div>
            </form>
        </div>
        <div class="card">
            <h3 style="margin-top:0">সাম্প্রতিক স্বাস্থ্য রেকর্ড</h3>
            <div class="table-wrap" style="border:none">
                <table class="table" style="min-width:500px">
                    <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>ধরন</th><th>খরচ</th><th>কাজ</th></tr></thead>
                    <tbody>
                    <?php foreach($healthLogs as $h): ?>
                    <tr>
                        <td><?= e($h['log_date']) ?></td>
                        <td><?= $h['batch_no'] ? 'B'.(int)$h['batch_no'] : 'পুকুর' ?></td>
                        <td><?= e($h['log_type']) ?></td>
                        <td>৳<?= number_format((float)$h['cost'],0) ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('এই রেকর্ড মুছে ফেলবেন?')">
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
    </div>
</section>
<?php endif; ?>

<?php
// ==================== EXPENSES PAGE ====================
if ($page === 'expenses'):
?>
<section class="section" id="expenses">
    <div class="section-header">
        <h2>💰 অন্যান্য খরচ ব্যবস্থাপনা</h2>
    </div>
    <div class="two-col">
        <div class="card">
            <h3 style="margin-top:0">নতুন খরচ রেকর্ড</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="expense">
                <div class="field"><label>তারিখ</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>ব্যাচ</label><select name="batch_id"><option value="">সব/সাধারণ</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>">B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>খরচের ধরন</label><select name="expense_type"><option>শ্রমিক খরচ</option><option>যন্ত্রপাতি ক্রয়</option><option>আনুষাঙ্গিক খরচ</option><option>বিদ্যুৎ খরচ</option><option>পরিবহন খরচ</option><option>অন্যান্য</option></select></div>
                <div class="field"><label>পরিমাণ (৳)</label><input type="number" step="0.01" name="amount" required></div>
                <div class="field" style="grid-column:span 2"><label>নোট</label><textarea name="notes"></textarea></div>
                <div class="field"><button class="btn btn-success">💰 খরচ রেকর্ড</button></div>
            </form>
        </div>
        <div class="card">
            <h3 style="margin-top:0">সাম্প্রতিক খরচ রেকর্ড</h3>
            <div class="table-wrap" style="border:none">
                <table class="table" style="min-width:500px">
                    <thead><tr><th>তারিখ</th><th>ব্যাচ</th><th>ধরন</th><th>পরিমাণ</th><th>কাজ</th></tr></thead>
                    <tbody>
                    <?php foreach($expenseLogs as $exp): ?>
                    <tr>
                        <td><?= e($exp['expense_date']) ?></td>
                        <td><?= $exp['batch_no'] ? 'B'.(int)$exp['batch_no'] : 'সাধারণ' ?></td>
                        <td><?= e($exp['expense_type']) ?></td>
                        <td>৳<?= number_format((float)$exp['amount'],0) ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('এই রেকর্ড মুছে ফেলবেন?')">
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
    </div>
</section>
<?php endif; ?>

<?php
// ==================== ACCOUNTING PAGE ====================
if ($page === 'accounting'):
?>
<section class="section" id="accounting">
    <div class="section-header">
        <h2>📊 সম্পূর্ণ হিসাবনিকাশ</h2>
        <span class="live-badge"><span class="live-dot"></span> লাইভ হিসাব</span>
    </div>

    <div class="three-col">
        <div class="card">
            <h3 style="margin-top:0">আনুমানিক বাজার মূল্য</h3>
            <div class="kpi-value" style="color:var(--primary)">৳<?= number_format($estimatedMarketValue,0) ?></div>
            <p class="muted">বর্তমান ওজন: <?= number_format($dashboardCurrentWeight,2) ?> kg × ৳<?= number_format($marketPricePerKg,0) ?>/kg</p>
        </div>
        <div class="card">
            <h3 style="margin-top:0">মোট খরচ</h3>
            <div class="kpi-value" style="color:var(--danger)">৳<?= number_format($totalAllExpenses,0) ?></div>
            <p class="muted">সকল খরচের সমষ্টি</p>
        </div>
        <div class="pl-card <?= $estimatedProfit < 0 ? 'loss' : '' ?>">
            <h3 style="margin:0"><?= $estimatedProfit >= 0 ? 'লাভ' : 'ক্ষতি' ?></h3>
            <div class="pl-value">৳<?= number_format(abs($estimatedProfit),0) ?></div>
            <p><?= number_format($profitPercent,1) ?>% <?= $estimatedProfit >= 0 ? 'লাভ' : 'ক্ষতি' ?></p>
        </div>
    </div>

    <div class="section-header" style="margin-top:30px">
        <h2>খরচের বিবরণ</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>খরচের ধরন</th><th>পরিমাণ</th><th>মোট খরচের %</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>মাছের ক্রয় মূল্য</strong></td>
                    <td>৳<?= number_format($totalInitialCost,0) ?></td>
                    <td><?= $totalAllExpenses > 0 ? number_format(($totalInitialCost/$totalAllExpenses)*100,1) : 0 ?>%</td>
                </tr>
                <tr>
                    <td><strong>খাদ্যের খরচ</strong></td>
                    <td>৳<?= number_format($totalFeedCost,0) ?></td>
                    <td><?= $totalAllExpenses > 0 ? number_format(($totalFeedCost/$totalAllExpenses)*100,1) : 0 ?>%</td>
                </tr>
                <tr>
                    <td><strong>চিকিৎসা খরচ</strong></td>
                    <td>৳<?= number_format($totalHealthCost,0) ?></td>
                    <td><?= $totalAllExpenses > 0 ? number_format(($totalHealthCost/$totalAllExpenses)*100,1) : 0 ?>%</td>
                </tr>
                <tr>
                    <td><strong>শ্রমিক/যন্ত্রপাতি/আনুষাঙ্গিক খরচ</strong></td>
                    <td>৳<?= number_format($totalExpenses,0) ?></td>
                    <td><?= $totalAllExpenses > 0 ? number_format(($totalExpenses/$totalAllExpenses)*100,1) : 0 ?>%</td>
                </tr>
                <tr style="background:#f8fafc;font-weight:800">
                    <td><strong>সর্বমোট খরচ</strong></td>
                    <td><strong>৳<?= number_format($totalAllExpenses,0) ?></strong></td>
                    <td>100%</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section-header" style="margin-top:30px">
        <h2>ব্যাচভিত্তিক হিসাব</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ব্যাচ</th><th>ক্রয় মূল্য</th><th>খাদ্য খরচ</th><th>চিকিৎসা খরচ</th><th>অন্যান্য খরচ</th><th>মোট খরচ</th><th>বর্তমান ওজন</th><th>বাজার মূল্য</th><th>লাভ/ক্ষতি</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): 
                $batchFeedCost = (float)$pdo->query("SELECT COALESCE(SUM(feed_cost),0) FROM feed_logs WHERE batch_id=".(int)$b['id'])->fetchColumn();
                $batchHealthCost = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM health_logs WHERE batch_id=".(int)$b['id'])->fetchColumn();
                $batchExpenseCost = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expense_logs WHERE batch_id=".(int)$b['id'])->fetchColumn();
                $batchTotalCost = (float)$b['initial_cost'] + $batchFeedCost + $batchHealthCost + $batchExpenseCost;
                $batchMarketValue = (float)$b['current_weight'] * $marketPricePerKg;
                $batchProfit = $batchMarketValue - $batchTotalCost;
            ?>
            <tr>
                <td><span class="badge badge-blue">B<?= (int)$b['batch_no'] ?></span> <?= e($b['fish_name']) ?></td>
                <td>৳<?= number_format((float)$b['initial_cost'],0) ?></td>
                <td>৳<?= number_format($batchFeedCost,0) ?></td>
                <td>৳<?= number_format($batchHealthCost,0) ?></td>
                <td>৳<?= number_format($batchExpenseCost,0) ?></td>
                <td><strong>৳<?= number_format($batchTotalCost,0) ?></strong></td>
                <td><?= number_format((float)$b['current_weight'],2) ?> kg</td>
                <td>৳<?= number_format($batchMarketValue,0) ?></td>
                <td><span class="badge <?= $batchProfit >= 0 ? 'badge-green' : 'badge-red' ?>">৳<?= number_format($batchProfit,0) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== ANALYTICS PAGE ====================
if ($page === 'analytics'):
?>
<section class="section" id="analytics">
    <div class="section-header">
        <h2>📊 লাইভ এনালাইসিস</h2>
        <span class="live-badge"><span class="live-dot"></span> রিয়েল-টাইম ডেটা</span>
    </div>
    <div class="two-col">
        <div class="card">
            <h3 style="margin-top:0">ব্যাচভিত্তিক Growth Rate</h3>
            <?php foreach ($batches as $b): 
                $lastSnap = latest_snapshot((int)$b['id']);
                $growthPercent = $lastSnap ? (float)$lastSnap['growth_percent'] : 0;
            ?>
            <div style="margin:12px 0">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span>B<?= (int)$b['batch_no'] ?> — <?= e($b['fish_name']) ?></span>
                    <strong><?= number_format($growthPercent,1) ?>%</strong>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?= min(100,max(0,$growthPercent)) ?>%;background:linear-gradient(90deg,#14b8a6,#0f766e)"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h3 style="margin-top:0">প্রকল্প সারাংশ</h3>
            <p><strong>পানির গভীরতা:</strong> <?= e($settings['pond_depth']) ?></p>
            <p><strong>মোট বর্তমান Biomass:</strong> <?= number_format($dashboardCurrentWeight,2) ?> kg</p>
            <p><strong>মোট জীবিত মাছ:</strong> <?= number_format($totalCount) ?> টি</p>
            <p><strong>মোট ব্যাচ:</strong> <?= count($batches) ?> টি</p>
            <p><strong>সর্বশেষ আপডেট:</strong> <?= e($settings['last_midnight_update'] ?: 'এখনো হয়নি') ?></p>
            <p><strong>পরবর্তী আপডেট:</strong> আজ রাত ১২:০০ টায়</p>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// ==================== SETTINGS PAGE ====================
if ($page === 'settings'):
?>
<section class="section" id="settings">
    <div class="section-header">
        <h2>⚙️ প্রকল্প সেটিংস</h2>
    </div>
    <div class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="field" style="grid-column:span 2"><label>প্রকল্পের নাম</label><input name="project_name" value="<?= e($settings['project_name']) ?>" required></div>
            <div class="field"><label>পানির গভীরতা</label><input name="pond_depth" value="<?= e($settings['pond_depth']) ?>"></div>
            <div class="field"><label>মোট Current Live Weight (kg)</label><input type="number" step="0.01" name="total_current_weight" value="<?= e((string)$settings['total_current_weight']) ?>"></div>
            <div class="field"><label>বাজার মূল্য (৳/kg)</label><input type="number" step="0.01" name="market_price_per_kg" value="<?= e((string)$settings['market_price_per_kg']) ?>" required></div>
            <div class="field" style="grid-column:span 2"><label>প্রকল্প নোট</label><textarea name="notes"><?= e($settings['notes']) ?></textarea></div>
            <div class="field"><button class="btn btn-success">💾 সেটিংস আপডেট</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- Footer -->
<footer class="footer">
    <?= e(APP_NAME) ?> · Version <?= e(APP_VERSION) ?> · Secure Session · CSRF · SQLite PDO · 7-Day Growth Engine · Midnight Auto-Update · Complete Accounting
</footer>

</main>

<!-- Mobile Bottom Navigation -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a href="?page=dashboard" class="mobile-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">🏠</span>
            <span>হোম</span>
        </a>
        <a href="?page=batches" class="mobile-nav-item <?= $page === 'batches' ? 'active' : '' ?>">
            <span class="nav-icon">🐟</span>
            <span>ব্যাচ</span>
        </a>
        <a href="?page=growth" class="mobile-nav-item <?= $page === 'growth' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span>
            <span>Growth</span>
        </a>
        <a href="?page=feed" class="mobile-nav-item <?= $page === 'feed' ? 'active' : '' ?>">
            <span class="nav-icon">🍚</span>
            <span>খাদ্য</span>
        </a>
        <a href="?page=health" class="mobile-nav-item <?= $page === 'health' ? 'active' : '' ?>">
            <span class="nav-icon">💊</span>
            <span>স্বাস্থ্য</span>
        </a>
        <a href="?page=accounting" class="mobile-nav-item <?= $page === 'accounting' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span>
            <span>হিসাব</span>
        </a>
        <a href="?page=analytics" class="mobile-nav-item <?= $page === 'analytics' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span>এনালাইসিস</span>
        </a>
    </div>
</nav>

<script>
// Disable zoom on all devices
document.addEventListener('gesturestart', function(e) {
    e.preventDefault();
});

document.addEventListener('gesturechange', function(e) {
    e.preventDefault();
});

document.addEventListener('gestureend', function(e) {
    e.preventDefault();
});

document.addEventListener('touchmove', function(e) {
    if (e.touches.length > 1) {
        e.preventDefault();
    }
}, { passive: false });

document.addEventListener('dblclick', function(e) {
    e.preventDefault();
}, { passive: false });

// Disable keyboard zoom shortcuts
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0')) {
        e.preventDefault();
    }
});

// Auto-refresh dashboard every 60 seconds for live updates
setTimeout(function() {
    window.location.reload();
}, 60000);

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});
</script>

</body>
</html>
