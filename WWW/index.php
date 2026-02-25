<?php
/**
 * Complete AWS S3 and MinIO Compatible Storage System
 * Main entry point with comprehensive logging and full S3 API support
 */

// Router functionality - handle all requests including static files
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($uri, PHP_URL_PATH) ?: '/';

// For PHP built-in server, we need to handle all requests through index.php
// Only serve static files directly if they exist and it's a GET request
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri) && !str_ends_with($uri, '.php')) {
    $mimeType = mime_content_type(__DIR__ . $uri);
    header('Content-Type: ' . $mimeType);
    readfile(__DIR__ . $uri);
    exit;
}

// Check if installation is needed
if (!file_exists(__DIR__ . '/.installed')) {
    header('Location: /install.php');
    exit;
}

// Otherwise, continue with the main application
require_once 'config.php';
require_once 'lib/db.php';
require_once 'lib/helpers.php';
require_once 'performance-monitor.php';
require_once 'auth.php';
require_once 'storage.php';
require_once 's3-api.php';
require_once 'rate-limiter.php';
require_once 'security.php';

// Enhanced logging function with S3 operation details
function logRequest($method, $uri, $status, $responseTime = null, $details = '')
{
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? '0';

    // Extract S3 operation details
    $s3Operation = $_SERVER['HTTP_X_AMZ_TARGET'] ?? $_GET['x-id'] ?? 'Unknown';
    $bucket = '';
    $object = '';

    // Parse URI for bucket and object
    if (preg_match('#^/([^/]+)(?:/(.*))?$#', $uri, $matches)) {
        $bucket = $matches[1] ?? '';
        $object = $matches[2] ?? '';
    }

    $logMessage = sprintf(
        "[%s] %s:%s [%d]: %s %s (Size: %s, Time: %sms, UA: %s) S3-OP: %s, Bucket: %s, Object: %s, Details: %s",
        $timestamp,
        $clientIP,
        $_SERVER['REMOTE_PORT'] ?? 'unknown',
        $status,
        $method,
        $uri,
        $contentLength,
        $responseTime ? round($responseTime * 1000, 2) : 'N/A',
        substr($userAgent, 0, 50),
        $s3Operation,
        $bucket,
        $object,
        $details
    );

    error_log($logMessage);
}

// Start request timing
$requestStart = microtime(true);

// Check rate limiting
if (!checkRateLimit()) {
    http_response_code(429);
    header('Retry-After: ' . RATE_LIMIT_WINDOW);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'SlowDown', 'message' => 'Please reduce your request rate']);
    exit;
}

// Initialize performance monitor
$perfMonitor = new PerformanceMonitor();
error_log("REQUEST: method=" . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ", uri=" . ($_SERVER['REQUEST_URI'] ?? '/') . ", ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Initialize S3 API
$s3api = new S3API();

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, HEAD, OPTIONS, COPY');
header('Access-Control-Allow-Headers: Authorization, Content-Type, x-amz-date, x-amz-content-sha256, x-amz-user-agent, x-amz-target, x-amz-copy-source, x-amz-acl');
header('Access-Control-Expose-Headers: ETag, x-amz-request-id, x-amz-id-2, Content-Length, Last-Modified');

// Handle preflight requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $s3api->handleOptions();
    logRequest('OPTIONS', $_SERVER['REQUEST_URI'], 200, microtime(true) - $requestStart, 'CORS preflight');
    exit;
}

// Register shutdown function to log final response
register_shutdown_function(function () use ($requestStart, $perfMonitor) {
    $responseTime = microtime(true) - $requestStart;
    $status = http_response_code() ?: 200;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // Get additional details
    $details = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'AWS4-HMAC-SHA256') === 0) {
            $details = 'AWS4-Auth';
        } elseif (strpos($auth, 'AWS ') === 0) {
            $details = 'AWS-Auth';
        } else {
            $details = 'Other-Auth';
        }
    } else {
        $details = 'No-Auth';
    }

    // Log to both systems
    logRequest($method, $uri, $status, $responseTime, $details);
    $perfMonitor->logRequest($method, $uri, $responseTime, $status, $details);
});

