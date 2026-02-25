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
    $ownerId = (int) ($_POST['owner_id'] ?? 0);

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
    $bucketId = (int) ($_POST['bucket_id'] ?? 0);

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
    $userId = (int) ($_POST['user_id'] ?? 0);
    $bucketId = (int) ($_POST['bucket_id'] ?? 0);
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
    $userId = (int) ($_POST['user_id'] ?? 0);
    $bucketId = (int) ($_POST['bucket_id'] ?? 0);

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
    <title>Buckets | S3 Storage Admin</title>
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
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent fw-semibold"
                        href="?page=buckets">Buckets</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=users">Users</a>
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
                <h1 class="fw-bold fs-2 mb-2">Buckets</h1>
                <p class="text-neutral-500 mb-0">Manage your storage containers and access permissions.</p>
            </div>
            <button class="s3-btn s3-btn-primary" data-bs-toggle="modal" data-bs-target="#createBucketModal">
                <i class="bi bi-plus-lg me-2"></i> Create Bucket
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

        <div class="s3-card p-0 overflow-hidden">
            <div class="table-responsive">
                <table class="s3-table mb-0">
                    <thead>
                        <tr>
                            <th>Bucket Name</th>
                            <th>Owner</th>
                            <th>Access Control</th>
                            <th>Items</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($buckets)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-neutral-400">No buckets found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($buckets as $bucket): ?>
                                <?php $perms = $bucketPermissions[$bucket['id']] ?? []; ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-folder-fill text-neutral-300 me-2"></i>
                                            <?= htmlspecialchars($bucket['name']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-custom badge-outline">
                                            <i class="bi bi-person me-1"></i> <?= htmlspecialchars($bucket['owner_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if (empty($perms)): ?>
                                                <span class="text-xs text-neutral-400 italic">No extra users</span>
                                            <?php else: ?>
                                                <?php foreach ($perms as $p): ?>
                                                    <?php
                                                    $permColor = match ($p['permission']) {
                                                        'admin' => 'hsl(var(--destructive))',
                                                        'write' => '#f59e0b',
                                                        default => 'hsl(var(--foreground))'
                                                    };
                                                    ?>
                                                    <div class="badge-custom bg-neutral-100 border-neutral-200 text-neutral-700 py-0 pe-1 ps-2 d-inline-flex align-items-center"
                                                        style="font-size: 0.7rem;">
                                                        <span class="me-1" style="color: <?= $permColor ?>;">●</span>
                                                        <?= htmlspecialchars($p['username']) ?>
                                                        <button type="button" class="btn-close ms-2"
                                                            style="font-size: 0.5rem; padding: 0.1rem;"
                                                            onclick="revokePermission(<?= $p['user_id'] ?>, <?= $bucket['id'] ?>, '<?= htmlspecialchars($p['username']) ?>')"
                                                            title="Revoke access"></button>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-neutral-500 small"><?= $bucket['object_count'] ?></td>
                                    <td class="text-neutral-500 font-monospace small"><?= formatBytes($bucket['total_size']) ?>
                                    </td>
                                    <td class="text-neutral-400 small"><?= date('M j, Y', strtotime($bucket['created_at'])) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="s3-btn s3-btn-outline p-2" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-neutral-200 p-2">
                                                <li>
                                                    <a class="dropdown-item rounded-2 py-2 small d-flex align-items-center"
                                                        href="#"
                                                        onclick="viewBucket('<?= htmlspecialchars($bucket['name']) ?>')">
                                                        <i class="bi bi-eye me-2"></i> View Files
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item rounded-2 py-2 small d-flex align-items-center"
                                                        href="#" data-bs-toggle="modal" data-bs-target="#permissionModal"
                                                        data-bucket-id="<?= $bucket['id'] ?>"
                                                        data-bucket-name="<?= htmlspecialchars($bucket['name']) ?>">
                                                        <i class="bi bi-shield-check me-2"></i> Permissions
                                                    </a>
                                                </li>
                                                <li>
                                                    <hr class="dropdown-divider mx-n2">
                                                </li>
                                                <li>
                                                    <a class="dropdown-item rounded-2 py-2 small d-flex align-items-center text-destructive"
                                                        href="#"
                                                        onclick="deleteBucket(<?= $bucket['id'] ?>, '<?= htmlspecialchars($bucket['name']) ?>')">
                                                        <i class="bi bi-trash me-2"></i> Delete Bucket
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Create Bucket Modal -->
    <div class="modal fade" id="createBucketModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content s3-card p-0 overflow-hidden shadow-lg border-0">
                <form method="POST">
                    <input type="hidden" name="action" value="create_bucket">
                    <div class="p-4 border-bottom bg-neutral-50">
                        <h5 class="fw-bold mb-1">Create New Bucket</h5>
                        <p class="text-xs text-neutral-500 mb-0">Storage containers must have unique names.</p>
                    </div>
                    <div class="p-4">
                        <div class="mb-4">
                            <label for="bucket_name"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Bucket Name</label>
                            <input type="text" class="s3-input" id="bucket_name" name="bucket_name"
                                pattern="[a-z0-9.-]+" placeholder="e.g. my-assets" required>
                            <div class="text-xs text-neutral-400 mt-2">Only lowercase letters, numbers, dots, and
                                hyphens.</div>
                        </div>
                        <div class="mb-2">
                            <label for="owner_id"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Owner</label>
                            <select class="s3-input" id="owner_id" name="owner_id" required>
                                <option value="">Select owner...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="s3-btn s3-btn-primary flex-grow-1">Create Bucket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Permission Modal -->
    <div class="modal fade" id="permissionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content s3-card p-0 overflow-hidden shadow-lg border-0">
                <form method="POST">
                    <input type="hidden" name="action" value="grant_permission">
                    <input type="hidden" name="bucket_id" id="permission_bucket_id">
                    <div class="p-4 border-bottom bg-neutral-50">
                        <h5 class="fw-bold mb-1">Grant Access</h5>
                        <p class="text-xs text-neutral-500 mb-0" id="permission_bucket_name_display">Bucket access
                            management</p>
                    </div>
                    <div class="p-4">
                        <div class="mb-4">
                            <label for="permission_user_id"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Select User</label>
                            <select class="s3-input" id="permission_user_id" name="user_id" required>
                                <option value="">Choose user...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label for="permission"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Access
                                Level</label>
                            <select class="s3-input" id="permission" name="permission" required>
                                <option value="read">Read Only (Download)</option>
                                <option value="write">Read & Write (Upload/Delete)</option>
                                <option value="admin">Full Control (Owner-like)</option>
                            </select>
                        </div>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="s3-btn s3-btn-primary flex-grow-1">Grant Access</button>
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
                    <input type="hidden" name="action" value="delete_bucket">
                    <input type="hidden" name="bucket_id" id="delete_bucket_id">
                    <div class="p-4 border-bottom bg-destructive/10">
                        <h5 class="fw-bold text-destructive mb-1">Confirm Deletion</h5>
                        <p class="text-xs text-destructive mb-0">This action is irreversible.</p>
                    </div>
                    <div class="p-4 text-center">
                        <div class="mb-4 text-neutral-400">
                            <i class="bi bi-exclamation-octagon fs-1"></i>
                        </div>
                        <p class="mb-4">Are you sure you want to delete <strong
                                id="delete_bucket_name_display"></strong> and all its contents?</p>
                        <div
                            class="alert bg-destructive/5 border-destructive/10 text-destructive small text-start p-3 mb-0">
                            <strong>Note:</strong> All objects stored in this bucket will be permanently erased from
                            disk.
                        </div>
                    </div>
                    <div class="p-4 border-top bg-neutral-50 d-flex gap-2">
                        <button type="button" class="s3-btn s3-btn-outline flex-grow-1"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit"
                            class="s3-btn s3-btn-primary bg-destructive border-destructive text-white flex-grow-1 py-2">Delete
                            Permanently</button>
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
        function deleteBucket(bucketId, bucketName) {
            document.getElementById('delete_bucket_id').value = bucketId;
            document.getElementById('delete_bucket_name_display').textContent = bucketName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function viewBucket(bucketName) {
            window.open('/' + bucketName, '_blank');
        }

        function revokePermission(userId, bucketId, username) {
            if (confirm('Revoke access for ' + username + '?')) {
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

        document.getElementById('permissionModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const bucketId = button.getAttribute('data-bucket-id');
            const bucketName = button.getAttribute('data-bucket-name');

            document.getElementById('permission_bucket_id').value = bucketId;
            document.getElementById('permission_bucket_name_display').textContent = 'Managing access for ' + bucketName;
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