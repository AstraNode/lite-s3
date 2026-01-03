<?php
/**
 * Authentication and Authorization System
 * Handles S3-style authentication and user permissions
 */

function authenticateRequest() {
    // Get authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    // Check for query parameter authentication (presigned URLs)
    $accessKey = $_GET['AWSAccessKeyId'] ?? '';
    $signature = $_GET['Signature'] ?? '';
    
    if (!empty($accessKey) && !empty($signature)) {
        return validateQueryAuth($accessKey, $signature);
    }
    
    // V4 presigned URL params
    if (!empty($_GET['X-Amz-Credential'])) {
        $credential = $_GET['X-Amz-Credential'];
        if (preg_match('/^([^\/]+)\//', $credential, $matches)) {
            $accessKey = $matches[1];
            return getUserByAccessKey($accessKey);
        }
    }
    
    // Require auth header
    if (empty($authHeader)) {
        return false;
    }
    
    // Basic auth format (for simple clients like curl -u)
    if (strpos($authHeader, 'Basic ') === 0) {
        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded);
        if ($decoded) {
            $parts = explode(':', $decoded, 2);
            if (count($parts) === 2) {
                return validateSimpleAuth($parts[0], $parts[1]);
            }
        }
        return false;
    }
    
    // Parse AWS Signature V4 format: AWS4-HMAC-SHA256 Credential=...
    if (strpos($authHeader, 'AWS4-HMAC-SHA256') === 0) {
        if (preg_match('/Credential=([^\/]+)/', $authHeader, $matches)) {
            $accessKey = $matches[1];
            return getUserByAccessKey($accessKey);
        }
        return false;
    }
    
    // Parse simple AWS format: AWS access_key:secret_key
    if (strpos($authHeader, 'AWS ') === 0) {
        $credentials = substr($authHeader, 4);
        $parts = explode(':', $credentials, 2);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        $accessKey = $parts[0];
        $providedSecret = $parts[1];
        
        return validateSimpleAuth($accessKey, $providedSecret);
    }
    
    return false;
}

/**
 * Get user by access key
 */
