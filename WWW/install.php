<?php
/**
 * S3 Object Storage - Installation Wizard
 * 
 * Upload this file to your web root and access it via browser.
 * DELETE THIS FILE AFTER INSTALLATION IS COMPLETE!
 */

$error = '';
$success = '';
$step = $_GET['step'] ?? 1;

// Security check - prevent CLI execution
if (php_sapi_name() === 'cli') {
    die("This script must be run from a web browser.\n");
}

// Check if already installed
if (file_exists(__DIR__ . '/.installed') && $step != 'complete') {
    die('<h1>Already Installed</h1><p>Delete .installed file to reinstall.</p>');
}

// Check requirements
function checkRequirements() {
    $requirements = [];
    
    // PHP version
    $requirements['php'] = [
        'name' => 'PHP 7.4+',
        'ok' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'value' => PHP_VERSION
    ];
    
    // Required extensions
    $extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'];
    foreach ($extensions as $ext) {
        $requirements[$ext] = [
            'name' => "ext-$ext",
            'ok' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? 'Loaded' : 'Missing'
        ];
    }
    
    // Writable directories
    $dirs = ['storage', 'logs', 'uploads'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        $writable = is_dir($path) ? is_writable($path) : is_writable(__DIR__);
        $requirements["dir_$dir"] = [
            'name' => "$dir/ writable",
            'ok' => $writable,
            'value' => $writable ? 'OK' : 'Not writable'
        ];
    }
    
    return $requirements;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['step'] == '2') {
        // Test database connection
        $host = trim($_POST['db_host']);
        $name = trim($_POST['db_name']);
        $user = trim($_POST['db_user']);
        $pass = trim($_POST['db_pass']);
        $port = (int)($_POST['db_port'] ?: 3306);
        
        // Admin credentials
        $adminUser = trim($_POST['admin_user'] ?? 'admin');
        $adminPass = trim($_POST['admin_pass'] ?? '');
        
        if (empty($adminPass) || strlen($adminPass) < 8) {
            $error = "Admin password must be at least 8 characters.";
            $step = 1;
        } else {
            try {
                $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                
                // Create tables from schema
                $schemaFile = __DIR__ . '/../schema.sql';
                if (!file_exists($schemaFile)) {
                    $schemaFile = dirname(__DIR__) . '/schema.sql';
                }
                
                if (file_exists($schemaFile)) {
                    $schema = file_get_contents($schemaFile);
                    // Execute each statement separately
                    $statements = array_filter(
                        array_map('trim', explode(';', $schema)),
                        fn($s) => !empty($s) && !preg_match('/^--/', trim($s))
                    );
                    foreach ($statements as $stmt) {
                        if (!empty(trim($stmt))) {
                            $pdo->exec($stmt);
                        }
                    }
                }
                
                // Create admin user with hashed password and secret key
                $accessKey = $adminUser;
                $secretKey = bin2hex(random_bytes(20)); // 40 char secret key for S3 API
                $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);
                
                // Check if admin exists - use access_key as the unique identifier
                $stmt = $pdo->prepare("SELECT id FROM users WHERE access_key = ?");
                $stmt->execute([$accessKey]);
                if ($stmt->fetch()) {
                    // Update existing user - store both the hash (for admin login) and plain secret (for S3 API)
                    $stmt = $pdo->prepare("UPDATE users SET secret_key = ?, plain_secret_key = ?, is_admin = 1, active = 1 WHERE access_key = ?");
                    $stmt->execute([$passwordHash, $secretKey, $accessKey]);
                } else {
                    // Insert new user with proper columns matching schema.sql
                    $stmt = $pdo->prepare("INSERT INTO users (username, access_key, secret_key, plain_secret_key, is_admin, active, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())");
                    $stmt->execute([$accessKey, $accessKey, $passwordHash, $secretKey]);
                }
                
                // Generate config
                $salt = bin2hex(random_bytes(32));
                $escapedPass = addslashes($pass);
                $config = "<?php
/**
 * S3 Object Storage Configuration
 * Generated: " . date('Y-m-d H:i:s') . "
 * 
 * IMPORTANT: Keep this file secure!
 */

// Database Configuration
define('DB_HOST', '$host');
define('DB_NAME', '$name');
define('DB_USER', '$user');
define('DB_PASS', '$escapedPass');
define('DB_PORT', $port);

// Security
define('SECRET_SALT', '$salt');
define('DEBUG_MODE', false);

// Paths
define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('LOGS_PATH', BASE_PATH . 'logs/');
define('UPLOADS_PATH', BASE_PATH . 'uploads/');
define('MAX_FILE_SIZE', 5368709120); // 5GB

// Session Settings
define('SESSION_TIMEOUT', 7200); // 2 hours

// S3 API Settings
define('S3_DEFAULT_REGION', 'us-east-1');
define('S3_PERMISSIVE_AUTH', false);
define('S3_SIMPLE_AUTH', true);

// Rate Limiting
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 1000);
define('RATE_LIMIT_WINDOW', 3600);

// Security Settings
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);
define('SCAN_UPLOADS', true);

