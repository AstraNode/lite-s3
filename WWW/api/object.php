<?php
/**
 * Object Operations API
 * S3-compatible object management with proper error handling
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/s3-errors.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/helpers.php';

class ObjectAPI {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    /**
     * Get bucket with permission check
     */
    private function getBucket($bucketName, $user, $requiredPermission = 'read') {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.name, p.permission FROM buckets b 
            JOIN permissions p ON b.id = p.bucket_id 
            WHERE b.name = ? AND p.user_id = ?
        ");
        $stmt->execute([$bucketName, $user['id']]);
        $bucket = $stmt->fetch();
        
        if (!$bucket) {
            return null;
        }
        
        // Check permission level
        $permLevels = ['read' => 1, 'write' => 2, 'admin' => 3];
        $userLevel = $permLevels[$bucket['permission']] ?? 0;
        $requiredLevel = $permLevels[$requiredPermission] ?? 1;
        
        if ($userLevel < $requiredLevel) {
            return null;
        }
        
        return $bucket;
    }
    
    /**
     * List objects in a bucket (ListObjectsV1/V2)
     */
    public function listObjects($bucketName, $user, $params = []) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Parse parameters
            $prefix = $params['prefix'] ?? '';
            $marker = $params['marker'] ?? '';
            $continuationToken = $params['continuation-token'] ?? '';
            $maxKeys = min((int)($params['max-keys'] ?? 1000), 1000);
            $delimiter = $params['delimiter'] ?? '';
            $listType = (int)($params['list-type'] ?? 1);
            
            // Build query
            $sql = "SELECT object_key, size, etag, mime_type, created_at FROM objects WHERE bucket_id = ?";
            $sqlParams = [$bucket['id']];
            
            if ($prefix) {
                $sql .= " AND object_key LIKE ?";
                $sqlParams[] = $prefix . '%';
            }
            
            if ($marker && $listType === 1) {
                $sql .= " AND object_key > ?";
                $sqlParams[] = $marker;
            }
            
            if ($continuationToken && $listType === 2) {
                $sql .= " AND object_key > ?";
                $sqlParams[] = base64_decode($continuationToken);
            }
            
            $sql .= " ORDER BY object_key LIMIT " . ($maxKeys + 1);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sqlParams);
            $objects = $stmt->fetchAll();
            
            // Check truncation
            $isTruncated = count($objects) > $maxKeys;
            if ($isTruncated) {
                array_pop($objects);
            }
            
            // Build result
            $result = [
                'Name' => $bucketName,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
                'IsTruncated' => $isTruncated ? 'true' : 'false',
                'KeyCount' => count($objects)
            ];
            
            if ($listType === 1) {
                $result['Marker'] = $marker;
                if ($isTruncated && !empty($objects)) {
                    $result['NextMarker'] = end($objects)['object_key'];
                }
            } else {
                if ($continuationToken) {
                    $result['ContinuationToken'] = $continuationToken;
                }
                if ($isTruncated && !empty($objects)) {
                    $result['NextContinuationToken'] = base64_encode(end($objects)['object_key']);
                }
            }
            
            // Add contents
            $contents = [];
            foreach ($objects as $obj) {
                $contents[] = [
                    'Key' => $obj['object_key'],
                    'LastModified' => date('c', strtotime($obj['created_at'])),
                    'ETag' => '"' . $obj['etag'] . '"',
                    'Size' => (int)$obj['size'],
                    'StorageClass' => 'STANDARD',
                    'Owner' => [
                        'ID' => 'owner-' . $user['id'],
                        'DisplayName' => $user['username']
                    ]
                ];
            }
            
            if (!empty($contents)) {
                $result['Contents'] = $contents;
            }
            
            S3Response::xml('ListBucketResult', $result);
            
        } catch (Exception $e) {
            error_log("ListObjects error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Get object
     */
    public function get($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            $object = $stmt->fetch();
            
            if (!$object) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, null, null, ['Key' => $objectKey]);
                return;
            }
            
            $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            if (!file_exists($filePath)) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, 'Object file not found on disk', null, ['Key' => $objectKey]);
                return;
            }
            
            // Handle Range requests
            $range = $_SERVER['HTTP_RANGE'] ?? null;
            $fileSize = filesize($filePath);
            
            if ($range) {
                $this->sendRangeResponse($filePath, $fileSize, $range, $object);
                return;
            }
            
            // Set headers
            header('Content-Type: ' . ($object['mime_type'] ?? 'application/octet-stream'));
            header('Content-Length: ' . $fileSize);
            header('ETag: "' . $object['etag'] . '"');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT');
            header('Accept-Ranges: bytes');
            
            // Stream file
            $chunkSize = defined('CHUNK_SIZE') ? CHUNK_SIZE : 8192;
            $handle = fopen($filePath, 'rb');
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
            
        } catch (Exception $e) {
            error_log("GetObject error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Handle Range request for partial content
     */
    private function sendRangeResponse($filePath, $fileSize, $range, $object) {
        if (!preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            S3Response::error(S3ErrorCodes::INVALID_RANGE);
            return;
        }
        
        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
        
        if ($start > $end || $end >= $fileSize) {
            S3Response::error(S3ErrorCodes::INVALID_RANGE);
            return;
        }
        
        $length = $end - $start + 1;
        
        http_response_code(206);
        header('Content-Type: ' . ($object['mime_type'] ?? 'application/octet-stream'));
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Accept-Ranges: bytes');
        header('ETag: "' . $object['etag'] . '"');
        
        $handle = fopen($filePath, 'rb');
        fseek($handle, $start);
        $remaining = $length;
        $chunkSize = defined('CHUNK_SIZE') ? CHUNK_SIZE : 8192;
        
        while ($remaining > 0 && !feof($handle)) {
            $readSize = min($chunkSize, $remaining);
            echo fread($handle, $readSize);
            $remaining -= $readSize;
            flush();
        }
        fclose($handle);
    }
    
    /**
     * Put object
     */
    public function put($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Validate object key
            if (strpos($objectKey, '..') !== false) {
                S3Response::error(S3ErrorCodes::INVALID_ARGUMENT, 'Invalid object key');
                return;
            }
            
            $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $dir = dirname($filePath);
            
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    S3Response::error(S3ErrorCodes::INTERNAL_ERROR, 'Failed to create directory');
                    return;
                }
            }
            
            // Read content from input
            $content = file_get_contents('php://input');
            
            // Validate Content-MD5 if provided
            $contentMD5 = $_SERVER['HTTP_CONTENT_MD5'] ?? null;
            if ($contentMD5) {
                $calculatedMD5 = base64_encode(md5($content, true));
                if ($calculatedMD5 !== $contentMD5) {
                    S3Response::error(S3ErrorCodes::BAD_DIGEST, 'Content-MD5 mismatch');
                    return;
                }
            }
            
            // Write file
            if (file_put_contents($filePath, $content) === false) {
                S3Response::error(S3ErrorCodes::INTERNAL_ERROR, 'Failed to write file');
                return;
            }
            
            $size = strlen($content);
            $etag = md5($content);
            $mimeType = getMimeType($objectKey);
            
            // Upsert object metadata
            $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            
            if ($stmt->fetch()) {
                $stmt = $this->pdo->prepare("UPDATE objects SET size = ?, etag = ?, mime_type = ?, created_at = NOW() WHERE bucket_id = ? AND object_key = ?");
                $stmt->execute([$size, $etag, $mimeType, $bucket['id'], $objectKey]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO objects (bucket_id, object_key, size, etag, mime_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$bucket['id'], $objectKey, $size, $etag, $mimeType]);
            }
            
            S3Response::putSuccess($etag);
            
        } catch (Exception $e) {
            error_log("PutObject error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete object
     */
    public function delete($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            
            $this->pdo->prepare("DELETE FROM objects WHERE bucket_id = ? AND object_key = ?")
                ->execute([$bucket['id'], $objectKey]);
            
            // S3 returns 204 even if object didn't exist
            S3Response::noContent();
            
        } catch (Exception $e) {
            error_log("DeleteObject error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Head object
     */
    public function head($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                http_response_code(404);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            $object = $stmt->fetch();
            
            if (!$object) {
                http_response_code(404);
                return;
            }
            
            $filePath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $size = file_exists($filePath) ? filesize($filePath) : $object['size'];
            
            header('Content-Type: ' . ($object['mime_type'] ?? 'application/octet-stream'));
            header('Content-Length: ' . $size);
            header('ETag: "' . $object['etag'] . '"');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT');
            header('Accept-Ranges: bytes');
            http_response_code(200);
            
        } catch (Exception $e) {
            http_response_code(500);
        }
    }
    
    /**
     * Copy object
     */
    public function copy($bucketName, $objectKey, $user, $copySource) {
        try {
            // Parse copy source
            $copySource = ltrim(urldecode($copySource), '/');
            $parts = explode('/', $copySource, 2);
            
            if (count($parts) !== 2) {
                S3Response::error(S3ErrorCodes::INVALID_ARGUMENT, 'Invalid copy source');
                return;
            }
            
            $sourceBucket = $parts[0];
            $sourceKey = $parts[1];
            
            // Check source access
            $srcBucket = $this->getBucket($sourceBucket, $user);
            if (!$srcBucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $sourceBucket]);
                return;
            }
            
            // Check source object exists
            $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$srcBucket['id'], $sourceKey]);
            $srcObject = $stmt->fetch();
            
            if (!$srcObject) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, null, null, ['Key' => $sourceKey]);
                return;
            }
            
            // Check destination access
            $dstBucket = $this->getBucket($bucketName, $user, 'write');
            if (!$dstBucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Copy file
            $srcPath = STORAGE_PATH . $sourceBucket . '/' . $sourceKey;
            $dstPath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $dstDir = dirname($dstPath);
            
            if (!is_dir($dstDir)) {
                @mkdir($dstDir, 0755, true);
            }
            
            if (!copy($srcPath, $dstPath)) {
                S3Response::error(S3ErrorCodes::INTERNAL_ERROR, 'Failed to copy file');
                return;
            }
            
            $etag = md5_file($dstPath);
            $size = filesize($dstPath);
            
            // Upsert destination object
            $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$dstBucket['id'], $objectKey]);
            
            if ($stmt->fetch()) {
                $stmt = $this->pdo->prepare("UPDATE objects SET size = ?, etag = ?, mime_type = ?, created_at = NOW() WHERE bucket_id = ? AND object_key = ?");
                $stmt->execute([$size, $etag, $srcObject['mime_type'], $dstBucket['id'], $objectKey]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO objects (bucket_id, object_key, size, etag, mime_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$dstBucket['id'], $objectKey, $size, $etag, $srcObject['mime_type']]);
            }
            
            S3Response::xml('CopyObjectResult', [
                'ETag' => '"' . $etag . '"',
                'LastModified' => gmdate('c')
            ]);
            
        } catch (Exception $e) {
            error_log("CopyObject error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Initiate multipart upload
     */
    public function initiateMultipartUpload($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $uploadId = bin2hex(random_bytes(16));
            
            $stmt = $this->pdo->prepare("INSERT INTO multipart_uploads (upload_id, bucket, object_key, user_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$uploadId, $bucketName, $objectKey, $user['id']]);
            
            S3Response::xml('InitiateMultipartUploadResult', [
                'Bucket' => $bucketName,
                'Key' => $objectKey,
                'UploadId' => $uploadId
            ]);
            
        } catch (Exception $e) {
            error_log("InitiateMultipartUpload error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Complete multipart upload
     */
    public function completeMultipartUpload($bucketName, $objectKey, $user, $uploadId) {
        try {
            // Verify upload exists
            $stmt = $this->pdo->prepare("SELECT id FROM multipart_uploads WHERE upload_id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $user['id']]);
            
            if (!$stmt->fetch()) {
                S3Response::error(S3ErrorCodes::NO_SUCH_UPLOAD);
                return;
            }
            
            // Get parts directory
            $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : sys_get_temp_dir();
            $partsDir = $uploadPath . '/multipart/' . $uploadId;
            
            if (!is_dir($partsDir)) {
                S3Response::error(S3ErrorCodes::INTERNAL_ERROR, 'Upload parts not found');
                return;
            }
            
            // Concatenate parts
            $bucket = $this->getBucket($bucketName, $user, 'write');
            $finalPath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $finalDir = dirname($finalPath);
            
            if (!is_dir($finalDir)) {
                @mkdir($finalDir, 0755, true);
            }
            
            $final = fopen($finalPath, 'wb');
            $parts = glob($partsDir . '/*');
            sort($parts, SORT_NATURAL);
            
            foreach ($parts as $part) {
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $final);
                fclose($in);
            }
            fclose($final);
            
            $etag = md5_file($finalPath);
            $size = filesize($finalPath);
            $mimeType = getMimeType($objectKey);
            
            // Save object metadata
            $stmt = $this->pdo->prepare("
                INSERT INTO objects (bucket_id, object_key, size, etag, mime_type, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE size = VALUES(size), etag = VALUES(etag), mime_type = VALUES(mime_type), created_at = NOW()
            ");
            $stmt->execute([$bucket['id'], $objectKey, $size, $etag, $mimeType]);
            
            // Cleanup
            array_map('unlink', glob($partsDir . '/*'));
            @rmdir($partsDir);
            
            $stmt = $this->pdo->prepare("DELETE FROM multipart_uploads WHERE upload_id = ?");
            $stmt->execute([$uploadId]);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            
            S3Response::xml('CompleteMultipartUploadResult', [
                'Location' => "$protocol://$host/$bucketName/$objectKey",
                'Bucket' => $bucketName,
                'Key' => $objectKey,
                'ETag' => '"' . $etag . '"'
            ]);
            
        } catch (Exception $e) {
            error_log("CompleteMultipartUpload error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * List multipart uploads for a bucket
     */
    public function listMultipartUploads($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT upload_id, object_key, created_at FROM multipart_uploads WHERE bucket = ? AND user_id = ?");
            $stmt->execute([$bucketName, $user['id']]);
            $uploads = $stmt->fetchAll();
            
            $uploadList = [];
            foreach ($uploads as $u) {
                $uploadList[] = [
                    'Key' => $u['object_key'],
                    'UploadId' => $u['upload_id'],
                    'Initiated' => date('c', strtotime($u['created_at']))
                ];
            }
            
            S3Response::xml('ListMultipartUploadsResult', [
                'Bucket' => $bucketName,
                'Upload' => $uploadList
            ]);
            
        } catch (Exception $e) {
            error_log("ListMultipartUploads error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
}
