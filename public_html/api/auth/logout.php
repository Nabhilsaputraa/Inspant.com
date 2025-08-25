// ===== FILE: api/auth/logout.php (SECURE VERSION) =====
<?php
// Prevent direct access
if (!defined('SECURE_ACCESS')) define('SECURE_ACCESS', true);

// Include required files
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/auth_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Credentials: true');

try {
    $pdo = getDBConnection();

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $session_token = $_SESSION['session_token'] ?? null;
        
        // Deactivate current session
        if ($session_token) {
            try {
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
                $stmt->execute([$session_token]);
            } catch (PDOException $e) {
                error_log("Session deletion failed: " . $e->getMessage());
            }
        }
        
        // Log activity
        logActivity($user_id, 'logout', 'User logged out');
        
        // Clear remember token cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    }

    // Destroy session
    session_destroy();

    jsonResponse(['success' => true, 'message' => 'Logout berhasil']);

} catch (Exception $e) {
    error_log("Logout error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Logout failed'], 500);
}