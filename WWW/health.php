<?php
/**
 * Health Check Endpoint
 * Simple endpoint to check system status
 */

header('Content-Type: application/json');

$health = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'version' => '1.0.0',
    'checks' => []
];

// Check database
try {
    require_once 'config.php';
    $pdo = getDB();
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    $health['checks']['database'] = [
        'status' => 'ok',
        'users' => $userCount
    ];
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    $health['status'] = 'error';
}

// Check storage directory
$storagePath = __DIR__ . '/storage';
if (is_dir($storagePath) && is_writable($storagePath)) {
    $health['checks']['storage'] = [
        'status' => 'ok',
        'path' => $storagePath
    ];
} else {
    $health['checks']['storage'] = [
        'status' => 'error',
        'error' => 'Storage directory not writable'
    ];
    $health['status'] = 'error';
}

// Check logs directory
$logsPath = __DIR__ . '/logs';
if (is_dir($logsPath) && is_writable($logsPath)) {
    $health['checks']['logs'] = [
        'status' => 'ok',
        'path' => $logsPath
    ];
} else {
    $health['checks']['logs'] = [
        'status' => 'error',
        'error' => 'Logs directory not writable'
    ];
    $health['status'] = 'error';
}

// Check PHP extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'hash'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (empty($missingExtensions)) {
    $health['checks']['extensions'] = [
        'status' => 'ok',
        'loaded' => $requiredExtensions
    ];
} else {
    $health['checks']['extensions'] = [
        'status' => 'error',
        'missing' => $missingExtensions
    ];
    $health['status'] = 'error';
}

// Set HTTP status code
http_response_code($health['status'] === 'ok' ? 200 : 503);

echo json_encode($health, JSON_PRETTY_PRINT);
?>
