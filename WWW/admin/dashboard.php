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
    <title>S3 Storage Admin - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="?page=dashboard">
                <i class="bi bi-cloud-upload"></i> S3 Storage Admin
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="?page=buckets">
                    <i class="bi bi-folder"></i> Buckets
                </a>
                <a class="nav-link" href="?page=users">
                    <i class="bi bi-people"></i> Users
                </a>
                <a class="nav-link" href="?page=logout">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </h1>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $stats['users'] ?></h4>
                                <p class="card-text">Users</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-people" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $stats['buckets'] ?></h4>
                                <p class="card-text">Buckets</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-folder" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $stats['objects'] ?></h4>
                                <p class="card-text">Objects</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-file-earmark" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= formatBytes($stats['total_size']) ?></h4>
                                <p class="card-text">Total Size</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-hdd" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> Recent Activity
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentObjects)): ?>
                            <p class="text-muted">No recent activity</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Object</th>
                                            <th>Bucket</th>
                                            <th>Size</th>
                                            <th>Uploaded</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentObjects as $object): ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-file-earmark"></i>
                                                    <?= htmlspecialchars($object['object_key']) ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($object['bucket_name']) ?>
                                                    </span>
                                                </td>
                                                <td><?= formatBytes($object['size']) ?></td>
                                                <td><?= date('M j, Y H:i', strtotime($object['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-lightning"></i> Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <a href="?page=buckets" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-folder-plus"></i><br>
                                    Manage Buckets
                                </a>
                            </div>
                            <div class="col-md-4 mb-3">
                                <a href="?page=users" class="btn btn-outline-success w-100">
                                    <i class="bi bi-person-plus"></i><br>
                                    Manage Users
                                </a>
                            </div>
                            <div class="col-md-4 mb-3">
                                <a href="/" class="btn btn-outline-info w-100" target="_blank">
                                    <i class="bi bi-code-slash"></i><br>
                                    API Documentation
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>
