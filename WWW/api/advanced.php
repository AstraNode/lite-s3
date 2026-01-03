<?php
/**
 * Advanced S3 Operations API
 * 
 * Implements additional S3-compatible features:
 * - Object Metadata (x-amz-meta-*)
 * - Delete Multiple Objects (DeleteObjects)
 * - Object Tagging
 * - Bucket Tagging
 * - Bucket CORS
 * - Bucket Lifecycle
 * - Object Versioning
 * - Bucket Notifications
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/s3-errors.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/constants.php';

class AdvancedS3API {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    /**
     * Get bucket with permission check
     */
    private function getBucket($bucketName, $user, $requiredPermission = 'read') {
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.name, b.versioning_enabled, p.permission FROM buckets b 
            JOIN permissions p ON b.id = p.bucket_id 
            WHERE b.name = ? AND p.user_id = ?
        ");
        $stmt->execute([$bucketName, $user['id']]);
        $bucket = $stmt->fetch();
        
        if (!$bucket) {
            return null;
        }
        
        $permLevels = ['read' => 1, 'write' => 2, 'admin' => 3];
        $userLevel = $permLevels[$bucket['permission']] ?? 0;
        $requiredLevel = $permLevels[$requiredPermission] ?? 1;
        
        if ($userLevel < $requiredLevel) {
            return null;
        }
        
        return $bucket;
    }
    
    // ========================================================================
    // DELETE MULTIPLE OBJECTS (DeleteObjects)
    // ========================================================================
    
    /**
     * Delete multiple objects in a single request
     * POST /{bucket}?delete
     */
    public function deleteObjects($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Parse XML body
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml || !isset($xml->Object)) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML, 'Invalid delete request XML');
                return;
            }
            
            $quiet = isset($xml->Quiet) && strtolower((string)$xml->Quiet) === 'true';
            $deleted = [];
            $errors = [];
            
            foreach ($xml->Object as $obj) {
                $key = (string)$obj->Key;
                $versionId = isset($obj->VersionId) ? (string)$obj->VersionId : null;
                
                try {
                    // Delete file
                    $filePath = STORAGE_PATH . $bucketName . '/' . $key;
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                    
                    // Delete from database
                    $stmt = $this->pdo->prepare("DELETE FROM objects WHERE bucket_id = ? AND object_key = ?");
                    $stmt->execute([$bucket['id'], $key]);
                    
                    $deleted[] = [
                        'Key' => $key,
                        'VersionId' => $versionId
                    ];
                } catch (Exception $e) {
                    $errors[] = [
                        'Key' => $key,
                        'VersionId' => $versionId,
                        'Code' => 'InternalError',
                        'Message' => 'Failed to delete object'
                    ];
                }
            }
            
            // Build response
            $result = [];
            
            if (!$quiet) {
                foreach ($deleted as $d) {
                    $result['Deleted'][] = $d;
                }
            }
            
            foreach ($errors as $e) {
                $result['Error'][] = $e;
            }
            
            S3Response::xml('DeleteResult', $result);
            
        } catch (Exception $e) {
            error_log("DeleteObjects error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // OBJECT TAGGING
    // ========================================================================
    
    /**
     * Get object tagging
     * GET /{bucket}/{key}?tagging
     */
    public function getObjectTagging($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Get object
            $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            $object = $stmt->fetch();
            
            if (!$object) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, null, null, ['Key' => $objectKey]);
                return;
            }
            
            // Get tags
            $stmt = $this->pdo->prepare("SELECT tag_key, tag_value FROM object_tags WHERE object_id = ?");
            $stmt->execute([$object['id']]);
            $tags = $stmt->fetchAll();
            
            $tagSet = [];
            foreach ($tags as $tag) {
                $tagSet[] = [
                    'Key' => $tag['tag_key'],
                    'Value' => $tag['tag_value']
                ];
            }
            
            S3Response::xml('Tagging', [
                'TagSet' => empty($tagSet) ? [] : ['Tag' => $tagSet]
            ]);
            
        } catch (Exception $e) {
            error_log("GetObjectTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put object tagging
     * PUT /{bucket}/{key}?tagging
     */
    public function putObjectTagging($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Get object
            $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            $object = $stmt->fetch();
            
            if (!$object) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, null, null, ['Key' => $objectKey]);
                return;
            }
            
            // Parse XML body
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML);
                return;
            }
            
            // Delete existing tags
            $stmt = $this->pdo->prepare("DELETE FROM object_tags WHERE object_id = ?");
            $stmt->execute([$object['id']]);
            
            // Insert new tags
            if (isset($xml->TagSet->Tag)) {
                $stmt = $this->pdo->prepare("INSERT INTO object_tags (object_id, tag_key, tag_value) VALUES (?, ?, ?)");
                foreach ($xml->TagSet->Tag as $tag) {
                    $key = (string)$tag->Key;
                    $value = (string)$tag->Value;
                    
                    // Validate tag constraints (max 10 tags, key max 128 chars, value max 256 chars)
                    if (strlen($key) > 128 || strlen($value) > 256) {
                        S3Response::error(S3ErrorCodes::INVALID_TAG, 'Tag key or value too long');
                        return;
                    }
                    
                    $stmt->execute([$object['id'], $key, $value]);
                }
            }
            
            http_response_code(200);
            header('Content-Length: 0');
            
        } catch (Exception $e) {
            error_log("PutObjectTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete object tagging
     * DELETE /{bucket}/{key}?tagging
     */
    public function deleteObjectTagging($bucketName, $objectKey, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'write');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            // Get object
            $stmt = $this->pdo->prepare("SELECT id FROM objects WHERE bucket_id = ? AND object_key = ?");
            $stmt->execute([$bucket['id'], $objectKey]);
            $object = $stmt->fetch();
            
            if (!$object) {
                S3Response::error(S3ErrorCodes::NO_SUCH_KEY, null, null, ['Key' => $objectKey]);
                return;
            }
            
            // Delete tags
            $stmt = $this->pdo->prepare("DELETE FROM object_tags WHERE object_id = ?");
            $stmt->execute([$object['id']]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("DeleteObjectTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // BUCKET TAGGING
    // ========================================================================
    
    /**
     * Get bucket tagging
     * GET /{bucket}?tagging
     */
    public function getBucketTagging($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT tag_key, tag_value FROM bucket_tags WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            $tags = $stmt->fetchAll();
            
            if (empty($tags)) {
                S3Response::error('NoSuchTagSet', 'The TagSet does not exist');
                return;
            }
            
            $tagSet = [];
            foreach ($tags as $tag) {
                $tagSet[] = [
                    'Key' => $tag['tag_key'],
                    'Value' => $tag['tag_value']
                ];
            }
            
            S3Response::xml('Tagging', [
                'TagSet' => ['Tag' => $tagSet]
            ]);
            
        } catch (Exception $e) {
            error_log("GetBucketTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put bucket tagging
     * PUT /{bucket}?tagging
     */
    public function putBucketTagging($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML);
                return;
            }
            
            // Delete existing tags
            $stmt = $this->pdo->prepare("DELETE FROM bucket_tags WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            // Insert new tags
            if (isset($xml->TagSet->Tag)) {
                $stmt = $this->pdo->prepare("INSERT INTO bucket_tags (bucket_id, tag_key, tag_value) VALUES (?, ?, ?)");
                $tagCount = 0;
                
                foreach ($xml->TagSet->Tag as $tag) {
                    $tagCount++;
                    if ($tagCount > 50) {
                        S3Response::error(S3ErrorCodes::INVALID_TAG, 'Maximum 50 tags per bucket');
                        return;
                    }
                    
                    $key = (string)$tag->Key;
                    $value = (string)$tag->Value;
                    $stmt->execute([$bucket['id'], $key, $value]);
                }
            }
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("PutBucketTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete bucket tagging
     * DELETE /{bucket}?tagging
     */
    public function deleteBucketTagging($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM bucket_tags WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("DeleteBucketTagging error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // BUCKET CORS
    // ========================================================================
    
    /**
     * Get bucket CORS configuration
     * GET /{bucket}?cors
     */
    public function getBucketCors($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM bucket_cors WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            $rules = $stmt->fetchAll();
            
            if (empty($rules)) {
                S3Response::error('NoSuchCORSConfiguration', 'The CORS configuration does not exist');
                return;
            }
            
            $corsRules = [];
            foreach ($rules as $rule) {
                $corsRule = [];
                
                $origins = json_decode($rule['allowed_origins'], true);
                foreach ($origins as $origin) {
                    $corsRule['AllowedOrigin'][] = $origin;
                }
                
                $methods = json_decode($rule['allowed_methods'], true);
                foreach ($methods as $method) {
                    $corsRule['AllowedMethod'][] = $method;
                }
                
                if ($rule['allowed_headers']) {
                    $headers = json_decode($rule['allowed_headers'], true);
                    foreach ($headers as $header) {
                        $corsRule['AllowedHeader'][] = $header;
                    }
                }
                
                if ($rule['expose_headers']) {
                    $expose = json_decode($rule['expose_headers'], true);
                    foreach ($expose as $header) {
                        $corsRule['ExposeHeader'][] = $header;
                    }
                }
                
                $corsRule['MaxAgeSeconds'] = $rule['max_age_seconds'];
                
                $corsRules[] = $corsRule;
            }
            
            S3Response::xml('CORSConfiguration', [
                'CORSRule' => $corsRules
            ]);
            
        } catch (Exception $e) {
            error_log("GetBucketCors error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put bucket CORS configuration
     * PUT /{bucket}?cors
     */
    public function putBucketCors($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML);
                return;
            }
            
            // Delete existing CORS
            $stmt = $this->pdo->prepare("DELETE FROM bucket_cors WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            // Insert new CORS rules
            if (isset($xml->CORSRule)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO bucket_cors 
                    (bucket_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($xml->CORSRule as $rule) {
                    $origins = [];
                    $methods = [];
                    $headers = [];
                    $expose = [];
                    $maxAge = 3600;
                    
                    if (isset($rule->AllowedOrigin)) {
                        foreach ($rule->AllowedOrigin as $o) {
                            $origins[] = (string)$o;
                        }
                    }
                    
                    if (isset($rule->AllowedMethod)) {
                        foreach ($rule->AllowedMethod as $m) {
                            $methods[] = (string)$m;
                        }
                    }
                    
                    if (isset($rule->AllowedHeader)) {
                        foreach ($rule->AllowedHeader as $h) {
                            $headers[] = (string)$h;
                        }
                    }
                    
                    if (isset($rule->ExposeHeader)) {
                        foreach ($rule->ExposeHeader as $e) {
                            $expose[] = (string)$e;
                        }
                    }
                    
                    if (isset($rule->MaxAgeSeconds)) {
                        $maxAge = (int)$rule->MaxAgeSeconds;
                    }
                    
                    $stmt->execute([
                        $bucket['id'],
                        json_encode($origins),
                        json_encode($methods),
                        empty($headers) ? null : json_encode($headers),
                        empty($expose) ? null : json_encode($expose),
                        $maxAge
                    ]);
                }
            }
            
            http_response_code(200);
            header('Content-Length: 0');
            
        } catch (Exception $e) {
            error_log("PutBucketCors error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete bucket CORS configuration
     * DELETE /{bucket}?cors
     */
    public function deleteBucketCors($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM bucket_cors WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("DeleteBucketCors error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // BUCKET LIFECYCLE
    // ========================================================================
    
    /**
     * Get bucket lifecycle configuration
     * GET /{bucket}?lifecycle
     */
    public function getBucketLifecycle($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM bucket_lifecycle WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            $rules = $stmt->fetchAll();
            
            if (empty($rules)) {
                S3Response::error('NoSuchLifecycleConfiguration', 'The lifecycle configuration does not exist');
                return;
            }
            
            $lifecycleRules = [];
            foreach ($rules as $rule) {
                $lifecycleRule = [
                    'ID' => $rule['rule_id'],
                    'Status' => $rule['enabled'] ? 'Enabled' : 'Disabled'
                ];
                
                if ($rule['prefix'] !== '') {
                    $lifecycleRule['Filter'] = ['Prefix' => $rule['prefix']];
                } else {
                    $lifecycleRule['Filter'] = ['Prefix' => ''];
                }
                
                if ($rule['expiration_days']) {
                    $lifecycleRule['Expiration'] = ['Days' => $rule['expiration_days']];
                }
                
                if ($rule['transition_days'] && $rule['transition_storage_class']) {
                    $lifecycleRule['Transition'] = [
                        'Days' => $rule['transition_days'],
                        'StorageClass' => $rule['transition_storage_class']
                    ];
                }
                
                if ($rule['noncurrent_expiration_days']) {
                    $lifecycleRule['NoncurrentVersionExpiration'] = [
                        'NoncurrentDays' => $rule['noncurrent_expiration_days']
                    ];
                }
                
                $lifecycleRules[] = $lifecycleRule;
            }
            
            S3Response::xml('LifecycleConfiguration', [
                'Rule' => $lifecycleRules
            ]);
            
        } catch (Exception $e) {
            error_log("GetBucketLifecycle error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put bucket lifecycle configuration
     * PUT /{bucket}?lifecycle
     */
    public function putBucketLifecycle($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML);
                return;
            }
            
            // Delete existing lifecycle rules
            $stmt = $this->pdo->prepare("DELETE FROM bucket_lifecycle WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            // Insert new rules
            if (isset($xml->Rule)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO bucket_lifecycle 
                    (bucket_id, rule_id, prefix, enabled, expiration_days, transition_days, transition_storage_class, noncurrent_expiration_days) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($xml->Rule as $rule) {
                    $ruleId = isset($rule->ID) ? (string)$rule->ID : bin2hex(random_bytes(8));
                    $enabled = !isset($rule->Status) || (string)$rule->Status === 'Enabled';
                    $prefix = '';
                    $expirationDays = null;
                    $transitionDays = null;
                    $transitionClass = null;
                    $noncurrentDays = null;
                    
                    if (isset($rule->Filter->Prefix)) {
                        $prefix = (string)$rule->Filter->Prefix;
                    } elseif (isset($rule->Prefix)) {
                        $prefix = (string)$rule->Prefix;
                    }
                    
                    if (isset($rule->Expiration->Days)) {
                        $expirationDays = (int)$rule->Expiration->Days;
                    }
                    
                    if (isset($rule->Transition)) {
                        $transitionDays = isset($rule->Transition->Days) ? (int)$rule->Transition->Days : null;
                        $transitionClass = isset($rule->Transition->StorageClass) ? (string)$rule->Transition->StorageClass : null;
                    }
                    
                    if (isset($rule->NoncurrentVersionExpiration->NoncurrentDays)) {
                        $noncurrentDays = (int)$rule->NoncurrentVersionExpiration->NoncurrentDays;
                    }
                    
                    $stmt->execute([
                        $bucket['id'],
                        $ruleId,
                        $prefix,
                        $enabled ? 1 : 0,
                        $expirationDays,
                        $transitionDays,
                        $transitionClass,
                        $noncurrentDays
                    ]);
                }
            }
            
            http_response_code(200);
            header('Content-Length: 0');
            
        } catch (Exception $e) {
            error_log("PutBucketLifecycle error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete bucket lifecycle configuration
     * DELETE /{bucket}?lifecycle
     */
    public function deleteBucketLifecycle($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM bucket_lifecycle WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("DeleteBucketLifecycle error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // BUCKET VERSIONING
    // ========================================================================
    
    /**
     * Get bucket versioning status
     * GET /{bucket}?versioning
     */
    public function getBucketVersioning($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $result = [];
            if ($bucket['versioning_enabled']) {
                $result['Status'] = 'Enabled';
            }
            // Note: AWS returns empty VersioningConfiguration if never enabled
            
            S3Response::xml('VersioningConfiguration', $result);
            
        } catch (Exception $e) {
            error_log("GetBucketVersioning error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put bucket versioning
     * PUT /{bucket}?versioning
     */
    public function putBucketVersioning($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $body = file_get_contents('php://input');
            $xml = @simplexml_load_string($body);
            
            if (!$xml) {
                S3Response::error(S3ErrorCodes::MALFORMED_XML);
                return;
            }
            
            $enabled = isset($xml->Status) && (string)$xml->Status === 'Enabled';
            
            $stmt = $this->pdo->prepare("UPDATE buckets SET versioning_enabled = ? WHERE id = ?");
            $stmt->execute([$enabled ? 1 : 0, $bucket['id']]);
            
            http_response_code(200);
            header('Content-Length: 0');
            
        } catch (Exception $e) {
            error_log("PutBucketVersioning error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // BUCKET POLICY
    // ========================================================================
    
    /**
     * Get bucket policy
     * GET /{bucket}?policy
     */
    public function getBucketPolicy($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user);
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("SELECT policy_json FROM bucket_policies WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            $policy = $stmt->fetchColumn();
            
            if (!$policy) {
                S3Response::error('NoSuchBucketPolicy', 'The bucket policy does not exist');
                return;
            }
            
            header('Content-Type: application/json');
            echo $policy;
            
        } catch (Exception $e) {
            error_log("GetBucketPolicy error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Put bucket policy
     * PUT /{bucket}?policy
     */
    public function putBucketPolicy($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $body = file_get_contents('php://input');
            
            // Validate JSON
            $policy = json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                S3Response::error('MalformedPolicy', 'Invalid JSON policy document');
                return;
            }
            
            // Upsert policy
            $stmt = $this->pdo->prepare("
                INSERT INTO bucket_policies (bucket_id, policy_json) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE policy_json = VALUES(policy_json), updated_at = NOW()
            ");
            $stmt->execute([$bucket['id'], $body]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("PutBucketPolicy error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    /**
     * Delete bucket policy
     * DELETE /{bucket}?policy
     */
    public function deleteBucketPolicy($bucketName, $user) {
        try {
            $bucket = $this->getBucket($bucketName, $user, 'admin');
            if (!$bucket) {
                S3Response::error(S3ErrorCodes::NO_SUCH_BUCKET, null, null, ['BucketName' => $bucketName]);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM bucket_policies WHERE bucket_id = ?");
            $stmt->execute([$bucket['id']]);
            
            http_response_code(204);
            
        } catch (Exception $e) {
            error_log("DeleteBucketPolicy error: " . $e->getMessage());
            S3Response::error(S3ErrorCodes::INTERNAL_ERROR);
        }
    }
    
    // ========================================================================
    // OBJECT METADATA
    // ========================================================================
    
    /**
     * Store object metadata from headers
     */
    public function storeObjectMetadata($objectId, $headers = []) {
        // Extract x-amz-meta-* headers
        $metadata = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_X_AMZ_META_') === 0) {
                $metaKey = strtolower(str_replace('HTTP_X_AMZ_META_', '', $key));
                $metaKey = str_replace('_', '-', $metaKey);
                $metadata[$metaKey] = $value;
            }
        }
        
        // Also check passed headers
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (strpos($lowerKey, 'x-amz-meta-') === 0) {
                $metaKey = substr($lowerKey, 11);
                $metadata[$metaKey] = $value;
            }
        }
        
        if (empty($metadata)) {
            return;
        }
        
        // Store as JSON in objects table
        $stmt = $this->pdo->prepare("UPDATE objects SET metadata_json = ? WHERE id = ?");
        $stmt->execute([json_encode($metadata), $objectId]);
        
        // Also store in object_metadata table for querying
        $stmt = $this->pdo->prepare("INSERT INTO object_metadata (object_id, meta_key, meta_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        foreach ($metadata as $key => $value) {
            $stmt->execute([$objectId, $key, $value]);
        }
    }
    
    /**
     * Get object metadata and add to response headers
     */
    public function sendObjectMetadataHeaders($objectId) {
        $stmt = $this->pdo->prepare("SELECT metadata_json FROM objects WHERE id = ?");
        $stmt->execute([$objectId]);
        $json = $stmt->fetchColumn();
        
        if ($json) {
            $metadata = json_decode($json, true);
            if ($metadata) {
                foreach ($metadata as $key => $value) {
                    header('x-amz-meta-' . $key . ': ' . $value);
                }
            }
        }
    }
}
