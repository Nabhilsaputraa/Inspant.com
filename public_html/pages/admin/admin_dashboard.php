
<?php
// admin_dashboard.php - Enhanced with proper login protection and complete features
session_start();

// Include configuration file
require_once dirname(__DIR__, 3) . '/config.php';

// Error handling configuration
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===== ADMIN AUTHENTICATION CHECK =====
function isValidAdminSession() {
    // Check if admin_logged_in session exists and is true
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    
    // Check if user_id exists
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    
    // Check if role is admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        return false;
    }
    
    // Check session timeout (2 hours)
    if (isset($_SESSION['admin_login_time'])) {
        $session_timeout = 2 * 60 * 60; // 2 hours
        if (time() - $_SESSION['admin_login_time'] > $session_timeout) {
            return false;
        }
    }
    
    try {
        // Verify with database that user is still active and admin
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user !== false;
        
    } catch (PDOException $e) {
        error_log("Database error in admin verification: " . $e->getMessage());
        return false;
    }
}

// Check admin authentication
if (!isValidAdminSession()) {
    // Destroy invalid session
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// Update last activity
$_SESSION['last_activity'] = time();

// Regenerate session ID periodically (every 5 minutes)
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} else if (time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Handle logout
if (isset($_GET['logout'])) {
    error_log("Admin logout: " . $_SESSION['username'] . " from IP: " . $_SERVER['REMOTE_ADDR']);
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database connection
try {
    $pdo = getDBConnection();
    $db_status = "Connected";
    $db_status_class = "success";
} catch(PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db_status = "Connection Error";
    $db_status_class = "error";
    $pdo = null;
}

// Admin-specific helper functions
function adminSanitizeInput($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) ?: 0;
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) ?: '';
        case 'url':
            return filter_var($input, FILTER_VALIDATE_URL) ?: '';
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Tambahkan fungsi helper untuk debug
function debugTablesStructure($pdo) {
    try {
        // Check insights_tabs table
        $stmt = $pdo->query("DESCRIBE insights_tabs");
        $tabsStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("insights_tabs structure: " . json_encode($tabsStructure));
        
        // Check articles table
        $stmt = $pdo->query("DESCRIBE articles");
        $articlesStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("articles structure: " . json_encode($articlesStructure));
        
        // Check foreign key constraints
        $stmt = $pdo->query("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE REFERENCED_TABLE_NAME = 'insights_tabs'
        ");
        $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Foreign key constraints: " . json_encode($constraints));
        
        // Check data counts
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM insights_tabs WHERE is_active = 1");
        $tabsCount = $stmt->fetchColumn();
        error_log("Active insights_tabs count: " . $tabsCount);
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM articles WHERE is_active = 1");
        $articlesCount = $stmt->fetchColumn();
        error_log("Active articles count: " . $articlesCount);
        
    } catch (PDOException $e) {
        error_log("Error in debugTablesStructure: " . $e->getMessage());
    }
}

// Panggil fungsi debug jika dalam mode development
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    debugTablesStructure($pdo);
}

// Admin JSON response helper
function adminJsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Enhanced AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        adminJsonResponse(false, 'Security token mismatch');
    }
    
    if (!$pdo) {
        adminJsonResponse(false, 'Database connection error');
    }
    
    try {
        $action = adminSanitizeInput($_POST['action']);
        
        switch ($action) {
            case 'save_hero':
                $data = [
                    'page_title' => adminSanitizeInput($_POST['page_title'] ?? ''),
                    'badge_text' => adminSanitizeInput($_POST['badge_text'] ?? ''),
                    'title' => adminSanitizeInput($_POST['title'] ?? ''),
                    'subtitle' => adminSanitizeInput($_POST['subtitle'] ?? ''),
                    'cta_text' => adminSanitizeInput($_POST['cta_text'] ?? '')
                ];
                
                if (empty($data['title'])) {
                    adminJsonResponse(false, 'Title is required');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO hero_content (page_title, badge_text, title, subtitle, cta_text) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    page_title = VALUES(page_title),
                    badge_text = VALUES(badge_text),
                    title = VALUES(title),
                    subtitle = VALUES(subtitle),
                    cta_text = VALUES(cta_text),
                    updated_at = CURRENT_TIMESTAMP
                ");
                
                $result = $stmt->execute(array_values($data));
                adminJsonResponse($result, $result ? 'Hero content saved successfully' : 'Failed to save hero content');
                break;
                
            case 'get_hero':
                $stmt = $pdo->query("SELECT * FROM hero_content WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
                $hero = $stmt->fetch();
                adminJsonResponse(true, 'Hero data retrieved', $hero);
                break;
                
            case 'save_feature':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                $features = $_POST['features'] ?? [];
                
                // Validate and sanitize features array
                if (is_array($features)) {
                    $features = array_map(function($feature) {
                        return adminSanitizeInput($feature);
                    }, $features);
                } else {
                    $features = [];
                }
                
                $data = [
                    'icon' => adminSanitizeInput($_POST['icon'] ?? ''),
                    'title' => adminSanitizeInput($_POST['title'] ?? ''),
                    'description' => adminSanitizeInput($_POST['description'] ?? ''),
                    'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                    'coming_soon' => adminSanitizeInput($_POST['coming_soon'] ?? 0, 'int'),
                    'sort_order' => adminSanitizeInput($_POST['sort_order'] ?? 0, 'int')
                ];
                
                if (empty($data['title'])) {
                    adminJsonResponse(false, 'Feature title is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE features 
                        SET icon = ?, title = ?, description = ?, features = ?, coming_soon = ?, sort_order = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO features (icon, title, description, features, coming_soon, sort_order) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                adminJsonResponse($result, $result ? 'Feature saved successfully' : 'Failed to save feature');
                break;
                
            case 'get_features':
                $stmt = $pdo->query("SELECT * FROM features WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                $features = $stmt->fetchAll();
                adminJsonResponse(true, 'Features retrieved', $features);
                break;
                
            case 'delete_feature':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    adminJsonResponse(false, 'Invalid feature ID');
                }
                
                $stmt = $pdo->prepare("UPDATE features SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                adminJsonResponse($result, $result ? 'Feature deleted successfully' : 'Failed to delete feature');
                break;
                
            case 'save_platform':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                $features = $_POST['features'] ?? [];
                
                if (is_array($features)) {
                    $features = array_map(function($feature) {
                        return adminSanitizeInput($feature);
                    }, $features);
                } else {
                    $features = [];
                }
                
                $data = [
                    'icon' => adminSanitizeInput($_POST['icon'] ?? ''),
                    'title' => adminSanitizeInput($_POST['title'] ?? ''),
                    'description' => adminSanitizeInput($_POST['description'] ?? ''),
                    'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                    'coming_soon' => adminSanitizeInput($_POST['coming_soon'] ?? 0, 'int'),
                    'sort_order' => adminSanitizeInput($_POST['sort_order'] ?? 0, 'int')
                ];
                
                if (empty($data['title'])) {
                    adminJsonResponse(false, 'Platform title is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE platform_cards 
                        SET icon = ?, title = ?, description = ?, features = ?, coming_soon = ?, sort_order = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO platform_cards (icon, title, description, features, coming_soon, sort_order) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                adminJsonResponse($result, $result ? 'Platform saved successfully' : 'Failed to save platform');
                break;
                
            case 'get_platforms':
                $stmt = $pdo->query("SELECT * FROM platform_cards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                $platforms = $stmt->fetchAll();
                adminJsonResponse(true, 'Platforms retrieved', $platforms);
                break;
                
            case 'delete_platform':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    adminJsonResponse(false, 'Invalid platform ID');
                }
                
                $stmt = $pdo->prepare("UPDATE platform_cards SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                adminJsonResponse($result, $result ? 'Platform deleted successfully' : 'Failed to delete platform');
                break;

            // ===== INSIGHTS TABS ACTIONS =====
            case 'get_insights_tabs':
                $stmt = $pdo->query("SELECT * FROM insights_tabs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                $tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Debug log
                error_log("get_insights_tabs query result: " . json_encode($tabs));
                
                adminJsonResponse(true, 'Insights tabs retrieved', $tabs);
                break;
                
            case 'save_insights_tab':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                $data = [
                    'name' => adminSanitizeInput($_POST['name'] ?? ''),
                    'slug' => adminSanitizeInput($_POST['slug'] ?? ''),
                    'sort_order' => adminSanitizeInput($_POST['sort_order'] ?? 0, 'int')
                ];
                
                if (empty($data['name'])) {
                    adminJsonResponse(false, 'Tab name is required');
                }
                
                // Generate slug if empty
                if (empty($data['slug'])) {
                    $data['slug'] = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['name']));
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE insights_tabs 
                        SET name = ?, slug = ?, sort_order = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO insights_tabs (name, slug, sort_order) 
                        VALUES (?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                adminJsonResponse($result, $result ? 'Insights tab saved successfully' : 'Failed to save insights tab');
                break;
                
            case 'delete_insights_tab':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    adminJsonResponse(false, 'Invalid tab ID');
                }
                
                $stmt = $pdo->prepare("UPDATE insights_tabs SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                adminJsonResponse($result, $result ? 'Insights tab deleted successfully' : 'Failed to delete insights tab');
                break;

            // ===== ARTICLES ACTIONS =====
            case 'get_tabs':
                $stmt = $pdo->query("SELECT * FROM insights_tabs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                $tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Debug log
                error_log("get_tabs query result: " . json_encode($tabs));
                
                adminJsonResponse(true, 'Tabs retrieved', $tabs);
                break;
                
            case 'save_article':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                $data = [
                    'tab_id' => adminSanitizeInput($_POST['tab_id'] ?? 0, 'int'),
                    'icon' => adminSanitizeInput($_POST['icon'] ?? ''),
                    'category' => adminSanitizeInput($_POST['category'] ?? ''),
                    'title' => adminSanitizeInput($_POST['title'] ?? ''),
                    'excerpt' => adminSanitizeInput($_POST['excerpt'] ?? ''),
                    'link_url' => adminSanitizeInput($_POST['link_url'] ?? '', 'url'),
                    'link_text' => adminSanitizeInput($_POST['link_text'] ?? ''),
                    'sort_order' => adminSanitizeInput($_POST['sort_order'] ?? 0, 'int')
                ];
                
                // Debug log
                error_log("save_article data: " . json_encode($data));
                
                if (empty($data['title'])) {
                    adminJsonResponse(false, 'Article title is required');
                }
                
                if (empty($data['tab_id']) || $data['tab_id'] <= 0) {
                    adminJsonResponse(false, 'Please select a valid insights tab');
                }
                
                try {
                    // Verify that tab_id exists
                    $tabCheck = $pdo->prepare("SELECT id FROM insights_tabs WHERE id = ? AND is_active = 1");
                    $tabCheck->execute([$data['tab_id']]);
                    if (!$tabCheck->fetch()) {
                        adminJsonResponse(false, 'Selected tab does not exist or is inactive');
                    }
                    
                    if ($id > 0) {
                        // Update existing article
                        $stmt = $pdo->prepare("
                            UPDATE articles 
                            SET tab_id = ?, icon = ?, category = ?, title = ?, excerpt = ?, link_url = ?, link_text = ?, sort_order = ?
                            WHERE id = ?
                        ");
                        $params = array_values($data);
                        $params[] = $id;
                        $result = $stmt->execute($params);
                        
                        error_log("Updated article ID $id, result: " . ($result ? 'success' : 'failed'));
                    } else {
                        // Insert new article
                        $stmt = $pdo->prepare("
                            INSERT INTO articles (tab_id, icon, category, title, excerpt, link_url, link_text, sort_order) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $result = $stmt->execute(array_values($data));
                        
                        $newId = $pdo->lastInsertId();
                        error_log("Inserted new article ID $newId, result: " . ($result ? 'success' : 'failed'));
                    }
                    
                    adminJsonResponse($result, $result ? 'Article saved successfully' : 'Failed to save article');
                    
                } catch (PDOException $e) {
                    error_log("Database error in save_article: " . $e->getMessage());
                    adminJsonResponse(false, 'Database error: ' . $e->getMessage());
                }
                break;
                
            case 'get_articles':
                try {
                    $stmt = $pdo->query("
                        SELECT a.*, t.name as tab_name, t.slug as tab_slug 
                        FROM articles a 
                        LEFT JOIN insights_tabs t ON a.tab_id = t.id 
                        WHERE a.is_active = 1 
                        ORDER BY a.date_published DESC, a.sort_order ASC, a.id DESC
                    ");
                    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Debug log
                    error_log("get_articles query result count: " . count($articles));
                    error_log("get_articles sample: " . json_encode(array_slice($articles, 0, 2)));
                    
                    adminJsonResponse(true, 'Articles retrieved', $articles);
                } catch (PDOException $e) {
                    error_log("Error in get_articles: " . $e->getMessage());
                    adminJsonResponse(false, 'Database error: ' . $e->getMessage());
                }
                break;
                
            case 'delete_article':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    adminJsonResponse(false, 'Invalid article ID');
                }
                
                $stmt = $pdo->prepare("UPDATE articles SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                adminJsonResponse($result, $result ? 'Article deleted successfully' : 'Failed to delete article');
                break;
                
            case 'save_about':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                $data = [
                    'icon' => adminSanitizeInput($_POST['icon'] ?? ''),
                    'title' => adminSanitizeInput($_POST['title'] ?? ''),
                    'description' => adminSanitizeInput($_POST['description'] ?? ''),
                    'sort_order' => adminSanitizeInput($_POST['sort_order'] ?? 0, 'int')
                ];
                
                if (empty($data['title'])) {
                    adminJsonResponse(false, 'About card title is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE about_cards 
                        SET icon = ?, title = ?, description = ?, sort_order = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO about_cards (icon, title, description, sort_order) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                adminJsonResponse($result, $result ? 'About card saved successfully' : 'Failed to save about card');
                break;
                
            case 'get_about':
                $stmt = $pdo->query("SELECT * FROM about_cards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                $about = $stmt->fetchAll();
                adminJsonResponse(true, 'About cards retrieved', $about);
                break;
                
            case 'delete_about':
                $id = adminSanitizeInput($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    adminJsonResponse(false, 'Invalid about card ID');
                }
                
                $stmt = $pdo->prepare("UPDATE about_cards SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                adminJsonResponse($result, $result ? 'About card deleted successfully' : 'Failed to delete about card');
                break;
                
            default:
                adminJsonResponse(false, 'Unknown action');
        }
    } catch (Exception $e) {
        error_log("Admin dashboard error: " . $e->getMessage());
        adminJsonResponse(false, 'An error occurred: ' . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Inspant Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
            :root {
                --primary-bg: #0a0a0a;
                --secondary-bg: #111111;
                --surface-bg: #1a1a1a;
                --elevated-bg: #222222;
                --accent-primary: #3b82f6;
                --accent-secondary: #2563eb;
                --accent-success: #10b981;
                --accent-danger: #ef4444;
                --accent-warning: #f59e0b;
                --text-primary: #ffffff;
                --text-secondary: #e5e5e5;
                --text-tertiary: #a3a3a3;
                --text-quaternary: #737373;
                --glass-bg: rgba(255, 255, 255, 0.05);
                --glass-border: rgba(255, 255, 255, 0.1);
                --glass-hover: rgba(255, 255, 255, 0.08);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
                --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
                --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
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
                line-height: 1.6;
                overflow-x: hidden;
                -webkit-font-smoothing: antialiased;
            }

            .background-layer {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: -1;
                background: 
                    radial-gradient(600px circle at 20% 30%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
                    radial-gradient(400px circle at 80% 70%, rgba(249, 115, 22, 0.03) 0%, transparent 50%);
            }

            .dashboard-container {
                display: flex;
                min-height: 100vh;
            }

            .sidebar {
                width: 300px;
                background: var(--glass-bg);
                border-right: 1px solid var(--glass-border);
                backdrop-filter: blur(20px);
                padding: 2rem 0;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
                z-index: 1000;
                box-shadow: var(--shadow-lg);
                transition: all var(--transition-base);
            }

            .sidebar-header {
                padding: 0 2rem 2rem;
                border-bottom: 1px solid var(--glass-border);
                margin-bottom: 2rem;
            }

            .logo {
                font-size: 1.75rem;
                font-weight: 800;
                color: var(--text-primary);
                margin-bottom: 0.5rem;
            }

            .logo span {
                color: aqua;
            }

            .sidebar-subtitle {
                color: var(--text-tertiary);
                font-size: 0.85rem;
                margin-bottom: 1rem;
                font-weight: 500;
            }

            .sidebar-status {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.75rem 1rem;
                background: var(--surface-bg);
                border-radius: 8px;
                margin-bottom: 1rem;
                font-size: 0.8rem;
                border: 1px solid var(--glass-border);
            }

            .status-success { color: var(--accent-success); }
            .status-error { color: var(--accent-danger); }

            .admin-info {
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 8px;
                padding: 0.75rem 1rem;
                margin-bottom: 1rem;
                font-size: 0.8rem;
            }

            .logout-btn {
                background: var(--accent-danger);
                color: white;
                padding: 0.75rem 1rem;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 0.9rem;
                font-weight: 600;
                width: 100%;
                transition: all var(--transition-base);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }

            .logout-btn:hover {
                background: #dc2626;
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
            }

            .nav-menu {
                list-style: none;
                padding: 0 1rem;
            }

            .nav-item {
                margin-bottom: 0.5rem;
            }

            .nav-link {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 1rem 1.5rem;
                color: var(--text-tertiary);
                text-decoration: none;
                transition: all var(--transition-base);
                font-weight: 500;
                cursor: pointer;
                border-radius: 12px;
                background: transparent;
                border: 1px solid transparent;
            }

            .nav-link:hover,
            .nav-link.active {
                background: var(--glass-hover);
                color: var(--accent-primary);
                border-color: var(--glass-border);
                transform: translateX(4px);
                box-shadow: var(--shadow-md);
            }

            .nav-link.active {
                background: var(--accent-primary);
                color: white;
            }

            .nav-icon {
                font-size: 1.25rem;
                width: 24px;
                text-align: center;
            }

            .main-content {
                flex: 1;
                margin-left: 300px;
                padding: 2rem;
                min-height: 100vh;
            }

            .content-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2rem;
                padding: 1.5rem 2rem;
                background: var(--glass-bg);
                border: 1px solid var(--glass-border);
                border-radius: 16px;
                backdrop-filter: blur(10px);
                box-shadow: var(--shadow-md);
            }

            .page-title {
                font-size: 2rem;
                font-weight: 700;
                color: var(--text-primary);
            }

            .content-section {
                display: none;
                animation: fadeIn 0.6s ease;
            }

            .content-section.active {
                display: block;
            }

            @keyframes fadeIn {
                from { 
                    opacity: 0; 
                    transform: translateY(20px); 
                }
                to { 
                    opacity: 1; 
                    transform: translateY(0); 
                }
            }

            .content-card {
                background: var(--glass-bg);
                border: 1px solid var(--glass-border);
                border-radius: 16px;
                padding: 2rem;
                margin-bottom: 2rem;
                backdrop-filter: blur(10px);
                box-shadow: var(--shadow-md);
                transition: all var(--transition-base);
            }

            .content-card:hover {
                background: var(--glass-hover);
                border-color: var(--accent-primary);
                transform: translateY(-4px);
                box-shadow: var(--shadow-xl);
            }

            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--glass-border);
            }

            .card-title {
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--text-primary);
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }

            .form-grid.single {
                grid-template-columns: 1fr;
            }

            .form-group {
                margin-bottom: 1.5rem;
            }

            .form-label {
                display: block;
                font-weight: 600;
                color: var(--text-secondary);
                margin-bottom: 0.75rem;
                font-size: 0.95rem;
            }

            .form-input,
            .form-textarea,
            .form-select {
                width: 100%;
                padding: 1rem 1.25rem;
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                color: var(--text-primary);
                font-size: 0.95rem;
                font-family: inherit;
                transition: all var(--transition-base);
            }

            .form-input:focus,
            .form-textarea:focus,
            .form-select:focus {
                outline: none;
                border-color: var(--accent-primary);
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
                background: var(--elevated-bg);
            }

            .form-textarea {
                min-height: 120px;
                resize: vertical;
            }

            .btn {
                padding: 0.875rem 1.5rem;
                border: none;
                border-radius: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all var(--transition-base);
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.95rem;
                font-family: inherit;
            }

            .btn-primary {
                background: var(--accent-primary);
                color: white;
                box-shadow: var(--shadow-md);
            }

            .btn-primary:hover {
                background: var(--accent-secondary);
                transform: translateY(-2px);
                box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);
            }

            .btn-success {
                background: var(--accent-success);
                color: white;
                box-shadow: var(--shadow-md);
            }

            .btn-success:hover {
                background: #059669;
                transform: translateY(-2px);
                box-shadow: 0 8px 16px rgba(16, 185, 129, 0.3);
            }

            .btn-danger {
                background: var(--accent-danger);
                color: white;
                box-shadow: var(--shadow-md);
            }

            .btn-danger:hover {
                background: #dc2626;
                transform: translateY(-2px);
                box-shadow: 0 8px 16px rgba(239, 68, 68, 0.3);
            }

            .status-message {
                padding: 1rem 1.5rem;
                border-radius: 12px;
                margin-bottom: 1.5rem;
                display: none;
                font-weight: 500;
                animation: slideDown 0.3s ease;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .status-success {
                background: rgba(16, 185, 129, 0.1);
                border: 1px solid var(--accent-success);
                color: var(--accent-success);
            }

            .status-error {
                background: rgba(239, 68, 68, 0.1);
                border: 1px solid var(--accent-danger);
                color: var(--accent-danger);
            }

            .switch {
                position: relative;
                display: inline-block;
                width: 54px;
                height: 28px;
            }

            .switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: var(--glass-border);
                transition: .4s;
                border-radius: 28px;
                border: 1px solid var(--glass-border);
            }

            .slider:before {
                position: absolute;
                content: "";
                height: 20px;
                width: 20px;
                left: 4px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            input:checked + .slider {
                background-color: var(--accent-primary);
                border-color: var(--accent-primary);
            }

            input:checked + .slider:before {
                transform: translateX(26px);
            }

            .item-card {
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                padding: 1.5rem;
                margin: 1rem 0;
                transition: all var(--transition-base);
            }

            .item-card:hover {
                background: var(--elevated-bg);
                border-color: var(--accent-primary);
                transform: translateY(-2px);
                box-shadow: var(--shadow-lg);
            }

            .item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--glass-border);
            }

            .item-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: var(--text-primary);
            }

            .item-actions {
                display: flex;
                gap: 0.5rem;
            }

            .loading {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid var(--glass-border);
                border-radius: 50%;
                border-top-color: var(--accent-primary);
                animation: spin 1s ease-in-out infinite;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            .mobile-toggle {
                display: none;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: var(--accent-primary);
                color: white;
                border: none;
                padding: 0.75rem;
                border-radius: 8px;
                cursor: pointer;
                font-size: 1.25rem;
            }

            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }

            .mobile-overlay.active {
                display: block;
            }

            @media (max-width: 968px) {
                .sidebar {
                    transform: translateX(-100%);
                    transition: transform var(--transition-base);
                }
                
                .sidebar.mobile-open {
                    transform: translateX(0);
                }
                
                .main-content {
                    margin-left: 0;
                    padding: 1rem;
                }
                
                .mobile-toggle {
                    display: block;
                }

                .form-grid {
                    grid-template-columns: 1fr;
                }

                .content-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                    padding: 1rem;
                }

                .page-title {
                    font-size: 1.5rem;
                }
            }

            @media (max-width: 480px) {
                .content-card,
                .item-card {
                    padding: 1rem;
                    border-radius: 12px;
                }

                .card-header,
                .item-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }

                .btn {
                    width: 100%;
                    justify-content: center;
                }

                .item-actions {
                    width: 100%;
                    justify-content: center;
                }
            }

            /* ===== CLASS BARU KHUSUS UNTUK INSIGHTS - TANPA EFEK 3D ===== */

            /* Class baru untuk insights cards tanpa efek 3D */
            .insights-flat-card {
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                padding: 1.5rem;
                margin: 1rem 0;
                /* HANYA transisi warna - TANPA transform dan box-shadow 3D */
                transition: background-color 0.3s ease, border-color 0.3s ease;
                position: relative;
            }

            .insights-flat-card:hover {
                background: var(--elevated-bg);
                border-color: var(--accent-primary);
                /* TANPA efek 3D: transform: translateY(-2px) dan box-shadow berlebihan */
            }

            /* Header untuk insights flat card */
            .insights-flat-card .item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--glass-border);
                transition: border-color 0.3s ease;
            }

            .insights-flat-card:hover .item-header {
                border-bottom-color: rgba(59, 130, 246, 0.3);
            }

            /* Title untuk insights flat card */
            .insights-flat-card .item-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: var(--text-primary);
                transition: color 0.3s ease;
            }

            .insights-flat-card:hover .item-title {
                color: var(--accent-primary);
                /* TANPA text-shadow berlebihan */
            }

            /* Actions untuk insights flat card */
            .insights-flat-card .item-actions {
                display: flex;
                gap: 0.5rem;
                transition: opacity 0.3s ease;
                /* TANPA transform yang menyebabkan pergeseran */
            }

            /* Buttons khusus untuk insights flat card */
            .insights-flat-card .btn {
                padding: 0.75rem 1.25rem;
                border: none;
                border-radius: 10px;
                font-weight: 600;
                cursor: pointer;
                /* HANYA transisi background - TANPA transform */
                transition: background-color 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.9rem;
                font-family: inherit;
                position: relative;
                overflow: hidden;
            }

            .insights-flat-card .btn-success {
                background: var(--accent-success);
                color: white;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
            }

            .insights-flat-card .btn-success:hover {
                background: #059669;
                /* TANPA transform: translateY(-1px) dan box-shadow berlebihan */
            }

            .insights-flat-card .btn-danger {
                background: var(--accent-danger);
                color: white;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
            }

            .insights-flat-card .btn-danger:hover {
                background: #dc2626;
                /* TANPA transform: translateY(-1px) dan box-shadow berlebihan */
            }

            /* Form elements khusus untuk insights flat card */
            .insights-flat-card .form-input,
            .insights-flat-card .form-textarea,
            .insights-flat-card .form-select {
                width: 100%;
                padding: 1rem 1.25rem;
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                color: var(--text-primary);
                font-size: 0.95rem;
                font-family: inherit;
                /* Transisi sederhana tanpa transform */
                transition: border-color 0.3s ease, background-color 0.3s ease;
            }

            .insights-flat-card .form-input:focus,
            .insights-flat-card .form-textarea:focus,
            .insights-flat-card .form-select:focus {
                outline: none;
                border-color: var(--accent-primary);
                background: var(--elevated-bg);
                /* Box-shadow minimal tanpa transform */
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }

            .insights-flat-card .form-label {
                display: block;
                font-weight: 600;
                color: var(--text-secondary);
                margin-bottom: 0.75rem;
                font-size: 0.95rem;
                transition: color 0.3s ease;
            }

            .insights-flat-card .form-group:focus-within .form-label {
                color: var(--accent-primary);
            }

            /* Switch khusus untuk insights flat card */
            .insights-flat-card .switch {
                position: relative;
                display: inline-block;
                width: 54px;
                height: 28px;
            }

            .insights-flat-card .switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .insights-flat-card .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: var(--glass-border);
                /* Transisi sederhana */
                transition: background-color 0.4s ease;
                border-radius: 28px;
                border: 1px solid var(--glass-border);
            }

            .insights-flat-card .slider:before {
                position: absolute;
                content: "";
                height: 20px;
                width: 20px;
                left: 4px;
                bottom: 3px;
                background: #ffffff;
                /* HANYA transform transition */
                transition: transform 0.4s ease;
                border-radius: 50%;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            .insights-flat-card input:checked + .slider {
                background: var(--accent-primary);
                border-color: var(--accent-primary);
            }

            .insights-flat-card input:checked + .slider:before {
                transform: translateX(26px);
            }

            /* Responsive untuk insights flat card */
            @media (max-width: 768px) {
                .insights-flat-card {
                    margin: 0.5rem 0;
                    padding: 1rem;
                }
                
                .insights-flat-card .item-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }
                
                .insights-flat-card .item-actions {
                    width: 100%;
                    justify-content: center;
                }
                
                .insights-flat-card .btn {
                    flex: 1;
                    justify-content: center;
                }
            }

            @media (max-width: 480px) {
                .insights-flat-card {
                    padding: 1rem;
                    border-radius: 12px;
                }

                .insights-flat-card .item-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }

                .insights-flat-card .btn {
                    width: 100%;
                    justify-content: center;
                }

                .insights-flat-card .item-actions {
                    width: 100%;
                    justify-content: center;
                }
            }

            /* 1. INSIGHTS SECTION CONTAINER - FLAT */
            #insights-section {
                /* Reset semua kemungkinan 3D effects */
                transform: none !important;
                box-shadow: none !important;
            }

            #insights-section .content-card {
                background: var(--glass-bg);
                border: 1px solid var(--glass-border);
                border-radius: 16px;
                padding: 2rem;
                margin-bottom: 2rem;
                backdrop-filter: blur(10px);
                /* HANYA transisi warna - TANPA transform dan box-shadow 3D */
                transition: background-color 0.3s ease, border-color 0.3s ease;
            }

            #insights-section .content-card:hover {
                background: var(--glass-hover);
                border-color: var(--accent-primary);
                /* TANPA: transform, box-shadow 3D */
            }

            /* 2. SUB-TABS - FLAT */
            #insights-section .sub-tabs {
                display: flex;
                gap: 0.5rem;
                margin-bottom: 2rem;
                padding: 0.5rem;
                background: var(--surface-bg);
                border-radius: 12px;
                border: 1px solid var(--glass-border);
                backdrop-filter: blur(10px);
            }

            #insights-section .sub-tab {
                padding: 0.875rem 1.5rem;
                background: transparent;
                border: 1px solid transparent;
                border-radius: 8px;
                color: var(--text-tertiary);
                cursor: pointer;
                /* HANYA transisi warna - TANasPA transform */
                transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.95rem;
                text-decoration: none;
                font-family: inherit;
                white-space: nowrap;
            }

            #insights-section .sub-tab:hover {
                background: var(--glass-hover);
                color: var(--text-secondary);
                border-color: var(--glass-border);
                /* TANPA: transform: translateY(-2px), box-shadow */
            }

            #insights-section .sub-tab.active {
                background: var(--accent-primary);
                color: white;
                border-color: var(--accent-primary);
                /* TANPA: box-shadow 3D */
            }

            #insights-section .sub-tab.active:hover {
                background: var(--accent-secondary);
                /* TANPA: transform */
            }

            /* 3. SUB-CONTENT CONTAINERS - FLAT */
            #insights-section .sub-content {
                display: none;
                /* TANPA animasi 3D */
            }

            #insights-section .sub-content.active {
                display: block;
                /* TANPA animasi fadeInUp yang menggunakan transform */
            }

            #insights-section .sub-content .card-header {
                margin-top: 1.5rem;
                padding-top: 1.5rem;
                border-top: 1px solid var(--glass-border);
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--glass-border);
            }

            #insights-section .sub-content .card-header:first-child {
                margin-top: 0;
                padding-top: 0;
                border-top: none;
            }

            /* 4. INSIGHTS CARDS - FLAT */
            #insights-section .insights-flat-card {
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                padding: 1.5rem;
                margin: 1rem 0;
                /* HANYA transisi warna - TANPA transform dan box-shadow 3D */
                transition: background-color 0.3s ease, border-color 0.3s ease;
                position: relative;
            }

            #insights-section .insights-flat-card:hover {
                background: var(--elevated-bg);
                border-color: var(--accent-primary);
                /* TANPA: transform: translateY(-2px), box-shadow berlebihan */
            }

            /* 5. CARD HEADERS - FLAT */
            #insights-section .insights-flat-card .item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--glass-border);
                transition: border-color 0.3s ease;
            }

            #insights-section .insights-flat-card:hover .item-header {
                border-bottom-color: rgba(59, 130, 246, 0.3);
            }

            /* 6. CARD TITLES - FLAT */
            #insights-section .insights-flat-card .item-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: var(--text-primary);
                transition: color 0.3s ease;
            }

            #insights-section .insights-flat-card:hover .item-title {
                color: var(--accent-primary);
                /* TANPA: text-shadow */
            }

            /* 7. BUTTONS - FLAT */
            #insights-section .btn {
                padding: 0.75rem 1.25rem;
                border: none;
                border-radius: 10px;
                font-weight: 600;
                cursor: pointer;
                /* HANYA transisi background - TANPA transform */
                transition: background-color 0.3s ease, opacity 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.9rem;
                font-family: inherit;
                position: relative;
                overflow: hidden;
            }

            #insights-section .btn-primary {
                background: var(--accent-primary);
                color: white;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
            }

            #insights-section .btn-primary:hover {
                background: var(--accent-secondary);
                /* TANPA: transform: translateY(-2px), box-shadow berlebihan */
            }

            #insights-section .btn-success {
                background: var(--accent-success);
                color: white;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
            }

            #insights-section .btn-success:hover {
                background: #059669;
                /* TANPA: transform: translateY(-2px), box-shadow berlebihan */
            }

            #insights-section .btn-danger {
                background: var(--accent-danger);
                color: white;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
            }

            #insights-section .btn-danger:hover {
                background: #dc2626;
                /* TANPA: transform: translateY(-2px), box-shadow berlebihan */
            }

            /* 8. FORM ELEMENTS - FLAT */
            #insights-section .form-input,
            #insights-section .form-textarea,
            #insights-section .form-select {
                width: 100%;
                padding: 1rem 1.25rem;
                background: var(--surface-bg);
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                color: var(--text-primary);
                font-size: 0.95rem;
                font-family: inherit;
                /* Transisi sederhana tanpa transform */
                transition: border-color 0.3s ease, background-color 0.3s ease;
            }

            #insights-section .form-input:focus,
            #insights-section .form-textarea:focus,
            #insights-section .form-select:focus {
                outline: none;
                border-color: var(--accent-primary);
                background: var(--elevated-bg);
                /* Box-shadow minimal tanpa transform */
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }

            #insights-section .form-label {
                display: block;
                font-weight: 600;
                color: var(--text-secondary);
                margin-bottom: 0.75rem;
                font-size: 0.95rem;
                transition: color 0.3s ease;
            }

            #insights-section .form-group:focus-within .form-label {
                color: var(--accent-primary);
            }

            /* 9. SWITCHES - FLAT */
            #insights-section .switch {
                position: relative;
                display: inline-block;
                width: 54px;
                height: 28px;
            }

            #insights-section .switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            #insights-section .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: var(--glass-border);
                /* Transisi sederhana */
                transition: background-color 0.4s ease;
                border-radius: 28px;
                border: 1px solid var(--glass-border);
            }

            #insights-section .slider:before {
                position: absolute;
                content: "";
                height: 20px;
                width: 20px;
                left: 4px;
                bottom: 3px;
                background: #ffffff;
                /* HANYA transform transition untuk slide */
                transition: transform 0.4s ease;
                border-radius: 50%;
                /* Box-shadow minimal */
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            #insights-section input:checked + .slider {
                background: var(--accent-primary);
                border-color: var(--accent-primary);
            }

            #insights-section input:checked + .slider:before {
                transform: translateX(26px);
            }

            /* 10. ITEM ACTIONS - FLAT */
            #insights-section .item-actions {
                display: flex;
                gap: 0.5rem;
                transition: opacity 0.3s ease;
                /* TANPA transform yang menyebabkan pergeseran */
            }

            /* 11. LOADING ELEMENTS - FLAT */
            #insights-section .loading {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid var(--glass-border);
                border-radius: 50%;
                border-top-color: var(--accent-primary);
                animation: spin 1s ease-in-out infinite;
            }

            @keyframes spin {
                to { 
                    transform: rotate(360deg); 
                }
            }

            /* 12. CARD TITLES - FLAT */
            #insights-section .card-title {
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--text-primary);
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            /* 13. FORM GRIDS - FLAT */
            #insights-section .form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }

            #insights-section .form-grid.single {
                grid-template-columns: 1fr;
            }

            #insights-section .form-group {
                margin-bottom: 1.5rem;
            }

            /* 14. RESPONSIVE - FLAT */
            @media (max-width: 768px) {
                #insights-section .sub-tabs {
                    flex-direction: column;
                    gap: 0.25rem;
                }
                
                #insights-section .sub-tab {
                    justify-content: center;
                    text-align: center;
                    /* TANPA transform bahkan di mobile */
                }
                
                #insights-section .insights-flat-card {
                    margin: 0.5rem 0;
                    padding: 1rem;
                }
                
                #insights-section .insights-flat-card .item-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }
                
                #insights-section .insights-flat-card .item-actions {
                    width: 100%;
                    justify-content: center;
                }
                
                #insights-section .insights-flat-card .btn {
                    flex: 1;
                    justify-content: center;
                }
                
                #insights-section .form-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 480px) {
                #insights-section .sub-tab {
                    padding: 0.75rem 1rem;
                    font-size: 0.9rem;
                }
                
                #insights-section .insights-flat-card {
                    padding: 1rem;
                    border-radius: 12px;
                }

                #insights-section .insights-flat-card .item-header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }

                #insights-section .insights-flat-card .btn {
                    width: 100%;
                    justify-content: center;
                }

                #insights-section .insights-flat-card .item-actions {
                    width: 100%;
                    justify-content: center;
                }
            }

            /* 15. OVERRIDE GLOBAL STYLES UNTUK INSIGHTS */
            #insights-section * {
                /* Force remove any 3D transforms */
                transform: none !important;
            }

            #insights-section *:hover {
                /* Force remove any 3D transforms on hover */
                transform: none !important;
            }

            /* Exceptions untuk slider switch yang memang perlu transform */
            #insights-section .slider:before {
                transform: translateX(0) !important;
            }

            #insights-section input:checked + .slider:before {
                transform: translateX(26px) !important;
            }

            /* Exception untuk loading spinner yang perlu rotate */
            #insights-section .loading {
                animation: spin 1s ease-in-out infinite !important;
            }

            /* 16. STATUS MESSAGES - FLAT */
            #insights-section .status-message {
                padding: 1rem 1.5rem;
                border-radius: 12px;
                margin-bottom: 1.5rem;
                display: none;
                font-weight: 500;
                /* TANPA animasi slideDown yang menggunakan transform */
            }

            #insights-section .status-success {
                background: rgba(16, 185, 129, 0.1);
                border: 1px solid var(--accent-success);
                color: var(--accent-success);
            }

            #insights-section .status-error {
                background: rgba(239, 68, 68, 0.1);
                border: 1px solid var(--accent-danger);
                color: var(--accent-danger);
            }

