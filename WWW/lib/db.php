<?php
/**
 * Database Connection Module
 */

function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        $host = $_ENV['DB_HOST'] ?? 'mysql';
        $name = $_ENV['DB_NAME'] ?? 's3_storage';
        $user = $_ENV['DB_USER'] ?? 's3user';
        $pass = $_ENV['DB_PASS'] ?? 's3pass123';
        $port = $_ENV['DB_PORT'] ?? '3306';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    return $pdo;
}
