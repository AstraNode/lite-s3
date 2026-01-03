<?php
/**
 * Object Storage System
 * Handles file operations and metadata management
 * Enhanced with security scanning and S3-compatible error handling
 */

require_once __DIR__ . '/lib/s3-errors.php';

class ObjectStorage {
    private $pdo;
    
    // Dangerous file extensions that should be blocked
    private static $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
        'jsp', 'asp', 'aspx', 'exe', 'bat', 'cmd', 'sh', 'ps1', 'cgi',
        'pl', 'py', 'rb', 'htaccess', 'htpasswd'
    ];
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    /**
     * Validate object key for security issues
     */
    private function validateObjectKey($objectKey) {
        // Check for path traversal attempts
        if (strpos($objectKey, '..') !== false) {
            return ['valid' => false, 'error' => 'InvalidArgument', 'message' => 'Object key contains invalid path traversal'];
        }
        
        // Check for null bytes
        if (strpos($objectKey, "\0") !== false) {
            return ['valid' => false, 'error' => 'InvalidArgument', 'message' => 'Object key contains null bytes'];
        }
        
        // Check key length (S3 limit is 1024 bytes)
        if (strlen($objectKey) > 1024) {
            return ['valid' => false, 'error' => 'KeyTooLongError', 'message' => 'Object key exceeds maximum length of 1024 bytes'];
        }
        
        // Check for dangerous extensions if scanning is enabled
        if (defined('SCAN_UPLOADS') && SCAN_UPLOADS) {
            $extension = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
            if (in_array($extension, self::$dangerousExtensions)) {
                return ['valid' => false, 'error' => 'InvalidArgument', 'message' => 'File type not allowed for security reasons'];
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * Scan file content for security threats
     */
    private function scanFileContent($filePath, $objectKey) {
        if (!defined('SCAN_UPLOADS') || !SCAN_UPLOADS) {
            return true;
        }
        
        // Read first 8KB to check for PHP code
        $content = file_get_contents($filePath, false, null, 0, 8192);
        
        // Check for PHP tags
        $phpPatterns = ['<?php', '<?=', '<script language="php"', '<%'];
        foreach ($phpPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                error_log("Security: PHP code detected in upload: $objectKey");
                return false;
            }
        }
        
        // Check MIME type if restrictions are set
        if (defined('ALLOWED_FILE_TYPES') && ALLOWED_FILE_TYPES !== ['*']) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($filePath);
            
            if (!in_array($mimeType, ALLOWED_FILE_TYPES)) {
                error_log("Security: Blocked MIME type '$mimeType' for: $objectKey");
                return false;
            }
        }
        
        return true;
    }
    
    public function putObject($bucket, $objectKey, $userId) {
        try {
            error_log("Storage: PUT Object - bucket='$bucket', key='$objectKey', user=$userId");
            
            // Validate object key
            $validation = $this->validateObjectKey($objectKey);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['message'], 'code' => $validation['error']];
            }
            
            // Ensure bucket exists
            $this->ensureBucketExists($bucket, $userId);
            
            // Get bucket ID
            $stmt = $this->pdo->prepare("SELECT id FROM buckets WHERE name = ?");
            $stmt->execute([$bucket]);
            $bucketData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bucketData) {
                return ['success' => false, 'error' => 'Bucket not found', 'code' => 'NoSuchBucket'];
            }
            
            $bucketId = $bucketData['id'];
            
            // Handle file upload
            if (!isset($_FILES['file']) && !isset($_POST['data'])) {
                // Stream raw PUT to temp file to support large uploads
                error_log("Storage: Handling raw PUT data for object '$objectKey'");
                $input = fopen('php://input', 'rb');
                $tempFile = tempnam(defined('UPLOAD_PATH') ? UPLOAD_PATH : sys_get_temp_dir(), 'upload_');
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
                        return ['success' => false, 'error' => 'Upload failed: ' . $this->getUploadErrorMessage($file['error'])];
                    }
                    $filePath = $file['tmp_name'];
                    $fileSize = $file['size'];
                    $mimeType = $file['type'];
                } else {
                    // Handle base64 data
                    $data = $_POST['data'];
                    $tempDir = defined('UPLOAD_PATH') ? UPLOAD_PATH : sys_get_temp_dir();
                    $filePath = tempnam($tempDir, 'upload_');
                    file_put_contents($filePath, base64_decode($data));
                    $fileSize = filesize($filePath);
                    $mimeType = $this->getMimeType($filePath, $objectKey);
                }
            }
            
            // Security scan the uploaded file
            if (!$this->scanFileContent($filePath, $objectKey)) {
                @unlink($filePath);
                return ['success' => false, 'error' => 'File rejected by security scan', 'code' => 'InvalidArgument'];
            }
            
            // Check file size
            $maxSize = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : PHP_INT_MAX;
            if ($fileSize > $maxSize) {
                @unlink($filePath);
                return ['success' => false, 'error' => 'File too large', 'code' => 'EntityTooLarge'];
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
    
    /**
     * Get human-readable upload error message
     */
    private function getUploadErrorMessage($errorCode) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
        ];
        
        return $errors[$errorCode] ?? 'Unknown upload error';
    }
    
    private function getMimeType($filePath, $objectKey) {
        // First try finfo for accurate detection
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($filePath);
            if ($mime && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }
        
        // Fallback to mime_content_type
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }
        
        // Fallback to extension-based detection
        $extension = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            'yaml' => 'text/yaml',
            'yml' => 'text/yaml',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
?>