/* ===== FORCE FLAT DESIGN - OVERRIDE SEMUA EFEK 3D ===== */

/* 1. FORCE REMOVE SEMUA 3D EFFECTS DI INSIGHTS */
#insights-section,
#insights-section *,
#insights-section *:hover,
#insights-section *:focus,
#insights-section *:active {
    /* FORCE REMOVE transform 3D */
    transform: none !important;
    
    /* FORCE REMOVE box-shadow 3D berlebihan */
    box-shadow: none !important;
}

/* 2. OVERRIDE GLOBAL ITEM-CARD DI INSIGHTS */
#insights-section .item-card,
#insights-section .insights-flat-card,
#insights-section .content-card {
    background: var(--surface-bg) !important;
    border: 1px solid var(--glass-border) !important;
    border-radius: 12px !important;
    padding: 1.5rem !important;
    margin: 1rem 0 !important;
    
    /* FORCE FLAT - hanya transisi warna */
    transition: background-color 0.3s ease, border-color 0.3s ease !important;
    
    /* FORCE REMOVE 3D */
    transform: none !important;
    box-shadow: none !important;
}

#insights-section .item-card:hover,
#insights-section .insights-flat-card:hover,
#insights-section .content-card:hover {
    background: var(--elevated-bg) !important;
    border-color: var(--accent-primary) !important;
    
    /* FORCE REMOVE 3D HOVER */
    transform: none !important;
    box-shadow: none !important;
}

