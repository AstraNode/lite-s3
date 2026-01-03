<?php
/**
 * Bucket Operations API
 * S3-compatible bucket management with proper error handling
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/s3-errors.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/constants.php';

class BucketAPI {
    private $pdo;
    
    // S3 bucket naming rules
    private static $BUCKET_NAME_MIN_LENGTH = 3;
    private static $BUCKET_NAME_MAX_LENGTH = 63;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    /**
     * Validate bucket name according to S3 naming rules
     */
    private function validateBucketName($name) {
        // Length check
        if (strlen($name) < self::$BUCKET_NAME_MIN_LENGTH || strlen($name) > self::$BUCKET_NAME_MAX_LENGTH) {
            return ['valid' => false, 'message' => 'Bucket name must be between 3 and 63 characters'];
        }
        
        // Must start and end with lowercase letter or number
        if (!preg_match('/^[a-z0-9]/', $name) || !preg_match('/[a-z0-9]$/', $name)) {
            return ['valid' => false, 'message' => 'Bucket name must start and end with a lowercase letter or number'];
        }
        
        // Only lowercase letters, numbers, hyphens, and dots
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name) && strlen($name) > 2) {
            return ['valid' => false, 'message' => 'Bucket name can only contain lowercase letters, numbers, hyphens, and periods'];
        }
        
        // Cannot contain consecutive periods
        if (strpos($name, '..') !== false) {
            return ['valid' => false, 'message' => 'Bucket name cannot contain consecutive periods'];
        }
        
        // Cannot be formatted as IP address
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $name)) {
            return ['valid' => false, 'message' => 'Bucket name cannot be formatted as an IP address'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * List all buckets for user
     */
    public function listBuckets($user) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.name, b.created_at 
                FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE p.user_id = ?
                ORDER BY b.name
            ");
            $stmt->execute([$user['id']]);
            $buckets = $stmt->fetchAll();
            
            $bucketList = [];
            foreach ($buckets as $b) {
                $bucketList[] = [
                    'Name' => $b['name'],
                    'CreationDate' => date('c', strtotime($b['created_at']))
                ];
            }
            
            $result = [
                'Owner' => [
                    'ID' => 'owner-' . $user['id'],
                    'DisplayName' => $user['username']
                ],
                'Buckets' => [
                    'Bucket' => $bucketList
                ]
            ];
            
            S3Response::xml('ListAllMyBucketsResult', $result);
        } catch (Exception $e) {
            error_log("ListBuckets error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Create a new bucket
     */
    public function create($bucketName, $user) {
        try {
            // Validate bucket name
            $validation = $this->validateBucketName($bucketName);
            if (!$validation['valid']) {
                S3Response::error(S3ErrorCodes::INVALID_BUCKET_NAME, $validation['message'], null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Check if bucket already exists
            $stmt = $this->pdo->prepare("SELECT id, user_id FROM buckets WHERE name = ?");
            $stmt->execute([$bucketName]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if ($existing['user_id'] == $user['id']) {
                    S3Response::error(S3ErrorCodes::BUCKET_ALREADY_OWNED_BY_YOU, null, null, ['BucketName' => $bucketName]);
                } else {
                    S3Response::error(S3ErrorCodes::BUCKET_ALREADY_EXISTS, null, null, ['BucketName' => $bucketName]);
                }
                return;
            }
            
            // Create bucket directory
            $bucketPath = STORAGE_PATH . $bucketName;
            if (!is_dir($bucketPath)) {
                if (!@mkdir($bucketPath, 0755, true)) {
                    S3Response::error(S3ErrorCodes::INTERNAL_ERROR, 'Failed to create storage directory');
                    return;
                }
            }
            
            // Insert into database
            $stmt = $this->pdo->prepare("INSERT INTO buckets (name, user_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$bucketName, $user['id']]);
            $bucketId = $this->pdo->lastInsertId();
            
            // Grant admin permission
            $stmt = $this->pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, 'admin')");
            $stmt->execute([$user['id'], $bucketId]);
            
            // Return success with Location header
            S3Response::bucketCreated('/' . $bucketName);
            
        } catch (Exception $e) {
            error_log("CreateBucket error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete a bucket
     */
    public function delete($bucketName, $user) {
        try {
            // Check bucket exists and user has admin permission
            $stmt = $this->pdo->prepare("
                SELECT b.id FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ? AND p.permission = 'admin'
            ");
            $stmt->execute([$bucketName, $user['id']]);
            $bucket = $stmt->fetch();
            
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Check bucket is empty
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM objects WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            if ($stmt->fetchColumn() > 0) {
                S3Response::error(S3ErrorCodes::BUCKET_NOT_EMPTY, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Delete bucket directory
            $bucketPath = STORAGE_PATH . $bucketName;
            if (is_dir($bucketPath)) {
                @rmdir($bucketPath);
            }
            
            // Delete permissions first
            $this->pdo->prepare("DELETE FROM permissions WHERE bucket_id = ?")->execute([$bucket['id']]);
            
            // Delete bucket
            $this->pdo->prepare("DELETE FROM buckets WHERE id = ?")->execute([$bucket['id']]);
            
            S3Response::noContent();
            
        } catch (Exception $e) {
            error_log("DeleteBucket error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * HEAD bucket - check if bucket exists
     */
    public function head($bucketName, $user) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.id FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ?
            ");
            $stmt->execute([$bucketName, $user['id']]);
            
            if (!$stmt->fetch()) {
                http_response_code(404);
                return;
            }
            
            http_response_code(200);
            
        } catch (Exception $e) {
            http_response_code(500);
        }
    }
    
    /**
     * Get bucket location
     */
    public function getLocation($bucketName, $user) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.id, b.region FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ?
            ");
            $stmt->execute([$bucketName, $user['id']]);
            $bucket = $stmt->fetch();
            
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $region = $bucket['region'] ?? 'us-east-1';
            
            // S3 returns empty LocationConstraint for us-east-1
            S3Response::xml('LocationConstraint', [
                '_value' => ($region === 'us-east-1') ? '' : $region
            ]);
            
        } catch (Exception $e) {
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Get bucket versioning status
     */
    public function getVersioning($bucketName, $user) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.versioning_enabled FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ?
            ");
            $stmt->execute([$bucketName, $user['id']]);
            $bucket = $stmt->fetch();
            
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $status = $bucket['versioning_enabled'] ? 'Enabled' : 'Suspended';
            
            S3Response::xml('VersioningConfiguration', [
                'Status' => $status
            ]);
            
        } catch (Exception $e) {
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
}
