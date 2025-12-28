<?php
/**
 * Bucket Operations API
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/constants.php';

class BucketAPI {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    public function listBuckets($user) {
        $stmt = $this->pdo->prepare("
            SELECT b.name, b.created_at 
            FROM buckets b 
            JOIN permissions p ON b.id = p.bucket_id 
            WHERE p.user_id = ?
            ORDER BY b.name
        ");
        $stmt->execute([$user['id']]);
        $buckets = $stmt->fetchAll();
        
        $result = [
            'Owner' => ['ID' => 'owner-' . $user['id'], 'DisplayName' => $user['username']],
            'Buckets' => []
        ];
        
        foreach ($buckets as $b) {
            $result['Buckets'][] = [
                'Contents' => [
                    'Name' => $b['name'],
                    'CreationDate' => date('c', strtotime($b['created_at']))
                ]
            ];
        }
        
        S3Response::xml('ListAllMyBucketsResult', $result);
    }
    
    public function create($bucketName, $user) {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucketName)) {
            S3Response::error('InvalidBucketName', 'Bucket name invalid', 400);
            return;
        }
        
        $stmt = $this->pdo->prepare("SELECT id FROM buckets WHERE name = ?");
        $stmt->execute([$bucketName]);
        if ($stmt->fetch()) {
            S3Response::error('BucketAlreadyExists', 'Bucket already exists', 409);
            return;
        }
        
        $bucketPath = STORAGE_PATH . $bucketName;
        if (!is_dir($bucketPath)) {
            mkdir($bucketPath, 0755, true);
        }
        
        $stmt = $this->pdo->prepare("INSERT INTO buckets (name, user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$bucketName, $user['id']]);
        $bucketId = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, 'admin')");
        $stmt->execute([$user['id'], $bucketId]);
        
        http_response_code(200);
    }
    
    public function delete($bucketName, $user) {
        $stmt = $this->pdo->prepare("
            SELECT b.id FROM buckets b 
            JOIN permissions p ON b.id = p.bucket_id 
            WHERE b.name = ? AND p.user_id = ? AND p.permission = 'admin'
        ");
        $stmt->execute([$bucketName, $user['id']]);
        $bucket = $stmt->fetch();
        
        if (!$bucket) {
            S3Response::error('NoSuchBucket', 'Bucket not found', 404);
            return;
        }
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM objects WHERE bucket_id = ?");
        $stmt->execute([$bucket['id']]);
        if ($stmt->fetchColumn() > 0) {
            S3Response::error('BucketNotEmpty', 'Bucket is not empty', 409);
            return;
        }
        
        $bucketPath = STORAGE_PATH . $bucketName;
        if (is_dir($bucketPath)) rmdir($bucketPath);
        
        $this->pdo->prepare("DELETE FROM permissions WHERE bucket_id = ?")->execute([$bucket['id']]);
        $this->pdo->prepare("DELETE FROM buckets WHERE id = ?")->execute([$bucket['id']]);
        
        http_response_code(204);
    }
}
