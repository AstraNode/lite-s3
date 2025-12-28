<?php
/**
 * User Management Page
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
if (($_POST['action'] ?? '') === 'create_user') {
    $accessKey = trim($_POST['access_key'] ?? '');
    $secretKey = trim($_POST['secret_key'] ?? '');
    $isAdmin = isset($_POST['is_admin']);
    
    if ($accessKey && $secretKey) {
        if (strlen($secretKey) < 6) {
            $error = "Secret key must be at least 6 characters long";
        } else {
            $hashedSecretKey = password_hash($secretKey, PASSWORD_DEFAULT);
            if (createUserWithPassword($accessKey, $hashedSecretKey, $accessKey, $secretKey, $isAdmin)) {
                $success = "User '$accessKey' created successfully";
            } else {
                $error = "Failed to create user (access key may already exist)";
            }
        }
    } else {
        $error = "Access key and secret key are required";
    }
}

if (($_POST['action'] ?? '') === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($userId) {
        // Check if it's the last admin
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_admin = 1");
        $stmt->execute();
        $adminCount = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $isAdmin = $stmt->fetchColumn();
        
        if ($isAdmin && $adminCount <= 1) {
            $error = "Cannot delete the last admin user";
        } else {
            // Delete user's buckets and objects
            $stmt = $pdo->prepare("SELECT id FROM buckets WHERE user_id = ?");
            $stmt->execute([$userId]);
            $bucketIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($bucketIds as $bucketId) {
                // Delete objects
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
                
                // Remove bucket directory
                $bucketPath = STORAGE_PATH . $bucketName;
                if (is_dir($bucketPath)) {
                    rmdir($bucketPath);
                }
            }
            
            // Delete from database
            $pdo->prepare("DELETE FROM objects WHERE bucket_id IN (" . implode(',', $bucketIds) . ")")->execute();
            $pdo->prepare("DELETE FROM buckets WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM permissions WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            
            $success = "User deleted successfully";
        }
    }
}

if (($_POST['action'] ?? '') === 'regenerate_keys') {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($userId) {
        // Only regenerate secret key, keep access key (username) unchanged
        $newSecretKey = bin2hex(random_bytes(16));
        $hashedSecret = password_hash($newSecretKey, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET secret_key = ? WHERE id = ?");
        if ($stmt->execute([$hashedSecret, $userId])) {
            // Get the user's access key to display
            $stmt = $pdo->prepare("SELECT access_key FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $accessKey = $stmt->fetchColumn();
            $success = "Secret key regenerated for '$accessKey'. New secret: $newSecretKey";
        } else {
            $error = "Failed to regenerate keys";
        }
    }
}

// Get users with statistics
$users = $pdo->query("
    SELECT u.*, 
           COUNT(DISTINCT b.id) as bucket_count,
           COUNT(DISTINCT o.id) as object_count,
           COALESCE(SUM(o.size), 0) as total_size
    FROM users u
    LEFT JOIN buckets b ON u.id = b.user_id
    LEFT JOIN objects o ON b.id = o.bucket_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Storage Admin - Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .password-toggle {
            cursor: pointer;
            user-select: none;
        }
        .password-toggle:hover {
            color: #0d6efd !important;
        }
    </style>
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
                <a class="nav-link" href="?page=buckets">
                    <i class="bi bi-folder"></i> Buckets
                </a>
                <a class="nav-link active" href="?page=users">
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
                        <i class="bi bi-people"></i> User Management
                    </h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        <i class="bi bi-person-plus"></i> Create User
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
                        <h5 class="mb-0">All Users</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($users)): ?>
                            <p class="text-muted">No users found. Create your first user to get started.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Access Key (Username)</th>
                                            <th>Role</th>
                                            <th>Buckets</th>
                                            <th>Objects</th>
                                            <th>Storage Used</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-key"></i>
                                                    <strong><?= htmlspecialchars($user['access_key']) ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($user['is_admin']): ?>
                                                        <span class="badge bg-danger">Admin</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">User</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $user['bucket_count'] ?></td>
                                                <td><?= $user['object_count'] ?></td>
                                                <td><?= formatBytes($user['total_size']) ?></td>
                                                <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-warning" 
                                                                onclick="regenerateKeys(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                            <i class="bi bi-arrow-clockwise"></i>
                                                        </button>
                                                        <?php if (!$user['is_admin'] || $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() > 1): ?>
                                                            <button class="btn btn-outline-danger" 
                                                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
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

        <!-- API Usage Instructions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle"></i> API Usage Instructions
                        </h5>
                    </div>
                    <div class="card-body">
                        <p>Users can access the S3 API using their access key and secret key:</p>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>cURL Example:</h6>
                                <pre class="bg-light p-3"><code>curl -X PUT \
  -H "Authorization: AWS ACCESS_KEY:SECRET_KEY" \
  -F "file=@/path/to/file.txt" \
  https://yourdomain.com/bucket-name/file.txt</code></pre>
                            </div>
                            <div class="col-md-6">
                                <h6>Python boto3 Example:</h6>
                                <pre class="bg-light p-3"><code>import boto3

s3 = boto3.client(
    's3',
    endpoint_url='https://yourdomain.com',
    aws_access_key_id='ACCESS_KEY',
    aws_secret_access_key='SECRET_KEY'
)

s3.upload_file('file.txt', 'bucket-name', 'file.txt')</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_user">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="access_key" class="form-label">Access Key (Username)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="text" class="form-control" id="access_key" name="access_key" 
                                   placeholder="Leave empty to auto-generate" 
                                   pattern="[a-zA-Z0-9_-]+" title="Only letters, numbers, underscores, and hyphens" required>
                            </div>
                            <div class="form-text">This will be used as both S3 access key and admin username</div>
                        </div>
                        <div class="mb-3">
                            <label for="secret_key" class="form-label">Secret Key (Password)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="secret_key" name="secret_key" 
                                       minlength="6" required placeholder="Leave empty to auto-generate">
                                <span class="input-group-text password-toggle" onclick="togglePassword('secret_key')">
                                    <i class="bi bi-eye" id="secret_key_toggle"></i>
                                </span>
                            </div>
                            <div class="form-text">This will be used as both S3 secret key and admin password (minimum 6 characters)</div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin">
                                <label class="form-check-label" for="is_admin">
                                    Admin privileges
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User</button>
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
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the user <strong id="delete_user_name"></strong>?</p>
                        <p class="text-danger"><strong>Warning:</strong> This will permanently delete all buckets and objects owned by this user!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Regenerate Keys Modal -->
    <div class="modal fade" id="regenerateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="regenerate_keys">
                    <input type="hidden" name="user_id" id="regenerate_user_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Regenerate API Keys</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to regenerate API keys for <strong id="regenerate_user_name"></strong>?</p>
                        <p class="text-warning"><strong>Warning:</strong> The old keys will stop working immediately!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Regenerate Keys</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteUser(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = username;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function regenerateKeys(userId, username) {
            document.getElementById('regenerate_user_id').value = userId;
            document.getElementById('regenerate_user_name').textContent = username;
            new bootstrap.Modal(document.getElementById('regenerateModal')).show();
        }

        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(fieldId + '_toggle');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }

        // Auto-generate keys if empty
        document.getElementById('createUserModal').addEventListener('show.bs.modal', function () {
            const accessKeyField = document.getElementById('access_key');
            const secretKeyField = document.getElementById('secret_key');
            
            if (!accessKeyField.value) {
                accessKeyField.value = 'user_' + Math.random().toString(36).substr(2, 8);
            }
            
            if (!secretKeyField.value) {
                secretKeyField.value = Math.random().toString(36).substr(2, 16);
            }
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
