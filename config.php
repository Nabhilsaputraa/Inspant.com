<?php
// config.php - Konfigurasi database dan pengaturan aplikasi

// Mulai session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'inspant_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // Sesuaikan dengan password MySQL Anda

// Konfigurasi Aplikasi
define('APP_NAME', 'Inspant');
define('APP_URL', 'http://localhost/inspant'); // Sesuaikan dengan URL aplikasi Anda
define('UPLOAD_DIR', 'assets/uploads/');

// Konfigurasi Keamanan
define('JWT_SECRET', 'your_jwt_secret_key_here_change_this'); // Ganti dengan key yang aman
define('BCRYPT_COST', 12);
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 hari
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 menit

// Konfigurasi Email (untuk reset password)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'noreply@inspant.com');
define('SMTP_FROM_NAME', 'Inspant System');

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Error reporting (matikan di production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fungsi koneksi database dengan PDO
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log error dan tampilkan pesan umum
            error_log("Database connection failed: " . $e->getMessage());
            
            // Jika database tidak exist, coba buat
            try {
                $pdo_temp = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, $options);
                $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e2) {
                die("Koneksi database gagal. Silakan coba lagi nanti.");
            }
        }
    }
    
    return $pdo;
}

// Fungsi untuk membersihkan input
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Fungsi untuk validasi email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Fungsi untuk generate token aman
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Fungsi untuk hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

// Fungsi untuk verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Fungsi untuk mengecek apakah user sudah login
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['session_token']);
}

// Fungsi untuk mengecek role user
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Fungsi untuk redirect
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Fungsi untuk response JSON
function jsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Fungsi untuk log aktivitas
function logActivity($user_id, $action, $details = null) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO user_activities (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->execute([$user_id, $action, $details, $ip, $user_agent]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

// CSRF Protection
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateSecureToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting untuk login attempts
function checkLoginAttempts($ip, $username = null) {
    $pdo = getDBConnection();
    
    // Cek attempts berdasarkan IP
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempts 
        FROM login_attempts 
        WHERE ip_address = ? 
        AND success = FALSE 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, LOGIN_LOCKOUT_TIME]);
    $ip_attempts = $stmt->fetchColumn();
    
    if ($ip_attempts >= MAX_LOGIN_ATTEMPTS) {
        return false;
    }
    
    // Cek attempts berdasarkan username jika ada
    if ($username) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE username = ? 
            AND success = FALSE 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$username, LOGIN_LOCKOUT_TIME]);
        $username_attempts = $stmt->fetchColumn();
        
        if ($username_attempts >= MAX_LOGIN_ATTEMPTS) {
            return false;
        }
    }
    
    return true;
}

// Record login attempt
function recordLoginAttempt($ip, $username, $success) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, username, success, attempted_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$ip, $username, $success]);
    } catch (Exception $e) {
        error_log("Failed to record login attempt: " . $e->getMessage());
    }
}

// Cleanup expired sessions dan tokens
function cleanupExpiredData() {
    try {
        $pdo = getDBConnection();
        
        // Hapus session yang expired
        $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");
        
        // Hapus password reset tokens yang expired
        $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW()");
        
        // Hapus login attempts yang lama (lebih dari 24 jam)
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
    } catch (Exception $e) {
        error_log("Failed to cleanup expired data: " . $e->getMessage());
    }
}

// Jalankan cleanup secara acak (1% chance)
if (rand(1, 100) === 1) {
    cleanupExpiredData();
}

/* ====================================================== */
/* FUNGSI TAMBAHAN UNTUK MENDUKUNG test.php */
/* ====================================================== */

function isDevelopment(): bool {
    return ($_SERVER['SERVER_NAME'] === 'localhost' || 
            strpos($_SERVER['SERVER_NAME'], '.test') !== false || 
            strpos($_SERVER['SERVER_NAME'], 'dev.') === 0);
}

function getAppConfig(): array {
    return [
        'env' => isDevelopment() ? 'development' : 'production',
        'db' => [
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS
        ],
        'security' => [
            'jwt_secret' => JWT_SECRET,
            'bcrypt_cost' => BCRYPT_COST
        ]
    ];
}

