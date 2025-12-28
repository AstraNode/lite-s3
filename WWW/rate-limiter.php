<?php
/**
 * Rate Limiting System
 * Prevents abuse and ensures fair usage
 */

class RateLimiter {
    private $pdo;
    private $enabled;
    
    public function __construct() {
        $this->pdo = getDB();
        $this->enabled = RATE_LIMIT_ENABLED;
        $this->initRateLimitTable();
    }
    
    private function initRateLimitTable() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_ip VARCHAR(45) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            request_count INT DEFAULT 1,
            window_start INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uniq_client_endpoint_window UNIQUE (client_ip, endpoint, window_start),
            INDEX idx_rate_limit_cleanup (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    public function checkRateLimit($clientIp, $endpoint = 'global') {
        // Disable rate limiting in debug mode or for OPTIONS
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            return true;
        }
        if (!$this->enabled) {
            return true;
        }
        
        $windowStart = time() - (time() % RATE_LIMIT_WINDOW);
        
        // Clean up old entries
        $this->cleanupOldEntries();
        
        // Check current rate limit
        $stmt = $this->pdo->prepare("
            SELECT request_count FROM rate_limits 
            WHERE client_ip = ? AND endpoint = ? AND window_start = ?
        ");
        $stmt->execute([$clientIp, $endpoint, $windowStart]);
        $result = $stmt->fetch();
        
        if ($result) {
            if ($result['request_count'] >= RATE_LIMIT_REQUESTS) {
                $this->logRateLimitExceeded($clientIp, $endpoint, $result['request_count']);
                return false;
            }
            
            // Increment counter
            $stmt = $this->pdo->prepare("
                UPDATE rate_limits 
                SET request_count = request_count + 1 
                WHERE client_ip = ? AND endpoint = ? AND window_start = ?
            ");
            $stmt->execute([$clientIp, $endpoint, $windowStart]);
        } else {
            // Create new entry
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limits (client_ip, endpoint, request_count, window_start) 
                VALUES (?, ?, 1, ?)
            ");
            $stmt->execute([$clientIp, $endpoint, $windowStart]);
        }
        
        return true;
    }
    
    private function cleanupOldEntries() {
        $cutoff = time() - (RATE_LIMIT_WINDOW * 2);
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE window_start < ?");
        $stmt->execute([$cutoff]);
    }
    
    private function logRateLimitExceeded($clientIp, $endpoint, $count) {
        error_log("Rate limit exceeded: IP=$clientIp, Endpoint=$endpoint, Count=$count");
    }
    
    public function getRemainingRequests($clientIp, $endpoint = 'global') {
        if (!$this->enabled) {
            return RATE_LIMIT_REQUESTS;
        }
        
        $windowStart = time() - (time() % RATE_LIMIT_WINDOW);
        
        $stmt = $this->pdo->prepare("
            SELECT request_count FROM rate_limits 
            WHERE client_ip = ? AND endpoint = ? AND window_start = ?
        ");
        $stmt->execute([$clientIp, $endpoint, $windowStart]);
        $result = $stmt->fetch();
        
        $used = $result ? $result['request_count'] : 0;
        return max(0, RATE_LIMIT_REQUESTS - $used);
    }
}

// Global rate limiter instance
$rateLimiter = new RateLimiter();

function checkRateLimit($endpoint = 'global') {
    global $rateLimiter;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $rateLimiter->checkRateLimit($clientIp, $endpoint);
}

function getRemainingRequests($endpoint = 'global') {
    global $rateLimiter;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $rateLimiter->getRemainingRequests($clientIp, $endpoint);
}
?>