// Timezone
date_default_timezone_set('UTC');

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOGS_PATH . 'php_errors.log');

// Session security
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Create required directories
foreach ([STORAGE_PATH, LOGS_PATH, UPLOADS_PATH] as \$dir) {
    if (!is_dir(\$dir)) {
        @mkdir(\$dir, 0755, true);
    }
}
";
                
                file_put_contents(__DIR__ . '/config.php', $config);
                chmod(__DIR__ . '/config.php', 0640);
                
                // Create directories
                $dirs = ['storage', 'logs', 'uploads'];
                foreach ($dirs as $dir) {
                    $path = __DIR__ . '/' . $dir;
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                    }
                }
                
                // Store admin credentials for display
                $_SESSION['install_access_key'] = $accessKey;
                $_SESSION['install_secret_key'] = $secretKey;
                
                // Mark as installed
                file_put_contents(__DIR__ . '/.installed', date('c'));
                
                header('Location: install.php?step=complete');
                exit;
                
            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
                $step = 1;
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
                $step = 1;
            }
        }
    }
}

$requirements = checkRequirements();
$allOk = !in_array(false, array_column($requirements, 'ok'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>S3 Storage - Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; }
        .card { background: rgba(255,255,255,0.98); }
        .requirement-ok { color: #198754; }
        .requirement-fail { color: #dc3545; }
        code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body class="d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">🚀 S3 Storage Installation</h2>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($step == 'complete'): ?>
                            <div class="alert alert-success">
                                <h4><i class="bi bi-check-circle"></i> Installation Complete!</h4>
                                <p class="mb-0">Your S3-compatible storage is ready.</p>
                            </div>
                            
                            <div class="bg-light p-4 rounded mb-4">
                                <h5><i class="bi bi-key"></i> Your API Credentials</h5>
                                <p class="text-muted small mb-3">Save these credentials securely. The secret key is shown only once!</p>
                                
                                <div class="mb-2">
                                    <strong>Access Key ID:</strong><br>
                                    <code class="fs-6"><?= htmlspecialchars($_SESSION['install_access_key'] ?? 'admin') ?></code>
                                </div>
                                <div class="mb-3">
                                    <strong>Secret Access Key:</strong><br>
                                    <code class="fs-6 text-danger"><?= htmlspecialchars($_SESSION['install_secret_key'] ?? 'Check config') ?></code>
                                </div>
                                
                                <div class="alert alert-warning mb-0 small">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    <strong>Important:</strong> Copy and save the secret key now. It cannot be retrieved later.
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <a href="admin/login.php" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> Go to Admin Panel
                                </a>
                            </div>
                            
                            <div class="mt-4 p-3 bg-danger bg-opacity-10 rounded text-center">
                                <i class="bi bi-trash text-danger"></i>
                                <strong class="text-danger">Delete install.php immediately!</strong>
                            </div>
                            
                        <?php else: ?>
                            <!-- Requirements Check -->
                            <div class="mb-4">
                                <h5><i class="bi bi-gear"></i> System Requirements</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <?php foreach ($requirements as $key => $req): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($req['name']) ?></td>
                                            <td class="<?= $req['ok'] ? 'requirement-ok' : 'requirement-fail' ?>">
                                                <?php if ($req['ok']): ?>
                                                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($req['value']) ?>
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle"></i> <?= htmlspecialchars($req['value']) ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                                <?php if (!$allOk): ?>
                                    <div class="alert alert-warning small">
                                        <i class="bi bi-exclamation-triangle"></i> Some requirements are not met. Installation may fail.
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="step" value="2">
                                
                                <h5 class="mb-3"><i class="bi bi-database"></i> Database Configuration</h5>
                                <p class="text-muted small">Enter your MySQL database details from cPanel or your hosting control panel.</p>
                                
                                <div class="row">
                                    <div class="col-8 mb-3">
                                        <label class="form-label">Database Host</label>
                                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                                    </div>
                                    <div class="col-4 mb-3">
                                        <label class="form-label">Port</label>
                                        <input type="number" name="db_port" class="form-control" value="3306">
                                    </div>
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
                                
                                <hr class="my-4">
                                
                                <h5 class="mb-3"><i class="bi bi-person-badge"></i> Admin Account</h5>
                                
                                <div class="mb-3">
                                    <label class="form-label">Admin Username (Access Key)</label>
                                    <input type="text" name="admin_user" class="form-control" value="admin" required>
                                    <div class="form-text">This will be your S3 Access Key ID</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Admin Password</label>
                                    <input type="password" name="admin_pass" class="form-control" required minlength="8" placeholder="Minimum 8 characters">
                                    <div class="form-text">For admin panel login. A separate S3 Secret Key will be generated.</div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 btn-lg mt-3" <?= !$allOk ? 'onclick="return confirm(\'Some requirements are not met. Continue anyway?\')"' : '' ?>>
                                    <i class="bi bi-download"></i> Install Now
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="text-center mt-3 text-white-50 small">
                    S3-Compatible Storage System &bull; LAMP/XAMPP Ready
                </div>
            </div>
        </div>
    </div>
</body>
</html>
