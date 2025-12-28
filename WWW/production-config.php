<?php
/**
 * Production Configuration
 * Production-ready settings for the S3 storage system
 */

// Production environment detection
define('IS_PRODUCTION', !file_exists(__DIR__ . '/.dev'));
// Debug mode toggle (create a .debug file to force verbose logging)
define('DEBUG_MODE', file_exists(__DIR__ . '/.debug') || !IS_PRODUCTION);

if (IS_PRODUCTION) {
    // Production settings
    ini_set('display_errors', DEBUG_MODE ? 1 : 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/error.log');
    error_reporting(DEBUG_MODE ? E_ALL : (E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED));
    
    // Security headers (only in web context)
    if (php_sapi_name() !== 'cli') {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    // Rate limiting (disabled as requested)
    define('RATE_LIMIT_ENABLED', false);
    define('RATE_LIMIT_REQUESTS', 1000);
    define('RATE_LIMIT_WINDOW', 60);
    
    // Security settings
    define('MAX_LOGIN_ATTEMPTS', 5);
    define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes
    define('SESSION_TIMEOUT', 3600); // 1 hour
    
    // Allow all file types
    define('ALLOWED_FILE_TYPES', ['*']);
    
    if (!defined('MAX_FILE_SIZE')) {
        // Effectively no limit (subject to server/container limits)
        define('MAX_FILE_SIZE', PHP_INT_MAX);
    }
    // Disable scanning to allow any content
    define('SCAN_UPLOADS', false);
    
} else {
    // Development settings
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/error.log');
    error_reporting(E_ALL);
    
    // Rate limiting (disabled in dev)
    define('RATE_LIMIT_ENABLED', false);
    define('RATE_LIMIT_REQUESTS', 1000);
    define('RATE_LIMIT_WINDOW', 60);
    
    // Security settings (relaxed for dev)
    define('MAX_LOGIN_ATTEMPTS', 10);
    define('LOGIN_LOCKOUT_TIME', 60);
    define('SESSION_TIMEOUT', 7200); // 2 hours
    
    // File upload security (relaxed for dev)
    define('ALLOWED_FILE_TYPES', ['*']); // Allow all file types in dev
    if (!defined('MAX_FILE_SIZE')) {
        define('MAX_FILE_SIZE', 5 * 1024 * 1024 * 1024); // 5GB in dev
    }
    define('SCAN_UPLOADS', false);
}

// Performance settings for large file support (5GB+)
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 43200); // 12h for large uploads
ini_set('max_input_time', 43200);
ini_set('default_socket_timeout', 30);
ini_set('output_buffering', 'Off'); // Required for large file streaming

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', IS_PRODUCTION ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Create storage directory if it doesn't exist
if (!is_dir(__DIR__ . '/storage')) {
    mkdir(__DIR__ . '/storage', 0755, true);
}

// Create meta directory if it doesn't exist
if (!is_dir(__DIR__ . '/meta')) {
    mkdir(__DIR__ . '/meta', 0755, true);
}
?>
