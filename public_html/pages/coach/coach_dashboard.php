<?php
// coach_dashboard_enhanced.php - Enhanced Coach Dashboard with Advanced Features
session_start();
require_once dirname(__DIR__, 3) . '/config.php';

// Error handling
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===== AUTHENTICATION CHECK =====
function isValidSession() {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'coach') {
        return false;
    }
    
    // Session timeout (4 hours)
    if (!isset($_SESSION['login_time'])) {
        $_SESSION['login_time'] = time();
    } elseif (time() - $_SESSION['login_time'] > 4 * 60 * 60) {
        return false;
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user !== false && $user['role'] === 'coach';
    } catch (PDOException $e) {
        error_log("Database error in user verification: " . $e->getMessage());
        return false;
    }
}

// Check authentication
if (!isValidSession()) {
    session_destroy();
    header("Location: ../../login.html");
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

// Initialize tables if needed
function initializeTables($pdo) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS coach_athlete_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            athlete_username VARCHAR(255) NOT NULL,
            athlete_id INT NULL,
            invitation_code VARCHAR(64) UNIQUE NOT NULL,
            status ENUM('pending', 'accepted', 'rejected', 'expired') DEFAULT 'pending',
            message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (athlete_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_code (invitation_code)
        )",
        
        "CREATE TABLE IF NOT EXISTS training_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            training_schedule_id INT NOT NULL,
            athlete_id INT NOT NULL,
            coach_id INT NOT NULL,
            performance_score DECIMAL(5,2) DEFAULT 0,
            distance_covered DECIMAL(10,2) NULL,
            duration_minutes INT NULL,
            heart_rate_avg INT NULL,
            heart_rate_max INT NULL,
            calories_burned INT NULL,
            notes TEXT NULL,
            feedback TEXT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (training_schedule_id) REFERENCES training_schedule(id) ON DELETE CASCADE,
            FOREIGN KEY (athlete_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_athlete (athlete_id),
            INDEX idx_date (completed_at)
        )",
        
        "CREATE TABLE IF NOT EXISTS athlete_performance_metrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            athlete_id INT NOT NULL,
            metric_date DATE NOT NULL,
            speed_avg DECIMAL(5,2) NULL,
            endurance_score DECIMAL(5,2) NULL,
            strength_score DECIMAL(5,2) NULL,
            flexibility_score DECIMAL(5,2) NULL,
            overall_performance DECIMAL(5,2) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (athlete_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_athlete_date (athlete_id, metric_date)
        )",

        "CREATE TABLE IF NOT EXISTS training_metric_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            template_name VARCHAR(100) NOT NULL,
            sport_category VARCHAR(50) DEFAULT 'general',
            is_default BOOLEAN DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_coach (coach_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS training_metric_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            field_type ENUM('number', 'decimal', 'text', 'select', 'boolean') DEFAULT 'number',
            field_unit VARCHAR(20) NULL,
            field_options JSON NULL,
            min_value DECIMAL(10,2) NULL,
            max_value DECIMAL(10,2) NULL,
            is_required BOOLEAN DEFAULT 0,
            display_order INT DEFAULT 0,
            FOREIGN KEY (template_id) REFERENCES training_metric_templates(id) ON DELETE CASCADE,
            INDEX idx_template (template_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS training_result_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            training_result_id INT NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            field_value TEXT NOT NULL,
            FOREIGN KEY (training_result_id) REFERENCES training_results(id) ON DELETE CASCADE,
            INDEX idx_result (training_result_id)
        )"
    ];
    
    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Table creation error: " . $e->getMessage());
        }
    }
}

// Initialize tables
if ($pdo) {
    initializeTables($pdo);
}

// Helper functions
function sanitizeloc($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) ?: 0;
        case 'float':
            return filter_var($input, FILTER_VALIDATE_FLOAT) ?: 0.0;
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) ?: '';
        case 'date':
            return date('Y-m-d', strtotime($input)) ?: null;
        case 'username':
            return preg_replace('/[^a-zA-Z0-9._-]/', '', $input);
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