/* 3. FORCE FLAT BUTTONS DI INSIGHTS */
#insights-section .btn,
#insights-section .btn:hover,
#insights-section .btn:focus,
#insights-section .btn:active {
    /* FORCE REMOVE 3D */
    transform: none !important;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
}

#insights-section .btn-primary {
    background: var(--accent-primary) !important;
    color: white !important;
}

#insights-section .btn-primary:hover {
    background: var(--accent-secondary) !important;
    transform: none !important;
}

#insights-section .btn-success {
    background: var(--accent-success) !important;
    color: white !important;
}

#insights-section .btn-success:hover {
    background: #059669 !important;
    transform: none !important;
}

#insights-section .btn-danger {
    background: var(--accent-danger) !important;
    color: white !important;
}

#insights-section .btn-danger:hover {
    background: #dc2626 !important;
    transform: none !important;
}

/* 4. FORCE FLAT SUB-TABS */
#insights-section .sub-tab,
#insights-section .sub-tab:hover,
#insights-section .sub-tab:focus,
#insights-section .sub-tab:active {
    /* FORCE REMOVE 3D */
    transform: none !important;
    box-shadow: none !important;
}

#insights-section .sub-tab {
    padding: 0.875rem 1.5rem !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    border-radius: 8px !important;
    color: var(--text-tertiary) !important;
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease !important;
}

