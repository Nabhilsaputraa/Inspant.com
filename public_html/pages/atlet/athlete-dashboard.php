<?php
// athlete_dashboard.php - Professional Athlete Management Dashboard
session_start();

// Include configuration file (adjust path as needed)
require_once dirname(__DIR__, 3) . '/config.php';

// Error handling configuration
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===== AUTHENTICATION CHECK =====
function isValidSession() {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'athlete') {
        return false;
    }
    
    // Session timeout (4 hours)
    if (!isset($_SESSION['login_time'])) {
        $_SESSION['login_time'] = time(); // set pertama kali
    } elseif (time() - $_SESSION['login_time'] > 4 * 60 * 60) {
        return false;
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);   // ✅ eksekusi query
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user !== false;
    } catch (PDOException $e) {
        error_log("Database error in user verification: " . $e->getMessage());
        return false;
    }
}


// Check authentication
if (!isValidSession()) {
    session_destroy();
    header("Location: ../../");
    exit;
}

// Update last activity
$_SESSION['last_activity'] = time();

// Handle logout
if (isset($_GET['logout'])) {
    error_log("User logout: " . $_SESSION['username'] . " from IP: " . $_SERVER['REMOTE_ADDR']);
    session_destroy();
    header("Location: ../../login.html");
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

// Helper functions
function sanitizeinputloc($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) ?: 0;
        case 'float':
            return filter_var($input, FILTER_VALIDATE_FLOAT) ?: 0.0;
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) ?: '';
        case 'date':
            return date('Y-m-d', strtotime($input)) ?: null;
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

