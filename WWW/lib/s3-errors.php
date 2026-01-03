<?php
/**
 * AWS S3 Compatible Error Response System
 * 
 * Implements exact AWS S3 error response format with proper HTTP status codes
 * Reference: https://docs.aws.amazon.com/AmazonS3/latest/API/ErrorResponses.html
 */

class S3ErrorCodes {
    // Access and authentication errors
    const ACCESS_DENIED = 'AccessDenied';
    const ACCOUNT_PROBLEM = 'AccountProblem';
    const CREDENTIALS_NOT_SUPPORTED = 'CredentialsNotSupported';
    const CROSS_LOCATION_LOGGING_PROHIBITED = 'CrossLocationLoggingProhibited';
    const EXPIRED_TOKEN = 'ExpiredToken';
    const INVALID_ACCESS_KEY_ID = 'InvalidAccessKeyId';
    const INVALID_SECURITY = 'InvalidSecurity';
    const INVALID_TOKEN = 'InvalidToken';
    const SIGNATURE_DOES_NOT_MATCH = 'SignatureDoesNotMatch';
    const TOKEN_REFRESH_REQUIRED = 'TokenRefreshRequired';
    
    // Bucket errors
    const BUCKET_ALREADY_EXISTS = 'BucketAlreadyExists';
    const BUCKET_ALREADY_OWNED_BY_YOU = 'BucketAlreadyOwnedByYou';
    const BUCKET_NOT_EMPTY = 'BucketNotEmpty';
    const INVALID_BUCKET_NAME = 'InvalidBucketName';
    const INVALID_BUCKET_STATE = 'InvalidBucketState';
    const NO_SUCH_BUCKET = 'NoSuchBucket';
    const TOO_MANY_BUCKETS = 'TooManyBuckets';
    
    // Object errors
    const ENTITY_TOO_LARGE = 'EntityTooLarge';
    const ENTITY_TOO_SMALL = 'EntityTooSmall';
    const INCOMPLETE_BODY = 'IncompleteBody';
    const INVALID_DIGEST = 'InvalidDigest';
    const INVALID_PART = 'InvalidPart';
    const INVALID_PART_ORDER = 'InvalidPartOrder';
    const INVALID_RANGE = 'InvalidRange';
    const KEY_TOO_LONG_ERROR = 'KeyTooLongError';
    const MISSING_CONTENT_LENGTH = 'MissingContentLength';
    const NO_SUCH_KEY = 'NoSuchKey';
    const NO_SUCH_VERSION = 'NoSuchVersion';
    const NO_SUCH_UPLOAD = 'NoSuchUpload';
    const PRECONDITION_FAILED = 'PreconditionFailed';
    
    // Request errors
    const BAD_DIGEST = 'BadDigest';
    const INVALID_ARGUMENT = 'InvalidArgument';
    const INVALID_REQUEST = 'InvalidRequest';
    const INVALID_URI = 'InvalidURI';
    const MALFORMED_XML = 'MalformedXML';
    const METHOD_NOT_ALLOWED = 'MethodNotAllowed';
    const MISSING_SECURITY_HEADER = 'MissingSecurityHeader';
    const NOT_IMPLEMENTED = 'NotImplemented';
    const REQUEST_TIME_TOO_SKEWED = 'RequestTimeTooSkewed';
    const REQUEST_TIMEOUT = 'RequestTimeout';
    const REQUEST_TORRENT_OF_BUCKET = 'RequestTorrentOfBucketError';
    
    // Server errors
    const INTERNAL_ERROR = 'InternalError';
    const SERVICE_UNAVAILABLE = 'ServiceUnavailable';
    const SLOW_DOWN = 'SlowDown';
    
    // Multipart Upload errors
    const INVALID_UPLOAD_ID = 'InvalidUploadId';
    
    // Map error codes to HTTP status codes
    private static $httpStatusCodes = [
        self::ACCESS_DENIED => 403,
        self::ACCOUNT_PROBLEM => 403,
        self::BUCKET_ALREADY_EXISTS => 409,
        self::BUCKET_ALREADY_OWNED_BY_YOU => 409,
        self::BUCKET_NOT_EMPTY => 409,
        self::CREDENTIALS_NOT_SUPPORTED => 400,
        self::ENTITY_TOO_LARGE => 400,
        self::ENTITY_TOO_SMALL => 400,
        self::EXPIRED_TOKEN => 400,
        self::INCOMPLETE_BODY => 400,
        self::INTERNAL_ERROR => 500,
        self::INVALID_ACCESS_KEY_ID => 403,
        self::INVALID_ARGUMENT => 400,
        self::INVALID_BUCKET_NAME => 400,
        self::INVALID_BUCKET_STATE => 409,
        self::INVALID_DIGEST => 400,
        self::INVALID_PART => 400,
        self::INVALID_PART_ORDER => 400,
        self::INVALID_RANGE => 416,
        self::INVALID_REQUEST => 400,
        self::INVALID_SECURITY => 403,
        self::INVALID_TOKEN => 400,
        self::INVALID_UPLOAD_ID => 404,
        self::INVALID_URI => 400,
        self::KEY_TOO_LONG_ERROR => 400,
        self::MALFORMED_XML => 400,
        self::METHOD_NOT_ALLOWED => 405,
        self::MISSING_CONTENT_LENGTH => 411,
        self::MISSING_SECURITY_HEADER => 400,
        self::NO_SUCH_BUCKET => 404,
        self::NO_SUCH_KEY => 404,
        self::NO_SUCH_UPLOAD => 404,
        self::NO_SUCH_VERSION => 404,
        self::NOT_IMPLEMENTED => 501,
        self::PRECONDITION_FAILED => 412,
        self::REQUEST_TIME_TOO_SKEWED => 403,
        self::REQUEST_TIMEOUT => 400,
        self::SERVICE_UNAVAILABLE => 503,
        self::SIGNATURE_DOES_NOT_MATCH => 403,
        self::SLOW_DOWN => 503,
        self::TOKEN_REFRESH_REQUIRED => 400,
        self::TOO_MANY_BUCKETS => 400,
    ];
    
