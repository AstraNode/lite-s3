#!/bin/bash
# =============================================================================
# S3 Object Storage - Universal Setup Script
# Works on: Linux, macOS, LAMP, XAMPP, WAMP, Shared Hosting
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║       S3 Object Storage - Universal Setup Script             ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Detect OS
OS="unknown"
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    OS="linux"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    OS="macos"
elif [[ "$OSTYPE" == "cygwin" ]] || [[ "$OSTYPE" == "msys" ]] || [[ "$OSTYPE" == "win32" ]]; then
    OS="windows"
fi
echo -e "${GREEN}✓${NC} Detected OS: $OS"

# Find PHP
PHP_BIN=$(which php 2>/dev/null || echo "")
if [ -z "$PHP_BIN" ]; then
    echo -e "${RED}✗${NC} PHP not found. Please install PHP 8.0+ first."
    exit 1
fi
PHP_VERSION=$($PHP_BIN -v | head -n1 | cut -d' ' -f2)
echo -e "${GREEN}✓${NC} PHP found: $PHP_VERSION"

# Check required extensions
echo -e "${BLUE}→${NC} Checking PHP extensions..."
REQUIRED_EXTENSIONS=("pdo" "pdo_mysql" "json" "hash" "mbstring")
MISSING=()
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if $PHP_BIN -m 2>/dev/null | grep -qi "^$ext$"; then
        echo -e "  ${GREEN}✓${NC} $ext"
    else
        echo -e "  ${YELLOW}!${NC} $ext (optional, trying anyway)"
    fi
done

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WWW_DIR="$SCRIPT_DIR/WWW"

echo ""
echo -e "${BLUE}→${NC} Setting up directories..."

# Create directories
mkdir -p "$WWW_DIR/storage"
mkdir -p "$WWW_DIR/logs"
mkdir -p "$WWW_DIR/uploads"
mkdir -p "$WWW_DIR/meta"

# Set permissions
if [ "$OS" != "windows" ]; then
    chmod 755 "$WWW_DIR/storage" 2>/dev/null || true
    chmod 755 "$WWW_DIR/logs" 2>/dev/null || true
    chmod 755 "$WWW_DIR/uploads" 2>/dev/null || true
    chmod 755 "$WWW_DIR/meta" 2>/dev/null || true
fi
echo -e "${GREEN}✓${NC} Directories created"

# Database configuration
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║               Database Configuration                          ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Default values
DEFAULT_HOST="localhost"
DEFAULT_NAME="s3_storage"
DEFAULT_USER="root"
DEFAULT_PASS=""
DEFAULT_PORT="3306"

# Interactive or use defaults
if [ "$1" = "--auto" ]; then
    DB_HOST="$DEFAULT_HOST"
    DB_NAME="$DEFAULT_NAME"
    DB_USER="$DEFAULT_USER"
    DB_PASS="$DEFAULT_PASS"
    DB_PORT="$DEFAULT_PORT"
else
    read -p "Database Host [$DEFAULT_HOST]: " DB_HOST
    DB_HOST=${DB_HOST:-$DEFAULT_HOST}
    
    read -p "Database Name [$DEFAULT_NAME]: " DB_NAME
    DB_NAME=${DB_NAME:-$DEFAULT_NAME}
    
    read -p "Database User [$DEFAULT_USER]: " DB_USER
    DB_USER=${DB_USER:-$DEFAULT_USER}
    
    read -sp "Database Password []: " DB_PASS
    echo ""
    DB_PASS=${DB_PASS:-$DEFAULT_PASS}
    
    read -p "Database Port [$DEFAULT_PORT]: " DB_PORT
    DB_PORT=${DB_PORT:-$DEFAULT_PORT}
fi

echo ""
echo -e "${BLUE}→${NC} Testing database connection..."

