<?php
// ===== FILE: api/auth/login.php (SECURE VERSION) =====

declare(strict_types=1);

// Prevent direct access
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Include validation
$baseDir = dirname(__DIR__, 3);
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
            'error' => 'Missing required file: ' . basename($file)
        ]));
    }
    require_once $file;
}

// Initialize session securely
if (session_status() === PHP_SESSION_NONE) {
    // cek apakah di localhost (http) atau server (https)
    $isLocal = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']);
    session_start([
        'name' => 'SECURE_SESSION',
        'cookie_lifetime' => SESSION_LIFETIME,
        'cookie_secure' => !$isLocal ? true : false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax', // 🔥 ubah ini
        'use_strict_mode' => true
    ]);
}

// Security headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . (isDevelopment() ? '*' : APP_URL));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Method not allowed'
    ], 405);
}

try {
    // Get and validate JSON input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        jsonResponse([
            'success' => false,
            'message' => 'No data received'
        ], 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON format'
        ], 400);
    }

    // Sanitize and validate input
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $remember = (bool)($input['remember'] ?? false);

    if (empty($username) || empty($password)) {
        jsonResponse([
            'success' => false,
            'message' => 'Username and password are required'
        ], 400);
    }

    if (strlen($username) < 3) {
        jsonResponse([
            'success' => false,
            'message' => 'Username must be at least 3 characters'
        ], 400);
    }

    if (strlen($password) < 6) {
        jsonResponse([
            'success' => false,
            'message' => 'Password must be at least 6 characters'
        ], 400);
    }

    // Get client IP
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Check login attempts
    if (!checkLoginAttempts($ip, $username)) {
        jsonResponse([
            'success' => false,
            'message' => 'Too many login attempts. Please try again later.'
        ], 429);
    }

    // Database operations
    $pdo = getDBConnection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection failed');
    }

    // Get user data
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash, role, is_active, 
               email_verified, profile_picture, full_name
        FROM users
        WHERE (username = :username OR email = :email)
        LIMIT 1
    ");
    $stmt->execute([':username' => $username, ':email' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify credentials
    $loginSuccess = false;
    if ($user && $user['is_active'] && verifyPassword($password, $user['password_hash'])) {
        $loginSuccess = true;
        
        // Generate session token
        $sessionToken = generateSecureToken();
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        // Create session
        $sessionStmt = $pdo->prepare("
            INSERT INTO user_sessions 
            (user_id, session_token, ip_address, user_agent, expires_at)
            VALUES (:user_id, :token, :ip, :ua, :expires)
        ");
        $sessionStmt->execute([
            ':user_id' => $user['id'],
            ':token' => $sessionToken,
            ':ip' => $ip,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':expires' => $expiresAt
        ]);

        // Set session variables (hanya di sini, setelah login sukses)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['user_logged_in'] = true;

        // Set remember me cookie if requested
        if ($remember) {
            $rememberToken = generateSecureToken();
            $cookieParams = session_get_cookie_params();
            setcookie(
                'remember_token',
                $rememberToken,
                time() + (30 * 24 * 60 * 60),
                '/',
                $cookieParams['domain'],
                true,
                true
            );
        }

        // Update last login
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        // Log activity
        logActivity($user['id'], 'login_success', 'User logged in');

        // Prepare response
        $response = [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'full_name' => $user['full_name'],
                    'profile_picture' => $user['profile_picture']
                ]
            ]
        ];

        // Add redirect based on role
        switch ($user['role']) {
            case 'admin':
                $response['data']['redirect'] = './pages/admin/admin_dashboard.php';
                break;
            case 'coach':
                $response['data']['redirect'] = './pages/coach/coach_dashboard.php';
                break;
            case 'athlete':
                $response['data']['redirect'] = './pages/atlet/athlete-dashboard.php';
                break;
            default:
                $response['data']['redirect'] = 'dashboard.html';
        }

        jsonResponse($response);
    }

    // Record failed attempt
    recordLoginAttempt($ip, $username, false);
    jsonResponse([
        'success' => false,
        'message' => 'Invalid username or password'
    ], 401);

} catch (Throwable $e) {
    error_log("Login Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    $response = [
        'success' => false,
        'message' => 'An error occurred during login'
    ];
    
    if (isDevelopment()) {
        $response['error'] = $e->getMessage();
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
    }
    
    jsonResponse($response, 500);
}

?>