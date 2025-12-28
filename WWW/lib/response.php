<?php
/**
 * S3 XML Response Helpers
 */

class S3Response {
    public static function xml($rootElement, $data) {
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo self::arrayToXml($data, $rootElement);
    }
    
    public static function error($code, $message, $httpCode = 400) {
        http_response_code($httpCode);
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo "<Error>\n<Code>$code</Code>\n<Message>" . htmlspecialchars($message) . "</Message>\n</Error>";
    }
    
    private static function arrayToXml($data, $rootElement) {
        $xml = "<$rootElement>\n";
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value[0])) {
                    foreach ($value as $item) {
                        $xml .= self::arrayToXml($item, $key);
                    }
                } else {
                    $xml .= self::arrayToXml($value, $key);
                }
            } else {
                $xml .= "  <$key>" . htmlspecialchars($value ?? '') . "</$key>\n";
            }
        }
        return $xml . "</$rootElement>\n";
    }
}
