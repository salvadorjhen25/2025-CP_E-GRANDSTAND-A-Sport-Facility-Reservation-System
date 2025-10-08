<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../classes/UsageManager.php';
require_once __DIR__ . '/../classes/PaymentManager.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
// Helper function to format countdown time
function formatCountdown($minutes) {
    if ($minutes <= 0) {
        return '0m';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    } else {
        return $mins . 'm';
    }
}
$auth = new Auth();
$auth->requireAdmin();
$usageManager = new UsageManager();
$paymentManager = new PaymentManager();
$pdo = getDBConnection();
// Auto-complete expired usage
$expiredCount = $usageManager->autoCompleteExpiredUsage();
if ($expiredCount > 0) {
    $success_message = "{$expiredCount} expired reservation(s) automatically moved to usage history.";
}

// Auto-start usage for reservations that have reached their start time
$autoStartedCount = $usageManager->autoStartUsage();
if ($autoStartedCount > 0) {
    $autoStartedMessage = "{$autoStartedCount} reservation(s) automatically started when their time arrived.";
    if (isset($success_message)) {
        $success_message .= " " . $autoStartedMessage;
    } else {
        $success_message = $autoStartedMessage;
    }
}
// Get statistics for notification badges
$stats = [];
// Total users (excluding admins)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$result = $stmt->fetch();
    $stats['users'] = $result ? $result['count'] : 0;
// Total facilities
$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$result = $stmt->fetch();
    $stats['facilities'] = $result ? $result['count'] : 0;
// Total reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$result = $stmt->fetch();
    $stats['reservations'] = $result ? $result['count'] : 0;
// Pending reservations
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending'] = 0;
}
// No-show reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
// Active usage count for notifications
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usage_logs WHERE status = 'active'");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_usages'] = 0;
}
// Pending payments count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'confirmed' AND payment_status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending_payments'] = 0;
}
// New users count (last 7 days)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['new_users'] = 0;
}
// Handle usage operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'start_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                // Check grace period status before starting usage
                $graceStatus = $paymentManager->getGracePeriodStatus($reservationId);
                if (!$graceStatus['eligible']) {
                    $error_message = "Cannot start usage: " . $graceStatus['reason'];
                    if (isset($graceStatus['time_until_grace'])) {
                        $error_message .= " (Grace period starts in " . $graceStatus['time_until_grace'] . ")";
                    }
                } else {
                    $result = $usageManager->startUsage($reservationId, $_SESSION['user_id'], $notes);
                    if ($result['success']) {
                        $success_message = $result['message'];
                        if (isset($result['payment_status']) && $result['payment_status'] === 'pending') {
                            $success_message .= " - Payment verification pending";
                        }
                    } else {
                        $error_message = $result['message'];
                    }
                }
                break;
            case 'complete_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                $result = $usageManager->completeUsage($reservationId, $_SESSION['user_id'], $notes);
                if ($result['success']) {
                    $success_message = $result['message'] . " You can view the completed usage in the Usage History page.";
                    // Redirect to refresh the page and remove the completed facility
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                    exit;
                } else {
                    $error_message = $result['message'];
                }
                break;
            case 'verify_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                $result = $usageManager->verifyUsage($reservationId, $_SESSION['user_id'], $notes);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                break;
                
            case 'manage_late_user':
                $reservationId = intval($_POST['reservation_id']);
                $lateAction = $_POST['late_action'] ?? '';
                $notes = trim($_POST['notes'] ?? '');
                $extendMinutes = intval($_POST['extend_minutes'] ?? 0);
                $newDuration = intval($_POST['new_duration'] ?? 0);
                
                $result = handleLateUser($reservationId, $lateAction, $notes, $extendMinutes, $newDuration);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                break;
        }
    }
}
// Get current usage and pending verifications
$currentUsage = $usageManager->getCurrentUsage(); // Only active usage now
$readyUsage = $usageManager->getReadyUsage(); // Ready to start usage
$pendingVerifications = $usageManager->getPendingVerifications();

// Get late users (users who haven't started usage within grace period)
$lateUsers = [];
$gracePeriodMinutes = 15; // 15 minutes grace period

foreach ($readyUsage as $usage) {
    $startTime = new DateTime($usage['start_time']);
    $now = new DateTime();
    $graceEndTime = $startTime->modify("+{$gracePeriodMinutes} minutes");
    $now = new DateTime(); // Reset now time
    
    if ($now > $graceEndTime) {
        $lateUsers[] = $usage;
    }
}

