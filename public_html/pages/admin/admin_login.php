<?php
// admin_login.php - Separate login handler
session_start();

// Include configuration file
require_once dirname(__DIR__, 3) . '/config.php';

// Error handling configuration
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for login attempts
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt'] = time();
}

// Reset attempts after 15 minutes
if (time() - $_SESSION['last_attempt'] > 900) {
    $_SESSION['login_attempts'] = 0;
}

// Check if too many attempts
if ($_SESSION['login_attempts'] >= 5) {
    $remaining_time = 900 - (time() - $_SESSION['last_attempt']);
    if ($remaining_time > 0) {
        $minutes = ceil($remaining_time / 60);
        $error_message = "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit";
        $is_locked = true;
    }
}

// Check if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin_dashboard.php");
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    
    // CSRF Token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt'] = time();
        $error_message = "Token keamanan tidak valid";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        // Basic validation
        if (empty($username) || empty($password)) {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt'] = time();
            $error_message = "Username dan password harus diisi";
        } else {
            try {
                // Database connection
                $pdo = getDBConnection();
                
                // Get user data - pastikan query benar dan role admin
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active FROM users WHERE username = ? AND role = 'admin' AND is_active = 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify credentials
                if ($user && password_verify($password, $user['password_hash'])) {
                    
                    // Successful login
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['admin_login_time'] = time();
                    
                    // Reset login attempts
                    $_SESSION['login_attempts'] = 0;
                    
                    // Regenerate session ID untuk keamanan
                    session_regenerate_id(true);
                    
                    // Log successful login
                    error_log("Admin login successful: " . $username . " from IP: " . $_SERVER['REMOTE_ADDR']);
                    
                    // Update last login time
                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    header("Location: admin_dashboard.php");
                    exit;
                    
                } else {
                    // Failed login
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt'] = time();
                    
                    // Log failed attempt
                    error_log("Admin login failed: " . $username . " from IP: " . $_SERVER['REMOTE_ADDR']);
                    
                    $error_message = "Username atau password salah, atau akun bukan admin";
                    if ($_SESSION['login_attempts'] >= 5) {
                        $error_message = "Terlalu banyak percobaan gagal. Akun dikunci 15 menit";
                        $is_locked = true;
                    }
                }
                
            } catch (PDOException $e) {
                // Database error
                error_log("Database error in admin login: " . $e->getMessage());
                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Inspant Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #0a0a0a;
            --secondary-bg: #111111;
            --surface-bg: #1a1a1a;
            --elevated-bg: #222222;
            --accent-primary: #3b82f6;
            --accent-secondary: #2563eb;
            --accent-danger: #ef4444;
            --text-primary: #ffffff;
            --text-secondary: #e5e5e5;
            --text-tertiary: #a3a3a3;
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            --transition-base: 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background-image: 
                radial-gradient(600px circle at 20% 30%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
                radial-gradient(400px circle at 80% 70%, rgba(249, 115, 22, 0.03) 0%, transparent 50%);
        }

        .login-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 3rem;
            width: 100%;
            max-width: 420px;
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow-lg);
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .logo span {
            color: aqua;
        }

        .login-subtitle {
            color: var(--text-tertiary);
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1.25rem;
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
            font-family: inherit;
            transition: all var(--transition-base);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: var(--elevated-bg);
        }

        .login-btn {
            width: 100%;
            background: var(--accent-primary);
            color: white;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-base);
            margin-bottom: 1rem;
        }

        .login-btn:hover:not(:disabled) {
            background: var(--accent-secondary);
            transform: translateY(-2px);
        }

        .login-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--accent-danger);
            color: var(--accent-danger);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            text-align: center;
        }

        .login-info {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: var(--text-tertiary);
            text-align: center;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--accent-primary);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-base);
            margin-top: 1rem;
        }

        .back-link:hover {
            color: var(--accent-secondary);
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
                border-radius: 12px;
            }

            .logo {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">Inspant<span>.</span></div>
            <p class="login-subtitle">Admin Dashboard Login</p>
        </div>
        
        <?php if (isset($error_message)): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-group">
                <label class="form-label" for="username">Username:</label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       class="form-input" 
                       required 
                       autocomplete="username"
                       <?php echo (isset($is_locked) && $is_locked) ? 'disabled' : ''; ?>
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label" for="password">Password:</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       class="form-input" 
                       required 
                       autocomplete="current-password"
                       <?php echo (isset($is_locked) && $is_locked) ? 'disabled' : ''; ?>>
            </div>
            
            <button type="submit" 
                    name="admin_login" 
                    class="login-btn"
                    <?php echo (isset($is_locked) && $is_locked) ? 'disabled' : ''; ?>>
                <?php echo (isset($is_locked) && $is_locked) ? 'Terkunci' : 'Login'; ?>
            </button>
        </form>
        
        <div class="login-info">
            <strong>Keamanan:</strong><br>
            • Maksimal 5 percobaan login<br>
            • Akun dikunci 15 menit setelah gagal<br>
            • Session timeout 2 jam
        </div>
        
        <a href="../index.php" class="back-link">
            ← Kembali ke Beranda
        </a>
    </div>
</body>
</html>