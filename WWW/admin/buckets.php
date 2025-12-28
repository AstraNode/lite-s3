<?php
/**
 * Bucket Management Page
 */

require_once '../config.php';
require_once '../auth.php';

// Check authentication
if (!($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: /admin/login');
    exit;
}

$pdo = getDB();

// Handle actions
if (($_POST['action'] ?? '') === 'create_bucket') {
    $bucketName = trim($_POST['bucket_name'] ?? '');
    $ownerId = (int)($_POST['owner_id'] ?? 0);
    
    if ($bucketName && $ownerId) {
        if (createBucket($bucketName, $ownerId)) {
            $success = "Bucket '$bucketName' created successfully";
        } else {
            $error = "Failed to create bucket";
        }
    } else {
        $error = "Bucket name and owner are required";
    }
}

if (($_POST['action'] ?? '') === 'delete_bucket') {
    $bucketId = (int)($_POST['bucket_id'] ?? 0);
    
    if ($bucketId) {
        // Delete all objects in bucket
        $stmt = $pdo->prepare("SELECT object_key FROM objects WHERE bucket_id = ?");
        $stmt->execute([$bucketId]);
        $objects = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt = $pdo->prepare("SELECT name FROM buckets WHERE id = ?");
        $stmt->execute([$bucketId]);
        $bucketName = $stmt->fetchColumn();
        
        foreach ($objects as $objectKey) {
            $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Delete from database
        $pdo->prepare("DELETE FROM objects WHERE bucket_id = ?")->execute([$bucketId]);
        $pdo->prepare("DELETE FROM permissions WHERE bucket_id = ?")->execute([$bucketId]);
        $pdo->prepare("DELETE FROM buckets WHERE id = ?")->execute([$bucketId]);
        
        // Remove directory
        $bucketPath = STORAGE_PATH . $bucketName;
        if (is_dir($bucketPath)) {
            rmdir($bucketPath);
        }
        
        $success = "Bucket deleted successfully";
    }
}

if (($_POST['action'] ?? '') === 'grant_permission') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $bucketId = (int)($_POST['bucket_id'] ?? 0);
    $permission = $_POST['permission'] ?? '';
    
    if ($userId && $bucketId && $permission) {
        if (grantBucketPermission($userId, $bucketId, $permission)) {
            $success = "Permission granted successfully";
        } else {
            $error = "Failed to grant permission - check logs";
        }
    } else {
        $error = "User, bucket, and permission type are required";
    }
}

if (($_POST['action'] ?? '') === 'revoke_permission') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $bucketId = (int)($_POST['bucket_id'] ?? 0);
    
    if ($userId && $bucketId) {
        if (revokeBucketPermission($userId, $bucketId)) {
            $success = "Permission revoked successfully";
        } else {
            $error = "Failed to revoke permission";
        }
    }
}

