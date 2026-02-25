<?php
/**
 * Complete AWS S3 and MinIO Compatible API Implementation
 * Supports all major S3 operations with comprehensive logging
 */

// S3 API Response Helper Functions (renamed to avoid conflict with lib/response.php)
class S3APIResponse
{

    public static function success($data = null, $headers = [])
    {
        foreach ($headers as $key => $value) {
            header("$key: $value");
        }
        if ($data) {
            echo $data;
        }
    }

    public static function error($code, $message, $statusCode = 400, $details = [])
    {
        http_response_code($statusCode);
        header('Content-Type: application/xml');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Error>' . "\n";
        $xml .= '<Code>' . htmlspecialchars($code) . '</Code>' . "\n";
        $xml .= '<Message>' . htmlspecialchars($message) . '</Message>' . "\n";

        if (!empty($details)) {
            foreach ($details as $key => $value) {
                $xml .= '<' . htmlspecialchars($key) . '>' . htmlspecialchars($value) . '</' . htmlspecialchars($key) . '>' . "\n";
            }
        }

        $xml .= '</Error>' . "\n";
        echo $xml;

        error_log("S3 Error: $code - $message");
    }

    public static function xml($rootElement, $data)
    {
        header('Content-Type: application/xml');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= "<$rootElement>\n";
        $xml .= self::arrayToXml($data);
        $xml .= "</$rootElement>\n";
        echo $xml;
    }

    private static function arrayToXml($data, $indent = '')
    {
        $xml = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Handle numeric array indices by using a generic element name
                if (is_numeric($key)) {
                    $xml .= "$indent<Contents>\n";
                    $xml .= self::arrayToXml($value, $indent . '  ');
                    $xml .= "$indent</Contents>\n";
                } else {
                    $xml .= "$indent<$key>\n";
                    $xml .= self::arrayToXml($value, $indent . '  ');
                    $xml .= "$indent</$key>\n";
                }
            } else {
                $xml .= "$indent<$key>" . htmlspecialchars($value) . "</$key>\n";
            }
        }
        return $xml;
    }
}

// S3 API Operations Handler
class S3API
{

    private $storage;
    private $pdo;

    public function __construct()
    {
        $this->storage = new ObjectStorage();
        $this->pdo = getDB();
    }

    // ============================================================================
    // BUCKET OPERATIONS
    // ============================================================================

    public function createBucket($bucketName, $user)
    {
        error_log("S3 API: CreateBucket - bucket='$bucketName', user=" . $user['id']);

        try {
            // Check if bucket already exists
            $stmt = $this->pdo->prepare("SELECT id FROM buckets WHERE name = ?");
            $stmt->execute([$bucketName]);
            if ($stmt->fetch()) {
                S3APIResponse::error('BucketAlreadyExists', 'The requested bucket name is not available', 409);
                return;
            }

            // Create bucket directory
            $bucketPath = STORAGE_PATH . $bucketName;
            if (!is_dir($bucketPath)) {
                if (!mkdir($bucketPath, 0755, true)) {
                    S3APIResponse::error('InternalError', 'Failed to create bucket directory', 500);
                    return;
                }
            }

            // Add to database (use user_id as owner reference)
            $stmt = $this->pdo->prepare("INSERT INTO buckets (name, user_id) VALUES (?, ?)");
            $stmt->execute([$bucketName, $user['id']]);
            $newBucketId = $this->pdo->lastInsertId();

            // Add permission for owner (upsert)
            $stmt = $this->pdo->prepare("INSERT INTO permissions (user_id, bucket_id, permission) VALUES (?, ?, 'admin') ON DUPLICATE KEY UPDATE permission = VALUES(permission)");
            $stmt->execute([$user['id'], $newBucketId]);

            http_response_code(200);
            error_log("S3 API: CreateBucket - Success for bucket='$bucketName'");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to create bucket: ' . $e->getMessage(), 500);
        }
    }

