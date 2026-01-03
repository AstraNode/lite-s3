<?php
/**
 * S3 XML Response Helpers
 * Enhanced with proper AWS S3 compatible responses
 */

require_once __DIR__ . '/s3-errors.php';

class S3Response {
    
    /**
     * Generate AWS-style request ID
     */
    private static function generateRequestId() {
        return strtoupper(bin2hex(random_bytes(8)));
    }
    
    /**
     * Generate AWS-style host ID
     */
    private static function generateHostId() {
        return base64_encode(random_bytes(32));
    }
    
    /**
     * Set common S3 response headers
     */
    private static function setCommonHeaders() {
        header('x-amz-request-id: ' . self::generateRequestId());
        header('x-amz-id-2: ' . self::generateHostId());
        header('Server: S3-Compatible-Storage');
    }
    
    /**
     * Send XML response with proper S3 format
     */
    public static function xml($rootElement, $data, $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/') {
        self::setCommonHeaders();
        header('Content-Type: application/xml; charset=utf-8');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo self::arrayToXml($data, $rootElement, $namespace);
    }
    
    /**
     * Send S3-compatible error response
     * 
     * @param string $code Error code (use S3ErrorCodes constants)
     * @param string $message Error message
     * @param int $httpCode HTTP status code (auto-detected if not provided)
     * @param array $extra Additional XML elements (Resource, BucketName, Key, etc.)
     */
    public static function error($code, $message = null, $httpCode = null, $extra = []) {
        // Auto-detect HTTP status if not provided
        if ($httpCode === null) {
            $httpCode = S3ErrorCodes::getHttpStatus($code);
        }
        
        // Use default message if not provided
        if ($message === null) {
            $message = S3ErrorCodes::getMessage($code);
        }
        
        http_response_code($httpCode);
        self::setCommonHeaders();
        header('Content-Type: application/xml; charset=utf-8');
        
        $requestId = self::generateRequestId();
        $hostId = self::generateHostId();
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo "<Error>\n";
        echo "  <Code>" . htmlspecialchars($code) . "</Code>\n";
        echo "  <Message>" . htmlspecialchars($message) . "</Message>\n";
        
        // Add extra elements (Resource, BucketName, Key, etc.)
        foreach ($extra as $key => $value) {
            echo "  <" . htmlspecialchars($key) . ">" . htmlspecialchars($value) . "</" . htmlspecialchars($key) . ">\n";
        }
        
        echo "  <RequestId>" . $requestId . "</RequestId>\n";
        echo "  <HostId>" . $hostId . "</HostId>\n";
        echo "</Error>\n";
        
        // Log error for debugging
        error_log("S3 Error: [$code] $message (HTTP $httpCode)");
    }
    
    /**
     * Convert array to XML with proper S3 format
     */
    private static function arrayToXml($data, $rootElement, $namespace = null) {
        $xml = "<$rootElement";
        if ($namespace) {
            $xml .= " xmlns=\"$namespace\"";
        }
        $xml .= ">\n";
        
        $xml .= self::buildXmlBody($data, '  ');
        
        return $xml . "</$rootElement>\n";
    }
    
    /**
     * Build XML body recursively
     */
    private static function buildXmlBody($data, $indent = '') {
        $xml = '';
        
        foreach ($data as $key => $value) {
            // Handle special _value key for text content (e.g., LocationConstraint)
            if ($key === '_value') {
                return $indent . htmlspecialchars($value ?? '') . "\n";
            }
            
            if (is_numeric($key)) {
                // Handle numeric indexed arrays (like Contents items)
                if (is_array($value)) {
                    $xml .= self::buildXmlBody($value, $indent);
                } else {
                    $xml .= $indent . htmlspecialchars($value ?? '') . "\n";
                }
            } elseif (is_array($value)) {
                // Check if array has only _value key (text content element)
                if (isset($value['_value']) && count($value) === 1) {
                    $xml .= $indent . "<$key>" . htmlspecialchars($value['_value'] ?? '') . "</$key>\n";
                }
                // Check if it's a list (has numeric keys)
                elseif (!empty($value) && isset($value[0])) {
                    // Repeated elements
                    foreach ($value as $item) {
                        $xml .= $indent . "<$key>\n";
                        if (is_array($item)) {
                            $xml .= self::buildXmlBody($item, $indent . '  ');
                        } else {
                            $xml .= $indent . '  ' . htmlspecialchars($item ?? '') . "\n";
                        }
                        $xml .= $indent . "</$key>\n";
                    }
                } else {
                    // Nested object
                    $xml .= $indent . "<$key>\n";
                    $xml .= self::buildXmlBody($value, $indent . '  ');
                    $xml .= $indent . "</$key>\n";
                }
            } else {
                $xml .= $indent . "<$key>" . htmlspecialchars($value ?? '') . "</$key>\n";
            }
        }
        
        return $xml;
    }
    
    /**
     * Send empty 204 No Content response (for DELETE)
     */
    public static function noContent() {
        http_response_code(204);
        self::setCommonHeaders();
        header('Content-Length: 0');
    }
    
    /**
     * Send success response with ETag (for PUT)
     */
    public static function putSuccess($etag, $headers = []) {
        http_response_code(200);
        self::setCommonHeaders();
        header('ETag: "' . $etag . '"');
        header('Content-Length: 0');
        
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
    }
    
    /**
     * Send bucket created response
     */
    public static function bucketCreated($location = null) {
        http_response_code(200);
        self::setCommonHeaders();
        if ($location) {
            header('Location: ' . $location);
        }
        header('Content-Length: 0');
    }
}
