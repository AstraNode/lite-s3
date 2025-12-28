<?php
/**
 * Object Storage System
 * Handles file operations and metadata management
 */

class ObjectStorage {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    public function putObject($bucket, $objectKey, $userId) {
        try {
            error_log("Storage: PUT Object - bucket='$bucket', key='$objectKey', user=$userId");
            
            // Ensure bucket exists
            $this->ensureBucketExists($bucket, $userId);
            
            // Get bucket ID
            $stmt = $this->pdo->prepare("SELECT id FROM buckets WHERE name = ?");
            $stmt->execute([$bucket]);
            $bucketData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bucketData) {
                return ['success' => false, 'error' => 'Bucket not found'];
            }
            
            $bucketId = $bucketData['id'];
            
            // Handle file upload
            if (!isset($_FILES['file']) && !isset($_POST['data'])) {
                // Stream raw PUT to temp file to support large uploads
                error_log("Storage: Handling raw PUT data for object '$objectKey'");
                $input = fopen('php://input', 'rb');
                $tempFile = tempnam(UPLOAD_PATH, 'upload_');
                $output = fopen($tempFile, 'wb');
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                $filePath = $tempFile;
                $fileSize = filesize($filePath);
                $mimeType = $this->getMimeType($filePath, $objectKey);
            } else {
                // Handle form upload
                if (isset($_FILES['file'])) {
                    $file = $_FILES['file'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        return ['success' => false, 'error' => 'Upload failed'];
                    }
                    $filePath = $file['tmp_name'];
                    $fileSize = $file['size'];
                    $mimeType = $file['type'];
                } else {
                    // Handle base64 data
                    $data = $_POST['data'];
                    $filePath = tempnam(UPLOAD_PATH, 'upload_');
                    file_put_contents($filePath, base64_decode($data));
                    $fileSize = filesize($filePath);
                    $mimeType = $this->getMimeType($filePath, $objectKey);
                }
            }
            
            // Check file size
            if ($fileSize > MAX_FILE_SIZE) {
                unlink($filePath);
                return ['success' => false, 'error' => 'File too large'];
            }
            
            // Create storage path
            $storagePath = STORAGE_PATH . $bucket . '/' . $objectKey;
            $storageDir = dirname($storagePath);
            
            error_log("Storage: Creating path '$storagePath' in directory '$storageDir'");
            
            // Ensure the directory exists
            if (!is_dir($storageDir)) {
                error_log("Storage: Creating directory '$storageDir'");
                if (!mkdir($storageDir, 0755, true)) {
                    error_log("Storage: Failed to create directory: " . $storageDir);
                    return ['success' => false, 'error' => 'Failed to create directory: ' . $storageDir];
                }
                error_log("Storage: Directory created successfully");
            }
            
            // Security scan before moving file
            if (function_exists('scanUploadedFile') && !scanUploadedFile($filePath, $objectKey)) {
                error_log("Storage: Security scan failed for file: $objectKey");
                unlink($filePath); // Remove the uploaded file
                return ['success' => false, 'error' => 'File rejected by security scan'];
            }
            
