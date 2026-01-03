#!/bin/bash
#
# S3 File Size Test Script (Bash Version)
# Tests uploads from 1 byte to 512MB (Hostinger shared hosting limit)
#
# IMPORTANT: Hostinger shared hosting limits single PUT uploads to 512MB max.
# For files >512MB, use Multipart Upload API which splits files into chunks.
#
# Usage: ./s3-size-test.sh [endpoint] [access_key] [secret_key]
# Example: ./s3-size-test.sh https://mys3.nsheth.in admin MyPassword123
#

ENDPOINT="${1:-https://mys3.nsheth.in}"
ACCESS_KEY="${2:-admin}"
SECRET_KEY="${3:-}"
BUCKET="test-bucket"
TEMP_DIR="/tmp"

if [ -z "$SECRET_KEY" ]; then
    echo "Usage: $0 <endpoint> <access_key> <secret_key>"
    echo "Example: $0 https://mys3.nsheth.in admin MyPassword123"
    exit 1
fi

# Test sizes in bytes (max 512MB for shared hosting)
declare -a SIZES=(
    "1:1B"
    "10:10B"
    "100:100B"
    "1024:1KB"
    "10240:10KB"
    "102400:100KB"
    "1048576:1MB"
    "10485760:10MB"
    "52428800:50MB"
    "104857600:100MB"
    "268435456:256MB"
    "536870912:512MB"
)

echo "=== S3 File Size Test ==="
echo "Endpoint: $ENDPOINT"
echo "Bucket: $BUCKET"
echo "User: $ACCESS_KEY"
echo "============================================================"
echo ""

PASSED=0
FAILED=0
SKIPPED=0
MAX_SUCCESS=""

for ENTRY in "${SIZES[@]}"; do
    SIZE="${ENTRY%%:*}"
    LABEL="${ENTRY##*:}"
    FILENAME="test_${LABEL}.bin"
    FILEPATH="$TEMP_DIR/$FILENAME"
    
    printf "Testing %-8s... " "$LABEL"
    
    # Check disk space (need at least size + 100MB buffer)
    FREE_SPACE=$(df --output=avail "$TEMP_DIR" 2>/dev/null | tail -1)
    FREE_BYTES=$((FREE_SPACE * 1024))
    NEEDED=$((SIZE + 104857600))
    
    if [ "$FREE_BYTES" -lt "$NEEDED" ]; then
        echo "⚠️  SKIPPED (not enough disk space)"
        ((SKIPPED++))
        continue
    fi
    
    # Create test file
    if [ "$SIZE" -gt 104857600 ]; then
        # For large files, use dd with sparse file then truncate
        BLOCKS=$((SIZE / 1048576))
        dd if=/dev/urandom of="$FILEPATH" bs=1M count="$BLOCKS" 2>/dev/null
        truncate -s "$SIZE" "$FILEPATH" 2>/dev/null
    else
        # For smaller files, direct dd
        dd if=/dev/urandom of="$FILEPATH" bs=1 count="$SIZE" 2>/dev/null
    fi
    
    # Upload using curl with -T for streaming
    START_TIME=$(date +%s.%N)
    HTTP_CODE=$(curl -s -X PUT \
        -u "$ACCESS_KEY:$SECRET_KEY" \
        -T "$FILEPATH" \
        "$ENDPOINT/$BUCKET/$FILENAME" \
        -w "%{http_code}" \
        -o /dev/null \
        --max-time 3600 \
        2>/dev/null)
    END_TIME=$(date +%s.%N)
    DURATION=$(echo "$END_TIME - $START_TIME" | bc)
    
    # Verify upload
    VERIFY_CODE=$(curl -s -I \
        -u "$ACCESS_KEY:$SECRET_KEY" \
        "$ENDPOINT/$BUCKET/$FILENAME" \
        -w "%{http_code}" \
        -o /dev/null \
        2>/dev/null)
    
    # Cleanup local file
    rm -f "$FILEPATH"
    
    # Calculate speed
    if [ "$(echo "$DURATION > 0" | bc)" -eq 1 ]; then
        SPEED=$(echo "scale=2; $SIZE / 1048576 / $DURATION" | bc)
    else
        SPEED="N/A"
    fi
    
    if [ "$HTTP_CODE" = "200" ] && [ "$VERIFY_CODE" = "200" ]; then
        echo "✅ Upload: $HTTP_CODE, Verify: $VERIFY_CODE, Time: ${DURATION}s, Speed: ${SPEED} MB/s"
        ((PASSED++))
        MAX_SUCCESS="$LABEL"
    else
        echo "❌ Upload: $HTTP_CODE, Verify: $VERIFY_CODE, Time: ${DURATION}s"
        ((FAILED++))
    fi
    
    # Delete test object
    curl -s -X DELETE \
        -u "$ACCESS_KEY:$SECRET_KEY" \
        "$ENDPOINT/$BUCKET/$FILENAME" \
        -o /dev/null 2>/dev/null
done

echo ""
echo "============================================================"
echo "=== SUMMARY ==="
echo "Passed: $PASSED, Failed: $FAILED, Skipped: $SKIPPED"
if [ -n "$MAX_SUCCESS" ]; then
    echo "Maximum successful upload size: $MAX_SUCCESS"
fi
echo ""
echo "Done!"
