<?php
/**
 * Pond Fish Farming Management System
 * পুকুর মাছ চাষ প্রকল্প - Complete Management Dashboard
 * Version: 6.1.0
 * PHP 8.1+ | SQLite | Mobile-First
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Create logs directory
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Database setup
define('DB_PATH', __DIR__ . '/data/pond.sqlite');
define('DEFAULT_PIN', '3894');
define('SESSION_TIMEOUT', 7200);
define('BRUTE_FORCE_LIMIT', 5);
define('BRUTE_FORCE_LOCKOUT', 300);

// Create data directory if not exists
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

// Initialize database
function initDatabase() {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Settings table
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY,
            project_name TEXT DEFAULT 'পুকুর মাছ চাষ প্রকল্প',
            pond_depth REAL DEFAULT 1.5,
            total_current_weight REAL DEFAULT 0,
            market_price_per_kg REAL DEFAULT 200,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Batches table
        $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY,
            batch_no TEXT UNIQUE,
            fish_name TEXT,
            release_date DATE,
            initial_weight REAL,
            initial_count INTEGER,
            initial_avg_weight REAL,
            initial_cost REAL,
            death_weight REAL DEFAULT 0,
            death_count INTEGER DEFAULT 0,
            current_count INTEGER,
            current_weight REAL,
            status TEXT DEFAULT 'active',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Growth snapshots table
        $pdo->exec("CREATE TABLE IF NOT EXISTS growth_snapshots (
            id INTEGER PRIMARY KEY,
            batch_id INTEGER,
            snapshot_date DATE,
            live_count INTEGER,
            live_weight REAL,
            avg_weight REAL,
            growth_weight REAL,
            growth_percent REAL,
            survival_percent REAL,
            feed_rate_percent REAL,
            daily_feed_kg REAL,
            weekly_feed_kg REAL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");
        
        // Feed logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS feed_logs (
            id INTEGER PRIMARY KEY,
            log_date DATE,
            batch_id INTEGER,
            feed_kg REAL,
            feed_cost REAL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");
        
        // Health logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS health_logs (
            id INTEGER PRIMARY KEY,
            log_date DATE,
            batch_id INTEGER,
            log_type TEXT,
            amount REAL,
            unit TEXT,
            cost REAL DEFAULT 0,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");
        
        // Expense logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS expense_logs (
            id INTEGER PRIMARY KEY,
            expense_date DATE,
            batch_id INTEGER,
            expense_type TEXT,
            amount REAL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");
        
        // Audit logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY,
            action TEXT,
            entity TEXT,
            entity_id INTEGER,
            details TEXT,
            ip TEXT,
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Insert default settings if not exists
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM settings");
        if ($stmt->fetch()['count'] == 0) {
            $pdo->exec("INSERT INTO settings (project_name, pond_depth, market_price_per_kg) 
                       VALUES ('পুকুর মাছ চাষ প্রকল্প', 1.5, 200)");
        }
        
        // Insert seed data (batches) if empty
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM batches");
        if ($stmt->fetch()['count'] == 0) {
            $batches = [
                ['B-001', 'ছোট পোনা', '2026-07-08', 10, 1200, 1.2, 5000],
                ['B-002', 'মাঝারি পোনা', '2026-07-22', 25, 280, 5.5, 12000],
                ['B-003', 'কাতল', '2026-08-24', 9, 64, 8.5, 4500],
                ['B-004', 'ব্রিগেড/লাটকাপ', '2026-08-31', 5.5, 56, 7, 2800]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO batches 
                (batch_no, fish_name, release_date, initial_weight, initial_count, initial_avg_weight, initial_cost, current_count, current_weight)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($batches as $batch) {
                $stmt->execute([...$batch, $batch[4], $batch[3]]);
            }
        }
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        die("Database Error: " . htmlspecialchars($e->getMessage()));
    }
}

// Get database connection
$pdo = initDatabase();

// Security functions
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function logAudit($action, $entity, $entity_id, $details = '') {
    global $pdo;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (action, entity, entity_id, details, ip, user_agent) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$action, $entity, $entity_id, $details, $ip, $user_agent]);
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

// Authentication
function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function checkBruteForce() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attempts_key = 'login_attempts_' . $ip;
    $lockout_key = 'login_lockout_' . $ip;
    
    if (isset($_SESSION[$lockout_key]) && time() < $_SESSION[$lockout_key]) {
        return false;
    }
    
    if (!isset($_SESSION[$attempts_key])) {
        $_SESSION[$attempts_key] = 0;
    }
    
    return $_SESSION[$attempts_key] < BRUTE_FORCE_LIMIT;
}

function recordFailedAttempt() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attempts_key = 'login_attempts_' . $ip;
    $lockout_key = 'login_lockout_' . $ip;
    
    $_SESSION[$attempts_key] = ($_SESSION[$attempts_key] ?? 0) + 1;
    
    if ($_SESSION[$attempts_key] >= BRUTE_FORCE_LIMIT) {
        $_SESSION[$lockout_key] = time() + BRUTE_FORCE_LOCKOUT;
    }
}

function resetLoginAttempts() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    unset($_SESSION['login_attempts_' . $ip]);
    unset($_SESSION['login_lockout_' . $ip]);
}

// Handle logout
if (isset($_POST['logout'])) {
    logAudit('LOGOUT', 'USER', 1, 'User logged out');
    session_destroy();
    header('Location: ?');
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!checkBruteForce()) {
        $error = 'অনেক বার ভুল চেষ্টা করেছেন। পরে আবার চেষ্টা করুন।';
    } else {
        $pin = $_POST['pin'] ?? '';
        if ($pin === DEFAULT_PIN) {
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            resetLoginAttempts();
            logAudit('LOGIN', 'USER', 1, 'User logged in');
            header('Location: ?page=dashboard');
            exit;
        } else {
            recordFailedAttempt();
            $error = 'পিন ভুল!';
        }
    }
}

// Check session timeout
if (isLoggedIn() && time() - ($_SESSION['login_time'] ?? 0) > SESSION_TIMEOUT) {
    logAudit('TIMEOUT', 'USER', 1, 'Session timeout');
    session_destroy();
    header('Location: ?');
    exit;
}

// Get current page
$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = ['dashboard', 'batches', 'growth', 'feed', 'health', 'expenses', 'accounting', 'analytics', 'settings'];

if (!isLoggedIn()) {
    $page = 'login';
} elseif (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Get settings
$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'project_name' => 'পুকুর মাছ চাষ প্রকল্প',
        'pond_depth' => 1.5,
        'market_price_per_kg' => 200
    ];
}

// Handle add batch (AJAX or POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_batch'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed');
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO batches 
            (batch_no, fish_name, release_date, initial_weight, initial_count, initial_avg_weight, initial_cost, current_count, current_weight)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $initial_weight = (float)$_POST['initial_weight'];
        $initial_count = (int)$_POST['initial_count'];
        $initial_avg = $initial_weight / $initial_count;
        
        $stmt->execute([
            $_POST['batch_no'],
            $_POST['fish_name'],
            $_POST['release_date'],
            $initial_weight,
            $initial_count,
            $initial_avg,
            (float)$_POST['initial_cost'],
            $initial_count,
            $initial_weight
        ]);
        
        logAudit('CREATE', 'BATCH', $pdo->lastInsertId(), 'New batch created: ' . $_POST['batch_no']);
        $success = 'ব্যাচ সফলভাবে যোগ করা হয়েছে!';
    } catch (PDOException $e) {
        $error = 'ত্রুটি: ' . $e->getMessage();
    }
}

// Handle delete batch
if (isset($_GET['delete_batch']) && is_numeric($_GET['delete_batch'])) {
    if (!verifyCSRFToken($_GET['csrf_token'] ?? '')) {
        die('CSRF validation failed');
    }
    
    try {
        $id = (int)$_GET['delete_batch'];
        $stmt = $pdo->prepare("SELECT batch_no FROM batches WHERE id = ?");
        $stmt->execute([$id]);
        $batch = $stmt->fetch();
        
        if ($batch) {
            $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$id]);
            logAudit('DELETE', 'BATCH', $id, 'Deleted batch: ' . $batch['batch_no']);
            $success = 'ব্যাচ মুছে ফেলা হয়েছে!';
        }
    } catch (PDOException $e) {
        $error = 'ত্রুটি: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['project_name'] ?? 'পুকুর মাছ চাষ প্রকল্প'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Noto Sans Bengali', 'Inter', sans-serif;
            background: #f0fdfa;
            color: #1f2937;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Navigation */
        .navbar {
            background: #0f766e;
            color: white;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .navbar h1 {
            font-size: 1.5rem;
        }

        .navbar .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
        }

        .navbar .logout-btn:hover {
            background: #b91c1c;
        }

        /* Mobile Bottom Navigation */
        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            padding: 0.5rem 0;
            z-index: 100;
        }

        .bottom-nav-items {
            display: flex;
            justify-content: space-around;
            overflow-x: auto;
        }

        .bottom-nav a {
            flex: 1;
            text-align: center;
            padding: 0.75rem 0.5rem;
            text-decoration: none;
            color: #6b7280;
            font-size: 0.75rem;
            border-top: 3px solid transparent;
            transition: all 0.2s;
        }

        .bottom-nav a.active {
            color: #0f766e;
            border-top-color: #0f766e;
        }

        .bottom-nav a:hover {
            color: #0f766e;
        }

        /* Dashboard */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .kpi-card h3 {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .kpi-card .value {
            font-size: 2rem;
            color: #0f766e;
            font-weight: 700;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 0.5rem;
            overflow-x: auto;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead {
            background: #0f766e;
            color: white;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 500;
            color: #374151;
        }

        input, textarea, select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
        }

        button {
            background: #0f766e;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
            transition: background 0.2s;
        }

        button:hover {
            background: #14b8a6;
        }

        button.danger {
            background: #dc2626;
        }

        button.danger:hover {
            background: #b91c1c;
        }

        button.success {
            background: #059669;
        }

        button.success:hover {
            background: #047857;
        }

        /* Login page */
        .login-container {
            max-width: 400px;
            margin: 5rem auto;
            background: white;
            padding: 2rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
        }

        .login-container h1 {
            color: #0f766e;
            margin-bottom: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }

        .alert.error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .alert.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        /* Charts */
        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .bar-chart {
            display: flex;
            align-items: flex-end;
            height: 200px;
            gap: 0.5rem;
            padding: 1rem 0;
        }

        .bar {
            flex: 1;
            background: #14b8a6;
            border-radius: 0.25rem 0.25rem 0 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            padding: 0.5rem 0;
            font-size: 0.75rem;
            color: #6b7280;
            min-height: 20px;
            transition: height 0.5s;
        }

        .bar:hover {
            background: #0d9488;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 0.5rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-close {
            float: right;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #6b7280;
            cursor: pointer;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .bottom-nav {
                display: block;
            }

            .container {
                padding-bottom: 5rem;
            }

            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            table {
                font-size: 0.75rem;
            }

            th, td {
                padding: 0.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .kpi-card .value {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }

            .kpi-card {
                padding: 1rem;
            }

            .kpi-card .value {
                font-size: 1.25rem;
            }

            .bar-chart {
                height: 150px;
            }
        }
    </style>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <div class="navbar">
            <h1>🐟 <?php echo htmlspecialchars($settings['project_name']); ?></h1>
            <form method="POST" style="margin: 0;">
                <button type="submit" name="logout" class="logout-btn">লগ আউট</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="container">
        <?php if (isset($error)): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($page === 'login'): ?>
            <div class="login-container">
                <h1>🐟 পুকুর মাছ চাষ প্রকল্প</h1>
                <?php if (isset($error)): ?>
                    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>পিন নম্বর:</label>
                        <input type="password" name="pin" required autofocus placeholder="3894" pattern="[0-9]{4}" maxlength="4">
                    </div>
                    <input type="hidden" name="action" value="login">
                    <button type="submit" style="width: 100%; padding: 0.75rem;">লগইন করুন</button>
                </form>
                <p style="margin-top: 1rem; color: #6b7280; font-size: 0.875rem;">ডিফল্ট পিন: <strong>3894</strong></p>
            </div>

        <?php elseif ($page === 'dashboard'): ?>
            <h2 style="margin-bottom: 1rem;">📊 ড্যাশবোর্ড</h2>
            
            <?php
            $batches = $pdo->query("SELECT COUNT(*) as count, SUM(current_count) as total_fish, SUM(current_weight) as total_weight FROM batches WHERE status='active'")->fetch(PDO::FETCH_ASSOC);
            $total_expenses = $pdo->query("SELECT SUM(amount) as total FROM expense_logs")->fetch(PDO::FETCH_ASSOC);
            $total_feed_cost = $pdo->query("SELECT SUM(feed_cost) as total FROM feed_logs")->fetch(PDO::FETCH_ASSOC);
            $total_cost = ($total_expenses['total'] ?? 0) + ($total_feed_cost['total'] ?? 0);
            $total_weight = $batches['total_weight'] ?? 0;
            $total_income = $total_weight * ($settings['market_price_per_kg'] ?? 200);
            ?>
            
            <div class="dashboard-grid">
                <div class="kpi-card">
                    <h3>সক্রিয় ব্যাচ</h3>
                    <div class="value"><?php echo $batches['count'] ?? 0; ?></div>
                </div>
                <div class="kpi-card">
                    <h3>মোট মাছ (সংখ্যা)</h3>
                    <div class="value"><?php echo number_format($batches['total_fish'] ?? 0); ?></div>
                </div>
                <div class="kpi-card">
                    <h3>মোট ওজন (কেজি)</h3>
                    <div class="value"><?php echo number_format($batches['total_weight'] ?? 0, 1); ?></div>
                </div>
                <div class="kpi-card">
                    <h3>মোট খরচ (টাকা)</h3>
                    <div class="value">৳<?php echo number_format($total_cost); ?></div>
                </div>
            </div>

            <div class="chart-container">
                <h3>🔄 ব্যাচ অনুযায়ী মাছের বিতরণ</h3>
                <?php
                $batches_data = $pdo->query("SELECT batch_no, current_weight FROM batches WHERE status='active' ORDER BY current_weight DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                if (count($batches_data) > 0):
                    $max_weight = max(array_column($batches_data, 'current_weight')) ?: 1;
                ?>
                    <div class="bar-chart">
                        <?php foreach ($batches_data as $b): 
                            $height = ($b['current_weight'] / $max_weight) * 100;
                            $height = max($height, 5);
                        ?>
                            <div class="bar" style="height: <?php echo $height; ?>%;">
                                <div><?php echo number_format($b['current_weight'], 1); ?> কেজি</div>
                                <small><?php echo htmlspecialchars($b['batch_no']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #6b7280; padding: 2rem 0;">কোন সক্রিয় ব্যাচ নেই</p>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'batches'): ?>
            <h2 style="margin-bottom: 1rem;">🐠 ব্যাচ ব্যবস্থাপনা</h2>
            
            <button onclick="showAddBatch()" style="margin-bottom: 1rem;">➕ নতুন ব্যাচ যোগ করুন</button>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ব্যাচ নো.</th>
                            <th>মাছের ধরন</th>
                            <th>ছাড়ের তারিখ</th>
                            <th>বর্তমান সংখ্যা</th>
                            <th>বর্তমান ওজন</th>
                            <th>স্ট্যাটাস</th>
                            <th>অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $batches = $pdo->query("SELECT * FROM batches ORDER BY release_date DESC")->fetchAll(PDO::FETCH_ASSOC);
                        if (count($batches) > 0):
                            foreach ($batches as $batch):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batch['batch_no']); ?></td>
                                <td><?php echo htmlspecialchars($batch['fish_name']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($batch['release_date'])); ?></td>
                                <td><?php echo number_format($batch['current_count']); ?></td>
                                <td><?php echo number_format($batch['current_weight'], 1); ?> কেজি</td>
                                <td>
                                    <span style="display: inline-block; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; <?php echo $batch['status'] === 'active' ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #b91c1c;'; ?>">
                                        <?php echo $batch['status'] === 'active' ? '✅ সক্রিয়' : '❌ বন্ধ'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button onclick="editBatch(<?php echo $batch['id']; ?>)">✏️</button>
                                    <button class="danger" onclick="deleteBatch(<?php echo $batch['id']; ?>, '<?php echo htmlspecialchars($batch['batch_no']); ?>')">🗑️</button>
                                </td>
                            </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 2rem; color: #6b7280;">
                                    কোন ব্যাচ নেই। নতুন ব্যাচ যোগ করুন।
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add Batch Modal -->
            <div id="addBatchModal" class="modal">
                <div class="modal-content">
                    <button class="modal-close" onclick="closeModal('addBatchModal')">&times;</button>
                    <h3 style="margin-bottom: 1rem;">➕ নতুন ব্যাচ যোগ করুন</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="add_batch" value="1">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>ব্যাচ নম্বর *</label>
                                <input type="text" name="batch_no" required placeholder="B-005">
                            </div>
                            <div class="form-group">
                                <label>মাছের ধরন *</label>
                                <input type="text" name="fish_name" required placeholder="যেমন: রুই, কাতল">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>ছাড়ের তারিখ *</label>
                            <input type="date" name="release_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>প্রাথমিক ওজন (কেজি) *</label>
                                <input type="number" name="initial_weight" step="0.01" required placeholder="10">
                            </div>
                            <div class="form-group">
                                <label>প্রাথমিক সংখ্যা *</label>
                                <input type="number" name="initial_count" required placeholder="1200">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>প্রাথমিক খরচ (টাকা) *</label>
                            <input type="number" name="initial_cost" step="0.01" required placeholder="5000">
                        </div>
                        
                        <button type="submit" class="success" style="width: 100%; padding: 0.75rem;">ব্যাচ তৈরি করুন</button>
                    </form>
                </div>
            </div>

        <?php elseif ($page === 'accounting'): ?>
            <h2 style="margin-bottom: 1rem;">💰 হিসাব ব্যবস্থা</h2>
            
            <?php
            $total_initial_cost = $pdo->query("SELECT SUM(initial_cost) as total FROM batches")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_feed_cost = $pdo->query("SELECT SUM(feed_cost) as total FROM feed_logs")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_health_cost = $pdo->query("SELECT SUM(cost) as total FROM health_logs")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_expenses = $pdo->query("SELECT SUM(amount) as total FROM expense_logs")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_cost = $total_initial_cost + $total_feed_cost + $total_health_cost + $total_expenses;
            
            $total_weight = $pdo->query("SELECT SUM(current_weight) as total FROM batches")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_income = $total_weight * ($settings['market_price_per_kg'] ?? 200);
            $profit = $total_income - $total_cost;
            ?>
            
            <div class="dashboard-grid">
                <div class="kpi-card">
                    <h3>মোট খরচ</h3>
                    <div class="value">৳<?php echo number_format($total_cost); ?></div>
                </div>
                <div class="kpi-card">
                    <h3>মোট আয়</h3>
                    <div class="value">৳<?php echo number_format($total_income); ?></div>
                </div>
                <div class="kpi-card">
                    <h3>লাভ/ক্ষতি</h3>
                    <div class="value" style="color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>">
                        ৳<?php echo number_format($profit); ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <h3>মুনাফা হার</h3>
                    <div class="value"><?php echo $total_cost > 0 ? number_format(($profit / $total_cost) * 100, 1) : '0'; ?>%</div>
                </div>
            </div>

            <div class="chart-container">
                <h3>খরচ বিশ্লেষণ</h3>
                <div class="dashboard-grid">
                    <div class="kpi-card">
                        <h3>প্রাথমিক খরচ</h3>
                        <div class="value">৳<?php echo number_format($total_initial_cost); ?></div>
                    </div>
                    <div class="kpi-card">
                        <h3>খাদ্য খরচ</h3>
                        <div class="value">৳<?php echo number_format($total_feed_cost); ?></div>
                    </div>
                    <div class="kpi-card">
                        <h3>স্বাস্থ্য খরচ</h3>
                        <div class="value">৳<?php echo number_format($total_health_cost); ?></div>
                    </div>
                    <div class="kpi-card">
                        <h3>অন্যান্য খরচ</h3>
                        <div class="value">৳<?php echo number_format($total_expenses); ?></div>
                    </div>
                </div>
            </div>

        <?php elseif ($page === 'analytics'): ?>
            <h2 style="margin-bottom: 1rem;">📈 বিশ্লেষণ</h2>
            <div class="alert info">
                লাইভ বিশ্লেষণ প্রতি ৬০ সেকেন্ডে আপডেট হয়। 
                <a href="?page=analytics" style="color: #0f766e;">রিফ্রেশ করুন</a>
            </div>
            
            <div class="dashboard-grid">
                <div class="kpi-card">
                    <h3>সর্বোচ্চ ওজন</h3>
                    <div class="value">
                        <?php 
                        $max_weight = $pdo->query("SELECT MAX(current_weight) as max FROM batches")->fetch(PDO::FETCH_ASSOC);
                        echo number_format($max_weight['max'] ?? 0, 1) . ' কেজি';
                        ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <h3>গড় ওজন/ব্যাচ</h3>
                    <div class="value">
                        <?php 
                        $avg_weight = $pdo->query("SELECT AVG(current_weight) as avg FROM batches WHERE status='active'")->fetch(PDO::FETCH_ASSOC);
                        echo number_format($avg_weight['avg'] ?? 0, 1) . ' কেজি';
                        ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <h3>মোট ব্যাচ</h3>
                    <div class="value">
                        <?php 
                        $total_batches = $pdo->query("SELECT COUNT(*) as count FROM batches")->fetch(PDO::FETCH_ASSOC);
                        echo $total_batches['count'] ?? 0;
                        ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <h3>সক্রিয় ব্যাচ</h3>
                    <div class="value">
                        <?php 
                        $active_batches = $pdo->query("SELECT COUNT(*) as count FROM batches WHERE status='active'")->fetch(PDO::FETCH_ASSOC);
                        echo $active_batches['count'] ?? 0;
                        ?>
                    </div>
                </div>
            </div>

        <?php elseif ($page === 'settings'): ?>
            <h2 style="margin-bottom: 1rem;">⚙️ সেটিংস</h2>
            
            <div class="table-container" style="max-width: 600px;">
                <div style="padding: 2rem;">
                    <div class="form-group">
                        <label>প্রকল্পের নাম:</label>
                        <input type="text" value="<?php echo htmlspecialchars($settings['project_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>পুকুরের গভীরতা (মিটার):</label>
                        <input type="text" value="<?php echo htmlspecialchars($settings['pond_depth']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>বাজার মূল্য (টাকা/কেজি):</label>
                        <input type="text" value="<?php echo htmlspecialchars($settings['market_price_per_kg']); ?>" readonly>
                    </div>
                    <div class="alert info">
                        সেটিংস পরিবর্তন করতে ডেভেলপারের সাথে যোগাযোগ করুন।
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="alert error">পৃষ্ঠা পাওয়া যায়নি।</div>
        <?php endif; ?>
    </div>

    <?php if (isLoggedIn()): ?>
        <div class="bottom-nav">
            <div class="bottom-nav-items">
                <a href="?page=dashboard" class="<?php echo $page === 'dashboard' ? 'active' : ''; ?>">📊 ড্যাশবোর্ড</a>
                <a href="?page=batches" class="<?php echo $page === 'batches' ? 'active' : ''; ?>">🐠 ব্যাচ</a>
                <a href="?page=feed" class="<?php echo $page === 'feed' ? 'active' : ''; ?>">🍽️ খাদ্য</a>
                <a href="?page=health" class="<?php echo $page === 'health' ? 'active' : ''; ?>">⚕️ স্বাস্থ্য</a>
                <a href="?page=accounting" class="<?php echo $page === 'accounting' ? 'active' : ''; ?>">💰 হিসাব</a>
                <a href="?page=settings" class="<?php echo $page === 'settings' ? 'active' : ''; ?>">⚙️ সেটিংস</a>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Modal functions
        function showAddBatch() {
            document.getElementById('addBatchModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function editBatch(id) {
            alert('সম্পাদনা ফিচার শীঘ্রই আসছে। ব্যাচ আইডি: ' + id);
        }

        function deleteBatch(id, batchNo) {
            if (confirm('⚠️ আপনি কি নিশ্চিত?\n\nব্যাচ "' + batchNo + '" মুছে ফেলা হবে। এই কাজটি বাতিল করা যাবে না।')) {
                window.location.href = '?page=batches&delete_batch=' + id + '&csrf_token=' + '<?php echo generateCSRFToken(); ?>';
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Handle logout confirmation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            if (this.querySelector('[name="logout"]')) {
                if (!confirm('আপনি কি লগ আউট করতে চান?')) {
                    e.preventDefault();
                }
            }
        });

        // Auto-refresh analytics page
        <?php if ($page === 'analytics'): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>