// Function to handle late user scenarios
function handleLateUser($reservationId, $action, $notes, $extendMinutes = 0, $newDuration = 0) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Get reservation details
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            return ['success' => false, 'message' => 'Reservation not found'];
        }
        
        $adminNotes = "Late user handled by admin. Action: " . ucfirst(str_replace('_', ' ', $action));
        if ($notes) {
            $adminNotes .= ". Notes: " . $notes;
        }
        
        switch ($action) {
            case 'extend_time':
                // Extend the end time
                $newEndTime = date('Y-m-d H:i:s', strtotime($reservation['end_time']) + ($extendMinutes * 60));
                $stmt = $pdo->prepare("UPDATE reservations SET end_time = ?, notes = CONCAT(COALESCE(notes, ''), ' | ', ?) WHERE id = ?");
                $stmt->execute([$newEndTime, $adminNotes, $reservationId]);
                
                $message = "Reservation extended by {$extendMinutes} minutes. New end time: " . date('g:i A', strtotime($newEndTime));
                break;
                
            case 'reduce_duration':
                // Calculate new end time based on new duration
                $startTime = strtotime($reservation['start_time']);
                $newEndTime = date('Y-m-d H:i:s', $startTime + ($newDuration * 60));
                $stmt = $pdo->prepare("UPDATE reservations SET end_time = ?, notes = CONCAT(COALESCE(notes, ''), ' | ', ?) WHERE id = ?");
                $stmt->execute([$newEndTime, $adminNotes, $reservationId]);
                
                $message = "Reservation duration reduced to {$newDuration} minutes. New end time: " . date('g:i A', strtotime($newEndTime));
                break;
                
            case 'mark_noshow':
                // Mark as no-show and cancel
                $stmt = $pdo->prepare("UPDATE reservations SET status = 'no_show', notes = CONCAT(COALESCE(notes, ''), ' | ', ?) WHERE id = ?");
                $stmt->execute([$adminNotes, $reservationId]);
                
                $message = "Reservation marked as no-show and cancelled";
                break;
                
            case 'start_late':
                // Start usage with remaining time (no changes to reservation)
                $message = "Reservation marked for late start. Admin can now start usage when user arrives";
                break;
                
            default:
                return ['success' => false, 'message' => 'Invalid action specified'];
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => $message];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Error handling late user: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Management - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1E40AF'
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .usage-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .usage-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .modal {
            transition: all 0.3s ease;
            pointer-events: auto;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        
        /* Enhanced modal animations */
        .modal {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .modal-content {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Modal entrance animations */
        .modal.show {
            animation: modalFadeIn 0.3s ease-out;
        }
        
        .modal.show .modal-content {
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalSlideIn {
            from { 
                opacity: 0; 
                transform: scale(0.8) translateY(-20px); 
            }
            to { 
                opacity: 1; 
                transform: scale(1) translateY(0); 
            }
        }
        
        /* Enhanced button hover effects */
        .modal button {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .modal button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }
        
        .modal button:hover::before {
            width: 300px;
            height: 300px;
        }
        
        /* Enhanced modal content styling */
        .modal-content {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Modal icon animations */
        .modal .w-16.h-16 {
            animation: iconBounce 0.6s ease-out;
        }
        
        @keyframes iconBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        /* Info box enhancements */
        .modal .bg-blue-50,
        .modal .bg-yellow-50 {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .modal .bg-blue-50 {
            border-left-color: #3b82f6;
        }
        
        .modal .bg-yellow-50 {
            border-left-color: #f59e0b;
        }
        
        .modal .bg-blue-50:hover,
        .modal .bg-yellow-50:hover {
            transform: translateX(5px);
        }
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .usage-card, .usage-card * {
            pointer-events: auto !important;
        }
        /* Timer styles */
        .timer-display {
            transition: all 0.3s ease;
        }
        .timer-display.timer-active {
            background: linear-gradient(45deg, #10B981, #059669) !important;
            color: white !important;
            animation: pulse-timer 2s infinite;
        }
        .timer-text {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        @keyframes pulse-timer {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }
        }
        /* Enhanced card styles for timer */
        .usage-card.timer-active {
            border-color: #10B981;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
        }
        /* Large timer display styles */
        .timer-display-large {
            transition: all 0.3s ease;
        }
        .timer-display-large.timer-active {
            color: #059669 !important;
            animation: pulse-timer-large 2s infinite;
        }
                 @keyframes pulse-timer-large {
             0%, 100% {
                 transform: scale(1);
             }
             50% {
                 transform: scale(1.02);
             }
         }
         /* Enhanced Countdown styles */
         .countdown-display {
             transition: all 0.3s ease;
             position: relative;
             overflow: hidden;
             min-height: 80px;
             display: flex;
             flex-direction: column;
             justify-content: center;
             align-items: center;
             padding: 1rem 0.5rem;
         }
         
         .countdown-display.countdown-normal {
             background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
             color: #1e40af !important;
             border: 2px solid #3b82f6;
         }
         
         .countdown-display.countdown-warning {
             background: linear-gradient(135deg, #fef3c7, #fde68a) !important;
             color: #92400e !important;
             border: 2px solid #f59e0b;
             animation: pulse-warning 2s infinite;
         }
         
         .countdown-display.countdown-urgent {
             background: linear-gradient(135deg, #fee2e2, #fecaca) !important;
             color: #dc2626 !important;
             border: 2px solid #ef4444;
             animation: pulse-urgent 1s infinite;
         }
         
         .countdown-display.countdown-ready {
             background: linear-gradient(135deg, #d1fae5, #a7f3d0) !important;
             color: #065f46 !important;
             border: 2px solid #10b981;
         }
         
         .countdown-display.countdown-expired {
             background: linear-gradient(135deg, #fef2f2, #fee2e2) !important;
             color: #991b1b !important;
             border: 2px solid #dc2626;
         }
        
        /* Late User Styling */
        .late-user-card {
            animation: pulse-late 2s infinite;
        }
        
        @keyframes pulse-late {
            0%, 100% { 
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); 
            }
            50% { 
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); 
            }
        }
        
        .late-user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }
         @keyframes pulse-warning {
             0%, 100% {
                 transform: scale(1);
                 box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
             }
             50% {
                 transform: scale(1.05);
                 box-shadow: 0 0 0 10px rgba(245, 158, 11, 0);
             }
         }
         @keyframes pulse-urgent {
             0%, 100% {
                 transform: scale(1);
                 box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7);
             }
             50% {
                 transform: scale(1.05);
                 box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
             }
         }
         .countdown-display.countdown-warning {
             color: #F59E0B !important;
             animation: pulse-countdown 2s infinite;
         }
         .countdown-display.countdown-urgent {
             color: #DC2626 !important;
             animation: pulse-countdown 1s infinite;
         }
         @keyframes pulse-countdown {
             0%, 100% {
                 transform: scale(1);
             }
             50% {
                 transform: scale(1.05);
             }
         }
         
         /* Enhanced Countdown Components */
         .countdown-main-time {
             font-size: 1.5rem;
             font-weight: 800;
             font-family: 'Courier New', monospace;
             text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
             line-height: 1.2;
             margin-bottom: 0.25rem;
         }
         
         .countdown-secondary-time {
             font-size: 0.875rem;
             font-weight: 600;
             opacity: 0.8;
             margin-top: 0.25rem;
             line-height: 1.3;
         }
         
         .countdown-timer {
             text-align: center;
             padding: 0.5rem;
             position: relative;
             z-index: 2;
         }
         
         .countdown-progress {
             height: 4px;
             background: linear-gradient(90deg, #10b981, #059669);
             border-radius: 2px;
             transition: all 0.3s ease;
             position: absolute;
             bottom: 0;
             left: 0;
             z-index: 1;
         }
         
         .countdown-progress.progress-early {
             background: linear-gradient(90deg, #10b981, #059669);
         }
         
         .countdown-progress.progress-normal {
             background: linear-gradient(90deg, #3b82f6, #1d4ed8);
         }
         
         .countdown-progress.progress-warning {
             background: linear-gradient(90deg, #f59e0b, #d97706);
         }
         
         .countdown-progress.progress-urgent {
             background: linear-gradient(90deg, #ef4444, #dc2626);
             animation: progress-pulse 1s infinite;
         }
         
         @keyframes progress-pulse {
             0%, 100% { opacity: 1; }
             50% { opacity: 0.7; }
         }
         
         .urgency-indicator {
             position: absolute;
             top: 0.5rem;
             right: 0.5rem;
             font-size: 0.75rem;
             font-weight: 700;
             padding: 0.25rem 0.5rem;
             border-radius: 0.375rem;
             text-transform: uppercase;
             letter-spacing: 0.05em;
             z-index: 3;
             box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
             backdrop-filter: blur(4px);
         }
         
         .urgency-indicator.urgent {
             background: rgba(239, 68, 68, 0.9);
             color: white;
             animation: urgent-pulse 1s infinite;
         }
         
         .urgency-indicator.warning {
             background: rgba(245, 158, 11, 0.9);
             color: white;
         }
         
         .urgency-indicator.approaching {
             background: rgba(59, 130, 246, 0.9);
             color: white;
         }
         
         .urgency-indicator.on-time {
             background: rgba(16, 185, 129, 0.9);
             color: white;
         }
         
         @keyframes urgent-pulse {
             0%, 100% { transform: scale(1); }
             50% { transform: scale(1.1); }
         }
         
         .countdown-text {
             font-size: 1rem;
             font-weight: 600;
             margin-top: 0.5rem;
             text-align: center;
             line-height: 1.4;
         }
         
         .countdown-expired-content,
         .countdown-ready-content {
             display: flex;
             align-items: center;
             justify-content: center;
             padding: 1rem;
             font-size: 1.125rem;
             font-weight: 700;
         }
         
         /* Countdown Grid Layout */
         .countdown-grid {
             display: grid;
             grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
             gap: 1.5rem;
             margin-top: 1rem;
         }
         
         .countdown-card {
             background: white;
             border-radius: 1rem;
             padding: 1.5rem;
             box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
             border: 1px solid #e5e7eb;
             transition: all 0.3s ease;
             position: relative;
             overflow: hidden;
         }
         
         /* Usage card specific styling */
         .usage-card .countdown-display {
             margin: 0.5rem 0;
             border-radius: 0.5rem;
             background: rgba(255, 255, 255, 0.8);
             backdrop-filter: blur(4px);
         }
         
         .usage-card .countdown-timer {
             margin: 0.5rem 0;
         }
         
         .usage-card .urgency-indicator {
             top: 0.25rem;
             right: 0.25rem;
             font-size: 0.7rem;
             padding: 0.2rem 0.4rem;
         }
         
         .countdown-card:hover {
             transform: translateY(-2px);
             box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
         }
         
         /* Countdown Statistics Cards */
         .countdown-stat-card {
             transition: all 0.3s ease;
             position: relative;
             overflow: hidden;
         }
         
         .countdown-stat-card::before {
             content: '';
             position: absolute;
             top: 0;
             left: -100%;
             width: 100%;
             height: 100%;
             background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
             transition: left 0.5s ease;
         }
         
         .countdown-stat-card:hover::before {
             left: 100%;
         }
         
         .countdown-stat-card:hover {
             transform: translateY(-4px);
             box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
         }
         
         .countdown-stat-card .text-2xl {
             transition: all 0.3s ease;
         }
         
         .countdown-stat-card:hover .text-2xl {
             transform: scale(1.1);
         }
         
         /* Enhanced countdown display within usage cards */
         .usage-card .bg-gradient-to-r {
             position: relative;
             overflow: hidden;
         }
         
         .usage-card .countdown-display {
             position: relative;
             min-height: 60px;
             display: flex;
             flex-direction: column;
             justify-content: center;
             align-items: center;
             padding: 0.75rem;
             margin: 0;
         }
         
         .usage-card .countdown-main-time {
             font-size: 1.25rem;
             font-weight: 700;
             margin-bottom: 0.125rem;
             text-align: center;
         }
         
         .usage-card .countdown-secondary-time {
             font-size: 0.75rem;
             font-weight: 500;
             opacity: 0.9;
             text-align: center;
         }
         
         .usage-card .countdown-progress {
             height: 3px;
             bottom: 0;
             left: 0;
             right: 0;
             width: 100%;
         }
         
         /* Responsive countdown styling */
         @media (max-width: 768px) {
             .usage-card .countdown-display {
                 min-height: 50px;
                 padding: 0.5rem;
             }
             
             .usage-card .countdown-main-time {
                 font-size: 1.125rem;
             }
             
             .usage-card .countdown-secondary-time {
                 font-size: 0.7rem;
             }
             
             .urgency-indicator {
                 font-size: 0.65rem;
                 padding: 0.15rem 0.3rem;
                 top: 0.2rem;
                 right: 0.2rem;
             }
         }
         
         @media (max-width: 480px) {
             .usage-card .countdown-display {
                 min-height: 45px;
                 padding: 0.375rem;
             }
             
             .usage-card .countdown-main-time {
                 font-size: 1rem;
             }
             
             .usage-card .countdown-secondary-time {
                 font-size: 0.65rem;
             }
             
             .urgency-indicator {
                 font-size: 0.6rem;
                 padding: 0.1rem 0.25rem;
             }
         }
         
         /* Enhanced Prettier Navbar Design (Copied from Dashboard) */
        .nav-container {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.95) 0%, rgba(197, 176, 205, 0.95) 50%, rgba(65, 94, 114, 0.1) 100%);
            border-bottom: 3px solid rgba(65, 94, 114, 0.3);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(23, 49, 62, 0.15);
            position: sticky;
            top: 0;
            z-index: 50;
            transition: all 0.3s ease;
            min-height: 80px;
        }
        .nav-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD, #415E72, #17313E, #415E72, #C5B0CD, #F3E2D4);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        /* Enhanced Navigation Layout */
        .nav-links-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #415E72 rgba(243, 226, 212, 0.3);
            flex-wrap: wrap;
            min-height: 60px;
        }
        .nav-links-container::-webkit-scrollbar {
            height: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(90deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(90deg, #17313E, #415E72);
        }
        .nav-link {
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 16px;
            padding: 10px 16px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #17313E;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid transparent;
            backdrop-filter: blur(15px);
            font-family: "Poppins", sans-serif;
            font-size: 0.9rem;
            white-space: nowrap;
            min-width: fit-content;
            flex-shrink: 0;
        }
        .nav-link::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(197, 176, 205, 0.4), transparent);
            transition: left 0.6s ease;
        }
        .nav-link:hover::before {
            left: 100%;
        }
        .nav-link:hover {
            color: #415E72;
            background: rgba(197, 176, 205, 0.4);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .nav-link.active {
            color: white;
            background: linear-gradient(135deg, #415E72, #17313E);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.4);
            border-color: #415E72;
            transform: translateY(-2px);
        }
        .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 4px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD);
            border-radius: 2px;
            box-shadow: 0 2px 6px rgba(243, 226, 212, 0.5);
        }
        .nav-link i {
            font-size: 16px;
            transition: all 0.4s ease;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .nav-link:hover i {
            transform: scale(1.15) rotate(5deg);
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.2));
        }
        .nav-link.active i {
            transform: scale(1.1);
            filter: drop-shadow(0 3px 6px rgba(255,255,255,0.3));
        }
        .nav-link span {
            font-size: 0.85rem;
            font-weight: 500;
        }
        /* Enhanced Brand and Admin Badge */
        .nav-brand {
            font-family: "Poppins", sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
            background: linear-gradient(135deg, #415E72, #17313E, #C5B0CD);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 4px rgba(23, 49, 62, 0.1);
            transition: all 0.3s ease;
        }
        .nav-brand:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }
        .admin-badge {
            background: linear-gradient(135deg, #415E72, #17313E);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.3);
            font-family: "Poppins", sans-serif;
            border: 2px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .admin-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.4);
            border-color: rgba(243, 226, 212, 0.5);
        }
        .admin-badge i {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        /* Enhanced Mobile Navigation */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.4s ease;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            border: 2px solid rgba(197, 176, 205, 0.3);
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.4);
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: linear-gradient(90deg, #17313E, #415E72);
            margin: 3px 0;
            transition: 0.4s;
            border-radius: 2px;
            box-shadow: 0 2px 4px rgba(23, 49, 62, 0.2);
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        /* Enhanced Mobile Layout */
        @media (max-width: 1024px) {
            .nav-links-container {
            gap: 6px;
                padding: 10px 0;
            }
            .nav-link {
                padding: 8px 12px;
                font-size: 0.8rem;
                gap: 6px;
            }
            .nav-link span {
                font-size: 0.8rem;
            }
            .nav-link i {
                font-size: 14px;
            }
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                display: none;
            }
            .nav-links-container.show {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: linear-gradient(180deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.95) 100%);
                border-top: 3px solid rgba(65, 94, 114, 0.3);
                box-shadow: 0 12px 35px rgba(23, 49, 62, 0.2);
                z-index: 40;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(20px);
                padding: 20px;
                gap: 12px;
            }
            .nav-links-container .nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 16px 20px;
                border-radius: 12px;
                margin-bottom: 8px;
                font-size: 1rem;
                gap: 12px;
            }
            .nav-links-container .nav-link:hover {
                transform: translateX(8px);
                border-left: 4px solid #415E72;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #F3E2D4;
            }
        }
        /* Enhanced Navbar animations on scroll */
        .nav-container.scrolled {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.98) 100%);
            box-shadow: 0 12px 40px rgba(23, 49, 62, 0.2);
            border-bottom: 3px solid rgba(65, 94, 114, 0.4);
        }
        /* Enhanced scrollbar for mobile menu */
        .nav-links-container::-webkit-scrollbar {
            width: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #17313E, #415E72);
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        /* Enhanced Mobile Navigation Styles */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.3);
            transform: scale(1.05);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: #17313E;
            margin: 3px 0;
            transition: 0.3s;
            border-radius: 2px;
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
        }
        .nav-links-container {
            transition: all 0.3s ease;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 0 1rem;
        }
        .nav-links-container::-webkit-scrollbar {
            display: none;
        }
        .nav-links-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: max-content;
            padding: 0 0.5rem;
        }
        @media (max-width: 768px) {
            .nav-links-container {
                padding: 0;
            }
            .nav-links-wrapper {
                flex-direction: column;
                gap: 0;
                min-width: auto;
                padding: 0;
            }
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: white;
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
            }
            .nav-links-container.show {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }
            .nav-links-container .nav-link {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-radius: 0;
                border-bottom: 1px solid #f3f4f6;
                margin: 0;
                width: 100%;
            }
            .nav-links-container .nav-link:hover {
                background: rgba(59, 130, 246, 0.05);
                transform: none;
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #3B82F6, #1E40AF);
                color: white;
                border-bottom: 1px solid #3B82F6;
            }
            .nav-links-container .nav-link.active::before {
                display: none;
            }
            .nav-links-container .nav-link span {
                font-size: 16px;
                font-weight: 500;
            }
            .nav-links-container .nav-link i {
                font-size: 18px;
            }
            .nav-divider {
                height: 1px;
                background: #e5e7eb;
                margin: 8px 20px;
            }
            .brand-text {
                display: none;
            }
            .brand-text-mobile {
                display: block;
            }
        }
        @media (min-width: 769px) {
            .brand-text {
                display: block;
            }
            .brand-text-mobile {
                display: none;
            }
        }
        /* Enhanced Mobile Responsiveness */
        @media (max-width: 640px) {
            /* Mobile-first grid adjustments */
            .grid {
                grid-template-columns: 1fr !important;
            }
            /* Mobile card adjustments */
            .stat-card, .facility-card, .category-card {
                margin-bottom: 1rem;
            }
            /* Mobile table responsiveness */
            .overflow-x-auto {
                font-size: 0.875rem;
            }
            /* Mobile form adjustments */
            .space-y-4 > * {
                margin-bottom: 1rem;
            }
            /* Mobile button adjustments */
            .btn, button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            /* Mobile modal adjustments */
            .modal-content {
                margin: 1rem;
                max-height: calc(100vh - 2rem);
            }
            /* Mobile navigation improvements */
            .nav-links-container {
                padding: 0;
            }
            .nav-links-container .nav-link {
                padding: 1rem 1.25rem;
                font-size: 1rem;
            }
            /* Mobile header adjustments */
            .text-3xl, .text-4xl {
                font-size: 1.5rem !important;
            }
            .text-2xl {
                font-size: 1.25rem !important;
            }
            /* Mobile spacing adjustments */
            .p-6, .p-8 {
                padding: 1rem !important;
            }
            .mb-8, .mb-6 {
                margin-bottom: 1rem !important;
            }
            /* Mobile filter adjustments */
            .filter-section {
                flex-direction: column;
            }
            .filter-section > * {
                margin-bottom: 0.5rem;
            }
            /* Mobile table cell adjustments */
            .px-6 {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            .py-4 {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
        }
        @media (max-width: 768px) {
            /* Tablet adjustments */
            .grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
            .grid-cols-3, .grid-cols-4 {
                grid-template-columns: repeat(2, 1fr);
            }
            /* Tablet navigation */
            .nav-links-container {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .nav-links-container .nav-link {
                flex: 1 1 auto;
                min-width: 120px;
            }
        }
        @media (max-width: 1024px) {
            /* Small desktop adjustments */
            .max-w-7xl {
                max-width: 100%;
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            /* Touch device optimizations */
            .nav-link, .btn, button, a {
                min-height: 44px;
                min-width: 44px;
            }
            .nav-link {
                padding: 12px 16px;
            }
            /* Larger touch targets for mobile */
            .mobile-menu-toggle {
                min-height: 44px;
                min-width: 44px;
                padding: 12px;
            }
            /* Improved touch feedback */
            .nav-link:active, .btn:active, button:active {
                transform: scale(0.95);
            }
        }
        /* Accessibility improvements */
        .nav-link:focus, .btn:focus, button:focus {
            outline: 2px solid #3B82F6;
            outline-offset: 2px;
        }
        /* Loading states for mobile */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        /* Mobile-specific animations */
        @media (max-width: 768px) {
            .animate-slide-up {
                animation: slideUpMobile 0.3s ease-out;
            }
            @keyframes slideUpMobile {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        }
        /* Mobile Table Enhancements */
        @media (max-width: 768px) {
            /* Mobile table card layout */
            .mobile-table-card {
                display: block;
            }
            .mobile-table-card .table-row {
                display: block;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
                background: white;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            .mobile-table-card .table-row > * {
                display: block;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .mobile-table-card .table-row > *:last-child {
                border-bottom: none;
            }
            .mobile-table-card .table-header {
                display: none;
            }
            /* Mobile table labels */
            .mobile-table-card .table-row > *::before {
                content: attr(data-label) ": ";
                font-weight: 600;
                color: #6b7280;
                display: inline-block;
                min-width: 100px;
            }
            /* Hide regular table on mobile */
            .table-responsive {
                display: none;
            }
            .mobile-table-card {
                display: block;
            }
        }
        @media (min-width: 769px) {
            /* Show regular table on desktop */
            .table-responsive {
                display: block;
            }
            .mobile-table-card {
                display: none;
            }
        }
        /* Mobile Filter Enhancements */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-section > * {
                width: 100%;
            }
            .filter-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .filter-buttons > * {
                width: 100%;
            }
            /* Mobile date inputs */
            input[type="date"], input[type="datetime-local"] {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            /* Mobile select dropdowns */
            select {
                font-size: 16px;
                padding: 0.75rem;
            }
            /* Mobile search inputs */
            input[type="search"], input[type="text"] {
                font-size: 16px;
                padding: 0.75rem;
            }
        }
        /* Mobile Modal Enhancements */
        @media (max-width: 768px) {
            .modal-content {
                margin: 1rem;
                max-width: calc(100vw - 2rem);
                max-height: calc(100vh - 2rem);
                overflow-y: auto;
            }
            .modal-content .p-6 {
                padding: 1rem;
            }
            /* Mobile form in modals */
            .modal-content form {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            .modal-content form > * {
                width: 100%;
            }
            .modal-content .flex.space-x-3 {
                flex-direction: column;
                gap: 0.5rem;
            }
            .modal-content .flex.space-x-3 > * {
                width: 100%;
            }
        }
        /* Mobile Button Enhancements */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-buttons > * {
                width: 100%;
                justify-content: center;
            }
            /* Mobile card actions */
            .card-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .card-actions > * {
                width: 100%;
            }
        }
        /* Mobile Statistics Cards */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .stat-card .text-3xl {
                font-size: 1.5rem !important;
            }
            .stat-card .text-lg {
                font-size: 1rem !important;
            }
        }
        /* Mobile Navigation Final Touches */
        @media (max-width: 768px) {
            .nav-links-container .nav-link {
                border-radius: 0;
                margin: 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .nav-links-container .nav-link:last-child {
                border-bottom: none;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #3B82F6;
                background: linear-gradient(90deg, #3B82F6, #1E40AF);
            }
            /* Mobile brand text */
            .brand-text-mobile {
                font-size: 1rem;
            }
        }
        /* Enhanced Notification Badge Styles - Fixed Visibility */
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: pulse-notification 2s infinite;
            z-index: 9999;
            transition: all 0.3s ease;
            pointer-events: none;
        }
        .notification-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
        }
        .notification-badge.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .notification-badge.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        @keyframes pulse-notification {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
            50% {
                transform: scale(1.15);
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
            }
        }
        .nav-link {
            position: relative;
            overflow: visible !important;
        }
        .nav-links-container {
            position: relative;
            z-index: 1000;
        }
        .nav-link .notification-badge {
            animation: pulse-notification 2s infinite;
            z-index: 9999;
        }
        .nav-link:hover .notification-badge {
            animation: none;
            transform: scale(1.2);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
        }
        .nav-link:hover .notification-badge.warning {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.6);
        }
        .nav-link:hover .notification-badge.info {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.6);
        }
        .nav-link:hover .notification-badge.success {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.6);
        }
        /* Ensure nav-container doesn't clip badges */
        .nav-container {
            overflow: visible !important;
        }
        /* Mobile responsive notification badge */
        @media (max-width: 768px) {
            .notification-badge {
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
                top: -5px;
                right: -5px;
                border-width: 2px;
            }
        }
        /* Enhanced nav-link hover effects */
        .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
        }
        .nav-link.active {
            box-shadow: 0 4px 16px rgba(65, 94, 114, 0.3);
        }
        .notification-badge {
            /* Force visibility */
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
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
        <div class="mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-clock text-primary mr-3"></i>Facility Usage Management
            </h1>
            <p class="text-gray-600">Track and verify facility usage by users</p>
        </div>
        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>
        <!-- Ready Reservations Notification -->
        <?php if (!empty($readyUsage)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        <span class="font-semibold"><?php echo count($readyUsage); ?> reservation(s) ready to start usage</span>
                    </div>
                    <div class="text-sm">
                        <i class="fas fa-clock mr-1"></i>
                        Countdown timers are active - reservations will be ready when timers reach zero
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <!-- Active Usage Notification -->
        <?php if (!empty($currentUsage)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-play-circle mr-2"></i>
                        <span class="font-semibold"><?php echo count($currentUsage); ?> facility(ies) currently in use</span>
                    </div>
                    <div class="text-sm">
                        <i class="fas fa-stopwatch mr-1"></i>
                        Live timers are running - usage will auto-complete when reservation time expires
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            </div>
        <?php endif; ?>
        <!-- Live Usage Timers -->
        <?php 
        $activeUsage = array_filter($currentUsage, function($usage) {
            return $usage['status'] === 'active';
        });
        ?>
        <?php if (!empty($activeUsage)): ?>
            <div class="mb-8 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-xl p-6 animate-slide-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-stopwatch text-green-500 mr-3"></i>Live Usage Timers
                    </h2>
                                    <div class="text-sm text-gray-600">
                    <span id="autoRefreshStatus">
                        <i class="fas fa-pause mr-1 text-orange-500"></i>Auto-refresh disabled
                    </span>
                    <button onclick="refreshTimers()" class="ml-2 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs transition duration-200">
                        <i class="fas fa-sync-alt mr-1"></i>Refresh Timers
                    </button>
                    <button onclick="location.reload()" class="ml-2 bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-xs transition duration-200">
                        <i class="fas fa-redo mr-1"></i>Reload Page
                    </button>
                    <button id="toggleAutoRefresh" onclick="toggleAutoRefresh()" class="ml-2 bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs transition duration-200">
                        <i class="fas fa-play mr-1"></i>Enable Auto-refresh
                    </button>
                </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($activeUsage as $usage): ?>
                        <div class="bg-white rounded-lg p-4 border border-green-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($usage['facility_name']); ?></h3>
                                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($usage['user_name']); ?></span>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-mono font-bold text-green-600 timer-display-large" 
                                     data-reservation-id="<?php echo $usage['reservation_id']; ?>" 
                                     data-usage-started="<?php echo $usage['started_at']; ?>">
                                    00:00:00
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Started: <?php echo date('g:i A', strtotime($usage['started_at'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
                 <!-- Current Usage Section -->
         <div class="mb-8">
             <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                 <i class="fas fa-play-circle text-green-500 mr-2"></i>Active Usage
                 <span class="ml-2 bg-green-100 text-green-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                     <?php echo count($currentUsage); ?>
                 </span>
             </h2>
             <?php if (empty($currentUsage)): ?>
                 <div class="bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-xl p-8 text-center">
                     <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                         <i class="fas fa-info-circle text-green-500 text-2xl"></i>
                     </div>
                     <h3 class="text-lg font-semibold text-gray-800 mb-2">No Active Usage</h3>
                     <p class="text-gray-600">No facilities are currently in use.</p>
                 </div>
             <?php else: ?>
                 <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                     <?php foreach ($currentUsage as $usage): ?>
                         <div class="usage-card bg-white rounded-xl shadow-lg overflow-hidden animate-slide-up border border-green-200">
                             <div class="h-40 bg-gradient-to-br from-green-400 via-green-500 to-blue-500 flex items-center justify-center relative">
                                 <div class="absolute inset-0 bg-black bg-opacity-10"></div>
                                 <div class="relative z-10 text-center">
                                     <div class="h-20 w-20 bg-white bg-opacity-25 rounded-full flex items-center justify-center mb-3 backdrop-blur-sm">
                                         <i class="fas fa-play text-white text-3xl"></i>
                                     </div>
                                     <p class="text-white font-bold text-lg"><?php echo htmlspecialchars($usage['facility_name']); ?></p>
                                     <p class="text-white text-sm opacity-90">Active Session</p>
                                 </div>
                                 <div class="absolute top-3 right-3">
                                     <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white bg-opacity-25 text-white backdrop-blur-sm timer-display" 
                                           data-reservation-id="<?php echo $usage['reservation_id']; ?>" 
                                           data-usage-started="<?php echo $usage['started_at']; ?>">
                                         <i class="fas fa-clock mr-2"></i>
                                         <span class="timer-text font-mono">00:00:00</span>
                                     </span>
                                 </div>
                             </div>
                             <div class="p-6">
                                 <div class="mb-4">
                                     <h3 class="text-xl font-bold text-gray-800 mb-1"><?php echo htmlspecialchars($usage['user_name']); ?></h3>
                                     <p class="text-sm text-gray-600 flex items-center mb-2">
                                         <i class="fas fa-calendar-alt mr-2 text-green-500"></i>
                                         Started: <?php echo date('M j, Y g:i A', strtotime($usage['started_at'])); ?>
                                     </p>
                                     <!-- Enhanced End Time Countdown -->
                                     <div class="bg-gradient-to-r from-orange-50 to-red-50 border border-orange-200 rounded-lg p-3 mb-3 relative">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-sm font-semibold text-orange-800">Reservation Ends In:</span>
                                             <i class="fas fa-stopwatch text-orange-500"></i>
                                         </div>
                                         <div class="countdown-display text-center relative" 
                                              data-end-time="<?php echo $usage['end_time']; ?>"
                                              data-type="end">
                                             <!-- Urgency Indicator -->
                                             <div class="urgency-indicator">
                                                 <i class="fas fa-clock text-orange-500"></i> ON TIME
                                             </div>
                                             
                                             <!-- Main Countdown Timer -->
                                             <div class="countdown-timer">
                                                 <div class="countdown-main-time">
                                                     <?php 
                                                     $endTime = new DateTime($usage['end_time']);
                                                     $now = new DateTime();
                                                     $timeLeft = $endTime->getTimestamp() - $now->getTimestamp();
                                                     if ($timeLeft <= 0) {
                                                         echo '<span class="text-red-600">Time Expired!</span>';
                                                     } else {
                                                         $hours = floor($timeLeft / 3600);
                                                         $minutes = floor(($timeLeft % 3600) / 60);
                                                         $seconds = $timeLeft % 60;
                                                         if ($hours > 0) {
                                                             echo "{$hours}h {$minutes}m";
                                                         } elseif ($minutes > 0) {
                                                             echo "{$minutes}m {$seconds}s";
                                                         } else {
                                                             echo "{$seconds}s";
                                                         }
                                                     }
                                                     ?>
                                                 </div>
                                                 <div class="countdown-secondary-time">
                                                     <?php 
                                                     if ($timeLeft > 0) {
                                                         $hours = floor($timeLeft / 3600);
                                                         $minutes = floor(($timeLeft % 3600) / 60);
                                                         $seconds = $timeLeft % 60;
                                                         if ($hours > 0) {
                                                             echo "{$hours}h {$minutes}m {$seconds}s";
                                                         } elseif ($minutes > 0) {
                                                             echo "{$minutes}m {$seconds}s";
                                                         } else {
                                                             echo "{$seconds}s";
                                                         }
                                                     }
                                                     ?>
                                                 </div>
                                             </div>
                                             
                                             <!-- Progress Bar -->
                                             <div class="countdown-progress" style="width: 0%"></div>
                                         </div>
                                     </div>
                                 </div>
                                 <div class="flex space-x-3">
                                     <button id="usage-btn-<?php echo $usage['reservation_id']; ?>" 
                                             onclick="completeUsage(<?php echo $usage['reservation_id']; ?>)" 
                                             class="flex-1 text-white text-center py-3 rounded-lg transition duration-200 transform hover:scale-105 font-semibold shadow-md bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600">
                                         <i class="fas fa-stop mr-2"></i>
                                         <span id="usage-btn-text-<?php echo $usage['reservation_id']; ?>">Complete Usage</span>
                                     </button>
                                 </div>
                             </div>
                         </div>
                     <?php endforeach; ?>
                 </div>
             <?php endif; ?>
         </div>
         <!-- Late Users Section -->
         <?php if (!empty($lateUsers)): ?>
             <div class="mb-8">
                 <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                     <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>Late Users (Action Required)
                     <span class="ml-2 bg-red-100 text-red-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                         <?php echo count($lateUsers); ?>
                     </span>
                 </h2>
                 <div class="bg-gradient-to-r from-red-50 to-orange-50 border border-red-200 rounded-xl p-4 mb-4">
                     <div class="flex items-center text-red-800">
                         <i class="fas fa-info-circle mr-2"></i>
                         <span class="text-sm font-medium">Users are late for their reservations. Take action to manage these bookings.</span>
                     </div>
                 </div>
                 <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                     <?php foreach ($lateUsers as $usage): ?>
                         <div class="usage-card late-user-card bg-white rounded-xl shadow-lg overflow-hidden animate-slide-up border border-red-200">
                             <div class="h-40 bg-gradient-to-br from-red-400 via-orange-500 to-red-500 flex items-center justify-center relative">
                                 <div class="absolute inset-0 bg-black bg-opacity-10"></div>
                                 <div class="relative z-10 text-center">
                                     <div class="h-20 w-20 bg-white bg-opacity-25 rounded-full flex items-center justify-center mb-3 backdrop-blur-sm">
                                         <i class="fas fa-exclamation-triangle text-white text-3xl"></i>
                                     </div>
                                     <p class="white font-bold text-lg"><?php echo htmlspecialchars($usage['facility_name']); ?></p>
                                     <p class="text-white text-sm opacity-90">Late User</p>
                                 </div>
                                 <div class="absolute top-3 right-3">
                                     <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-500 text-white backdrop-blur-sm">
                                         <i class="fas fa-clock mr-2"></i>
                                         <?php 
                                         $startTime = new DateTime($usage['start_time']);
                                         $now = new DateTime();
                                         $lateMinutes = $now->diff($startTime)->i;
                                         echo "{$lateMinutes}m late";
                                         ?>
                                     </span>
                                 </div>
                             </div>
                             <div class="p-6">
                                 <div class="mb-4">
                                     <h3 class="text-xl font-bold text-gray-800 mb-1"><?php echo htmlspecialchars($usage['user_name']); ?></h3>
                                     <p class="text-sm text-gray-600 flex items-center mb-2">
                                         <i class="fas fa-calendar-alt mr-2 text-red-500"></i>
                                         <?php echo date('M j, Y', strtotime($usage['start_time'])); ?>
                                     </p>
                                     <p class="text-sm text-gray-600 flex items-center mb-3">
                                         <i class="fas fa-clock mr-2 text-red-500"></i>
                                         <?php echo date('g:i A', strtotime($usage['start_time'])); ?> - 
                                         <?php echo date('g:i A', strtotime($usage['end_time'])); ?>
                                     </p>
                                     <!-- Late Status Info -->
                                     <div class="bg-gradient-to-r from-red-50 to-orange-50 border border-red-200 rounded-lg p-3 mb-4">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-sm font-semibold text-red-800">Late Status:</span>
                                             <i class="fas fa-exclamation-triangle text-red-500"></i>
                                         </div>
                                         <div class="text-center">
                                             <div class="text-lg font-mono font-bold text-red-600">
                                                 <?php 
                                                 $startTime = new DateTime($usage['start_time']);
                                                 $now = new DateTime();
                                                 $lateMinutes = $now->diff($startTime)->i;
                                                 $lateSeconds = $now->diff($startTime)->s;
                                                 if ($lateMinutes > 0) {
                                                     echo "{$lateMinutes}m {$lateSeconds}s late";
                                                 } else {
                                                     echo "{$lateSeconds}s late";
                                                 }
                                                 ?>
                                             </div>
                                         </div>
                                     </div>
                                 </div>
                                 <div class="flex space-x-3">
                                     <button onclick="startUsage(<?php echo $usage['reservation_id']; ?>)" 
                                             class="flex-1 bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white text-center py-3 rounded-lg transition duration-200 transform hover:scale-105 font-semibold shadow-md">
                                         <i class="fas fa-play mr-2"></i>Start Usage
                                     </button>
                                     <button onclick="showLateUserOptions(<?php echo $usage['reservation_id']; ?>)" 
                                             class="flex-1 bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white text-center py-3 rounded-lg transition duration-200 transform hover:scale-105 font-semibold shadow-md">
                                         <i class="fas fa-cog mr-2"></i>Manage
                                     </button>
                                 </div>
                             </div>
                         </div>
                     <?php endforeach; ?>
                 </div>
             </div>
         <?php endif; ?>
         
         <!-- Ready to Start Section -->
         <?php if (!empty($readyUsage)): ?>
             <div class="mb-8">
                 <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                     <i class="fas fa-clock text-blue-500 mr-2"></i>Ready to Start (Countdown Timers)
                     <span class="ml-2 bg-blue-100 text-blue-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                         <?php echo count($readyUsage); ?>
                     </span>
                 </h2>
                 <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                     <?php foreach ($readyUsage as $usage): ?>
                         <div class="usage-card bg-white rounded-xl shadow-lg overflow-hidden animate-slide-up border border-blue-200">
                             <div class="h-40 bg-gradient-to-br from-blue-400 via-blue-500 to-indigo-500 flex items-center justify-center relative">
                                 <div class="absolute inset-0 bg-black bg-opacity-10"></div>
                                 <div class="relative z-10 text-center">
                                     <div class="h-20 w-20 bg-white bg-opacity-25 rounded-full flex items-center justify-center mb-3 backdrop-blur-sm">
                                         <i class="fas fa-clock text-white text-3xl"></i>
                                     </div>
                                     <p class="text-white font-bold text-lg"><?php echo htmlspecialchars($usage['facility_name']); ?></p>
                                     <p class="text-white text-sm opacity-90">Ready to Start</p>
                                 </div>
                             </div>
                                                              <div class="p-6">
                                 <div class="mb-4">
                                     <h3 class="text-xl font-bold text-gray-800 mb-1"><?php echo htmlspecialchars($usage['user_name']); ?></h3>
                                     <p class="text-sm text-gray-600 flex items-center mb-2">
                                         <i class="fas fa-calendar-alt mr-2 text-blue-500"></i>
                                         <?php echo date('M j, Y', strtotime($usage['start_time'])); ?>
                                     </p>
                                     <p class="text-sm text-gray-600 flex items-center mb-3">
                                         <i class="fas fa-clock mr-2 text-blue-500"></i>
                                         <?php echo date('g:i A', strtotime($usage['start_time'])); ?> - 
                                         <?php echo date('g:i A', strtotime($usage['end_time'])); ?>
                                     </p>
                                     <!-- Enhanced Countdown Timer -->
                                     <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-4 relative">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-sm font-semibold text-blue-800">Reservation Starts In:</span>
                                             <i class="fas fa-hourglass-half text-blue-500"></i>
                                         </div>
                                         <div class="countdown-display text-center relative" 
                                              data-start-time="<?php echo $usage['start_time']; ?>"
                                              data-type="start">
                                             <!-- Urgency Indicator -->
                                             <div class="urgency-indicator">
                                                 <i class="fas fa-clock text-blue-500"></i> ON TIME
                                             </div>
                                             
                                             <!-- Main Countdown Timer -->
                                             <div class="countdown-timer">
                                                 <div class="countdown-main-time">
                                                     <?php 
                                                     $startTime = new DateTime($usage['start_time']);
                                                     $now = new DateTime();
                                                     $timeLeft = $startTime->getTimestamp() - $now->getTimestamp();
                                                     if ($timeLeft <= 0) {
                                                         echo '<span class="text-green-600">Ready Now!</span>';
                                                     } else {
                                                         $hours = floor($timeLeft / 3600);
                                                         $minutes = floor(($timeLeft % 3600) / 60);
                                                         $seconds = $timeLeft % 60;
                                                         if ($hours > 0) {
                                                             echo "{$hours}h {$minutes}m";
                                                         } elseif ($minutes > 0) {
                                                             echo "{$minutes}m {$seconds}s";
                                                         } else {
                                                             echo "{$seconds}s";
                                                         }
                                                     }
                                                     ?>
                                                 </div>
                                                 <div class="countdown-secondary-time">
                                                     <?php 
                                                     if ($timeLeft > 0) {
                                                         $hours = floor($timeLeft / 3600);
                                                         $minutes = floor(($timeLeft % 3600) / 60);
                                                         $seconds = $timeLeft % 60;
                                                         if ($hours > 0) {
                                                             echo "{$hours}h {$minutes}m {$seconds}s";
                                                         } elseif ($minutes > 0) {
                                                             echo "{$minutes}m {$seconds}s";
                                                         } else {
                                                             echo "{$seconds}s";
                                                         }
                                                     }
                                                     ?>
                                                 </div>
                                             </div>
                                             
                                             <!-- Progress Bar -->
                                             <div class="countdown-progress" style="width: 0%"></div>
                                         </div>
                                     </div>
                                     <?php if ($usage['notes']): ?>
                                         <p class="text-sm text-gray-500 mt-2 italic">
                                             <i class="fas fa-sticky-note mr-1"></i>
                                             <?php echo htmlspecialchars($usage['notes']); ?>
                                         </p>
                                     <?php endif; ?>
                                 </div>
                                 <div class="flex space-x-3">
                                     <button id="usage-btn-<?php echo $usage['reservation_id']; ?>" 
                                             onclick="startUsage(<?php echo $usage['reservation_id']; ?>)" 
                                             class="flex-1 bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white text-center py-3 rounded-lg transition duration-200 transform hover:scale-105 font-semibold shadow-md">
                                         <i class="fas fa-play mr-2"></i>
                                         <span id="usage-btn-text-<?php echo $usage['reservation_id']; ?>">Start Usage</span>
                                     </button>
                                 </div>
                             </div>
                         </div>
                     <?php endforeach; ?>
                 </div>
             </div>
         <?php endif; ?>
             </div>
         <!-- Success Modal for Completed Usage -->
    <?php if (isset($success_message) && strpos($success_message, 'completed') !== false): ?>
    <div id="successModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center opacity-0 invisible backdrop-blur-sm show">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 border border-gray-100">
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Usage Completed Successfully!</h3>
                    <p class="text-gray-600">The facility usage has been completed and moved to usage history.</p>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h4 class="font-medium text-blue-800 mb-2 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>What happens next?
                    </h4>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li>• The usage record has been saved to Usage History</li>
                        <li>• You can view detailed information in the Usage History page</li>
                        <li>• The facility is now available for new reservations</li>
                        <li>• Usage statistics have been updated</li>
                    </ul>
                </div>
                <div class="flex space-x-3">
                    <button onclick="closeSuccessModal()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                        <i class="fas fa-times mr-2"></i>Close
                    </button>
                    <a href="usage_history.php" class="flex-1 bg-primary hover:bg-secondary text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold text-center">
                        <i class="fas fa-history mr-2"></i>View Usage History
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Usage Verification Modal -->
    <div id="usageModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center opacity-0 invisible backdrop-blur-sm">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 border border-gray-100">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 id="modalTitle" class="text-xl font-semibold text-gray-900">Verify Facility Usage</h3>
                    <button onclick="closeUsageModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="verify_usage">
                    <input type="hidden" id="reservation_id" name="reservation_id">
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-sticky-note mr-2"></i>Admin Notes
                        </label>
                        <textarea id="notes" name="notes" rows="3" 
                                  placeholder="Add any notes about this usage verification..."
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"></textarea>
                    </div>
                    <div class="flex space-x-3 pt-4">
                        <button type="submit" id="submitBtn"
                                class="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-check mr-2"></i>Verify Usage
                        </button>
                        <button type="button" onclick="closeUsageModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Start Usage Confirmation Modal -->
    <div id="startUsageModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center opacity-0 invisible backdrop-blur-sm">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 border border-gray-100">
            <div class="p-6">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-play text-green-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Start Facility Usage</h3>
                    <p class="text-gray-600">Are you sure you want to start tracking the usage time for this facility?</p>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                        <div class="text-sm text-blue-700">
                            <p class="font-medium mb-1">What happens when you start usage:</p>
                            <ul class="space-y-1">
                                <li>• Usage timer will begin counting</li>
                                <li>• Facility status will change to "In Use"</li>
                                <li>• Usage tracking will be activated</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="start_usage">
                    <input type="hidden" id="startUsageReservationId" name="reservation_id">
                    <input type="hidden" name="notes" value="Usage started by admin">
                    <div class="flex space-x-3">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-play mr-2"></i>Start Usage
                        </button>
                        <button type="button" onclick="closeStartUsageModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Late User Management Modal -->
    <div id="lateUserModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center opacity-0 invisible backdrop-blur-sm">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 border border-gray-100">
            <div class="p-6">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Manage Late User</h3>
                    <p class="text-gray-600">Choose how to handle this late reservation</p>
                </div>
                
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-red-500 mt-1 mr-3"></i>
                        <div class="text-sm text-red-700">
                            <p class="font-medium mb-1">Late User Options:</p>
                            <ul class="space-y-1">
                                <li>• <strong>Extend Time:</strong> Give extra time to compensate for lateness</li>
                                <li>• <strong>Reduce Duration:</strong> Shorten the session to fit remaining time</li>
                                <li>• <strong>Mark No-Show:</strong> Cancel if user doesn't arrive</li>
                                <li>• <strong>Start Late:</strong> Begin usage with remaining time</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="manage_late_user">
                    <input type="hidden" id="lateUserReservationId" name="reservation_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Action to Take:</label>
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input type="radio" name="late_action" value="extend_time" class="mr-3" checked>
                                <span class="text-sm">Extend Time (Add 15 minutes to compensate)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="late_action" value="reduce_duration" class="mr-3">
                                <span class="text-sm">Reduce Duration (Fit to remaining time)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="late_action" value="mark_noshow" class="mr-3">
                                <span class="text-sm">Mark as No-Show (Cancel reservation)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="late_action" value="start_late" class="mr-3">
                                <span class="text-sm">Start Late (Use remaining time only)</span>
                            </label>
                        </div>
                    </div>
                    
                    <div id="extendTimeOptions" class="hidden">
                        <label for="extend_minutes" class="block text-sm font-medium text-gray-700 mb-2">Extension Minutes:</label>
                        <select id="extend_minutes" name="extend_minutes" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            <option value="15">15 minutes</option>
                            <option value="30">30 minutes</option>
                            <option value="45">45 minutes</option>
                            <option value="60">1 hour</option>
                        </select>
                    </div>
                    
                    <div id="reduceDurationOptions" class="hidden">
                        <label for="new_duration" class="block text-sm font-medium text-gray-700 mb-2">New Duration:</label>
                        <select id="new_duration" name="new_duration" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            <option value="15">15 minutes</option>
                            <option value="30">30 minutes</option>
                            <option value="45">45 minutes</option>
                            <option value="60">1 hour</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="late_user_notes" class="block text-sm font-medium text-gray-700 mb-2">Admin Notes:</label>
                        <textarea id="late_user_notes" name="notes" rows="3" 
                                  placeholder="Add notes about how you handled this late user..."
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"></textarea>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-red-500 to-orange-500 hover:from-red-600 hover:to-orange-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-check mr-2"></i>Apply Action
                        </button>
                        <button type="button" onclick="closeLateUserModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Complete Usage Confirmation Modal -->
    <div id="completeUsageModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center opacity-0 invisible backdrop-blur-sm">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 border border-gray-100">
            <div class="p-6">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-stop text-orange-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Complete Facility Usage</h3>
                    <p class="text-gray-600">Are you sure you want to complete the usage of this facility?</p>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3"></i>
                        <div class="text-sm text-yellow-700">
                            <p class="font-medium mb-1">What happens when you complete usage:</p>
                            <ul class="space-y-1">
                                <li>• Usage timer will stop</li>
                                <li>• Usage record will move to Usage History</li>
                                <li>• Facility will be available for new reservations</li>
                                <li>• Usage statistics will be updated</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="complete_usage">
                    <input type="hidden" id="completeUsageReservationId" name="reservation_id">
                    <input type="hidden" name="notes" value="Usage completed by admin">
                    <div class="flex space-x-3">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-stop mr-2"></i>Complete Usage
                        </button>
                        <button type="button" onclick="closeCompleteUsageModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Real-Time Countdown Monitoring -->
    <div class="mb-8 bg-gradient-to-r from-purple-50 to-pink-50 border border-purple-200 rounded-xl p-6 animate-slide-up">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                <i class="fas fa-hourglass-half text-purple-500 mr-3"></i>Real-Time Countdown Monitoring
            </h2>
            <div class="text-sm text-gray-600">
                <span id="countdownStatus">
                    <i class="fas fa-sync-alt mr-1 text-purple-500"></i>Live monitoring active
                </span>
                <button onclick="refreshCountdowns()" class="ml-2 bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded text-xs transition duration-200">
                    <i class="fas fa-sync-alt mr-1"></i>Refresh
                </button>
            </div>
        </div>
        
        <!-- Countdown Statistics Dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="countdown-stat-card bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-blue-600" id="totalCountdowns">0</div>
                <div class="text-sm text-blue-800">Total Timers</div>
            </div>
            <div class="countdown-stat-card bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-green-600" id="onTimeCountdowns">0</div>
                <div class="text-sm text-green-800">On Time</div>
            </div>
            <div class="countdown-stat-card bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600" id="warningCountdowns">0</div>
                <div class="text-sm text-yellow-800">Warning</div>
            </div>
            <div class="countdown-stat-card bg-gradient-to-r from-red-50 to-pink-50 border border-red-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-red-600" id="urgentCountdowns">0</div>
                <div class="text-sm text-red-800">Urgent</div>
            </div>
        </div>
        
        <div id="countdownGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Countdown cards will be dynamically populated here -->
        </div>
        
        <div class="mt-4 text-center">
            <div class="inline-flex items-center px-4 py-2 bg-purple-100 text-purple-800 rounded-lg text-sm">
                <i class="fas fa-info-circle mr-2"></i>
                <span>Countdown timers automatically start usage when time arrives and complete when time expires</span>
            </div>
        </div>
    </div>
    
    <script>
        // Timer management system
        class UsageTimer {
            constructor() {
                this.timers = new Map();
                this.init();
            }
            init() {
                // Initialize timers for all current usage items
                const currentUsageElements = document.querySelectorAll('[data-usage-started]');
                currentUsageElements.forEach(element => {
                    const reservationId = element.dataset.reservationId;
                    const startedAtString = element.dataset.usageStarted;
                    
                    // Debug logging
                    console.log('Timer init:', { reservationId, startedAtString, element });
                    
                    // Only initialize timer if started_at is not null/empty and not 'null' string
                    if (startedAtString && startedAtString !== 'null' && startedAtString !== '' && startedAtString !== 'true') {
                        const startedAt = new Date(startedAtString);
                        if (!isNaN(startedAt.getTime()) && startedAt.getTime() > 0) {
                            this.startTimer(reservationId, startedAt, element);
                        } else {
                            console.warn('Invalid start time for reservation:', reservationId, startedAtString);
                        }
                    } else {
                        console.warn('Missing or invalid started_at for reservation:', reservationId, startedAtString);
                    }
                });
            }
            startTimer(reservationId, startedAt, element) {
                const updateTimer = () => {
                    const now = new Date();
                    const elapsed = now - startedAt;
                    const hours = Math.floor(elapsed / (1000 * 60 * 60));
                    const minutes = Math.floor((elapsed % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((elapsed % (1000 * 60)) / 1000);
                    const timeString = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                    
                    if (element) {
                        // Update the main element text content
                        element.textContent = timeString;
                        element.classList.add('timer-active');
                        
                        // Also update any timer-text spans within this element
                        const timerText = element.querySelector('.timer-text');
                        if (timerText) {
                            timerText.textContent = timeString;
                        }
                        
                        // Update the parent card if it exists
                        const parentCard = element.closest('.usage-card');
                        if (parentCard) {
                            parentCard.classList.add('timer-active');
                        }
                        
                        // Debug logging for timer updates
                        console.log('Timer update:', { reservationId, timeString, elapsed });
                    }
                };
                // Update immediately
                updateTimer();
                // Update every second
                const intervalId = setInterval(updateTimer, 1000);
                // Store timer info
                this.timers.set(reservationId, {
                    intervalId,
                    startedAt,
                    element
                });
            }
            stopTimer(reservationId) {
                const timer = this.timers.get(reservationId);
                if (timer) {
                    clearInterval(timer.intervalId);
                    this.timers.delete(reservationId);
                }
            }
            formatDuration(minutes) {
                const hours = Math.floor(minutes / 60);
                const mins = minutes % 60;
                return `${hours}h ${mins}m`;
            }
        }
                         // Initialize timer system
        const usageTimer = new UsageTimer();
        
        // Manual timer refresh function
        function refreshTimers() {
            console.log('Refreshing timers...');
            
            // Stop all existing timers
            usageTimer.timers.forEach((timer, reservationId) => {
                usageTimer.stopTimer(reservationId);
            });
            
            // Clear the timers map
            usageTimer.timers.clear();
            
            // Reinitialize all timers
            usageTimer.init();
            
            // Show success message
            const status = document.getElementById('autoRefreshStatus');
            if (status) {
                const originalText = status.innerHTML;
                status.innerHTML = '<i class="fas fa-check mr-1 text-green-500"></i>Timers refreshed!';
                setTimeout(() => {
                    status.innerHTML = originalText;
                }, 2000);
            }
        }
         // Enhanced Countdown management system
         class CountdownManager {
             constructor() {
                 this.countdowns = new Map();
                 this.init();
             }
             
             init() {
                 // Initialize countdowns for all elements
                 const countdownElements = document.querySelectorAll('.countdown-display');
                 countdownElements.forEach(element => {
                     if (element.dataset.endTime) {
                         // End time countdown
                         const endTime = new Date(element.dataset.endTime);
                         const type = element.dataset.type;
                         this.startCountdown(element, endTime, type);
                     } else if (element.dataset.startTime) {
                         // Start time countdown
                         const startTime = new Date(element.dataset.startTime);
                         this.startStartCountdown(element, startTime);
                     }
                 });
             }
             
             startCountdown(element, endTime, type) {
                 const updateCountdown = () => {
                     const now = new Date();
                     const timeLeft = endTime - now;
                     
                     if (timeLeft <= 0) {
                         // Time has expired
                         element.innerHTML = `
                             <div class="countdown-expired-content">
                                 <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>
                                 <span class="countdown-text text-red-600 font-bold">${type === 'start' ? 'Started' : 'Time Expired!'}</span>
                             </div>
                         `;
                         element.classList.remove('countdown-warning', 'countdown-urgent', 'countdown-normal');
                         element.classList.add('countdown-urgent', 'countdown-expired');
                         return;
                     }
                     
                     // Calculate detailed time breakdown
                     const totalSeconds = Math.floor(timeLeft / 1000);
                     const days = Math.floor(totalSeconds / 86400);
                     const hours = Math.floor((totalSeconds % 86400) / 3600);
                     const minutes = Math.floor((totalSeconds % 3600) / 60);
                     const seconds = totalSeconds % 60;
                     
                     // Create detailed time string
                     let timeString = '';
                     let timeStringShort = '';
                     
                     if (days > 0) {
                         timeString = `${days}d ${hours}h ${minutes}m ${seconds}s`;
                         timeStringShort = `${days}d ${hours}h ${minutes}m`;
                     } else if (hours > 0) {
                         timeString = `${hours}h ${minutes}m ${seconds}s`;
                         timeStringShort = `${hours}h ${minutes}m`;
                     } else if (minutes > 0) {
                         timeString = `${minutes}m ${seconds}s`;
                         timeStringShort = `${minutes}m ${seconds}s`;
                     } else {
                         timeString = `${seconds}s`;
                         timeStringShort = `${seconds}s`;
                     }
                     
                     // Calculate progress percentage for progress bar
                     const totalDuration = type === 'start' ? 3600000 : 7200000; // 1 hour for start, 2 hours for end
                     const progressPercent = Math.max(0, Math.min(100, ((totalDuration - timeLeft) / totalDuration) * 100));
                     
                     // Update the display with enhanced layout
                     const countdownText = element.querySelector('.countdown-text');
                     const countdownTimer = element.querySelector('.countdown-timer');
                     const progressBar = element.querySelector('.countdown-progress');
                     
                     if (countdownText) {
                         countdownText.textContent = `${type === 'start' ? 'Starts in: ' : 'Ends in: '}`;
                     }
                     
                     if (countdownTimer) {
                         countdownTimer.innerHTML = `
                             <div class="countdown-main-time">${timeStringShort}</div>
                             <div class="countdown-secondary-time">${timeString}</div>
                         `;
                     }
                     
                     if (progressBar) {
                         progressBar.style.width = `${progressPercent}%`;
                         progressBar.className = `countdown-progress ${this.getProgressBarClass(progressPercent)}`;
                     }
                     
                     // Add warning classes based on time remaining
                     element.classList.remove('countdown-warning', 'countdown-urgent', 'countdown-normal');
                     
                     if (totalSeconds <= 300) { // 5 minutes or less
                         element.classList.add('countdown-urgent');
                     } else if (totalSeconds <= 900) { // 15 minutes or less
                         element.classList.add('countdown-warning');
                     } else {
                         element.classList.add('countdown-normal');
                     }
                     
                     // Add urgency indicators
                     this.updateUrgencyIndicator(element, totalSeconds);
                 };
                 
                 // Update immediately
                 updateCountdown();
                 // Update every second for precise countdown
                 const intervalId = setInterval(updateCountdown, 1000);
                 
                 // Store countdown info
                 this.countdowns.set(element, {
                     intervalId,
                     endTime,
                     type
                 });
             }
             
             startStartCountdown(element, startTime) {
                 const updateStartCountdown = () => {
                     const now = new Date();
                     const timeLeft = startTime - now;
                     
                     if (timeLeft <= 0) {
                         // Reservation has started
                         element.innerHTML = `
                             <div class="countdown-ready-content">
                                 <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                 <span class="countdown-text text-green-600 font-bold">Ready Now!</span>
                             </div>
                         `;
                         element.classList.remove('countdown-warning', 'countdown-urgent', 'countdown-normal');
                         element.classList.add('countdown-ready', 'countdown-expired');
                         
                         // Refresh the page to update the status
                         setTimeout(() => {
                             location.reload();
                         }, 2000);
                         return;
                     }
                     
                     // Calculate detailed time breakdown
                     const totalSeconds = Math.floor(timeLeft / 1000);
                     const days = Math.floor(totalSeconds / 86400);
                     const hours = Math.floor((totalSeconds % 86400) / 3600);
                     const minutes = Math.floor((totalSeconds % 3600) / 60);
                     const seconds = totalSeconds % 60;
                     
                     // Create detailed time string
                     let timeString = '';
                     let timeStringShort = '';
                     
                     if (days > 0) {
                         timeString = `${days}d ${hours}h ${minutes}m ${seconds}s`;
                         timeStringShort = `${days}d ${hours}h ${minutes}m`;
                     } else if (hours > 0) {
                         timeString = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                         timeStringShort = `${hours}h ${minutes}m ${seconds}s`;
                     } else {
                         timeString = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                         timeStringShort = `${minutes}m ${seconds}s`;
                     }
                     
                     // Calculate progress percentage
                     const totalDuration = 3600000; // 1 hour
                     const progressPercent = Math.max(0, Math.min(100, ((totalDuration - timeLeft) / totalDuration) * 100));
                     
                     // Update the display
                     const countdownTimer = element.querySelector('.countdown-timer');
                     const progressBar = element.querySelector('.countdown-progress');
                     
                     if (countdownTimer) {
                         countdownTimer.innerHTML = `
                             <div class="countdown-main-time">${timeStringShort}</div>
                             <div class="countdown-secondary-time">${timeString}</div>
                         `;
                     }
                     
                     if (progressBar) {
                         progressBar.style.width = `${progressPercent}%`;
                         progressBar.className = `countdown-progress ${this.getProgressBarClass(progressPercent)}`;
                     }
                     
                     // Add warning classes based on time remaining
                     element.classList.remove('countdown-warning', 'countdown-urgent', 'countdown-normal');
                     
                     if (totalSeconds <= 300) { // 5 minutes or less
                         element.classList.add('countdown-urgent');
                     } else if (totalSeconds <= 900) { // 15 minutes or less
                         element.classList.add('countdown-warning');
                     } else {
                         element.classList.add('countdown-normal');
                     }
                     
                     // Add urgency indicators
                     this.updateUrgencyIndicator(element, totalSeconds);
                 };
                 
                 // Update immediately
                 updateStartCountdown();
                 // Update every second for precise countdown
                 const intervalId = setInterval(updateStartCountdown, 1000);
                 
                 // Store countdown info
                 this.countdowns.set(element, {
                     intervalId,
                     startTime,
                     type: 'start'
                 });
             }
             
             getProgressBarClass(percent) {
                 if (percent >= 80) return 'progress-urgent';
                 if (percent >= 60) return 'progress-warning';
                 if (percent >= 40) return 'progress-normal';
                 return 'progress-early';
             }
             
             updateUrgencyIndicator(element, totalSeconds) {
                 const urgencyIndicator = element.querySelector('.urgency-indicator');
                 if (urgencyIndicator) {
                     if (totalSeconds <= 300) { // 5 minutes
                         urgencyIndicator.innerHTML = '<i class="fas fa-exclamation-triangle text-red-500"></i> URGENT';
                         urgencyIndicator.className = 'urgency-indicator urgent';
                     } else if (totalSeconds <= 900) { // 15 minutes
                         urgencyIndicator.innerHTML = '<i class="fas fa-exclamation-circle text-orange-500"></i> WARNING';
                         urgencyIndicator.className = 'urgency-indicator warning';
                     } else if (totalSeconds <= 1800) { // 30 minutes
                         urgencyIndicator.innerHTML = '<i class="fas fa-info-circle text-blue-500"></i> APPROACHING';
                         urgencyIndicator.className = 'urgency-indicator approaching';
                     } else {
                         urgencyIndicator.innerHTML = '<i class="fas fa-clock text-green-500"></i> ON TIME';
                         urgencyIndicator.className = 'urgency-indicator on-time';
                     }
                 }
             }
             
             stopCountdown(element) {
                 const countdown = this.countdowns.get(element);
                 if (countdown) {
                     clearInterval(countdown.intervalId);
                     this.countdowns.delete(element);
                 }
             }
             
             // Get countdown statistics
             getCountdownStats() {
                 const stats = {
                     total: this.countdowns.size,
                     urgent: 0,
                     warning: 0,
                     normal: 0,
                     ready: 0
                 };
                 
                 this.countdowns.forEach((countdown, element) => {
                     if (element.classList.contains('countdown-urgent')) stats.urgent++;
                     else if (element.classList.contains('countdown-warning')) stats.warning++;
                     else if (element.classList.contains('countdown-ready')) stats.ready++;
                     else stats.normal++;
                 });
                 
                 return stats;
             }
             
             // Get detailed countdown information for a specific element
             getDetailedCountdown(element) {
                 const countdown = this.countdowns.get(element);
                 if (!countdown) return null;
                 
                 const now = new Date();
                 let timeLeft = 0;
                 
                 if (countdown.type === 'start') {
                     timeLeft = countdown.startTime - now;
                 } else {
                     timeLeft = countdown.endTime - now;
                 }
                 
                 if (timeLeft <= 0) return { expired: true, message: 'Time has expired' };
                 
                 const totalSeconds = Math.floor(timeLeft / 1000);
                 const days = Math.floor(totalSeconds / 86400);
                 const hours = Math.floor((totalSeconds % 86400) / 3600);
                 const minutes = Math.floor((totalSeconds % 3600) / 60);
                 const seconds = totalSeconds % 60;
                 
                 return {
                     expired: false,
                     days,
                     hours,
                     minutes,
                     seconds,
                     totalSeconds,
                     formatted: this.formatDetailedTime(days, hours, minutes, seconds),
                     short: this.formatShortTime(days, hours, minutes, seconds)
                 };
             }
             
             // Format detailed time display
             formatDetailedTime(days, hours, minutes, seconds) {
                 let result = '';
                 if (days > 0) result += `${days}d `;
                 if (hours > 0 || days > 0) result += `${hours}h `;
                 if (minutes > 0 || hours > 0 || days > 0) result += `${minutes}m `;
                 result += `${seconds}s`;
                 return result.trim();
             }
             
             // Format short time display
             formatShortTime(days, hours, minutes, seconds) {
                 if (days > 0) return `${days}d ${hours}h ${minutes}m`;
                 if (hours > 0) return `${hours}h ${minutes}m`;
                 if (minutes > 0) return `${minutes}m ${seconds}s`;
                 return `${seconds}s`;
             }
             
             // Update countdown statistics display
             updateStatisticsDisplay() {
                 const stats = this.getCountdownStats();
                 
                 // Update the statistics dashboard
                 const totalElement = document.getElementById('totalCountdowns');
                 const onTimeElement = document.getElementById('onTimeCountdowns');
                 const warningElement = document.getElementById('warningCountdowns');
                 const urgentElement = document.getElementById('urgentCountdowns');
                 
                 if (totalElement) totalElement.textContent = stats.total;
                 if (onTimeElement) onTimeElement.textContent = stats.normal;
                 if (warningElement) onTimeElement.textContent = stats.warning;
                 if (urgentElement) urgentElement.textContent = stats.urgent;
                 
                 // Add visual feedback for statistics
                 this.animateStatisticsUpdate();
             }
             
             // Animate statistics update
             animateStatisticsUpdate() {
                 const statCards = document.querySelectorAll('.countdown-stat-card');
                 statCards.forEach(card => {
                     card.style.transform = 'scale(1.05)';
                     card.style.transition = 'transform 0.3s ease';
                     setTimeout(() => {
                         card.style.transform = 'scale(1)';
                     }, 300);
                 });
             }
         }
         
         // Initialize countdown system
         const countdownManager = new CountdownManager();
         // Initialize countdowns
         countdownManager.init();
         
         // Update statistics display initially
         setTimeout(() => {
             countdownManager.updateStatisticsDisplay();
         }, 1000);
         
         // Update statistics every 10 seconds
         setInterval(() => {
             countdownManager.updateStatisticsDisplay();
         }, 10000);
         
                 // Initialize timers after a short delay to ensure DOM is ready
        setTimeout(() => {
            console.log('Initializing timers after DOM ready...');
            refreshTimers();
        }, 500);
        
        // Also initialize when the page becomes visible (in case of tab switching)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                console.log('Page became visible, checking timers...');
                setTimeout(() => {
                    refreshTimers();
                }, 100);
            }
        });
        
        // Check for broken timers every 30 seconds and fix them
        setInterval(() => {
            const timerElements = document.querySelectorAll('[data-usage-started]');
            let brokenTimers = 0;
            
            timerElements.forEach(element => {
                const startedAtString = element.dataset.usageStarted;
                if (startedAtString && startedAtString !== 'null' && startedAtString !== '' && startedAtString !== 'true') {
                    const startedAt = new Date(startedAtString);
                    if (!isNaN(startedAt.getTime()) && startedAt.getTime() > 0) {
                        // Check if timer is actually running
                        const reservationId = element.dataset.reservationId;
                        if (!usageTimer.timers.has(reservationId)) {
                            brokenTimers++;
                            console.warn('Found broken timer for reservation:', reservationId);
                        }
                    }
                }
            });
            
            if (brokenTimers > 0) {
                console.log(`Found ${brokenTimers} broken timers, fixing...`);
                refreshTimers();
            }
        }, 30000);
         
         // Auto-remove expired facilities
         setInterval(() => {
             const expiredElements = document.querySelectorAll('.countdown-expired');
             expiredElements.forEach(element => {
                 const card = element.closest('.usage-card');
                 if (card) {
                     // Add fade-out animation
                     card.style.transition = 'opacity 0.5s ease-out';
                     card.style.opacity = '0';
                     // Remove the card after animation
                     setTimeout(() => {
                         card.remove();
                         // Reload page to update the list
                         setTimeout(() => {
                             location.reload();
                         }, 100);
                     }, 500);
                 }
             });
         }, 1000);
        function startUsage(reservationId) {
            // Show confirmation modal instead of confirm dialog
            showStartUsageModal(reservationId);
        }
        
        function confirmStartUsage(reservationId) {
            // Show loading state
            const submitBtn = document.querySelector('#startUsageModal button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Starting...';
            submitBtn.disabled = true;
            
            // Create and submit form directly
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'start_usage';
            form.appendChild(actionInput);
            const reservationInput = document.createElement('input');
            reservationInput.type = 'hidden';
            reservationInput.name = 'reservation_id';
            reservationInput.value = reservationId;
            form.appendChild(reservationInput);
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'notes';
            notesInput.value = 'Usage started by admin';
            form.appendChild(notesInput);
            document.body.appendChild(form);
            form.submit();
        }
        function transformButtonToComplete(reservationId) {
            const button = document.getElementById(`usage-btn-${reservationId}`);
            if (!button) {
                console.error('Button not found for reservation:', reservationId);
                return;
            }
            const buttonText = document.getElementById(`usage-btn-text-${reservationId}`);
            const icon = button.querySelector('i');
            if (buttonText && icon) {
                // Change button appearance
                button.className = button.className.replace('from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600', 'from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600');
                button.onclick = () => completeUsage(reservationId);
                // Change icon and text
                icon.className = 'fas fa-stop mr-2';
                buttonText.textContent = 'Complete Usage';
                // Update card status
                const card = button.closest('.usage-card');
                if (card) {
                    card.classList.remove('border-blue-200');
                    card.classList.add('border-green-200');
                    // Update header background
                    const header = card.querySelector('.h-40');
                    if (header) {
                        header.className = header.className.replace('from-blue-400 via-blue-500 to-indigo-500', 'from-green-400 via-green-500 to-blue-500');
                    }
                    // Update status text
                    const statusText = card.querySelector('.text-white.text-sm.opacity-90');
                    if (statusText) {
                        statusText.textContent = 'Active Session';
                    }
                    // Update main icon
                    const mainIcon = card.querySelector('.h-20.w-20 i');
                    if (mainIcon) {
                        mainIcon.className = 'fas fa-play text-white text-3xl';
                    }
                } else {
                    console.warn('Card not found for reservation:', reservationId);
                }
            } else {
                console.error('Button text or icon not found for reservation:', reservationId);
            }
        }
        function completeUsage(reservationId) {
            // Show confirmation modal instead of confirm dialog
            showCompleteUsageModal(reservationId);
        }
        
        function confirmCompleteUsage(reservationId) {
            // Show loading state
            const submitBtn = document.querySelector('#completeUsageModal button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Completing...';
            submitBtn.disabled = true;
            
            // Create and submit form directly
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'complete_usage';
            form.appendChild(actionInput);
            const reservationInput = document.createElement('input');
            reservationInput.type = 'hidden';
            reservationInput.name = 'reservation_id';
            reservationInput.value = reservationId;
            form.appendChild(reservationInput);
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'notes';
            notesInput.value = 'Usage completed by admin';
            form.appendChild(notesInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        // Modal functions for beautiful confirmations
        function showStartUsageModal(reservationId) {
            const modal = document.getElementById('startUsageModal');
            document.getElementById('startUsageReservationId').value = reservationId;
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        
        function closeStartUsageModal() {
            const modal = document.getElementById('startUsageModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        
        function showCompleteUsageModal(reservationId) {
            const modal = document.getElementById('completeUsageModal');
            document.getElementById('completeUsageReservationId').value = reservationId;
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        
        function closeCompleteUsageModal() {
            const modal = document.getElementById('completeUsageModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        
        // Late User Management Functions
        function showLateUserOptions(reservationId) {
            const modal = document.getElementById('lateUserModal');
            document.getElementById('lateUserReservationId').value = reservationId;
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        
        function closeLateUserModal() {
            const modal = document.getElementById('lateUserModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        
        // Show/hide conditional options based on selected action
        document.addEventListener('DOMContentLoaded', function() {
            const actionRadios = document.querySelectorAll('input[name="late_action"]');
            const extendOptions = document.getElementById('extendTimeOptions');
            const reduceOptions = document.getElementById('reduceDurationOptions');
            
            actionRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    // Hide all options first
                    extendOptions.classList.add('hidden');
                    reduceOptions.classList.add('hidden');
                    
                    // Show relevant options based on selection
                    if (this.value === 'extend_time') {
                        extendOptions.classList.remove('hidden');
                    } else if (this.value === 'reduce_duration') {
                        reduceOptions.classList.remove('hidden');
                    }
                });
            });
        });
        
        function closeUsageModal() {
            const modal = document.getElementById('usageModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        function verifyUsage(reservationId) {
            const modal = document.getElementById('usageModal');
            document.getElementById('modalTitle').textContent = 'Verify Facility Usage';
            document.getElementById('action').value = 'verify_usage';
            document.getElementById('reservation_id').value = reservationId;
            document.getElementById('notes').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-check mr-2"></i>Verify Usage';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.pointerEvents = 'none';
            }
        }
                 // Disable auto-refresh completely to prevent excessive refreshing
         let autoRefreshEnabled = false;
         function toggleAutoRefresh() {
             const button = document.getElementById('toggleAutoRefresh');
             const status = document.getElementById('autoRefreshStatus');
             if (autoRefreshEnabled) {
                 // Disable auto-refresh
                 autoRefreshEnabled = false;
                 button.innerHTML = '<i class="fas fa-play mr-1"></i>Enable Auto-refresh';
                 button.className = 'ml-2 bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs transition duration-200';
                 status.innerHTML = '<i class="fas fa-pause mr-1 text-orange-500"></i>Auto-refresh disabled';
             } else {
                 // Enable auto-refresh
                 autoRefreshEnabled = true;
                 button.innerHTML = '<i class="fas fa-pause mr-1"></i>Disable Auto-refresh';
                 button.className = 'ml-2 bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-xs transition duration-200';
                 status.innerHTML = '<i class="fas fa-sync-alt mr-1"></i>Auto-refresh enabled (manual only)';
             }
         }
         // Live countdown timer system (no auto-refresh)
         function updateCountdowns() {
             const countdownElements = document.querySelectorAll('.countdown-display');
             countdownElements.forEach(element => {
                 const startTime = element.dataset.startTime;
                 const endTime = element.dataset.endTime;
                 const countdownTimer = element.querySelector('.countdown-timer');
                 if (startTime && countdownTimer) {
                     const start = new Date(startTime);
                     const now = new Date();
                     const timeLeft = start.getTime() - now.getTime();
                     if (timeLeft <= 0) {
                         // Reservation has started
                         countdownTimer.innerHTML = '<span class="text-green-600 font-bold">Ready to Start!</span>';
                         element.classList.remove('countdown-warning', 'countdown-urgent');
                         element.classList.add('countdown-ready');
                     } else {
                         // Still counting down to start
                         const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                         const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                         const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                         let timeString;
                         if (hours > 0) {
                             timeString = `${hours}h ${minutes}m ${seconds}s`;
                         } else if (minutes > 0) {
                             timeString = `${minutes}m ${seconds}s`;
                         } else {
                             timeString = `${seconds}s`;
                         }
                         countdownTimer.textContent = timeString;
                         // Add warning classes based on time remaining
                         element.classList.remove('countdown-warning', 'countdown-urgent', 'countdown-ready');
                         if (timeLeft <= 300000) { // 5 minutes or less
                             element.classList.add('countdown-urgent');
                         } else if (timeLeft <= 900000) { // 15 minutes or less
                             element.classList.add('countdown-warning');
                         }
                     }
                 }
                 if (endTime && countdownTimer) {
                     const end = new Date(endTime);
                     const now = new Date();
                     const timeLeft = end.getTime() - now.getTime();
                     if (timeLeft <= 0) {
                         // Reservation has ended
                         countdownTimer.innerHTML = '<span class="text-red-600 font-bold">Time Expired!</span>';
                         element.classList.remove('countdown-warning', 'countdown-urgent');
                         element.classList.add('countdown-urgent');
                     } else {
                         // Still counting down to end
                         const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                         const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                         const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                         let timeString;
                         if (hours > 0) {
                             timeString = `${hours}h ${minutes}m ${seconds}s`;
                         } else if (minutes > 0) {
                             timeString = `${minutes}m ${seconds}s`;
                         } else {
                             timeString = `${seconds}s`;
                         }
                         countdownTimer.textContent = timeString;
                         // Add warning classes based on time remaining
                         element.classList.remove('countdown-warning', 'countdown-urgent');
                         if (timeLeft <= 300000) { // 5 minutes or less
                             element.classList.add('countdown-urgent');
                         } else if (timeLeft <= 900000) { // 15 minutes or less
                             element.classList.add('countdown-warning');
                         }
                     }
                 }
             });
         }
         // Update countdowns every second
         setInterval(updateCountdowns, 1000);
         updateCountdowns(); // Initial update
         
         // Enhanced Automatic Countdown Timer System
         class AutomaticCountdownSystem {
             constructor() {
                 this.countdowns = new Map();
                 this.isActive = true;
                 this.init();
             }
             
             init() {
                 this.loadCountdownData();
                 this.startAutoRefresh();
                 this.setupEventListeners();
             }
             
             loadCountdownData() {
                 // Fallback data from PHP variables
                 const data = [];
                 
                 // Add ready usage countdowns
                 <?php if (!empty($readyUsage)): ?>
                 <?php foreach ($readyUsage as $usage): ?>
                 data.push({
                     id: <?php echo $usage['reservation_id']; ?>,
                     type: 'start',
                     facility: '<?php echo addslashes($usage['facility_name']); ?>',
                     user: '<?php echo addslashes($usage['user_name']); ?>',
                     startTime: '<?php echo $usage['start_time']; ?>',
                     endTime: '<?php echo $usage['end_time']; ?>',
                     status: 'ready'
                 });
                 <?php endforeach; ?>
                 <?php endif; ?>
                 
                 // Add active usage countdowns
                 <?php if (!empty($currentUsage)): ?>
                 <?php foreach ($currentUsage as $usage): ?>
                 data.push({
                     id: <?php echo $usage['reservation_id']; ?>,
                     type: 'end',
                     facility: '<?php echo addslashes($usage['facility_name']); ?>',
                     user: '<?php echo addslashes($usage['user_name']); ?>',
                     startTime: '<?php echo $usage['started_at']; ?>',
                     endTime: '<?php echo $usage['end_time']; ?>',
                     status: 'active'
                 });
                 <?php endforeach; ?>
                 <?php endif; ?>
                 
                 this.renderCountdownGrid(data);
             }
             
             renderCountdownGrid(data) {
                 const grid = document.getElementById('countdownGrid');
                 if (!grid) return;
                 
                 grid.innerHTML = '';
                 
                 if (data.length === 0) {
                     grid.innerHTML = `
                         <div class="col-span-full text-center py-8">
                             <div class="inline-flex items-center justify-center w-16 h-16 bg-purple-100 rounded-full mb-4">
                                 <i class="fas fa-clock text-purple-500 text-2xl"></i>
                             </div>
                             <h3 class="text-lg font-semibold text-gray-800 mb-2">No Active Countdowns</h3>
                             <p class="text-gray-600">All reservations are either completed or not yet ready to start.</p>
                         </div>
                     `;
                     return;
                 }
                 
                 data.forEach(item => {
                     const card = this.createCountdownCard(item);
                     grid.appendChild(card);
                     this.startCountdown(item);
                 });
             }
             
             createCountdownCard(item) {
                 const card = document.createElement('div');
                 card.className = 'bg-white rounded-lg p-4 border border-purple-200 shadow-sm countdown-card transform transition duration-300 hover:scale-105';
                 card.dataset.reservationId = item.id;
                 
                 const isStartCountdown = item.type === 'start';
                 const statusColor = isStartCountdown ? 'blue' : 'green';
                 const statusText = isStartCountdown ? 'Ready to Start' : 'Active Usage';
                 const icon = isStartCountdown ? 'clock' : 'play';
                 
                 card.innerHTML = `
                     <div class="flex items-center justify-between mb-3">
                         <h3 class="font-semibold text-gray-800 truncate">${item.facility}</h3>
                         <span class="text-xs text-gray-500 ml-2">${item.user}</span>
                     </div>
                     <div class="text-center mb-3">
                         <div class="text-xl font-mono font-bold text-${statusColor}-600 countdown-timer-new" 
                              data-type="${item.type}" 
                              data-start-time="${item.startTime}" 
                              data-end-time="${item.endTime}">
                             Loading...
                         </div>
                         <div class="text-xs text-gray-500 mt-1">
                             ${isStartCountdown ? 'Starts in:' : 'Ends in:'}
                         </div>
                     </div>
                     <div class="flex items-center justify-center">
                         <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-${statusColor}-100 text-${statusColor}-800">
                             <i class="fas fa-${icon} mr-2"></i>${statusText}
                         </span>
                     </div>
                 `;
                 
                 return card;
             }
             
             startCountdown(item) {
                 const timerElement = document.querySelector(`[data-reservation-id="${item.id}"] .countdown-timer-new`);
                 if (!timerElement) return;
                 
                 const updateCountdown = () => {
                     const now = new Date();
                     let targetTime, timeLeft, isExpired = false;
                     
                     if (item.type === 'start') {
                         targetTime = new Date(item.startTime);
                         timeLeft = targetTime - now;
                         
                         if (timeLeft <= 0) {
                             // Time to start usage
                             this.autoStartUsage(item.id);
                             isExpired = true;
                         }
                     } else {
                         targetTime = new Date(item.endTime);
                         timeLeft = targetTime - now;
                         
                         if (timeLeft <= 0) {
                             // Time to complete usage
                             this.autoCompleteUsage(item.id);
                             isExpired = true;
                         }
                     }
                     
                     if (isExpired) {
                         timerElement.innerHTML = '<span class="text-red-600 font-bold">Time Expired!</span>';
                         timerElement.classList.add('countdown-expired');
                         // Auto-refresh after a short delay
                         setTimeout(() => location.reload(), 3000);
                         return;
                     }
                     
                     // Format time remaining
                     const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                     const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                     const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                     
                     let timeString;
                     if (hours > 0) {
                         timeString = `${hours}h ${minutes}m ${seconds}s`;
                     } else if (minutes > 0) {
                         timeString = `${minutes}m ${seconds}s`;
                     } else {
                         timeString = `${seconds}s`;
                     }
                     
                     timerElement.textContent = timeString;
                     
                     // Add warning classes
                     timerElement.classList.remove('countdown-warning', 'countdown-urgent');
                     if (timeLeft <= 300000) { // 5 minutes or less
                         timerElement.classList.add('countdown-urgent', 'text-red-600');
                     } else if (timeLeft <= 900000) { // 15 minutes or less
                         timerElement.classList.add('countdown-warning', 'text-orange-600');
                     }
                 };
                 
                 // Update immediately
                 updateCountdown();
                 
                 // Update every second
                 const intervalId = setInterval(updateCountdown, 1000);
                 
                 // Store countdown info
                 this.countdowns.set(item.id, {
                     intervalId,
                     item,
                     timerElement
                 });
             }
             
             async autoStartUsage(reservationId) {
                 try {
                     console.log(`Auto-starting usage for reservation ${reservationId}`);
                     // Create form and submit to start usage
                     const form = document.createElement('form');
                     form.method = 'POST';
                     form.style.display = 'none';
                     
                     const actionInput = document.createElement('input');
                     actionInput.type = 'hidden';
                     actionInput.name = 'action';
                     actionInput.value = 'start_usage';
                     form.appendChild(actionInput);
                     
                     const reservationInput = document.createElement('input');
                     reservationInput.type = 'hidden';
                     reservationInput.name = 'reservation_id';
                     reservationInput.value = reservationId;
                     form.appendChild(reservationInput);
                     
                     const notesInput = document.createElement('input');
                     notesInput.type = 'hidden';
                     notesInput.name = 'notes';
                     notesInput.value = 'Automatically started when reservation time arrived';
                     form.appendChild(notesInput);
                     
                     document.body.appendChild(form);
                     form.submit();
                 } catch (error) {
                     console.error('Error auto-starting usage:', error);
                 }
             }
             
             async autoCompleteUsage(reservationId) {
                 try {
                     console.log(`Auto-completing usage for reservation ${reservationId}`);
                     // Create form and submit to complete usage
                     const form = document.createElement('form');
                     form.method = 'POST';
                     form.style.display = 'none';
                     
                     const actionInput = document.createElement('input');
                     actionInput.type = 'hidden';
                     actionInput.name = 'action';
                     actionInput.value = 'complete_usage';
                     form.appendChild(actionInput);
                     
                     const reservationInput = document.createElement('input');
                     reservationInput.type = 'hidden';
                     reservationInput.name = 'reservation_id';
                     reservationInput.value = reservationId;
                     form.appendChild(reservationInput);
                     
                     const notesInput = document.createElement('input');
                     notesInput.type = 'hidden';
                     notesInput.name = 'notes';
                     notesInput.value = 'Automatically completed when reservation time expired';
                     form.appendChild(notesInput);
                     
                     document.body.appendChild(form);
                     form.submit();
                 } catch (error) {
                     console.error('Error auto-completing usage:', error);
                 }
             }
             
             startAutoRefresh() {
                 // Refresh countdown data every 60 seconds
                 setInterval(() => {
                     if (this.isActive) {
                         this.loadCountdownData();
                     }
                 }, 60000);
             }
             
             setupEventListeners() {
                 // Pause countdowns when page is not visible
                 document.addEventListener('visibilitychange', () => {
                     this.isActive = !document.hidden;
                 });
             }
         }
         
         // Initialize automatic countdown system
         const automaticCountdownSystem = new AutomaticCountdownSystem();
         
         // Enhanced function to refresh countdowns
         function refreshCountdowns() {
             // Refresh the automatic countdown system
             if (automaticCountdownSystem) {
                 automaticCountdownSystem.loadCountdownData();
             }
             
             // Update countdown statistics
             if (countdownManager) {
                 countdownManager.updateStatisticsDisplay();
             }
             
             // Update the status display
             const status = document.getElementById('countdownStatus');
             if (status) {
                 const originalText = status.innerHTML;
                 status.innerHTML = '<i class="fas fa-check mr-1 text-green-500"></i>Countdowns refreshed!';
                 
                 // Add visual feedback
                 status.style.transform = 'scale(1.1)';
                 status.style.transition = 'transform 0.3s ease';
                 
                 setTimeout(() => {
                     status.style.transform = 'scale(1)';
                     status.innerHTML = originalText;
                 }, 2000);
             }
             
             // Refresh all countdown displays
             const countdownElements = document.querySelectorAll('.countdown-display');
             countdownElements.forEach(element => {
                 element.style.transform = 'scale(1.02)';
                 element.style.transition = 'transform 0.2s ease';
                 setTimeout(() => {
                     element.style.transform = 'scale(1)';
                 }, 200);
             });
         }
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            mobileToggle.classList.toggle("active");
            navLinksContainer.classList.toggle("show");
        }
        // Combined mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Mobile menu functionality
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener("click", function() {
                    const mobileToggle = document.querySelector(".mobile-menu-toggle");
                    const navContainer = document.querySelector(".nav-links-container");
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener("click", function(e) {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navContainer = document.querySelector(".nav-links-container");
                if (!mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                }
            });
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
            
            // Close modals on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeStartUsageModal();
                    closeCompleteUsageModal();
                    closeUsageModal();
                    closeSuccessModal();
                    closeLateUserModal();
                }
            });
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtonGroups = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtonGroups.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
        // Enhanced navbar scroll effects
        window.addEventListener("scroll", function() {
            const navbar = document.querySelector(".nav-container");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });
        // Enhanced navbar scroll effects
        window.addEventListener("scroll", function() {
            const navbar = document.querySelector(".nav-container");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });
        // Enhanced navbar scroll effects
        window.addEventListener("scroll", function() {
            const navbar = document.querySelector(".nav-container");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });
        // Proper navigation handling
        document.addEventListener("DOMContentLoaded", function() {
            // Handle navigation links properly
            const navLinks = document.querySelectorAll(".nav-links-container .nav-link");
            navLinks.forEach(link => {
                link.addEventListener("click", function(e) {
                    // Don't prevent default for logout link
                    if (this.getAttribute("href") === "../auth/logout.php") {
                        return;
                    }
                    // Close mobile menu if open
                    const mobileToggle = document.querySelector(".mobile-menu-toggle");
                    const navContainer = document.querySelector(".nav-links-container");
                    if (mobileToggle && navContainer) {
                        mobileToggle.classList.remove("active");
                        navContainer.classList.remove("show");
                    }
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener("click", function(e) {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navContainer = document.querySelector(".nav-links-container");
                if (mobileToggle && navContainer && !mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                }
            });
            // Highlight current page
            const currentPage = window.location.pathname.split("/").pop();
            navLinks.forEach(link => {
                const href = link.getAttribute("href");
                if (href && href.includes(currentPage) && currentPage !== "") {
                    link.classList.add("active");
                }
            });
        });
    </script>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</body>
</html>