#insights-section .sub-tab:hover {
    background: var(--glass-hover) !important;
    color: var(--text-secondary) !important;
    border-color: var(--glass-border) !important;
    transform: none !important;
}

#insights-section .sub-tab.active {
    background: var(--accent-primary) !important;
    color: white !important;
    border-color: var(--accent-primary) !important;
    transform: none !important;
}

#insights-section .sub-tab.active:hover {
    background: var(--accent-secondary) !important;
    transform: none !important;
}

/* 5. FORCE FLAT FORM ELEMENTS */
#insights-section .form-input,
#insights-section .form-textarea,
#insights-section .form-select,
#insights-section .form-input:focus,
#insights-section .form-textarea:focus,
#insights-section .form-select:focus {
    transform: none !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* 6. EXCEPTION UNTUK ELEMEN YANG HARUS PUNYA TRANSFORM */
#insights-section .slider:before {
    transition: transform 0.4s ease !important;
}

#insights-section input:checked + .slider:before {
    transform: translateX(26px) !important;
}

#insights-section .loading {
    animation: spin 1s ease-in-out infinite !important;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* 7. FORCE OVERRIDE UNTUK DYNAMIC CONTENT */
#insights-section [class*="card"],
#insights-section [class*="item"],
#insights-section [class*="btn"] {
    transform: none !important;
}

