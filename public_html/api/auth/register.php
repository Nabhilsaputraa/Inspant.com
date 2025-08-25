<?php
// api/auth/register.php - Fixed version with error handling

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set headers first
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false, 
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// Function to send JSON response and exit
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Function to log errors safely
function logError($message) {
    error_log(date('Y-m-d H:i:s') . " - Register API Error: " . $message);
}

try {
    // Check if required files exist
    $configPath = dirname(__DIR__, 3) . '/config.php';
    $authPath   = dirname(__DIR__, 3) . '/auth_functions.php';

    if (!file_exists($configPath)) {
        logError("config.php not found at: " . $configPath);
        sendJsonResponse([
            'success' => false,
            'message' => 'Konfigurasi sistem tidak ditemukan. Hubungi administrator.',
            'debug' => 'Config file missing'
        ], 500);
    }
    
    if (!file_exists($authPath)) {
        logError("auth_functions.php not found at: " . $authPath);
        sendJsonResponse([
            'success' => false,
            'message' => 'Komponen autentikasi tidak ditemukan. Hubungi administrator.',
            'debug' => 'Auth functions missing'
        ], 500);
    }
    
    // Include required files
    require_once $configPath;
    require_once $authPath;
    
    // Get and validate JSON input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Tidak ada data yang diterima.'
        ], 400);
    }
    
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("JSON decode error: " . json_last_error_msg());
        sendJsonResponse([
            'success' => false,
            'message' => 'Format data tidak valid.',
            'debug' => 'Invalid JSON: ' . json_last_error_msg()
        ], 400);
    }
    
    // Validate required fields
    $requiredFields = ['full_name', 'username', 'email', 'password', 'confirm_password', 'role'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Field berikut wajib diisi: ' . implode(', ', $missingFields),
            'errors' => array_fill_keys($missingFields, 'Field ini wajib diisi')
        ], 400);
    }
    
    // Sanitize and prepare data
    $data = [
        'full_name' => trim($input['full_name']),
        'username' => trim($input['username']),
        'email' => trim($input['email']),
        'phone' => isset($input['phone']) ? trim($input['phone']) : null,
        'password' => $input['password'],
        'confirm_password' => $input['confirm_password'],
        'role' => trim($input['role'])
    ];
    
    // Additional validation
    if (strlen($data['full_name']) < 2) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Nama lengkap minimal 2 karakter.'
        ], 400);
    }
    
    if (strlen($data['username']) < 3) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Username minimal 3 karakter.'
        ], 400);
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Format email tidak valid.'
        ], 400);
    }
    
    if (strlen($data['password']) < 6) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Password minimal 6 karakter.'
        ], 400);
    }
    
    if ($data['password'] !== $data['confirm_password']) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Konfirmasi password tidak cocok.'
        ], 400);
    }
    
    if (!in_array($data['role'], ['coach', 'athlete'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Role harus coach atau athlete.'
        ], 400);
    }
    
    // Check if username pattern is valid
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Username hanya boleh berisi huruf, angka, dan underscore.'
        ], 400);
    }
    
    // Rate limiting check
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Simple rate limiting - you can enhance this
    if (function_exists('getDBConnection')) {
        try {
            $pdo = getDBConnection();
            
            // Check registration attempts from this IP in last hour
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM users 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(JSON_OBJECT('ip', ?), '{}'), '$.ip')) = ?
            ");
            $stmt->execute([$ip, $ip]);
            $recentCount = $stmt->fetchColumn();
            
            if ($recentCount >= 5) {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'Terlalu banyak registrasi dari IP ini. Coba lagi dalam 1 jam.'
                ], 429);
            }
        } catch (Exception $e) {
            logError("Rate limiting check failed: " . $e->getMessage());
            // Continue without rate limiting if check fails
        }
    }
    
    // Process registration
    if (!class_exists('AuthManager')) {
        logError("AuthManager class not found");
        sendJsonResponse([
            'success' => false,
            'message' => 'Sistem autentikasi tidak tersedia.',
            'debug' => 'AuthManager class missing'
        ], 500);
    }
    
    $auth = new AuthManager();
    $result = $auth->register($data);
    
    // Log successful registration
    if ($result['success']) {
        logError("New user registered successfully: " . $data['username'] . " (" . $data['role'] . ")");
    } else {
        logError("Registration failed for " . $data['username'] . ": " . $result['message']);
    }
    
    // Send response
    $statusCode = $result['success'] ? 201 : 400;
    sendJsonResponse($result, $statusCode);
    
} catch (PDOException $e) {
    logError("Database error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Terjadi kesalahan database. Silakan coba lagi.',
        'debug' => 'Database connection failed'
    ], 500);
    
} catch (Exception $e) {
    logError("General error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
        'debug' => 'Server error: ' . $e->getMessage()
    ], 500);
}
?>