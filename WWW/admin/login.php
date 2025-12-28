<?php
/**
 * Enhanced Admin Login Page with Username/Password Authentication
 */

require_once '../config.php';
require_once '../security.php';
session_start();

// Handle login
if (($_POST['action'] ?? '') === 'login') {
    $accessKey = trim($_POST['username'] ?? '');
    $secretKey = $_POST['password'] ?? '';
    
    // Check if client is blocked
    if (isClientBlocked()) {
        $error = 'Too many failed login attempts. Please try again later.';
        recordLoginAttempt($accessKey, false);
    } elseif ($accessKey && $secretKey) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, access_key, secret_key, is_admin FROM users WHERE access_key = ?");
        $stmt->execute([$accessKey]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($secretKey, $user['secret_key'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['access_key']; // access_key is the username
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['login_time'] = time();
            
            // Record successful login
            try {
                recordLoginAttempt($accessKey, true);
                logSecurityEvent('successful_login', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $user['id'], "Admin login successful");
            } catch (Exception $e) {
                error_log("Security logging failed: " . $e->getMessage());
            }
            
            header('Location: index.php?page=dashboard');
            exit;
        } else {
            $error = 'Invalid access key or secret key';
            try {
                recordLoginAttempt($accessKey, false);
                logSecurityEvent('failed_login', $_SERVER['REMOTE_ADDR'] ?? 'unknown', null, "Failed admin login attempt");
            } catch (Exception $e) {
                error_log("Security logging failed: " . $e->getMessage());
            }
        }
    } else {
        $error = 'Please enter both access key and secret key';
    }
}

// Check if already logged in
if ($_SESSION['admin_logged_in'] ?? false) {
    header('Location: index.php?page=dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Storage Admin - Login</title>
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
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center min-vh-100 align-items-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-cloud-upload text-primary" style="font-size: 3rem;"></i>
                            <h3 class="mt-3">S3 Storage Admin</h3>
                            <p class="text-muted">Sign in to manage your storage</p>
                        </div>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="loginForm">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Access Key</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                           required autocomplete="username" placeholder="Your S3 Access Key">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Secret Key</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required autocomplete="current-password" placeholder="Your S3 Secret Key">
                                    <span class="input-group-text password-toggle" onclick="togglePassword()">
                                        <i class="bi bi-eye" id="passwordToggleIcon"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-box-arrow-in-right"></i> Sign In
                            </button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                S3-Compatible Object Storage
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }
        
        // Focus on username field if empty, otherwise password
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            const passwordField = document.getElementById('password');
            
            if (usernameField.value) {
                passwordField.focus();
            } else {
                usernameField.focus();
            }
        });
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please enter both username and password');
                return false;
            }
        });
    </script>
</body>
</html>