    public function deleteBucket($bucketName, $user)
    {
        error_log("S3 API: DeleteBucket - bucket='$bucketName', user=" . $user['id']);

        try {
            // Check if bucket exists and user has permission
            $stmt = $this->pdo->prepare("
                SELECT b.id FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ? AND p.permission = 'admin'
            ");
            $stmt->execute([$bucketName, $user['id']]);
            $bucket = $stmt->fetch();

            if (!$bucket) {
                S3APIResponse::error('NoSuchBucket', 'The specified bucket does not exist', 404);
                return;
            }

            // Check if bucket is empty
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM objects WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            if ($stmt->fetchColumn() > 0) {
                S3APIResponse::error('BucketNotEmpty', 'The bucket you tried to delete is not empty', 409);
                return;
            }

            // Delete bucket directory
            $bucketPath = STORAGE_PATH . $bucketName;
            if (is_dir($bucketPath)) {
                rmdir($bucketPath);
            }

            // Remove permissions first (foreign key constraint)
            $stmt = $this->pdo->prepare("DELETE FROM permissions WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);

            // Remove from database
            $stmt = $this->pdo->prepare("DELETE FROM buckets WHERE id = ?");
            $stmt->execute([$bucket['id']]);

            http_response_code(204);
            error_log("S3 API: DeleteBucket - Success for bucket='$bucketName'");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to delete bucket: ' . $e->getMessage(), 500);
        }
    }

    public function listBuckets($user)
    {
        error_log("S3 API: ListBuckets - user=" . $user['id']);

        try {
            $stmt = $this->pdo->prepare("
                SELECT b.name, b.created_at FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE p.user_id = ? 
                ORDER BY b.name
            ");
            $stmt->execute([$user['id']]);
            $buckets = $stmt->fetchAll();

            $data = [
                'Owner' => [
                    'ID' => 'owner-' . $user['id'],
                    'DisplayName' => $user['username']
                ],
                'Buckets' => []
            ];

            foreach ($buckets as $bucket) {
                $data['Buckets'][] = [
                    'Name' => $bucket['name'],
                    'CreationDate' => date('c', strtotime($bucket['created_at']))
                ];
            }

            S3APIResponse::xml('ListAllMyBucketsResult', $data);
            error_log("S3 API: ListBuckets - Success, found " . count($buckets) . " buckets");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to list buckets: ' . $e->getMessage(), 500);
        }
    }

    public function getBucketLocation($bucketName, $user)
    {
        error_log("S3 API: GetBucketLocation - bucket='$bucketName', user=" . $user['id']);

        try {
            // Check if bucket exists and user has permission
            $stmt = $this->pdo->prepare("
                SELECT b.id FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ?
            ");
            $stmt->execute([$bucketName, $user['id']]);

            if (!$stmt->fetch()) {
                S3APIResponse::error('NoSuchBucket', 'The specified bucket does not exist', 404);
                return;
            }

            S3APIResponse::xml('LocationConstraint', ['LocationConstraint' => 'us-east-1']);
            error_log("S3 API: GetBucketLocation - Success for bucket='$bucketName'");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to get bucket location: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================================
    // OBJECT OPERATIONS
    // ============================================================================

    public function putObject($bucketName, $objectKey, $user, $content = null, $headers = [])
    {
        error_log("S3 API: PutObject - bucket='$bucketName', key='$objectKey', user=" . $user['id']);

        try {
            $result = $this->storage->putObject($bucketName, $objectKey, $user['id']);

            if ($result['success']) {
                http_response_code(200);
                header('ETag: "' . $result['etag'] . '"');
                header('Content-Length: 0');

                // Add custom headers if provided
                foreach ($headers as $key => $value) {
                    header("$key: $value");
                }

                error_log("S3 API: PutObject - Success, ETag=" . $result['etag']);
            } else {
                S3APIResponse::error('InternalError', $result['error'], 500);
            }

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to put object: ' . $e->getMessage(), 500);
        }
    }

    public function getObject($bucketName, $objectKey, $user)
    {
        error_log("S3 API: GetObject - bucket='$bucketName', key='$objectKey', user=" . $user['id']);

        try {
            $result = $this->storage->getObject($bucketName, $objectKey);

            if ($result['success']) {
                header('Content-Type: ' . $result['mime_type']);
                header('Content-Length: ' . $result['size']);
                header('ETag: "' . $result['etag'] . '"');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', strtotime($result['created_at'])));

                // Stream file in chunks for large file support (5GB+)
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $handle = fopen($result['file_path'], 'rb');
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
                error_log("S3 API: GetObject - Success, size=" . $result['size']);
            } else {
                S3APIResponse::error('NoSuchKey', 'The specified key does not exist', 404);
            }

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to get object: ' . $e->getMessage(), 500);
        }
    }

    public function deleteObject($bucketName, $objectKey, $user)
    {
        error_log("S3 API: DeleteObject - bucket='$bucketName', key='$objectKey', user=" . $user['id']);

        try {
            $result = $this->storage->deleteObject($bucketName, $objectKey);

            if ($result['success']) {
                http_response_code(204);
                header('Content-Length: 0');
                error_log("S3 API: DeleteObject - Success");
            } else {
                S3APIResponse::error('InternalError', $result['error'], 500);
            }

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to delete object: ' . $e->getMessage(), 500);
        }
    }

    public function headObject($bucketName, $objectKey, $user)
    {
        error_log("S3 API: HeadObject - bucket='$bucketName', key='$objectKey', user=" . $user['id']);

        try {
            $result = $this->storage->getObject($bucketName, $objectKey);

            if ($result['success']) {
                header('Content-Type: ' . $result['mime_type']);
                header('Content-Length: ' . $result['size']);
                header('ETag: "' . $result['etag'] . '"');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', strtotime($result['created_at'])));
                http_response_code(200);
                error_log("S3 API: HeadObject - Success, size=" . $result['size']);
            } else {
                http_response_code(404);
                error_log("S3 API: HeadObject - Object not found");
            }

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to head object: ' . $e->getMessage(), 500);
        }
    }

    public function copyObject($bucketName, $objectKey, $user, $sourceBucket, $sourceKey)
    {
        error_log("S3 API: CopyObject - bucket='$bucketName', key='$objectKey', source='$sourceBucket/$sourceKey', user=" . $user['id']);

        try {
            // Get source object
            $sourceResult = $this->storage->getObject($sourceBucket, $sourceKey);
            if (!$sourceResult['success']) {
                S3APIResponse::error('NoSuchKey', 'The specified source key does not exist', 404);
                return;
            }

            // Copy to destination
            $destPath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (copy($sourceResult['file_path'], $destPath)) {
                $etag = md5_file($destPath);

                // Update database (MySQL upsert)
                $stmt = $this->pdo->prepare("
                    INSERT INTO objects (bucket_id, object_key, size, mime_type, etag, created_at)
                    SELECT b.id, ?, ?, ?, ?, NOW() FROM buckets b WHERE b.name = ?
                    ON DUPLICATE KEY UPDATE 
                        size = VALUES(size),
                        mime_type = VALUES(mime_type),
                        etag = VALUES(etag),
                        created_at = VALUES(created_at)
                ");
                $stmt->execute([$objectKey, $sourceResult['size'], $sourceResult['mime_type'], $etag, $bucketName]);

                http_response_code(200);
                header('ETag: "' . $etag . '"');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));

                S3APIResponse::xml('CopyObjectResult', [
                    'ETag' => '"' . $etag . '"',
                    'LastModified' => gmdate('c')
                ]);

                error_log("S3 API: CopyObject - Success, ETag=$etag");
            } else {
                S3APIResponse::error('InternalError', 'Failed to copy object', 500);
            }

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to copy object: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================================
    // LIST OPERATIONS
    // ============================================================================

    public function listObjects($bucketName, $user, $params = [])
    {
        error_log("S3 API: ListObjects - bucket='$bucketName', user=" . $user['id'] . ", params=" . json_encode($params));

        try {
            // Check bucket permission
            $stmt = $this->pdo->prepare("
                SELECT b.id FROM buckets b 
                JOIN permissions p ON b.id = p.bucket_id 
                WHERE b.name = ? AND p.user_id = ?
            ");
            $stmt->execute([$bucketName, $user['id']]);
            $bucket = $stmt->fetch();

            if (!$bucket) {
                S3APIResponse::error('NoSuchBucket', 'The specified bucket does not exist', 404);
                return;
            }

            // Build query
            $sql = "SELECT object_key, size, created_at, etag FROM objects WHERE bucket_id = ?";
            $params_sql = [$bucket['id']];

            $prefix = isset($params['prefix']) ? rawurldecode($params['prefix']) : '';
            $delimiter = $params['delimiter'] ?? '';
            $maxKeys = min((int) ($params['max-keys'] ?? 1000), 1000);
            $marker = isset($params['marker']) ? rawurldecode($params['marker']) : '';
            $listType = (int) ($params['list-type'] ?? 1);
            $continuationToken = $params['continuation-token'] ?? null;

            if ($prefix) {
                $sql .= " AND object_key LIKE ?";
                $params_sql[] = $prefix . '%';
            }

            if ($marker) {
                $sql .= " AND object_key > ?";
                $params_sql[] = $marker;
            }
            if ($continuationToken) {
                $sql .= " AND object_key > ?";
                $params_sql[] = $continuationToken;
            }

            $sql .= " ORDER BY object_key LIMIT " . (int) ($maxKeys + 1);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params_sql);
            $objects = $stmt->fetchAll();

            // Repair zero-size metadata by reading filesystem size
            foreach ($objects as &$obj) {
                if ((int) $obj['size'] === 0) {
                    $filePath = STORAGE_PATH . $bucketName . '/' . $obj['object_key'];
                    if (file_exists($filePath)) {
                        $actualSize = filesize($filePath);
                        $actualEtag = md5_file($filePath);
                        if ($actualSize > 0) {
                            $upd = $this->pdo->prepare("UPDATE objects SET size = ?, etag = ? WHERE bucket_id = ? AND object_key = ?");
                            $upd->execute([$actualSize, $actualEtag, $bucket['id'], $obj['object_key']]);
                            $obj['size'] = $actualSize;
                            $obj['etag'] = $actualEtag;
                        }
                    }
                }
            }

            $isTruncated = count($objects) > $maxKeys;
            if ($isTruncated) {
                array_pop($objects);
            }

            $data = [
                'Name' => $bucketName,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
                'IsTruncated' => $isTruncated ? 'true' : 'false'
            ];
            if ($listType === 2) {
                if ($isTruncated && !empty($objects)) {
                    $data['NextContinuationToken'] = end($objects)['object_key'];
                }
            } else {
                $data['Marker'] = $marker;
            }

            // Add each object as a separate Contents element
            foreach ($objects as $obj) {
                $data[] = [
                    'Key' => $obj['object_key'],
                    'LastModified' => date('c', strtotime($obj['created_at'])),
                    'ETag' => '"' . $obj['etag'] . '"',
                    'Size' => $obj['size'],
                    'StorageClass' => 'STANDARD',
                    'Owner' => [
                        'ID' => 'owner-' . $user['id'],
                        'DisplayName' => $user['username']
                    ]
                ];
            }

            if ($listType === 2) {
                S3APIResponse::xml('ListBucketResult', $data);
            } else {
                S3APIResponse::xml('ListBucketResult', $data);
            }
            error_log("S3 API: ListObjects - Success, found " . count($objects) . " objects");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to list objects: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================================
    // MULTIPART UPLOAD OPERATIONS
    // ============================================================================

    public function createMultipartUpload($bucketName, $objectKey, $user)
    {
        error_log("S3 API: CreateMultipartUpload - bucket='$bucketName', key='$objectKey', user=" . $user['id']);

        try {
            $uploadId = bin2hex(random_bytes(16));

            // Store upload info
            $stmt = $this->pdo->prepare("INSERT INTO multipart_uploads (upload_id, bucket, object_key, user_id, created_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$uploadId, $bucketName, $objectKey, $user['id'], date('Y-m-d H:i:s')]);

            S3APIResponse::xml('InitiateMultipartUploadResult', [
                'Bucket' => $bucketName,
                'Key' => $objectKey,
                'UploadId' => $uploadId
            ]);

            error_log("S3 API: CreateMultipartUpload - Success, UploadId=$uploadId");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to create multipart upload: ' . $e->getMessage(), 500);
        }
    }

    public function uploadPart($bucketName, $objectKey, $user, $uploadId, $partNumber)
    {
        error_log("S3 API: UploadPart - bucket='$bucketName', key='$objectKey', uploadId='$uploadId', partNumber='$partNumber', user=" . $user['id']);

        try {
            // Verify upload exists
            $stmt = $this->pdo->prepare("SELECT id FROM multipart_uploads WHERE upload_id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $user['id']]);
            if (!$stmt->fetch()) {
                S3APIResponse::error('NoSuchUpload', 'The specified multipart upload does not exist', 404);
                return;
            }

            // Store raw part to disk: /uploads/multipart/<uploadId>/<partNumber>
            $partsDir = UPLOAD_PATH . 'multipart/' . $uploadId;
            if (!is_dir($partsDir)) {
                if (!@mkdir($partsDir, 0755, true) && !is_dir($partsDir)) {
                    S3APIResponse::error('InternalError', 'Failed to create upload parts directory', 500);
                    return;
                }
            }
            $partPath = $partsDir . '/' . sprintf('%06d', (int) $partNumber);
            $input = fopen('php://input', 'rb');
            $output = fopen($partPath, 'wb');
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            $etag = md5_file($partPath);
            http_response_code(200);
            header('ETag: "' . $etag . '"');
            error_log("S3 API: UploadPart - Stored part $partNumber for uploadId=$uploadId");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to upload part: ' . $e->getMessage(), 500);
        }
    }

    public function completeMultipartUpload($bucketName, $objectKey, $user, $uploadId)
    {
        error_log("S3 API: CompleteMultipartUpload - bucket='$bucketName', key='$objectKey', uploadId='$uploadId', user=" . $user['id']);

        try {
            // Concatenate parts in order to final object path
            $partsDir = UPLOAD_PATH . 'multipart/' . $uploadId;
            $finalPath = STORAGE_PATH . $bucketName . '/' . $objectKey;
            $finalDir = dirname($finalPath);
            if (!is_dir($finalDir)) {
                mkdir($finalDir, 0755, true);
            }
            $final = fopen($finalPath, 'wb');
            $parts = glob($partsDir . '/*');
            sort($parts);
            foreach ($parts as $p) {
                $in = fopen($p, 'rb');
                stream_copy_to_stream($in, $final);
                fclose($in);
            }
            fclose($final);
            $etag = md5_file($finalPath);
            // Save metadata (upsert into objects)
            $mimeType = function_exists('mime_content_type') ? (mime_content_type($finalPath) ?: 'application/octet-stream') : 'application/octet-stream';
            $size = filesize($finalPath);
            $stmt = $this->pdo->prepare("
                INSERT INTO objects (bucket_id, object_key, size, mime_type, etag, created_at)
                SELECT b.id, ?, ?, ?, ?, NOW() FROM buckets b WHERE b.name = ?
                ON DUPLICATE KEY UPDATE
                    size = VALUES(size),
                    mime_type = VALUES(mime_type),
                    etag = VALUES(etag),
                    created_at = VALUES(created_at)
            ");
            $stmt->execute([$objectKey, $size, $mimeType, $etag, $bucketName]);
            // Clean up upload record and parts
            $stmt = $this->pdo->prepare("DELETE FROM multipart_uploads WHERE upload_id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $user['id']]);
            array_map('unlink', glob($partsDir . '/*'));
            @rmdir($partsDir);
            $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $protocol = $is_https ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            S3APIResponse::xml('CompleteMultipartUploadResult', [
                'Location' => "$protocol://$host/$bucketName/$objectKey",
                'Bucket' => $bucketName,
                'Key' => $objectKey,
                'ETag' => '"' . $etag . '"'
            ]);
            error_log("S3 API: CompleteMultipartUpload - Concatenated parts, ETag=$etag");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to complete multipart upload: ' . $e->getMessage(), 500);
        }
    }

    public function abortMultipartUpload($bucketName, $objectKey, $user, $uploadId)
    {
        error_log("S3 API: AbortMultipartUpload - bucket='$bucketName', key='$objectKey', uploadId='$uploadId', user=" . $user['id']);

        try {
            // Remove upload record
            $stmt = $this->pdo->prepare("DELETE FROM multipart_uploads WHERE upload_id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $user['id']]);

            http_response_code(204);
            error_log("S3 API: AbortMultipartUpload - Success, UploadId=$uploadId");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to abort multipart upload: ' . $e->getMessage(), 500);
        }
    }

    public function listMultipartUploads($bucketName, $user, $params = [])
    {
        error_log("S3 API: ListMultipartUploads - bucket='$bucketName', user=" . $user['id']);

        try {
            $stmt = $this->pdo->prepare("SELECT upload_id, object_key, created_at FROM multipart_uploads WHERE bucket = ? AND user_id = ?");
            $stmt->execute([$bucketName, $user['id']]);
            $uploads = $stmt->fetchAll();

            $data = [
                'Bucket' => $bucketName,
                'Uploads' => []
            ];

            foreach ($uploads as $upload) {
                $data['Uploads'][] = [
                    'Key' => $upload['object_key'],
                    'UploadId' => $upload['upload_id'],
                    'Initiated' => date('c', strtotime($upload['created_at']))
                ];
            }

            S3APIResponse::xml('ListMultipartUploadsResult', $data);
            error_log("S3 API: ListMultipartUploads - Success, found " . count($uploads) . " uploads");

        } catch (Exception $e) {
            S3APIResponse::error('InternalError', 'Failed to list multipart uploads: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================================
    // UTILITY OPERATIONS
    // ============================================================================

    public function handleOptions()
    {
        error_log("S3 API: OPTIONS request");

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, x-amz-date, x-amz-content-sha256, x-amz-user-agent, x-amz-target');
        header('Access-Control-Max-Age: 86400');

        http_response_code(200);
        error_log("S3 API: OPTIONS - Success");
    }
}
?>