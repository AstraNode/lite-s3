-- S3 Object Storage - Database Schema
-- Run this SQL to create the required tables
-- Compatible with MySQL 5.7+, MariaDB 10.2+

-- Users table with enhanced security
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    access_key VARCHAR(255) NOT NULL UNIQUE,
    secret_key VARCHAR(255) NOT NULL COMMENT 'bcrypt hashed for admin login and S3 auth',
    plain_secret_key VARCHAR(255) DEFAULT NULL COMMENT 'For AWS Sig V4 - plaintext secret for signature calculation',
    password VARCHAR(255) DEFAULT NULL COMMENT 'Alias for secret_key, for install.php compatibility',
    is_admin TINYINT(1) DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_access_key (access_key),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buckets table
CREATE TABLE IF NOT EXISTS buckets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    region VARCHAR(64) DEFAULT 'us-east-1',
    versioning_enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_bucket_name (name),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Objects table with enhanced metadata
CREATE TABLE IF NOT EXISTS objects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    object_key VARCHAR(1024) NOT NULL,
    version_id VARCHAR(64) DEFAULT NULL COMMENT 'NULL for non-versioned buckets',
    size BIGINT DEFAULT 0,
    mime_type VARCHAR(255) DEFAULT 'application/octet-stream',
    content_type VARCHAR(255) DEFAULT 'application/octet-stream',
    content_encoding VARCHAR(255) DEFAULT NULL,
    content_disposition VARCHAR(255) DEFAULT NULL,
    content_language VARCHAR(255) DEFAULT NULL,
    cache_control VARCHAR(255) DEFAULT NULL,
    etag VARCHAR(64),
    storage_class VARCHAR(32) DEFAULT 'STANDARD',
    metadata_json TEXT DEFAULT NULL COMMENT 'JSON object of x-amz-meta-* headers',
    checksum_sha256 VARCHAR(64) DEFAULT NULL,
    is_delete_marker TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Object expiration time',
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE KEY idx_bucket_object (bucket_id, object_key(255)),
    INDEX idx_object_key (object_key(255)),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bucket_id INT NOT NULL,
    permission VARCHAR(50) NOT NULL DEFAULT 'read',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE KEY idx_user_bucket (user_id, bucket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multipart uploads tracking
CREATE TABLE IF NOT EXISTS multipart_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id VARCHAR(64) NOT NULL UNIQUE,
    bucket VARCHAR(255) NOT NULL,
    object_key VARCHAR(1024) NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_upload_id (upload_id),
    INDEX idx_bucket_key (bucket, object_key(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting table
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(255) NOT NULL DEFAULT 'global',
    request_count INT DEFAULT 1,
    window_start INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_client_endpoint_window (client_ip, endpoint, window_start),
    INDEX idx_rate_limit_cleanup (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security events log
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    client_ip VARCHAR(45) NOT NULL,
    user_id INT DEFAULT NULL,
    details TEXT,
    severity ENUM('info', 'low', 'medium', 'high', 'critical') DEFAULT 'info',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts for brute force protection
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_ip VARCHAR(45) NOT NULL,
    access_key VARCHAR(255) DEFAULT NULL,
    success TINYINT(1) DEFAULT 0,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_agent TEXT,
    INDEX idx_login_attempts_ip (client_ip, attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123)
-- IMPORTANT: Change this password immediately after installation!
-- The secret_key is bcrypt hashed for security
INSERT INTO users (username, access_key, secret_key, plain_secret_key, is_admin, active) VALUES 
('admin', 'admin', '$2y$10$FOFCeYz3c8c0pgvDnnLtBe4IXqVSt7z/A0ovoOY4EthRKpWjJAZ96', 'admin123', 1, 1)
ON DUPLICATE KEY UPDATE id=id;

-- ============================================================================
-- ADVANCED S3 FEATURES
-- ============================================================================

-- Object metadata (x-amz-meta-* headers)
CREATE TABLE IF NOT EXISTS object_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_id INT NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (object_id) REFERENCES objects(id) ON DELETE CASCADE,
    UNIQUE KEY idx_object_meta (object_id, meta_key),
    INDEX idx_meta_key (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Object tagging
CREATE TABLE IF NOT EXISTS object_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_id INT NOT NULL,
    tag_key VARCHAR(128) NOT NULL,
    tag_value VARCHAR(256) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (object_id) REFERENCES objects(id) ON DELETE CASCADE,
    UNIQUE KEY idx_object_tag (object_id, tag_key),
    INDEX idx_tag_key (tag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bucket tagging
CREATE TABLE IF NOT EXISTS bucket_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    tag_key VARCHAR(128) NOT NULL,
    tag_value VARCHAR(256) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE KEY idx_bucket_tag (bucket_id, tag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bucket CORS configuration
CREATE TABLE IF NOT EXISTS bucket_cors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    allowed_origins TEXT NOT NULL COMMENT 'JSON array of origins',
    allowed_methods TEXT NOT NULL COMMENT 'JSON array of methods',
    allowed_headers TEXT COMMENT 'JSON array of headers',
    expose_headers TEXT COMMENT 'JSON array of headers to expose',
    max_age_seconds INT DEFAULT 3600,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    INDEX idx_bucket_cors (bucket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bucket lifecycle rules
CREATE TABLE IF NOT EXISTS bucket_lifecycle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    rule_id VARCHAR(255) NOT NULL,
    prefix VARCHAR(1024) DEFAULT '',
    enabled TINYINT(1) DEFAULT 1,
    expiration_days INT DEFAULT NULL COMMENT 'Delete objects after X days',
    transition_days INT DEFAULT NULL COMMENT 'Transition to different storage class',
    transition_storage_class VARCHAR(32) DEFAULT NULL,
    noncurrent_expiration_days INT DEFAULT NULL COMMENT 'For versioned objects',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE KEY idx_bucket_rule (bucket_id, rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Object versions (for versioning support)
CREATE TABLE IF NOT EXISTS object_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    object_key VARCHAR(1024) NOT NULL,
    version_id VARCHAR(64) NOT NULL,
    size BIGINT DEFAULT 0,
    mime_type VARCHAR(255) DEFAULT 'application/octet-stream',
    etag VARCHAR(64),
    is_latest TINYINT(1) DEFAULT 1,
    is_delete_marker TINYINT(1) DEFAULT 0,
    storage_class VARCHAR(32) DEFAULT 'STANDARD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE KEY idx_bucket_key_version (bucket_id, object_key(255), version_id),
    INDEX idx_version_id (version_id),
    INDEX idx_is_latest (is_latest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bucket notifications (for event notifications)
CREATE TABLE IF NOT EXISTS bucket_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL COMMENT 's3:ObjectCreated:*, s3:ObjectRemoved:*, etc.',
    destination_type ENUM('webhook', 'queue', 'function') DEFAULT 'webhook',
    destination_url TEXT NOT NULL,
    prefix_filter VARCHAR(1024) DEFAULT NULL,
    suffix_filter VARCHAR(255) DEFAULT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    INDEX idx_bucket_event (bucket_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bucket policies (IAM-style policies)
CREATE TABLE IF NOT EXISTS bucket_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL UNIQUE,
    policy_json TEXT NOT NULL COMMENT 'JSON policy document',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access logs
CREATE TABLE IF NOT EXISTS access_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bucket_id INT NOT NULL,
    object_key VARCHAR(1024) DEFAULT NULL,
    operation VARCHAR(50) NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    requester_id INT DEFAULT NULL,
    source_ip VARCHAR(45) NOT NULL,
    request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    bytes_sent BIGINT DEFAULT 0,
    total_time_ms INT DEFAULT 0,
    http_status INT DEFAULT 200,
    user_agent TEXT,
    INDEX idx_bucket_time (bucket_id, request_time),
    INDEX idx_operation (operation),
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
