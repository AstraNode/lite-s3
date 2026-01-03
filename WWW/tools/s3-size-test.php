<?php
/**
 * S3 File Size Test Script
 * Tests uploads from 1 byte to 5GB
 * 
 * Usage: php s3-size-test.php [endpoint] [access_key] [secret_key]
 * Example: php s3-size-test.php https://mys3.nsheth.in admin Nityam@123
 */

// Configuration
$endpoint = $argv[1] ?? 'https://mys3.nsheth.in';
$accessKey = $argv[2] ?? 'admin';
$secretKey = $argv[3] ?? '';
$bucket = 'test-bucket';
$tempDir = sys_get_temp_dir();

if (empty($secretKey)) {
    echo "Usage: php s3-size-test.php <endpoint> <access_key> <secret_key>\n";
    echo "Example: php s3-size-test.php https://mys3.nsheth.in admin MyPassword123\n";
    exit(1);
}

// Test sizes: 1B, 10B, 100B, 1KB, 10KB, 100KB, 1MB, 10MB, 50MB, 100MB, 256MB, 512MB, 1GB, 2GB, 5GB
$testSizes = [
    ['size' => 1, 'label' => '1B'],
    ['size' => 10, 'label' => '10B'],
    ['size' => 100, 'label' => '100B'],
    ['size' => 1024, 'label' => '1KB'],
    ['size' => 10 * 1024, 'label' => '10KB'],
    ['size' => 100 * 1024, 'label' => '100KB'],
    ['size' => 1024 * 1024, 'label' => '1MB'],
    ['size' => 10 * 1024 * 1024, 'label' => '10MB'],
    ['size' => 50 * 1024 * 1024, 'label' => '50MB'],
    ['size' => 100 * 1024 * 1024, 'label' => '100MB'],
    ['size' => 256 * 1024 * 1024, 'label' => '256MB'],
    ['size' => 512 * 1024 * 1024, 'label' => '512MB'],
    ['size' => 1024 * 1024 * 1024, 'label' => '1GB'],
    ['size' => 2 * 1024 * 1024 * 1024, 'label' => '2GB'],
    ['size' => 5 * 1024 * 1024 * 1024, 'label' => '5GB'],
];

echo "=== S3 File Size Test ===\n";
echo "Endpoint: $endpoint\n";
echo "Bucket: $bucket\n";
echo "User: $accessKey\n";
echo str_repeat("=", 60) . "\n\n";

$results = [];

foreach ($testSizes as $test) {
    $size = $test['size'];
    $label = $test['label'];
    $filename = "test_{$label}.bin";
    $filepath = "$tempDir/$filename";
    
    echo "Testing $label... ";
    
    // Check if we have enough disk space
    $freeSpace = disk_free_space($tempDir);
    if ($freeSpace < $size * 1.5) {
        echo "⚠️  SKIPPED (not enough disk space)\n";
        $results[] = ['size' => $label, 'status' => 'skipped', 'reason' => 'disk space'];
        continue;
    }
    
    // Create test file
    $start = microtime(true);
    
    // Use dd for large files, direct write for small
    if ($size > 100 * 1024 * 1024) {
        $blocks = ceil($size / (1024 * 1024));
        exec("dd if=/dev/urandom of=$filepath bs=1M count=$blocks 2>/dev/null");
        // Truncate to exact size
        $fh = fopen($filepath, 'r+');
        ftruncate($fh, $size);
        fclose($fh);
    } else {
        $fh = fopen($filepath, 'wb');
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = min($remaining, 8192);
            fwrite($fh, random_bytes($chunk));
            $remaining -= $chunk;
        }
        fclose($fh);
    }
    
    $createTime = microtime(true) - $start;
    
    // Upload using curl
    $url = "$endpoint/$bucket/$filename";
    $uploadStart = microtime(true);
    
    // Use -T for streaming (handles large files better)
    $cmd = sprintf(
        'curl -s -X PUT -u %s:%s -T %s %s -w "%%{http_code}" -o /dev/null --max-time 3600 2>&1',
        escapeshellarg($accessKey),
        escapeshellarg($secretKey),
        escapeshellarg($filepath),
        escapeshellarg($url)
    );
    
    $httpCode = trim(shell_exec($cmd));
    $uploadTime = microtime(true) - $uploadStart;
    
    // Verify upload
    $verifyUrl = "$endpoint/$bucket/$filename";
    $verifyCmd = sprintf(
        'curl -s -I -u %s:%s %s -w "%%{http_code}" -o /dev/null 2>&1',
        escapeshellarg($accessKey),
        escapeshellarg($secretKey),
        escapeshellarg($verifyUrl)
    );
    $verifyCode = trim(shell_exec($verifyCmd));
    
    // Cleanup
    @unlink($filepath);
    
    // Calculate speed
    $speedMBps = $size / (1024 * 1024) / max($uploadTime, 0.001);
    
    if ($httpCode === '200' && $verifyCode === '200') {
        echo "✅ Upload: {$httpCode}, Verify: {$verifyCode}, Time: " . number_format($uploadTime, 2) . "s, Speed: " . number_format($speedMBps, 2) . " MB/s\n";
        $results[] = ['size' => $label, 'status' => 'success', 'time' => $uploadTime, 'speed' => $speedMBps];
    } else {
        echo "❌ Upload: {$httpCode}, Verify: {$verifyCode}, Time: " . number_format($uploadTime, 2) . "s\n";
        $results[] = ['size' => $label, 'status' => 'failed', 'upload_code' => $httpCode, 'verify_code' => $verifyCode];
    }
    
    // Delete test object
    $deleteCmd = sprintf(
        'curl -s -X DELETE -u %s:%s %s -o /dev/null 2>&1',
        escapeshellarg($accessKey),
        escapeshellarg($secretKey),
        escapeshellarg($url)
    );
    shell_exec($deleteCmd);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "=== SUMMARY ===\n";
$passed = count(array_filter($results, fn($r) => $r['status'] === 'success'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
$skipped = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
echo "Passed: $passed, Failed: $failed, Skipped: $skipped\n";

// Find max successful size
$maxSize = null;
foreach (array_reverse($results) as $r) {
    if ($r['status'] === 'success') {
        $maxSize = $r['size'];
        break;
    }
}
if ($maxSize) {
    echo "Maximum successful upload size: $maxSize\n";
}

echo "\nDone!\n";
