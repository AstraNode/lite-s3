<?php
/**
 * Admin Panel Index - Main Router
 * Handles admin panel routing and authentication
 */

require_once '../config.php';
require_once '../security.php';
session_start();

// Check if user is logged in
if (!($_SESSION['admin_logged_in'] ?? false)) {
    // Redirect to login if not authenticated
    header('Location: login.php');
    exit;
}

// Check session timeout
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// Get the requested page
$page = $_GET['page'] ?? 'dashboard';

// Route to appropriate admin page
switch ($page) {
    case 'dashboard':
        include 'dashboard.php';
        break;
    case 'buckets':
        include 'buckets.php';
        break;
    case 'users':
        include 'users.php';
        break;
    case 'monitor':
        include 'monitor.php';
        break;
    case 'change-password':
        include 'change-password.php';
        break;
    case 'logout':
        session_destroy();
        header('Location: login.php');
        exit;
    default:
        // Default to dashboard
        include 'dashboard.php';
        break;
}
?>