    // Map error codes to human-readable messages
    private static $errorMessages = [
        self::ACCESS_DENIED => 'Access Denied',
        self::ACCOUNT_PROBLEM => 'There is a problem with your AWS account that prevents the operation from completing successfully.',
        self::BUCKET_ALREADY_EXISTS => 'The requested bucket name is not available. The bucket namespace is shared by all users of the system.',
        self::BUCKET_ALREADY_OWNED_BY_YOU => 'The bucket you tried to create already exists, and you own it.',
        self::BUCKET_NOT_EMPTY => 'The bucket you tried to delete is not empty.',
        self::CREDENTIALS_NOT_SUPPORTED => 'This request does not support credentials.',
        self::ENTITY_TOO_LARGE => 'Your proposed upload exceeds the maximum allowed object size.',
        self::ENTITY_TOO_SMALL => 'Your proposed upload is smaller than the minimum allowed object size.',
        self::EXPIRED_TOKEN => 'The provided token has expired.',
        self::INCOMPLETE_BODY => 'You did not provide the number of bytes specified by the Content-Length HTTP header.',
        self::INTERNAL_ERROR => 'We encountered an internal error. Please try again.',
        self::INVALID_ACCESS_KEY_ID => 'The AWS Access Key ID you provided does not exist in our records.',
        self::INVALID_ARGUMENT => 'Invalid Argument.',
        self::INVALID_BUCKET_NAME => 'The specified bucket is not valid.',
        self::INVALID_BUCKET_STATE => 'The request is not valid with the current state of the bucket.',
        self::INVALID_DIGEST => 'The Content-MD5 you specified is not valid.',
        self::INVALID_PART => 'One or more of the specified parts could not be found.',
        self::INVALID_PART_ORDER => 'The list of parts was not in ascending order.',
        self::INVALID_RANGE => 'The requested range cannot be satisfied.',
        self::INVALID_REQUEST => 'Invalid Request.',
        self::INVALID_SECURITY => 'The provided security credentials are not valid.',
        self::INVALID_TOKEN => 'The provided token is malformed or otherwise not valid.',
        self::INVALID_UPLOAD_ID => 'The specified multipart upload does not exist.',
        self::INVALID_URI => 'Could not parse the specified URI.',
        self::KEY_TOO_LONG_ERROR => 'Your key is too long.',
        self::MALFORMED_XML => 'The XML you provided was not well-formed or did not validate.',
        self::METHOD_NOT_ALLOWED => 'The specified method is not allowed against this resource.',
        self::MISSING_CONTENT_LENGTH => 'You must provide the Content-Length HTTP header.',
        self::MISSING_SECURITY_HEADER => 'Your request was missing a required header.',
        self::NO_SUCH_BUCKET => 'The specified bucket does not exist.',
        self::NO_SUCH_KEY => 'The specified key does not exist.',
        self::NO_SUCH_UPLOAD => 'The specified multipart upload does not exist.',
        self::NO_SUCH_VERSION => 'Indicates that the version ID specified in the request does not match an existing version.',
        self::NOT_IMPLEMENTED => 'A header you provided implies functionality that is not implemented.',
        self::PRECONDITION_FAILED => 'At least one of the preconditions you specified did not hold.',
        self::REQUEST_TIME_TOO_SKEWED => 'The difference between the request time and the server\'s time is too large.',
        self::REQUEST_TIMEOUT => 'Your socket connection to the server was not read from or written to within the timeout period.',
        self::SERVICE_UNAVAILABLE => 'Please reduce your request rate.',
        self::SIGNATURE_DOES_NOT_MATCH => 'The request signature we calculated does not match the signature you provided.',
        self::SLOW_DOWN => 'Please reduce your request rate.',
        self::TOKEN_REFRESH_REQUIRED => 'The provided token must be refreshed.',
        self::TOO_MANY_BUCKETS => 'You have attempted to create more buckets than allowed.',
    ];
    