#insights-section [class*="card"]:hover,
#insights-section [class*="item"]:hover,
#insights-section [class*="btn"]:hover {
    transform: none !important;
    box-shadow: none !important;
}

/* 8. NUCLEAR OPTION - REMOVE SEMUA ANIMASI YANG MENGGUNAKAN TRANSFORM */
#insights-section * {
    animation: none !important;
}

/* Re-enable yang dibutuhkan */
#insights-section .loading {
    animation: spin 1s ease-in-out infinite !important;
}

/* 9. FORCE FLAT UNTUK NEWLY CREATED ELEMENTS */
#insights-section .item-card[data-new="true"],
#insights-section .insights-flat-card[data-new="true"] {
    transform: none !important;
    box-shadow: none !important;
    transition: background-color 0.3s ease, border-color 0.3s ease !important;
}

/* 10. RESPONSIVE FORCE FLAT */
@media (max-width: 768px) {
    #insights-section *,
    #insights-section *:hover {
        transform: none !important;
        box-shadow: none !important;
    }
}

/* 11. DEBUGGING - HIGHLIGHT ELEMENTS WITH TRANSFORM */
/* Uncomment untuk debugging */
/*
#insights-section *[style*="transform"]:not(.slider):not(.loading) {
    outline: 2px solid red !important;
}
*/
    </style>