// Get request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');

// Route requests
try {
    if (strpos($path, 'admin/') === 0) {
        // Admin UI routes
        handleAdminRoutes($path);
    } else {
        // S3 API routes
        handleS3Routes($path, $s3api);
    }
} catch (Exception $e) {
    http_response_code(500);
    S3Response::error('InternalError', 'Server error: ' . $e->getMessage(), 500);
    error_log("Fatal error: " . $e->getMessage());
}

// (Removed showApiInfo; root now lists buckets via handleS3Routes)

function handleAdminRoutes($path)
{
    $adminPath = str_replace('admin/', '', $path);

    if (empty($adminPath)) {
        // Redirect to admin dashboard
        header('Location: /admin/?page=dashboard');
        exit;
    } else {
        // Include admin page
        $adminFile = __DIR__ . '/admin/' . $adminPath . '.php';
        if (file_exists($adminFile)) {
            include $adminFile;
        } else {
            http_response_code(404);
            echo 'Admin page not found';
        }
    }
}

function handleS3Routes($path, $s3api)
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $parts = explode('/', $path);

    error_log("S3 Route: Path='$path', Method=$method, Parts=" . json_encode($parts));

    // Browser-friendly HTML view when no auth header is sent  
    $wantsHtml = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false;
    $hasAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || isset($_GET['AWSAccessKeyId']);
    if ($method === 'GET' && $wantsHtml && !$hasAuth) {
        if (empty($path)) {
            // Show public landing page (no data exposed)
            renderPublicLandingPage();
            return;
        } else {
            // Redirect to admin login for bucket/object access
            header('Location: /admin/login.php');
            exit;
        }
    }

    // Authenticate request - REQUIRED for all S3 API operations
    $user = authenticateRequest();
    if (!$user) {
        S3Response::error('AccessDenied', 'Authentication required', 403);
        return;
    }
    error_log("Auth: User authenticated - " . $user['access_key']);

    // Handle different S3 operations based on path and query parameters
    if (empty($path)) {
        // List all buckets
        $s3api->listBuckets($user);
    } elseif (count($parts) == 1) {
        // Single bucket operations
        $bucketName = rawurldecode($parts[0]);

        switch ($method) {
            case 'PUT':
                // Create bucket
                $s3api->createBucket($bucketName, $user);
                break;

            case 'DELETE':
                // Delete bucket
                $s3api->deleteBucket($bucketName, $user);
                break;

            case 'GET':
                // List objects in bucket or get bucket location
                if (isset($_GET['location'])) {
                    $s3api->getBucketLocation($bucketName, $user);
                } else {
                    $s3api->listObjects($bucketName, $user, $_GET);
                }
                break;

            case 'HEAD':
                // Check if bucket exists
                $s3api->getBucketLocation($bucketName, $user);
                break;

            default:
                S3Response::error('MethodNotAllowed', 'Method not allowed', 405);
        }
    } else {
        // Object operations
        $bucketName = rawurldecode($parts[0]);
        $decodedParts = array_map('rawurldecode', array_slice($parts, 1));
        $objectKey = implode('/', $decodedParts);

        error_log("S3 Object Operation: Bucket='$bucketName', Key='$objectKey', Method=$method");

        switch ($method) {
            case 'PUT':
                // Check for multipart upload part first
                if (isset($_GET['partNumber']) && isset($_GET['uploadId'])) {
                    $s3api->uploadPart($bucketName, $objectKey, $user, $_GET['uploadId'], $_GET['partNumber']);
                } else {
                    // Regular put object
                    $s3api->putObject($bucketName, $objectKey, $user);
                }
                break;

            case 'GET':
                // Get object
                $s3api->getObject($bucketName, $objectKey, $user);
                break;

            case 'DELETE':
                // Delete object
                $s3api->deleteObject($bucketName, $objectKey, $user);
                break;

            case 'HEAD':
                // Head object
                $s3api->headObject($bucketName, $objectKey, $user);
                break;

            case 'POST':
                // Handle multipart uploads and other POST operations
                if (isset($_GET['uploads'])) {
                    $s3api->createMultipartUpload($bucketName, $objectKey, $user);
                } elseif (isset($_GET['uploadId']) && isset($_GET['partNumber'])) {
                    $s3api->uploadPart($bucketName, $objectKey, $user, $_GET['uploadId'], $_GET['partNumber']);
                } elseif (isset($_GET['uploadId']) && !isset($_GET['partNumber'])) {
                    $s3api->completeMultipartUpload($bucketName, $objectKey, $user, $_GET['uploadId']);
                } elseif (isset($_GET['abort'])) {
                    $s3api->abortMultipartUpload($bucketName, $objectKey, $user, $_GET['uploadId']);
                } elseif (isset($_GET['list-parts'])) {
                    // List parts of multipart upload
                    S3Response::error('NotImplemented', 'ListParts not yet implemented', 501);
                } else {
                    // Regular POST upload (treat as PUT)
                    $s3api->putObject($bucketName, $objectKey, $user);
                }
                break;

            case 'COPY':
                // Copy object
                $source = $_SERVER['HTTP_X_AMZ_COPY_SOURCE'] ?? '';
                if ($source) {
                    $sourceParts = explode('/', $source, 2);
                    if (count($sourceParts) == 2) {
                        $s3api->copyObject($bucketName, $objectKey, $user, $sourceParts[0], $sourceParts[1]);
                    } else {
                        S3Response::error('InvalidRequest', 'Invalid copy source', 400);
                    }
                } else {
                    S3Response::error('InvalidRequest', 'Copy source required', 400);
                }
                break;

            default:
                S3Response::error('MethodNotAllowed', 'Method not allowed', 405);
        }
    }
}

