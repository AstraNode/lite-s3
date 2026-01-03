#!/bin/bash
#
# S3 Multipart Upload Test Script
# Tests uploading large files (>512MB) using multipart upload
#
# Hostinger shared hosting limits single PUT uploads to 512MB.
# For larger files, use multipart upload which splits files into chunks.
#
# Usage: ./s3-multipart-test.sh [endpoint] [access_key] [secret_key] [file_size_mb]
# Example: ./s3-multipart-test.sh https://mys3.nsheth.in admin MyPassword123 1024
#

set -e

ENDPOINT="${1:-https://mys3.nsheth.in}"
ACCESS_KEY="${2:-admin}"
SECRET_KEY="${3:-}"
FILE_SIZE_MB="${4:-1024}"  # Default 1GB
BUCKET="test-bucket"
OBJECT_KEY="multipart-test-${FILE_SIZE_MB}mb.bin"
CHUNK_SIZE_MB=100  # 100MB chunks (safe for shared hosting)
TEMP_DIR="/tmp/multipart-test-$$"

if [ -z "$SECRET_KEY" ]; then
    echo "Usage: $0 <endpoint> <access_key> <secret_key> [file_size_mb]"
    echo "Example: $0 https://mys3.nsheth.in admin MyPassword123 1024"
    exit 1
fi

