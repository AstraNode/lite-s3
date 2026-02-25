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
    $userId = (int) ($_POST['user_id'] ?? 0);

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
            if (!empty($bucketIds)) {
                $placeholders = implode(',', array_fill(0, count($bucketIds), '?'));
                $pdo->prepare("DELETE FROM objects WHERE bucket_id IN ($placeholders)")->execute($bucketIds);
            }
            $pdo->prepare("DELETE FROM buckets WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM permissions WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

            $success = "User deleted successfully";
        }
    }
}

if (($_POST['action'] ?? '') === 'regenerate_keys') {
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId) {
        // Regenerate secret key, keep access key (username) unchanged
        $newSecretKey = bin2hex(random_bytes(16)); // Plain text S3 secret key
        $hashedSecret = password_hash($newSecretKey, PASSWORD_DEFAULT); // For admin login

        // Update both secret_key (hashed) and plain_secret_key (for AWS Sig V4)
        $stmt = $pdo->prepare("UPDATE users SET secret_key = ?, plain_secret_key = ? WHERE id = ?");
        if ($stmt->execute([$hashedSecret, $newSecretKey, $userId])) {
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
    <title>Users | S3 Storage Admin</title>
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
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=dashboard">Overview</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=buckets">Buckets</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent fw-semibold" href="?page=users">Users</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=monitor">Monitor</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=change-password">Security</a>
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
        <header class="d-flex justify-content-between align-items-end mb-5">
            <div>
                <h1 class="fw-bold fs-2 mb-2">Users</h1>
                <p class="text-neutral-500 mb-0">Manage API access keys and administrative privileges.</p>
            </div>
            <button class="s3-btn s3-btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-person-plus me-2"></i> Create User
            </button>
        </header>

        <?php if (isset($success)): ?>
            <div class="alert bg-black text-white border-0 shadow-sm small py-3 d-flex align-items-center mb-4 animate-in"
                role="alert">
                <i class="bi bi-check2-circle me-3 fs-5 text-success"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert bg-destructive/10 border-destructive/20 text-destructive small py-3 d-flex align-items-center mb-4 animate-in"
                role="alert">
                <i class="bi bi-exclamation-triangle me-3 fs-5"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="s3-card p-0 overflow-hidden mb-5">
            <div class="table-responsive">
                <table class="s3-table mb-0">
                    <thead>
                        <tr>
                            <th>Access Key</th>
                            <th>Status</th>
                            <th>Buckets</th>
                            <th>Storage</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-neutral-400">No users found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-neutral-100 p-2 rounded-2 me-3">
                                                <i class="bi bi-key text-neutral-500"></i>
                                            </div>
                                            <?= htmlspecialchars($user['access_key']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($user['is_admin']): ?>
                                            <span class="badge-custom bg-black text-white">Administrator</span>
                                        <?php else: ?>
                                            <span class="badge-custom badge-outline text-neutral-500">Standard User</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-neutral-500 small"><?= $user['bucket_count'] ?> containers</td>
                                    <td class="text-neutral-500 font-monospace small"><?= formatBytes($user['total_size']) ?>
                                    </td>
                                    <td class="text-neutral-400 small"><?= date('M j, Y', strtotime($user['created_at'])) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group gap-2">
                                            <button class="s3-btn s3-btn-outline p-2" title="Regenerate Keys"
                                                onclick="regenerateKeys(<?= $user['id'] ?>, '<?= htmlspecialchars($user['access_key']) ?>')">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                            <?php if (!$user['is_admin'] || $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() > 1): ?>
                                                <button
                                                    class="s3-btn s3-btn-outline p-2 text-destructive border-destructive/20 hover:bg-destructive/10"
                                                    title="Delete User"
                                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['access_key']) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Integration Help -->
        <h4 class="fw-bold mb-4">API Integration</h4>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="s3-card border-neutral-200">
                    <h6 class="fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-terminal me-2 text-neutral-400"></i> cURL Command
                    </h6>
                    <div class="bg-neutral-900 text-neutral-300 p-3 rounded-3 position-relative overflow-hidden"
                        style="font-size: 0.8rem;">
                        <div class="position-absolute top-0 end-0 p-2 opacity-50">SH</div>
                        <code class="text-white">curl -X PUT \<br>
                        &nbsp;&nbsp;-H "Authorization: AWS ACCESS_KEY:SECRET_KEY" \<br>
                        &nbsp;&nbsp;-F "file=@/path/to/file.txt" \<br>
                        &nbsp;&nbsp;https://yourdomain.com/bucket/file.txt</code>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="s3-card border-neutral-200">
                    <h6 class="fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-code-square me-2 text-neutral-400"></i> Python (Boto3)
                    </h6>
                    <div class="bg-neutral-900 text-neutral-300 p-3 rounded-3 position-relative overflow-hidden"
                        style="font-size: 0.8rem;">
                        <div class="position-absolute top-0 end-0 p-2 opacity-50">PY</div>
                        <code class="text-white">import boto3<br><br>
                        s3 = boto3.client('s3', endpoint_url='...',<br>
                        &nbsp;&nbsp;aws_access_key_id='KEY',<br>
                        &nbsp;&nbsp;aws_secret_access_key='SECRET')</code>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content s3-card p-0 overflow-hidden shadow-lg border-0">
                <form method="POST">
                    <input type="hidden" name="action" value="create_user">
                    <div class="p-4 border-bottom bg-neutral-50">
                        <h5 class="fw-bold mb-1">Create Access Credentials</h5>
                        <p class="text-xs text-neutral-500 mb-0">Generate new API keys for storage access.</p>
                    </div>
                    <div class="p-4">
                        <div class="mb-4">
                            <label for="access_key"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Access Key
                                (Username)</label>
                            <input type="text" class="s3-input" id="access_key" name="access_key"
                                placeholder="Leave empty for auto" pattern="[a-zA-Z0-9_-]+" required>
                        </div>
                        <div class="mb-4">
                            <label for="secret_key"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Secret Key
                                (Password)</label>
                            <div class="position-relative">
                                <input type="password" class="s3-input pe-5" id="secret_key" name="secret_key"
                                    minlength="6" placeholder="Leave empty for auto" required>
                                <button type="button"
                                    class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                    onclick="togglePassword('secret_key')">
                                    <i class="bi bi-eye" id="secret_key_toggle"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin">
                                <label class="form-check-label text-neutral-600 small" for="is_admin">
                                    Grant administrator privileges (Console access)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="s3-btn s3-btn-primary flex-grow-1">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content s3-card p-0 overflow-hidden shadow-lg border-0">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <div class="p-4 border-bottom bg-destructive/10">
                        <h5 class="fw-bold text-destructive mb-1">Remove User</h5>
                        <p class="text-xs text-destructive mb-0">Extreme caution required.</p>
                    </div>
                    <div class="p-4 text-center">
                        <p class="mb-4">Are you sure you want to delete <strong id="delete_user_name_display"></strong>?
                        </p>
                        <div
                            class="alert bg-destructive/5 border-destructive/10 text-destructive small text-start p-3 mb-0">
                            <strong>Warning:</strong> All buckets and objects owned by this user will be
                            <strong>permanently deleted</strong> from the system.
                        </div>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit"
                            class="s3-btn s3-btn-primary bg-destructive border-destructive text-white flex-grow-1">Confirm
                            Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Regenerate Keys Modal -->
    <div class="modal fade" id="regenerateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content s3-card p-0 overflow-hidden shadow-lg border-0">
                <form method="POST">
                    <input type="hidden" name="action" value="regenerate_keys">
                    <input type="hidden" name="user_id" id="regenerate_user_id">
                    <div class="p-4 border-bottom bg-neutral-50">
                        <h5 class="fw-bold mb-1">Rotate Secret Key</h5>
                        <p class="text-xs text-neutral-500 mb-0">Security credential rotation.</p>
                    </div>
                    <div class="p-4">
                        <p class="mb-0">Regenerate secret API key for <strong
                                id="regenerate_user_name_display"></strong>?</p>
                        <p class="text-neutral-500 small mt-3">The existing secret key will be invalidated immediately,
                            breaking current integrations using it.</p>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="s3-btn s3-btn-primary flex-grow-1">Rotate Key</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="py-5 text-center text-neutral-400 border-top mt-5 bg-neutral-50">
        <p class="small mb-0">S3 Storage Admin v1.0 &bull; Secure Object Storage</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteUser(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name_display').textContent = username;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function regenerateKeys(userId, username) {
            document.getElementById('regenerate_user_id').value = userId;
            document.getElementById('regenerate_user_name_display').textContent = username;
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

        document.getElementById('createUserModal').addEventListener('show.bs.modal', function () {
            const accessKeyField = document.getElementById('access_key');
            const secretKeyField = document.getElementById('secret_key');
            if (!accessKeyField.value) { accessKeyField.value = 'user_' + Math.random().toString(36).substr(2, 8); }
            if (!secretKeyField.value) { secretKeyField.value = Math.random().toString(36).substr(2, 16); }
        });
    </script>
</body>

</html>

<?php
function formatBytes($size, $precision = 2)
{
    if ($size <= 0)
        return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}
?>