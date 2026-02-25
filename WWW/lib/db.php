<?php
/**
 * Database Connection Module
 * S3-Compatible Storage System
 * 
 * Provides singleton PDO connection with proper error handling
 */

// Skip if getDB already defined (by config.php)
if (function_exists('getDB')) {
    return;
}

/**
 * Get PDO database connection instance (singleton pattern)
 * 
 * @param bool $forceNew Force a new connection (useful after long operations)
 * @return PDO
 * @throws PDOException on connection failure
 */
function getDB($forceNew = false)
{
    static $pdo = null;

    // Force close old connection if requested
    if ($forceNew && $pdo !== null) {
        $pdo = null;
    }

    if ($pdo === null) {
        // First, try to load from config.php if it exists
        $configFile = dirname(__DIR__) . '/config.php';
        if (file_exists($configFile)) {
            require_once $configFile;
        }

        // Check for defined constants first (from config.php), then ENV, then defaults
        $host = defined('DB_HOST') ? DB_HOST : ($_ENV['DB_HOST'] ?? 'localhost');
        $name = defined('DB_NAME') ? DB_NAME : ($_ENV['DB_NAME'] ?? 's3_storage');
        $user = defined('DB_USER') ? DB_USER : ($_ENV['DB_USER'] ?? 'root');
        $pass = defined('DB_PASS') ? DB_PASS : ($_ENV['DB_PASS'] ?? '');
        $port = defined('DB_PORT') ? DB_PORT : ($_ENV['DB_PORT'] ?? '3306');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            // Disable SSL verification for internal Docker connections to avoid "Certificate not trusted" errors
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ];

        // NEVER use persistent connections for large file uploads
        // Persistent connections can return stale/dead connections from pool
        if ($forceNew) {
            $options[PDO::ATTR_PERSISTENT] = false;
        } elseif (defined('DB_PERSISTENT') && DB_PERSISTENT) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        try {
            error_log("DB: Creating " . ($forceNew ? "fresh" : "new") . " connection to $host:$port/$name");
            $pdo = new PDO($dsn, $user, $pass, $options);

            // Set additional MySQL-specific options
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            error_log("DB: Connection established successfully");

        } catch (PDOException $e) {
            // Log the error but don't expose connection details in production unless debug is on
            $errorMsg = $e->getMessage();
            error_log("Database connection failed: " . $errorMsg);

            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                $advice = "";
                if (strpos($errorMsg, 'Connection refused') !== false) {
                    $advice = " (Check if MySQL is running or if the port is correct)";
                } elseif (strpos($errorMsg, 'Access denied') !== false) {
                    $advice = " (Check if DB_USER and DB_PASS are correct for the VPS)";
                } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                    $advice = " (Check if DB_NAME exists; try importing schema.sql)";
                }
                throw new PDOException("Database connection failed: " . $errorMsg . $advice);
            }

            // Re-throw with sanitized message for production
            throw new PDOException("Database connection failed. Please verify your config.php credentials and ensure the database server is accessible.");
        }
    }

    return $pdo;
}

/**
 * Test database connection
 * 
 * @return bool True if connection successful
 */
function testDBConnection()
{
    try {
        $pdo = getDB();
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if required tables exist
 * 
 * @return array Missing table names
 */
function checkRequiredTables()
{
    $requiredTables = ['users', 'buckets', 'objects', 'permissions'];
    $missingTables = [];

    try {
        $pdo = getDB();
        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        }
    } catch (Exception $e) {
        return $requiredTables; // Return all as missing on error
    }

    return $missingTables;
}