    /**
     * Get HTTP status code for an error code
     */
    public static function getHttpStatus($errorCode) {
        return self::$httpStatusCodes[$errorCode] ?? 500;
    }
    
    /**
     * Get default message for an error code
     */
    public static function getMessage($errorCode) {
        return self::$errorMessages[$errorCode] ?? 'An error occurred.';
    }
}

/**
 * Enhanced S3-compatible XML Response System
 */
class S3XmlResponse {
    
    /**
     * Generate request ID (similar to AWS)
     */
    private static function generateRequestId() {
        return strtoupper(bin2hex(random_bytes(8)));
    }
    
    /**
     * Generate host ID (similar to AWS)
     */
    private static function generateHostId() {
        return base64_encode(random_bytes(32));
    }
    
    /**
     * Send S3-compatible error response
     * 
     * @param string $code Error code from S3ErrorCodes
     * @param string|null $message Custom message (optional, defaults to standard message)
     * @param array $additionalElements Additional XML elements like Resource, RequestId
     */
    public static function error($code, $message = null, $additionalElements = []) {
        $httpStatus = S3ErrorCodes::getHttpStatus($code);
        $message = $message ?? S3ErrorCodes::getMessage($code);
        
        http_response_code($httpStatus);
        header('Content-Type: application/xml; charset=utf-8');
        header('x-amz-request-id: ' . self::generateRequestId());
        header('x-amz-id-2: ' . self::generateHostId());
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('Error');
        
        $xml->writeElement('Code', $code);
        $xml->writeElement('Message', $message);
        
        // Add resource if we have bucket/key context
        if (isset($additionalElements['Resource'])) {
            $xml->writeElement('Resource', $additionalElements['Resource']);
            unset($additionalElements['Resource']);
        }
        
        // Add BucketName for bucket-specific errors
        if (isset($additionalElements['BucketName'])) {
            $xml->writeElement('BucketName', $additionalElements['BucketName']);
            unset($additionalElements['BucketName']);
        }
        
        // Add Key for object-specific errors
        if (isset($additionalElements['Key'])) {
            $xml->writeElement('Key', $additionalElements['Key']);
            unset($additionalElements['Key']);
        }
        
        // Request identification
        $xml->writeElement('RequestId', self::generateRequestId());
        $xml->writeElement('HostId', self::generateHostId());
        
        // Add any additional elements
        foreach ($additionalElements as $key => $value) {
            $xml->writeElement($key, $value);
        }
        
        $xml->endElement(); // Error
        $xml->endDocument();
        
        echo $xml->outputMemory();
        
        // Log error for debugging
        error_log("S3 Error Response: Code=$code, Status=$httpStatus, Message=$message");
    }
    
    /**
     * Send S3-compatible XML success response
     * 
     * @param string $rootElement Root element name
     * @param array $data Data to convert to XML
     * @param string $namespace Optional namespace URI
     */
    public static function xml($rootElement, array $data, $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/') {
        header('Content-Type: application/xml; charset=utf-8');
        header('x-amz-request-id: ' . self::generateRequestId());
        header('x-amz-id-2: ' . self::generateHostId());
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement($rootElement);
        if ($namespace) {
            $xml->writeAttribute('xmlns', $namespace);
        }
        
        self::arrayToXml($xml, $data);
        
        $xml->endElement();
        $xml->endDocument();
        
        echo $xml->outputMemory();
    }
    
    /**
     * Recursively convert array to XML
     */
    private static function arrayToXml(XMLWriter $xml, array $data) {
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                // Handle numeric keys as repeated elements
                if (is_array($value)) {
                    self::arrayToXml($xml, $value);
                } else {
                    $xml->text((string)$value);
                }
            } elseif (is_array($value)) {
                // Check if it's a list of items (numeric keys inside)
                if (!empty($value) && isset($value[0])) {
                    foreach ($value as $item) {
                        $xml->startElement($key);
                        if (is_array($item)) {
                            self::arrayToXml($xml, $item);
                        } else {
                            $xml->text((string)$item);
                        }
                        $xml->endElement();
                    }
                } else {
                    $xml->startElement($key);
                    self::arrayToXml($xml, $value);
                    $xml->endElement();
                }
            } else {
                $xml->writeElement($key, (string)$value);
            }
        }
    }
    
    /**
     * Send empty success response (for DELETE operations)
     */
    public static function noContent() {
        http_response_code(204);
        header('Content-Length: 0');
        header('x-amz-request-id: ' . self::generateRequestId());
        header('x-amz-id-2: ' . self::generateHostId());
    }
    
    /**
     * Send success with ETag header (for PUT operations)
     */
    public static function putSuccess($etag, $additionalHeaders = []) {
        http_response_code(200);
        header('ETag: "' . $etag . '"');
        header('Content-Length: 0');
        header('x-amz-request-id: ' . self::generateRequestId());
        header('x-amz-id-2: ' . self::generateHostId());
        
        foreach ($additionalHeaders as $name => $value) {
            header("$name: $value");
        }
    }
}
