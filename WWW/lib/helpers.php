<?php
/**
 * Utility Helpers
 * S3-Compatible Storage System
 */

/**
 * Format bytes to human readable string
 */
function formatBytes($size, $precision = 1) {
    if ($size == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Get Bootstrap icon for file type
 */
function getFileIcon($mimeType) {
    $icons = [
        'image/' => 'bi-file-earmark-image',
        'video/' => 'bi-file-earmark-play',
        'audio/' => 'bi-file-earmark-music',
        'text/' => 'bi-file-earmark-text',
        'application/pdf' => 'bi-file-earmark-pdf',
        'application/zip' => 'bi-file-earmark-zip',
        'application/x-rar' => 'bi-file-earmark-zip',
        'application/x-7z' => 'bi-file-earmark-zip',
        'application/gzip' => 'bi-file-earmark-zip',
        'application/json' => 'bi-file-earmark-code',
        'application/xml' => 'bi-file-earmark-code',
        'application/javascript' => 'bi-file-earmark-code',
        'text/html' => 'bi-file-earmark-code',
        'text/css' => 'bi-file-earmark-code',
    ];
    foreach ($icons as $prefix => $icon) {
        if (strpos($mimeType ?? '', $prefix) === 0) return $icon;
    }
    return 'bi-file-earmark';
}

/**
 * Generate ETag from content
 */
function generateETag($content) {
    return md5($content);
}

/**
 * Get MIME type from filename extension
 */
function getMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        // Text
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'rtf' => 'text/rtf',
        
        // Application
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'wasm' => 'application/wasm',
        
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'avif' => 'image/avif',
        
        // Video
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'flv' => 'video/x-flv',
        'wmv' => 'video/x-ms-wmv',
        
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
        'oga' => 'audio/ogg',
        'wma' => 'audio/x-ms-wma',
        
        // Fonts
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];
    return $types[$ext] ?? 'application/octet-stream';
}

/**
 * Generate a unique request ID (AWS-style)
 */
function generateRequestId() {
    return strtoupper(bin2hex(random_bytes(8)));
}

/**
 * Generate AWS-style extended request ID
 */
function generateExtendedRequestId() {
    return base64_encode(random_bytes(32));
}

/**
 * Sanitize filename for storage
 */
function sanitizeFilename($filename) {
    // Remove path traversal attempts
    $filename = basename($filename);
    // Remove null bytes
    $filename = str_replace("\0", '', $filename);
    // Remove control characters
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
    return $filename;
}

/**
 * Check if path is safe (no traversal)
 */
function isPathSafe($path) {
    // Normalize path separators
    $path = str_replace('\\', '/', $path);
    // Check for directory traversal
    if (strpos($path, '..') !== false) {
        return false;
    }
    // Check for absolute paths
    if (preg_match('/^\/|^[A-Za-z]:/', $path)) {
        return false;
    }
    return true;
}

/**
 * Format ISO 8601 timestamp
 */
function formatISO8601($timestamp = null) {
    $time = $timestamp ? strtotime($timestamp) : time();
    return gmdate('Y-m-d\TH:i:s.000\Z', $time);
}

/**
 * Format AWS date (YYYYMMDD)
 */
function formatAWSDate($timestamp = null) {
    $time = $timestamp ? strtotime($timestamp) : time();
    return gmdate('Ymd', $time);
}

/**
 * Format AWS timestamp (YYYYMMDDTHHMMSSZ)
 */
function formatAWSTimestamp($timestamp = null) {
    $time = $timestamp ? strtotime($timestamp) : time();
    return gmdate('Ymd\THis\Z', $time);
}

/**
 * Get client IP address (handles proxies)
 */
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // General proxy
        'HTTP_X_REAL_IP',            // Nginx proxy
        'REMOTE_ADDR'                // Direct connection
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate secure random token
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}
