<?php
/**
 * S3 Object Storage - Configuration Template
 * 
 * Copy this file to config.php and edit the values below.
 * For shared hosting (cPanel), use the MySQL credentials from cPanel.
 */

// ============== DATABASE CONFIGURATION ==============
// Get these from cPanel > MySQL Databases
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');   // e.g., username_s3storage
define('DB_USER', 'your_database_user');   // e.g., username_s3user
define('DB_PASS', 'your_database_password');
define('DB_PORT', 3306);

// ============== SECURITY CONFIGURATION ==============
// Generate a random salt: bin2hex(random_bytes(32))
define('SECRET_SALT', 'CHANGE_THIS_TO_RANDOM_STRING_AT_LEAST_32_CHARS');

// Set to false in production
define('DEBUG_MODE', false);

// ============== PATH CONFIGURATION ==============
// Usually auto-detected, but can be set manually
define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('UPLOAD_PATH', sys_get_temp_dir() . '/');

// ============== FILE LIMITS ==============
// Maximum file size (5GB = 5 * 1024 * 1024 * 1024)
define('MAX_FILE_SIZE', 5368709120);

// ============== SESSION CONFIGURATION ==============
// Session timeout in seconds (2 hours default)
define('SESSION_TIMEOUT', 7200);

// ============== DO NOT EDIT BELOW THIS LINE ==============
// Timezone
date_default_timezone_set('UTC');

// Error reporting
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Database connection function
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

// Ensure storage directory exists
if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0755, true);
}
