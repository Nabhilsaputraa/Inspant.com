<?php
declare(strict_types=1);

// ===== FILE: api/auth/test.php (SECURE VERSION) =====

// Prevent direct access
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Set error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Validate base directory
$baseDir = dirname(__DIR__, 3);
if (!is_dir($baseDir)) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'System configuration error',
        'error' => 'Invalid base directory'
    ]));
}

// Include necessary files with validation
$requiredFiles = [
    $baseDir . '/config.php',
    $baseDir . '/auth_functions.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file) || !is_readable($file)) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'message' => 'System configuration error',
            'error' => 'Missing or unreadable file: ' . basename($file)
        ]));
    }
    require_once $file;
}

// Initialize session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'name' => 'SECURE_SESSION',
        'cookie_lifetime' => 86400,
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true
    ]);
}

// Security headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . (isDevelopment() ? '*' : 'https://yourdomain.com'));
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');

/**
 * Main execution block
 */
try {
    // Validate application configuration
    if (!function_exists('getAppConfig')) {
        throw new RuntimeException('System function not available');
    }

    $appConfig = getAppConfig();
    if (!is_array($appConfig) || !isset($appConfig['env'])) {
        throw new RuntimeException('Invalid application configuration');
    }

    // Environment check
    if (!function_exists('isTestEnvironment')) {
        throw new RuntimeException('Environment check function not available');
    }

    if (!isTestEnvironment($appConfig['env'])) {
        throw new RuntimeException('Test endpoint not available in current environment');
    }

    // Database connection
    if (!function_exists('getDBConnection')) {
        throw new RuntimeException('Database function not available');
    }

    $pdo = getDBConnection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection failed');
    }

    // Prepared statement with type checking
    $query = "SELECT COUNT(*) as user_count FROM users WHERE created_at > :date";
    $stmt = $pdo->prepare($query);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare SQL statement');
    }

    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    if (!$stmt->execute([':date' => $thirtyDaysAgo])) {
        throw new RuntimeException('Failed to execute SQL query');
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($result) || !isset($result['user_count'])) {
        throw new RuntimeException('Invalid query result');
    }

    // Table validation
    if (!function_exists('getAllowedTables') || !function_exists('tableExists')) {
        throw new RuntimeException('Table validation functions not available');
    }

    $allowedTables = getAllowedTables();
    if (!is_array($allowedTables)) {
        throw new RuntimeException('Invalid table configuration');
    }

    $existingTables = [];
    foreach ($allowedTables as $table) {
        if (is_string($table) && tableExists($pdo, $table)) {
            $existingTables[] = $table;
        }
    }

    // Activity logging
    if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        if (!function_exists('logActivity')) {
            throw new RuntimeException('Logging function not available');
        }

        $userId = (int)$_SESSION['user_id'];
        logActivity($userId, 'system_test', 'Database connection test performed');
    }

    // Prepare response data
    $responseData = [
        'environment' => $appConfig['env'],
        'database_status' => 'connected',
        'active_users_last_30_days' => (int)$result['user_count'],
        'available_tables' => $existingTables,
        'table_count' => count($existingTables),
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true)
    ];

    // Send response
    if (!function_exists('jsonResponse')) {
        throw new RuntimeException('Response function not available');
    }

    jsonResponse([
        'success' => true,
        'message' => 'System test successful',
        'data' => $responseData
    ]);

} catch (Throwable $e) {
    // Error handling
    error_log("System Test Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    $response = [
        'success' => false,
        'message' => 'System test failed',
        'error' => 'Internal server error'
    ];

    if (function_exists('isDevelopment') && isDevelopment()) {
        $response['error'] = $e->getMessage();
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }

    if (function_exists('jsonResponse')) {
        jsonResponse($response, 500);
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode($response);
    }
}