function renderPublicLandingPage()
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Object Storage | Distributed S3-Compatible Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
</head>
<body class="animate-in">
    <nav class="s3-nav py-3">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="/">
                <i class="bi bi-cloud-fill me-2 fs-4"></i> S3 Storage
            </a>
            <div class="d-flex gap-3">
                <a class="s3-btn s3-btn-outline" href="/admin/login.php">Log in</a>
                <a class="s3-btn s3-btn-primary" href="/admin/login.php">Get Started</a>
            </div>
        </div>
    </nav>
    
    <main class="container">
        <section class="py-5 mt-5 text-center">
            <div class="mb-4 inline-flex items-center rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1 text-xs font-medium text-neutral-900 animate-slide-up" style="display:inline-flex;">
                <span class="badge-custom badge-outline me-2">New</span> 
                S3-Compatible Object Storage for everyone
            </div>
            <h1 class="hero-title animate-slide-up" style="animation-delay: 0.1s;">The simple choice for <br><span class="text-neutral-500">modern storage needs.</span></h1>
            <p class="hero-subtitle mx-auto animate-slide-up" style="animation-delay: 0.2s;">
                Self-hosted, high-performance, and S3-compatible object storage. 
                Designed to run seamlessly on your infrastructure with granular control.
            </p>
            <div class="d-flex justify-content-center gap-3 animate-slide-up" style="animation-delay: 0.3s;">
                <a href="/admin/login.php" class="s3-btn s3-btn-primary py-3 px-5 fs-5">Sign In to Dashboard</a>
            </div>
        </section>
        
        <section class="row g-4 py-5 mt-4">
            <div class="col-md-4">
                <div class="s3-card h-100 animate-slide-up" style="animation-delay: 0.4s;">
                    <div class="mb-3 text-primary"><i class="bi bi-shield-check fs-2"></i></div>
                    <h5 class="fw-bold mb-3">S3 Compatible API</h5>
                    <p class="text-muted small mb-0">Fully compatible with standard S3 SDKs, CLI tools, and libraries like boto3 and rclone.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="s3-card h-100 animate-slide-up" style="animation-delay: 0.5s;">
                    <div class="mb-3 text-primary"><i class="bi bi-people fs-2"></i></div>
                    <h5 class="fw-bold mb-3">Multi-tenancy</h5>
                    <p class="text-muted small mb-0">Comprehensive user management with isolated buckets and granular access permissions.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="s3-card h-100 animate-slide-up" style="animation-delay: 0.6s;">
                    <div class="mb-3 text-primary"><i class="bi bi-lightning-charge fs-2"></i></div>
                    <h5 class="fw-bold mb-3">High Performance</h5>
                    <p class="text-muted small mb-0">Optimized for speed and efficiency, supporting large files and concurrent operations.</p>
                </div>
            </div>
        </section>

        <section class="mt-5 pt-5 border-top animate-slide-up" style="animation-delay: 0.7s;">
            <div class="s3-card bg-neutral-50" style="border-style: dashed;">
                <h5 class="mb-4 d-flex align-items-center gap-2">
                    <i class="bi bi-terminal fs-4 text-neutral-400"></i>
                    Quick Start Guide
                </h5>
                <div class="row">
                    <div class="col-md-6">
                        <p class="text-muted small mb-2">Upload a file via cURL</p>
                        <pre class="p-3 bg-black text-white rounded-3 small mb-0"><code>curl -X PUT -H "Authorization: AWS ACCESS:SECRET" \
  --data-binary @file.txt \
  http://api.domain.com/bucket/file.txt</code></pre>
                    </div>
                    <div class="col-md-6">
                        <p class="text-muted small mb-2">Download a file via cURL</p>
                        <pre class="p-3 bg-black text-white rounded-3 small mb-0"><code>curl -H "Authorization: AWS ACCESS:SECRET" \
  http://api.domain.com/bucket/file.txt</code></pre>
                    </div>
                </div>
            </div>
        </section>
        
        <footer class="py-5 mt-5 text-center border-top text-muted small">
            &copy; ' . date('Y') . ' S3 Storage. Built for performance and reliability.
        </footer>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
}

