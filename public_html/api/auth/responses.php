// ===== FILE: api/utils/response.php =====
<?php
// Utility functions for API responses

class ApiResponse {
    public static function success($message = 'Success', $data = null) {
        http_response_code(200);
        return json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }
    
    public static function error($message = 'Error', $code = 400, $details = null) {
        http_response_code($code);
        return json_encode([
            'success' => false,
            'message' => $message,
            'error' => $details,
            'timestamp' => date('c')
        ]);
    }
    
    public static function unauthorized($message = 'Unauthorized') {
        return self::error($message, 401);
    }
    
    public static function forbidden($message = 'Forbidden') {
        return self::error($message, 403);
    }
    
    public static function notFound($message = 'Not found') {
        return self::error($message, 404);
    }
    
    public static function serverError($message = 'Internal server error') {
        return self::error($message, 500);
    }
}
?>