-- Database: inspant_db
-- Buat database baru
CREATE DATABASE IF NOT EXISTS inspant_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inspant_db;

-- Tabel users untuk menyimpan data pengguna
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'coach', 'atlet') NOT NULL DEFAULT 'atlet',
    email_verified BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    profile_picture VARCHAR(255) NULL,
    date_of_birth DATE NULL,
    gender ENUM('L', 'P') NULL,
    address TEXT NULL,
    emergency_contact VARCHAR(100) NULL,
    emergency_phone VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
);

-- Tabel user_sessions untuk mengelola sesi login
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(128) UNIQUE NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
);

-- Tabel login_attempts untuk mencegah brute force
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_username_time (username, attempted_at)
);

-- Tabel password_resets untuk reset password
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reset_token VARCHAR(128) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (reset_token),
    INDEX idx_expires (expires_at)
);

-- Tabel coach_profiles untuk data khusus coach
CREATE TABLE coach_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    specialization VARCHAR(100) NULL,
    certification TEXT NULL,
    experience_years INT DEFAULT 0,
    bio TEXT NULL,
    hourly_rate DECIMAL(10,2) NULL,
    availability JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Tabel athlete_profiles untuk data khusus atlet
CREATE TABLE athlete_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    sport VARCHAR(100) NULL,
    position VARCHAR(50) NULL,
    height DECIMAL(5,2) NULL,
    weight DECIMAL(5,2) NULL,
    blood_type ENUM('A', 'B', 'AB', 'O') NULL,
    medical_conditions TEXT NULL,
    coach_id INT NULL,
    team_name VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert admin user default
INSERT INTO users (full_name, username, email, password_hash, role, email_verified, is_active) 
VALUES (
    'Administrator', 
    'admin', 
    'admin@inspant.com', 
    '$2y$12$LQv3c1ydiCMwVl/X0swxkOLRKNFdE3Qkz./Vf0ZMgdq.2F9FdwwKq', -- password: admin123
    'admin', 
    TRUE, 
    TRUE
);

-- Insert sample coach
INSERT INTO users (full_name, username, email, password_hash, role, email_verified, is_active) 
VALUES (
    'John Coach', 
    'coach_john', 
    'john@inspant.com', 
    '$2y$12$LQv3c1ydiCMwVl/X0swxkOLRKNFdE3Qkz./Vf0ZMgdq.2F9FdwwKq', -- password: admin123
    'coach', 
    TRUE, 
    TRUE
);

-- Insert coach profile
INSERT INTO coach_profiles (user_id, specialization, experience_years, bio) 
VALUES (
    2, 
    'Basketball Training', 
    5, 
    'Experienced basketball coach with 5 years of professional training experience.'
);

-- Insert sample athlete
INSERT INTO users (full_name, username, email, password_hash, role, email_verified, is_active) 
VALUES (
    'Jane Athlete', 
    'athlete_jane', 
    'jane@inspant.com', 
    '$2y$12$LQv3c1ydiCMwVl/X0swxkOLRKNFdE3Qkz./Vf0ZMgdq.2F9FdwwKq', -- password: admin123
    'atlet', 
    TRUE, 
    TRUE
);

-- Insert athlete profile
INSERT INTO athlete_profiles (user_id, sport, position, height, weight, coach_id) 
VALUES (
    3, 
    'Basketball', 
    'Point Guard', 
    175.00, 
    65.00, 
    2
);

-- Tabel untuk log aktivitas user (optional)
CREATE TABLE user_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at)
);