function renderBucketsHtml()
{
    header('Content-Type: text/html; charset=UTF-8');
    $pdo = getDB();
    $buckets = $pdo->query("SELECT b.name, b.created_at, COUNT(o.id) as object_count, COALESCE(SUM(o.size), 0) as total_size FROM buckets b LEFT JOIN objects o ON b.id = o.bucket_id GROUP BY b.id ORDER BY b.name")->fetchAll(PDO::FETCH_ASSOC);
    $stats = [
        'buckets' => count($buckets),
        'objects' => $pdo->query("SELECT COUNT(*) FROM objects")->fetchColumn(),
        'total_size' => $pdo->query("SELECT COALESCE(SUM(size), 0) FROM objects")->fetchColumn()
    ];

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | S3 Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
</head>
<body class="animate-in">
    <nav class="s3-nav py-3">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="/">
                <i class="bi bi-cloud-fill me-2 fs-4"></i> S3 Storage
            </a>
            <div class="d-flex gap-3">
                <a class="s3-btn s3-btn-outline" href="/admin/login.php">Admin Panel</a>
                <a class="s3-btn s3-btn-secondary" href="/health.php">Health</a>
            </div>
        </div>
    </nav>
    
    <div class="container py-5">
        <div class="row align-items-end mb-5">
            <div class="col-lg-8">
                <h1 class="fw-bold fs-2 mb-2">Storage Explorer</h1>
                <p class="text-muted mb-0">Browse through your connected buckets and objects.</p>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="s3-card border-none bg-neutral-900 text-white">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-folder2-open fs-3 text-neutral-400"></i>
                    </div>
                    <div class="h2 fw-bold mb-1">' . $stats['buckets'] . '</div>
                    <div class="text-neutral-400 small">Total Buckets</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="s3-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-file-earmark-text fs-3 text-neutral-400"></i>
                    </div>
                    <div class="h2 fw-bold mb-1">' . $stats['objects'] . '</div>
                    <div class="text-neutral-400 small">Total Objects</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="s3-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-hdd-network fs-3 text-neutral-400"></i>
                    </div>
                    <div class="h2 fw-bold mb-1">' . formatBytesUI($stats['total_size']) . '</div>
                    <div class="text-neutral-400 small">Total Capacity Used</div>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="s3-card p-0 overflow-hidden">
                    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">Buckets</h5>
                    </div>
                    ';
    if (empty($buckets)) {
        echo '<div class="text-center py-5">
            <div class="text-neutral-300 mb-2"><i class="bi bi-inbox fs-1"></i></div>
            <p class="text-neutral-500">No buckets found.</p>
        </div>';
    } else {
        echo '<table class="s3-table mb-0">
            <thead>
                <tr>
                    <th>Bucket Name</th>
                    <th>Objects</th>
                    <th>Size</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($buckets as $b) {
            $name = htmlspecialchars($b['name']);
            $count = (int) $b['object_count'];
            $size = formatBytesUI($b['total_size']);
            echo "<tr>
                <td class='fw-medium fw-semibold'>
                    <div class='d-flex align-items-center'>
                        <i class='bi bi-folder-fill text-neutral-400 me-3'></i>
                        $name
                    </div>
                </td>
                <td><span class='badge-custom badge-outline'>$count items</span></td>
                <td class='text-neutral-500'>$size</td>
                <td class='text-end'>
                    <a href='/$name' class='s3-btn s3-btn-outline px-3 py-1'>Explore</a>
                </td>
            </tr>";
        }
        echo '</tbody></table>';
    }
    echo '      </div>
            </div>
            
            <div class="col-lg-4">
                <div class="s3-card mb-4">
                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-lightning-charge-fill text-primary"></i>
                        Quick Commands
                    </h6>
                    <p class="text-muted small mb-4">Interact with your storage directly from the terminal.</p>
                    
                    <div class="space-y-4">
                        <div class="mb-3">
                            <label class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">List Buckets</label>
                            <pre class="bg-neutral-50 p-2 rounded border small mb-0"><code>curl -H "Auth: AWS admin:pass" http://s3.local/</code></pre>
                        </div>
                        <div>
                            <label class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">List Objects</label>
                            <pre class="bg-neutral-50 p-2 rounded border small mb-0"><code>curl -H "Auth: AWS admin:pass" http://s3.local/my-bucket</code></pre>
                        </div>
                    </div>
                </div>

                <div class="s3-card bg-primary text-white border-none">
                    <h6 class="fw-bold mb-2">Need Help?</h6>
                    <p class="text-neutral-300 small mb-4">Check out our documentation for more details on API usage.</p>
                    <a href="/admin/login.php" class="s3-btn s3-btn-secondary w-100">Access Admin Dashboard</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
}

