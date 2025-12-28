<?php
/**
 * Object Operations API
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/helpers.php';

class ObjectAPI {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    public function listObjects($bucketName, $user, $params = []) {
        $bucket = $this->getBucket($bucketName, $user);
        if (!$bucket) return;
        
        $prefix = $params['prefix'] ?? '';
        $marker = $params['marker'] ?? '';
        $maxKeys = min((int)($params['max-keys'] ?? 1000), 1000);
        
        $stmt = $this->pdo->prepare("
            SELECT object_key, size, etag, created_at 
            FROM objects WHERE bucket_id = ? AND object_key LIKE ? AND object_key > ?
            ORDER BY object_key LIMIT ?
        ");
        $stmt->execute([$bucket['id'], $prefix . '%', $marker, $maxKeys + 1]);
        $objects = $stmt->fetchAll();
        
        $isTruncated = count($objects) > $maxKeys;
        if ($isTruncated) array_pop($objects);
        
        $result = [
            'Name' => $bucketName, 'Prefix' => $prefix, 'MaxKeys' => $maxKeys,
            'IsTruncated' => $isTruncated ? 'true' : 'false', 'Marker' => $marker
        ];
        
        foreach ($objects as $o) {
            $result['Contents'][] = [
                'Key' => $o['object_key'], 'Size' => $o['size'],
                'ETag' => '"' . $o['etag'] . '"',
                'LastModified' => date('c', strtotime($o['created_at'])),
                'StorageClass' => 'STANDARD',
                'Owner' => ['ID' => 'owner-' . $user['id'], 'DisplayName' => $user['username']]
            ];
        }
        
        S3Response::xml('ListBucketResult', $result);
    }
    
    public function get($bucketName, $objectKey, $user) {
        $bucket = $this->getBucket($bucketName, $user);
        if (!$bucket) return;
        
        $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE bucket_id = ? AND object_key = ?");
        $stmt->execute([$bucket['id'], $objectKey]);
        $object = $stmt->fetch();
        
        if (!$object) {
            S3Response::error('NoSuchKey', 'Object not found', 404);
            return;
        }
        
        $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
        if (!file_exists($filePath)) {
            S3Response::error('NoSuchKey', 'File not found', 404);
            return;
        }
        
        header('Content-Type: ' . ($object['mime_type'] ?? 'application/octet-stream'));
        header('Content-Length: ' . $object['size']);
        header('ETag: "' . $object['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT');
        
        // Chunked streaming for large files
        $handle = fopen($filePath, 'rb');
        while (!feof($handle)) {
            echo fread($handle, CHUNK_SIZE);
            flush();
        }
        fclose($handle);
    }
    
    public function put($bucketName, $objectKey, $user) {
        $bucket = $this->getBucket($bucketName, $user, 'write');
        if (!$bucket) return;
        
        $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
        $dir = dirname($filePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        
        $content = file_get_contents('php://input');
        file_put_contents($filePath, $content);
        
        $size = strlen($content);
        $etag = md5($content);
        $mimeType = getMimeType($objectKey);
        
        $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
        $stmt->execute([$bucket['id'], $objectKey]);
        
        if ($stmt->fetch()) {
            $stmt = $this->pdo->prepare("UPDATE objects SET size = ?, etag = ?, mime_type = ?, created_at = NOW() WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$size, $etag, $mimeType, $bucket['id'], $objectKey]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO objects (bucket_id, object_key, size, etag, mime_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$bucket['id'], $objectKey, $size, $etag, $mimeType]);
        }
        
        header('ETag: "' . $etag . '"');
        http_response_code(200);
    }
    
    public function delete($bucketName, $objectKey, $user) {
        $bucket = $this->getBucket($bucketName, $user, 'write');
        if (!$bucket) return;
        
        $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
        if (file_exists($filePath)) unlink($filePath);
        
        $this->pdo->prepare("DELETE FROM objects WHERE bucket_id = ? AND object_key = ?")
            ->execute([$bucket['id'], $objectKey]);
        
        http_response_code(204);
    }
    
    public function head($bucketName, $objectKey, $user) {
        $bucket = $this->getBucket($bucketName, $user);
        if (!$bucket) return;
        
        $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE bucket_id = ? AND object_key = ?");
        $stmt->execute([$bucket['id'], $objectKey]);
        $object = $stmt->fetch();
        
        if (!$object) {
            http_response_code(404);
            return;
        }
        
        header('Content-Type: ' . ($object['mime_type'] ?? 'application/octet-stream'));
        header('Content-Length: ' . $object['size']);
        header('ETag: "' . $object['etag'] . '"');
        http_response_code(200);
    }
    
    private function getBucket($bucketName, $user, $permission = 'read') {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.name FROM buckets b 
            JOIN permissions p ON b.id = p.bucket_id 
            WHERE b.name = ? AND p.user_id = ?
        ");
        $stmt->execute([$bucketName, $user['id']]);
        $bucket = $stmt->fetch();
        
        if (!$bucket) {
            S3Response::error('NoSuchBucket', 'Bucket not found', 404);
            return null;
        }
        return $bucket;
    }
}