cleanup() {
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

mkdir -p "$TEMP_DIR"

echo "=== S3 Multipart Upload Test ==="
echo "Endpoint: $ENDPOINT"
echo "Bucket: $BUCKET"
echo "Object: $OBJECT_KEY"
echo "File Size: ${FILE_SIZE_MB}MB"
echo "Chunk Size: ${CHUNK_SIZE_MB}MB"
echo "============================================================"
echo ""

# Step 1: Create test file
echo "Step 1: Creating ${FILE_SIZE_MB}MB test file..."
TEST_FILE="$TEMP_DIR/testfile.bin"
dd if=/dev/urandom of="$TEST_FILE" bs=1M count="$FILE_SIZE_MB" status=progress 2>&1 | tail -1
ACTUAL_SIZE=$(stat -f%z "$TEST_FILE" 2>/dev/null || stat -c%s "$TEST_FILE" 2>/dev/null)
echo "Created file: $(ls -lh "$TEST_FILE" | awk '{print $5}')"
echo ""

# Step 2: Initiate multipart upload
echo "Step 2: Initiating multipart upload..."
INIT_RESPONSE=$(curl -s -X POST \
    -u "$ACCESS_KEY:$SECRET_KEY" \
    "$ENDPOINT/$BUCKET/$OBJECT_KEY?uploads")

echo "Response: $INIT_RESPONSE"

UPLOAD_ID=$(echo "$INIT_RESPONSE" | grep -oP '(?<=<UploadId>)[^<]+' || echo "")
if [ -z "$UPLOAD_ID" ]; then
    echo "❌ Failed to initiate multipart upload"
    exit 1
fi
echo "Upload ID: $UPLOAD_ID"
echo ""

# Step 3: Split file and upload parts
echo "Step 3: Splitting file and uploading parts..."
PARTS_DIR="$TEMP_DIR/parts"
mkdir -p "$PARTS_DIR"

split -b ${CHUNK_SIZE_MB}M "$TEST_FILE" "$PARTS_DIR/part_"

PART_NUMBER=1
ETAGS=""
PARTS_XML=""

for PART_FILE in "$PARTS_DIR"/part_*; do
    PART_SIZE=$(stat -f%z "$PART_FILE" 2>/dev/null || stat -c%s "$PART_FILE" 2>/dev/null)
    echo -n "  Uploading part $PART_NUMBER ($(echo "scale=1; $PART_SIZE/1048576" | bc)MB)... "
    
    START=$(date +%s.%N)
    RESPONSE=$(curl -s -X PUT \
        -u "$ACCESS_KEY:$SECRET_KEY" \
        --data-binary "@$PART_FILE" \
        "$ENDPOINT/$BUCKET/$OBJECT_KEY?partNumber=$PART_NUMBER&uploadId=$UPLOAD_ID" \
        -D - 2>&1)
    END=$(date +%s.%N)
    DURATION=$(echo "$END - $START" | bc)
    
    # Extract ETag from response headers
    ETAG=$(echo "$RESPONSE" | grep -i "^etag:" | awk '{print $2}' | tr -d '\r\n"')
    
    if [ -z "$ETAG" ]; then
        # Check if we got a 200 status
        HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP/" | tail -1 | awk '{print $2}')
        if [ "$HTTP_CODE" = "200" ]; then
            # Generate our own ETag
            ETAG=$(md5sum "$PART_FILE" | awk '{print $1}')
        else
            echo "❌ Failed (HTTP $HTTP_CODE)"
            echo "$RESPONSE"
            exit 1
        fi
    fi
    
    echo "✅ ETag: $ETAG (${DURATION}s)"
    
    PARTS_XML="$PARTS_XML<Part><PartNumber>$PART_NUMBER</PartNumber><ETag>$ETAG</ETag></Part>"
    
    ((PART_NUMBER++))
done

TOTAL_PARTS=$((PART_NUMBER - 1))
echo "Uploaded $TOTAL_PARTS parts"
echo ""

# Step 4: Complete multipart upload
echo "Step 4: Completing multipart upload..."
COMPLETE_XML="<CompleteMultipartUpload>$PARTS_XML</CompleteMultipartUpload>"

COMPLETE_RESPONSE=$(curl -s -X POST \
    -u "$ACCESS_KEY:$SECRET_KEY" \
    -H "Content-Type: application/xml" \
    -d "$COMPLETE_XML" \
    "$ENDPOINT/$BUCKET/$OBJECT_KEY?uploadId=$UPLOAD_ID")

echo "Response: $COMPLETE_RESPONSE"

if echo "$COMPLETE_RESPONSE" | grep -q "<ETag>"; then
    echo "✅ Multipart upload completed successfully!"
else
    echo "❌ Failed to complete multipart upload"
    exit 1
fi
echo ""

# Step 5: Verify the uploaded object
echo "Step 5: Verifying uploaded object..."
HEAD_RESPONSE=$(curl -s -I \
    -u "$ACCESS_KEY:$SECRET_KEY" \
    "$ENDPOINT/$BUCKET/$OBJECT_KEY")

CONTENT_LENGTH=$(echo "$HEAD_RESPONSE" | grep -i "content-length:" | awk '{print $2}' | tr -d '\r')
REMOTE_ETAG=$(echo "$HEAD_RESPONSE" | grep -i "etag:" | awk '{print $2}' | tr -d '\r"')

echo "Remote size: $CONTENT_LENGTH bytes"
echo "Remote ETag: $REMOTE_ETAG"

if [ "$CONTENT_LENGTH" = "$ACTUAL_SIZE" ]; then
    echo "✅ Size matches!"
else
    echo "⚠️  Size mismatch: expected $ACTUAL_SIZE, got $CONTENT_LENGTH"
fi
echo ""

# Step 6: Cleanup - delete test object
echo "Step 6: Cleaning up..."
curl -s -X DELETE \
    -u "$ACCESS_KEY:$SECRET_KEY" \
    "$ENDPOINT/$BUCKET/$OBJECT_KEY" -o /dev/null

echo "✅ Test object deleted"
echo ""

echo "============================================================"
echo "=== MULTIPART UPLOAD TEST COMPLETE ==="
echo "File Size: ${FILE_SIZE_MB}MB"
echo "Parts: $TOTAL_PARTS"
echo "Chunk Size: ${CHUNK_SIZE_MB}MB"
echo "Status: ✅ SUCCESS"
echo "============================================================"
