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
    <title>Login | S3 Storage Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
</head>
<body class="bg-neutral-50 animate-in">
    <div class="container">
        <div class="row justify-content-center min-vh-100 align-items-center">
            <div class="col-md-5 col-lg-4">
                <div class="s3-card shadow-sm border-neutral-200">
                    <div class="text-center mb-5">
                        <div class="d-inline-flex align-items-center justify-content-center bg-black text-white rounded-3 mb-3" style="width: 48px; height: 48px;">
                            <i class="bi bi-cloud-upload fs-4"></i>
                        </div>
                        <h4 class="fw-bold">Welcome back</h4>
                        <p class="text-neutral-500 small">Enter your credentials to access your storage</p>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert bg-destructive/10 border-destructive/20 text-destructive small py-2 d-flex align-items-center mb-4" role="alert">
                            <i class="bi bi-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="loginForm">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="mb-4">
                            <label for="username" class="text-xs fw-bold text-neutral-500 mb-2 d-block text-uppercase">Access Key</label>
                            <input type="text" class="s3-input" id="username" name="username" 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                   required autocomplete="username" placeholder="Your Access Key">
                        </div>
                        
                        <div class="mb-5">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label for="password" class="text-xs fw-bold text-neutral-500 d-block text-uppercase">Secret Key</label>
                            </div>
                            <div class="position-relative">
                                <input type="password" class="s3-input pe-5" id="password" name="password" 
                                       required autocomplete="current-password" placeholder="••••••••">
                                <button type="button" class="position-absolute end-0 top-50 translate-middle-y border-0 bg-transparent px-3 text-neutral-400" onclick="togglePassword()">
                                    <i class="bi bi-eye" id="passwordToggleIcon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="s3-btn s3-btn-primary w-100 py-2">
                            Sign In to Admin
                        </button>
                    </form>
                    
                    <div class="text-center mt-5">
                        <a href="/" class="text-neutral-400 small text-decoration-none hover:text-neutral-600">
                            <i class="bi bi-arrow-left me-1"></i> Back to public explorer
                        </a>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <p class="text-neutral-400 text-xs">S3 Storage Engine &copy; <?= date('Y') ?></p>
                </div>
            </div>
        </div>
    </div>
    
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
        
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            const passwordField = document.getElementById('password');
            if (usernameField.value) { passwordField.focus(); } else { usernameField.focus(); }
        });
    </script>
</body>
</html>