            // Ensure directory exists
            if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true)) {
                error_log("Storage: Failed to create directory: " . $storageDir);
                return ['success' => false, 'error' => 'Failed to create directory'];
            }
            
            // Atomic replace: write to temp in same dir, then rename over destination
            $tempDest = $storageDir . '/.' . uniqid('tmp_', true);
            $moved = false;
            if (is_uploaded_file($filePath)) {
                $moved = @move_uploaded_file($filePath, $tempDest);
            } else {
                $moved = @rename($filePath, $tempDest);
                if (!$moved) {
                    // fallback copy
                    $moved = @copy($filePath, $tempDest);
                    @unlink($filePath);
                }
            }
            if (!$moved) {
                error_log("Storage: Failed to stage temp file to $tempDest");
                return ['success' => false, 'error' => 'Failed to stage file'];
            }
            // Validate content length if provided
            $expectedLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;
            if ($expectedLen !== null && $expectedLen >= 0) {
                $actualLen = filesize($tempDest);
                if ($actualLen !== $expectedLen) {
                    @unlink($tempDest);
                    error_log("Storage: Length mismatch. expected=$expectedLen actual=$actualLen for $storagePath");
                    return ['success' => false, 'error' => 'Content-Length mismatch'];
                }
            }
            // Finalize replace
            if (file_exists($storagePath)) {
                @unlink($storagePath);
            }
            if (!@rename($tempDest, $storagePath)) {
                @unlink($tempDest);
                error_log("Storage: Failed to move staged file into place $storagePath");
                return ['success' => false, 'error' => 'Failed to save file'];
            }
            @chmod($storagePath, 0644);
            error_log("Storage: File moved successfully");
            
            // Calculate ETag
            $etag = md5_file($storagePath);
            error_log("Storage: Calculated ETag: $etag");
            
            // Save metadata (MySQL upsert)
            $stmt = $this->pdo->prepare("
                INSERT INTO objects (bucket_id, object_key, size, mime_type, etag, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    size = VALUES(size),
                    mime_type = VALUES(mime_type),
                    etag = VALUES(etag),
                    created_at = VALUES(created_at)
            ");
            $stmt->execute([$bucketId, $objectKey, $fileSize, $mimeType, $etag]);
            
            return [
                'success' => true,
                'etag' => $etag,
                'size' => $fileSize,
                'mime_type' => $mimeType
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getObject($bucket, $objectKey) {
        try {
            $stmt = $this->pdo->prepare("SELECT o.*, b.name as bucket_name 
                                       FROM objects o 
                                       JOIN buckets b ON o.bucket_id = b.id 
                                       WHERE b.name = ? AND o.object_key = ?");
            $stmt->execute([$bucket, $objectKey]);
            $object = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$object) {
                return ['success' => false, 'error' => 'Object not found'];
            }
            
            $filePath = STORAGE_PATH . $bucket . '/' . $objectKey;
            
            if (!file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found on disk'];
            }
            
            return [
                'success' => true,
                'file_path' => $filePath,
                'size' => $object['size'],
                'mime_type' => $object['mime_type'],
                'etag' => $object['etag'],
                'created_at' => $object['created_at']
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function deleteObject($bucket, $objectKey) {
        try {
            $stmt = $this->pdo->prepare("SELECT o.id, o.object_key 
                                       FROM objects o 
                                       JOIN buckets b ON o.bucket_id = b.id 
                                       WHERE b.name = ? AND o.object_key = ?");
            $stmt->execute([$bucket, $objectKey]);
            $object = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$object) {
                return ['success' => false, 'error' => 'Object not found'];
            }
            
            // Delete file
            $filePath = STORAGE_PATH . $bucket . '/' . $objectKey;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete metadata
            $stmt = $this->pdo->prepare("DELETE FROM objects WHERE id = ?");
            $stmt->execute([$object['id']]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function listObjects($bucket, $prefix = '', $maxKeys = 1000) {
        try {
            $stmt = $this->pdo->prepare("SELECT o.*, b.name as bucket_name 
                                       FROM objects o 
                                       JOIN buckets b ON o.bucket_id = b.id 
                                       WHERE b.name = ? AND o.object_key LIKE ? 
                                       ORDER BY o.object_key 
                                       LIMIT ?");
            $stmt->execute([$bucket, $prefix . '%', $maxKeys]);
            $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $result = [];
            foreach ($objects as $object) {
                $result[] = [
                    'key' => $object['object_key'],
                    'size' => (int)$object['size'],
                    'last_modified' => $object['created_at'],
                    'etag' => $object['etag'],
                    'mime_type' => $object['mime_type']
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function ensureBucketExists($bucketName, $userId) {
        $stmt = $this->pdo->prepare("SELECT id FROM buckets WHERE name = ?");
        $stmt->execute([$bucketName]);
        
        if (!$stmt->fetch()) {
            createBucket($bucketName, $userId);
        }
    }
    
    private function getMimeType($filePath, $objectKey) {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime) {
                return $mime;
            }
        }
        
        // Fallback to extension-based detection
        $extension = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg'
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
?>
