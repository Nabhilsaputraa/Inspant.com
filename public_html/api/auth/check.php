<?php
// Prevent direct access
if (!defined('SECURE_ACCESS')) define('SECURE_ACCESS', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/auth_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

try {
    $pdo = getDBConnection();

    if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
        $stmt = $pdo->prepare("
            SELECT us.*, u.username, u.email, u.role, u.full_name, u.profile_picture 
            FROM user_sessions us 
            JOIN users u ON us.user_id = u.id 
            WHERE us.user_id = ? AND us.session_token = ? 
            AND us.expires_at > NOW() AND u.is_active = 1
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            $stmt = $pdo->prepare("UPDATE user_sessions SET updated_at = NOW() WHERE id = ?");
            $stmt->execute([$session['id']]);
            
            $redirect = '/pages/atlet/athlete_dashboard.html';
            if ($session['role'] === 'coach') {
                $redirect = '/pages/coach/coach_dashboard.html';
            } elseif ($session['role'] === 'admin') {
                $redirect = '/pages/admin/dashboard.html';
            }
            
            jsonResponse([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $session['user_id'],
                        'username' => $session['username'],
                        'email' => $session['email'],
                        'role' => $session['role'],
                        'full_name' => $session['full_name'],
                        'profile_picture' => $session['profile_picture']
                    ],
                    'redirect' => $redirect
                ]
            ]);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Not authenticated']);

} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Auth check failed'], 500);
}

?>