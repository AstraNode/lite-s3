<?php
/**
 * Health Check Endpoint
 * AWS S3-compatible health check
 * 
 * Returns JSON health status for monitoring tools
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$health = [
    'status' => 'ok',
    'timestamp' => gmdate('c'),
    'version' => '1.0.0',
    'service' => 's3',
    'checks' => []
];

// Check if config exists
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    $health['checks']['config'] = [
        'status' => 'warning',
        'message' => 'Not installed - run install.php'
    ];
    $health['status'] = 'warning';
} else {
    // Check database
    try {
        require_once $configFile;
        // Note: config.php already defines getDB()
        
        $pdo = getDB();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1");
        $userCount = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM buckets");
        $bucketCount = $stmt->fetchColumn();
        
        $health['checks']['database'] = [
            'status' => 'ok',
            'connection' => 'connected',
            'users' => (int)$userCount,
            'buckets' => (int)$bucketCount
        ];
    } catch (Exception $e) {
        $health['checks']['database'] = [
            'status' => 'error',
            'error' => 'Connection failed'
        ];
        $health['status'] = 'error';
    }
}

// Check storage directory
$storagePath = __DIR__ . '/storage';
$storageOk = is_dir($storagePath) && is_writable($storagePath);
$health['checks']['storage'] = [
    'status' => $storageOk ? 'ok' : 'error',
    'writable' => $storageOk
];
if (!$storageOk) {
    $health['status'] = 'error';
}

// Check logs directory
$logsPath = __DIR__ . '/logs';
$logsOk = is_dir($logsPath) && is_writable($logsPath);
$health['checks']['logs'] = [
    'status' => $logsOk ? 'ok' : 'error',
    'writable' => $logsOk
];
if (!$logsOk) {
    $health['status'] = 'error';
}

// Check PHP extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'hash', 'openssl', 'mbstring'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

$health['checks']['extensions'] = [
    'status' => empty($missingExtensions) ? 'ok' : 'error',
    'loaded' => count($requiredExtensions) - count($missingExtensions),
    'required' => count($requiredExtensions)
];
if (!empty($missingExtensions)) {
    $health['checks']['extensions']['missing'] = $missingExtensions;
    $health['status'] = 'error';
}

// PHP info
$health['php'] = [
    'version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_upload' => ini_get('upload_max_filesize'),
    'post_max' => ini_get('post_max_size')
];

// Set HTTP status code
http_response_code($health['status'] === 'ok' ? 200 : ($health['status'] === 'warning' ? 200 : 503));

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
