<?php
/**
 * S3 Object Storage - Universal Configuration
 * 
 * Works EVERYWHERE:
 * ✅ Docker (auto-configured via environment variables)
 * ✅ LAMP (edit values below)
 * ✅ XAMPP (edit values below)  
 * ✅ Shared Hosting / cPanel (edit values below)
 * 
 * QUICK START:
 * 1. Copy this file to config.php
 * 2. Edit database credentials below (or use env vars)
 * 3. Import schema.sql into your database
 * 4. Access http://your-domain/admin/ (login: admin / admin123)
 */

// ============== DATABASE CONFIGURATION ==============
// Docker: Uses environment variables automatically
// XAMPP: localhost, root, '' (empty password)
// LAMP: localhost, root, your_password
// Shared Hosting: Get from cPanel > MySQL Databases

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 's3_storage');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));

// ============== SECURITY CONFIGURATION ==============
// Generate a random salt: bin2hex(random_bytes(32))
define('SECRET_SALT', 'CHANGE_THIS_TO_RANDOM_STRING_AT_LEAST_32_CHARS');

// Set to false in production
define('DEBUG_MODE', false);

// ============== S3 AUTH CONFIGURATION ==============
// Enable permissive auth mode (allows slight signature variations)
define('S3_PERMISSIVE_AUTH', true);

// Enable simple auth (just verify access key exists, skip signature check)
// Useful for testing with simple clients
define('S3_SIMPLE_AUTH', false);

// ============== RATE LIMITING ==============
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 1000);  // Max requests per window
define('RATE_LIMIT_WINDOW', 60);      // Window in seconds

// ============== SECURITY FEATURES ==============
define('MAX_LOGIN_ATTEMPTS', 5);      // Max failed logins before lockout
define('LOGIN_LOCKOUT_TIME', 900);    // Lockout duration (15 minutes)
define('SCAN_UPLOADS', true);         // Enable file security scanning
define('ALLOWED_FILE_TYPES', ['*']); // ['image/jpeg', 'image/png'] or ['*'] for all
define('PRESIGNED_URL_EXPIRY', 3600); // Default presigned URL expiry (1 hour)

// ============== PATH CONFIGURATION ==============
// Usually auto-detected, but can be set manually
define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('UPLOAD_PATH', sys_get_temp_dir() . '/');
define('LOGS_PATH', BASE_PATH . 'logs/');

// ============== FILE LIMITS ==============
// Maximum file size (5GB = 5 * 1024 * 1024 * 1024)
define('MAX_FILE_SIZE', 5368709120);
define('CHUNK_SIZE', 8192);  // Stream chunk size

// ============== SESSION CONFIGURATION ==============
// Session timeout in seconds (2 hours default)
define('SESSION_TIMEOUT', 7200);

// ============== CORS CONFIGURATION ==============
define('CORS_ALLOWED_ORIGINS', '*');  // '*' or comma-separated domains
define('CORS_MAX_AGE', 86400);        // Preflight cache time

// ============== DO NOT EDIT BELOW THIS LINE ==============
// Timezone
date_default_timezone_set('UTC');

// Error reporting based on debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Session configuration with security hardening
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    // Use secure cookies when HTTPS is available
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Database connection function
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
        } catch (PDOException $e) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                die('Database connection failed: ' . $e->getMessage());
            }
            die('Database connection failed. Please check your configuration.');
        }
    }
    
    return $pdo;
}

// Ensure required directories exist
$requiredDirs = [STORAGE_PATH, LOGS_PATH];
foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