function isTestEnvironment(string $env = null): bool {
    $env = $env ?? (getAppConfig()['env'] ?? 'production');
    $testEnvs = ['development', 'testing', 'staging'];
    return in_array(strtolower($env), $testEnvs, true);
}

function getAllowedTables(): array {
    return [
        'users',
        'user_activities',
        'login_attempts',
        'password_resets',
        'sessions'
    ];
}

function tableExists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM information_schema.tables 
            WHERE table_schema = ? 
            AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([DB_NAME, $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Table check error: " . $e->getMessage());
        return false;
    }
}

// Global database connection
$GLOBALS['pdo'] = null;

/**
 * Get database connection
 * @return PDO
 */
function getDB() {
    if ($GLOBALS['pdo'] === null) {
        $GLOBALS['pdo'] = getDBConnection();
    }
    return $GLOBALS['pdo'];
}

/**
 * Execute query with parameters
 * @param string $query
 * @param array $params
 * @return PDOStatement
 */
function executeQuery($query, $params = []) {
    $db = getDB();
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch single row
 * @param string $query
 * @param array $params
 * @return array|false
 */
function fetchRow($query, $params = []) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetch();
}

/**
 * Fetch all rows
 * @param string $query
 * @param array $params
 * @return array
 */
function fetchAll($query, $params = []) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetchAll();
}

/**
 * Insert data
 * @param string $table
 * @param array $data
 * @return int Last insert ID
 */
function insertData($table, $data) {
    $db = getDB();
    $columns = implode(',', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));
    
    $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    $stmt = $db->prepare($query);
    $stmt->execute($data);
    
    return $db->lastInsertId();
}

/**
 * Update data
 * @param string $table
 * @param array $data
 * @param string $where
 * @param array $whereParams
 * @return int Affected rows
 */
function updateData($table, $data, $where, $whereParams = []) {
    $db = getDB();
    $setParts = [];
    
    foreach (array_keys($data) as $column) {
        $setParts[] = "{$column} = :{$column}";
    }
    
    $setClause = implode(', ', $setParts);
    $query = "UPDATE {$table} SET {$setClause} WHERE {$where}";
    
    $params = array_merge($data, $whereParams);
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    return $stmt->rowCount();
}

/**
 * Delete data
 * @param string $table
 * @param string $where
 * @param array $params
 * @return int Affected rows
 */
function deleteData($table, $where, $params = []) {
    $db = getDB();
    $query = "DELETE FROM {$table} WHERE {$where}";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    return $stmt->rowCount();
}

/* ====================================================== */
/* FUNGSI CONTENT UNTUK DASHBOARD - TAMBAHAN BARU */
/* ====================================================== */