// Get buckets with owner info
$buckets = $pdo->query("
    SELECT b.*, u.username as owner_name, 
           COUNT(o.id) as object_count,
           COALESCE(SUM(o.size), 0) as total_size
    FROM buckets b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN objects o ON b.id = o.bucket_id
    GROUP BY b.id
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get permissions for each bucket
$bucketPermissions = [];
$permStmt = $pdo->query("
    SELECT p.bucket_id, p.permission, u.username, u.id as user_id
    FROM permissions p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.bucket_id, u.username
");
foreach ($permStmt->fetchAll(PDO::FETCH_ASSOC) as $perm) {
    $bucketPermissions[$perm['bucket_id']][] = $perm;
}

// Get users for dropdowns
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Storage Admin - Buckets</title>
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
                <a class="nav-link" href="?page=dashboard">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a class="nav-link active" href="?page=buckets">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>
                        <i class="bi bi-folder"></i> Bucket Management
                    </h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBucketModal">
                        <i class="bi bi-folder-plus"></i> Create Bucket
                    </button>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">All Buckets</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($buckets)): ?>
                            <p class="text-muted">No buckets found. Create your first bucket to get started.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Owner</th>
                                            <th>Access</th>
                                            <th>Objects</th>
                                            <th>Size</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($buckets as $bucket): ?>
                                            <?php $perms = $bucketPermissions[$bucket['id']] ?? []; ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-folder"></i>
                                                    <strong><?= htmlspecialchars($bucket['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($bucket['owner_name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (empty($perms)): ?>
                                                        <span class="text-muted small">No access</span>
                                                    <?php else: ?>
                                                        <?php foreach ($perms as $p): ?>
                                                            <?php 
                                                                $badgeClass = match($p['permission']) {
                                                                    'admin' => 'bg-danger',
                                                                    'write' => 'bg-warning text-dark',
                                                                    default => 'bg-info'
                                                                };
                                                            ?>
                                                            <span class="badge <?= $badgeClass ?> me-1" 
                                                                  title="<?= ucfirst($p['permission']) ?> - Click X to revoke"
                                                                  style="padding-right: 0.3rem;">
                                                                <?= htmlspecialchars($p['username']) ?>
                                                                <button type="button" class="btn-close btn-close-white ms-1" 
                                                                        style="font-size: 0.6rem; padding: 0.2rem;"
                                                                        onclick="revokePermission(<?= $p['user_id'] ?>, <?= $bucket['id'] ?>, '<?= htmlspecialchars($p['username']) ?>')"
                                                                        title="Revoke permission"></button>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $bucket['object_count'] ?></td>
                                                <td><?= formatBytes($bucket['total_size']) ?></td>
                                                <td><?= date('M j, Y', strtotime($bucket['created_at'])) ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="viewBucket('<?= htmlspecialchars($bucket['name']) ?>')">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button class="btn btn-outline-success" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#permissionModal"
                                                                data-bucket-id="<?= $bucket['id'] ?>"
                                                                data-bucket-name="<?= htmlspecialchars($bucket['name']) ?>">
                                                            <i class="bi bi-shield-check"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger" 
                                                                onclick="deleteBucket(<?= $bucket['id'] ?>, '<?= htmlspecialchars($bucket['name']) ?>')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
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
    </div>

    <!-- Create Bucket Modal -->
    <div class="modal fade" id="createBucketModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_bucket">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Bucket</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="bucket_name" class="form-label">Bucket Name</label>
                            <input type="text" class="form-control" id="bucket_name" name="bucket_name" 
                                   pattern="[a-z0-9.-]+" title="Only lowercase letters, numbers, dots, and hyphens" required>
                        </div>
                        <div class="mb-3">
                            <label for="owner_id" class="form-label">Owner</label>
                            <select class="form-select" id="owner_id" name="owner_id" required>
                                <option value="">Select owner...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Bucket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Permission Modal -->
    <div class="modal fade" id="permissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="grant_permission">
                    <input type="hidden" name="bucket_id" id="permission_bucket_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Grant Bucket Permission</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="permission_user_id" class="form-label">User</label>
                            <select class="form-select" id="permission_user_id" name="user_id" required>
                                <option value="">Select user...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="permission" class="form-label">Permission</label>
                            <select class="form-select" id="permission" name="permission" required>
                                <option value="read">Read Only</option>
                                <option value="write">Read & Write</option>
                                <option value="admin">Full Access</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Grant Permission</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_bucket">
                    <input type="hidden" name="bucket_id" id="delete_bucket_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Bucket</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the bucket <strong id="delete_bucket_name"></strong>?</p>
                        <p class="text-danger"><strong>Warning:</strong> This will permanently delete all objects in this bucket!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Bucket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteBucket(bucketId, bucketName) {
            document.getElementById('delete_bucket_id').value = bucketId;
            document.getElementById('delete_bucket_name').textContent = bucketName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function viewBucket(bucketName) {
            window.open('/' + bucketName, '_blank');
        }

        function revokePermission(userId, bucketId, username) {
            if (confirm('Revoke access for ' + username + '?')) {
                // Submit revoke form
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="revoke_permission">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="bucket_id" value="${bucketId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Handle permission modal
        document.getElementById('permissionModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const bucketId = button.getAttribute('data-bucket-id');
            const bucketName = button.getAttribute('data-bucket-name');
            
            document.getElementById('permission_bucket_id').value = bucketId;
            document.querySelector('#permissionModal .modal-title').textContent = 
                'Grant Permission for ' + bucketName;
        });
    </script>
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
