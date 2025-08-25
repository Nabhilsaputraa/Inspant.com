// ===== FILE: create_database.php (Database Setup Script) =====
<?php
// Script untuk membuat database dan tabel yang diperlukan
// Jalankan sekali untuk setup database

define('SECURE_ACCESS', true);

header('Content-Type: application/json');

try {
    // Koneksi tanpa database untuk membuat database
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buat database jika belum ada
    $pdo->exec("CREATE DATABASE IF NOT EXISTS inspant_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE inspant_db");
    
    // Buat tabel users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20),
            role ENUM('admin', 'coach', 'athlete', 'atlet') NOT NULL DEFAULT 'atlet',
            email_verified BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            profile_picture VARCHAR(255),
            date_of_birth DATE,
            gender ENUM('male', 'female', 'other'),
            address TEXT,
            emergency_contact VARCHAR(100),
            emergency_phone VARCHAR(20),
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_email (email),
            INDEX idx_role (role),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB
    ");

    // Tambahkan ini di create_database.php setelah tabel users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS coach_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            specialization VARCHAR(255),
            certification VARCHAR(255),
            experience_years INT,
            bio TEXT,
            hourly_rate DECIMAL(10,2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS athlete_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sport VARCHAR(100),
            position VARCHAR(100),
            height DECIMAL(5,2),
            weight DECIMAL(5,2),
            blood_type VARCHAR(5),
            medical_conditions TEXT,
            team_name VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB
    ");
    
    // Buat tabel user_sessions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_token VARCHAR(64) NOT NULL UNIQUE,
            ip_address VARCHAR(45),
            user_agent TEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token (session_token),
            INDEX idx_user_id (user_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB
    ");
    
    // Buat tabel login_attempts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(255),
            success BOOLEAN NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_address, attempted_at),
            INDEX idx_username_time (username, attempted_at)
        ) ENGINE=InnoDB
    ");
    
    // Buat tabel user_activities
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
    ");
    
    // Buat user test
    $testUsers = [
        [
            'username' => 'admin',
            'email' => 'admin@inspant.com',
            'password' => 'admin123',
            'full_name' => 'Administrator',
            'role' => 'admin'
        ],
        [
            'username' => 'coach1',
            'email' => 'coach@inspant.com',
            'password' => 'coach123',
            'full_name' => 'Coach Test',
            'role' => 'coach'
        ],
        [
            'username' => 'atlet1',
            'email' => 'atlet@inspant.com',
            'password' => 'atlet123',
            'full_name' => 'Atlet Test',
            'role' => 'atlet'
        ]
    ];
    
    $created = [];
    foreach ($testUsers as $user) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$user['username'], $user['email']]);
            
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, role, is_active, email_verified) 
                    VALUES (?, ?, ?, ?, ?, 1, 1)
                ");
                $stmt->execute([
                    $user['username'],
                    $user['email'],
                    password_hash($user['password'], PASSWORD_BCRYPT),
                    $user['full_name'],
                    $user['role']
                ]);
                
                $created[] = [
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'password' => $user['password'],
                    'role' => $user['role']
                ];
            }
        } catch (Exception $e) {
            // Skip if user already exists
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Database and tables created successfully',
        'created_users' => $created
    ], JSON_PRETTY_PRINT);
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database setup failed',
        'error' => $e->getMessage()
    ]);
}
?>