<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../classes/PaymentManager.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    if ($bookingType === 'daily') {
        // For daily bookings, calculate based on calendar days, not time difference
        $startDate = new DateTime($startTime);
        $endDate = new DateTime($endTime);
        // If start and end are on the same day, it's 1 day
        if ($startDate->format('Y-m-d') === $endDate->format('Y-m-d')) {
            return '1 day';
        } else {
            // Calculate the difference in days
            $days = $startDate->diff($endDate)->days;
            // Add 1 because we count both start and end days
            $days += 1;
        return $days . ' day' . ($days > 1 ? 's' : '');
        }
    } else {
        // For hourly bookings, calculate based on actual time difference
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
}
// Helper function to create usage log entry
function createUsageLogEntry($reservationId, $adminId, $notes = '') {
    global $pdo;
    try {
        // Get reservation details
        $stmt = $pdo->prepare("
            SELECT r.*, f.name as facility_name, u.full_name as user_name 
            FROM reservations r 
            JOIN facilities f ON r.facility_id = f.id 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();
        if (!$reservation) {
            return false;
        }
        // Check if usage log already exists for this reservation
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM usage_logs WHERE reservation_id = ?");
        $stmt->execute([$reservationId]);
        $existingLog = $stmt->fetch();
        if ($existingLog['count'] > 0) {
            // Update existing log to 'ready' status
            $stmt = $pdo->prepare("
                UPDATE usage_logs 
                SET status = 'ready', 
                    action = 'confirmed',
                    notes = ?,
                    updated_at = NOW()
                WHERE reservation_id = ?
            ");
            $stmt->execute([$notes, $reservationId]);
        } else {
            // Create new usage log entry
            $stmt = $pdo->prepare("
                INSERT INTO usage_logs (
                    reservation_id, 
                    facility_id, 
                    user_id, 
                    admin_id, 
                    action, 
                    status, 
                    notes, 
                    created_at, 
                    updated_at
                ) VALUES (?, ?, ?, ?, 'confirmed', 'ready', ?, NOW(), NOW())
            ");
            $stmt->execute([
                $reservationId, 
                $reservation['facility_id'], 
                $reservation['user_id'], 
                $adminId, 
                $notes
            ]);
        }
        return true;
    } catch (Exception $e) {
        error_log("Error creating usage log entry: " . $e->getMessage());
        return false;
    }
}
$pdo = getDBConnection();
$paymentManager = new PaymentManager();
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
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
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
// Handle status updates and payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $reservation_id = $_POST['reservation_id'] ?? null;
    if ($_POST['action'] === 'update_status') {
        $new_status = $_POST['status'] ?? null;
        if ($reservation_id && $new_status) {
                // Get current reservation details
                $stmt = $pdo->prepare("SELECT payment_status, payment_slip_url, start_time FROM reservations WHERE id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
            if ($new_status === 'confirmed') {
                // Approve reservation - check payment requirements first
                if ($reservation && $reservation['payment_status'] === 'pending') {
                    // Payment verification required - redirect to payment verification page
                    header('Location: payment_verification.php');
                    exit();
                } elseif ($reservation && $reservation['payment_status'] === 'paid') {
                    // Payment already verified - confirm reservation
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
                    if ($stmt->execute([$new_status, $reservation_id])) {
                        $success_message = "Reservation confirmed successfully! It will now appear in Usage Management for tracking.";
                        // Create usage log entry for confirmed reservation - ready for usage management
                        createUsageLogEntry($reservation_id, $_SESSION['user_id'], 'Reservation confirmed and ready for usage management');
                    } else {
                        $error_message = "Failed to update reservation status.";
                    }
                } else {
                    // No payment required or other status - confirm reservation
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
                    if ($stmt->execute([$new_status, $reservation_id])) {
                        $success_message = "Reservation confirmed successfully! It will now appear in Usage Management for tracking.";
                        // Create usage log entry for confirmed reservation - ready for usage management
                        createUsageLogEntry($reservation_id, $_SESSION['user_id'], 'Reservation confirmed and ready for usage management');
                    } else {
                        $error_message = "Failed to update reservation status.";
                    }
                }
            } elseif ($new_status === 'cancelled') {
                // Cancel reservation - automatically reject payment if slip is uploaded
                if ($reservation && $reservation['payment_status'] === 'pending' && $reservation['payment_slip_url']) {
                    // Payment slip uploaded - reject payment and cancel reservation
                    $notes = $_POST['notes'] ?? 'Payment rejected during reservation cancellation';
                    if ($paymentManager->verifyPayment($reservation_id, $_SESSION['user_id'], false, $notes)) {
                        // Update cancellation tracking
                        $stmt = $pdo->prepare("UPDATE reservations SET cancelled_by = ?, cancelled_at = NOW(), action_notes = ? WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id'], $notes, $reservation_id]);
                        $success_message = "Payment rejected and reservation cancelled successfully.";
                    } else {
                        $error_message = "Failed to reject payment during cancellation.";
                    }
                } else {
                    // No payment slip to reject - just cancel reservation
                    $notes = $_POST['notes'] ?? 'Reservation cancelled by admin';
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ?, cancelled_by = ?, cancelled_at = NOW(), action_notes = ? WHERE id = ?");
                    if ($stmt->execute([$new_status, $_SESSION['user_id'], $notes, $reservation_id])) {
                        $success_message = "Reservation cancelled successfully!";
                    } else {
                        $error_message = "Failed to cancel reservation.";
                    }
                }
            } elseif ($new_status === 'no_show') {
                // Handle no-show status
                $notes = $_POST['notes'] ?? 'Marked as no-show by admin';
                $stmt = $pdo->prepare("UPDATE reservations SET status = ?, no_show_reported_at = NOW(), noshow_by = ?, noshow_at = NOW(), action_notes = ? WHERE id = ?");
                if ($stmt->execute([$new_status, $_SESSION['user_id'], $notes, $reservation_id])) {
                    $success_message = "Reservation marked as no-show successfully!";
                } else {
                    $error_message = "Failed to mark reservation as no-show.";
                }
            } else {
                // Handle other status updates (like completed)
                $notes = $_POST['notes'] ?? 'Status updated by admin';
                if ($new_status === 'completed') {
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ?, completed_by = ?, completed_at = NOW(), action_notes = ? WHERE id = ?");
                    $stmt->execute([$new_status, $_SESSION['user_id'], $notes, $reservation_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ?, action_notes = ? WHERE id = ?");
                    $stmt->execute([$new_status, $notes, $reservation_id]);
                }
                
                if ($stmt->rowCount() > 0) {
                    $success_message = "Reservation status updated successfully!";
                    // If reservation is completed, automatically move to usage history
                    if ($new_status === 'completed') {
                        $success_message .= " Reservation has been moved to usage history.";
                    }
                } else {
                    $error_message = "Failed to update reservation status.";
                }
            }
        }
    }
    
    // Handle payment verification actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_payment') {
        $reservation_id = $_POST['reservation_id'] ?? null;
        $or_number = $_POST['or_number'] ?? '';
        $staff_name = $_POST['staff_name'] ?? '';
        
        if ($reservation_id && $or_number && $staff_name) {
            try {
                // Update reservation with payment verification
                $stmt = $pdo->prepare("
                    UPDATE reservations 
                    SET payment_status = 'paid', 
                        payment_verified_at = NOW(),
                        or_number = ?,
                        verified_by_staff_name = ?,
                        payment_verified_by = ?,
                        status = 'confirmed',
                        updated_at = NOW()
                    WHERE id = ? AND payment_status = 'pending'
                ");
                
                if ($stmt->execute([$or_number, $staff_name, $_SESSION['user_id'], $reservation_id])) {
                    // Create payment log entry
                    $stmt = $pdo->prepare("
                        INSERT INTO payment_logs (reservation_id, action, admin_id, notes, created_at)
                        VALUES (?, 'verified', ?, ?, NOW())
                    ");
                    $stmt->execute([$reservation_id, $_SESSION['user_id'], "Payment verified with OR: $or_number by $staff_name"]);
                    
                    // Create usage log entry for confirmed reservation
                    $stmt = $pdo->prepare("
                        INSERT INTO usage_logs (reservation_id, facility_id, user_id, action, status, notes, created_at) 
                        VALUES (?, (SELECT facility_id FROM reservations WHERE id = ?), (SELECT user_id FROM reservations WHERE id = ?), 'confirmed', 'ready', 'Reservation confirmed and ready for usage', NOW())
                    ");
                    $stmt->execute([$reservation_id, $reservation_id, $reservation_id]);
                    
                    $success_message = "Payment verified successfully!";
                } else {
                    $error_message = "Failed to verify payment.";
                }
            } catch (Exception $e) {
                $error_message = "Failed to verify payment: " . $e->getMessage();
            }
        } else {
            $error_message = "OR number and staff name are required.";
        }
    }
    
    // Handle payment rejection
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_payment') {
        $reservation_id = $_POST['reservation_id'] ?? null;
        $reason = $_POST['rejection_reason'] ?? 'No reason provided';
        
        if ($reservation_id) {
            try {
                // Update reservation status
                $stmt = $pdo->prepare("
                    UPDATE reservations 
                    SET status = 'cancelled', 
                        cancelled_by = ?,
                        cancelled_at = NOW(),
                        action_notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$_SESSION['user_id'], "Payment rejected: $reason", $reservation_id])) {
                    // Create payment log entry
                    $stmt = $pdo->prepare("
                        INSERT INTO payment_logs (reservation_id, action, admin_id, notes, created_at)
                        VALUES (?, 'rejected', ?, ?, NOW())
                    ");
                    $stmt->execute([$reservation_id, $_SESSION['user_id'], "Payment rejected: $reason"]);
                    
                    $success_message = "Payment rejected and reservation cancelled.";
                } else {
                    $error_message = "Failed to reject payment.";
                }
            } catch (Exception $e) {
                $error_message = "Failed to reject payment: " . $e->getMessage();
            }
        }
    }
    
    // Handle move to payment history action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_to_payment_history') {
        $reservation_id = $_POST['reservation_id'] ?? null;
        if ($reservation_id) {
            try {
                // Verify the reservation exists and is expired
                $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND status IN ('pending', 'confirmed', 'expired')");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    // Update reservation status to archived to move it to payment history
                    $notes = $_POST['notes'] ?? 'Moved to payment history by admin';
                    $stmt = $pdo->prepare("
                        UPDATE reservations 
                        SET status = 'archived',
                            archived_by = ?,
                            archived_at = NOW(),
                            action_notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$_SESSION['user_id'], $notes, $reservation_id]);
                    
                    $success_message = "Reservation moved to payment history successfully.";
                } else {
                    $error_message = "Reservation not found or already processed.";
                }
            } catch (Exception $e) {
                $error_message = "Failed to move reservation to payment history: " . $e->getMessage();
            }
        }
    }
}
// Auto-expire reservations that have passed their end time
try {
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET status = 'expired', updated_at = NOW() 
        WHERE status IN ('pending', 'confirmed') 
        AND end_time < NOW() 
        AND (payment_status = 'pending' OR payment_status = 'expired')
    ");
    $stmt->execute();
    $expired_count = $stmt->rowCount();
    if ($expired_count > 0) {
        $success_message = "Automatically expired {$expired_count} reservation(s) that passed their end time.";
    }
} catch (Exception $e) {
    error_log("Error auto-expiring reservations: " . $e->getMessage());
}

// Auto-expire payment status after 24 hours
try {
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET payment_status = 'expired', updated_at = NOW() 
        WHERE payment_status = 'pending' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND status IN ('pending', 'confirmed')
    ");
    $stmt->execute();
    $expired_payments_count = $stmt->rowCount();
    if ($expired_payments_count > 0) {
        $success_message = "Automatically expired {$expired_payments_count} payment(s) that exceeded 24 hours.";
    }
} catch (Exception $e) {
    error_log("Error auto-expiring payments: " . $e->getMessage());
}

// Get search parameters
$user_name_filter = $_GET['user_name'] ?? '';

// Build query
$query = "
    SELECT r.*, u.full_name as user_name, u.email as user_email, f.name as facility_name, f.hourly_rate,
           CASE 
               WHEN r.end_time < NOW() AND r.status IN ('pending', 'confirmed') THEN 'expired'
               ELSE r.status 
           END as effective_status
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE r.status NOT IN ('completed', 'cancelled', 'archived')  -- Exclude completed, cancelled, and archived reservations from main list
      AND (r.payment_status != 'paid' OR r.payment_verified_at IS NULL)  -- Exclude verified payments
      AND r.payment_status != 'expired'  -- Exclude expired payments (moved to payment history)
      -- Keep expired reservations visible in this list
";
$params = [];

// Add user name search filter
if ($user_name_filter) {
    $query .= " AND u.full_name LIKE ?";
    $params[] = '%' . $user_name_filter . '%';
}

$query .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Get recent payment verifications
$recentVerificationsQuery = "
    SELECT r.*, f.name as facility_name, u.full_name as user_name, 
           r.or_number, r.verified_by_staff_name, r.payment_verified_at,
           admin.full_name as verified_by_admin
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users admin ON r.payment_verified_by = admin.id
    WHERE r.payment_status = 'paid'
    ORDER BY r.payment_verified_at DESC
    LIMIT 10
";
$recentStmt = $pdo->prepare($recentVerificationsQuery);
$recentStmt->execute();
$recent_verifications = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation & Payment Management - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .category-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(197, 176, 205, 0.3);
        }
        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(23, 49, 62, 0.15);
            border-color: #415E72;
        }
        .modal {
            transition: all 0.3s ease;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(197, 176, 205, 0.4);
        }
        .modal.show .modal-content {
            transform: scale(1);
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
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: rgba(243, 226, 212, 0.98);
                border-top: 2px solid rgba(65, 94, 114, 0.2);
                box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(15px);
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
                padding: 18px 24px;
                border-radius: 0;
                border-bottom: 1px solid rgba(197, 176, 205, 0.3);
                margin: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.9);
            }
            .nav-links-container .nav-link:hover {
                background: rgba(197, 176, 205, 0.2);
                transform: translateX(5px);
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #415E72, #17313E);
                color: white;
                border-bottom: 2px solid #415E72;
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
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .category-card {
            pointer-events: auto !important;
        }
        .category-card * {
            pointer-events: auto !important;
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
        
        /* Enhanced reservation card animations */
        .reservation-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .reservation-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        
        /* Priority indicator animations */
        .priority-urgent {
            animation: pulse 1s infinite;
        }
        
        .priority-critical {
            animation: pulse 0.5s infinite;
        }
        
        /* Quick actions button hover */
        .quick-actions-btn:hover {
            transform: scale(1.1) rotate(5deg);
        }
        
        /* Enhanced filter animations */
        .filter-section select,
        .filter-section input {
            transition: all 0.3s ease;
        }
        
        .filter-section select:focus,
        .filter-section input:focus {
            transform: scale(1.02);
            box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1);
        }
        
        /* Statistics card hover effects */
        .stats-card {
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        /* Success message animations */
        .success-message {
            animation: slideInRight 0.5s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        @keyframes gradientShift {
            0%, 100% {
                background: linear-gradient(90deg, #10b981, #3b82f6, #f59e0b);
            }
            33% {
                background: linear-gradient(90deg, #3b82f6, #f59e0b, #10b981);
            }
            66% {
                background: linear-gradient(90deg, #f59e0b, #10b981, #3b82f6);
            }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.5s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
                        'bounce-in': 'bounceIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'float': 'float 6s ease-in-out infinite',
                        'gradient-shift': 'gradientShift 3s ease-in-out infinite',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container mx-auto px-4 py-8">
        
        <!-- User Search Section -->
        <div class="bg-white rounded-2xl p-6 shadow-xl border border-gray-100 mb-8">
            <div class="flex flex-col lg:flex-row items-center justify-between gap-6">
                <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg flex items-center justify-center">
                        <i class="fas fa-search text-white text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Search Reservations</h3>
                        <p class="text-gray-600 text-sm">Search by user name to find specific reservations</p>
                    </div>
                </div>
                
                <form method="GET" class="flex flex-col sm:flex-row gap-4 w-full lg:w-auto">
                    <!-- User Name Search -->
                    <div class="relative">
                        <input type="text" name="user_name" value="<?php echo htmlspecialchars($_GET['user_name'] ?? ''); ?>" 
                               class="bg-gray-50 border-2 border-gray-200 rounded-xl px-4 py-3 pl-10 pr-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 min-w-[250px]"
                               placeholder="Enter user name to search...">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                    </div>
                    
                    <!-- Search Actions -->
                    <div class="flex gap-2">
                        <button type="submit" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <button type="button" onclick="clearSearch()" class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-undo mr-2"></i>Clear
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Active Search Display -->
            <?php if (!empty($_GET['user_name'])): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fas fa-search text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700">Search Results for:</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            User: <?php echo htmlspecialchars($_GET['user_name']); ?>
                            <button onclick="clearSearch()" class="ml-2 text-blue-600 hover:text-blue-800">
                                <i class="fas fa-times"></i>
                            </button>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Success Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in success-message">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2 text-green-500"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-green-500 hover:text-green-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Error Messages -->
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-green-500 hover:text-green-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <!-- Enhanced Reservations Grid -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-3xl font-bold text-gray-900 flex items-center mb-2">
                        <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl shadow-lg flex items-center justify-center mr-4">
                            <i class="fas fa-list-ul text-white text-lg"></i>
                        </div>
                        Reservation & Payment Management
                        <span class="ml-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white px-4 py-1 rounded-full text-lg font-bold">
                            <?php echo count($reservations); ?>
                        </span>
                    </h3>
                    <p class="text-gray-600 text-lg flex items-center">
                        <i class="fas fa-info-circle mr-2 text-primary-500"></i>
                        Search for reservations by user name. Completed reservations and verified payments are automatically moved to the payment history page.
                    </p>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Last Updated</p>
                        <p class="text-sm font-semibold text-gray-700"><?php echo date('M j, Y g:i A'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php if (empty($reservations)): ?>
            <!-- Enhanced Empty State -->
            <div class="text-center py-20 animate-fade-in">
                <div class="w-32 h-32 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mx-auto mb-8 shadow-xl">
                    <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                </div>
                <h3 class="text-3xl font-bold text-gray-700 mb-4">No reservations found</h3>
                <p class="text-gray-500 text-lg mb-8 max-w-md mx-auto leading-relaxed">
                    <?php if (!empty($user_name_filter)): ?>
                        No reservations found for user "<?php echo htmlspecialchars($user_name_filter); ?>". Try searching with a different name or clear the search to see all reservations.
                    <?php else: ?>
                        No reservations found. Check back later for new reservations.
                    <?php endif; ?>
                </p>
                <div class="flex items-center justify-center space-x-4">
                    <?php if (!empty($user_name_filter)): ?>
                        <button onclick="clearSearch()" class="bg-gradient-to-r from-primary-500 to-primary-600 hover:from-primary-600 hover:to-primary-700 text-white px-8 py-4 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold text-lg shadow-lg">
                            <i class="fas fa-undo mr-2"></i>Clear Search
                        </button>
                    <?php endif; ?>
                    <button onclick="window.location.reload()" class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-8 py-4 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold text-lg shadow-lg">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh
                    </button>
                </div>
            </div>
        <?php else: ?>
            <!-- Enhanced Reservations Cards -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-8">
                <?php foreach ($reservations as $index => $reservation): ?>
                    <div class="reservation-card bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-200 hover:shadow-2xl transition-all duration-300 transform hover:scale-105 animate-slide-up group relative" 
                         data-reservation-id="<?php echo $reservation['id']; ?>" 
                         data-effective-status="<?php echo $reservation['effective_status']; ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        <!-- Priority Indicator -->
                        <?php 
                            $daysUntilStart = (strtotime($reservation['start_time']) - time()) / (24 * 60 * 60);
                            $isUrgent = $daysUntilStart <= 1 && $daysUntilStart > 0;
                            $isCritical = $daysUntilStart <= 0.5 && $daysUntilStart > 0;
                        ?>
                        <?php if ($isCritical): ?>
                            <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-red-500 to-red-600 animate-pulse"></div>
                        <?php elseif ($isUrgent): ?>
                            <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-orange-500 to-orange-600"></div>
                        <?php endif; ?>
                        
                        <!-- Quick Actions Menu -->
                        <div class="absolute top-4 left-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                            <div class="bg-white bg-opacity-90 backdrop-blur-sm rounded-lg shadow-lg p-2">
                                <button onclick="showQuickActions(<?php echo $reservation['id']; ?>)" 
                                        class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg flex items-center justify-center hover:scale-110 transition-transform duration-200">
                                    <i class="fas fa-ellipsis-h text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Header -->
                        <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-6 text-white relative">
                            <!-- Workflow Status Indicator -->
                            <div class="absolute top-4 right-4">
                                <?php
                                $statusColors = [
                                    'pending' => 'bg-yellow-500',
                                    'confirmed' => 'bg-green-500',
                                    'cancelled' => 'bg-red-500',
                                    'completed' => 'bg-blue-500',
                                    'expired' => 'bg-gray-500',
                                    'no_show' => 'bg-orange-500'
                                ];
                                $effectiveStatus = $reservation['effective_status'];
                                $statusColor = $statusColors[$effectiveStatus] ?? 'bg-gray-500';
                                ?>
                                <span class="<?php echo $statusColor; ?> text-white px-3 py-1 rounded-full text-xs font-bold <?php echo $effectiveStatus === 'expired' ? 'animate-pulse' : 'animate-pulse-slow'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $effectiveStatus)); ?>
                                    <?php if ($effectiveStatus === 'expired'): ?>
                                        <i class="fas fa-exclamation-triangle ml-1"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h3 class="text-xl font-bold mb-2"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                            <p class="text-blue-100 text-sm"><?php echo htmlspecialchars($reservation['user_name']); ?></p>
                            <?php if (!empty($reservation['phone_number'])): ?>
                                <p class="text-blue-100 text-xs mt-1">
                                    <i class="fas fa-phone mr-1"></i>
                                    +63<?php echo htmlspecialchars($reservation['phone_number']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Creation Date Display -->
                            <div class="mt-3 p-2 bg-white/10 rounded-lg border border-white/20">
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-calendar-plus text-blue-200 text-xs"></i>
                                    <div class="text-blue-100 text-xs">
                                        <div class="font-medium">Reservation Created</div>
                                        <div class="text-blue-200">
                                            <?php echo date('M j, Y g:i A', strtotime($reservation['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Content -->
                        <div class="p-6">
                            <!-- Reservation Details -->
                            <div class="space-y-4 mb-6">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Date:</span>
                                    <span class="text-gray-900 font-semibold"><?php echo date('M j, Y', strtotime($reservation['start_time'])); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Time:</span>
                                    <span class="text-gray-900 font-semibold">
                                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Duration:</span>
                                    <span class="text-gray-900 font-semibold">
                                        <?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type']); ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Amount:</span>
                                    <span class="text-gray-900 font-bold text-lg">₱<?php echo number_format($reservation['total_amount'], 2); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Payment:</span>
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $reservation['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : ($reservation['payment_status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                        <?php echo ucfirst($reservation['payment_status']); ?>
                                    </span>
                                </div>
                               
                                <?php if (!empty($reservation['phone_number'])): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 text-sm font-medium">Contact:</span>
                                    <span class="text-gray-900 font-semibold flex items-center">
                                        <i class="fas fa-phone mr-2 text-green-500"></i>
                                        +63<?php echo htmlspecialchars($reservation['phone_number']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <!-- Action Buttons -->
                            <div class="space-y-3">
                                <?php if ($reservation['status'] === 'pending'): ?>
                                    <?php if ($reservation['payment_status'] === 'pending' && !$reservation['payment_slip_url']): ?>
                                        <!-- User has not uploaded payment yet -->
                                        <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-3">
                                            <div class="flex items-center text-orange-800 mb-2">
                                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                                <span class="font-semibold">Payment Required</span>
                                            </div>
                                            <p class="text-orange-700 text-sm mb-3">User has not uploaded payment yet. Cannot approve reservation until payment is received.</p>
                                            <button onclick="confirmRejectReservation(<?php echo $reservation['id']; ?>)" 
                                                    class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold group">
                                                <i class="fas fa-times mr-2 group-hover:rotate-12 transition-transform"></i>Cancel Reservation
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <!-- Payment uploaded or not required - can approve -->
                                    <button onclick="submitForm('update_status', <?php echo $reservation['id']; ?>, {status: 'confirmed'})" 
                                            class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold group">
                                        <i class="fas fa-check mr-2 group-hover:rotate-12 transition-transform"></i>Approve Reservation
                                    </button>
                                    <button onclick="confirmRejectReservation(<?php echo $reservation['id']; ?>)" 
                                            class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold group">
                                        <i class="fas fa-times mr-2 group-hover:rotate-12 transition-transform"></i>Cancel Reservation
                                    </button>
                                    <?php endif; ?>
                                <?php elseif ($reservation['status'] === 'confirmed'): ?>
                                    <button onclick="confirmCompleteReservation(<?php echo $reservation['id']; ?>)" 
                                            class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold group">
                                        <i class="fas fa-check-double mr-2 group-hover:rotate-12 transition-transform"></i>Mark as Completed
                                    </button>
                                    <button onclick="confirmNoShowReservation(<?php echo $reservation['id']; ?>)" 
                                            class="w-full bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold group">
                                        <i class="fas fa-user-times mr-2 group-hover:rotate-12 transition-transform"></i>Mark as No-Show
                                    </button>
                                <?php endif; ?>
                                <!-- Payment Management Buttons -->
                                <?php if ($reservation['payment_status'] === 'pending'): ?>
                                    <!-- Payment verification form -->
                                    <div class="bg-gradient-to-r from-orange-50 to-orange-100 border border-orange-200 rounded-xl p-4">
                                        <div class="flex items-center text-orange-800 mb-3">
                                            <i class="fas fa-credit-card mr-2"></i>
                                            <span class="font-semibold">Payment Verification Required</span>
                                        </div>
                                        <p class="text-orange-700 text-sm mb-4">User has made payment at the office. Verify payment to confirm reservation.</p>
                                        
                                        <form method="POST" class="space-y-3">
                                            <input type="hidden" name="action" value="verify_payment">
                                            <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-orange-800 mb-1">OR Number *</label>
                                                    <input type="text" name="or_number" required
                                                           class="w-full px-3 py-2 border border-orange-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-sm"
                                                           placeholder="Enter OR number">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-orange-800 mb-1">Verified By</label>
                                                    <input type="text" name="staff_name" required readonly
                                                           value="<?php echo htmlspecialchars($_SESSION['full_name']); ?>"
                                                           class="w-full px-3 py-2 border border-orange-300 rounded-lg bg-orange-50 text-sm font-medium text-orange-800">
                                                    <p class="text-xs text-orange-600 mt-1">
                                                        <i class="fas fa-info-circle mr-1"></i>Automatically filled with your name
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div class="flex flex-col sm:flex-row gap-2">
                                                <button type="submit" 
                                                        class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 transform hover:scale-105 text-sm">
                                                    <i class="fas fa-check mr-1"></i>Verify Payment
                                                </button>
                                                <button type="button" onclick="showRejectModal(<?php echo $reservation['id']; ?>, '<?php echo htmlspecialchars($reservation['facility_name']); ?>')"
                                                        class="flex-1 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 transform hover:scale-105 text-sm">
                                                    <i class="fas fa-times mr-1"></i>Reject Payment
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                                    <!-- Payment verified -->
                                    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                        <div class="flex items-center text-green-800 mb-2">
                                            <i class="fas fa-check-circle mr-2"></i>
                                            <span class="font-semibold">Payment Verified</span>
                                        </div>
                                        <p class="text-green-700 text-sm">
                                            Payment verified on <?php echo $reservation['payment_verified_at'] ? date('M j, Y g:i A', strtotime($reservation['payment_verified_at'])) : 'Unknown'; ?>
                                        </p>
                                        <?php if ($reservation['or_number']): ?>
                                            <div class="text-xs text-green-600 mt-2">
                                                <i class="fas fa-receipt mr-1"></i>
                                                OR: <?php echo htmlspecialchars($reservation['or_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Recent Payment Verifications Section -->
    <div class="bg-white rounded-2xl p-6 shadow-xl border border-gray-100 mb-8">
        <div class="flex items-center mb-6">
            <i class="fas fa-history text-blue-500 text-xl mr-3"></i>
            <h2 class="text-xl font-semibold text-gray-900">Recent Payment Verifications</h2>
        </div>
        
        <?php if (empty($recent_verifications)): ?>
            <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-8 text-center border border-gray-200">
                <div class="w-16 h-16 bg-gray-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                    <i class="fas fa-history text-white text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No Recent Verifications</h3>
                <p class="text-gray-600">Payment verifications will appear here.</p>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="overflow-x-auto table-responsive">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OR Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verified By</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verified</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_verifications as $verification): ?>
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($verification['facility_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['user_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-green-600">₱<?php echo number_format($verification['total_amount'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($verification['or_number']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['verified_by_staff_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($verification['verified_by_admin']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <i class="fas fa-calendar-plus text-blue-500 mr-1"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($verification['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo date('M j, Y g:i A', strtotime($verification['payment_verified_at'])); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="mobile-table-card hidden">
                    <?php foreach ($recent_verifications as $verification): ?>
                        <div class="border border-gray-200 rounded-lg p-4 mb-4 bg-white shadow-sm">
                            <div class="space-y-2">
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">Facility:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['facility_name']); ?></span>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">User:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['user_name']); ?></span>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">Amount:</span>
                                    <span class="text-sm font-semibold text-green-600">₱<?php echo number_format($verification['total_amount'], 2); ?></span>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">OR Number:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['or_number']); ?></span>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">Verified By:</span>
                                    <div class="text-right">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($verification['verified_by_staff_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($verification['verified_by_admin']); ?></div>
                                    </div>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-medium text-gray-500">Date:</span>
                                    <span class="text-sm text-gray-900"><?php echo date('M j, Y g:i A', strtotime($verification['payment_verified_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Clean JavaScript -->
    <script>
        // Modal system functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }
        function hideModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        }
        function closeAllModals() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = 'auto';
        }
        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('modal')) {
                closeAllModals();
            }
        });
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });
        // Reservation management functions
        function confirmRejectReservation(reservationId) {
            document.getElementById('rejectReservationId').value = reservationId;
            showModal('rejectReservationModal');
        }
        function confirmCompleteReservation(reservationId) {
            document.getElementById('completeReservationId').value = reservationId;
            showModal('completeReservationModal');
        }
        function confirmNoShowReservation(reservationId) {
            document.getElementById('noShowReservationId').value = reservationId;
            showModal('noShowReservationModal');
        }
        // Helper function to submit forms
        function submitForm(action, reservationId, additionalData = {}) {
            try {
                const form = document.createElement("form");
                form.method = "POST";
                form.style.display = "none";
                form.action = window.location.href; // Ensure form submits to current page
                // Add action and reservation_id
                const actionInput = document.createElement("input");
                actionInput.type = "hidden";
                actionInput.name = "action";
                actionInput.value = action;
                form.appendChild(actionInput);
                const reservationInput = document.createElement("input");
                reservationInput.type = "hidden";
                reservationInput.name = "reservation_id";
                reservationInput.value = reservationId;
                form.appendChild(reservationInput);
                // Add additional data
                for (const [key, value] of Object.entries(additionalData)) {
                    const input = document.createElement("input");
                    input.type = "hidden";
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                // Add to document and submit
                document.body.appendChild(form);
                form.submit();
            } catch (error) {
                console.error("Error in submitForm:", error);
                alert("There was an error processing your request. Please try again.");
            }
        }
        // Payment slip viewer function
        function viewPaymentSlip(imageUrl, userName, phoneNumber) {
            // Create modal overlay
            const modal = document.createElement("div");
            modal.className = "fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50";
            modal.id = "paymentSlipModal";
            // Create modal content
            const modalContent = document.createElement("div");
            modalContent.className = "bg-white rounded-lg p-6 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto";
            // Create header
            const header = document.createElement("div");
            header.className = "flex justify-between items-center mb-4";
            const phoneDisplay = phoneNumber ? `<p class="text-sm text-gray-600 mt-1"><i class="fas fa-phone mr-1"></i>+63${phoneNumber}</p>` : '';
            header.innerHTML = `
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Payment Slip - ${userName}</h3>
                    ${phoneDisplay}
                </div>
                <button onclick="closePaymentSlipModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            `;
            // Create image container
            const imageContainer = document.createElement("div");
            imageContainer.className = "text-center";
            imageContainer.innerHTML = `
                <img src="${imageUrl}" alt="Payment Slip" class="max-w-full h-auto rounded-lg shadow-lg mx-auto" style="max-height: 70vh;">
            `;
            // Assemble modal
            modalContent.appendChild(header);
            modalContent.appendChild(imageContainer);
            modal.appendChild(modalContent);
            // Add to document
            document.body.appendChild(modal);
            // Close on background click
            modal.addEventListener("click", function(e) {
                if (e.target === modal) {
                    closePaymentSlipModal();
                }
            });
            // Close on escape key
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape") {
                    closePaymentSlipModal();
                }
            });
        }
        function closePaymentSlipModal() {
            const modal = document.getElementById("paymentSlipModal");
            if (modal) {
                modal.remove();
            }
        }
        
        // Show notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transform transition-all duration-300 ${
                type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-yellow-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        
        // Show quick actions menu
        function showQuickActions(reservationId) {
            // Check if reservation is expired
            const reservationRow = document.querySelector(`[data-reservation-id="${reservationId}"]`);
            const isExpired = reservationRow && reservationRow.dataset.effectiveStatus === 'expired';
            
            // Create quick actions menu
            const menu = document.createElement('div');
            menu.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            menu.id = 'quickActionsMenu';
            
            const menuContent = document.createElement('div');
            menuContent.className = 'bg-white rounded-2xl p-6 max-w-sm w-full mx-4 transform scale-95 transition-all duration-300';
            
            if (isExpired) {
                // Show expired reservation warning
                menuContent.innerHTML = `
                    <div class="text-center mb-6">
                        <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-exclamation-triangle text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Reservation Expired</h3>
                        <p class="text-gray-600 text-sm">This reservation has passed its end time and cannot be approved.</p>
                    </div>
                    <div class="space-y-3">
                        <button onclick="moveToPaymentHistory(${reservationId})" 
                                class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-history mr-2"></i>Move to Payment History
                        </button>
                        <button onclick="submitForm('update_status', ${reservationId}, {status: 'cancelled'})" 
                                class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel Reservation
                        </button>
                    </div>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <button onclick="closeQuickActions()" 
                                class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Close
                        </button>
                    </div>
                `;
            } else {
                // Show normal quick actions
                menuContent.innerHTML = `
                    <div class="text-center mb-6">
                        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-bolt text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Quick Actions</h3>
                        <p class="text-gray-600 text-sm">Choose an action for this reservation</p>
                    </div>
                    <div class="space-y-3">
                        <button onclick="submitForm('update_status', ${reservationId}, {status: 'confirmed'})" 
                                class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-check mr-2"></i>Approve
                        </button>
                        <button onclick="submitForm('update_status', ${reservationId}, {status: 'cancelled'})" 
                                class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button onclick="submitForm('update_status', ${reservationId}, {status: 'completed'})" 
                                class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-check-double mr-2"></i>Mark Complete
                        </button>
                        <button onclick="submitForm('update_status', ${reservationId}, {status: 'no_show'})" 
                                class="w-full bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-user-times mr-2"></i>Mark No-Show
                        </button>
                    </div>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <button onclick="closeQuickActions()" 
                                class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                `;
            }
            
            menu.appendChild(menuContent);
            document.body.appendChild(menu);
            
            // Animate in
            setTimeout(() => {
                menuContent.style.transform = 'scale(1)';
            }, 100);
            
            // Close on background click
            menu.addEventListener('click', function(e) {
                if (e.target === menu) {
                    closeQuickActions();
                }
            });
        }
        
        // Close quick actions menu
        function closeQuickActions() {
            const menu = document.getElementById('quickActionsMenu');
            if (menu) {
                const menuContent = menu.querySelector('div');
                menuContent.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    menu.remove();
                }, 300);
            }
        }
        
        // Move expired reservation to payment history
        function moveToPaymentHistory(reservationId) {
            if (confirm('Are you sure you want to move this expired reservation to payment history? This will mark it as cancelled and move it to the payment history page.')) {
                // Create a form to submit the action
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'move_to_payment_history';
                
                const reservationIdInput = document.createElement('input');
                reservationIdInput.type = 'hidden';
                reservationIdInput.name = 'reservation_id';
                reservationIdInput.value = reservationId;
                
                form.appendChild(actionInput);
                form.appendChild(reservationIdInput);
                document.body.appendChild(form);
                form.submit();
            }
            closeQuickActions();
        }
        
        // Clear search function
        function clearSearch() {
            window.location.href = window.location.pathname;
        }
        // Mobile menu toggle
        function toggleMobileMenu() {
            const navLinks = document.querySelector('.nav-links-container');
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            
            if (navLinks) {
                navLinks.classList.toggle('show');
            }
            if (mobileToggle) {
                mobileToggle.classList.toggle('active');
            }
        }
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navContainer = document.querySelector('.nav-container');
            if (navContainer) {
                if (window.scrollY > 50) {
                    navContainer.classList.add('scrolled');
                } else {
                    navContainer.classList.remove('scrolled');
                }
            }
        });
    </script>
    <!-- Action Modals -->

    <!-- Reject/Cancel Reservation Modal -->
    <div id="rejectReservationModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 opacity-0 invisible transition-all duration-300">
        <div class="modal-content bg-white rounded-3xl shadow-2xl max-w-md w-full mx-4 transform scale-95 transition-all duration-300">
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-times text-white text-2xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Cancel Reservation</h3>
                    <p class="text-gray-600">Are you sure you want to cancel this reservation? This action cannot be undone and will notify the user.</p>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="cancelled">
                    <input type="hidden" id="rejectReservationId" name="reservation_id" value="">
                    <div class="space-y-3">
                        <label class="block text-sm font-medium text-gray-700">Notes (Optional)</label>
                        <textarea name="notes" rows="3" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-red-500/20 focus:border-red-500 transition-all duration-300" placeholder="Add any notes about the cancellation..."></textarea>
                    </div>
                    <div class="flex space-x-3">
                        <button type="button" onclick="hideModal('rejectReservationModal')" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-ban mr-2"></i>Cancel Reservation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Complete Reservation Modal -->
    <div id="completeReservationModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 opacity-0 invisible transition-all duration-300">
        <div class="modal-content bg-white rounded-3xl shadow-2xl max-w-md w-full mx-4 transform scale-95 transition-all duration-300">
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-double text-white text-2xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Complete Reservation</h3>
                    <p class="text-gray-600">Are you sure you want to mark this reservation as completed? This will automatically move it to the usage history page.</p>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="completed">
                    <input type="hidden" id="completeReservationId" name="reservation_id" value="">
                    <div class="flex space-x-3">
                        <button type="button" onclick="hideModal('completeReservationModal')" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-check-double mr-2"></i>Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- No-Show Reservation Modal -->
    <div id="noShowReservationModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 opacity-0 invisible transition-all duration-300">
        <div class="modal-content bg-white rounded-3xl shadow-2xl max-w-md w-full mx-4 transform scale-95 transition-all duration-300">
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-times text-white text-2xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Mark as No-Show</h3>
                    <p class="text-gray-600">Are you sure you want to mark this reservation as no-show? This action cannot be undone and will be recorded in the Cancel History.</p>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="no_show">
                    <input type="hidden" id="noShowReservationId" name="reservation_id" value="">
                    <div class="flex space-x-3">
                        <button type="button" onclick="hideModal('noShowReservationModal')" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 font-bold">
                            <i class="fas fa-user-times mr-2"></i>Mark No-Show
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Success Payment Verification Modal -->
    <?php if (isset($success_message)): ?>
    <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="successModalContent">
            <div class="p-6">
                <!-- Success Icon -->
                <div class="flex items-center justify-center mb-4">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                    </div>
                </div>
                <!-- Success Title -->
                <div class="text-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">Reservation Approved Successfully!</h3>
                    <p class="text-gray-600">The reservation is now confirmed and ready for usage management.</p>
                </div>
                <!-- Next Steps -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h4 class="font-medium text-blue-800 mb-2 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>What happens next?
                    </h4>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li>• The facility is now available in Usage Management</li>
                        <li>• Admin can start usage when the user arrives</li>
                        <li>• The facility will appear in "Currently in Use" when active</li>
                        <li>• Users can see the facility status on the main page</li>
                        <li>• Completed usage will be moved to Usage History</li>
                    </ul>
                </div>
                <!-- Action Buttons -->
                <div class="flex space-x-3">
                    <button onclick="closeSuccessModal()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition duration-200 font-medium">
                        <i class="fas fa-times mr-2"></i>Close
                    </button>
                    <a href="usage_management.php" class="flex-1 bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 font-medium text-center">
                        <i class="fas fa-clock mr-2"></i>Go to Usage Management
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <script>
        // Show success modal if it exists
        document.addEventListener('DOMContentLoaded', function() {
            const successModal = document.getElementById('successModal');
            if (successModal) {
                const modalContent = document.getElementById('successModalContent');
                if (modalContent) {
                    // Animate modal in
                    setTimeout(() => {
                        modalContent.classList.remove('scale-95', 'opacity-0');
                        modalContent.classList.add('scale-100', 'opacity-100');
                    }, 100);
                }
            }
        });
        // Function to close success modal
        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            if (modal && modalContent) {
                modalContent.classList.add('scale-95', 'opacity-0');
                modalContent.classList.remove('scale-100', 'opacity-100');
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }
        }
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const successModal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            if (successModal && event.target === successModal) {
                closeSuccessModal();
            }
        });
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSuccessModal();
            }
        });
    </script>
    
    <!-- Payment Rejection Modal -->
    <div id="rejectPaymentModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0" id="rejectPaymentModalContent">
            <div class="p-6">
                <div class="flex items-center mb-6">
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mr-4 shadow-lg">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Reject Payment</h3>
                        <p class="text-sm text-gray-600">This will cancel the reservation</p>
                    </div>
                </div>
                
                <form method="POST" id="rejectPaymentForm">
                    <input type="hidden" name="action" value="reject_payment">
                    <input type="hidden" name="reservation_id" id="rejectPaymentReservationId">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rejection Reason</label>
                        <textarea name="rejection_reason" rows="3" required
                                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-red-500 focus:border-red-500 transition duration-200"
                                  placeholder="Enter reason for rejection"></textarea>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-3">
                        <button type="button" onclick="closeRejectPaymentModal()" 
                                class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-3 rounded-xl font-medium transition duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-xl font-medium transition duration-200 shadow-lg">
                            <i class="fas fa-times mr-2"></i>Reject Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Payment rejection modal functions
        function showRejectModal(reservationId, facilityName) {
            const reservationIdInput = document.getElementById('rejectPaymentReservationId');
            if (reservationIdInput) {
                reservationIdInput.value = reservationId;
            }
            
            const modal = document.getElementById('rejectPaymentModal');
            const modalContent = document.getElementById('rejectPaymentModalContent');
            
            if (modal && modalContent) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                
                setTimeout(() => {
                    modalContent.classList.remove('scale-95', 'opacity-0');
                    modalContent.classList.add('scale-100', 'opacity-100');
                }, 10);
                
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeRejectPaymentModal() {
            const modal = document.getElementById('rejectPaymentModal');
            const modalContent = document.getElementById('rejectPaymentModalContent');
            
            if (modal && modalContent) {
                modalContent.classList.add('scale-95', 'opacity-0');
                modalContent.classList.remove('scale-100', 'opacity-100');
                
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                }, 300);
            }
        }
        
        // Close modal when clicking outside
        const rejectPaymentModal = document.getElementById('rejectPaymentModal');
        if (rejectPaymentModal) {
            rejectPaymentModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeRejectPaymentModal();
                }
            });
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRejectPaymentModal();
            }
        });
        
        // Mobile responsiveness for recent verifications table
        function toggleMobileTable() {
            const table = document.querySelector('.table-responsive');
            const mobileCards = document.querySelector('.mobile-table-card');
            
            if (window.innerWidth <= 768) {
                if (table) table.classList.add('hidden');
                if (mobileCards) mobileCards.classList.remove('hidden');
            } else {
                if (table) table.classList.remove('hidden');
                if (mobileCards) mobileCards.classList.add('hidden');
            }
        }
        
        // Initialize mobile table on load and resize
        document.addEventListener('DOMContentLoaded', function() {
            toggleMobileTable();
            window.addEventListener('resize', toggleMobileTable);
        });
    </script>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</body>
</html>
