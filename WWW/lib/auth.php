<?php
/**
 * Simplified Auth Module
 */

require_once __DIR__ . '/lib/db.php';

function authenticateRequest() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    // Query params (presigned URLs)
    if (!empty($_GET['AWSAccessKeyId']) && !empty($_GET['Signature'])) {
        return validateAuth($_GET['AWSAccessKeyId'], $_GET['Signature']);
    }
    
    if (empty($authHeader)) return false;
    
    // AWS4 Signature
    if (strpos($authHeader, 'AWS4-HMAC-SHA256') === 0) {
        preg_match('/Credential=([^\/]+)/', $authHeader, $m);
        return $m ? getUserByKey($m[1]) : false;
    }
    
    // Simple AWS format
    if (strpos($authHeader, 'AWS ') === 0) {
        $parts = explode(':', substr($authHeader, 4), 2);
        return count($parts) === 2 ? validateAuth($parts[0], $parts[1]) : false;
    }
    
    return false;
}

function getUserByKey($accessKey) {
    $stmt = getDB()->prepare("SELECT * FROM users WHERE access_key = ?");
    $stmt->execute([$accessKey]);
    return $stmt->fetch() ?: false;
}

function validateAuth($accessKey, $secret) {
    $user = getUserByKey($accessKey);
    if (!$user) return false;
    return (password_verify($secret, $user['secret_key']) || $secret === $user['secret_key']) ? $user : false;
}
