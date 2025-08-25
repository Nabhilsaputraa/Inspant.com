<?php
// auth_middleware.php - Middleware untuk proteksi halaman

require_once 'config.php';
require_once 'auth_functions.php';

class AuthMiddleware {
    private $auth;
    
    public function __construct() {
        $this->auth = new AuthManager();
    }
    
    /**
     * Proteksi halaman - redirect jika tidak login
     */
    public function protectPage($requiredRole = null) {
        // Cek apakah user sudah login
        if (!$this->auth->checkAuthentication()) {
            $this->redirectToLogin();
            return false;
        }
        
        // Cek role jika diperlukan
        if ($requiredRole && !$this->hasRole($requiredRole)) {
            $this->redirectToAccessDenied();
            return false;
        }
        
        return true;
    }
    
    /**
     * Proteksi API - return JSON error jika tidak login
     */
    public function protectAPI($requiredRole = null) {
        if (!$this->auth->checkAuthentication()) {
            jsonResponse([
                'success' => false,
                'message' => 'Sesi telah berakhir. Silakan login kembali.',
                'code' => 'UNAUTHORIZED'
            ], 401);
        }
        
        if ($requiredRole && !$this->hasRole($requiredRole)) {
            jsonResponse([
                'success' => false,
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses resource ini.',
                'code' => 'FORBIDDEN'
            ], 403);
        }
        
        return true;
    }
    
    /**
     * Cek apakah user sudah login (untuk halaman login/register)
     */
    public function redirectIfAuthenticated($redirectTo = '/dashboard.html') {
        if ($this->auth->checkAuthentication()) {
            // Redirect ke dashboard sesuai role
            $role = $_SESSION['role'] ?? 'atlet';
            switch ($role) {
                case 'admin':
                    redirect('/pages/admin/dashboard.html');
                    break;
                case 'coach':
                    redirect('/pages/coach/coach_dashboard.html');
                    break;
                case 'atlet':
                    redirect('/pages/atlet/athlete_dashboard.html');
                    break;
                default:
                    redirect($redirectTo);
            }
        }
    }
    
    /**
     * Get current user data
     */
    public function getCurrentUser() {
        if (!$this->auth->checkAuthentication()) {
            return null;
        }
        
        return $this->auth->getUserProfile($_SESSION['user_id']);
    }
    
    /**
     * Cek role user
     */
    private function hasRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
    
    /**
     * Redirect ke halaman login
     */
    private function redirectToLogin() {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $loginUrl = '/login.html';
        
        if (!empty($currentUrl) && $currentUrl !== '/') {
            $loginUrl .= '?redirect=' . urlencode($currentUrl);
        }
        
        redirect($loginUrl);
    }
    
    /**
     * Redirect ke halaman access denied
     */
    private function redirectToAccessDenied() {
        redirect('/access-denied.html');
    }
}

// Fungsi helper untuk penggunaan yang mudah
function requireLogin($role = null) {
    $middleware = new AuthMiddleware();
    return $middleware->protectPage($role);
}

function requireAPI($role = null) {
    $middleware = new AuthMiddleware();
    return $middleware->protectAPI($role);
}

function getCurrentUser() {
    $middleware = new AuthMiddleware();
    return $middleware->getCurrentUser();
}

function redirectIfLoggedIn($redirectTo = '/dashboard.html') {
    $middleware = new AuthMiddleware();
    return $middleware->redirectIfAuthenticated($redirectTo);
}

?>