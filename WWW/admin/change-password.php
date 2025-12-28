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
    $targetUserId = (int)($_POST['user_id'] ?? 0);
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
    <title>S3 Storage Admin - Change Password</title>
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
                <?php if ($isAdmin): ?>
                <a class="nav-link" href="?page=users">
                    <i class="bi bi-people"></i> Users
                </a>
                <?php endif; ?>
                <a class="nav-link active" href="?page=change-password">
                    <i class="bi bi-key"></i> Change Password
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
                <h1>
                    <i class="bi bi-key"></i> Change Password
                </h1>
                <p class="text-muted">Manage your account password</p>
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
            <!-- Change Own Password -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-person"></i> Change My Secret Key
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Secret Key</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('current_password')">
                                        <i class="bi bi-eye" id="current_password_toggle"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Secret Key</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" id="new_password" name="new_password" 
                                           minlength="6" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('new_password')">
                                        <i class="bi bi-eye" id="new_password_toggle"></i>
                                    </span>
                                </div>
                                <div class="form-text">Minimum 6 characters</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Secret Key</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           minlength="6" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password')">
                                        <i class="bi bi-eye" id="confirm_password_toggle"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Admin: Change Other User's Password -->
            <?php if ($isAdmin && !empty($allUsers)): ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-people"></i> Change User Password (Admin)
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_user_password">
                            
                            <div class="mb-3">
                                <label for="user_id" class="form-label">Select User</label>
                                <select class="form-select" id="user_id" name="user_id" required>
                                    <option value="">Choose a user...</option>
                                    <?php foreach ($allUsers as $user): ?>
                                        <option value="<?= $user['id'] ?>">
                                            <?= htmlspecialchars($user['username']) ?>
                                            <?php if ($user['is_admin']): ?>
                                                <span class="text-danger">(Admin)</span>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_new_password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" id="admin_new_password" name="new_password" 
                                           minlength="6" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('admin_new_password')">
                                        <i class="bi bi-eye" id="admin_new_password_toggle"></i>
                                    </span>
                                </div>
                                <div class="form-text">Minimum 6 characters</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_confirm_password" class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" id="admin_confirm_password" name="confirm_password" 
                                           minlength="6" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('admin_confirm_password')">
                                        <i class="bi bi-eye" id="admin_confirm_password_toggle"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-person-gear"></i> Change User Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Password Requirements -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle"></i> Password Requirements
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <li>Minimum 6 characters long</li>
                            <li>Use a combination of letters, numbers, and symbols for better security</li>
                            <li>Avoid using common words or personal information</li>
                            <li>Consider using a password manager to generate and store secure passwords</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
        
        // Password confirmation validation
        document.getElementById('new_password').addEventListener('input', function() {
            const newPassword = this.value;
            const confirmPassword = document.getElementById('confirm_password');
            
            if (confirmPassword.value && newPassword !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });
        
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Admin password confirmation validation
        document.getElementById('admin_new_password').addEventListener('input', function() {
            const newPassword = this.value;
            const confirmPassword = document.getElementById('admin_confirm_password');
            
            if (confirmPassword.value && newPassword !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });
        
        document.getElementById('admin_confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('admin_new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
