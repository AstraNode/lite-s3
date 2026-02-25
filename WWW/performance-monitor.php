<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

class PerformanceMonitor
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = getDB();
    }

    /**
     * Get statistics for a specific time range
     * @param int $hours
     * @return array
     */
    public function getStats($hours = 1)
    {
        try {
            // Count recent uploads
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM objects WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)");
            $stmt->execute([$hours]);
            $recentUploads = $stmt->fetchColumn();

            // Total storage used
            $stmt = $this->pdo->query("SELECT SUM(size) FROM objects");
            $totalSize = $stmt->fetchColumn() ?: 0;

            // Health metrics (mocked or derived)
            return [
                'requests' => $recentUploads * 5 + rand(10, 50), // Mocked request count
                'storage_used' => $totalSize,
                'error_rate' => 0.02, // Mocked 0.02%
                'avg_latency' => 45 + rand(5, 15) // Mocked latency in ms
            ];
        } catch (Exception $e) {
            return [
                'requests' => 0,
                'storage_used' => 0,
                'error_rate' => 0,
                'avg_latency' => 0
            ];
        }
    }

    /**
     * Log a request for performance tracking (Matches signature in index.php)
     */
    public function logRequest($method, $uri, $time, $status, $details = '')
    {
        try {
            // Convert time to ms if it's a float
            $timeMs = is_float($time) ? $time * 1000 : $time;

            if ($timeMs > 500) {
                error_log("PERF WARNING: Slow request ({$timeMs}ms) - $method $uri (Status: $status)");
            }
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }

    /**
     * Get concurrent connections count
     * @return int
     */
    public function getConcurrentConnections()
    {
        // Mocked based on recent activity or process list
        return rand(2, 8);
    }
}