function jsresloc($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        jsresloc(false, 'Security token mismatch');
    }
    
    if (!$pdo) {
        jsresloc(false, 'Database connection error');
    }
    
    try {
        $action = sanitizeloc($_POST['action']);
        $coachId = $_SESSION['user_id'];
        
        switch ($action) {
            // ===== INVITATION MANAGEMENT =====
            case 'send_invitation':
                $username = sanitizeloc($_POST['username'] ?? '', 'username');
                $message = sanitizeloc($_POST['message'] ?? '');
                
                if (empty($username)) {
                    jsresloc(false, 'Username is required');
                }
                
                // Check if athlete exists
                $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE username = ? AND role = 'athlete'");
                $stmt->execute([$username]);
                $athlete = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$athlete) {
                    jsresloc(false, 'Athlete not found with this username');
                }
                
                // Check if already connected
                $stmt = $pdo->prepare("
                    SELECT id FROM athlete_profiles 
                    WHERE user_id = ? AND coach_id = ? AND is_active = 1
                ");
                $stmt->execute([$athlete['id'], $coachId]);
                if ($stmt->fetch()) {
                    jsresloc(false, 'This athlete is already connected with you');
                }
                
                // Check for pending invitation
                $stmt = $pdo->prepare("
                    SELECT id FROM coach_athlete_invitations 
                    WHERE coach_id = ? AND athlete_id = ? AND status = 'pending'
                ");
                $stmt->execute([$coachId, $athlete['id']]);
                if ($stmt->fetch()) {
                    jsresloc(false, 'You already have a pending invitation for this athlete');
                }
                
                // Create invitation
                $invitationCode = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("
                    INSERT INTO coach_athlete_invitations 
                    (coach_id, athlete_username, athlete_id, invitation_code, message) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([$coachId, $username, $athlete['id'], $invitationCode, $message]);
                
                if ($result) {
                    jsresloc(true, 'Invitation sent successfully to ' . $athlete['full_name']);
                } else {
                    jsresloc(false, 'Failed to send invitation');
                }
                break;
                
            case 'get_invitations':
                $stmt = $pdo->prepare("
                    SELECT i.*, u.full_name as athlete_name 
                    FROM coach_athlete_invitations i
                    LEFT JOIN users u ON i.athlete_id = u.id
                    WHERE i.coach_id = ?
                    ORDER BY i.created_at DESC
                ");
                $stmt->execute([$coachId]);
                $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsresloc(true, 'Invitations retrieved', $invitations);
                break;
                
            case 'cancel_invitation':
                $invitationId = sanitizeloc($_POST['invitation_id'] ?? 0, 'int');
                
                $stmt = $pdo->prepare("
                    UPDATE coach_athlete_invitations 
                    SET status = 'expired' 
                    WHERE id = ? AND coach_id = ? AND status = 'pending'
                ");
                $result = $stmt->execute([$invitationId, $coachId]);
                
                jsresloc($result, $result ? 'Invitation cancelled' : 'Failed to cancel invitation');
                break;
                
            // ===== TRAINING SCHEDULE MANAGEMENT =====
            case 'add_training_schedule':
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                $title = sanitizeloc($_POST['title'] ?? '');
                $type = sanitizeloc($_POST['type'] ?? 'technical');
                $scheduleDate = sanitizeloc($_POST['schedule_date'] ?? '', 'date');
                $startTime = sanitizeloc($_POST['start_time'] ?? '');
                $duration = sanitizeloc($_POST['duration'] ?? 60, 'int');
                $location = sanitizeloc($_POST['location'] ?? '');
                $description = sanitizeloc($_POST['description'] ?? '');
                $intensity = sanitizeloc($_POST['intensity'] ?? 'moderate');
                
                if (!$athleteId || !$title || !$scheduleDate || !$startTime) {
                    jsresloc(false, 'Required fields are missing');
                }
                
                // Verify coach has access to this athlete
                $stmt = $pdo->prepare("
                    SELECT id FROM athlete_profiles 
                    WHERE user_id = ? AND coach_id = ? AND is_active = 1
                ");
                $stmt->execute([$athleteId, $coachId]);
                if (!$stmt->fetch()) {
                    jsresloc(false, 'You do not have access to this athlete');
                }
                
                // Calculate end time
                $endTime = date('H:i:s', strtotime($startTime) + ($duration * 60));
                
                $stmt = $pdo->prepare("
                    INSERT INTO training_schedule 
                    (user_id, coach_id, title, type, description, schedule_date, start_time, end_time, 
                     duration, location, intensity, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");
                
                $result = $stmt->execute([
                    $athleteId, $coachId, $title, $type, $description, 
                    $scheduleDate, $startTime, $endTime, $duration, $location, $intensity
                ]);
                
                jsresloc($result, $result ? 'Training scheduled successfully' : 'Failed to schedule training');
                break;
                
            case 'get_training_schedules':
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                
                $sql = "
                    SELECT ts.*, u.full_name as athlete_name 
                    FROM training_schedule ts
                    JOIN users u ON ts.user_id = u.id
                    WHERE ts.coach_id = ? AND ts.is_active = 1
                ";
                
                $params = [$coachId];
                if ($athleteId > 0) {
                    $sql .= " AND ts.user_id = ?";
                    $params[] = $athleteId;
                }
                
                $sql .= " ORDER BY ts.schedule_date DESC, ts.start_time DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                jsresloc(true, 'Schedules retrieved', $schedules);
                break;
                
            case 'update_training_status':
                $scheduleId = sanitizeloc($_POST['schedule_id'] ?? 0, 'int');
                $status = sanitizeloc($_POST['status'] ?? '');
                
                if (!in_array($status, ['scheduled', 'in_progress', 'completed', 'cancelled'])) {
                    jsresloc(false, 'Invalid status');
                }
                
                $stmt = $pdo->prepare("
                    UPDATE training_schedule 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ? AND coach_id = ?
                ");
                $result = $stmt->execute([$status, $scheduleId, $coachId]);
                
                jsresloc($result, $result ? 'Status updated' : 'Failed to update status');
                break;
                
            // ===== TRAINING RESULTS MANAGEMENT =====
            case 'add_training_result':
                $scheduleId = sanitizeloc($_POST['schedule_id'] ?? 0, 'int');
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                $performanceScore = sanitizeloc($_POST['performance_score'] ?? 0, 'float');
                $distanceCovered = sanitizeloc($_POST['distance_covered'] ?? 0, 'float');
                $durationMinutes = sanitizeloc($_POST['duration_minutes'] ?? 0, 'int');
                $heartRateAvg = sanitizeloc($_POST['heart_rate_avg'] ?? 0, 'int');
                $heartRateMax = sanitizeloc($_POST['heart_rate_max'] ?? 0, 'int');
                $caloriesBurned = sanitizeloc($_POST['calories_burned'] ?? 0, 'int');
                $notes = sanitizeloc($_POST['notes'] ?? '');
                $feedback = sanitizeloc($_POST['feedback'] ?? '');
                
                if (!$scheduleId || !$athleteId) {
                    jsresloc(false, 'Schedule ID and Athlete ID are required');
                }
                
                // Verify coach has access
                $stmt = $pdo->prepare("
                    SELECT id FROM training_schedule 
                    WHERE id = ? AND coach_id = ? AND user_id = ?
                ");
                $stmt->execute([$scheduleId, $coachId, $athleteId]);
                if (!$stmt->fetch()) {
                    jsresloc(false, 'Invalid training session');
                }
                
                // Add training result
                $stmt = $pdo->prepare("
                    INSERT INTO training_results 
                    (training_schedule_id, athlete_id, coach_id, performance_score, 
                     distance_covered, duration_minutes, heart_rate_avg, heart_rate_max, 
                     calories_burned, notes, feedback) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    $scheduleId, $athleteId, $coachId, $performanceScore,
                    $distanceCovered, $durationMinutes, $heartRateAvg, $heartRateMax,
                    $caloriesBurned, $notes, $feedback
                ]);
                
                if ($result) {
                    // Update training status to completed
                    $stmt = $pdo->prepare("
                        UPDATE training_schedule 
                        SET status = 'completed' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$scheduleId]);
                    
                    // Update athlete performance metrics
                    updateAthleteMetrics($pdo, $athleteId, $performanceScore);
                    
                    jsresloc(true, 'Training result saved successfully');
                } else {
                    jsresloc(false, 'Failed to save training result');
                }
                break;
                
            case 'get_training_results':
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                $period = sanitizeloc($_POST['period'] ?? 30, 'int');
                
                $sql = "
                    SELECT tr.*, ts.title, ts.type, u.full_name as athlete_name 
                    FROM training_results tr
                    JOIN training_schedule ts ON tr.training_schedule_id = ts.id
                    JOIN users u ON tr.athlete_id = u.id
                    WHERE tr.coach_id = ? 
                    AND tr.completed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ";
                
                $params = [$coachId, $period];
                if ($athleteId > 0) {
                    $sql .= " AND tr.athlete_id = ?";
                    $params[] = $athleteId;
                }
                
                $sql .= " ORDER BY tr.completed_at DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                jsresloc(true, 'Results retrieved', $results);
                break;
                
            case 'get_performance_data':
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                $period = sanitizeloc($_POST['period'] ?? 30, 'int');
                
                $sql = "
                    SELECT 
                        DATE(tr.completed_at) as date,
                        AVG(tr.performance_score) as avg_score,
                        AVG(tr.distance_covered) as avg_distance,
                        AVG(tr.duration_minutes) as avg_duration,
                        AVG(tr.heart_rate_avg) as avg_heart_rate,
                        COUNT(*) as session_count
                    FROM training_results tr
                    WHERE tr.coach_id = ? 
                    AND tr.completed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ";
                
                $params = [$coachId, $period];
                if ($athleteId > 0) {
                    $sql .= " AND tr.athlete_id = ?";
                    $params[] = $athleteId;
                }
                
                $sql .= " GROUP BY DATE(tr.completed_at) ORDER BY date ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                jsresloc(true, 'Performance data retrieved', $data);
                break;
                
            // ===== ATHLETE MANAGEMENT =====
            case 'get_my_athletes':
                $stmt = $pdo->prepare("
                    SELECT 
                        u.id, u.full_name, u.username, u.email,
                        ap.sport, ap.position, ap.team_name,
                        COUNT(DISTINCT ts.id) as total_sessions,
                        COUNT(DISTINCT tr.id) as completed_sessions,
                        AVG(tr.performance_score) as avg_performance
                    FROM users u
                    JOIN athlete_profiles ap ON u.id = ap.user_id
                    LEFT JOIN training_schedule ts ON u.id = ts.user_id AND ts.coach_id = ?
                    LEFT JOIN training_results tr ON u.id = tr.athlete_id AND tr.coach_id = ?
                    WHERE ap.coach_id = ? AND ap.is_active = 1
                    GROUP BY u.id
                    ORDER BY u.full_name ASC
                ");
                $stmt->execute([$coachId, $coachId, $coachId]);
                $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                jsresloc(true, 'Athletes retrieved', $athletes);
                break;
                
            case 'remove_athlete':
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                
                $stmt = $pdo->prepare("
                    UPDATE athlete_profiles 
                    SET is_active = 0, coach_id = NULL 
                    WHERE user_id = ? AND coach_id = ?
                ");
                $result = $stmt->execute([$athleteId, $coachId]);
                
                jsresloc($result, $result ? 'Athlete removed' : 'Failed to remove athlete');
                break;
                
            // ===== DASHBOARD STATS =====
            case 'get_dashboard_stats':
                $stats = [];
                
                // Total athletes
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM athlete_profiles 
                    WHERE coach_id = ? AND is_active = 1
                ");
                $stmt->execute([$coachId]);
                $stats['total_athletes'] = $stmt->fetchColumn();
                
                // Training sessions this month
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM training_schedule 
                    WHERE coach_id = ? AND is_active = 1 
                    AND MONTH(schedule_date) = MONTH(CURRENT_DATE()) 
                    AND YEAR(schedule_date) = YEAR(CURRENT_DATE())
                ");
                $stmt->execute([$coachId]);
                $stats['monthly_sessions'] = $stmt->fetchColumn();
                
                // Completed sessions
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM training_schedule 
                    WHERE coach_id = ? AND status = 'completed' 
                    AND schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmt->execute([$coachId]);
                $stats['completed_sessions'] = $stmt->fetchColumn();
                
                // Average performance
                $stmt = $pdo->prepare("
                    SELECT AVG(performance_score) FROM training_results 
                    WHERE coach_id = ? 
                    AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmt->execute([$coachId]);
                $stats['avg_performance'] = round($stmt->fetchColumn() ?: 0, 1);
                
                jsresloc(true, 'Stats retrieved', $stats);
                break;
<<<<<<< Updated upstream
                
=======

            case 'sync_athletes':
                $synced = ensureAthleteProfiles($pdo, $coachId);
                jsresloc(true, "Synchronized $synced athlete profiles");
                break;

            case 'create_metric_template':
                $templateName = sanitizeloc($_POST['template_name'] ?? '');
                $sportCategory = sanitizeloc($_POST['sport_category'] ?? 'general');
                $fields = json_decode($_POST['fields'] ?? '[]', true);
                
                if (empty($templateName) || empty($fields)) {
                    jsresloc(false, 'Template name and fields are required');
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    // Insert template
                    $stmt = $pdo->prepare("
                        INSERT INTO training_metric_templates (coach_id, template_name, sport_category) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$coachId, $templateName, $sportCategory]);
                    $templateId = $pdo->lastInsertId();
                    
                    // Insert fields
                    $stmt = $pdo->prepare("
                        INSERT INTO training_metric_fields 
                        (template_id, field_name, field_type, field_unit, field_options, min_value, max_value, is_required, display_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($fields as $index => $field) {
                        $stmt->execute([
                            $templateId,
                            $field['name'],
                            $field['type'],
                            $field['unit'] ?? null,
                            isset($field['options']) ? json_encode($field['options']) : null,
                            $field['min'] ?? null,
                            $field['max'] ?? null,
                            $field['required'] ?? 0,
                            $index
                        ]);
                    }
                    
                    $pdo->commit();
                    jsresloc(true, 'Template created successfully');
                    
                } catch (Exception $e) {
                    $pdo->rollback();
                    jsresloc(false, 'Failed to create template: ' . $e->getMessage());
                }
                break;

            case 'get_metric_templates':
                $stmt = $pdo->prepare("
                    SELECT t.*, 
                        COUNT(f.id) as field_count,
                        GROUP_CONCAT(f.field_name ORDER BY f.display_order) as field_names
                    FROM training_metric_templates t
                    LEFT JOIN training_metric_fields f ON t.id = f.template_id
                    WHERE t.coach_id = ?
                    GROUP BY t.id
                    ORDER BY t.template_name
                ");
                $stmt->execute([$coachId]);
                $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsresloc(true, 'Templates retrieved', $templates);
                break;

            case 'get_template_fields':
                $templateId = sanitizeloc($_POST['template_id'] ?? 0, 'int');
                
                $stmt = $pdo->prepare("
                    SELECT * FROM training_metric_fields 
                    WHERE template_id = ? 
                    ORDER BY display_order
                ");
                $stmt->execute([$templateId]);
                $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsresloc(true, 'Fields retrieved', $fields);
                break;

            case 'add_training_result_with_template':
                $scheduleId = sanitizeloc($_POST['schedule_id'] ?? 0, 'int');
                $athleteId = sanitizeloc($_POST['athlete_id'] ?? 0, 'int');
                $templateId = sanitizeloc($_POST['template_id'] ?? 0, 'int');
                $customData = json_decode($_POST['custom_data'] ?? '{}', true);
                
                // Basic validation
                if (!$scheduleId || !$athleteId) {
                    jsresloc(false, 'Schedule ID and Athlete ID are required');
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    // Insert basic training result
                    $stmt = $pdo->prepare("
                        INSERT INTO training_results 
                        (training_schedule_id, athlete_id, coach_id, performance_score, notes, feedback) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $scheduleId, 
                        $athleteId, 
                        $coachId, 
                        $customData['overall_score'] ?? 0,
                        $customData['notes'] ?? '',
                        $customData['feedback'] ?? ''
                    ]);
                    
                    $resultId = $pdo->lastInsertId();
                    
                    // Insert custom field data
                    if ($templateId > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO training_result_data (training_result_id, field_name, field_value) 
                            VALUES (?, ?, ?)
                        ");
                        
                        foreach ($customData as $fieldName => $fieldValue) {
                            if (!in_array($fieldName, ['overall_score', 'notes', 'feedback'])) {
                                $stmt->execute([$resultId, $fieldName, $fieldValue]);
                            }
                        }
                    }
                    
                    // Update training status
                    $stmt = $pdo->prepare("UPDATE training_schedule SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$scheduleId]);
                    
                    $pdo->commit();
                    jsresloc(true, 'Training result saved successfully');
                    
                } catch (Exception $e) {
                    $pdo->rollback();
                    jsresloc(false, 'Failed to save result: ' . $e->getMessage());
                }
                break;

>>>>>>> Stashed changes
            default:
                jsresloc(false, 'Unknown action');
        }
    } catch (Exception $e) {
        error_log("Coach dashboard error: " . $e->getMessage());
        jsresloc(false, 'An error occurred: ' . $e->getMessage());
    }
}

// Helper function to update athlete metrics
function updateAthleteMetrics($pdo, $athleteId, $performanceScore) {
    $today = date('Y-m-d');
    
    // Get average scores for today
    $stmt = $pdo->prepare("
        SELECT 
            AVG(performance_score) as avg_performance,
            AVG(distance_covered/duration_minutes) as speed_avg
        FROM training_results 
        WHERE athlete_id = ? AND DATE(completed_at) = ?
    ");
    $stmt->execute([$athleteId, $today]);
    $dayStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update or insert metrics
    $stmt = $pdo->prepare("
        INSERT INTO athlete_performance_metrics 
        (athlete_id, metric_date, overall_performance, speed_avg) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        overall_performance = VALUES(overall_performance),
        speed_avg = VALUES(speed_avg)
    ");
    
    $stmt->execute([
        $athleteId, 
        $today, 
        $dayStats['avg_performance'] ?: $performanceScore,
        $dayStats['speed_avg'] ?: 0
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coach Dashboard - Professional Sports Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                radial-gradient(400px circle at 80% 70%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
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
        }

        .btn-danger {
            background: var(--accent-danger);
            color: white;
        }

        .btn-info {
            background: var(--accent-info);
            color: white;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
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

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--surface-bg);
            margin: 5% auto;
            padding: 2rem;
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            position: relative;
            box-shadow: var(--shadow-xl);
        }

        .close {
            position: absolute;
            right: 1.5rem;
            top: 1.5rem;
            font-size: 1.5rem;
            color: var(--text-tertiary);
            cursor: pointer;
            transition: color var(--transition-base);
        }

        .close:hover {
            color: var(--text-primary);
        }

        .athlete-card {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
            transition: all var(--transition-base);
        }

        .athlete-card:hover {
            background: var(--elevated-bg);
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .athlete-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .athlete-avatar {
            width: 60px;
            height: 60px;
            background: var(--accent-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.25rem;
            color: white;
        }

        .athlete-details h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .athlete-details p {
            font-size: 0.9rem;
            color: var(--text-tertiary);
        }

        .athlete-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--glass-bg);
            border-radius: 8px;
            border: 1px solid var(--glass-border);
        }

        .stat-item-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-item-label {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            text-transform: uppercase;
            margin-top: 0.25rem;
        }

        .invitation-card {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem;
            margin: 0.5rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .invitation-status {
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: var(--accent-warning);
        }

        .status-accepted {
            background: rgba(16, 185, 129, 0.2);
            color: var(--accent-success);
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: var(--accent-danger);
        }

        .training-card {
            background: var(--surface-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .training-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .training-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .training-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-tertiary);
            font-size: 0.9rem;
        }

        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
            margin: 2rem 0;
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

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-toggle {
                display: block;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="background-layer"></div>
    
    <button class="mobile-toggle" id="mobileToggle">☰</button>

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">Coach<span>Pro</span></div>
                <p class="sidebar-subtitle">Professional Coach Dashboard</p>
                
                <div class="user-info" style="background: var(--surface-bg); padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                    <strong>Coach:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?><br>
                    <small>Session: <?php echo isset($_SESSION['login_time']) ? date('H:i, d M Y', $_SESSION['login_time']) : 'Unknown'; ?></small>
                </div>
                
                <button onclick="logout()" class="btn btn-danger" style="width: 100%; margin-top: 1rem;">
                    🚪 Logout
                </button>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <div class="nav-link active" onclick="switchSection('dashboard')" data-section="dashboard">
                        <span>📊</span> Dashboard
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('athletes')" data-section="athletes">
                        <span>👥</span> My Athletes
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('invitations')" data-section="invitations">
                        <span>📧</span> Invitations
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('schedule')" data-section="schedule">
                        <span>📅</span> Training Schedule
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('results')" data-section="results">
                        <span>📈</span> Training Results
                    </div>
                </li>
                <li class="nav-item">
                    <div class="nav-link" onclick="switchSection('analytics')" data-section="analytics">
                        <span>📊</span> Performance Analytics
                    </div>
                </li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="content-header">
                <h1 class="page-title" id="pageTitle">Dashboard Overview</h1>
                <div>
                    <span style="color: var(--text-tertiary); font-size: 0.9rem;">
                        <?php echo date('M j, Y g:i A'); ?>
                    </span>
                </div>
            </div>

            <!-- Dashboard Section -->
            <section class="content-section active" id="dashboard-section">
                <div class="stats-grid" id="statsGrid">
                    <div class="loading" style="margin: 2rem auto;"></div>
                </div>
                
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Quick Actions</h2>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <button class="btn btn-primary" onclick="showInviteModal()">
                            ➕ Invite Athlete
                        </button>
                        <button class="btn btn-success" onclick="showScheduleModal()">
                            📅 Schedule Training
                        </button>
                        <button class="btn btn-info" onclick="showResultModal()">
                            📝 Add Training Result
                        </button>
                        <button class="btn btn-primary" onclick="switchSection('analytics')">
                            📊 View Analytics
                        </button>
                    </div>
                </div>
            </section>

            <!-- Athletes Section -->
            <section class="content-section" id="athletes-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">My Athletes</h2>
                        <button class="btn btn-primary" onclick="showInviteModal()">
                            ➕ Invite New Athlete
                        </button>
                    </div>
                    <div id="athletesContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Invitations Section -->
            <section class="content-section" id="invitations-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Athlete Invitations</h2>
                        <button class="btn btn-primary" onclick="showInviteModal()">
                            ➕ Send New Invitation
                        </button>
                    </div>
                    <div id="invitationsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Training Schedule Section -->
            <section class="content-section" id="schedule-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Training Schedule</h2>
                        <button class="btn btn-primary" onclick="showScheduleModal()">
                            ➕ Add Training Session
                        </button>
                    </div>
                    <div id="scheduleContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Training Results Section -->
            <section class="content-section" id="results-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Training Results</h2>
                        <button class="btn btn-primary" onclick="showResultModal()">
                            ➕ Add Result
                        </button>
                    </div>
                    <div id="resultsContainer">
                        <div class="loading" style="margin: 2rem auto; display: block;"></div>
                    </div>
                </div>
            </section>

            <!-- Analytics Section -->
            <section class="content-section" id="analytics-section">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Performance Analytics</h2>
                        <select class="form-select" style="width: 200px;" onchange="updateAnalytics(this.value)">
                            <option value="7">Last 7 Days</option>
                            <option value="30" selected>Last 30 Days</option>
                            <option value="90">Last 3 Months</option>
                        </select>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Modals -->
    <!-- Invite Athlete Modal -->
    <div id="inviteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('inviteModal')">&times;</span>
            <h2>Invite Athlete</h2>
            <form id="inviteForm" novalidate>
                <div class="form-group">
                    <label class="form-label">Athlete Username <span style="color: red;">*</span></label>
                    <input type="text" class="form-input" id="invite-username" placeholder="Enter athlete's username">
                </div>
                <div class="form-group">
                    <label class="form-label">Personal Message</label>
                    <textarea class="form-textarea" id="invite-message" rows="3" placeholder="Add a personal message (optional)"></textarea>
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal('inviteModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Invitation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Schedule Training Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('scheduleModal')">&times;</span>
            <h2>Schedule Training Session</h2>
            <form id="scheduleForm" novalidate>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Athlete <span style="color: red;">*</span></label>
                        <select class="form-select" id="schedule-athlete">
                            <option value="">Select Athlete</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Training Type <span style="color: red;">*</span></label>
                        <select class="form-select" id="schedule-type">
                            <option value="strength">Strength</option>
                            <option value="cardio">Cardio</option>
                            <option value="tactical">Tactical</option>
                            <option value="technical">Technical</option>
                            <option value="flexibility">Flexibility</option>
                            <option value="recovery">Recovery</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Session Title <span style="color: red;">*</span></label>
                    <input type="text" class="form-input" id="schedule-title" placeholder="e.g., Morning Strength Training">
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Date <span style="color: red;">*</span></label>
                        <input type="date" class="form-input" id="schedule-date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Time <span style="color: red;">*</span></label>
                        <input type="time" class="form-input" id="schedule-time">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Duration (minutes) <span style="color: red;">*</span></label>
                        <input type="number" class="form-input" id="schedule-duration" value="60" min="15" max="240">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Intensity</label>
                        <select class="form-select" id="schedule-intensity">
                            <option value="low">Low</option>
                            <option value="moderate" selected>Moderate</option>
                            <option value="high">High</option>
                            <option value="maximum">Maximum</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-input" id="schedule-location" placeholder="Training Center, Field A, etc.">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="schedule-description" rows="3" placeholder="Training objectives and exercises..."></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal('scheduleModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Schedule Training</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Training Result Modal -->
    <div id="resultModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeModal('resultModal')">&times;</span>
            <h2>Add Training Result</h2>
            <form id="resultForm" novalidate>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Training Session <span style="color: red;">*</span></label>
                        <select class="form-select" id="result-session" onchange="updateResultAthlete(this.value)">
                            <option value="">Select Session</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metric Template</label>
                        <select class="form-select" id="result-template" onchange="loadTemplateFields(this.value)">
                            <option value="">Select Template</option>
                        </select>
                        <button type="button" class="btn btn-info" onclick="showCreateTemplateModal()" style="margin-top: 0.5rem; width: 100%;">
                            Create New Template
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Overall Performance Score (0-100) <span style="color: red;">*</span></label>
                    <input type="number" class="form-input" id="result-overall-score" min="0" max="100">
                </div>
                
                <!-- Dynamic custom fields container -->
                <div id="custom-fields-container"></div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea class="form-textarea" id="result-notes" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Feedback for Athlete</label>
                    <textarea class="form-textarea" id="result-feedback" rows="2"></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal('resultModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Result</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Global variables
    const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
    let currentSection = 'dashboard';
    let athletesData = [];
    let schedulesData = [];
    let performanceChart = null;

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        loadDashboardStats();
        setupEventListeners();
        
        // Set today's date as default
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('schedule-date').value = today;

        cleanupRequiredAttributes();
    });

    function cleanupRequiredAttributes() {
        console.log('Cleaning up required attributes...');
        const requiredFields = document.querySelectorAll('[required]');
        
        requiredFields.forEach(field => {
            console.log('Removing required from:', field.id || field.name || field.className);
            field.removeAttribute('required');
        });
        
        console.log(`Cleaned ${requiredFields.length} required attributes`);
    }

    // Debug function - tambahkan setelah DOMContentLoaded
    function debugAllRequiredFields() {
        console.log('=== CHECKING ALL REQUIRED FIELDS ===');
        const requiredFields = document.querySelectorAll('[required]');
        requiredFields.forEach((field, index) => {
            const rect = field.getBoundingClientRect();
            const isVisible = rect.width > 0 && rect.height > 0 && field.style.display !== 'none';
            const isHidden = field.type === 'hidden';
            
            console.log(`Field ${index}:`, {
                id: field.id,
                name: field.name,
                class: field.className,
                type: field.type,
                required: field.required,
                visible: isVisible,
                hidden: isHidden,
                element: field
            });
            
            if (field.required && !isVisible && !isHidden) {
                console.error('PROBLEMATIC FIELD:', field);
                field.required = false; // Hapus required dari field bermasalah
                console.log('Removed required attribute from problematic field');
            }
        });
        console.log('=== END DEBUG ===');
    }

    // Panggil sebelum form submit
    document.addEventListener('submit', function(e) {
        console.log('Form submission detected, checking required fields...');
        debugAllRequiredFields();
    }, true);

    // Setup event listeners
    function setupEventListeners() {
        // Form submissions
        document.getElementById('inviteForm').addEventListener('submit', handleInviteSubmit);
        document.getElementById('scheduleForm').addEventListener('submit', handleScheduleSubmit);
        document.getElementById('resultForm').addEventListener('submit', handleResultSubmit);
        
        // Mobile toggle
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
    }

    // Section switching
    function switchSection(section) {
        // Update active nav
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`[data-section="${section}"]`).classList.add('active');
        
        // Update active section
        document.querySelectorAll('.content-section').forEach(sec => {
            sec.classList.remove('active');
        });
        document.getElementById(`${section}-section`).classList.add('active');
        
        // Update page title
        const titles = {
            dashboard: 'Dashboard Overview',
            athletes: 'My Athletes',
            invitations: 'Athlete Invitations',
            schedule: 'Training Schedule',
            results: 'Training Results',
            analytics: 'Performance Analytics'
        };
        document.getElementById('pageTitle').textContent = titles[section];
        
        // Load section data
        currentSection = section;
        loadSectionData(section);
        
        // Close mobile menu
        document.getElementById('sidebar').classList.remove('mobile-open');
    }

    // Load section data
    function loadSectionData(section) {
        switch(section) {
            case 'dashboard':
                loadDashboardStats();
                break;
            case 'athletes':
                loadAthletes();
                break;
            case 'invitations':
                loadInvitations();
                break;
            case 'schedule':
                loadSchedules();
                break;
            case 'results':
                loadResults();
                break;
            case 'analytics':
                loadAnalytics();
                break;
            case 'templates':
                loadTemplates();
                break;
        }
    }

    // API request helper
    async function makeRequest(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);
        
        for (let key in data) {
            formData.append(key, data[key]);
        }
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            return result;
        } catch (error) {
            console.error('Request error:', error);
            return { success: false, message: 'Network error' };
        }
    }

    // Show notification
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            background: ${type === 'success' ? 'var(--accent-success)' : 'var(--accent-danger)'};
            color: white;
            border-radius: 8px;
            z-index: 3000;
            animation: slideIn 0.3s ease;
        `;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Dashboard functions
    async function loadDashboardStats() {
        const result = await makeRequest('get_dashboard_stats');
        if (result.success) {
            const stats = result.data;
            document.getElementById('statsGrid').innerHTML = `
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Athletes</span>
                        <span class="stat-icon">👥</span>
                    </div>
                    <div class="stat-value">${stats.total_athletes || 0}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Monthly Sessions</span>
                        <span class="stat-icon">📅</span>
                    </div>
                    <div class="stat-value">${stats.monthly_sessions || 0}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Completed Sessions</span>
                        <span class="stat-icon">✅</span>
                    </div>
                    <div class="stat-value">${stats.completed_sessions || 0}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Avg Performance</span>
                        <span class="stat-icon">📈</span>
                    </div>
                    <div class="stat-value">${stats.avg_performance || 0}%</div>
                </div>
            `;
        }
    }

    // Athletes functions
    async function loadAthletes() {
        const result = await makeRequest('get_my_athletes');
        if (result.success) {
            athletesData = result.data || [];
            const container = document.getElementById('athletesContainer');
            
            if (athletesData.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No athletes yet</h3>
                        <p>Start by inviting athletes to join your training program</p>
                        <button class="btn btn-primary" onclick="showInviteModal()">
                            Send First Invitation
                        </button>
                    </div>
                `;
            } else {
                container.innerHTML = athletesData.map(athlete => `
                    <div class="athlete-card">
                        <div class="athlete-info">
                            <div class="athlete-avatar">
                                ${getInitials(athlete.full_name)}
                            </div>
                            <div class="athlete-details">
                                <h3>${athlete.full_name}</h3>
                                <p>@${athlete.username} • ${athlete.sport || 'Athlete'} • ${athlete.position || 'N/A'}</p>
                            </div>
                        </div>
                        <div class="athlete-stats">
                            <div class="stat-item">
                                <div class="stat-item-value">${athlete.total_sessions || 0}</div>
                                <div class="stat-item-label">Sessions</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-item-value">${athlete.completed_sessions || 0}</div>
                                <div class="stat-item-label">Completed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-item-value">${Math.round(athlete.avg_performance || 0)}%</div>
                                <div class="stat-item-label">Performance</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                            <button class="btn btn-primary" onclick="scheduleTrainingFor(${athlete.id})">
                                📅 Schedule Training
                            </button>
                            <button class="btn btn-info" onclick="viewAthleteDetails(${athlete.id})">
                                📊 View Details
                            </button>
                            <button class="btn btn-danger" onclick="removeAthlete(${athlete.id})">
                                🗑️ Remove
                            </button>
                        </div>
                    </div>
                `).join('');
            }
            
            // Update athlete selects
            updateAthleteSelects();
        }
    }

    // Invitations functions
    async function loadInvitations() {
        const result = await makeRequest('get_invitations');
        if (result.success) {
            const invitations = result.data || [];
            const container = document.getElementById('invitationsContainer');
            
            if (invitations.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No invitations sent</h3>
                        <p>Invite athletes to join your training program</p>
                    </div>
                `;
            } else {
                container.innerHTML = invitations.map(inv => `
                    <div class="invitation-card">
                        <div>
                            <strong>@${inv.athlete_username}</strong>
                            ${inv.athlete_name ? `(${inv.athlete_name})` : ''}
                            <br>
                            <small>${new Date(inv.created_at).toLocaleDateString()}</small>
                        </div>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span class="invitation-status status-${inv.status}">${inv.status}</span>
                            ${inv.status === 'pending' ? `
                                <button class="btn btn-danger" onclick="cancelInvitation(${inv.id})">
                                    Cancel
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `).join('');
            }
        }
    }

    // Training Schedule functions
    async function loadSchedules() {
        const result = await makeRequest('get_training_schedules');
        if (result.success) {
            schedulesData = result.data || [];
            const container = document.getElementById('scheduleContainer');
            
            if (schedulesData.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No training sessions scheduled</h3>
                        <p>Create training sessions for your athletes</p>
                    </div>
                `;
            } else {
                // Group by date
                const grouped = {};
                schedulesData.forEach(session => {
                    const date = session.schedule_date;
                    if (!grouped[date]) grouped[date] = [];
                    grouped[date].push(session);
                });
                
                let html = '';
                for (const date in grouped) {
                    html += `
                        <h3 style="margin: 1.5rem 0 1rem; color: var(--text-secondary);">
                            ${new Date(date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                        </h3>
                    `;
                    
                    html += grouped[date].map(session => `
                        <div class="training-card">
                            <div class="training-header">
                                <div>
                                    <h3 class="training-title">${session.title}</h3>
                                    <p style="color: var(--text-tertiary);">${session.athlete_name}</p>
                                </div>
                                <span class="badge badge-${getStatusColor(session.status)}">
                                    ${session.status}
                                </span>
                            </div>
                            <div class="training-meta">
                                <div class="meta-item">
                                    <span>🕐</span> ${session.start_time} (${session.duration} min)
                                </div>
                                <div class="meta-item">
                                    <span>📍</span> ${session.location || 'TBD'}
                                </div>
                                <div class="meta-item">
                                    <span>🏃</span> ${session.type}
                                </div>
                                <div class="meta-item">
                                    <span>💪</span> ${session.intensity || 'moderate'}
                                </div>
                            </div>
                            ${session.description ? `
                                <p style="margin-top: 1rem; color: var(--text-tertiary);">${session.description}</p>
                            ` : ''}
                            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                ${session.status === 'scheduled' ? `
                                    <button class="btn btn-success" onclick="markAsCompleted(${session.id})">
                                        ✅ Mark Completed
                                    </button>
                                    <button class="btn btn-info" onclick="addResultForSession(${session.id}, ${session.user_id})">
                                        📝 Add Result
                                    </button>
                                ` : ''}
                                <button class="btn btn-danger" onclick="cancelSession(${session.id})">
                                    ❌ Cancel
                                </button>
                            </div>
                        </div>
                    `).join('');
                }
                
                container.innerHTML = html || '<div class="empty-state"><h3>No sessions found</h3></div>';
            }
            
            // Update session select in result modal
            updateSessionSelect();
        }
    }

    // Training Results functions
    async function loadResults() {
        const result = await makeRequest('get_training_results');
        if (result.success) {
            const results = result.data || [];
            const container = document.getElementById('resultsContainer');
            
            if (results.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No training results yet</h3>
                        <p>Add results after completing training sessions</p>
                    </div>
                `;
            } else {
                container.innerHTML = results.map(result => `
                    <div class="training-card">
                        <div class="training-header">
                            <div>
                                <h3 class="training-title">${result.title}</h3>
                                <p style="color: var(--text-tertiary);">${result.athlete_name} • ${new Date(result.completed_at).toLocaleDateString()}</p>
                            </div>
                            <div class="badge badge-${getScoreColor(result.performance_score)}">
                                Score: ${result.performance_score}%
                            </div>
                        </div>
                        <div class="training-meta">
                            ${result.distance_covered ? `
                                <div class="meta-item">
                                    <span>🏃</span> ${result.distance_covered} km
                                </div>
                            ` : ''}
                            ${result.duration_minutes ? `
                                <div class="meta-item">
                                    <span>⏱️</span> ${result.duration_minutes} min
                                </div>
                            ` : ''}
                            ${result.calories_burned ? `
                                <div class="meta-item">
                                    <span>🔥</span> ${result.calories_burned} cal
                                </div>
                            ` : ''}
                            ${result.heart_rate_avg ? `
                                <div class="meta-item">
                                    <span>❤️</span> ${result.heart_rate_avg} bpm avg
                                </div>
                            ` : ''}
                        </div>
                        ${result.notes ? `
                            <p style="margin-top: 1rem; color: var(--text-tertiary);">
                                <strong>Notes:</strong> ${result.notes}
                            </p>
                        ` : ''}
                        ${result.feedback ? `
                            <p style="margin-top: 0.5rem; color: var(--text-tertiary);">
                                <strong>Feedback:</strong> ${result.feedback}
                            </p>
                        ` : ''}
                    </div>
                `).join('');
            }
        }
    }

    // Analytics functions
    async function loadAnalytics(period = 30) {
        const result = await makeRequest('get_performance_data', { period: period });
        if (result.success) {
            const data = result.data || [];
            
            if (data.length > 0) {
                drawPerformanceChart(data);
            } else {
                document.querySelector('.chart-container').innerHTML = `
                    <div class="empty-state">
                        <h3>No performance data available</h3>
                        <p>Start recording training results to see analytics</p>
                    </div>
                `;
            }
        }
    }

    function drawPerformanceChart(data) {
        const ctx = document.getElementById('performanceChart').getContext('2d');
        
        if (performanceChart) {
            performanceChart.destroy();
        }
        
        performanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => new Date(d.date).toLocaleDateString()),
                datasets: [
                    {
                        label: 'Average Performance Score',
                        data: data.map(d => d.avg_score),
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.3
                    },
                    {
                        label: 'Sessions Count',
                        data: data.map(d => d.session_count * 10),
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: 'rgb(229, 229, 229)'
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: 'rgb(163, 163, 163)'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            color: 'rgb(163, 163, 163)'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        ticks: {
                            color: 'rgb(163, 163, 163)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }

    function updateAnalytics(period) {
        loadAnalytics(period);
    }

    // Form handlers
    async function handleInviteSubmit(e) {
        e.preventDefault();
        
        const username = document.getElementById('invite-username').value;
        const message = document.getElementById('invite-message').value;
        
        // Manual validation
        if (!username || username.trim() === '') {
            showNotification('Athlete username is required', 'error');
            document.getElementById('invite-username').focus();
            return;
        }
        
        const result = await makeRequest('send_invitation', {
            username: username.trim(),
            message: message
        });
        
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            closeModal('inviteModal');
            document.getElementById('inviteForm').reset();
            if (currentSection === 'invitations') {
                loadInvitations();
            }
        }
    }

    async function handleScheduleSubmit(e) {
        e.preventDefault();
        
        // Manual validation
        const athleteId = document.getElementById('schedule-athlete').value;
        const title = document.getElementById('schedule-title').value;
        const scheduleDate = document.getElementById('schedule-date').value;
        const startTime = document.getElementById('schedule-time').value;
        const duration = document.getElementById('schedule-duration').value;
        
        if (!athleteId) {
            showNotification('Please select an athlete', 'error');
            document.getElementById('schedule-athlete').focus();
            return;
        }
        if (!title || title.trim() === '') {
            showNotification('Session title is required', 'error');
            document.getElementById('schedule-title').focus();
            return;
        }
        if (!scheduleDate) {
            showNotification('Date is required', 'error');
            document.getElementById('schedule-date').focus();
            return;
        }
        if (!startTime) {
            showNotification('Start time is required', 'error');
            document.getElementById('schedule-time').focus();
            return;
        }
        
        const data = {
            athlete_id: athleteId,
            title: title.trim(),
            type: document.getElementById('schedule-type').value,
            schedule_date: scheduleDate,
            start_time: startTime,
            duration: duration,
            intensity: document.getElementById('schedule-intensity').value,
            location: document.getElementById('schedule-location').value,
            description: document.getElementById('schedule-description').value
        };
        
        const result = await makeRequest('add_training_schedule', data);
        
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            closeModal('scheduleModal');
            document.getElementById('scheduleForm').reset();
            if (currentSection === 'schedule') {
                loadSchedules();
            }
        }
    }

    async function handleResultSubmit(e) {
        e.preventDefault();
        
        const scheduleId = document.getElementById('result-session').value;
        const templateId = document.getElementById('result-template').value;
        const overallScore = document.getElementById('result-overall-score').value;
        
        // Validasi manual tanpa menggunakan browser validation
        if (!scheduleId) {
            showNotification('Please select a training session', 'error');
            return;
        }
        
        if (!overallScore) {
            showNotification('Overall performance score is required', 'error');
            return;
        }
        
        // Validasi custom fields dengan data-required
        if (templateId) {
            const customFields = document.querySelectorAll('#custom-fields-container input, #custom-fields-container select');
            for (let field of customFields) {
                if (field.getAttribute('data-required') === 'true' && (!field.value || field.value.trim() === '')) {
                    showNotification(`${field.name || 'Field'} is required`, 'error');
                    return;
                }
            }
        }
        
        // Get athlete ID
        const athleteIdField = document.getElementById('result-athlete-id');
        const athleteId = athleteIdField ? athleteIdField.value : null;
        
        if (!athleteId) {
            showNotification('Unable to determine athlete for this session', 'error');
            return;
        }
        
        // Submit data
        const basicData = {
            overall_score: overallScore,
            notes: document.getElementById('result-notes').value,
            feedback: document.getElementById('result-feedback').value
        };
        
        try {
            let result;
            
            if (templateId) {
                const customData = { ...basicData };
                const customFields = document.querySelectorAll('#custom-fields-container input, #custom-fields-container select');
                
                customFields.forEach(field => {
                    if (field.name && field.value !== '') {
                        customData[field.name] = field.value;
                    }
                });
                
                result = await makeRequest('add_training_result_with_template', {
                    schedule_id: scheduleId,
                    athlete_id: athleteId,
                    template_id: templateId,
                    custom_data: JSON.stringify(customData)
                });
            } else {
                result = await makeRequest('add_training_result', {
                    schedule_id: scheduleId,
                    athlete_id: athleteId,
                    performance_score: basicData.overall_score,
                    notes: basicData.notes,
                    feedback: basicData.feedback
                });
            }
            
            showNotification(result.message, result.success ? 'success' : 'error');
            
            if (result.success) {
                closeModal('resultModal');
                document.getElementById('resultForm').reset();
                document.getElementById('custom-fields-container').innerHTML = '';
                const hiddenField = document.getElementById('result-athlete-id');
                if (hiddenField) hiddenField.remove();
                loadSectionData(currentSection);
            }
        } catch (error) {
            showNotification('An error occurred while saving the result', 'error');
        }
    }

<<<<<<< Updated upstream
=======
    // Enhanced athlete management functions
    async function removeAthlete(id) {
        if (!confirm('Remove this athlete from your program? (This can be undone later)')) return;
        
        const result = await makeRequest('remove_athlete', { athlete_id: id });
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            loadAthletes();
            loadDashboardStats(); // Refresh stats
        }
    }

    function showDeleteConfirmation(athleteId, athleteName) {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'block';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 500px;">
                <span class="close" onclick="this.closest('.modal').remove()">&times;</span>
                <h2 style="color: var(--accent-danger); margin-bottom: 1rem;">⚠️ Permanently Delete Athlete</h2>
                
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                    <h4>This will permanently delete:</h4>
                    <ul style="margin: 0.5rem 0 0 1rem; color: var(--text-secondary);">
                        <li>Athlete profile: <strong>${athleteName}</strong></li>
                        <li>All training schedules</li>
                        <li>All training results</li>
                        <li>All performance metrics</li>
                        <li>All invitation history</li>
                    </ul>
                    <p style="margin-top: 1rem; font-weight: 600;">This action CANNOT be undone!</p>
                </div>
                
                <div style="margin: 1.5rem 0;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                        Type "DELETE ${athleteName}" to confirm:
                    </label>
                    <input type="text" id="deleteConfirmText" class="form-input" placeholder="DELETE ${athleteName}" style="width: 100%;">
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="this.closest('.modal').remove()">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmPermanentDelete(${athleteId}, '${athleteName}', this)">
                        🗑️ Permanently Delete
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close modal when clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
            }
        });
    }

    async function confirmPermanentDelete(athleteId, athleteName, buttonElement) {
        const confirmText = document.getElementById('deleteConfirmText').value;
        const expectedText = `DELETE ${athleteName}`;
        
        if (confirmText !== expectedText) {
            showNotification('Confirmation text does not match. Please type exactly: ' + expectedText, 'error');
            return;
        }
        
        // Disable button and show loading
        buttonElement.disabled = true;
        buttonElement.innerHTML = '<div class="loading"></div> Deleting...';
        
        const result = await makeRequest('permanently_delete_athlete', { athlete_id: athleteId });
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            document.querySelector('.modal').remove();
            loadAthletes();
            loadDashboardStats();
        } else {
            buttonElement.disabled = false;
            buttonElement.innerHTML = '🗑️ Permanently Delete';
        }
    }

    // Function to show removed athletes (for restore functionality)
    async function showRemovedAthletes() {
        const result = await makeRequest('get_removed_athletes');
        
        if (!result.success) {
            showNotification('Failed to load removed athletes', 'error');
            return;
        }
        
        const removedAthletes = result.data || [];
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'block';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 700px;">
                <span class="close" onclick="this.closest('.modal').remove()">&times;</span>
                <h2>Removed Athletes</h2>
                
                <div style="max-height: 400px; overflow-y: auto; margin: 1rem 0;">
                    ${removedAthletes.length === 0 ? `
                        <div class="empty-state">
                            <h3>No removed athletes</h3>
                            <p>Athletes you remove will appear here</p>
                        </div>
                    ` : removedAthletes.map(athlete => `
                        <div class="athlete-card">
                            <div class="athlete-info">
                                <div class="athlete-avatar">
                                    ${getInitials(athlete.full_name)}
                                </div>
                                <div class="athlete-details">
                                    <h3>${athlete.full_name}</h3>
                                    <p>@${athlete.username} • Removed: ${new Date(athlete.removed_at).toLocaleDateString()}</p>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                <button class="btn btn-success" onclick="restoreAthlete(${athlete.id})">
                                    ↺ Restore
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
                
                <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                    <button class="btn" onclick="this.closest('.modal').remove()">Close</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    async function restoreAthlete(athleteId) {
        if (!confirm('Restore this athlete to your program?')) return;
        
        const result = await makeRequest('restore_athlete', { athlete_id: athleteId });
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            document.querySelector('.modal').remove();
            loadAthletes();
            loadDashboardStats();
        }
    }
    
    // Template management
    async function loadMetricTemplates() {
        const result = await makeRequest('get_metric_templates');
        if (result.success) {
            const select = document.getElementById('result-template');
            select.innerHTML = '<option value="">Select Template</option>';
            
            result.data.forEach(template => {
                select.innerHTML += `
                    <option value="${template.id}">
                        ${template.template_name} (${template.field_count} fields)
                    </option>
                `;
            });
        }
    }

    function generateFieldHTML(field) {
        const fieldId = `custom_${field.field_name.replace(/[^a-zA-Z0-9]/g, '_')}`;
        const isRequired = parseInt(field.is_required) === 1;
        let inputHTML = '';
        
        switch (field.field_type) {
            case 'number':
                inputHTML = `
                    <input type="number" class="form-input" id="${fieldId}" 
                        name="${field.field_name}"
                        ${field.min_value !== null && field.min_value !== '' ? `min="${field.min_value}"` : ''}
                        ${field.max_value !== null && field.max_value !== '' ? `max="${field.max_value}"` : ''}
                        data-required="${isRequired}">
                `;
                break;
            case 'decimal':
                inputHTML = `
                    <input type="number" step="0.01" class="form-input" id="${fieldId}" 
                        name="${field.field_name}"
                        ${field.min_value !== null && field.min_value !== '' ? `min="${field.min_value}"` : ''}
                        ${field.max_value !== null && field.max_value !== '' ? `max="${field.max_value}"` : ''}
                        data-required="${isRequired}">
                `;
                break;
            case 'text':
                inputHTML = `
                    <input type="text" class="form-input" id="${fieldId}" 
                        name="${field.field_name}"
                        data-required="${isRequired}">
                `;
                break;
            case 'select':
                const options = field.field_options ? JSON.parse(field.field_options) : [];
                inputHTML = `
                    <select class="form-select" id="${fieldId}" name="${field.field_name}" 
                            data-required="${isRequired}">
                        <option value="">Select...</option>
                        ${options.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                    </select>
                `;
                break;
            case 'boolean':
                inputHTML = `
                    <select class="form-select" id="${fieldId}" name="${field.field_name}"
                            data-required="${isRequired}">
                        <option value="">Select...</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                `;
                break;
            default:
                inputHTML = `
                    <input type="text" class="form-input" id="${fieldId}" 
                        name="${field.field_name}"
                        data-required="${isRequired}">
                `;
        }
        
        return `
            <div class="form-group" style="display: block;">
                <label class="form-label">
                    ${field.field_name} ${field.field_unit ? `(${field.field_unit})` : ''}
                    ${isRequired ? '<span style="color: var(--accent-danger);">*</span>' : ''}
                </label>
                ${inputHTML}
            </div>
        `;
    }

    async function loadTemplateFields(templateId) {
        if (!templateId) {
            document.getElementById('custom-fields-container').innerHTML = '';
            return;
        }
        
        const result = await makeRequest('get_template_fields', { template_id: templateId });
        if (result.success) {
            const container = document.getElementById('custom-fields-container');
            container.innerHTML = result.data.map(field => generateFieldHTML(field)).join('');
        }
    }

    // Template creation modal
    function showCreateTemplateModal() {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'block';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 800px;">
                <span class="close" onclick="this.closest('.modal').remove()">&times;</span>
                <h2>Create Metric Template</h2>
                
                <form id="templateForm" novalidate>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Template Name <span style="color: red;">*</span></label>
                            <input type="text" class="form-input" id="template-name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sport Category</label>
                            <select class="form-select" id="template-category">
                                <option value="general">General</option>
                                <option value="football">Football</option>
                                <option value="basketball">Basketball</option>
                                <option value="swimming">Swimming</option>
                                <option value="athletics">Athletics</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="card-header">
                        <h3>Metric Fields</h3>
                        <button type="button" class="btn btn-primary" onclick="addTemplateField()">
                            Add Field
                        </button>
                    </div>
                    
                    <div id="template-fields">
                        <div class="field-template" style="display: none;">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 1rem; align-items: end; margin-bottom: 1rem; padding: 1rem; border: 1px solid var(--glass-border); border-radius: 8px;">
                                <div>
                                    <label>Field Name <span style="color: red;">*</span></label>
                                    <input type="text" class="form-input field-name">
                                </div>
                                <div>
                                    <label>Type <span style="color: red;">*</span></label>
                                    <select class="form-select field-type">
                                        <option value="number">Number</option>
                                        <option value="decimal">Decimal</option>
                                        <option value="text">Text</option>
                                        <option value="select">Select</option>
                                        <option value="boolean">Yes/No</option>
                                    </select>
                                </div>
                                <div>
                                    <label>Unit</label>
                                    <input type="text" class="form-input field-unit" placeholder="kg, km, etc.">
                                </div>
                                <div>
                                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                                        <input type="checkbox" class="field-required"> Required
                                    </label>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-danger" onclick="this.closest('.field-template').remove()">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn" onclick="this.closest('.modal').remove()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Template</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add first field by default
        addTemplateField();
        
        // Handle form submit
        document.getElementById('templateForm').addEventListener('submit', handleTemplateSubmit);
    }

    function addTemplateField() {
        const container = document.getElementById('template-fields');
        const template = container.querySelector('.field-template').cloneNode(true);
        template.style.display = 'block';
        template.classList.remove('field-template');
        container.appendChild(template);
    }

>>>>>>> Stashed changes
    // Helper functions
    function getInitials(name) {
        return name.split(' ').map(n => n[0]).join('').toUpperCase().substr(0, 2);
    }

    function getStatusColor(status) {
        const colors = {
            'scheduled': 'primary',
            'completed': 'success',
            'cancelled': 'danger',
            'in_progress': 'info'
        };
        return colors[status] || 'primary';
    }

    function getScoreColor(score) {
        if (score >= 80) return 'success';
        if (score >= 60) return 'primary';
        return 'danger';
    }

    function updateAthleteSelects() {
        const select = document.getElementById('schedule-athlete');
        select.innerHTML = '<option value="">Select Athlete</option>';
        athletesData.forEach(athlete => {
            select.innerHTML += `<option value="${athlete.id}">${athlete.full_name}</option>`;
        });
    }

    function updateSessionSelect() {
        const select = document.getElementById('result-session');
        select.innerHTML = '<option value="">Select Session</option>';
        
        const completedSessions = schedulesData.filter(s => s.status === 'scheduled' || s.status === 'completed');
        completedSessions.forEach(session => {
            select.innerHTML += `
                <option value="${session.id}" data-athlete="${session.user_id}" data-athlete-name="${session.athlete_name}">
                    ${session.title} - ${session.athlete_name} (${session.schedule_date})
                </option>
            `;
        });
    }

    function updateResultAthlete(sessionId) {
        const sessionSelect = document.getElementById('result-session');
        const selectedOption = sessionSelect.querySelector(`option[value="${sessionId}"]`);
        
        // Hapus hidden field yang ada
        const existingHidden = document.getElementById('result-athlete-id');
        if (existingHidden) {
            existingHidden.remove();
        }
        
        if (selectedOption) {
            // Buat hidden field baru tanpa required
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.id = 'result-athlete-id';
            hiddenInput.value = selectedOption.dataset.athlete || '';
            
            // Tambahkan ke form
            document.getElementById('resultForm').appendChild(hiddenInput);
        }
    }

    // Modal functions
    function showInviteModal() {
        document.getElementById('inviteModal').style.display = 'block';
    }

    function showScheduleModal() {
        if (athletesData.length === 0) {
            loadAthletes().then(() => {
                document.getElementById('scheduleModal').style.display = 'block';
            });
        } else {
            document.getElementById('scheduleModal').style.display = 'block';
        }
    }

    function addMissingResultFormFields() {
        // This function should be called to ensure all necessary form fields exist
        // You may need to update the result modal HTML to include:
        // - result-athlete-id (hidden input)
        // - result-overall-score (instead of result-score)
        
        const resultModal = document.getElementById('resultModal');
        const form = resultModal.querySelector('form');
        
        // Add missing hidden field for athlete ID if it doesn't exist
        if (!document.getElementById('result-athlete-id')) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.id = 'result-athlete-id';
            form.appendChild(hiddenInput);
        }
    }

    function showResultModal() {
        if (schedulesData.length === 0) {
            loadSchedules().then(() => {
                loadMetricTemplates(); // Load templates
                document.getElementById('resultModal').style.display = 'block';
            });
        } else {
            loadMetricTemplates(); // Load templates
            document.getElementById('resultModal').style.display = 'block';
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.style.display = 'none';
        
        // Reset form if it exists
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
            form.noValidate = false; // Reset validation
            
            // Clear custom fields for result modal
            if (modalId === 'resultModal') {
                const customContainer = document.getElementById('custom-fields-container');
                if (customContainer) {
                    customContainer.innerHTML = '';
                }
                const templateSelect = document.getElementById('result-template');
                if (templateSelect) {
                    templateSelect.value = '';
                }
            }
        }
    }

    // Action functions
    async function cancelInvitation(id) {
        if (!confirm('Cancel this invitation?')) return;
        
        const result = await makeRequest('cancel_invitation', { invitation_id: id });
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            loadInvitations();
        }
    }

    async function removeAthlete(id) {
        if (!confirm('Remove this athlete from your program?')) return;
        
        const result = await makeRequest('remove_athlete', { athlete_id: id });
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            loadAthletes();
        }
    }

    async function markAsCompleted(id) {
        const result = await makeRequest('update_training_status', {
            schedule_id: id,
            status: 'completed'
        });
        
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            loadSchedules();
        }
    }

    async function handleTemplateSubmit(e) {
        e.preventDefault();
        
        const templateName = document.getElementById('template-name').value;
        const sportCategory = document.getElementById('template-category').value;
        
        // Manual validation
        if (!templateName || templateName.trim() === '') {
            showNotification('Template name is required', 'error');
            return;
        }
        
        // Collect field data
        const fields = [];
        const fieldContainers = document.querySelectorAll('#template-fields > div:not(.field-template)');
        
        let hasValidFields = false;
        for (let container of fieldContainers) {
            const name = container.querySelector('.field-name').value;
            const type = container.querySelector('.field-type').value;
            const unit = container.querySelector('.field-unit').value;
            const required = container.querySelector('.field-required').checked;
            
            if (name && name.trim()) {
                fields.push({
                    name: name.trim(),
                    type: type,
                    unit: unit.trim() || null,
                    required: required ? 1 : 0
                });
                hasValidFields = true;
            }
        }
        
        if (!hasValidFields) {
            showNotification('Please add at least one field to the template', 'error');
            return;
        }
        
        const result = await makeRequest('create_metric_template', {
            template_name: templateName.trim(),
            sport_category: sportCategory,
            fields: JSON.stringify(fields)
        });
        
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            document.querySelector('.modal').remove();
            loadMetricTemplates(); // Refresh the templates dropdown
        }
    }

    async function cancelSession(id) {
        if (!confirm('Cancel this training session?')) return;
        
        const result = await makeRequest('update_training_status', {
            schedule_id: id,
            status: 'cancelled'
        });
        
        showNotification(result.message, result.success ? 'success' : 'error');
        
        if (result.success) {
            loadSchedules();
        }
    }

    function scheduleTrainingFor(athleteId) {
        document.getElementById('schedule-athlete').value = athleteId;
        showScheduleModal();
    }

    function addResultForSession(sessionId, athleteId) {
        document.getElementById('result-session').value = sessionId;
        updateResultAthlete(sessionId);
        showResultModal();
    }

    function viewAthleteDetails(athleteId) {
        // This could open a detailed view of the athlete
        alert('Athlete details view - Coming soon!');
    }

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
    </script>
</body>
</html>