<?php
/**
 * Main Router - Modular Version
 * S3-Compatible API Router with enhanced security
 */

// Load core modules
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/s3-errors.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/aws-auth.php';
require_once __DIR__ . '/lib/helpers.php';

// Load API modules
require_once __DIR__ . '/api/bucket.php';
require_once __DIR__ . '/api/object.php';
require_once __DIR__ . '/api/advanced.php';

// CORS headers with proper S3 compatibility
$corsOrigin = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, x-amz-date, x-amz-content-sha256, x-amz-user-agent, x-amz-target, x-amz-copy-source, x-amz-acl, x-amz-meta-*, Range, If-Match, If-None-Match, If-Modified-Since, If-Unmodified-Since');
header('Access-Control-Expose-Headers: ETag, x-amz-request-id, x-amz-id-2, Content-Length, Last-Modified, x-amz-version-id');
header('Access-Control-Max-Age: ' . (defined('CORS_MAX_AGE') ? CORS_MAX_AGE : 86400));

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];

// Browser view - show landing page
$wantsHtml = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;
$hasAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_GET['X-Amz-Credential']) || !empty($_GET['AWSAccessKeyId']);

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
    S3Response::error(S3ErrorCodes::ACCESS_DENIED, 'Authentication required');
    exit;
}

// Route S3 operations
$parts = $path ? explode('/', $path, 2) : [];
$bucketName = $parts[0] ?? '';
$objectKey = $parts[1] ?? '';

$bucketAPI = new BucketAPI();
$objectAPI = new ObjectAPI();
$advancedAPI = new AdvancedS3API();

if (empty($bucketName)) {
    // Root: list buckets
    if ($method === 'GET') {
        $bucketAPI->listBuckets($user);
    } else {
        S3Response::error(S3ErrorCodes::METHOD_NOT_ALLOWED);
    }
} elseif (empty($objectKey)) {
    // Bucket operations
    switch ($method) {
        case 'GET':
            // Check for special bucket operations
            if (isset($_GET['location'])) {
                $bucketAPI->getLocation($bucketName, $user);
            } elseif (isset($_GET['versioning'])) {
                $advancedAPI->getBucketVersioning($bucketName, $user);
            } elseif (isset($_GET['uploads'])) {
                $objectAPI->listMultipartUploads($bucketName, $user);
            } elseif (isset($_GET['tagging'])) {
                $advancedAPI->getBucketTagging($bucketName, $user);
            } elseif (isset($_GET['cors'])) {
                $advancedAPI->getBucketCors($bucketName, $user);
            } elseif (isset($_GET['lifecycle'])) {
                $advancedAPI->getBucketLifecycle($bucketName, $user);
            } elseif (isset($_GET['policy'])) {
                $advancedAPI->getBucketPolicy($bucketName, $user);
            } else {
                $objectAPI->listObjects($bucketName, $user, $_GET);
            }
            break;
        case 'PUT':
            if (isset($_GET['versioning'])) {
                $advancedAPI->putBucketVersioning($bucketName, $user);
            } elseif (isset($_GET['tagging'])) {
                $advancedAPI->putBucketTagging($bucketName, $user);
            } elseif (isset($_GET['cors'])) {
                $advancedAPI->putBucketCors($bucketName, $user);
            } elseif (isset($_GET['lifecycle'])) {
                $advancedAPI->putBucketLifecycle($bucketName, $user);
            } elseif (isset($_GET['policy'])) {
                $advancedAPI->putBucketPolicy($bucketName, $user);
            } else {
                $bucketAPI->create($bucketName, $user);
            }
            break;
        case 'DELETE':
            if (isset($_GET['tagging'])) {
                $advancedAPI->deleteBucketTagging($bucketName, $user);
            } elseif (isset($_GET['cors'])) {
                $advancedAPI->deleteBucketCors($bucketName, $user);
            } elseif (isset($_GET['lifecycle'])) {
                $advancedAPI->deleteBucketLifecycle($bucketName, $user);
            } elseif (isset($_GET['policy'])) {
                $advancedAPI->deleteBucketPolicy($bucketName, $user);
            } else {
                $bucketAPI->delete($bucketName, $user);
            }
            break;
        case 'POST':
            if (isset($_GET['delete'])) {
                $advancedAPI->deleteObjects($bucketName, $user);
            } else {
                S3Response::error(S3ErrorCodes::METHOD_NOT_ALLOWED);
            }
            break;
        case 'HEAD':
            $bucketAPI->head($bucketName, $user);
            break;
        default:
            S3Response::error(S3ErrorCodes::METHOD_NOT_ALLOWED);
    }
} else {
    // Object operations
    switch ($method) {
        case 'GET':
            if (isset($_GET['tagging'])) {
                $advancedAPI->getObjectTagging($bucketName, $objectKey, $user);
            } else {
                $objectAPI->get($bucketName, $objectKey, $user);
            }
            break;
        case 'PUT':
            if (isset($_GET['tagging'])) {
                $advancedAPI->putObjectTagging($bucketName, $objectKey, $user);
            } elseif (!empty($_SERVER['HTTP_X_AMZ_COPY_SOURCE'])) {
                // Check if this is a copy operation
                $objectAPI->copy($bucketName, $objectKey, $user, $_SERVER['HTTP_X_AMZ_COPY_SOURCE']);
            } else {
                $objectAPI->put($bucketName, $objectKey, $user);
            }
            break;
        case 'DELETE':
            if (isset($_GET['tagging'])) {
                $advancedAPI->deleteObjectTagging($bucketName, $objectKey, $user);
            } else {
                $objectAPI->delete($bucketName, $objectKey, $user);
            }
            break;
        case 'HEAD':
            $objectAPI->head($bucketName, $objectKey, $user);
            break;
        case 'POST':
            // Handle multipart uploads
            if (isset($_GET['uploads'])) {
                $objectAPI->initiateMultipartUpload($bucketName, $objectKey, $user);
            } elseif (isset($_GET['uploadId'])) {
                $objectAPI->completeMultipartUpload($bucketName, $objectKey, $user, $_GET['uploadId']);
            } else {
                $objectAPI->put($bucketName, $objectKey, $user);
            }
            break;
        default:
            S3Response::error(S3ErrorCodes::METHOD_NOT_ALLOWED);
    }
}