function getUserByAccessKey($accessKey) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE access_key = ?");
    $stmt->execute([$accessKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

/**
 * Validate query string authentication (for presigned URLs or simple clients)
 */
function validateQueryAuth($accessKey, $signature) {
    $user = getUserByAccessKey($accessKey);
    if (!$user) {
        return false;
    }
    
    // Check if signature matches the plain secret (for simple S3 clients)
    if (password_verify($signature, $user['secret_key'])) {
        return $user;
    }
    
    return false;
}

/**
 * Validate simple AWS auth format (access_key:secret_key)
 */
function validateSimpleAuth($accessKey, $providedSecret) {
    $user = getUserByAccessKey($accessKey);
    if (!$user) {
        return false;
    }
    
    // Method 1: password_hash verification (for bcrypt hashed secret_key)
    if (password_verify($providedSecret, $user['secret_key'])) {
        return $user;
    }
    
    // Method 2: Match against plain_secret_key (for S3 API clients)
    if (!empty($user['plain_secret_key']) && $providedSecret === $user['plain_secret_key']) {
        return $user;
    }
    
    // Method 3: Direct match with secret_key (for legacy plain-text secrets)
    if ($providedSecret === $user['secret_key']) {
        return $user;
    }
    
    return false;
}

function hasBucketPermission($userId, $bucketName, $method) {
    $pdo = getDB();
    
    // Check if bucket exists
    $stmt = $pdo->prepare("SELECT b.*, u.is_admin FROM buckets b 
                          JOIN users u ON b.user_id = u.id 
                          WHERE b.name = ?");
    $stmt->execute([$bucketName]);
    $bucket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bucket) {
        // Bucket doesn't exist, check if user can create it
        return in_array($method, ['PUT']) && $userId;
    }
    
    // Check if user is admin
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['is_admin']) {
        return true;
    }
    
    // Check if user owns the bucket
    if ($bucket['user_id'] == $userId) {
        return true;
    }
    
    // Check explicit permissions
    $requiredPermission = 'read';
    if (in_array($method, ['PUT', 'DELETE'])) {
        $requiredPermission = 'write';
    }
    
    $stmt = $pdo->prepare("SELECT permission FROM permissions 
                          WHERE user_id = ? AND bucket_id = ?");
    $stmt->execute([$userId, $bucket['id']]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$permission) {
        return false;
    }
    
    $permissions = ['read' => 1, 'write' => 2, 'admin' => 3];
    return $permissions[$permission['permission']] >= $permissions[$requiredPermission];
}

function createBucket($bucketName, $ownerId) {
    $pdo = getDB();
    
    try {
        $stmt = $pdo->prepare("INSERT INTO buckets (name, user_id) VALUES (?, ?)");
        $stmt->execute([$bucketName, $ownerId]);
        $bucketId = $pdo->lastInsertId();
        
        // Grant admin permission to owner
        $stmt = $pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, 'admin')");
        $stmt->execute([$ownerId, $bucketId]);
        
        // Create storage directory
        $bucketPath = STORAGE_PATH . $bucketName;
        if (!is_dir($bucketPath)) {
            mkdir($bucketPath, 0755, true);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("createBucket error: " . $e->getMessage());
        return false;
    }
}

function createUser($username, $accessKey, $secretKey, $isAdmin = false) {
    $pdo = getDB();
    
    try {
        $hashedSecret = hash('sha256', $secretKey . SECRET_SALT);
        $stmt = $pdo->prepare("INSERT INTO users (username, access_key, secret_key, is_admin) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $accessKey, $hashedSecret, $isAdmin ? 1 : 0]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function grantBucketPermission($userId, $bucketId, $permission) {
    $pdo = getDB();
    
    try {
        // Check if permission already exists
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE user_id = ? AND bucket_id = ?");
        $stmt->execute([$userId, $bucketId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing permission
            $stmt = $pdo->prepare("UPDATE permissions SET permission = ? WHERE id = ?");
            $stmt->execute([$permission, $existing['id']]);
        } else {
            // Insert new permission
            $stmt = $pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $bucketId, $permission]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Permission error: " . $e->getMessage());
        return false;
    }
}

function revokeBucketPermission($userId, $bucketId) {
    $pdo = getDB();
    
    try {
        $stmt = $pdo->prepare("DELETE FROM permissions WHERE user_id = ? AND bucket_id = ?");
        $stmt->execute([$userId, $bucketId]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function generatePresignedUrl($bucket, $object, $expires = null) {
    if (!$expires) {
        $expires = time() + PRESIGNED_URL_EXPIRY;
    }
    
    $token = base64_encode(json_encode([
        'bucket' => $bucket,
        'object' => $object,
        'expires' => $expires
    ]));
    
    $signature = hash('sha256', $token . SECRET_SALT);
    
    return "?token=" . urlencode($token) . "&signature=" . $signature;
}

function validatePresignedUrl($token, $signature) {
    $expectedSignature = hash('sha256', $token . SECRET_SALT);
    
    if ($signature !== $expectedSignature) {
        return false;
    }
    
    $data = json_decode(base64_decode($token), true);
    
    if (!$data || !isset($data['expires'])) {
        return false;
    }
    
    if (time() > $data['expires']) {
        return false;
    }
    
    return $data;
}

function createUserWithPassword($accessKey, $hashedSecretKey, $s3AccessKey, $s3SecretKey, $isAdmin = false) {
    try {
        $pdo = getDB();
        
        // Check if access key already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE access_key = ?");
        $stmt->execute([$accessKey]);
        if ($stmt->fetchColumn() > 0) {
            error_log("User creation failed: Access key '$accessKey' already exists");
            return false;
        }
        
        // Create user with unified authentication:
        // - secret_key: bcrypt hash for admin panel login verification
        // - plain_secret_key: plaintext S3 secret for AWS Signature V4 calculation
        $stmt = $pdo->prepare("INSERT INTO users (username, access_key, secret_key, plain_secret_key, is_admin, active) VALUES (?, ?, ?, ?, ?, 1)");
        $result = $stmt->execute([$accessKey, $s3AccessKey, $hashedSecretKey, $s3SecretKey, $isAdmin ? 1 : 0]);
        
        if ($result) {
            error_log("User created successfully: $accessKey with S3 access key: $s3AccessKey");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Failed to create user: " . $e->getMessage());
        return false;
    }
}
?>
