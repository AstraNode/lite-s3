<?php
/**
 * S3 Object Storage - Installation Wizard
 * 
 * Upload this file to your web root and access it via browser.
 * Delete this file after installation is complete!
 */

$error = '';
$success = '';
$step = $_GET['step'] ?? 1;

// Check if already installed
if (file_exists(__DIR__ . '/.installed') && $step != 'complete') {
    die('<h1>Already Installed</h1><p>Delete .installed file to reinstall.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['step'] == '2') {
        // Test database connection
        $host = trim($_POST['db_host']);
        $name = trim($_POST['db_name']);
        $user = trim($_POST['db_user']);
        $pass = trim($_POST['db_pass']);
        $port = (int)($_POST['db_port'] ?: 3306);
        
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // Create tables
            $schema = file_get_contents(__DIR__ . '/../schema.sql');
            $pdo->exec($schema);
            
            // Generate config
            $salt = bin2hex(random_bytes(32));
            $config = "<?php
// S3 Object Storage Configuration
// Generated: " . date('Y-m-d H:i:s') . "

define('DB_HOST', '$host');
define('DB_NAME', '$name');
define('DB_USER', '$user');
define('DB_PASS', '$pass');
define('DB_PORT', $port);

define('SECRET_SALT', '$salt');
define('DEBUG_MODE', false);

define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('UPLOAD_PATH', sys_get_temp_dir() . '/');
define('MAX_FILE_SIZE', 5368709120);
define('SESSION_TIMEOUT', 7200);

date_default_timezone_set('UTC');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = \"mysql:host=\" . DB_HOST . \";port=\" . DB_PORT . \";dbname=\" . DB_NAME . \";charset=utf8mb4\";
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }
    return \$pdo;
}

if (!is_dir(STORAGE_PATH)) mkdir(STORAGE_PATH, 0755, true);
";
            
            file_put_contents(__DIR__ . '/config.php', $config);
            
            // Create storage directory
            if (!is_dir(__DIR__ . '/storage')) {
                mkdir(__DIR__ . '/storage', 0755, true);
            }
            
            // Mark as installed
            file_put_contents(__DIR__ . '/.installed', date('c'));
            
            header('Location: install.php?step=complete');
            exit;
            
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
            $step = 1;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>S3 Storage - Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; }
        .card { background: rgba(255,255,255,0.95); }
    </style>
</head>
<body class="d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">🚀 S3 Storage Installation</h2>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($step == 'complete'): ?>
                            <div class="alert alert-success">
                                <h4>✅ Installation Complete!</h4>
                                <p>Your S3-compatible storage is ready.</p>
                            </div>
                            <div class="bg-light p-3 rounded mb-3">
                                <strong>Default Login:</strong><br>
                                Access Key: <code>admin</code><br>
                                Secret Key: <code>admin123</code><br>
                                <span class="text-danger">⚠️ Change these immediately!</span>
                            </div>
                            <a href="admin/login.php" class="btn btn-primary w-100">Go to Admin Panel</a>
                            <div class="mt-3 text-center text-muted small">
                                <strong>Important:</strong> Delete install.php now!
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="step" value="2">
                                
                                <h5 class="mb-3">Database Configuration</h5>
                                <p class="text-muted small">Enter your MySQL database details from cPanel.</p>
                                
                                <div class="mb-3">
                                    <label class="form-label">Database Host</label>
                                    <input type="text" name="db_host" class="form-control" value="localhost" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Database Name</label>
                                    <input type="text" name="db_name" class="form-control" required placeholder="e.g., username_s3storage">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Database User</label>
                                    <input type="text" name="db_user" class="form-control" required placeholder="e.g., username_s3user">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Database Password</label>
                                    <input type="password" name="db_pass" class="form-control" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Database Port</label>
                                    <input type="number" name="db_port" class="form-control" value="3306">
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">Install Now</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
