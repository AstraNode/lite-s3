<?php
/**
 * Change Password Page
 */

require_once '../config.php';
require_once '../auth.php';

// Check authentication
if (!($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: /admin/login');
    exit;
}

$pdo = getDB();
$currentUserId = $_SESSION['user_id'];
$isAdmin = $_SESSION['is_admin'] ?? false;

// Handle secret key change
if (($_POST['action'] ?? '') === 'change_password') {
    $currentSecretKey = $_POST['current_password'] ?? '';
    $newSecretKey = $_POST['new_password'] ?? '';
    $confirmSecretKey = $_POST['confirm_password'] ?? '';

    if (!$currentSecretKey || !$newSecretKey || !$confirmSecretKey) {
        $error = 'All fields are required';
    } elseif ($newSecretKey !== $confirmSecretKey) {
        $error = 'New secret keys do not match';
    } elseif (strlen($newSecretKey) < 6) {
        $error = 'New secret key must be at least 6 characters long';
    } else {
        // Verify current secret key
        $stmt = $pdo->prepare("SELECT secret_key FROM users WHERE id = ?");
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch();

        if ($user && password_verify($currentSecretKey, $user['secret_key'])) {
            // Update secret key
            $hashedSecretKey = password_hash($newSecretKey, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET secret_key = ? WHERE id = ?");

            if ($stmt->execute([$hashedSecretKey, $currentUserId])) {
                $success = 'Secret key changed successfully';
                error_log("Secret key changed for user ID: " . $currentUserId);
            } else {
                $error = 'Failed to update secret key';
            }
        } else {
            $error = 'Current secret key is incorrect';
        }
    }
}

// Handle admin changing other user's secret key
if (($_POST['action'] ?? '') === 'change_user_password' && $isAdmin) {
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $newSecretKey = $_POST['new_password'] ?? '';
    $confirmSecretKey = $_POST['confirm_password'] ?? '';

    if (!$targetUserId || !$newSecretKey || !$confirmSecretKey) {
        $error = 'All fields are required';
    } elseif ($newSecretKey !== $confirmSecretKey) {
        $error = 'New secret keys do not match';
    } elseif (strlen($newSecretKey) < 6) {
        $error = 'New secret key must be at least 6 characters long';
    } else {
        // Verify target user exists
        $stmt = $pdo->prepare("SELECT access_key FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch();

        if ($targetUser) {
            // Update secret key
            $hashedSecretKey = password_hash($newSecretKey, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET secret_key = ? WHERE id = ?");

            if ($stmt->execute([$hashedSecretKey, $targetUserId])) {
                $success = 'Secret key changed successfully for user: ' . $targetUser['access_key'];
                error_log("Admin changed secret key for user ID: " . $targetUserId);
            } else {
                $error = 'Failed to update secret key';
            }
        } else {
            $error = 'User not found';
        }
    }
}

// Get current user info
$stmt = $pdo->prepare("SELECT access_key, is_admin FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch();

// Get all users for admin
$allUsers = [];
if ($isAdmin) {
    $allUsers = $pdo->query("SELECT id, access_key, is_admin FROM users ORDER BY access_key")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Settings | S3 Storage Admin</title>
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
                    <?php if ($isAdmin): ?>
                        <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                            href="?page=users">Users</a>
                    <?php endif; ?>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900"
                        href="?page=monitor">Monitor</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent fw-semibold"
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
        <header class="mb-5">
            <h1 class="fw-bold fs-2 mb-2">Security Settings</h1>
            <p class="text-neutral-500 mb-0">Update your access credentials and manage user security.</p>
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

        <div class="row g-4 mb-5">
            <!-- Change Own Password -->
            <div class="col-lg-6">
                <div class="s3-card h-100">
                    <div class="mb-4">
                        <h5 class="fw-bold mb-1">Update Personal Secret</h5>
                        <p class="text-xs text-neutral-500 mb-0">Change your own S3 secret key and console password.</p>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">

                        <div class="mb-3">
                            <label for="current_password"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Current Secret
                                Key</label>
                            <div class="position-relative">
                                <input type="password" class="s3-input pe-5" id="current_password"
                                    name="current_password" required>
                                <button type="button"
                                    class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                    onclick="togglePassword('current_password')">
                                    <i class="bi bi-eye" id="current_password_toggle"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_password"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">New Secret
                                Key</label>
                            <div class="position-relative">
                                <input type="password" class="s3-input pe-5" id="new_password" name="new_password"
                                    minlength="6" required>
                                <button type="button"
                                    class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                    onclick="togglePassword('new_password')">
                                    <i class="bi bi-eye" id="new_password_toggle"></i>
                                </button>
                            </div>
                            <div class="text-xs text-neutral-400 mt-2">Minimum 6 characters.</div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password"
                                class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Confirm New
                                Key</label>
                            <div class="position-relative">
                                <input type="password" class="s3-input pe-5" id="confirm_password"
                                    name="confirm_password" minlength="6" required>
                                <button type="button"
                                    class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                    onclick="togglePassword('confirm_password')">
                                    <i class="bi bi-eye" id="confirm_password_toggle"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="s3-btn s3-btn-primary w-100">
                            Apply Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Admin: Change Other User's Password -->
            <?php if ($isAdmin && !empty($allUsers)): ?>
                <div class="col-lg-6">
                    <div class="s3-card h-100">
                        <div class="mb-4">
                            <h5 class="fw-bold mb-1">Administrative Override</h5>
                            <p class="text-xs text-neutral-500 mb-0">Reset credentials for any system user.</p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_user_password">

                            <div class="mb-3">
                                <label for="user_id"
                                    class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Select Target
                                    User</label>
                                <select class="s3-input" id="user_id" name="user_id" required>
                                    <option value="" disabled selected>Select a user...</option>
                                    <?php foreach ($allUsers as $user): ?>
                                        <option value="<?= $user['id'] ?>">
                                            <?= htmlspecialchars($user['access_key']) ?>
                                            <?php if ($user['is_admin']): ?> (Admin)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="admin_new_password"
                                    class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">New User
                                    Password</label>
                                <div class="position-relative">
                                    <input type="password" class="s3-input pe-5" id="admin_new_password" name="new_password"
                                        minlength="6" required>
                                    <button type="button"
                                        class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                        onclick="togglePassword('admin_new_password')">
                                        <i class="bi bi-eye" id="admin_new_password_toggle"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="admin_confirm_password"
                                    class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Confirm
                                    Reset</label>
                                <div class="position-relative">
                                    <input type="password" class="s3-input pe-5" id="admin_confirm_password"
                                        name="confirm_password" minlength="6" required>
                                    <button type="button"
                                        class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400"
                                        onclick="togglePassword('admin_confirm_password')">
                                        <i class="bi bi-eye" id="admin_confirm_password_toggle"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit"
                                class="s3-btn s3-btn-outline border-neutral-300 w-100 hover:bg-neutral-50">
                                Reset User Credentials
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="s3-card bg-neutral-100 border-0 p-4">
            <h6 class="fw-bold mb-3 d-flex align-items-center">
                <i class="bi bi-shield-lock me-2"></i> Security Best Practices
            </h6>
            <div class="row g-4">
                <div class="col-md-6">
                    <ul class="text-neutral-600 small mb-0 ps-3">
                        <li class="mb-2">Use at least 12 characters for production environments.</li>
                        <li class="mb-2">Avoid reusing secrets across different S3 providers.</li>
                        <li>Rotate keys every 90 days to minimize breach impact.</li>
                    </ul>
                </div>
                <div class="col-md-6 border-start border-neutral-200">
                    <p class="text-xs text-neutral-400 mb-0">Changes to your secret key will update both your admin
                        console password and your S3 API secret key. Active sessions may be terminated after update.</p>
                </div>
            </div>
        </div>
    </main>

    <footer class="py-5 text-center text-neutral-400 border-top mt-5 bg-neutral-50">
        <p class="small mb-0">S3 Storage Admin Security &bull; Managed Infrastructure</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

        function setupValidation(input1, input2) {
            const validate = () => {
                if (input2.value && input1.value !== input2.value) {
                    input2.setCustomValidity('Passwords do not match');
                } else {
                    input2.setCustomValidity('');
                }
            };
            input1.addEventListener('input', validate);
            input2.addEventListener('input', validate);
        }

        const np = document.getElementById('new_password');
        const cp = document.getElementById('confirm_password');
        if (np && cp) setupValidation(np, cp);

        const anp = document.getElementById('admin_new_password');
        const acp = document.getElementById('admin_confirm_password');
        if (anp && acp) setupValidation(anp, acp);
    </script>
</body>

</html>