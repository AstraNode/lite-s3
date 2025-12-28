<?php
/**
 * Utility Helpers
 */

function formatBytes($size, $precision = 1) {
    if ($size == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function getFileIcon($mimeType) {
    $icons = [
        'image/' => 'bi-file-earmark-image',
        'video/' => 'bi-file-earmark-play',
        'audio/' => 'bi-file-earmark-music',
        'text/' => 'bi-file-earmark-text',
        'application/pdf' => 'bi-file-earmark-pdf',
        'application/zip' => 'bi-file-earmark-zip',
    ];
    foreach ($icons as $prefix => $icon) {
        if (strpos($mimeType ?? '', $prefix) === 0) return $icon;
    }
    return 'bi-file-earmark';
}

function generateETag($content) {
    return md5($content);
}

function getMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'txt' => 'text/plain', 'html' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'json' => 'application/json',
        'xml' => 'application/xml', 'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
    ];
    return $types[$ext] ?? 'application/octet-stream';
}
