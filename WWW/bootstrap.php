<?php
/**
 * Centralized Configuration
 * All app settings in one place - environment-driven
 */

// Prevent double loading
if (defined('CONFIG_LOADED')) return;
define('CONFIG_LOADED', true);

// =============================================================================
// PATHS
// =============================================================================
define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('LOGS_PATH', BASE_PATH . 'logs/');
define('UPLOADS_PATH', BASE_PATH . 'uploads/');
define('META_PATH', BASE_PATH . 'meta/');

// =============================================================================
// DATABASE (Environment-driven)
// =============================================================================
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 's3_storage');
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 's3user');
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: 's3pass123');
define('DB_PORT', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');

// =============================================================================
// FILE LIMITS
// =============================================================================
define('MAX_FILE_SIZE', 5 * 1024 * 1024 * 1024); // 5GB
define('CHUNK_SIZE', 8 * 1024);                   // 8KB chunks for streaming

// =============================================================================
// SECURITY
// =============================================================================
define('SECRET_SALT', $_ENV['SECRET_SALT'] ?? getenv('SECRET_SALT') ?: 'change-this-in-production');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60); // seconds

// =============================================================================
// MODE DETECTION
// =============================================================================
define('IS_DEV', file_exists(BASE_PATH . '.dev'));
define('IS_DEBUG', file_exists(BASE_PATH . '.debug'));
define('IS_INSTALLED', file_exists(BASE_PATH . '.installed'));

// =============================================================================
// PHP SETTINGS FOR LARGE FILES
// =============================================================================
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 43200);  // 12 hours
ini_set('max_input_time', 43200);
ini_set('post_max_size', '6G');
ini_set('upload_max_filesize', '6G');
ini_set('output_buffering', 'Off');

if (!IS_DEV) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// =============================================================================
// DATABASE CONNECTION (Singleton)
// =============================================================================
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }
    
    return $pdo;
}

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $exp = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $exp), $precision) . ' ' . $units[$exp];
}

function getMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'txt' => 'text/plain', 'html' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'json' => 'application/json',
        'xml' => 'application/xml', 'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'gz' => 'application/gzip',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
    ];
    return $types[$ext] ?? 'application/octet-stream';
}

function logError($message) {
    $logFile = LOGS_PATH . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// =============================================================================
// ENSURE DIRECTORIES EXIST
// =============================================================================
foreach ([STORAGE_PATH, LOGS_PATH, UPLOADS_PATH, META_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// =============================================================================
// AUTO-INSTALL DATABASE
// =============================================================================
if (!IS_INSTALLED) {
    try {
        $pdo = getDB();
        
        // Create tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                access_key VARCHAR(255) UNIQUE NOT NULL,
                secret_key VARCHAR(255) NOT NULL,
                is_admin BOOLEAN DEFAULT FALSE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;
            
            CREATE TABLE IF NOT EXISTS buckets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) UNIQUE NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;
            
            CREATE TABLE IF NOT EXISTS objects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bucket_id INT NOT NULL,
                object_key VARCHAR(1024) NOT NULL,
                size BIGINT DEFAULT 0,
                etag VARCHAR(64),
                mime_type VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (bucket_id) REFERENCES buckets(id),
                UNIQUE KEY (bucket_id, object_key(255))
            ) ENGINE=InnoDB;
            
            CREATE TABLE IF NOT EXISTS permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                bucket_id INT NOT NULL,
                permission ENUM('read', 'write', 'admin') DEFAULT 'read',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (bucket_id) REFERENCES buckets(id)
            ) ENGINE=InnoDB;
        ");
        
        // Create default admin user if not exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE access_key = 'admin'");
        if ($stmt->fetchColumn() == 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, access_key, secret_key, is_admin) VALUES (?, ?, ?, ?)")
                ->execute(['admin', 'admin', $hash, true]);
            
            // Create demo bucket
            $userId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO buckets (name, user_id) VALUES (?, ?)")->execute(['Demo', $userId]);
            $bucketId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, 'admin')")
                ->execute([$userId, $bucketId]);
        }
        
        file_put_contents(BASE_PATH . '.installed', date('c'));
    } catch (Exception $e) {
        logError("Install failed: " . $e->getMessage());
    }
}
