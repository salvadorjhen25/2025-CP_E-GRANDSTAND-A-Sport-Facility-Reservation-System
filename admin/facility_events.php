<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdminOrStaff();
$pdo = getDBConnection();

// Ensure facility_events table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_events (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        facility_ids JSON NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        start_time TIME,
        end_time TIME,
        is_all_day BOOLEAN DEFAULT FALSE,
        is_active BOOLEAN DEFAULT TRUE,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Add closure columns to facilities table if they don't exist
    $pdo->exec("ALTER TABLE facilities 
        ADD COLUMN IF NOT EXISTS is_closed_for_event BOOLEAN DEFAULT FALSE,
        ADD COLUMN IF NOT EXISTS closure_reason TEXT NULL,
        ADD COLUMN IF NOT EXISTS closure_end_date DATE NULL");
        
    // Create indexes
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_facility_events_dates ON facility_events(start_date, end_date, is_active)");
} catch (Exception $e) {
    // Table might already exist, continue
}

// Get statistics for notification badges
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$result = $stmt->fetch();
$stats['users'] = $result ? $result['count'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$result = $stmt->fetch();
$stats['facilities'] = $result ? $result['count'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$result = $stmt->fetch();
$stats['reservations'] = $result ? $result['count'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$result = $stmt->fetch();
$stats['pending'] = $result ? $result['count'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$result = $stmt->fetch();
$stats['no_shows'] = $result ? $result['count'] : 0;

// Active events count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM facility_events WHERE is_active = 1 AND end_date >= CURDATE()");
    $result = $stmt->fetch();
    $stats['active_events'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_events'] = 0;
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_event':
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $facility_ids = $_POST['facility_ids'] ?? [];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $start_time = $_POST['start_time'] ?? null;
                $end_time = $_POST['end_time'] ?? null;
                $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
                
                if (empty($title) || empty($facility_ids) || empty($start_date) || empty($end_date)) {
                    $error_message = 'Please fill in all required fields.';
                } elseif ($start_date > $end_date) {
                    $error_message = 'End date must be after start date.';
                } else {
                    try {
                        // Convert facility IDs to JSON
                        $facility_ids_json = json_encode($facility_ids);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO facility_events (title, description, facility_ids, start_date, end_date, start_time, end_time, is_all_day, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $title, 
                            $description, 
                            $facility_ids_json, 
                            $start_date, 
                            $end_date, 
                            $is_all_day ? null : $start_time, 
                            $is_all_day ? null : $end_time, 
                            $is_all_day, 
                            $_SESSION['user_id']
                        ]);
                        
                        // Mark facilities as closed for event
                        foreach ($facility_ids as $facility_id) {
                            $stmt = $pdo->prepare("
                                UPDATE facilities 
                                SET is_closed_for_event = 1, 
                                    closure_reason = ?, 
                                    closure_end_date = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$title, $end_date, $facility_id]);
                        }
                        
                        $success_message = 'Event created successfully and facilities marked as closed!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to create event. Please try again.';
                    }
                }
                break;
                
            case 'update_event':
                $event_id = intval($_POST['event_id']);
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $facility_ids = $_POST['facility_ids'] ?? [];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $start_time = $_POST['start_time'] ?? null;
                $end_time = $_POST['end_time'] ?? null;
                $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
                
                if (empty($title) || empty($facility_ids) || empty($start_date) || empty($end_date)) {
                    $error_message = 'Please fill in all required fields.';
                } elseif ($start_date > $end_date) {
                    $error_message = 'End date must be after start date.';
                } else {
                    try {
                        // Get old facility IDs to reset their closure status
                        $stmt = $pdo->prepare("SELECT facility_ids FROM facility_events WHERE id = ?");
                        $stmt->execute([$event_id]);
                        $old_event = $stmt->fetch();
                        $old_facility_ids = json_decode($old_event['facility_ids'], true);
                        
                        // Reset old facilities
                        foreach ($old_facility_ids as $facility_id) {
                            $stmt = $pdo->prepare("
                                UPDATE facilities 
                                SET is_closed_for_event = 0, 
                                    closure_reason = NULL, 
                                    closure_end_date = NULL
                                WHERE id = ?
                            ");
                            $stmt->execute([$facility_id]);
                        }
                        
                        // Update event
                        $facility_ids_json = json_encode($facility_ids);
                        $stmt = $pdo->prepare("
                            UPDATE facility_events 
                            SET title = ?, description = ?, facility_ids = ?, start_date = ?, end_date = ?, 
                                start_time = ?, end_time = ?, is_all_day = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $title, $description, $facility_ids_json, $start_date, $end_date,
                            $is_all_day ? null : $start_time, $is_all_day ? null : $end_time, 
                            $is_all_day, $event_id
                        ]);
                        
                        // Mark new facilities as closed
                        foreach ($facility_ids as $facility_id) {
                            $stmt = $pdo->prepare("
                                UPDATE facilities 
                                SET is_closed_for_event = 1, 
                                    closure_reason = ?, 
                                    closure_end_date = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$title, $end_date, $facility_id]);
                        }
                        
                        $success_message = 'Event updated successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to update event. Please try again.';
                    }
                }
                break;
                
            case 'delete_event':
                $event_id = intval($_POST['event_id']);
                
                try {
                    // Get facility IDs to reset their closure status
                    $stmt = $pdo->prepare("SELECT facility_ids FROM facility_events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    $event = $stmt->fetch();
                    $facility_ids = json_decode($event['facility_ids'], true);
                    
                    // Reset facilities
                    foreach ($facility_ids as $facility_id) {
                        $stmt = $pdo->prepare("
                            UPDATE facilities 
                            SET is_closed_for_event = 0, 
                                closure_reason = NULL, 
                                closure_end_date = NULL
                            WHERE id = ?
                        ");
                        $stmt->execute([$facility_id]);
                    }
                    
                    // Delete event
                    $stmt = $pdo->prepare("DELETE FROM facility_events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    
                    $success_message = 'Event deleted successfully and facilities reopened!';
                } catch (Exception $e) {
                    $error_message = 'Failed to delete event. Please try again.';
                }
                break;
                
            case 'toggle_event_status':
                $event_id = intval($_POST['event_id']);
                $is_active = intval($_POST['is_active']);
                
                try {
                    // Get facility IDs
                    $stmt = $pdo->prepare("SELECT facility_ids FROM facility_events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    $event = $stmt->fetch();
                    $facility_ids = json_decode($event['facility_ids'], true);
                    
                    // Update event status
                    $stmt = $pdo->prepare("UPDATE facility_events SET is_active = ? WHERE id = ?");
                    $stmt->execute([$is_active, $event_id]);
                    
                    // Update facility closure status
                    foreach ($facility_ids as $facility_id) {
                        $stmt = $pdo->prepare("
                            UPDATE facilities 
                            SET is_closed_for_event = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$is_active, $facility_id]);
                    }
                    
                    $success_message = $is_active ? 'Event activated and facilities closed!' : 'Event deactivated and facilities reopened!';
                } catch (Exception $e) {
                    $error_message = 'Failed to update event status. Please try again.';
                }
                break;
        }
    }
}

// Get all events with facility names
$stmt = $pdo->query("
    SELECT fe.*, u.full_name as created_by_name
    FROM facility_events fe
    LEFT JOIN users u ON fe.created_by = u.id
    ORDER BY fe.start_date DESC, fe.created_at DESC
");
$events = $stmt->fetchAll();

// Get all facilities for the form
$stmt = $pdo->query("SELECT id, name, category_id FROM facilities WHERE is_active = 1 ORDER BY name");
$facilities = $stmt->fetchAll();

// Get categories for grouping
$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facility Events - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <link rel="stylesheet" href="../assets/css/mobile-responsive.css">
    <script src="../assets/js/modal-system.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1E40AF'
                    }
                }
            }
        }
    </script>
    <style>
        * {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body, html {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #1a202c;
            min-height: 100vh;
        }
        
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 2rem -20px;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .page-header h1 {
            position: relative;
            z-index: 1;
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .page-header p {
            position: relative;
            z-index: 1;
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .add-event-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            border-radius: 15px;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .add-event-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.4);
        }
        
        .events-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin: 2rem 0;
        }
        
        .events-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .events-header h2 {
            color: #2d3748;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .event-row {
            transition: all 0.3s ease;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .event-row:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: translateX(5px);
        }
        
        .event-row:last-child {
            border-bottom: none;
        }
        
        .facility-tag {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
        }
        
        .facility-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge.active {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 101, 101, 0.3);
        }
        
        .status-badge.inactive {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(160, 174, 192, 0.3);
        }
        
        .action-btn {
            padding: 0.5rem;
            border-radius: 10px;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            background: none;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        .action-btn.edit {
            color: #4299e1;
        }
        
        .action-btn.edit:hover {
            background: rgba(66, 153, 225, 0.1);
        }
        
        .action-btn.toggle {
            color: #38a169;
        }
        
        .action-btn.toggle:hover {
            background: rgba(56, 161, 105, 0.1);
        }
        
        .action-btn.delete {
            color: #e53e3e;
        }
        
        .action-btn.delete:hover {
            background: rgba(229, 62, 62, 0.1);
        }
        
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #a0aec0;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            color: #4a5568;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #718096;
            font-size: 1rem;
        }
        
        /* Modal Styles */
        .modal {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
            max-width: 600px;
            width: 90vw;
            max-height: 85vh;
            overflow-y: auto;
            margin: 3vh auto;
            animation: modalSlideIn 0.3s ease-out;
            border: 1px solid #4b5563;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 20px 20px 0 0;
            position: relative;
            border-bottom: 1px solid #6b7280;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s ease;
        }
        
        .step.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .step.completed {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }
        
        .step-number {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .step.active .step-number {
            background: white;
            color: #374151;
        }
        
        .step.completed .step-number {
            background: #22c55e;
            color: white;
        }
        
        .step-label {
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        /* Modal Steps */
        .modal-step {
            display: none;
        }
        
        .modal-step.active {
            display: block;
        }
        
        /* Preview Styles */
        .preview-container {
            background: #374151;
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid #4b5563;
        }
        
        .preview-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #4b5563;
        }
        
        .preview-title {
            color: #f9fafb;
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }
        
        .preview-subtitle {
            color: #9ca3af;
            font-size: 0.875rem;
            margin: 0;
        }
        
        .preview-content {
            space-y: 1.5rem;
        }
        
        .preview-section {
            margin-bottom: 1.5rem;
        }
        
        .preview-section:last-child {
            margin-bottom: 0;
        }
        
        .preview-section-title {
            color: #f9fafb;
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #4b5563;
        }
        
        .preview-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: #4b5563;
            border-radius: 10px;
            border: 1px solid #6b7280;
        }
        
        .preview-item:last-child {
            margin-bottom: 0;
        }
        
        .preview-label {
            color: #9ca3af;
            font-weight: 500;
            font-size: 0.875rem;
            min-width: 100px;
            flex-shrink: 0;
        }
        
        .preview-value {
            color: #f9fafb;
            font-weight: 500;
            flex: 1;
            word-break: break-word;
        }
        
        .preview-facilities {
            flex: 1;
        }
        
        .facility-preview-tag {
            display: inline-block;
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            margin: 0.25rem 0.25rem 0.25rem 0;
            border: 1px solid #6b7280;
        }
        
        .no-facilities {
            color: #9ca3af;
            font-style: italic;
        }
        
        .edit-btn {
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        
        .edit-btn:hover {
            background: #4b5563;
            transform: scale(1.05);
        }
        
        .edit-btn i {
            font-size: 0.75rem;
        }
        
        .modal-body {
            padding: 1.5rem;
            background: #1f2937;
            color: white;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #f9fafb;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #4b5563;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #374151;
            color: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #6b7280;
            background: #4b5563;
            box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.2);
        }
        
        .form-input::placeholder {
            color: #9ca3af;
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 60px;
        }
        
        .facilities-grid {
            max-height: 200px;
            overflow-y: auto;
            border: 2px solid #4b5563;
            border-radius: 10px;
            padding: 0.75rem;
            background: #374151;
        }
        
        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .search-container {
            position: relative;
            flex: 1;
        }
        
        .search-input {
            padding-right: 2.5rem;
        }
        
        .search-icon {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }
        
        .category-filter {
            flex: 0 0 200px;
        }
        
        .category-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #4b5563;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #374151;
            color: white;
        }
        
        .category-select:focus {
            outline: none;
            border-color: #6b7280;
            background: #4b5563;
            box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.2);
        }
        
        .category-section {
            margin-bottom: 1rem;
        }
        
        .category-section:last-child {
            margin-bottom: 0;
        }
        
        .category-title {
            color: #f9fafb;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #4b5563;
        }
        
        .facility-checkbox {
            margin-right: 0.5rem;
            transform: scale(1.2);
        }
        
        .facility-label {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            color: #f9fafb;
        }
        
        .facility-label:hover {
            background: rgba(107, 114, 128, 0.2);
        }
        
        .facility-label.hidden {
            display: none;
        }
        
        .category-section.hidden {
            display: none;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
        }
        
        .date-time-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .all-day-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: #374151;
            border-radius: 10px;
            border: 2px solid #4b5563;
            color: #f9fafb;
        }
        
        .all-day-checkbox input[type="checkbox"] {
            transform: scale(1.3);
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            background: #374151;
            border-radius: 0 0 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            border-top: 1px solid #4b5563;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: 1px solid #4b5563;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
            border: 1px solid #6b7280;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 114, 128, 0.4);
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 101, 101, 0.3);
        }
        
        .alert i {
            font-size: 1.25rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin: 10px;
                border-radius: 15px;
            }
            
            .page-header {
                padding: 1.5rem;
                margin: -10px -10px 1.5rem -10px;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .modal-content {
                width: 95vw;
                margin: 1vh auto;
                max-height: 90vh;
                max-width: 500px;
            }
            
            .date-time-grid {
                grid-template-columns: 1fr;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .filter-controls {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .category-filter {
                flex: 1;
            }
            
            .step-indicator {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .step {
                justify-content: center;
                padding: 0.75rem 1rem;
            }
            
            .step-label {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="flex items-center">
                    <i class="fas fa-calendar-times mr-3"></i>Facility Events
                </h1>
                <p>Manage facility closures due to events</p>
            </div>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <p><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Add Event Button -->
            <div class="mb-6">
                <button onclick="openAddEventModal()" class="add-event-btn">
                    <i class="fas fa-plus mr-2"></i>Add New Event
                </button>
            </div>

            <!-- Events List -->
            <div class="events-container">
                <div class="events-header">
                    <h2 class="flex items-center">
                        <i class="fas fa-list mr-2"></i>All Events
                    </h2>
                </div>
                
                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Events Found</h3>
                        <p>Create your first facility event to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facilities</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Range</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($events as $event): ?>
                                    <?php 
                                    $facility_ids = json_decode($event['facility_ids'], true);
                                    $event_facilities = array_filter($facilities, function($f) use ($facility_ids) {
                                        return in_array($f['id'], $facility_ids);
                                    });
                                    ?>
                                    <tr class="event-row hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($event['title']); ?></div>
                                                <?php if ($event['description']): ?>
                                                    <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(substr($event['description'], 0, 100)) . (strlen($event['description']) > 100 ? '...' : ''); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($event_facilities as $facility): ?>
                                                    <span class="facility-tag">
                                                        <?php echo htmlspecialchars($facility['name']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <div><?php echo date('M j, Y', strtotime($event['start_date'])); ?></div>
                                            <div class="text-gray-500">
                                                <?php if ($event['is_all_day']): ?>
                                                    All Day
                                                <?php else: ?>
                                                    <?php echo date('g:i A', strtotime($event['start_time'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($event['end_date'] != $event['start_date']): ?>
                                                <div class="text-gray-500">to <?php echo date('M j, Y', strtotime($event['end_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($event['is_active']): ?>
                                                <span class="status-badge active">
                                                    <i class="fas fa-times"></i>Facilities Closed
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge inactive">
                                                    <i class="fas fa-pause"></i>Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php echo htmlspecialchars($event['created_by_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="editEvent(<?php echo htmlspecialchars(json_encode($event)); ?>, <?php echo htmlspecialchars(json_encode($event_facilities)); ?>)" 
                                                        class="action-btn edit" title="Edit Event">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="toggleEventStatus(<?php echo $event['id']; ?>, <?php echo $event['is_active'] ? 0 : 1; ?>)" 
                                                        class="action-btn toggle" title="<?php echo $event['is_active'] ? 'Deactivate Event' : 'Activate Event'; ?>">
                                                    <i class="fas fa-<?php echo $event['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                </button>
                                                <button onclick="deleteEvent(<?php echo $event['id']; ?>)" 
                                                        class="action-btn delete" title="Delete Event">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Event Modal -->
    <div id="eventModal" class="modal fixed inset-0 hidden z-50">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Event</h3>
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <span class="step-number">1</span>
                        <span class="step-label">Details</span>
                    </div>
                    <div class="step" data-step="2">
                        <span class="step-number">2</span>
                        <span class="step-label">Facilities</span>
                    </div>
                    <div class="step" data-step="3">
                        <span class="step-number">3</span>
                        <span class="step-label">Preview</span>
                    </div>
                </div>
            </div>
            
            <form id="eventForm" method="POST" class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add_event">
                <input type="hidden" name="event_id" id="eventId" value="">
                
                <!-- Step 1: Event Details -->
                <div id="step1" class="modal-step active">
                    <div class="space-y-4">
                        <div class="form-group">
                            <label for="title" class="form-label">Event Title *</label>
                            <input type="text" id="title" name="title" required class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" name="description" rows="3" class="form-input form-textarea"></textarea>
                        </div>
                        
                        <div class="date-time-grid">
                            <div class="form-group">
                                <label for="start_date" class="form-label">Start Date *</label>
                                <input type="date" id="start_date" name="start_date" required class="form-input">
                            </div>
                            
                            <div class="form-group">
                                <label for="end_date" class="form-label">End Date *</label>
                                <input type="date" id="end_date" name="end_date" required class="form-input">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="all-day-checkbox">
                                <input type="checkbox" id="is_all_day" name="is_all_day">
                                <span>All Day Event</span>
                            </div>
                        </div>
                        
                        <div id="timeFields" class="date-time-grid">
                            <div class="form-group">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" id="start_time" name="start_time" class="form-input">
                            </div>
                            
                            <div class="form-group">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" id="end_time" name="end_time" class="form-input">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Step 2: Select Facilities -->
                <div id="step2" class="modal-step">
                    <div class="form-group">
                        <label class="form-label">Select Facilities *</label>
                        
                        <!-- Search and Filter Controls -->
                        <div class="filter-controls mb-4">
                            <div class="search-container">
                                <input type="text" id="facilitySearch" placeholder="Search facilities..." class="search-input">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <div class="category-filter">
                                <select id="categoryFilter" class="category-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="facilities-grid" id="facilitiesContainer">
                            <?php foreach ($categories as $category): ?>
                                <div class="category-section" data-category="<?php echo $category['id']; ?>">
                                    <h4 class="category-title"><?php echo htmlspecialchars($category['name']); ?></h4>
                                    <div class="checkbox-group">
                                        <?php 
                                        $category_facilities = array_filter($facilities, function($f) use ($category) {
                                            return $f['category_id'] == $category['id'];
                                        });
                                        foreach ($category_facilities as $facility): 
                                        ?>
                                            <label class="facility-label" data-facility-name="<?php echo strtolower(htmlspecialchars($facility['name'])); ?>">
                                                <input type="checkbox" name="facility_ids[]" value="<?php echo $facility['id']; ?>" 
                                                       class="facility-checkbox">
                                                <span><?php echo htmlspecialchars($facility['name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: Preview & Edit -->
                <div id="step3" class="modal-step">
                    <div class="preview-container">
                        <div class="preview-header">
                            <h4 class="preview-title">Event Preview</h4>
                            <p class="preview-subtitle">Review and edit your event details before saving</p>
                        </div>
                        
                        <div class="preview-content">
                            <div class="preview-section">
                                <h5 class="preview-section-title">Event Information</h5>
                                <div class="preview-item">
                                    <span class="preview-label">Title:</span>
                                    <span class="preview-value" id="previewTitle">-</span>
                                    <button class="edit-btn" onclick="editField('title')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Description:</span>
                                    <span class="preview-value" id="previewDescription">-</span>
                                    <button class="edit-btn" onclick="editField('description')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="preview-section">
                                <h5 class="preview-section-title">Date & Time</h5>
                                <div class="preview-item">
                                    <span class="preview-label">Start Date:</span>
                                    <span class="preview-value" id="previewStartDate">-</span>
                                    <button class="edit-btn" onclick="editField('start_date')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">End Date:</span>
                                    <span class="preview-value" id="previewEndDate">-</span>
                                    <button class="edit-btn" onclick="editField('end_date')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Time:</span>
                                    <span class="preview-value" id="previewTime">-</span>
                                    <button class="edit-btn" onclick="editField('time')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="preview-section">
                                <h5 class="preview-section-title">Affected Facilities</h5>
                                <div class="preview-item">
                                    <span class="preview-label">Facilities:</span>
                                    <div class="preview-facilities" id="previewFacilities">
                                        <span class="no-facilities">No facilities selected</span>
                                    </div>
                                    <button class="edit-btn" onclick="goToStep(2)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeEventModal()" class="btn btn-secondary">
                        Cancel
                    </button>
                    <button type="button" id="prevBtn" onclick="previousStep()" class="btn btn-secondary" style="display: none;">
                        <i class="fas fa-arrow-left mr-2"></i>Previous
                    </button>
                    <button type="button" id="nextBtn" onclick="nextStep()" class="btn btn-primary">
                        Next<i class="fas fa-arrow-right ml-2"></i>
                    </button>
                    <button type="submit" id="saveBtn" class="btn btn-primary" style="display: none;">
                        <i class="fas fa-save mr-2"></i>Save Event
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 3;
        
        // Modal functions
        function openAddEventModal() {
            document.getElementById('modalTitle').textContent = 'Add New Event';
            document.getElementById('formAction').value = 'add_event';
            document.getElementById('eventId').value = '';
            document.getElementById('eventForm').reset();
            document.querySelectorAll('.facility-checkbox').forEach(cb => cb.checked = false);
            
            // Reset filters
            document.getElementById('facilitySearch').value = '';
            document.getElementById('categoryFilter').value = '';
            filterFacilities();
            
            // Reset to step 1
            currentStep = 1;
            showStep(1);
            updateStepIndicator();
            updateButtons();
            
            document.getElementById('eventModal').classList.remove('hidden');
        }
        
        function editEvent(event, facilities) {
            document.getElementById('modalTitle').textContent = 'Edit Event';
            document.getElementById('formAction').value = 'update_event';
            document.getElementById('eventId').value = event.id;
            document.getElementById('title').value = event.title;
            document.getElementById('description').value = event.description || '';
            document.getElementById('start_date').value = event.start_date;
            document.getElementById('end_date').value = event.end_date;
            document.getElementById('is_all_day').checked = event.is_all_day == 1;
            document.getElementById('start_time').value = event.start_time || '';
            document.getElementById('end_time').value = event.end_time || '';
            
            // Check facilities
            document.querySelectorAll('.facility-checkbox').forEach(cb => cb.checked = false);
            facilities.forEach(facility => {
                const checkbox = document.querySelector(`input[value="${facility.id}"]`);
                if (checkbox) checkbox.checked = true;
            });
            
            // Reset filters
            document.getElementById('facilitySearch').value = '';
            document.getElementById('categoryFilter').value = '';
            filterFacilities();
            
            // Reset to step 1
            currentStep = 1;
            showStep(1);
            updateStepIndicator();
            updateButtons();
            
            toggleTimeFields();
            document.getElementById('eventModal').classList.remove('hidden');
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.add('hidden');
        }
        
        // Multi-step functionality
        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.modal-step').forEach(stepEl => {
                stepEl.classList.remove('active');
            });
            
            // Show current step
            document.getElementById(`step${step}`).classList.add('active');
            
            // Update preview if on step 3
            if (step === 3) {
                updatePreview();
            }
        }
        
        function nextStep() {
            if (validateCurrentStep()) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                    updateStepIndicator();
                    updateButtons();
                }
            }
        }
        
        function previousStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
                updateStepIndicator();
                updateButtons();
            }
        }
        
        function goToStep(step) {
            currentStep = step;
            showStep(currentStep);
            updateStepIndicator();
            updateButtons();
        }
        
        function updateStepIndicator() {
            document.querySelectorAll('.step').forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index + 1 < currentStep) {
                    stepEl.classList.add('completed');
                } else if (index + 1 === currentStep) {
                    stepEl.classList.add('active');
                }
            });
        }
        
        function updateButtons() {
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const saveBtn = document.getElementById('saveBtn');
            
            // Show/hide previous button
            prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
            
            // Show/hide next/save buttons
            if (currentStep === totalSteps) {
                nextBtn.style.display = 'none';
                saveBtn.style.display = 'block';
            } else {
                nextBtn.style.display = 'block';
                saveBtn.style.display = 'none';
            }
        }
        
        function validateCurrentStep() {
            if (currentStep === 1) {
                // Validate step 1: Event details
                const title = document.getElementById('title').value.trim();
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                if (!title) {
                    alert('Please enter an event title.');
                    return false;
                }
                
                if (!startDate) {
                    alert('Please select a start date.');
                    return false;
                }
                
                if (!endDate) {
                    alert('Please select an end date.');
                    return false;
                }
                
                if (startDate > endDate) {
                    alert('End date must be after start date.');
                    return false;
                }
                
                const isAllDay = document.getElementById('is_all_day').checked;
                if (!isAllDay) {
                    const startTime = document.getElementById('start_time').value;
                    const endTime = document.getElementById('end_time').value;
                    
                    if (!startTime || !endTime) {
                        alert('Please select start and end times.');
                        return false;
                    }
                    
                    if (startDate === endDate && startTime >= endTime) {
                        alert('End time must be after start time.');
                        return false;
                    }
                }
                
                return true;
            } else if (currentStep === 2) {
                // Validate step 2: Facilities
                const selectedFacilities = document.querySelectorAll('.facility-checkbox:checked');
                if (selectedFacilities.length === 0) {
                    alert('Please select at least one facility.');
                    return false;
                }
                return true;
            }
            
            return true;
        }
        
        function updatePreview() {
            // Update event information
            document.getElementById('previewTitle').textContent = 
                document.getElementById('title').value || '-';
            document.getElementById('previewDescription').textContent = 
                document.getElementById('description').value || '-';
            
            // Update dates
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            document.getElementById('previewStartDate').textContent = 
                startDate ? new Date(startDate).toLocaleDateString() : '-';
            document.getElementById('previewEndDate').textContent = 
                endDate ? new Date(endDate).toLocaleDateString() : '-';
            
            // Update time
            const isAllDay = document.getElementById('is_all_day').checked;
            if (isAllDay) {
                document.getElementById('previewTime').textContent = 'All Day';
            } else {
                const startTime = document.getElementById('start_time').value;
                const endTime = document.getElementById('end_time').value;
                if (startTime && endTime) {
                    document.getElementById('previewTime').textContent = 
                        `${startTime} - ${endTime}`;
                } else {
                    document.getElementById('previewTime').textContent = '-';
                }
            }
            
            // Update facilities
            const selectedFacilities = document.querySelectorAll('.facility-checkbox:checked');
            const facilitiesContainer = document.getElementById('previewFacilities');
            
            if (selectedFacilities.length === 0) {
                facilitiesContainer.innerHTML = '<span class="no-facilities">No facilities selected</span>';
            } else {
                facilitiesContainer.innerHTML = '';
                selectedFacilities.forEach(checkbox => {
                    const label = checkbox.closest('.facility-label');
                    const facilityName = label.querySelector('span').textContent;
                    const tag = document.createElement('span');
                    tag.className = 'facility-preview-tag';
                    tag.textContent = facilityName;
                    facilitiesContainer.appendChild(tag);
                });
            }
        }
        
        function editField(fieldName) {
            if (fieldName === 'title' || fieldName === 'description') {
                goToStep(1);
                document.getElementById(fieldName).focus();
            } else if (fieldName === 'start_date' || fieldName === 'end_date' || fieldName === 'time') {
                goToStep(1);
                if (fieldName === 'time') {
                    document.getElementById('is_all_day').focus();
                } else {
                    document.getElementById(fieldName).focus();
                }
            }
        }
        
        function toggleEventStatus(eventId, newStatus) {
            if (confirm(newStatus ? 'Are you sure you want to activate this event and close the facilities?' : 'Are you sure you want to deactivate this event and reopen the facilities?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_event_status">
                    <input type="hidden" name="event_id" value="${eventId}">
                    <input type="hidden" name="is_active" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteEvent(eventId) {
            if (confirm('Are you sure you want to delete this event? This will reopen all affected facilities.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="${eventId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleTimeFields() {
            const isAllDay = document.getElementById('is_all_day').checked;
            const timeFields = document.getElementById('timeFields');
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            
            if (isAllDay) {
                timeFields.style.display = 'none';
                startTime.required = false;
                endTime.required = false;
            } else {
                timeFields.style.display = 'grid';
                startTime.required = true;
                endTime.required = true;
            }
        }
        
        // Search and filter functionality
        function initializeFilters() {
            const searchInput = document.getElementById('facilitySearch');
            const categoryFilter = document.getElementById('categoryFilter');
            const facilitiesContainer = document.getElementById('facilitiesContainer');
            
            if (searchInput) {
                searchInput.addEventListener('input', filterFacilities);
            }
            
            if (categoryFilter) {
                categoryFilter.addEventListener('change', filterFacilities);
            }
        }
        
        function filterFacilities() {
            const searchTerm = document.getElementById('facilitySearch').value.toLowerCase();
            const selectedCategory = document.getElementById('categoryFilter').value;
            const categorySections = document.querySelectorAll('.category-section');
            const facilityLabels = document.querySelectorAll('.facility-label');
            
            categorySections.forEach(section => {
                const categoryId = section.getAttribute('data-category');
                const facilitiesInSection = section.querySelectorAll('.facility-label');
                let hasVisibleFacilities = false;
                
                facilitiesInSection.forEach(label => {
                    const facilityName = label.getAttribute('data-facility-name');
                    const matchesSearch = facilityName.includes(searchTerm);
                    const matchesCategory = !selectedCategory || categoryId === selectedCategory;
                    
                    if (matchesSearch && matchesCategory) {
                        label.classList.remove('hidden');
                        hasVisibleFacilities = true;
                    } else {
                        label.classList.add('hidden');
                    }
                });
                
                if (hasVisibleFacilities) {
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                }
            });
        }
        
        // Event listeners
        document.getElementById('is_all_day').addEventListener('change', toggleTimeFields);
        
        // Close modal on outside click
        document.getElementById('eventModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEventModal();
            }
        });
        
        // Initialize
        toggleTimeFields();
        initializeFilters();
    </script>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
</body>
</html>