function renderBucketObjectsHtml($bucketName)
{
    header('Content-Type: text/html; charset=UTF-8');
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM buckets WHERE name = ?");
    $stmt->execute([$bucketName]);
    $bucket = $stmt->fetch(PDO::FETCH_ASSOC);

    $bucketNameSafe = htmlspecialchars($bucketName);

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bucket: ' . $bucketNameSafe . ' | S3 Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
</head>
<body class="animate-in">
    <nav class="s3-nav py-3">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="/">
                <i class="bi bi-cloud-fill me-2 fs-4"></i> S3 Storage
            </a>
            <a class="s3-btn s3-btn-outline" href="/">Return Home</a>
        </div>
    </nav>
    
    <div class="container py-5">
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/" class="text-neutral-500 text-decoration-none">Buckets</a></li>
                <li class="breadcrumb-item active" aria-current="page">' . $bucketNameSafe . '</li>
            </ol>
        </nav>
        
        <div class="d-flex justify-content-between align-items-end mb-5">
            <div>
                <h1 class="fw-bold fs-2 mb-1 d-flex align-items-center">
                    <i class="bi bi-folder2 text-neutral-400 me-3"></i>
                    ' . $bucketNameSafe . '
                </h1>
                <p class="text-muted mb-0">List of objects stored in this bucket.</p>
            </div>
            <div>
                <a href="/admin/login.php" class="s3-btn s3-btn-primary"><i class="bi bi-plus-lg me-2"></i> Upload File</a>
            </div>
        </div>';

    if (!$bucket) {
        echo '<div class="s3-card text-center py-5">
            <i class="bi bi-exclamation-triangle text-destructive fs-1 mb-3"></i>
            <h4 class="fw-bold">Bucket not found</h4>
            <p class="text-neutral-500 mb-4">The bucket you are looking for does not exist or has been deleted.</p>
            <a href="/" class="s3-btn s3-btn-outline">Browse all buckets</a>
        </div>';
    } else {
        $stmt = $pdo->prepare("SELECT object_key, size, created_at, etag, mime_type FROM objects WHERE bucket_id = ? ORDER BY object_key");
        $stmt->execute([$bucket['id']]);
        $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($objects)) {
            echo '<div class="s3-card text-center py-5" style="border-style: dashed;">
                <div class="text-neutral-300 mb-2"><i class="bi bi-inbox fs-1"></i></div>
                <h5 class="fw-bold mb-1">Bucket is empty</h5>
                <p class="text-neutral-500 mb-0">There are no objects in this bucket yet.</p>
            </div>';
        } else {
            echo '<div class="s3-card p-0 overflow-hidden">
                <div class="table-responsive">
                    <table class="s3-table mb-0">
                        <thead><tr>
                            <th>Object Key</th>
                            <th>Size</th>
                            <th>Type</th>
                            <th>Last Modified</th>
                            <th class="text-end">Action</th>
                        </tr></thead>
                        <tbody>';
            foreach ($objects as $o) {
                $key = htmlspecialchars($o['object_key']);
                $size = formatBytesUI($o['size']);
                $mime = htmlspecialchars($o['mime_type'] ?: 'unknown');
                $created = date('M j, Y H:i', strtotime($o['created_at']));
                $icon = getFileIcon($o['mime_type']);
                $url = '/' . rawurlencode($bucketName) . '/' . str_replace('%2F', '/', rawurlencode($o['object_key']));
                echo "<tr>
                    <td class='fw-medium'>
                        <div class='d-flex align-items-center'>
                            <i class='bi $icon text-neutral-400 me-3'></i>
                            $key
                        </div>
                    </td>
                    <td class='text-neutral-500'>$size</td>
                    <td><span class='badge-custom badge-outline'>$mime</span></td>
                    <td class='text-neutral-500'>$created</td>
                    <td class='text-end'>
                        <a href='$url' class='s3-btn s3-btn-outline px-3 py-1' target='_blank'><i class='bi bi-download'></i></a>
                    </td>
                </tr>";
            }
            echo '</tbody></table></div></div>';
        }
    }

    echo '</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>';
}

function formatBytesUI($size, $precision = 1)
{
    if ($size == 0)
        return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function getFileIcon($mimeType)
{
    if (!$mimeType)
        return 'bi-file-earmark';
    if (strpos($mimeType, 'image/') === 0)
        return 'bi-file-earmark-image';
    if (strpos($mimeType, 'video/') === 0)
        return 'bi-file-earmark-play';
    if (strpos($mimeType, 'audio/') === 0)
        return 'bi-file-earmark-music';
    if (strpos($mimeType, 'text/') === 0)
        return 'bi-file-earmark-text';
    if (strpos($mimeType, 'application/pdf') === 0)
        return 'bi-file-earmark-pdf';
    if (strpos($mimeType, 'application/zip') === 0 || strpos($mimeType, 'application/x-rar') === 0)
        return 'bi-file-earmark-zip';
    return 'bi-file-earmark';
}
?>