function jsonresloc($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Enhanced AJAX handlerlogin.html
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        jsonresloc(false, 'Security token mismatch');
    }
    
    if (!$pdo) {
        jsonresloc(false, 'Database connection error');
    }
    
    try {
        $action = sanitizeinputloc($_POST['action']);
        
        switch ($action) {
            // ===== ATHLETE PROFILES ACTIONS =====
            case 'get_athletes':
                $stmt = $pdo->query("
                    SELECT ap.*, aps.* 
                    FROM athlete_profiles ap
                    LEFT JOIN athlete_profiles_stats aps ON ap.user_id = aps.user_id 
                    WHERE ap.is_active = 1 
                    ORDER BY ap.created_at DESC
                ");
                $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Athletes retrieved', $athletes);
                break;
                
            case 'save_athlete':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                $data = [
                    'user_id' => sanitizeinputloc($_POST['user_id'] ?? 0, 'int'),
                    'sport' => sanitizeinputloc($_POST['sport'] ?? ''),
                    'position' => sanitizeinputloc($_POST['position'] ?? ''),
                    'height' => sanitizeinputloc($_POST['height'] ?? 0, 'float'),
                    'weight' => sanitizeinputloc($_POST['weight'] ?? 0, 'float'),
                    'blood_type' => sanitizeinputloc($_POST['blood_type'] ?? ''),
                    'medical_conditions' => sanitizeinputloc($_POST['medical_conditions'] ?? ''),
                    'team_name' => sanitizeinputloc($_POST['team_name'] ?? ''),
                    'coach_id' => sanitizeinputloc($_POST['coach_id'] ?? 0, 'int')
                ];
                
                if (empty($data['sport'])) {
                    jsonresloc(false, 'Sport is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE athlete_profiles 
                        SET user_id = ?, sport = ?, position = ?, height = ?, weight = ?, 
                            blood_type = ?, medical_conditions = ?, team_name = ?, coach_id = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO athlete_profiles 
                        (user_id, sport, position, height, weight, blood_type, medical_conditions, team_name, coach_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                jsonresloc($result, $result ? 'Athlete saved successfully' : 'Failed to save athlete');
                break;
                
            case 'delete_athlete':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    jsonresloc(false, 'Invalid athlete ID');
                }
                
                $stmt = $pdo->prepare("UPDATE athlete_profiles SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                jsonresloc($result, $result ? 'Athlete deleted successfully' : 'Failed to delete athlete');
                break;

            // ===== TRAINING SCHEDULE ACTIONS =====
            case 'get_training_schedules':
                $stmt = $pdo->query("
                    SELECT ts.*, ap.team_name, au.username as athlete_name
                    FROM training_schedule ts
                    LEFT JOIN athlete_profiles ap ON ts.user_id = ap.user_id
                    LEFT JOIN admin_users au ON ts.user_id = au.id
                    WHERE ts.is_active = 1 
                    ORDER BY ts.start_time ASC
                ");
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Training schedules retrieved', $schedules);
                break;
                
            case 'save_training_schedule':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                $data = [
                    'user_id' => sanitizeinputloc($_POST['user_id'] ?? 0, 'int'),
                    'type' => sanitizeinputloc($_POST['type'] ?? ''),
                    'intensity' => sanitizeinputloc($_POST['intensity'] ?? ''),
                    'location' => sanitizeinputloc($_POST['location'] ?? ''),
                    'exercises' => sanitizeinputloc($_POST['exercises'] ?? ''),
                    'notes' => sanitizeinputloc($_POST['notes'] ?? ''),
                    'start_time' => sanitizeinputloc($_POST['start_time'] ?? '', 'date'),
                    'duration' => sanitizeinputloc($_POST['duration'] ?? 0, 'int'),
                    'position' => sanitizeinputloc($_POST['position'] ?? ''),
                    'status' => sanitizeinputloc($_POST['status'] ?? 'scheduled'),
                    'description' => sanitizeinputloc($_POST['description'] ?? '')
                ];
                
                if (empty($data['type'])) {
                    jsonresloc(false, 'Training type is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE training_schedule 
                        SET user_id = ?, type = ?, intensity = ?, location = ?, exercises = ?, 
                            notes = ?, start_time = ?, duration = ?, position = ?, status = ?, description = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO training_schedule 
                        (user_id, type, intensity, location, exercises, notes, start_time, duration, position, status, description) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                jsonresloc($result, $result ? 'Training schedule saved successfully' : 'Failed to save training schedule');
                break;
                
            case 'delete_training_schedule':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    jsonresloc(false, 'Invalid schedule ID');
                }
                
                $stmt = $pdo->prepare("UPDATE training_schedule SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                jsonresloc($result, $result ? 'Training schedule deleted successfully' : 'Failed to delete schedule');
                break;

            // ===== ACHIEVEMENTS ACTIONS =====
            case 'get_achievements':
                $stmt = $pdo->query("
                    SELECT a.*, au.username as athlete_name 
                    FROM achievements a
                    LEFT JOIN admin_users au ON a.user_id = au.id
                    WHERE a.is_active = 1 
                    ORDER BY a.achievement_year DESC, a.created_at DESC
                ");
                $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Achievements retrieved', $achievements);
                break;
                
            case 'save_achievement':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                $data = [
                    'user_id' => sanitizeinputloc($_POST['user_id'] ?? 0, 'int'),
                    'title' => sanitizeinputloc($_POST['title'] ?? ''),
                    'details' => sanitizeinputloc($_POST['details'] ?? ''),
                    'achievement_year' => sanitizeinputloc($_POST['achievement_year'] ?? 0, 'int'),
                    'icon' => sanitizeinputloc($_POST['icon'] ?? ''),
                    'category' => sanitizeinputloc($_POST['category'] ?? '')
                ];
                
                if (empty($data['title'])) {
                    jsonresloc(false, 'Achievement title is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE achievements 
                        SET user_id = ?, title = ?, details = ?, achievement_year = ?, icon = ?, category = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO achievements 
                        (user_id, title, details, achievement_year, icon, category) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                jsonresloc($result, $result ? 'Achievement saved successfully' : 'Failed to save achievement');
                break;
                
            case 'delete_achievement':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    jsonresloc(false, 'Invalid achievement ID');
                }
                
                $stmt = $pdo->prepare("UPDATE achievements SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                jsonresloc($result, $result ? 'Achievement deleted successfully' : 'Failed to delete achievement');
                break;

            // ===== WORKOUT LOGS ACTIONS =====
            case 'get_workout_logs':
                $stmt = $pdo->query("
                    SELECT wl.*, au.username as athlete_name 
                    FROM workout_logs wl
                    LEFT JOIN admin_users au ON wl.user_id = au.id
                    WHERE wl.is_active = 1 
                    ORDER BY wl.workout_date DESC, wl.created_at DESC
                ");
                $workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Workout logs retrieved', $workouts);
                break;
                
            case 'save_workout_log':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                $data = [
                    'user_id' => sanitizeinputloc($_POST['user_id'] ?? 0, 'int'),
                    'workout_type' => sanitizeinputloc($_POST['workout_type'] ?? ''),
                    'duration' => sanitizeinputloc($_POST['duration'] ?? 0, 'int'),
                    'intensity' => sanitizeinputloc($_POST['intensity'] ?? ''),
                    'location' => sanitizeinputloc($_POST['location'] ?? ''),
                    'exercises' => sanitizeinputloc($_POST['exercises'] ?? ''),
                    'notes' => sanitizeinputloc($_POST['notes'] ?? ''),
                    'workout_date' => sanitizeinputloc($_POST['workout_date'] ?? '', 'date')
                ];
                
                if (empty($data['workout_type'])) {
                    jsonresloc(false, 'Workout type is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE workout_logs 
                        SET user_id = ?, workout_type = ?, duration = ?, intensity = ?, 
                            location = ?, exercises = ?, notes = ?, workout_date = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO workout_logs 
                        (user_id, workout_type, duration, intensity, location, exercises, notes, workout_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                jsonresloc($result, $result ? 'Workout log saved successfully' : 'Failed to save workout log');
                break;
                
            case 'delete_workout_log':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    jsonresloc(false, 'Invalid workout log ID');
                }
                
                $stmt = $pdo->prepare("UPDATE workout_logs SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                jsonresloc($result, $result ? 'Workout log deleted successfully' : 'Failed to delete workout log');
                break;

            // ===== NUTRITION LOGS ACTIONS =====
            case 'get_nutrition_logs':
                $stmt = $pdo->query("
                    SELECT nl.*, au.username as athlete_name 
                    FROM nutrition_logs nl
                    LEFT JOIN admin_users au ON nl.user_id = au.id
                    WHERE nl.is_active = 1 
                    ORDER BY nl.meal_date DESC, nl.created_at DESC
                ");
                $nutrition = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Nutrition logs retrieved', $nutrition);
                break;
                
            case 'save_nutrition_log':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                $data = [
                    'user_id' => sanitizeinputloc($_POST['user_id'] ?? 0, 'int'),
                    'meal_type' => sanitizeinputloc($_POST['meal_type'] ?? ''),
                    'food_items' => sanitizeinputloc($_POST['food_items'] ?? ''),
                    'calories' => sanitizeinputloc($_POST['calories'] ?? 0, 'float'),
                    'protein' => sanitizeinputloc($_POST['protein'] ?? 0, 'float'),
                    'fats' => sanitizeinputloc($_POST['fats'] ?? 0, 'float'),
                    'meal_date' => sanitizeinputloc($_POST['meal_date'] ?? '', 'date')
                ];
                
                if (empty($data['meal_type'])) {
                    jsonresloc(false, 'Meal type is required');
                }
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE nutrition_logs 
                        SET user_id = ?, meal_type = ?, food_items = ?, calories = ?, 
                            protein = ?, fats = ?, meal_date = ?
                        WHERE id = ?
                    ");
                    $params = array_values($data);
                    $params[] = $id;
                    $result = $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO nutrition_logs 
                        (user_id, meal_type, food_items, calories, protein, fats, meal_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute(array_values($data));
                }
                
                jsonresloc($result, $result ? 'Nutrition log saved successfully' : 'Failed to save nutrition log');
                break;
                
            case 'delete_nutrition_log':
                $id = sanitizeinputloc($_POST['id'] ?? 0, 'int');
                if ($id <= 0) {
                    jsonresloc(false, 'Invalid nutrition log ID');
                }
                
                $stmt = $pdo->prepare("UPDATE nutrition_logs SET is_active = 0 WHERE id = ?");
                $result = $stmt->execute([$id]);
                jsonresloc($result, $result ? 'Nutrition log deleted successfully' : 'Failed to delete nutrition log');
                break;

            // ===== GET USERS FOR DROPDOWNS =====
            case 'get_users':
                $stmt = $pdo->query("
                    SELECT id, username, full_name, email 
                    FROM admin_users 
                    WHERE is_active = 1 
                    ORDER BY username ASC
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Users retrieved', $users);
                break;

            // ===== DASHBOARD STATS =====
            case 'get_dashboard_stats':
                $stats = [];
                
                // Total athletes
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM athlete_profiles WHERE is_active = 1");
                $stats['total_athletes'] = $stmt->fetchColumn();
                
                // Total training sessions this month
                $stmt = $pdo->query("
                    SELECT COUNT(*) as count FROM training_schedule 
                    WHERE is_active = 1 AND MONTH(start_time) = MONTH(CURRENT_DATE()) 
                    AND YEAR(start_time) = YEAR(CURRENT_DATE())
                ");
                $stats['monthly_trainings'] = $stmt->fetchColumn();
                
                // Total achievements
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM achievements WHERE is_active = 1");
                $stats['total_achievements'] = $stmt->fetchColumn();
                
                // Recent workout logs
                $stmt = $pdo->query("
                    SELECT COUNT(*) as count FROM workout_logs 
                    WHERE is_active = 1 AND DATE(workout_date) >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
                ");
                $stats['recent_workouts'] = $stmt->fetchColumn();
                
                jsonresloc(true, 'Dashboard stats retrieved', $stats);
                break;
                
            // ===== COACH INVITATIONS ACTIONS =====
            case 'get_coach_invitations':
                $stmt = $pdo->query("
                    SELECT i.*, u.full_name as coach_name, u.username as coach_username
                    FROM coach_athlete_invitations i
                    JOIN users u ON i.coach_id = u.id
                    WHERE i.athlete_id = ? OR (i.athlete_username = ? AND i.athlete_id IS NULL)
                    ORDER BY i.created_at DESC
                ");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['username']]);
                $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonresloc(true, 'Invitations retrieved', $invitations);
                break;

            case 'respond_to_invitation':
                $invitationId = sanitizeinputloc($_POST['invitation_id'] ?? 0, 'int');
                $response = sanitizeinputloc($_POST['response'] ?? ''); // 'accepted' or 'rejected'
                
                if (!in_array($response, ['accepted', 'rejected'])) {
                    jsonresloc(false, 'Invalid response');
                }
                
                // Get invitation details
                $stmt = $pdo->prepare("
                    SELECT coach_id, athlete_username 
                    FROM coach_athlete_invitations 
                    WHERE id = ? AND (athlete_id = ? OR athlete_username = ?) AND status = 'pending'
                ");
                $stmt->execute([$invitationId, $_SESSION['user_id'], $_SESSION['username']]);
                $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$invitation) {
                    jsonresloc(false, 'Invitation not found or already responded');
                }
                
                // Update invitation status
                $stmt = $pdo->prepare("
                    UPDATE coach_athlete_invitations 
                    SET status = ?, athlete_id = ?, responded_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$response, $_SESSION['user_id'], $invitationId]);
                
                // If accepted, update athlete profile
                if ($response === 'accepted') {
                    $stmt = $pdo->prepare("
                        UPDATE athlete_profiles 
                        SET coach_id = ? 
                        WHERE user_id = ? AND is_active = 1
                    ");
                    $stmt->execute([$invitation['coach_id'], $_SESSION['user_id']]);
                    
                    // If no profile exists, create one
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO athlete_profiles 
                        (user_id, coach_id, sport, created_at) 
                        VALUES (?, ?, 'General', NOW())
                    ");
                    $stmt->execute([$_SESSION['user_id'], $invitation['coach_id']]);
                }
                
                $message = $response === 'accepted' ? 'Invitation accepted successfully' : 'Invitation rejected';
                jsonresloc(true, $message);
                break;

            // ===== TRAINING RESULTS ACTIONS =====
            case 'get_my_training_results':
                $period = sanitizeinputloc($_POST['period'] ?? 30, 'int');
                
                $stmt = $pdo->prepare("
                    SELECT 
                        tr.*, 
                        ts.title as session_title,
                        ts.type as session_type,
                        ts.schedule_date,
                        u.full_name as coach_name
                    FROM training_results tr
                    JOIN training_schedule ts ON tr.training_schedule_id = ts.id
                    JOIN users u ON tr.coach_id = u.id
                    WHERE tr.athlete_id = ? 
                    AND tr.completed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    ORDER BY tr.completed_at DESC
                ");
                $stmt->execute([$_SESSION['user_id'], $period]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                jsonresloc(true, 'Training results retrieved', $results);
                break;

            case 'get_my_training_stats':
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_sessions,
                        AVG(performance_score) as avg_performance,
                        MAX(performance_score) as best_performance,
                        SUM(distance_covered) as total_distance,
                        SUM(calories_burned) as total_calories,
                        COUNT(CASE WHEN completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as recent_sessions
                    FROM training_results 
                    WHERE athlete_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                jsonresloc(true, 'Training stats retrieved', $stats);
                break;

            default:
                jsonresloc(false, 'Unknown action');
        }
    } catch (Exception $e) {
        error_log("Athlete dashboard error: " . $e->getMessage());
        jsonresloc(false, 'An error occurred: ' . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Athlete Management Dashboard</title>
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
            --accent-info: #06b6d4;
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
            width: 320px;
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
            color: var(--accent-primary);
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

        .user-info {
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
            margin-left: 320px;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-md);
            transition: all var(--transition-base);
        }

        .stat-card:hover {
            background: var(--glass-hover);
            border-color: var(--accent-primary);
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--text-tertiary);
            font-weight: 500;
        }

        .stat-icon {
            font-size: 1.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            background: var(--surface-bg);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .stat-description {
            font-size: 0.8rem;
            color: var(--text-quaternary);
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

        .form-grid.triple {
            grid-template-columns: 1fr 1fr 1fr;
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

        .btn-info {
            background: var(--accent-info);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .btn-info:hover {
            background: #0891b2;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(6, 182, 212, 0.3);
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-tertiary);
        }

        .empty-state h3 {
            margin-bottom: 1rem;
            color: var(--text-tertiary);
        }

        .empty-state p {
            color: var(--text-quaternary);
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

        .athlete-info {
            background: var(--elevated-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .athlete-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .meta-item {
            background: var(--surface-bg);
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--glass-border);
        }

        .meta-label {
            font-size: 0.8rem;
            color: var(--text-tertiary);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            gap: 0.25rem;
        }

        .badge-primary {
            background: rgba(59, 130, 246, 0.1);
            color: var(--accent-primary);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent-warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-info {
            background: rgba(6, 182, 212, 0.1);
            color: var(--accent-info);
            border: 1px solid rgba(6, 182, 212, 0.2);
        }

        @media (max-width: 1024px) {
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

            .form-grid.triple {
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

            .stats-grid {
                grid-template-columns: 1fr;
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

            .athlete-meta {
                grid-template-columns: 1fr;
            }
        }

        /* Invitation Cards Styling */
        .invitation-card {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
            transition: all var(--transition-base);
            position: relative;
        }

        .invitation-card:hover {
            background: var(--elevated-bg);
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .invitation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .coach-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .coach-avatar {
            width: 50px;
            height: 50px;
            background: var(--accent-success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
        }

        .coach-details h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .coach-details p {
            font-size: 0.85rem;
            color: var(--text-tertiary);
        }

        .invitation-status {
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent-warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-accepted {
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent-danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .invitation-message {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            font-style: italic;
            color: var(--text-secondary);
        }

        .invitation-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        /* Training Results Cards */
        .result-card {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
            transition: all var(--transition-base);
        }

        .result-card:hover {
            background: var(--elevated-bg);
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .result-score {
            font-size: 2rem;
            font-weight: 700;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            color: white;
        }

        .score-excellent {
            background: var(--accent-success);
        }

        .score-good {
            background: var(--accent-primary);
        }

        .score-average {
            background: var(--accent-warning);
        }

        .score-poor {
            background: var(--accent-danger);
        }

        .result-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .metric-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
        }

        .metric-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 0.25rem;
        }

        .metric-label {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            text-transform: uppercase;
        }

        .feedback-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .feedback-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .feedback-content {
            color: var(--text-tertiary);
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="background-layer"></div>
    
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">Athlete<span>Pro</span></div>
                <p class="sidebar-subtitle">Professional Athlete Management</p>
                
                <div class="sidebar-status status-<?php echo $db_status_class; ?>">
                    <span><?php echo $db_status_class === 'success' ? '🟢' : '🔴'; ?></span>
                    Database: <?php echo htmlspecialchars($db_status); ?>
                </div>
                
                <div class="user-info">
                    <strong>User:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?><br>
                    <small>Login: <?php echo isset($_SESSION['login_time']) ? date('H:i, d M Y', $_SESSION['login_time']) : 'Unknown'; ?></small>
                </div>
                
                <button onclick="logout()" class="logout-btn">
                    <span>🚪</span>
                    Logout
                </button>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <div class="nav-link active" onclick="switchSection('dashboard')" data-section="dashboard">
                        <span class="nav-icon">📊</span>
                        Dashboard
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('athletes')" data-section="athletes">
                        <span class="nav-icon">🏃</span>
                        Athletes
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('training')" data-section="training">
                        <span class="nav-icon">💪</span>
                        Training Schedule
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('workouts')" data-section="workouts">
                        <span class="nav-icon">🏋️</span>
                        Workout Logs
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('nutrition')" data-section="nutrition">
                        <span class="nav-icon">🥗</span>
                        Nutrition Logs
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('achievements')" data-section="achievements">
                        <span class="nav-icon">🏆</span>
                        Achievements
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('invitations')" data-section="invitations">
                        <span class="nav-icon">📧</span>
                        Coach Invitations
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('training-results')" data-section="training-results">
                        <span class="nav-icon">📊</span>
                        Training Results
                    </div>
                </li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="content-header">
                <h1 class="page-title" id="pageTitle">Dashboard Overview</h1>
                <div class="header-actions">
                    <span style="color: var(--text-tertiary); font-size: 0.9rem;">
                        Last updated: <?php echo date('M j, Y g:i A'); ?>
                    </span>
                </div>
            </div>

            <div class="status-message" id="statusMessage"></div>

            <!-- Dashboard Section -->
            <section class="content-section active" id="dashboard-section">
                <div class="stats-grid" id="statsGrid">
                    <div class="loading" style="margin: 2rem auto; display: block;"></div>
                </div>
                
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📈</span>
                            Quick Overview
                        </h2>
                    </div>
                    
                    <div class="athlete-meta">
                        <div class="meta-item">
                            <div class="meta-label">System Status</div>
                            <div class="meta-value">
                                <span class="badge badge-success">
                                    <span>✅</span> Online
                                </span>
                            </div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">Active Session</div>
                            <div class="meta-value"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">Last Login</div>
                            <div class="meta-value"><?php echo isset($_SESSION['login_time']) ? date('M j, H:i', $_SESSION['login_time']) : 'Unknown'; ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Athletes Section -->
            <section class="content-section" id="athletes-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🏃</span>
                            Athlete Profiles Management
                        </h2>
                        <button class="btn btn-primary" onclick="addAthlete()">
                            <span>+</span>
                            Add New Athlete
                        </button>
                    </div>
                    
                    <div id="athletesContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Training Schedule Section -->
            <section class="content-section" id="training-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💪</span>
                            Training Schedule Management
                        </h2>
                        <button class="btn btn-primary" onclick="addTrainingSchedule()">
                            <span>+</span>
                            Add Training Session
                        </button>
                    </div>
                    
                    <div id="trainingContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Workout Logs Section -->
            <section class="content-section" id="workouts-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🏋️</span>
                            Workout Logs Management
                        </h2>
                        <button class="btn btn-primary" onclick="addWorkoutLog()">
                            <span>+</span>
                            Add Workout Log
                        </button>
                    </div>
                    
                    <div id="workoutsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Nutrition Logs Section -->
            <section class="content-section" id="nutrition-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🥗</span>
                            Nutrition Logs Management
                        </h2>
                        <button class="btn btn-primary" onclick="addNutritionLog()">
                            <span>+</span>
                            Add Nutrition Log
                        </button>
                    </div>
                    
                    <div id="nutritionContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Achievements Section -->
            <section class="content-section" id="achievements-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🏆</span>
                            Achievements Management
                        </h2>
                        <button class="btn btn-primary" onclick="addAchievement()">
                            <span>+</span>
                            Add Achievement
                        </button>
                    </div>
                    
                    <div id="achievementsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Coach Invitations Section -->
            <section class="content-section" id="invitations-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📧</span>
                            Coach Invitations
                        </h2>
                    </div>
                    
                    <div id="invitationsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Training Results Section -->
            <section class="content-section" id="training-results-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📊</span>
                            My Training Results
                        </h2>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <select class="form-select" style="width: 150px;" id="results-period" onchange="loadTrainingResults(this.value)">
                                <option value="7">Last 7 Days</option>
                                <option value="30" selected>Last 30 Days</option>
                                <option value="90">Last 3 Months</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="trainingResultsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
    class AthleteDashboard {
        constructor() {
            this.currentSection = 'dashboard';
            this.csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
            this.isLoading = false;
            this.users = [];
            this.init();
        }

        init() {
            this.bindEvents();
            this.loadSectionData('dashboard');
            this.setupMobileNavigation();
            this.loadUsers();
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
                if (window.innerWidth > 1024) {
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
                dashboard: 'Dashboard Overview',
                athletes: 'Athlete Profiles',
                training: 'Training Schedule',
                workouts: 'Workout Logs',
                nutrition: 'Nutrition Logs',
                achievements: 'Achievements',
                invitations: 'Coach Invitations',
                'training-results': 'Training Results'
            };
            
            document.getElementById('pageTitle').textContent = titles[sectionId];
            
            this.loadSectionData(sectionId);
            this.currentSection = sectionId;
            
            if (window.innerWidth <= 1024) {
                document.getElementById('sidebar')?.classList.remove('mobile-open');
                document.getElementById('mobileOverlay')?.classList.remove('active');
            }
        }

        async loadSectionData(section) {
            switch(section) {
                case 'dashboard':
                    await this.loadDashboardStats();
                    break;
                case 'athletes':
                    await this.loadAthletesData();
                    break;
                case 'training':
                    await this.loadTrainingData();
                    break;
                case 'workouts':
                    await this.loadWorkoutsData();
                    break;
                case 'nutrition':
                    await this.loadNutritionData();
                    break;
                case 'achievements':
                    await this.loadAchievementsData();
                    break;
                case 'invitations':
                    await this.loadInvitations();
                    break;
                case 'training-results':
                    await this.loadTrainingResults();
                    break;
            }
        }

        async loadUsers() {
            try {
                const result = await this.makeRequest('get_users');
                if (result.success) {
                    this.users = result.data || [];
                    console.log('Loaded users:', this.users);
                }
            } catch (error) {
                console.error('Error loading users:', error);
                this.users = [];
            }
        }

        // Dashboard Functions
        async loadDashboardStats() {
            try {
                const result = await this.makeRequest('get_dashboard_stats');
                if (result.success) {
                    this.renderDashboardStats(result.data);
                }
            } catch (error) {
                this.showMessage('Failed to load dashboard stats', 'error');
            }
        }

        renderDashboardStats(stats) {
            const statsGrid = document.getElementById('statsGrid');
            
            const statsCards = [
                {
                    title: 'Total Athletes',
                    value: stats.total_athletes || 0,
                    icon: '🏃',
                    description: 'Registered athletes',
                    color: 'primary'
                },
                {
                    title: 'Monthly Training',
                    value: stats.monthly_trainings || 0,
                    icon: '💪',
                    description: 'Training sessions this month',
                    color: 'success'
                },
                {
                    title: 'Total Achievements',
                    value: stats.total_achievements || 0,
                    icon: '🏆',
                    description: 'Recorded achievements',
                    color: 'warning'
                },
                {
                    title: 'Recent Workouts',
                    value: stats.recent_workouts || 0,
                    icon: '🏋️',
                    description: 'Workouts in last 7 days',
                    color: 'info'
                }
            ];

            statsGrid.innerHTML = statsCards.map(stat => `
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">${stat.title}</span>
                        <span class="stat-icon">${stat.icon}</span>
                    </div>
                    <div class="stat-value">${stat.value}</div>
                    <div class="stat-description">${stat.description}</div>
                </div>
            `).join('');
        }

        // Athletes Functions
        async loadAthletesData() {
            try {
                const result = await this.makeRequest('get_athletes');
                if (result.success) {
                    this.renderAthletesContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load athletes data', 'error');
            }
        }

        renderAthletesContainer(athletes) {
            const container = document.getElementById('athletesContainer');
            container.innerHTML = '';
            
            if (athletes.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No athletes found</h3>
                        <p>Click "Add New Athlete" to create your first athlete profile.</p>
                    </div>
                `;
                return;
            }
            
            athletes.forEach((athlete, index) => {
                const athleteCard = this.createAthleteCard(athlete, index);
                container.appendChild(athleteCard);
            });
        }

        createAthleteCard(athlete, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const userOptions = this.users.map(user => 
                `<option value="${user.id}" ${user.id == athlete.user_id ? 'selected' : ''}>${user.username} (${user.full_name || user.email})</option>`
            ).join('');
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">🏃</span>
                        ${athlete.sport || 'Unknown Sport'} - ${athlete.position || 'No Position'}
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveAthlete(${athlete.id || 0}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteAthlete(${athlete.id || 0})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="athlete-info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">User/Athlete *</label>
                            <select class="form-select" id="athlete-user-${index}" required>
                                <option value="">Select User</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sport *</label>
                            <input type="text" class="form-input" id="athlete-sport-${index}" value="${athlete.sport || ''}" placeholder="e.g., Football, Basketball" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Position</label>
                            <input type="text" class="form-input" id="athlete-position-${index}" value="${athlete.position || ''}" placeholder="e.g., Forward, Goalkeeper">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Team Name</label>
                            <input type="text" class="form-input" id="athlete-team-${index}" value="${athlete.team_name || ''}" placeholder="Enter team name">
                        </div>
                    </div>
                    
                    <div class="form-grid triple">
                        <div class="form-group">
                            <label class="form-label">Height (cm)</label>
                            <input type="number" class="form-input" id="athlete-height-${index}" value="${athlete.height || ''}" placeholder="170" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Weight (kg)</label>
                            <input type="number" class="form-input" id="athlete-weight-${index}" value="${athlete.weight || ''}" placeholder="70" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Blood Type</label>
                            <select class="form-select" id="athlete-blood-${index}">
                                <option value="">Select Blood Type</option>
                                <option value="A+" ${athlete.blood_type === 'A+' ? 'selected' : ''}>A+</option>
                                <option value="A-" ${athlete.blood_type === 'A-' ? 'selected' : ''}>A-</option>
                                <option value="B+" ${athlete.blood_type === 'B+' ? 'selected' : ''}>B+</option>
                                <option value="B-" ${athlete.blood_type === 'B-' ? 'selected' : ''}>B-</option>
                                <option value="AB+" ${athlete.blood_type === 'AB+' ? 'selected' : ''}>AB+</option>
                                <option value="AB-" ${athlete.blood_type === 'AB-' ? 'selected' : ''}>AB-</option>
                                <option value="O+" ${athlete.blood_type === 'O+' ? 'selected' : ''}>O+</option>
                                <option value="O-" ${athlete.blood_type === 'O-' ? 'selected' : ''}>O-</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Medical Conditions</label>
                        <textarea class="form-textarea" id="athlete-medical-${index}" placeholder="Any medical conditions or notes">${athlete.medical_conditions || ''}</textarea>
                    </div>
                </div>
            `;
            return card;
        }

        async addAthlete() {
            if (this.users.length === 0) {
                this.showMessage('No users available. Please create users first.', 'error');
                return;
            }

            const newAthlete = {
                user_id: this.users[0]?.id || 1,
                sport: 'New Sport',
                position: 'Position',
                height: 0,
                weight: 0,
                blood_type: '',
                medical_conditions: '',
                team_name: '',
                coach_id: 0
            };
            
            const result = await this.makeRequest('save_athlete', newAthlete);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAthletesData();
            }
        }

        async saveAthlete(id, index) {
            const data = {
                id: id,
                user_id: document.getElementById(`athlete-user-${index}`).value,
                sport: document.getElementById(`athlete-sport-${index}`).value.trim(),
                position: document.getElementById(`athlete-position-${index}`).value.trim(),
                height: parseFloat(document.getElementById(`athlete-height-${index}`).value) || 0,
                weight: parseFloat(document.getElementById(`athlete-weight-${index}`).value) || 0,
                blood_type: document.getElementById(`athlete-blood-${index}`).value,
                medical_conditions: document.getElementById(`athlete-medical-${index}`).value.trim(),
                team_name: document.getElementById(`athlete-team-${index}`).value.trim(),
                coach_id: 0
            };
            
            if (!data.sport) {
                this.showMessage('Sport is required', 'error');
                return;
            }

            if (!data.user_id) {
                this.showMessage('Please select a user', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_athlete', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteAthlete(id) {
            if (!confirm('Are you sure you want to delete this athlete profile?')) return;
            
            const result = await this.makeRequest('delete_athlete', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAthletesData();
            }
        }

        // Training Schedule Functions
        async loadTrainingData() {
            try {
                const result = await this.makeRequest('get_training_schedules');
                if (result.success) {
                    this.renderTrainingContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load training data', 'error');
            }
        }

        renderTrainingContainer(schedules) {
            const container = document.getElementById('trainingContainer');
            container.innerHTML = '';
            
            if (schedules.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No training schedules found</h3>
                        <p>Click "Add Training Session" to create your first training schedule.</p>
                    </div>
                `;
                return;
            }
            
            schedules.forEach((schedule, index) => {
                const scheduleCard = this.createTrainingCard(schedule, index);
                container.appendChild(scheduleCard);
            });
        }

        createTrainingCard(schedule, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const userOptions = this.users.map(user => 
                `<option value="${user.id}" ${user.id == schedule.user_id ? 'selected' : ''}>${user.username} (${user.full_name || user.email})</option>`
            ).join('');
            
            const statusBadge = this.getStatusBadge(schedule.status);
            const intensityBadge = this.getIntensityBadge(schedule.intensity);
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">💪</span>
                        ${schedule.type || 'Training Session'}
                        <div style="margin-left: 1rem; display: inline-flex; gap: 0.5rem;">
                            ${statusBadge}
                            ${intensityBadge}
                        </div>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveTrainingSchedule(${schedule.id || 0}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteTrainingSchedule(${schedule.id || 0})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="athlete-info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Athlete *</label>
                            <select class="form-select" id="training-user-${index}" required>
                                <option value="">Select Athlete</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Training Type *</label>
                            <input type="text" class="form-input" id="training-type-${index}" value="${schedule.type || ''}" placeholder="e.g., Strength, Cardio, Skills" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Start Time</label>
                            <input type="datetime-local" class="form-input" id="training-start-${index}" value="${schedule.start_time ? schedule.start_time.replace(' ', 'T') : ''}">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" class="form-input" id="training-duration-${index}" value="${schedule.duration || 60}" placeholder="60" min="1">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Intensity</label>
                            <select class="form-select" id="training-intensity-${index}">
                                <option value="">Select Intensity</option>
                                <option value="low" ${schedule.intensity === 'low' ? 'selected' : ''}>Low</option>
                                <option value="moderate" ${schedule.intensity === 'moderate' ? 'selected' : ''}>Moderate</option>
                                <option value="high" ${schedule.intensity === 'high' ? 'selected' : ''}>High</option>
                                <option value="maximum" ${schedule.intensity === 'maximum' ? 'selected' : ''}>Maximum</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="training-status-${index}">
                                <option value="scheduled" ${schedule.status === 'scheduled' ? 'selected' : ''}>Scheduled</option>
                                <option value="completed" ${schedule.status === 'completed' ? 'selected' : ''}>Completed</option>
                                <option value="cancelled" ${schedule.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                                <option value="in-progress" ${schedule.status === 'in-progress' ? 'selected' : ''}>In Progress</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-input" id="training-location-${index}" value="${schedule.location || ''}" placeholder="Training location">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Exercises</label>
                        <textarea class="form-textarea" id="training-exercises-${index}" placeholder="List of exercises and sets/reps">${schedule.exercises || ''}</textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-textarea" id="training-notes-${index}" placeholder="Additional notes or observations">${schedule.notes || ''}</textarea>
                    </div>
                </div>
            `;
            return card;
        }

        getStatusBadge(status) {
            const badges = {
                'scheduled': '<span class="badge badge-info">📅 Scheduled</span>',
                'completed': '<span class="badge badge-success">✅ Completed</span>',
                'cancelled': '<span class="badge badge-warning">❌ Cancelled</span>',
                'in-progress': '<span class="badge badge-primary">⏳ In Progress</span>'
            };
            return badges[status] || '<span class="badge badge-info">📅 Scheduled</span>';
        }

        getIntensityBadge(intensity) {
            const badges = {
                'low': '<span class="badge badge-success">🟢 Low</span>',
                'moderate': '<span class="badge badge-warning">🟡 Moderate</span>',
                'high': '<span class="badge badge-warning">🟠 High</span>',
                'maximum': '<span class="badge badge-danger">🔴 Maximum</span>'
            };
            return badges[intensity] || '';
        }

        async addTrainingSchedule() {
            if (this.users.length === 0) {
                this.showMessage('No users available. Please create users first.', 'error');
                return;
            }

            const newSchedule = {
                user_id: this.users[0]?.id || 1,
                type: 'New Training',
                intensity: 'moderate',
                location: 'Training Center',
                exercises: '',
                notes: '',
                start_time: new Date().toISOString().slice(0, 16),
                duration: 60,
                position: '',
                status: 'scheduled',
                description: ''
            };
            
            const result = await this.makeRequest('save_training_schedule', newSchedule);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadTrainingData();
            }
        }

        async saveTrainingSchedule(id, index) {
            const data = {
                id: id,
                user_id: document.getElementById(`training-user-${index}`).value,
                type: document.getElementById(`training-type-${index}`).value.trim(),
                intensity: document.getElementById(`training-intensity-${index}`).value,
                location: document.getElementById(`training-location-${index}`).value.trim(),
                exercises: document.getElementById(`training-exercises-${index}`).value.trim(),
                notes: document.getElementById(`training-notes-${index}`).value.trim(),
                start_time: document.getElementById(`training-start-${index}`).value,
                duration: parseInt(document.getElementById(`training-duration-${index}`).value) || 60,
                position: '',
                status: document.getElementById(`training-status-${index}`).value,
                description: document.getElementById(`training-notes-${index}`).value.trim()
            };
            
            if (!data.type) {
                this.showMessage('Training type is required', 'error');
                return;
            }

            if (!data.user_id) {
                this.showMessage('Please select an athlete', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_training_schedule', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteTrainingSchedule(id) {
            if (!confirm('Are you sure you want to delete this training schedule?')) return;
            
            const result = await this.makeRequest('delete_training_schedule', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadTrainingData();
            }
        }

        // Workout Logs Functions
        async loadWorkoutsData() {
            try {
                const result = await this.makeRequest('get_workout_logs');
                if (result.success) {
                    this.renderWorkoutsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load workout logs', 'error');
            }
        }

        renderWorkoutsContainer(workouts) {
            const container = document.getElementById('workoutsContainer');
            container.innerHTML = '';
            
            if (workouts.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No workout logs found</h3>
                        <p>Click "Add Workout Log" to create your first workout entry.</p>
                    </div>
                `;
                return;
            }
            
            workouts.forEach((workout, index) => {
                const workoutCard = this.createWorkoutCard(workout, index);
                container.appendChild(workoutCard);
            });
        }

        createWorkoutCard(workout, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const userOptions = this.users.map(user => 
                `<option value="${user.id}" ${user.id == workout.user_id ? 'selected' : ''}>${user.username} (${user.full_name || user.email})</option>`
            ).join('');
            
            const intensityBadge = this.getIntensityBadge(workout.intensity);
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">🏋️</span>
                        ${workout.workout_type || 'Workout'} - ${workout.workout_date || 'No Date'}
                        <div style="margin-left: 1rem; display: inline-flex; gap: 0.5rem;">
                            ${intensityBadge}
                            <span class="badge badge-info">⏱️ ${workout.duration || 0}min</span>
                        </div>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveWorkoutLog(${workout.id || 0}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteWorkoutLog(${workout.id || 0})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="athlete-info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Athlete *</label>
                            <select class="form-select" id="workout-user-${index}" required>
                                <option value="">Select Athlete</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Workout Type *</label>
                            <input type="text" class="form-input" id="workout-type-${index}" value="${workout.workout_type || ''}" placeholder="e.g., Strength, Cardio, HIIT" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-input" id="workout-date-${index}" value="${workout.workout_date || ''}">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" class="form-input" id="workout-duration-${index}" value="${workout.duration || 30}" placeholder="30" min="1">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Intensity</label>
                            <select class="form-select" id="workout-intensity-${index}">
                                <option value="">Select Intensity</option>
                                <option value="low" ${workout.intensity === 'low' ? 'selected' : ''}>Low</option>
                                <option value="moderate" ${workout.intensity === 'moderate' ? 'selected' : ''}>Moderate</option>
                                <option value="high" ${workout.intensity === 'high' ? 'selected' : ''}>High</option>
                                <option value="maximum" ${workout.intensity === 'maximum' ? 'selected' : ''}>Maximum</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-input" id="workout-location-${index}" value="${workout.location || ''}" placeholder="Gym, Home, etc.">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Exercises</label>
                        <textarea class="form-textarea" id="workout-exercises-${index}" placeholder="List exercises, sets, reps, and weights">${workout.exercises || ''}</textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-textarea" id="workout-notes-${index}" placeholder="How did you feel? Any observations?">${workout.notes || ''}</textarea>
                    </div>
                </div>
            `;
            return card;
        }

        async addWorkoutLog() {
            if (this.users.length === 0) {
                this.showMessage('No users available. Please create users first.', 'error');
                return;
            }

            const newWorkout = {
                user_id: this.users[0]?.id || 1,
                workout_type: 'New Workout',
                duration: 30,
                intensity: 'moderate',
                location: 'Gym',
                exercises: '',
                notes: '',
                workout_date: new Date().toISOString().split('T')[0]
            };
            
            const result = await this.makeRequest('save_workout_log', newWorkout);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadWorkoutsData();
            }
        }

        async saveWorkoutLog(id, index) {
            const data = {
                id: id,
                user_id: document.getElementById(`workout-user-${index}`).value,
                workout_type: document.getElementById(`workout-type-${index}`).value.trim(),
                duration: parseInt(document.getElementById(`workout-duration-${index}`).value) || 30,
                intensity: document.getElementById(`workout-intensity-${index}`).value,
                location: document.getElementById(`workout-location-${index}`).value.trim(),
                exercises: document.getElementById(`workout-exercises-${index}`).value.trim(),
                notes: document.getElementById(`workout-notes-${index}`).value.trim(),
                workout_date: document.getElementById(`workout-date-${index}`).value
            };
            
            if (!data.workout_type) {
                this.showMessage('Workout type is required', 'error');
                return;
            }

            if (!data.user_id) {
                this.showMessage('Please select an athlete', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_workout_log', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteWorkoutLog(id) {
            if (!confirm('Are you sure you want to delete this workout log?')) return;
            
            const result = await this.makeRequest('delete_workout_log', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadWorkoutsData();
            }
        }

        // Nutrition Logs Functions
        async loadNutritionData() {
            try {
                const result = await this.makeRequest('get_nutrition_logs');
                if (result.success) {
                    this.renderNutritionContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load nutrition logs', 'error');
            }
        }

        renderNutritionContainer(nutrition) {
            const container = document.getElementById('nutritionContainer');
            container.innerHTML = '';
            
            if (nutrition.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No nutrition logs found</h3>
                        <p>Click "Add Nutrition Log" to create your first nutrition entry.</p>
                    </div>
                `;
                return;
            }
            
            nutrition.forEach((entry, index) => {
                const nutritionCard = this.createNutritionCard(entry, index);
                container.appendChild(nutritionCard);
            });
        }

        createNutritionCard(nutrition, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const userOptions = this.users.map(user => 
                `<option value="${user.id}" ${user.id == nutrition.user_id ? 'selected' : ''}>${user.username} (${user.full_name || user.email})</option>`
            ).join('');
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">🥗</span>
                        ${nutrition.meal_type || 'Meal'} - ${nutrition.meal_date || 'No Date'}
                        <div style="margin-left: 1rem; display: inline-flex; gap: 0.5rem;">
                            <span class="badge badge-info">🔥 ${nutrition.calories || 0} cal</span>
                            <span class="badge badge-success">🥩 ${nutrition.protein || 0}g protein</span>
                        </div>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveNutritionLog(${nutrition.id || 0}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteNutritionLog(${nutrition.id || 0})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="athlete-info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Athlete *</label>
                            <select class="form-select" id="nutrition-user-${index}" required>
                                <option value="">Select Athlete</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Meal Type *</label>
                            <select class="form-select" id="nutrition-meal-type-${index}" required>
                                <option value="">Select Meal Type</option>
                                <option value="breakfast" ${nutrition.meal_type === 'breakfast' ? 'selected' : ''}>Breakfast</option>
                                <option value="lunch" ${nutrition.meal_type === 'lunch' ? 'selected' : ''}>Lunch</option>
                                <option value="dinner" ${nutrition.meal_type === 'dinner' ? 'selected' : ''}>Dinner</option>
                                <option value="snack" ${nutrition.meal_type === 'snack' ? 'selected' : ''}>Snack</option>
                                <option value="pre-workout" ${nutrition.meal_type === 'pre-workout' ? 'selected' : ''}>Pre-workout</option>
                                <option value="post-workout" ${nutrition.meal_type === 'post-workout' ? 'selected' : ''}>Post-workout</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-input" id="nutrition-date-${index}" value="${nutrition.meal_date || ''}">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Calories</label>
                            <input type="number" class="form-input" id="nutrition-calories-${index}" value="${nutrition.calories || ''}" placeholder="500" step="0.1">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Protein (g)</label>
                            <input type="number" class="form-input" id="nutrition-protein-${index}" value="${nutrition.protein || ''}" placeholder="25" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Fats (g)</label>
                            <input type="number" class="form-input" id="nutrition-fats-${index}" value="${nutrition.fats || ''}" placeholder="15" step="0.1">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Food Items</label>
                        <textarea class="form-textarea" id="nutrition-food-${index}" placeholder="List all food items consumed">${nutrition.food_items || ''}</textarea>
                    </div>
                </div>
            `;
            return card;
        }

        async addNutritionLog() {
            if (this.users.length === 0) {
                this.showMessage('No users available. Please create users first.', 'error');
                return;
            }

            const newNutrition = {
                user_id: this.users[0]?.id || 1,
                meal_type: 'breakfast',
                food_items: '',
                calories: 0,
                protein: 0,
                fats: 0,
                meal_date: new Date().toISOString().split('T')[0]
            };
            
            const result = await this.makeRequest('save_nutrition_log', newNutrition);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadNutritionData();
            }
        }

        async saveNutritionLog(id, index) {
            const data = {
                id: id,
                user_id: document.getElementById(`nutrition-user-${index}`).value,
                meal_type: document.getElementById(`nutrition-meal-type-${index}`).value,
                food_items: document.getElementById(`nutrition-food-${index}`).value.trim(),
                calories: parseFloat(document.getElementById(`nutrition-calories-${index}`).value) || 0,
                protein: parseFloat(document.getElementById(`nutrition-protein-${index}`).value) || 0,
                fats: parseFloat(document.getElementById(`nutrition-fats-${index}`).value) || 0,
                meal_date: document.getElementById(`nutrition-date-${index}`).value
            };
            
            if (!data.meal_type) {
                this.showMessage('Meal type is required', 'error');
                return;
            }

            if (!data.user_id) {
                this.showMessage('Please select an athlete', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_nutrition_log', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteNutritionLog(id) {
            if (!confirm('Are you sure you want to delete this nutrition log?')) return;
            
            const result = await this.makeRequest('delete_nutrition_log', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadNutritionData();
            }
        }

        // Achievements Functions
        async loadAchievementsData() {
            try {
                const result = await this.makeRequest('get_achievements');
                if (result.success) {
                    this.renderAchievementsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load achievements', 'error');
            }
        }

        renderAchievementsContainer(achievements) {
            const container = document.getElementById('achievementsContainer');
            container.innerHTML = '';
            
            if (achievements.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No achievements found</h3>
                        <p>Click "Add Achievement" to create your first achievement record.</p>
                    </div>
                `;
                return;
            }
            
            achievements.forEach((achievement, index) => {
                const achievementCard = this.createAchievementCard(achievement, index);
                container.appendChild(achievementCard);
            });
        }

        createAchievementCard(achievement, index) {
            const card = document.createElement('div');
            card.className = 'item-card';
            
            const userOptions = this.users.map(user => 
                `<option value="${user.id}" ${user.id == achievement.user_id ? 'selected' : ''}>${user.username} (${user.full_name || user.email})</option>`
            ).join('');
            
            const categoryBadge = this.getCategoryBadge(achievement.category);
            
            card.innerHTML = `
                <div class="item-header">
                    <h3 class="item-title">
                        <span style="margin-right: 0.5rem;">${achievement.icon || '🏆'}</span>
                        ${achievement.title || 'Achievement'}
                        <div style="margin-left: 1rem; display: inline-flex; gap: 0.5rem;">
                            ${categoryBadge}
                            <span class="badge badge-info">📅 ${achievement.achievement_year || 'No Year'}</span>
                        </div>
                    </h3>
                    <div class="item-actions">
                        <button class="btn btn-success" onclick="dashboard.saveAchievement(${achievement.id || 0}, ${index})">
                            <span>💾</span> Save
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.deleteAchievement(${achievement.id || 0})">
                            <span>🗑️</span> Delete
                        </button>
                    </div>
                </div>
                
                <div class="athlete-info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Athlete *</label>
                            <select class="form-select" id="achievement-user-${index}" required>
                                <option value="">Select Athlete</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-input" id="achievement-title-${index}" value="${achievement.title || ''}" placeholder="Achievement title" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Year</label>
                            <input type="number" class="form-input" id="achievement-year-${index}" value="${achievement.achievement_year || new Date().getFullYear()}" placeholder="${new Date().getFullYear()}" min="1900" max="2100">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="achievement-category-${index}">
                                <option value="">Select Category</option>
                                <option value="competition" ${achievement.category === 'competition' ? 'selected' : ''}>Competition</option>
                                <option value="personal" ${achievement.category === 'personal' ? 'selected' : ''}>Personal Best</option>
                                <option value="team" ${achievement.category === 'team' ? 'selected' : ''}>Team Achievement</option>
                                <option value="training" ${achievement.category === 'training' ? 'selected' : ''}>Training Milestone</option>
                                <option value="academic" ${achievement.category === 'academic' ? 'selected' : ''}>Academic</option>
                                <option value="other" ${achievement.category === 'other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <input type="text" class="form-input" id="achievement-icon-${index}" value="${achievement.icon || '🏆'}" placeholder="🏆">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Details</label>
                        <textarea class="form-textarea" id="achievement-details-${index}" placeholder="Detailed description of the achievement">${achievement.details || ''}</textarea>
                    </div>
                </div>
            `;
            return card;
        }

        getCategoryBadge(category) {
            const badges = {
                'competition': '<span class="badge badge-primary">🏅 Competition</span>',
                'personal': '<span class="badge badge-success">💪 Personal</span>',
                'team': '<span class="badge badge-info">👥 Team</span>',
                'training': '<span class="badge badge-warning">🎯 Training</span>',
                'academic': '<span class="badge badge-info">📚 Academic</span>',
                'other': '<span class="badge badge-primary">⭐ Other</span>'
            };
            return badges[category] || '';
        }

        async addAchievement() {
            if (this.users.length === 0) {
                this.showMessage('No users available. Please create users first.', 'error');
                return;
            }

            const newAchievement = {
                user_id: this.users[0]?.id || 1,
                title: 'New Achievement',
                details: '',
                achievement_year: new Date().getFullYear(),
                icon: '🏆',
                category: 'competition'
            };
            
            const result = await this.makeRequest('save_achievement', newAchievement);
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAchievementsData();
            }
        }

        async saveAchievement(id, index) {
            const data = {
                id: id,
                user_id: document.getElementById(`achievement-user-${index}`).value,
                title: document.getElementById(`achievement-title-${index}`).value.trim(),
                details: document.getElementById(`achievement-details-${index}`).value.trim(),
                achievement_year: parseInt(document.getElementById(`achievement-year-${index}`).value) || new Date().getFullYear(),
                icon: document.getElementById(`achievement-icon-${index}`).value.trim() || '🏆',
                category: document.getElementById(`achievement-category-${index}`).value
            };
            
            if (!data.title) {
                this.showMessage('Achievement title is required', 'error');
                return;
            }

            if (!data.user_id) {
                this.showMessage('Please select an athlete', 'error');
                return;
            }
            
            const result = await this.makeRequest('save_achievement', data);
            this.showMessage(result.message, result.success ? 'success' : 'error');
        }

        async deleteAchievement(id) {
            if (!confirm('Are you sure you want to delete this achievement?')) return;
            
            const result = await this.makeRequest('delete_achievement', { id: id });
            this.showMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                this.loadAchievementsData();
            }
        }


        // ===== COACH INVITATIONS FUNCTIONS =====
        async loadInvitations() {
            try {
                const result = await this.makeRequest('get_coach_invitations');
                if (result.success) {
                    this.renderInvitationsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load invitations', 'error');
            }
        }

        renderInvitationsContainer(invitations) {
            const container = document.getElementById('invitationsContainer');
            container.innerHTML = '';
            
            if (invitations.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No invitations found</h3>
                        <p>When coaches invite you to join their program, invitations will appear here.</p>
                    </div>
                `;
                return;
            }
            
            invitations.forEach(invitation => {
                const invitationCard = this.createInvitationCard(invitation);
                container.appendChild(invitationCard);
            });
        }

        createInvitationCard(invitation) {
            const card = document.createElement('div');
            card.className = 'invitation-card';
            
            const coachInitials = this.getInitials(invitation.coach_name || invitation.coach_username);
            const statusClass = `status-${invitation.status}`;
            const createdDate = new Date(invitation.created_at).toLocaleDateString();
            
            card.innerHTML = `
                <div class="invitation-header">
                    <div class="coach-info">
                        <div class="coach-avatar">${coachInitials}</div>
                        <div class="coach-details">
                            <h3>${invitation.coach_name || 'Coach'}</h3>
                            <p>@${invitation.coach_username} • Invited on ${createdDate}</p>
                        </div>
                    </div>
                    <span class="invitation-status ${statusClass}">${invitation.status}</span>
                </div>
                
                ${invitation.message ? `
                    <div class="invitation-message">
                        "${invitation.message}"
                    </div>
                ` : ''}
                
                ${invitation.status === 'pending' ? `
                    <div class="invitation-actions">
                        <button class="btn btn-success" onclick="dashboard.respondToInvitation(${invitation.id}, 'accepted')">
                            <span>✅</span> Accept
                        </button>
                        <button class="btn btn-danger" onclick="dashboard.respondToInvitation(${invitation.id}, 'rejected')">
                            <span>❌</span> Reject
                        </button>
                    </div>
                ` : invitation.responded_at ? `
                    <p style="color: var(--text-tertiary); font-size: 0.9rem; margin-top: 1rem;">
                        Responded on ${new Date(invitation.responded_at).toLocaleDateString()}
                    </p>
                ` : ''}
            `;
            
            return card;
        }

        async respondToInvitation(invitationId, response) {
            const confirmMessage = response === 'accepted' 
                ? 'Accept this coaching invitation?' 
                : 'Reject this coaching invitation?';
                
            if (!confirm(confirmMessage)) return;
            
            const result = await this.makeRequest('respond_to_invitation', {
                invitation_id: invitationId,
                response: response
            });
            
            this.showMessage(result.message, result.success ? 'success' : 'error');
            
            if (result.success) {
                this.loadInvitations();
                if (response === 'accepted') {
                    // Refresh dashboard stats as we might have a new coach
                    this.loadDashboardStats();
                }
            }
        }

        // ===== TRAINING RESULTS FUNCTIONS =====
        async loadTrainingResults(period = 30) {
            try {
                const result = await this.makeRequest('get_my_training_results', { period });
                if (result.success) {
                    this.renderTrainingResultsContainer(result.data || []);
                }
            } catch (error) {
                this.showMessage('Failed to load training results', 'error');
            }
        }

        renderTrainingResultsContainer(results) {
            const container = document.getElementById('trainingResultsContainer');
            container.innerHTML = '';
            
            if (results.length === 0) {
                container.innerHTML = `
                    <div class="item-card empty-state">
                        <h3>No training results found</h3>
                        <p>When your coach adds training results, they will appear here.</p>
                    </div>
                `;
                return;
            }
            
            results.forEach(result => {
                const resultCard = this.createTrainingResultCard(result);
                container.appendChild(resultCard);
            });
        }

        createTrainingResultCard(result) {
            const card = document.createElement('div');
            card.className = 'result-card';
            
            const scoreClass = this.getScoreClass(result.performance_score);
            const completedDate = new Date(result.completed_at).toLocaleDateString();
            
            card.innerHTML = `
                <div class="result-header">
                    <div>
                        <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                            ${result.session_title}
                        </h3>
                        <p style="color: var(--text-tertiary); font-size: 0.9rem;">
                            ${result.session_type} • Coach: ${result.coach_name} • ${completedDate}
                        </p>
                    </div>
                    <div class="result-score ${scoreClass}">
                        ${result.performance_score}%
                    </div>
                </div>
                
                <div class="result-metrics">
                    ${result.duration_minutes ? `
                        <div class="metric-item">
                            <div class="metric-value">${result.duration_minutes}</div>
                            <div class="metric-label">Minutes</div>
                        </div>
                    ` : ''}
                    
                    ${result.distance_covered ? `
                        <div class="metric-item">
                            <div class="metric-value">${result.distance_covered}</div>
                            <div class="metric-label">Distance (km)</div>
                        </div>
                    ` : ''}
                    
                    ${result.calories_burned ? `
                        <div class="metric-item">
                            <div class="metric-value">${result.calories_burned}</div>
                            <div class="metric-label">Calories</div>
                        </div>
                    ` : ''}
                    
                    ${result.heart_rate_avg ? `
                        <div class="metric-item">
                            <div class="metric-value">${result.heart_rate_avg}</div>
                            <div class="metric-label">Avg HR (bpm)</div>
                        </div>
                    ` : ''}
                    
                    ${result.heart_rate_max ? `
                        <div class="metric-item">
                            <div class="metric-value">${result.heart_rate_max}</div>
                            <div class="metric-label">Max HR (bpm)</div>
                        </div>
                    ` : ''}
                </div>
                
                ${result.notes ? `
                    <div class="feedback-section">
                        <div class="feedback-title">Coach Notes:</div>
                        <div class="feedback-content">${result.notes}</div>
                    </div>
                ` : ''}
                
                ${result.feedback ? `
                    <div class="feedback-section">
                        <div class="feedback-title">Coach Feedback:</div>
                        <div class="feedback-content">${result.feedback}</div>
                    </div>
                ` : ''}
            `;
            
            return card;
        }

        getScoreClass(score) {
            if (score >= 85) return 'score-excellent';
            if (score >= 70) return 'score-good';
            if (score >= 55) return 'score-average';
            return 'score-poor';
        }

        getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').toUpperCase().substr(0, 2);
        }
    }

    // Global Functions
    let dashboard;

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    function loadTrainingResults(period) {
        dashboard.loadTrainingResults(period);
    }

    // Legacy function wrappers for backward compatibility
    function switchSection(section) {
        dashboard.switchSection(section);
    }

    function addAthlete() {
        dashboard.addAthlete();
    }

    function addTrainingSchedule() {
        dashboard.addTrainingSchedule();
    }

    function addWorkoutLog() {
        dashboard.addWorkoutLog();
    }

    function addNutritionLog() {
        dashboard.addNutritionLog();
    }

    function addAchievement() {
        dashboard.addAchievement();
    }

    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
        dashboard = new AthleteDashboard();
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                // Save current section if applicable
                const currentSection = dashboard.currentSection;
                console.log('Save shortcut for section:', currentSection);
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
                window.location.href = '../../login.html';
            });
        }, 300000); // Check every 5 minutes

        // Add loading states for better UX
        document.addEventListener('submit', function(e) {
            const submitBtn = e.target.querySelector('[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loading"></span> Saving...';
                
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.getAttribute('data-original-text') || 'Save';
                }, 3000);
            }
        });

        // Initialize tooltips or help text
        document.querySelectorAll('[data-tooltip]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                // Add tooltip functionality if needed
                console.log('Tooltip:', this.getAttribute('data-tooltip'));
            });
        });

        // Performance monitoring
        if ('performance' in window) {
            window.addEventListener('load', () => {
                const perfData = performance.getEntriesByType('navigation')[0];
                console.log('Page load time:', perfData.loadEventEnd - perfData.loadEventStart, 'ms');
            });
        }
    });
    </script>
</body>
</html>