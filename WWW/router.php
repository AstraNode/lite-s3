<?php
/**
 * Main Router - Modular Version
 */

// Load core modules
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

// Load API modules
require_once __DIR__ . '/api/bucket.php';
require_once __DIR__ . '/api/object.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, x-amz-date, x-amz-content-sha256');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];

// Browser view - show landing page
$wantsHtml = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;
$hasAuth = !empty($_SERVER['HTTP_AUTHORIZATION']);

if ($method === 'GET' && $wantsHtml && !$hasAuth) {
    if (empty($path) || $path === 'index.php') {
        require __DIR__ . '/views/landing.php';
        exit;
    }
    header('Location: /admin/login.php');
    exit;
}

// S3 API - requires authentication
$user = authenticateRequest();
if (!$user) {
    S3Response::error('AccessDenied', 'Authentication required', 403);
    exit;
}

// Route S3 operations
$parts = $path ? explode('/', $path, 2) : [];
$bucketName = $parts[0] ?? '';
$objectKey = $parts[1] ?? '';

$bucketAPI = new BucketAPI();
$objectAPI = new ObjectAPI();

if (empty($bucketName)) {
    // Root: list buckets
    if ($method === 'GET') {
        $bucketAPI->listBuckets($user);
    }
} elseif (empty($objectKey)) {
    // Bucket operations
    switch ($method) {
        case 'GET':
            $objectAPI->listObjects($bucketName, $user, $_GET);
            break;
        case 'PUT':
            $bucketAPI->create($bucketName, $user);
            break;
        case 'DELETE':
            $bucketAPI->delete($bucketName, $user);
            break;
    }
} else {
    // Object operations
    switch ($method) {
        case 'GET':
            $objectAPI->get($bucketName, $objectKey, $user);
            break;
        case 'PUT':
            $objectAPI->put($bucketName, $objectKey, $user);
            break;
        case 'DELETE':
            $objectAPI->delete($bucketName, $objectKey, $user);
            break;
        case 'HEAD':
            $objectAPI->head($bucketName, $objectKey, $user);
            break;
    }
}
