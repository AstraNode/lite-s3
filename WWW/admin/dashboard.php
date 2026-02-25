<?php
/**
 * Admin Dashboard
 */

require_once '../config.php';
require_once '../auth.php';

// Check authentication
if (!($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: /admin/login');
    exit;
}

// Handle logout
if ($_GET['action'] ?? '' === 'logout') {
    session_destroy();
    header('Location: /admin/login');
    exit;
}

$pdo = getDB();

// Get statistics
$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'buckets' => $pdo->query("SELECT COUNT(*) FROM buckets")->fetchColumn(),
    'objects' => $pdo->query("SELECT COUNT(*) FROM objects")->fetchColumn(),
    'total_size' => $pdo->query("SELECT SUM(size) FROM objects")->fetchColumn() ?: 0
];

// Get recent activity
$recentObjects = $pdo->query("
    SELECT o.object_key, o.size, o.created_at, b.name as bucket_name 
    FROM objects o 
    JOIN buckets b ON o.bucket_id = b.id 
    ORDER BY o.created_at DESC 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | S3 Storage Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
</head>

<body class="bg-neutral-50 animate-in">
    <nav class="s3-nav py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-4">
                <a class="navbar-brand fw-bold d-flex align-items-center" href="?page=dashboard">
                    <i class="bi bi-cloud-upload me-2 fs-5"></i> Admin
                </a>
                <div class="d-flex gap-1 overflow-auto">
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent fw-semibold" href="?page=dashboard">Overview</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=buckets">Buckets</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=users">Users</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=monitor">Monitor</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=change-password">Security</a>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-neutral-400 small d-none d-sm-inline">Logged in as
                    <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></span>
                <a class="s3-btn s3-btn-outline px-3" href="?page=logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-5">
        <header class="mb-5">
            <h1 class="fw-bold fs-2 mb-2">System Overview</h1>
            <p class="text-neutral-500 mb-0">High-level statistics and recent activity from your storage engine.</p>
        </header>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="s3-card border-none bg-black text-white">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-people fs-4 text-neutral-400"></i>
                    </div>
                    <div class="h3 fw-bold mb-1"><?= $stats['users'] ?></div>
                    <div class="text-neutral-400 small">Total Users</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="s3-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-folder fs-4 text-neutral-400"></i>
                    </div>
                    <div class="h3 fw-bold mb-1"><?= $stats['buckets'] ?></div>
                    <div class="text-neutral-400 small">Active Buckets</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="s3-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-file-earmark-code fs-4 text-neutral-400"></i>
                    </div>
                    <div class="h3 fw-bold mb-1"><?= $stats['objects'] ?></div>
                    <div class="text-neutral-400 small">Total Objects</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="s3-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <i class="bi bi-hdd-network fs-4 text-neutral-400"></i>
                    </div>
                    <div class="h3 fw-bold mb-1"><?= formatBytes($stats['total_size']) ?></div>
                    <div class="text-neutral-400 small">Storage Consumed</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Recent Activity -->
            <div class="col-lg-8">
                <div class="s3-card p-0">
                    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">Recent Uploads</h5>
                        <a href="?page=buckets"
                            class="text-xs fw-semibold text-neutral-500 text-decoration-none hover:text-neutral-900 border-bottom">View
                            all buckets</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentObjects)): ?>
                            <div class="text-center py-5">
                                <p class="text-neutral-400 small">No recent activity detected</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="s3-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>ObjectName</th>
                                            <th>Bucket</th>
                                            <th>Size</th>
                                            <th class="text-end">Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentObjects as $object): ?>
                                            <tr>
                                                <td class="fw-medium">
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi bi-file-earmark-text text-neutral-400 me-2"></i>
                                                        <?= htmlspecialchars($object['object_key']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge-custom badge-outline">
                                                        <?= htmlspecialchars($object['bucket_name']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-neutral-500 font-monospace" style="font-size: 0.75rem;">
                                                    <?= formatBytes($object['size']) ?></td>
                                                <td class="text-end text-neutral-400" style="font-size: 0.75rem;">
                                                    <?= date('M j, H:i', strtotime($object['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-4">
                <div class="s3-card h-100">
                    <h5 class="fw-bold mb-4">Quick Actions</h5>
                    <div class="d-grid gap-3">
                        <a href="?page=buckets" class="s3-btn s3-btn-outline justify-content-start py-3">
                            <i class="bi bi-folder-plus me-3 text-neutral-400"></i>
                            <div class="text-start">
                                <div class="fw-semibold">Manage Buckets</div>
                                <div class="text-xs text-neutral-500">Create and configure storage containers</div>
                            </div>
                        </a>
                        <a href="?page=users" class="s3-btn s3-btn-outline justify-content-start py-3">
                            <i class="bi bi-person-plus me-3 text-neutral-400"></i>
                            <div class="text-start">
                                <div class="fw-semibold">User Access</div>
                                <div class="text-xs text-neutral-500">Manage API keys and permissions</div>
                            </div>
                        </a>
                        <a href="?page=monitor" class="s3-btn s3-btn-outline justify-content-start py-3">
                            <i class="bi bi-graph-up me-3 text-neutral-400"></i>
                            <div class="text-start">
                                <div class="fw-semibold">Monitoring</div>
                                <div class="text-xs text-neutral-500">Real-time performance metrics</div>
                            </div>
                        </a>
                        <a href="/" class="s3-btn s3-btn-outline justify-content-start py-3" target="_blank">
                            <i class="bi bi-code-slash me-3 text-neutral-400"></i>
                            <div class="text-start">
                                <div class="fw-semibold">API Docs</div>
                                <div class="text-xs text-neutral-500">Integration guide and examples</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="py-5 text-center text-neutral-400 border-top mt-5 bg-neutral-50">
        <p class="small mb-0">S3 Storage Admin v1.0 &bull; Secure Object Storage</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

<?php
function formatBytes($size, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }

    return round($size, $precision) . ' ' . $units[$i];
}
?>