// FUNCTIONS UNTUK WEBSITE (diambil dari database)
function getHeroContent() {
    try {
        $query = "SELECT * FROM hero_content WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
        $result = fetchRow($query);
        
        if (!$result) {
            // Default content jika tidak ada di database
            return [
                'page_title' => 'Inspant - Sports Analytics Platform',
                'badge_icon' => '🏆',
                'badge_text' => 'Sports Analytics Platform',
                'title' => 'Revolutionize Your Sports Performance',
                'subtitle' => 'Unlock championship-level insights with our cutting-edge sports analytics platform. Track, analyze, and optimize performance with real-time data visualization and AI-powered insights.',
                'cta_text' => 'Explore Analytics'
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("getHeroContent error: " . $e->getMessage());
        return [
            'page_title' => 'Inspant - Sports Analytics Platform',
            'badge_icon' => '🏆',
            'badge_text' => 'Sports Analytics Platform',
            'title' => 'Revolutionize Your Sports Performance',
            'subtitle' => 'Unlock championship-level insights with our cutting-edge sports analytics platform.',
            'cta_text' => 'Get Started'
        ];
    }
}

function getFeatures() {
    try {
        $query = "SELECT * FROM features WHERE is_active = 1 ORDER BY sort_order ASC, id ASC";
        $results = fetchAll($query);
        
        if (empty($results)) {
            // Default features jika tidak ada di database
            return [
                [
                    'icon' => '📊',
                    'title' => 'Real-Time Performance Tracking',
                    'description' => 'Monitor athlete performance in real-time with advanced biometric tracking, movement analysis, and instant feedback systems.',
                    'features' => json_encode([
                        'Live biometric monitoring',
                        'Movement pattern analysis',
                        'Heart rate & endurance tracking',
                        'Instant performance alerts',
                        'Recovery time optimization'
                    ]),
                    'coming_soon' => false
                ],
                [
                    'icon' => '🎯',
                    'title' => 'AI-Powered Analytics',
                    'description' => 'Advanced machine learning algorithms provide predictive insights and personalized training recommendations.',
                    'features' => json_encode([
                        'Predictive performance modeling',
                        'Injury risk assessment',
                        'Training load optimization',
                        'Performance trend analysis',
                        'Custom AI recommendations'
                    ]),
                    'coming_soon' => false
                ],
                [
                    'icon' => '📱',
                    'title' => 'Mobile Companion',
                    'description' => 'Stay connected with your training data anywhere with our comprehensive mobile application.',
                    'features' => json_encode([
                        'Real-time sync across devices',
                        'Offline data collection',
                        'Push notifications',
                        'Quick data entry',
                        'Coach-athlete communication'
                    ]),
                    'coming_soon' => true
                ]
            ];
        }
        
        return $results;
    } catch (Exception $e) {
        error_log("getFeatures error: " . $e->getMessage());
        return [];
    }
}

function getPlatformCards() {
    try {
        $query = "SELECT * FROM platform_cards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC";
        $results = fetchAll($query);
        
        if (empty($results)) {
            // Default platform cards jika tidak ada di database
            return [
                [
                    'icon' => '🌐',
                    'title' => 'Web Dashboard',
                    'description' => 'Comprehensive web-based analytics dashboard with full-screen visualizations, detailed reports, and team management tools.',
                    'features' => json_encode([
                        'Full-screen data visualization',
                        'Interactive charts & graphs',
                        'Team management interface',
                        'Export & sharing tools',
                        'Real-time collaboration'
                    ]),
                    'coming_soon' => false
                ],
                [
                    'icon' => '📱',
                    'title' => 'Mobile App',
                    'description' => 'Native mobile application for iOS and Android with offline capabilities and real-time data synchronization.',
                    'features' => json_encode([
                        'Offline data collection',
                        'Real-time synchronization',
                        'Push notifications',
                        'Camera-based analysis',
                        'GPS tracking integration'
                    ]),
                    'coming_soon' => true
                ],
                [
                    'icon' => '💻',
                    'title' => 'Desktop Suite',
                    'description' => 'Professional desktop application for advanced analytics, detailed reporting, and comprehensive data management.',
                    'features' => json_encode([
                        'Advanced statistical analysis',
                        'High-resolution visualizations',
                        'Bulk data processing',
                        'Custom report generation',
                        'Integration with external tools'
                    ]),
                    'coming_soon' => true
                ]
            ];
        }
        
        return $results;
    } catch (Exception $e) {
        error_log("getPlatformCards error: " . $e->getMessage());
        return [];
    }
}

function getInsightsTabs() {
    try {
        $query = "SELECT * FROM insights_tabs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC";
        $results = fetchAll($query);
        
        if (empty($results)) {
            // Default tabs jika tidak ada di database
            return [
                ['name' => 'Performance', 'slug' => 'performance'],
                ['name' => 'Research', 'slug' => 'research'],
                ['name' => 'Trends', 'slug' => 'trends'],
                ['name' => 'Updates', 'slug' => 'updates']
            ];
        }
        
        return $results;
    } catch (Exception $e) {
        error_log("getInsightsTabs error: " . $e->getMessage());
        return [
            ['name' => 'Performance', 'slug' => 'performance'],
            ['name' => 'Research', 'slug' => 'research'],
            ['name' => 'Trends', 'slug' => 'trends'],
            ['name' => 'Updates', 'slug' => 'updates']
        ];
    }
}

function getArticlesByCategory() {
    try {
        $query = "SELECT a.*, t.slug as tab_slug 
                  FROM articles a 
                  LEFT JOIN insights_tabs t ON a.tab_id = t.id 
                  WHERE a.is_active = 1 AND t.is_active = 1 
                  ORDER BY a.date_published DESC, a.id DESC";
        $results = fetchAll($query);
        
        $articles = [];
        foreach ($results as $article) {
            $tab_slug = $article['tab_slug'] ?: 'performance';
            if (!isset($articles[$tab_slug])) {
                $articles[$tab_slug] = [];
            }
            $articles[$tab_slug][] = $article;
        }
        
        // Default articles jika tidak ada di database
        if (empty($articles)) {
            $articles = [
                'performance' => [
                    [
                        'icon' => '🏃‍♂️',
                        'category' => 'Performance',
                        'date_published' => date('Y-m-d'),
                        'title' => 'Optimizing Sprint Performance Through Data Analytics',
                        'excerpt' => 'Discover how elite athletes are using biomechanical analysis and performance data to shave milliseconds off their sprint times.',
                        'link_url' => '#',
                        'link_text' => 'Read analysis'
                    ],
                    [
                        'icon' => '⚽',
                        'category' => 'Performance',
                        'date_published' => date('Y-m-d', strtotime('-2 days')),
                        'title' => 'Soccer Analytics: Measuring Player Efficiency',
                        'excerpt' => 'Advanced metrics for evaluating soccer player performance beyond traditional statistics like goals and assists.',
                        'link_url' => '#',
                        'link_text' => 'View insights'
                    ]
                ],
                'research' => [
                    [
                        'icon' => '🔬',
                        'category' => 'Research',
                        'date_published' => date('Y-m-d', strtotime('-1 day')),
                        'title' => 'The Science of Recovery: Data-Driven Rest Protocols',
                        'excerpt' => 'Latest research on how data analytics can optimize recovery periods and prevent overtraining in professional athletes.',
                        'link_url' => '#',
                        'link_text' => 'Read study'
                    ]
                ],
                'trends' => [
                    [
                        'icon' => '📈',
                        'category' => 'Trends',
                        'date_published' => date('Y-m-d', strtotime('-3 days')),
                        'title' => 'AI in Sports: The Future of Athletic Performance',
                        'excerpt' => 'Exploring how artificial intelligence and machine learning are revolutionizing sports analytics and athlete development.',
                        'link_url' => '#',
                        'link_text' => 'Explore trends'
                    ]
                ],
                'updates' => [
                    [
                        'icon' => '🚀',
                        'category' => 'Updates',
                        'date_published' => date('Y-m-d'),
                        'title' => 'Platform Update: New Visualization Features',
                        'excerpt' => 'Introducing enhanced data visualization tools and improved user interface for better analytics experience.',
                        'link_url' => '#',
                        'link_text' => 'See updates'
                    ]
                ]
            ];
        }
        
        return $articles;
    } catch (Exception $e) {
        error_log("getArticlesByCategory error: " . $e->getMessage());
        return [];
    }
}

function getAboutCards() {
    try {
        $query = "SELECT * FROM about_cards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC";
        $results = fetchAll($query);
        
        if (empty($results)) {
            // Default about cards jika tidak ada di database
            return [
                [
                    'icon' => '🎯',
                    'title' => 'Performance Excellence',
                    'description' => 'Dedicated to helping athletes achieve peak performance through data-driven insights, personalized training programs, and cutting-edge analytics tools.'
                ],
                [
                    'icon' => '🔬',
                    'title' => 'Scientific Innovation',
                    'description' => 'Pioneering research in sports science, biomechanics, and performance analytics to push the boundaries of what\'s possible in athletic achievement.'
                ],
                [
                    'icon' => '🤝',
                    'title' => 'Community Impact',
                    'description' => 'Committed to democratizing sports analytics and making professional-grade tools accessible to athletes and coaches worldwide.'
                ]
            ];
        }
        
        return $results;
    } catch (Exception $e) {
        error_log("getAboutCards error: " . $e->getMessage());
        return [];
    }
}

// Initialize database connection pada startup
try {
    getDBConnection();
} catch (Exception $e) {
    error_log("Database initialization failed: " . $e->getMessage());
}

function createContentTables() {
    try {
        $pdo = getDBConnection();
        
        $createTables = "
        CREATE TABLE IF NOT EXISTS hero_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_title VARCHAR(255) NOT NULL,
            badge_icon VARCHAR(10) DEFAULT '🏆',
            badge_text VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            subtitle TEXT NOT NULL,
            cta_text VARCHAR(100) NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS features (
            id INT AUTO_INCREMENT PRIMARY KEY,
            icon VARCHAR(10) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            features JSON NOT NULL,
            coming_soon BOOLEAN DEFAULT FALSE,
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS platform_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            icon VARCHAR(10) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            features JSON NOT NULL,
            coming_soon BOOLEAN DEFAULT FALSE,
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS insights_tabs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tab_id INT,
            icon VARCHAR(10) NOT NULL,
            category VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            excerpt TEXT NOT NULL,
            link_url VARCHAR(500) NOT NULL,
            link_text VARCHAR(100) NOT NULL,
            date_published DATE DEFAULT (CURRENT_DATE),
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tab_id) REFERENCES insights_tabs(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS about_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            icon VARCHAR(10) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        ";
        
        // Execute table creation
        $queries = array_filter(array_map('trim', explode(';', $createTables)));
        foreach ($queries as $query) {
            if (!empty($query)) {
                $pdo->exec($query);
            }
        }
        
        // Insert default data
        insertDefaultContent();
        
        return true;
    } catch(PDOException $e) {
        error_log("Table creation error: " . $e->getMessage());
        return false;
    }
}

function insertDefaultContent() {
    try {
        $pdo = getDBConnection();
        
        // Insert default hero content
        $stmt = $pdo->query("SELECT COUNT(*) FROM hero_content");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO hero_content (page_title, badge_text, title, subtitle, cta_text) VALUES 
                ('Inspant - Sports Analytics Platform', 'Sports Analytics Platform', 'Revolutionize Your Sports Performance', 'Unlock championship-level insights with our cutting-edge sports analytics platform. Track, analyze, and optimize performance with real-time data visualization and AI-powered insights.', 'Explore Analytics')
            ");
        }
        
        // Insert default tabs
        $stmt = $pdo->query("SELECT COUNT(*) FROM insights_tabs");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO insights_tabs (name, slug, sort_order) VALUES 
                ('Performance', 'performance', 1),
                ('Research', 'research', 2),
                ('Trends', 'trends', 3),
                ('Updates', 'updates', 4)
            ");
        }
        
        // Insert default features
        $stmt = $pdo->query("SELECT COUNT(*) FROM features");
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO features (icon, title, description, features, coming_soon, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            
            $defaultFeatures = [
                [
                    '📊',
                    'Real-Time Performance Tracking',
                    'Monitor athlete performance in real-time with advanced biometric tracking, movement analysis, and instant feedback systems.',
                    json_encode(['Live biometric monitoring', 'Movement pattern analysis', 'Heart rate & endurance tracking', 'Instant performance alerts', 'Recovery time optimization']),
                    0,
                    1
                ],
                [
                    '🎯',
                    'AI-Powered Analytics',
                    'Advanced machine learning algorithms provide predictive insights and personalized training recommendations.',
                    json_encode(['Predictive performance modeling', 'Injury risk assessment', 'Training load optimization', 'Performance trend analysis', 'Custom AI recommendations']),
                    0,
                    2
                ],
                [
                    '📱',
                    'Mobile Companion',
                    'Stay connected with your training data anywhere with our comprehensive mobile application.',
                    json_encode(['Real-time sync across devices', 'Offline data collection', 'Push notifications', 'Quick data entry', 'Coach-athlete communication']),
                    1,
                    3
                ]
            ];
            
            foreach ($defaultFeatures as $feature) {
                $stmt->execute($feature);
            }
        }
        
    } catch(PDOException $e) {
        error_log("Default content insertion error: " . $e->getMessage());
    }
}

// Auto-create tables when config is loaded
if (!defined('SKIP_AUTO_SETUP')) {
    createContentTables();
}

$admin_config = [
    'password_hash' => password_hash('admin123', PASSWORD_DEFAULT), // Change this password!
    'session_timeout' => 86400, // 24 hours
    'max_login_attempts' => 5,
    'lockout_time' => 300, // 5 minutes
    'csrf_token_lifetime' => 3600 // 1 hour
];

?>