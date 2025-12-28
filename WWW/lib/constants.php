<?php
/**
 * Constants & Configuration
 */

// Paths (use defined check for idempotency)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . '/');
    define('STORAGE_PATH', BASE_PATH . 'storage/');
    define('LOGS_PATH', BASE_PATH . 'logs/');
    define('UPLOADS_PATH', BASE_PATH . 'uploads/');
    
    // Limits
    define('MAX_FILE_SIZE', PHP_INT_MAX);
    define('CHUNK_SIZE', 8192);
    
    // Security
    define('SECRET_SALT', 'change-this-salt-in-production');
    define('SESSION_TIMEOUT', 3600);
    
    // Production settings
    if (!file_exists(BASE_PATH . '.dev')) {
        error_reporting(0);
        ini_set('display_errors', 0);
    }
}
