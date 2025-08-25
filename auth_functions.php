<?php
// auth_functions.php - Fungsi-fungsi untuk autentikasi

require_once 'config.php';

class AuthManager {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Register user baru
     */
    public function register($data) {
        try {
            // Validasi input
            $validation = $this->validateRegistrationData($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Data tidak valid',
                    'errors' => $validation['errors']
                ];
            }
            
            // Cek apakah username atau email sudah ada
            if ($this->isUserExists($data['username'], $data['email'])) {
                return [
                    'success' => false,
                    'message' => 'Username atau email sudah terdaftar'
                ];
            }
            
            // Hash password
            $passwordHash = hashPassword($data['password']);
            
            // Begin transaction
            $this->pdo->beginTransaction();
            
            // Insert user
            $stmt = $this->pdo->prepare("
                INSERT INTO users (full_name, username, email, phone, password_hash, role, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $data['full_name'],
                $data['username'],
                $data['email'],
                $data['phone'] ?? null,
                $passwordHash,
                $data['role']
            ]);
            
            $userId = $this->pdo->lastInsertId();
            
            // Insert profile berdasarkan role
            if ($data['role'] === 'coach') {
                $this->createCoachProfile($userId, $data);
            } elseif ($data['role'] === 'athlete') {
                $this->createAthleteProfile($userId, $data);
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Registrasi berhasil! Silakan login dengan akun Anda.',
                'user_id' => $userId
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Registration error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat registrasi. Silakan coba lagi.'
            ];
        }
    }
    
    /**
     * Login user
     */
    public function login($username, $password, $remember = false) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // Cek rate limiting
            if (!checkLoginAttempts($ip, $username)) {
                recordLoginAttempt($ip, $username, false);
                return [
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.',
                    'locked' => true
                ];
            }
            
            // Cari user berdasarkan username atau email
            $stmt = $this->pdo->prepare("
                SELECT id, full_name, username, email, password_hash, role, is_active, email_verified 
                FROM users 
                WHERE (username = ? OR email = ?) AND is_active = TRUE
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if (!$user || !verifyPassword($password, $user['password_hash'])) {
                recordLoginAttempt($ip, $username, false);
                return [
                    'success' => false,
                    'message' => 'Username/email atau password salah'
                ];
            }
            
            // Cek apakah akun aktif
            if (!$user['is_active']) {
                recordLoginAttempt($ip, $username, false);
                return [
                    'success' => false,
                    'message' => 'Akun Anda telah dinonaktifkan. Hubungi administrator.'
                ];
            }
            
            // Login berhasil
            recordLoginAttempt($ip, $username, true);
            
            // Buat session
            $sessionToken = $this->createUserSession($user['id'], $remember);
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['last_activity'] = time();
            
            // Log activity
            logActivity($user['id'], 'login', 'User logged in successfully');
            
            return [
                'success' => true,
                'message' => 'Login berhasil',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $user['role']
                    ],
                    'redirect' => $this->getRedirectUrl($user['role'])
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat login. Silakan coba lagi.'
            ];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        try {
            if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
                // Hapus session dari database
                $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
                $stmt->execute([$_SESSION['session_token']]);
                
                // Log activity
                logActivity($_SESSION['user_id'], 'logout', 'User logged out');
            }
            
            // Hapus semua session variables
            session_unset();
            session_destroy();
            
            return [
                'success' => true,
                'message' => 'Logout berhasil'
            ];
            
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat logout'
            ];
        }
    }
    
    /**
     * Cek apakah user sudah login dan session valid
     */
    public function checkAuthentication() {
        if (!isLoggedIn()) {
            return false;
        }
        
        try {
            // Cek session di database
            $stmt = $this->pdo->prepare("
                SELECT user_id, expires_at 
                FROM user_sessions 
                WHERE session_token = ? AND expires_at > NOW()
            ");
            $stmt->execute([$_SESSION['session_token']]);
            $session = $stmt->fetch();
            
            if (!$session || $session['user_id'] != $_SESSION['user_id']) {
                $this->logout();
                return false;
            }
            
            // Update last activity
            $_SESSION['last_activity'] = time();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Auth check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validasi data registrasi
     */
    private function validateRegistrationData($data) {
        $errors = [];
        
        // Validasi nama lengkap
        if (empty($data['full_name']) || strlen($data['full_name']) < 2) {
            $errors['full_name'] = 'Nama lengkap minimal 2 karakter';
        }
        
        // Validasi username
        if (empty($data['username']) || strlen($data['username']) < 3) {
            $errors['username'] = 'Username minimal 3 karakter';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $errors['username'] = 'Username hanya boleh berisi huruf, angka, dan underscore';
        }
        
        // Validasi email
        if (empty($data['email']) || !isValidEmail($data['email'])) {
            $errors['email'] = 'Format email tidak valid';
        }
        
        // Validasi password
        if (empty($data['password']) || strlen($data['password']) < 6) {
            $errors['password'] = 'Password minimal 6 karakter';
        }
        
        // Validasi konfirmasi password
        if ($data['password'] !== $data['confirm_password']) {
            $errors['confirm_password'] = 'Konfirmasi password tidak cocok';
        }
        
        // Validasi role
        if (!in_array($data['role'], ['coach', 'athlete'])) {
            $errors['role'] = 'Role tidak valid';
        }
        
        // Validasi nomor telepon jika ada
        if (!empty($data['phone']) && !preg_match('/^[0-9+\-\s]+$/', $data['phone'])) {
            $errors['phone'] = 'Format nomor telepon tidak valid';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Cek apakah user sudah ada
     */
    private function isUserExists($username, $email) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM users 
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $email]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Buat profile coach
     */
    private function createCoachProfile($userId, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO coach_profiles (user_id, created_at) 
            VALUES (?, NOW())
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Buat profile atlet
     */
    private function createAthleteProfile($userId, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO athlete_profiles (user_id, created_at) 
            VALUES (?, NOW())
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Buat user session
     */
    private function createUserSession($userId, $remember = false) {
        // Generate session token
        $sessionToken = generateSecureToken(64);
        
        // Set expiry time
        $expiryTime = $remember ? 
            date('Y-m-d H:i:s', time() + SESSION_LIFETIME) : 
            date('Y-m-d H:i:s', time() + 3600); // 1 jam
        
        // Insert session
        $stmt = $this->pdo->prepare("
            INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->execute([$userId, $sessionToken, $ip, $userAgent, $expiryTime]);
        
        return $sessionToken;
    }
    
    /**
     * Update last login time
     */
    private function updateLastLogin($userId) {
        $stmt = $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    /**
     * Get redirect URL berdasarkan role
     */
    private function getRedirectUrl($role) {
        switch ($role) {
            case 'admin':
                return '/pages/admin/dashboard.html';
            case 'coach':
                return '/pages/coach/coach_dashboard.html';
            case 'atlet':
                return '/pages/atlet/athlete_dashboard.html';
            default:
                return '/index.html';
        }
    }
    
    /**
     * Get user profile dengan data tambahan
     */
    public function getUserProfile($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.*, 
                       CASE 
                           WHEN u.role = 'coach' THEN 
                               JSON_OBJECT(
                                   'specialization', cp.specialization,
                                   'certification', cp.certification,
                                   'experience_years', cp.experience_years,
                                   'bio', cp.bio,
                                   'hourly_rate', cp.hourly_rate
                               )
                           WHEN u.role = 'athlete' THEN 
                               JSON_OBJECT(
                                   'sport', ap.sport,
                                   'position', ap.position,
                                   'height', ap.height,
                                   'weight', ap.weight,
                                   'blood_type', ap.blood_type,
                                   'medical_conditions', ap.medical_conditions,
                                   'team_name', ap.team_name
                               )
                           ELSE NULL 
                       END as profile_data
                FROM users u
                LEFT JOIN coach_profiles cp ON u.id = cp.user_id AND u.role = 'coach'
                LEFT JOIN athlete_profiles ap ON u.id = ap.user_id AND u.role = 'athlete'
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Get profile error: " . $e->getMessage());
            return false;
        }
    }
}

function createTableIfNotExists(PDO $pdo, string $tableName, string $schema): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$tableName} ({$schema})");
        return true;
    } catch (PDOException $e) {
        error_log("Failed to create table {$tableName}: " . $e->getMessage());
        return false;
    }
}

?>