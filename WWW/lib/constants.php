<?php
/**
 * Constants & Configuration
 * S3-Compatible Storage System
 * 
 * Note: Many settings can be overridden in config.php
 */

// Paths (use defined check for idempotency)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . '/');
}

// Storage paths with fallback defaults
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . 'storage/');
}
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', BASE_PATH . 'logs/');
}
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', BASE_PATH . 'uploads/');
}

// File size limits
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 5 * 1024 * 1024 * 1024); // 5GB default
}
if (!defined('CHUNK_SIZE')) {
    define('CHUNK_SIZE', 8192); // 8KB chunks for streaming
}
if (!defined('MAX_OBJECT_KEY_LENGTH')) {
    define('MAX_OBJECT_KEY_LENGTH', 1024);
}

// Multipart upload settings
if (!defined('MIN_PART_SIZE')) {
    define('MIN_PART_SIZE', 5 * 1024 * 1024); // 5MB min part
}
if (!defined('MAX_PART_SIZE')) {
    define('MAX_PART_SIZE', 5 * 1024 * 1024 * 1024); // 5GB max part
}
if (!defined('MAX_PARTS')) {
    define('MAX_PARTS', 10000);
}

// Security settings
if (!defined('SECRET_SALT')) {
    define('SECRET_SALT', 'change-this-salt-in-production');
}
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 3600); // 1 hour
}

// Authentication settings
if (!defined('S3_PERMISSIVE_AUTH')) {
    define('S3_PERMISSIVE_AUTH', false);
}
if (!defined('S3_SIMPLE_AUTH')) {
    define('S3_SIMPLE_AUTH', true); // Allow basic auth
}
if (!defined('PRESIGNED_URL_MAX_EXPIRY')) {
    define('PRESIGNED_URL_MAX_EXPIRY', 604800); // 7 days
}

// Rate limiting
if (!defined('RATE_LIMIT_ENABLED')) {
    define('RATE_LIMIT_ENABLED', true);
}
if (!defined('RATE_LIMIT_REQUESTS')) {
    define('RATE_LIMIT_REQUESTS', 1000);
}
if (!defined('RATE_LIMIT_WINDOW')) {
    define('RATE_LIMIT_WINDOW', 3600); // 1 hour
}

// Login security
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', 5);
}
if (!defined('LOGIN_LOCKOUT_TIME')) {
    define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
}

// File security
if (!defined('SCAN_UPLOADS')) {
    define('SCAN_UPLOADS', true);
}

// S3 API settings
if (!defined('S3_DEFAULT_REGION')) {
    define('S3_DEFAULT_REGION', 'us-east-1');
}
if (!defined('S3_SERVICE')) {
    define('S3_SERVICE', 's3');
}

// Dangerous file extensions to block
if (!defined('DANGEROUS_EXTENSIONS')) {
    define('DANGEROUS_EXTENSIONS', serialize([
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
        'pl', 'cgi', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'scr',
        'htaccess', 'htpasswd', 'ini', 'conf', 'asp', 'aspx', 'jsp', 'jspx'
    ]));
}

// Production mode settings
if (!file_exists(BASE_PATH . '.dev')) {
    // Production: disable error display
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOGS_PATH . 'php_errors.log');
} else {
    // Development: show errors
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