# Test database connection using PHP
CONNECTION_TEST=$($PHP_BIN -r "
try {
    \$pdo = new PDO('mysql:host=$DB_HOST;port=$DB_PORT', '$DB_USER', '$DB_PASS');
    echo 'OK';
} catch (Exception \$e) {
    echo 'FAIL: ' . \$e->getMessage();
}
" 2>&1)

if [[ "$CONNECTION_TEST" == "OK" ]]; then
    echo -e "${GREEN}✓${NC} Database connection successful"
else
    echo -e "${RED}✗${NC} Database connection failed: $CONNECTION_TEST"
    echo -e "${YELLOW}!${NC} Please check your credentials and try again."
    exit 1
fi

# Create database if not exists
echo -e "${BLUE}→${NC} Creating database if needed..."
$PHP_BIN -r "
try {
    \$pdo = new PDO('mysql:host=$DB_HOST;port=$DB_PORT', '$DB_USER', '$DB_PASS');
    \$pdo->exec('CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo 'Database ready';
} catch (Exception \$e) {
    echo 'Error: ' . \$e->getMessage();
    exit(1);
}
"
echo -e "${GREEN}✓${NC} Database '$DB_NAME' ready"

# Import schema
echo -e "${BLUE}→${NC} Importing database schema..."
if [ -f "$SCRIPT_DIR/schema.sql" ]; then
    $PHP_BIN -r "
    try {
        \$pdo = new PDO('mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME', '$DB_USER', '$DB_PASS');
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \$sql = file_get_contents('$SCRIPT_DIR/schema.sql');
        \$pdo->exec(\$sql);
        echo 'Schema imported';
    } catch (Exception \$e) {
        // Ignore duplicate table errors
        if (strpos(\$e->getMessage(), 'already exists') === false) {
            echo 'Warning: ' . \$e->getMessage();
        } else {
            echo 'Schema already exists';
        }
    }
    "
    echo -e "${GREEN}✓${NC} Database schema imported"
else
    echo -e "${YELLOW}!${NC} schema.sql not found, skipping import"
fi

# Generate config.php
echo ""
echo -e "${BLUE}→${NC} Generating config.php..."

SALT=$(openssl rand -hex 32 2>/dev/null || cat /dev/urandom | tr -dc 'a-f0-9' | fold -w 64 | head -n 1)

cat > "$WWW_DIR/config.php" << EOFCONFIG
<?php
/**
 * S3 Object Storage Configuration
 * Generated by setup.sh on $(date '+%Y-%m-%d %H:%M:%S')
 * 
 * WARNING: Keep this file secure! Never commit to public repositories.
 */

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
define('DB_HOST', '${DB_HOST}');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('DB_PORT', ${DB_PORT});

// ============================================================================
// SECURITY SETTINGS
// ============================================================================
define('SECRET_SALT', '${SALT}');
define('DEBUG_MODE', false);

// ============================================================================
// PATH CONFIGURATION
// ============================================================================
define('BASE_PATH', __DIR__ . '/');
define('STORAGE_PATH', BASE_PATH . 'storage/');
define('LOGS_PATH', BASE_PATH . 'logs/');
define('UPLOADS_PATH', BASE_PATH . 'uploads/');

// ============================================================================
// FILE SETTINGS
// ============================================================================
define('MAX_FILE_SIZE', 5368709120); // 5GB

// ============================================================================
// SESSION SETTINGS
// ============================================================================
define('SESSION_TIMEOUT', 7200); // 2 hours

// ============================================================================
// S3 API SETTINGS
// ============================================================================
define('S3_DEFAULT_REGION', 'us-east-1');
define('S3_PERMISSIVE_AUTH', false);
define('S3_SIMPLE_AUTH', true);

// ============================================================================
// RATE LIMITING
// ============================================================================
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 1000);
define('RATE_LIMIT_WINDOW', 3600);

// ============================================================================
// SECURITY LIMITS
// ============================================================================
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);
define('SCAN_UPLOADS', true);

// ============================================================================
// TIMEZONE
// ============================================================================
date_default_timezone_set('UTC');

// ============================================================================
// PRODUCTION SETTINGS
// ============================================================================
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOGS_PATH . 'php_errors.log');

// ============================================================================
// SESSION SECURITY
// ============================================================================
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// ============================================================================
// AUTO DIRECTORY CREATION
// ============================================================================
foreach ([STORAGE_PATH, LOGS_PATH, UPLOADS_PATH] as \$dir) {
    if (!is_dir(\$dir)) {
        @mkdir(\$dir, 0755, true);
    }
}

// ============================================================================
// DATABASE CONNECTION HELPER
// ============================================================================
function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return \$pdo;
}
EOFCONFIG

chmod 640 "$WWW_DIR/config.php" 2>/dev/null || true
echo -e "${GREEN}✓${NC} config.php created"

# Create .installed marker
echo "$(date -Iseconds)" > "$WWW_DIR/.installed"
echo -e "${GREEN}✓${NC} Installation marker created"

# Summary
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                  🎉 Setup Complete!                          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}Database:${NC} mysql://$DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
echo -e "  ${BLUE}Web Root:${NC} $WWW_DIR"
echo ""
echo -e "  ${YELLOW}Next Steps:${NC}"
echo -e "  1. Point your web server to: $WWW_DIR"
echo -e "  2. Open http://localhost/admin/ in your browser"
echo -e "  3. Login with: admin / admin123"
echo ""
echo -e "  ${YELLOW}Test with curl:${NC}"
echo -e "  curl -u admin:admin123 http://localhost/"
echo ""