</head>
<body>
    <div class="background-layer"></div>
    
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">Inspant<span>.</span></div>
                <p class="sidebar-subtitle">Analytics Dashboard</p>
                
                <div class="sidebar-status status-<?php echo $db_status_class; ?>">
                    <span><?php echo $db_status_class === 'success' ? '🟢' : '🔴'; ?></span>
                    Database: <?php echo htmlspecialchars($db_status); ?>
                </div>
                
                <div class="admin-info">
                    <strong>Admin:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?><br>
                    <small>Login: <?php echo date('H:i, d M Y', $_SESSION['admin_login_time']); ?></small>
                </div>
                
                <button onclick="logout()" class="logout-btn">
                    <span>🚪</span>
                    Logout
                </button>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <div class="nav-link active" onclick="switchSection('hero')" data-section="hero">
                        <span class="nav-icon">🏠</span>
                        Hero Section
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('features')" data-section="features">
                        <span class="nav-icon">📊</span>
                        Features
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('platforms')" data-section="platforms">
                        <span class="nav-icon">📱</span>
                        Platforms
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('insights')" data-section="insights">
                        <span class="nav-icon">💡</span>
                        Insights Tabs
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('articles')" data-section="articles">
                        <span class="nav-icon">📰</span>
                        Articles
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('about')" data-section="about">
                        <span class="nav-icon">ℹ️</span>
                        About
                    </div>
                </li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="content-header">
                <h1 class="page-title" id="pageTitle">Hero Section Editor</h1>
                <div class="header-actions">
                    <span style="color: var(--text-tertiary); font-size: 0.9rem;">
                        Last updated: <?php echo date('M j, Y g:i A'); ?>
                    </span>
                </div>
            </div>

            <div class="status-message" id="statusMessage"></div>

            <!-- Hero Section -->
            <section class="content-section active" id="hero-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🏠</span>
                            Hero Content Management
                        </h2>
                        <button class="btn btn-success" onclick="saveHero()">
                            <span>💾</span>
                            Save Changes
                        </button>
                    </div>
                    
                    <form id="heroForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Page Title</label>
                                <input type="text" class="form-input" id="heroPageTitle" name="page_title" placeholder="Enter page title">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Badge Text</label>
                                <input type="text" class="form-input" id="badgeText" name="badge_text" placeholder="Enter badge text">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Main Title</label>
                            <input type="text" class="form-input" id="mainTitle" name="title" placeholder="Enter main title">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Subtitle</label>
                            <textarea class="form-textarea" id="subtitle" name="subtitle" placeholder="Enter subtitle description"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">CTA Button Text</label>
                            <input type="text" class="form-input" id="ctaText" name="cta_text" placeholder="Enter call-to-action text">
                        </div>
                    </form>
                </div>
            </section>

            <!-- Features Section -->
            <section class="content-section" id="features-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📊</span>
                            Features Management
                        </h2>
                        <button class="btn btn-primary" onclick="addFeature()">
                            <span>+</span>
                            Add New Feature
                        </button>
                    </div>
                    
                    <div id="featuresContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Platforms Section -->
            <section class="content-section" id="platforms-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📱</span>
                            Platform Cards Management
                        </h2>
                        <button class="btn btn-primary" onclick="addPlatform()">
                            <span>+</span>
                            Add New Platform
                        </button>
                    </div>
                    
                    <div id="platformsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Insights Tabs Section with Sub Tabs -->
            <section class="content-section" id="insights-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💡</span>
                            Insights Management
                        </h2>
                    </div>
                    
                    <div class="sub-tabs">
                        <button class="sub-tab active" onclick="switchInsightsTab('tabs')" data-tab="tabs">
                            <span>🏷️</span> Insights Tabs
                        </button>
                        <button class="sub-tab" onclick="switchInsightsTab('articles')" data-tab="articles">
                            <span>📰</span> Tab Articles
                        </button>
                    </div>

                    <!-- Insights Tabs Sub Content -->
                    <div class="sub-content active" id="insights-tabs-content">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span>🏷️</span>
                                Insights Tabs Management
                            </h3>
                            <button class="btn btn-primary" onclick="addInsightsTab()">
                                <span>+</span>
                                Add New Tab
                            </button>
                        </div>
                        
                        <div id="insightsContainer">
                            <div class="loading" style="margin: 2rem auto; display: block;"></div>
                        </div>
                    </div>

                    <!-- Tab Articles Sub Content -->
                    <div class="sub-content" id="insights-articles-content">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span>📰</span>
                                Tab Articles Management
                            </h3>
                            <button class="btn btn-primary" onclick="addTabArticle()">
                                <span>+</span>
                                Add New Article
                            </button>
                        </div>
                        
                        <div id="tabArticlesContainer">
                            <div class="loading" style="margin: 2rem auto; display: block;"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Articles Section -->
            <section class="content-section" id="articles-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📰</span>
                            Articles Management
                        </h2>
                        <button class="btn btn-primary" onclick="addArticle()">
                            <span>+</span>
                            Add New Article
                        </button>
                    </div>
                    
                    <div id="articlesContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- About Section -->
            <section class="content-section" id="about-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">ℹ️</span>
                            About Cards Management
                        </h2>
                        <button class="btn btn-primary" onclick="addAboutCard()">
                            <span>+</span>
                            Add New Card
                        </button>
                    </div>
                    
                    <div id="aboutContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
    class AdminDashboard {
        constructor() {
            this.currentSection = 'hero';
            this.currentInsightsTab = 'tabs';
            this.csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
            this.isLoading = false;
            this.tabs = [];
            this.init();
        }

        init() {
            this.bindEvents();
            this.loadSectionData('hero');
            this.setupMobileNavigation();
            this.addCSSForSubTabs();
        }

        addCSSForSubTabs() {
            // Tambahkan CSS untuk sub-tabs jika belum ada
            if (!document.getElementById('sub-tabs-style')) {
                const style = document.createElement('style');
                style.id = 'sub-tabs-style';
                style.textContent = `
                    .sub-tabs {
                        display: flex;
                        gap: 0.5rem;
                        margin-bottom: 2rem;
                        padding: 0.5rem;
                        background: var(--surface-bg);
                        border-radius: 12px;
                        border: 1px solid var(--glass-border);
                        backdrop-filter: blur(10px);
                    }

                    .sub-tab {
                        padding: 0.875rem 1.5rem;
                        background: transparent;
                        border: 1px solid transparent;
                        border-radius: 8px;
                        color: var(--text-tertiary);
                        cursor: pointer;
                        transition: all var(--transition-base);
                        font-weight: 500;
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                        font-size: 0.95rem;
                        text-decoration: none;
                        font-family: inherit;
                        white-space: nowrap;
                    }

                    .sub-tab:hover {
                        background: var(--glass-hover);
                        color: var(--text-secondary);
                        border-color: var(--glass-border);
                        transform: translateY(-2px);
                        box-shadow: var(--shadow-md);
                    }

                    .sub-tab.active {
                        background: var(--accent-primary);
                        color: white;
                        border-color: var(--accent-primary);
                        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
                    }

                    .sub-tab.active:hover {
                        background: var(--accent-secondary);
                        transform: translateY(-2px);
                    }

                    .sub-content {
                        display: none;
                        animation: fadeInUp 0.4s ease;
                    }

                    .sub-content.active {
                        display: block;
                    }

                    @keyframes fadeInUp {
                        from { 
                            opacity: 0; 
                            transform: translateY(20px); 
                        }
                        to { 
                            opacity: 1; 
                            transform: translateY(0); 
                        }
                    }

                    .sub-content .card-header {
                        margin-top: 1.5rem;
                        padding-top: 1.5rem;
                        border-top: 1px solid var(--glass-border);
                    }

                    .sub-content .card-header:first-child {
                        margin-top: 0;
                        padding-top: 0;
                        border-top: none;
                    }

                    @media (max-width: 768px) {
                        .sub-tabs {
                            flex-direction: column;
                            gap: 0.25rem;
                        }
                        
                        .sub-tab {
                            justify-content: center;
                            text-align: center;
                        }
                    }

                    @media (max-width: 480px) {
                        .sub-tab {
                            padding: 0.75rem 1rem;
                            font-size: 0.9rem;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        bindEvents() {
            document.querySelectorAll('[data-section]').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const section = link.getAttribute('data-section');
                    this.switchSection(section);
                });
            });

            document.querySelectorAll('input, textarea').forEach(input => {
                input.addEventListener('input', this.validateInput.bind(this));
            });
        }

        setupMobileNavigation() {
            const mobileToggle = document.getElementById('mobileToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');

            mobileToggle?.addEventListener('click', () => {
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active');
            });

            overlay?.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 968) {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('active');
                }
            });
        }

        validateInput(event) {
            const input = event.target;
            const value = input.value.trim();
            
            if (input.hasAttribute('required') && !value) {
                input.style.borderColor = 'var(--accent-danger)';
            } else {
                input.style.borderColor = 'var(--glass-border)';
            }
        }

        async makeRequest(action, data = {}) {
            if (this.isLoading) {
                console.warn('Request already in progress');
                return { success: false, message: 'Request in progress' };
            }
            
            this.isLoading = true;
            this.showLoading(true);

            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', this.csrfToken);
            
            for (let key in data) {
                if (Array.isArray(data[key])) {
                    data[key].forEach((item, index) => {
                        formData.append(`${key}[${index}]`, item);
                    });
                } else {
                    formData.append(key, data[key]);
                }
            }
            
            try {
                console.log('Making request:', action, data);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const textResponse = await response.text();
                console.log('Raw response:', textResponse.substring(0, 200) + '...');
                
                // Try to parse as JSON
                let result;
                try {
                    result = JSON.parse(textResponse);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.log('Full response:', textResponse);
                    throw new Error('Invalid JSON response from server');
                }
                
                console.log('Parsed result:', result);
                return result;
                
            } catch (error) {
                console.error('Request failed:', error);
                return { 
                    success: false, 
                    message: `Network error: ${error.message}` 
                };
            } finally {
                this.isLoading = false;
                this.showLoading(false);
            }
        }

        showLoading(show) {
            const loadingElements = document.querySelectorAll('.loading');
            loadingElements.forEach(el => {
                el.style.display = show ? 'block' : 'none';
            });
        }

        showMessage(message, type = 'success') {
            const messageEl = document.getElementById('statusMessage');
            messageEl.className = `status-message status-${type}`;
            messageEl.textContent = message;
            messageEl.style.display = 'block';
            
            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 5000);

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        switchSection(sectionId) {
            console.log('Switching to section:', sectionId);
            
            if (sectionId === this.currentSection) return;

            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.remove('active');
            });
            
            const targetSection = document.getElementById(sectionId + '-section');
            if (targetSection) {
                targetSection.classList.add('active');
            } else {
                console.error('Target section not found:', sectionId + '-section');
            }
            
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            const activeLink = document.querySelector(`[data-section="${sectionId}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
            }
            
            const titles = {
                hero: 'Hero Section Editor',
                features: 'Features Management',
                platforms: 'Platform Cards Management',
                insights: 'Insights Management',
                articles: 'Articles Management',
                about: 'About Section Management'
            };
            
            document.getElementById('pageTitle').textContent = titles[sectionId];
            
            // Reset insights tab jika beralih ke insights section
            if (sectionId === 'insights') {
                this.currentInsightsTab = 'tabs';
                // Update sub-tab active state
                setTimeout(() => {
                    document.querySelectorAll('.sub-tab').forEach(tab => {
                        tab.classList.remove('active');
                    });
                    document.querySelectorAll('.sub-content').forEach(content => {
                        content.classList.remove('active');
                    });
                    
                    const activeSubTab = document.querySelector('[data-tab="tabs"]');
                    if (activeSubTab) {
                        activeSubTab.classList.add('active');
                    }
                    
                    const activeSubContent = document.getElementById('insights-tabs-content');
                    if (activeSubContent) {
                        activeSubContent.classList.add('active');
                    }
                }, 100);
            }
            
            this.loadSectionData(sectionId);
            this.currentSection = sectionId;
            
            if (window.innerWidth <= 968) {
                document.getElementById('sidebar')?.classList.remove('mobile-open');
                document.getElementById('mobileOverlay')?.classList.remove('active');
            }
        }

        async loadSectionData(section) {
            switch(section) {
                case 'hero':
                    await this.loadHeroData();
                    break;
                case 'features':
                    await this.loadFeaturesData();
                    break;
                case 'platforms':
                    await this.loadPlatformsData();
                    break;
                case 'insights':
                    await this.loadInsightsTabData(this.currentInsightsTab);
                    break;
                case 'articles':
                    await this.loadArticlesData();
                    break;
                case 'about':
                    await this.loadAboutData();
                    break;
            }
        }

        switchInsightsTab(tabId) {
            console.log('Switching insights tab to:', tabId);
            
            if (tabId === this.currentInsightsTab) return;

            // Update tab buttons
            document.querySelectorAll('.sub-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.sub-content').forEach(content => {
                content.classList.remove('active');
            });
            
            const activeTab = document.querySelector(`[data-tab="${tabId}"]`);
            if (activeTab) {
                activeTab.classList.add('active');
            }
            
            const targetContent = document.getElementById(`insights-${tabId}-content`);
            if (targetContent) {
                targetContent.classList.add('active');
            }
            
            this.currentInsightsTab = tabId;
            this.loadInsightsTabData(tabId);
        }

        async loadInsightsTabData(tabType) {
            console.log('Loading insights tab data for:', tabType);
            
            try {
                switch(tabType) {
                    case 'tabs':
                        await this.loadInsightsData();
                        break;
                    case 'articles':
                        // Load tabs first, then articles
                        await this.loadTabsForArticles();
                        await this.loadTabArticlesData();
                        break;
                }
            } catch (error) {
                console.error('Error loading insights tab data:', error);
                this.showMessage('Failed to load data: ' + error.message, 'error');
            }
        }

        // Fungsi baru untuk memuat tabs sebelum articles
        async loadTabsForArticles() {
            try {
                const result = await this.makeRequest('get_insights_tabs');
                if (result.success) {
                    this.tabs = result.data || [];
                    console.log('Loaded tabs for articles:', this.tabs);
                } else {
                    throw new Error(result.message || 'Failed to load tabs');
                }
            } catch (error) {
                console.error('Error loading tabs for articles:', error);
                this.tabs = [];
            }
        }

        // Hero Functions
        async loadHeroData() {
            try {
                const result = await this.makeRequest('get_hero');
                if (result.success && result.data) {
                    const hero = result.data;
                    document.getElementById('heroPageTitle').value = hero.page_title || '';
                    document.getElementById('badgeText').value = hero.badge_text || '';
                    document.getElementById('mainTitle').value = hero.title || '';
                    document.getElementById('subtitle').value = hero.subtitle || '';
                    document.getElementById('ctaText').value = hero.cta_text || '';
                }
            } catch (error) {
                this.showMessage('Failed to load hero data', 'error');
            }
        }

        async saveHero() {
            const data = {
                page_title: document.getElementById('heroPageTitle').value.trim(),
                badge_text: document.getElementById('badgeText').value.trim(),
                title: document.getElementById('mainTitle').value.trim(),
                subtitle: document.getElementById('subtitle').value.trim(),
                cta_text: document.getElementById('ctaText').value.trim()
            };
            
            if (!data.title) {
                this.showMessage('Title is required', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_hero', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        // Features Functions
        async loadFeaturesData() {
            try {
                const result = await this.makeRequest('get_features');
                if (result.success) {
                    this.renderFeaturesContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load features data', 'error');
            }
        }

        renderFeaturesContainer(features) {
            const container = document.getElementById('featuresContainer');
            container.innerHTML = '';
            
            if (features.length === 0) {
                container.innerHTML = `
                    <div class="item-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No features found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Feature" to create your first feature.</p>
                    </div>
                `;
                return;
            }
            
            features.forEach((feature, index) => {
                const featureCard = this.createFeatureCard(feature, index);
                container.appendChild(featureCard);
            });
        }

        createFeatureCard(feature, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            let featuresArray = [];
            try {
                featuresArray = JSON.parse(feature.features || '[]');
            } catch (e) {
                featuresArray = [];
            }
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${feature.icon || '📊'}</span>
                        ${feature.title || 'Untitled Feature'}
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveFeature(${feature.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteFeature(${feature.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="feature-icon-${index}" value="${feature.icon || ''}" placeholder="Enter emoji icon">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-input" id="feature-title-${index}" value="${feature.title || ''}" placeholder="Enter feature title">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="feature-description-${index}" placeholder="Enter feature description">${feature.description || ''}</textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Coming Soon</label>
                        <label class="switch">
                            <input type="checkbox" id="feature-coming-soon-${index}" ${feature.coming_soon == 1 ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" class="form-input" id="feature-sort-${index}" value="${feature.sort_order || 0}" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Features List (one per line)</label>
                    <textarea class="form-textarea" id="feature-features-${index}" placeholder="Enter features, one per line">${featuresArray.join('\n')}</textarea>
                </div>
            `;
            return card;
        }

        async addFeature() {
            const newFeature = {
                icon: '📊',
                title: 'New Feature',
                description: 'Feature description',
                features: ['Feature 1', 'Feature 2'],
                coming_soon: 0,
                sort_order: 0
            };
            
            const result = await this.makeRequest('save_feature', newFeature);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadFeaturesData();
            }
        }

        async saveFeature(id, index) {
            const featuresText = document.getElementById(`feature-features-${index}`).value.trim();
            const featuresArray = featuresText ? featuresText.split('\n').map(f => f.trim()).filter(f => f) : [];
            
            const data = {
                id: id,
                icon: document.getElementById(`feature-icon-${index}`).value.trim(),
                title: document.getElementById(`feature-title-${index}`).value.trim(),
                description: document.getElementById(`feature-description-${index}`).value.trim(),
                features: featuresArray,
                coming_soon: document.getElementById(`feature-coming-soon-${index}`).checked ? 1 : 0,
                sort_order: parseInt(document.getElementById(`feature-sort-${index}`).value) || 0
            };
            
            if (!data.title) {
                this.showMessage('Feature title is required', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_feature', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteFeature(id) {
            if (!confirm('Are you sure you want to delete this feature?')) return;
            
            const result = await this.makeRequest('delete_feature', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadFeaturesData();
            }
        }

        // Platform Functions
        async loadPlatformsData() {
            try {
                const result = await this.makeRequest('get_platforms');
                if (result.success) {
                    this.renderPlatformsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load platforms data', 'error');
            }
        }

        renderPlatformsContainer(platforms) {
            const container = document.getElementById('platformsContainer');
            container.innerHTML = '';
            
            if (platforms.length === 0) {
                container.innerHTML = `
                    <div class="item-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No platforms found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Platform" to create your first platform.</p>
                    </div>
                `;
                return;
            }
            
            platforms.forEach((platform, index) => {
                const platformCard = this.createPlatformCard(platform, index);
                container.appendChild(platformCard);
            });
        }

        createPlatformCard(platform, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            let featuresArray = [];
            try {
                featuresArray = JSON.parse(platform.features || '[]');
            } catch (e) {
                featuresArray = [];
            }
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${platform.icon || '🌐'}</span>
                        ${platform.title || 'Untitled Platform'}
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.savePlatform(${platform.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deletePlatform(${platform.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="platform-icon-${index}" value="${platform.icon || ''}" placeholder="Enter emoji icon">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-input" id="platform-title-${index}" value="${platform.title || ''}" placeholder="Enter platform title">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="platform-description-${index}" placeholder="Enter platform description">${platform.description || ''}</textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Coming Soon</label>
                        <label class="switch">
                            <input type="checkbox" id="platform-coming-soon-${index}" ${platform.coming_soon == 1 ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" class="form-input" id="platform-sort-${index}" value="${platform.sort_order || 0}" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Features List (one per line)</label>
                    <textarea class="form-textarea" id="platform-features-${index}" placeholder="Enter features, one per line">${featuresArray.join('\n')}</textarea>
                </div>
            `;
            return card;
        }

        async addPlatform() {
            const newPlatform = {
                icon: '🌐',
                title: 'New Platform',
                description: 'Platform description',
                features: ['Feature 1', 'Feature 2'],
                coming_soon: 0,
                sort_order: 0
            };
            
            const result = await this.makeRequest('save_platform', newPlatform);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadPlatformsData();
            }
        }

        async savePlatform(id, index) {
            const featuresText = document.getElementById(`platform-features-${index}`).value.trim();
            const featuresArray = featuresText ? featuresText.split('\n').map(f => f.trim()).filter(f => f) : [];
            
            const data = {
                id: id,
                icon: document.getElementById(`platform-icon-${index}`).value.trim(),
                title: document.getElementById(`platform-title-${index}`).value.trim(),
                description: document.getElementById(`platform-description-${index}`).value.trim(),
                features: featuresArray,
                coming_soon: document.getElementById(`platform-coming-soon-${index}`).checked ? 1 : 0,
                sort_order: parseInt(document.getElementById(`platform-sort-${index}`).value) || 0
            };
            
            if (!data.title) {
                this.showMessage('Platform title is required', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_platform', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deletePlatform(id) {
            if (!confirm('Are you sure you want to delete this platform?')) return;
            
            const result = await this.makeRequest('delete_platform', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadPlatformsData();
            }
        }

        // Insights Functions
        async loadInsightsData() {
            try {
                const result = await this.makeRequest('get_insights_tabs');
                if (result.success) {
                    this.renderInsightsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load insights data', 'error');
            }
        }

        renderInsightsContainer(tabs) {
            const container = document.getElementById('insightsContainer');
            container.innerHTML = '';
            
            if (tabs.length === 0) {
                container.innerHTML = `
                    <div class="insights-flat-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No insights tabs found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Tab" to create your first insights tab.</p>
                    </div>
                `;
                return;
            }
            
            tabs.forEach((tab, index) => {
                const tabCard = this.createInsightsCard(tab, index);
                container.appendChild(tabCard);
            });
        }

        createInsightsCard(tab, index) {
            const card = document.createElement('div');
            card.className = 'insights-flat-card';
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">💡</span>
                        ${tab.name || 'Untitled Tab'}
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveInsightsTab(${tab.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteInsightsTab(${tab.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Tab Name</label>
                        <input type="text" class="form-input" id="insights-name-${index}" value="${tab.name || ''}" placeholder="Enter tab name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Slug</label>
                        <input type="text" class="form-input" id="insights-slug-${index}" value="${tab.slug || ''}" placeholder="Enter URL slug">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-input" id="insights-sort-${index}" value="${tab.sort_order || 0}" min="0">
                </div>
            `;
            return card;
        }

        async addInsightsTab() {
            const newTab = {
                name: 'New Tab',
                slug: 'new-tab',
                sort_order: 0
            };
            
            const result = await this.makeRequest('save_insights_tab', newTab);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadInsightsData();
            }
        }

        async saveInsightsTab(id, index) {
            const data = {
                id: id,
                name: document.getElementById(`insights-name-${index}`).value.trim(),
                slug: document.getElementById(`insights-slug-${index}`).value.trim(),
                sort_order: parseInt(document.getElementById(`insights-sort-${index}`).value) || 0
            };
            
            if (!data.name) {
                this.showMessage('Tab name is required', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_insights_tab', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteInsightsTab(id) {
            if (!confirm('Are you sure you want to delete this insights tab?')) return;
            
            const result = await this.makeRequest('delete_insights_tab', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadInsightsData();
            }
        }

        // Tab Articles Functions
        async loadTabArticlesData() {
            console.log('Loading tab articles data...');
            
            try {
                // Pastikan tabs sudah dimuat
                if (this.tabs.length === 0) {
                    await this.loadTabsForArticles();
                }

                const result = await this.makeRequest('get_articles');
                if (result.success) {
                    this.renderTabArticlesContainer(result.data || []);
                } else {
                    throw new Error(result.message || 'Failed to load articles');
                }
            } catch (error) {
                console.error('Error loading tab articles:', error);
                this.showMessage('Failed to load articles: ' + error.message, 'error');
                
                // Render empty container with error message
                const container = document.getElementById('tabArticlesContainer');
                if (container) {
                    container.innerHTML = `
                        <div class="insights-flat-card" style="text-align: center; padding: 3rem;">
                            <h3 style="color: var(--accent-danger); margin-bottom: 1rem;">Error Loading Articles</h3>
                            <p style="color: var(--text-tertiary);">${error.message}</p>
                            <button class="btn btn-primary" onclick="dashboard.loadTabArticlesData()" style="margin-top: 1rem;">
                                <span>🔄</span> Retry
                            </button>
                        </div>
                    `;
                }
            }
        }

        renderTabArticlesContainer(articles) {
            const container = document.getElementById('tabArticlesContainer');
            container.innerHTML = '';
            
            if (articles.length === 0) {
                container.innerHTML = `
                    <div class="insights-flat-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No articles found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Article" to create your first article.</p>
                    </div>
                `;
                return;
            }
            
            articles.forEach((article, index) => {
                const articleCard = this.createTabArticleCard(article, index);
                container.appendChild(articleCard);
            });
        }

        createTabArticleCard(article, index) {
            const card = document.createElement('div');
            card.className = 'insights-flat-card';
            
            console.log('Creating card for article:', article);
            console.log('Available tabs:', this.tabs);
            
            // Pastikan tabs tersedia
            const tabOptions = this.tabs.map(tab => 
                `<option value="${tab.id}" ${tab.id == article.tab_id ? 'selected' : ''}>${tab.name}</option>`
            ).join('');
            
            if (this.tabs.length === 0) {
                console.warn('No tabs available for article card');
            }
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${article.icon || '📰'}</span>
                        ${article.title || 'Untitled Article'}
                        <small style="color: var(--text-tertiary); font-weight: normal; margin-left: 0.5rem;">
                            (${article.tab_name || 'No Tab'})
                        </small>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveTabArticle(${article.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteTabArticle(${article.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Insights Tab *</label>
                        <select class="form-select" id="tab-article-tab-${index}" required>
                            <option value="">Select Tab</option>
                            ${tabOptions}
                        </select>
                        ${this.tabs.length === 0 ? '<small style="color: var(--accent-danger);">No tabs available. Create tabs first.</small>' : ''}
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="tab-article-icon-${index}" value="${article.icon || '📰'}" placeholder="Enter emoji icon">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-input" id="tab-article-category-${index}" value="${article.category || ''}" placeholder="Enter category">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Link Text</label>
                        <input type="text" class="form-input" id="tab-article-link-text-${index}" value="${article.link_text || 'Read more'}" placeholder="Enter link text">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-input" id="tab-article-title-${index}" value="${article.title || ''}" placeholder="Enter article title" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Excerpt</label>
                    <textarea class="form-textarea" id="tab-article-excerpt-${index}" placeholder="Enter article excerpt">${article.excerpt || ''}</textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Link URL</label>
                    <input type="url" class="form-input" id="tab-article-link-url-${index}" value="${article.link_url || ''}" placeholder="https://example.com/article">
                </div>
            `;
            return card;
        }

        async addTabArticle() {
            console.log('Adding new tab article...');
            
            // Pastikan tabs sudah dimuat
            if (this.tabs.length === 0) {
                await this.loadTabsForArticles();
            }

            if (this.tabs.length === 0) {
                this.showMessage('Please create at least one insights tab first. Go to "Insights Tabs" sub-tab to create tabs.', 'error');
                return;
            }

            const newArticle = {
                tab_id: this.tabs[0]?.id || 1,
                icon: '📰',
                category: 'News',
                title: 'New Article',
                excerpt: 'Article excerpt',
                link_url: '#',
                link_text: 'Read more',
                sort_order: 0
            };
            
            console.log('Creating new article with data:', newArticle);
            
            try {
                const result = await this.makeRequest('save_article', newArticle);
                this.showMessage(result.message, result.success ? 'success' : 'error');
                if (result.success) {
                    await this.loadTabArticlesData();
                }
            } catch (error) {
                console.error('Error adding tab article:', error);
                this.showMessage('Failed to add article: ' + error.message, 'error');
            }
        }

        async saveTabArticle(id, index) {
            console.log('Saving tab article:', id, index);
            
            const data = {
                id: id,
                tab_id: document.getElementById(`tab-article-tab-${index}`)?.value || '',
                icon: document.getElementById(`tab-article-icon-${index}`)?.value?.trim() || '📰',
                category: document.getElementById(`tab-article-category-${index}`)?.value?.trim() || '',
                title: document.getElementById(`tab-article-title-${index}`)?.value?.trim() || '',
                excerpt: document.getElementById(`tab-article-excerpt-${index}`)?.value?.trim() || '',
                link_url: document.getElementById(`tab-article-link-url-${index}`)?.value?.trim() || '',
                link_text: document.getElementById(`tab-article-link-text-${index}`)?.value?.trim() || '',
                sort_order: 0
            };
            
            console.log('Saving article with data:', data);
            
            if (!data.title) {
                this.showMessage('Article title is required', 'error');
                return;
            }

            if (!data.tab_id) {
                this.showMessage('Please select an insights tab', 'error');
                return;
            }
            
            try {
                const result = await this.makeRequest('save_article', data);
                this.showMessage(result.message, result.success ? 'success' : 'error');
                if (result.success) {
                    // Reload articles after successful save
                    await this.loadTabArticlesData();
                }
            } catch (error) {
                console.error('Error saving tab article:', error);
                this.showMessage('Failed to save article: ' + error.message, 'error');
            }
        }

        async deleteTabArticle(id) {
            if (!confirm('Are you sure you want to delete this article?')) return;
            
            const result = await this.makeRequest('delete_article', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadTabArticlesData();
            }
        }

        // Articles Functions (untuk section terpisah)
        async loadArticlesData() {
            try {
                const [tabsResult, articlesResult] = await Promise.all([
                    this.makeRequest('get_insights_tabs'),
                    this.makeRequest('get_articles')
                ]);
                
                if (tabsResult.success && articlesResult.success) {
                    this.tabs = tabsResult.data || [];
                    this.renderArticlesContainer(articlesResult.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load articles data', 'error');
            }
        }

        renderArticlesContainer(articles) {
            const container = document.getElementById('articlesContainer');
            container.innerHTML = '';
            
            if (articles.length === 0) {
                container.innerHTML = `
                    <div class="item-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No articles found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Article" to create your first article.</p>
                    </div>
                `;
                return;
            }
            
            articles.forEach((article, index) => {
                const articleCard = this.createArticleCard(article, index);
                container.appendChild(articleCard);
            });
        }

        createArticleCard(article, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const tabOptions = this.tabs.map(tab => 
                `<option value="${tab.id}" ${tab.id == article.tab_id ? 'selected' : ''}>${tab.name}</option>`
            ).join('');
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${article.icon || '📰'}</span>
                        ${article.title || 'Untitled Article'}
                        <small style="color: var(--text-tertiary); font-weight: normal; margin-left: 0.5rem;">
                            (${article.tab_name || 'No Tab'})
                        </small>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveArticle(${article.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteArticle(${article.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Tab Category</label>
                        <select class="form-select" id="article-tab-${index}">
                            <option value="">Select Tab</option>
                            ${tabOptions}
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="article-icon-${index}" value="${article.icon || ''}" placeholder="Enter emoji icon">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-input" id="article-category-${index}" value="${article.category || ''}" placeholder="Enter category">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Link Text</label>
                        <input type="text" class="form-input" id="article-link-text-${index}" value="${article.link_text || ''}" placeholder="Enter link text">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-input" id="article-title-${index}" value="${article.title || ''}" placeholder="Enter article title">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Excerpt</label>
                    <textarea class="form-textarea" id="article-excerpt-${index}" placeholder="Enter article excerpt">${article.excerpt || ''}</textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Link URL</label>
                    <input type="url" class="form-input" id="article-link-url-${index}" value="${article.link_url || ''}" placeholder="Enter article URL">
                </div>
            `;
            return card;
        }

        async addArticle() {
            if (this.tabs.length === 0) {
                this.showMessage('Please create at least one insights tab first', 'error');
                return;
            }

            const newArticle = {
                tab_id: this.tabs[0]?.id || 1,
                icon: '📰',
                category: 'News',
                title: 'New Article',
                excerpt: 'Article excerpt',
                link_url: '#',
                link_text: 'Read more',
                sort_order: 0
            };
            
            const result = await this.makeRequest('save_article', newArticle);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadArticlesData();
            }
        }

        async saveArticle(id, index) {
            const data = {
                id: id,
                tab_id: document.getElementById(`article-tab-${index}`).value,
                icon: document.getElementById(`article-icon-${index}`).value.trim(),
                category: document.getElementById(`article-category-${index}`).value.trim(),
                title: document.getElementById(`article-title-${index}`).value.trim(),
                excerpt: document.getElementById(`article-excerpt-${index}`).value.trim(),
                link_url: document.getElementById(`article-link-url-${index}`).value.trim(),
                link_text: document.getElementById(`article-link-text-${index}`).value.trim(),
                sort_order: 0
            };
            
            if (!data.title) {
                this.showMessage('Article title is required', 'error');
                return;
            }

            if (!data.tab_id) {
                this.showMessage('Please select an insights tab', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_article', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteArticle(id) {
            if (!confirm('Are you sure you want to delete this article?')) return;
            
            const result = await this.makeRequest('delete_article', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadArticlesData();
            }
        }

        // About Functions
        async loadAboutData() {
            try {
                const result = await this.makeRequest('get_about');
                if (result.success) {
                    this.renderAboutContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load about data', 'error');
            }
        }

        renderAboutContainer(aboutCards) {
            const container = document.getElementById('aboutContainer');
            container.innerHTML = '';
            
            if (aboutCards.length === 0) {
                container.innerHTML = `
                    <div class="item-card" style="text-align: center; padding: 3rem;">
                        <h3 style="color: var(--text-tertiary); margin-bottom: 1rem;">No about cards found</h3>
                        <p style="color: var(--text-quaternary);">Click "Add New Card" to create your first about card.</p>
                    </div>
                `;
                return;
            }
            
            aboutCards.forEach((card, index) => {
                const aboutCard = this.createAboutCard(card, index);
                container.appendChild(aboutCard);
            });
        }

        createAboutCard(card, index) {
            const cardElement = document.createElement('div');
            cardElement.className = 'item-card';
            
            cardElement.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${card.icon || '🎯'}</span>
                        ${card.title || 'Untitled Card'}
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveAboutCard(${card.id}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteAboutCard(${card.id})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="about-icon-${index}" value="${card.icon || ''}" placeholder="Enter emoji icon">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-input" id="about-title-${index}" value="${card.title || ''}" placeholder="Enter card title">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="about-description-${index}" placeholder="Enter card description">${card.description || ''}</textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-input" id="about-sort-${index}" value="${card.sort_order || 0}" min="0">
                </div>
            `;
            return cardElement;
        }

        async addAboutCard() {
            const newCard = {
                icon: '🎯',
                title: 'New Card',
                description: 'Card description',
                sort_order: 0
            };
            
            const result = await this.makeRequest('save_about', newCard);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAboutData();
            }
        }

        async saveAboutCard(id, index) {
            const data = {
                id: id,
                icon: document.getElementById(`about-icon-${index}`).value.trim(),
                title: document.getElementById(`about-title-${index}`).value.trim(),
                description: document.getElementById(`about-description-${index}`).value.trim(),
                sort_order: parseInt(document.getElementById(`about-sort-${index}`).value) || 0
            };
            
            if (!data.title) {
                this.showMessage('Card title is required', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_about', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteAboutCard(id) {
            if (!confirm('Are you sure you want to delete this card?')) return;
            
            const result = await this.makeRequest('delete_about', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAboutData();
            }
        }
    }

    // Global Functions
    let dashboard;

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    // Legacy function wrappers for backward compatibility
    function switchSection(section) {
        dashboard.switchSection(section);
    }

    function switchInsightsTab(tab) {
        dashboard.switchInsightsTab(tab);
    }

    function saveHero() {
        dashboard.saveHero();
    }

    function addFeature() {
        dashboard.addFeature();
    }

    function addPlatform() {
        dashboard.addPlatform();
    }

    function addInsightsTab() {
        dashboard.addInsightsTab();
    }

    function addTabArticle() {
        dashboard.addTabArticle();
    }

    function addArticle() {
        dashboard.addArticle();
    }

    function addAboutCard() {
        dashboard.addAboutCard();
    }

    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
        dashboard = new AdminDashboard();
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                const currentSection = dashboard.currentSection;
                if (currentSection === 'hero') {
                    dashboard.saveHero();
                }
            }
            
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');
                if (sidebar?.classList.contains('mobile-open')) {
                    sidebar.classList.remove('mobile-open');
                    overlay?.classList.remove('active');
                }
            }
        });

        // Auto-refresh session
        setInterval(() => {
            fetch(window.location.href, {
                method: 'HEAD'
            }).catch(() => {
                // If session expired, redirect to login
                window.location.href = 'admin_login.php';
            });
        }, 300000); // Check every 5 minutes
    });
    </script>
</body>
</html>