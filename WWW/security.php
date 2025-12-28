<?php
/**
 * Security System
 * Handles login attempts, file scanning, and security measures
 */

class SecurityManager {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDB();
        $this->initSecurityTables();
    }
    
    private function initSecurityTables() {
        // MySQL syntax only
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_ip VARCHAR(45) NOT NULL,
            access_key VARCHAR(255),
            success BOOLEAN DEFAULT 0,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_agent TEXT
        )");
        
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(255) NOT NULL,
            client_ip VARCHAR(45) NOT NULL,
            user_id INT,
            details TEXT,
            severity VARCHAR(50) DEFAULT 'info',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Create indexes for MySQL (with error handling)
        try {
            $this->pdo->exec("CREATE INDEX idx_login_attempts_ip ON login_attempts(client_ip, attempt_time)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }
        try {
            $this->pdo->exec("CREATE INDEX idx_security_logs_type ON security_logs(event_type, created_at)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }
    }
    
    public function recordLoginAttempt($accessKey, $success, $clientIp = null, $userAgent = null) {
        $clientIp = $clientIp ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $userAgent = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (client_ip, access_key, success, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$clientIp, $accessKey, $success ? 1 : 0, $userAgent]);
        
        if (!$success) {
            $this->logSecurityEvent('failed_login', $clientIp, null, "Failed login attempt for: $accessKey");
        }
    }
    
    public function isClientBlocked($clientIp = null) {
        $clientIp = $clientIp ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        $lockoutSeconds = (int) LOGIN_LOCKOUT_TIME;
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as failed_attempts 
            FROM login_attempts 
            WHERE client_ip = ? 
            AND success = 0 
            AND attempt_time > (NOW() - INTERVAL {$lockoutSeconds} SECOND)
        ");
        $stmt->execute([$clientIp]);
        
        $result = $stmt->fetch();
        return $result['failed_attempts'] >= MAX_LOGIN_ATTEMPTS;
    }
    
    public function scanUploadedFile($filePath, $originalName) {
        if (!SCAN_UPLOADS) {
            return true;
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'jsp', 'asp', 'aspx', 'exe', 'bat', 'cmd', 'sh', 'ps1'];
        
        if (in_array($extension, $dangerousExtensions)) {
            $this->logSecurityEvent('dangerous_file_upload', $_SERVER['REMOTE_ADDR'] ?? 'unknown', null, "Dangerous file extension: $extension");
            return false;
        }
        
        // Check file content for PHP tags
        $content = file_get_contents($filePath, false, null, 0, 1024); // Read first 1KB
        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            $this->logSecurityEvent('php_in_file', $_SERVER['REMOTE_ADDR'] ?? 'unknown', null, "PHP code detected in file: $originalName");
            return false;
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if (ALLOWED_FILE_TYPES !== ['*'] && !in_array($mimeType, ALLOWED_FILE_TYPES)) {
            $this->logSecurityEvent('invalid_mime_type', $_SERVER['REMOTE_ADDR'] ?? 'unknown', null, "Invalid MIME type: $mimeType for file: $originalName");
            return false;
        }
        
        return true;
    }
    
    public function logSecurityEvent($eventType, $clientIp, $userId = null, $details = '') {
        $severity = $this->getEventSeverity($eventType);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO security_logs (event_type, client_ip, user_id, details, severity) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$eventType, $clientIp, $userId, $details, $severity]);
        
        // Log to error log for critical events
        if ($severity === 'critical' || $severity === 'high') {
            error_log("SECURITY: $eventType - IP: $clientIp - Details: $details");
        }
    }
    
    private function getEventSeverity($eventType) {
        $criticalEvents = ['dangerous_file_upload', 'php_in_file', 'sql_injection_attempt'];
        $highEvents = ['failed_login', 'rate_limit_exceeded', 'unauthorized_access'];
        
        if (in_array($eventType, $criticalEvents)) {
            return 'critical';
        } elseif (in_array($eventType, $highEvents)) {
            return 'high';
        } else {
            return 'info';
        }
    }
    
    public function getSecurityStats() {
        $stats = [];
        
        // Failed login attempts in last 24 hours
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM login_attempts 
            WHERE success = 0 
            AND attempt_time > (NOW() - INTERVAL 1 DAY)
        ");
        $stmt->execute();
        $stats['failed_logins_24h'] = $stmt->fetchColumn();
        
        // Security events in last 24 hours
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM security_logs 
            WHERE created_at > (NOW() - INTERVAL 1 DAY)
        ");
        $stmt->execute();
        $stats['security_events_24h'] = $stmt->fetchColumn();
        
        // Blocked IPs
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as attempts
            FROM login_attempts 
            WHERE success = 0 
            AND attempt_time > (NOW() - INTERVAL 1 DAY)
            GROUP BY client_ip 
            HAVING COUNT(*) >= ?
        ");
        $stmt->execute([ (int) MAX_LOGIN_ATTEMPTS ]);
        $stats['blocked_ips'] = $stmt->rowCount();
        
        return $stats;
    }
}

// Global security manager instance
$securityManager = new SecurityManager();

function recordLoginAttempt($accessKey, $success) {
    global $securityManager;
    return $securityManager->recordLoginAttempt($accessKey, $success);
}

function isClientBlocked() {
    global $securityManager;
    return $securityManager->isClientBlocked();
}

function scanUploadedFile($filePath, $originalName) {
    global $securityManager;
    return $securityManager->scanUploadedFile($filePath, $originalName);
}

function logSecurityEvent($eventType, $clientIp, $userId = null, $details = '') {
    global $securityManager;
    return $securityManager->logSecurityEvent($eventType, $clientIp, $userId, $details);
}
?>
