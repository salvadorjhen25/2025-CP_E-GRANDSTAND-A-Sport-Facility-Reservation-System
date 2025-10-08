<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
require_once 'classes/EmailMailer.php';
$auth = new Auth();
$auth->requireRegularUser();
$pdo = getDBConnection();
$paymentManager = new PaymentManager();
// Get facility details
$facility_id = $_GET['facility_id'] ?? null;
if (!$facility_id) {
    header('Location: index.php');
    exit();
}
$stmt = $pdo->prepare("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE f.id = ? AND f.is_active = 1
");
$stmt->execute([$facility_id]);
$facility = $stmt->fetch();
if (!$facility) {
    header('Location: index.php');
    exit();
}
// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $booking_type = $_POST['booking_type'] ?? 'hourly';
    $purpose = $_POST['purpose'] ?? '';
    $attendees = null; // No longer collecting attendees
    $phone_number = $_POST['phone_number'] ?? '';
    // Validate inputs
    $errors = [];
    if (empty($start_time) || empty($end_time)) {
        $errors[] = 'Please select start and end times';
    }
    if (strtotime($start_time) >= strtotime($end_time)) {
        $errors[] = 'End time must be after start time';
    }
    
    // Check if the booking date is in the past (only date comparison, not time)
    $booking_date = date('Y-m-d', strtotime($start_time));
    $current_date = date('Y-m-d');
    if ($booking_date < $current_date) {
        $errors[] = 'Cannot book in the past';
    }
    
    // Check if the booking time has already passed today
    if ($booking_date === $current_date) {
        // Get current time as timestamp for accurate comparison
        $current_timestamp = time();
        $start_timestamp = strtotime($start_time);
        $end_timestamp = strtotime($end_time);
        
        // Add 15 minutes buffer to prevent booking too close to current time
        $buffer_timestamp = $current_timestamp + (15 * 60); // 15 minutes in seconds
        
        // Check if start time has already passed (with buffer)
        if ($start_timestamp <= $buffer_timestamp) {
            $current_time_formatted = date('g:i A', $current_timestamp);
            $earliest_booking = date('g:i A', $buffer_timestamp);
            $errors[] = "Cannot book a time slot that has already passed or is too close to current time. Current time: {$current_time_formatted}, Earliest available booking: {$earliest_booking}";
        }
        
        // Additional check: if the entire booking duration has already passed
        if ($end_timestamp <= $current_timestamp) {
            $current_time_formatted = date('g:i A', $current_timestamp);
            $errors[] = "Cannot book a time slot that has completely passed. Current time: {$current_time_formatted}, Booking ends at: " . date('g:i A', $end_timestamp);
        }
        
        // Debug logging (remove in production)
        error_log("Time Validation Debug - Current: " . date('Y-m-d H:i:s', $current_timestamp) . ", Start: " . date('Y-m-d H:i:s', $start_timestamp) . ", End: " . date('Y-m-d H:i:s', $end_timestamp) . ", Buffer: " . date('Y-m-d H:i:s', $buffer_timestamp));
    }
    
    // Check if booking time is within operating hours
    $start_hour = (int)date('H', strtotime($start_time));
    $end_hour = (int)date('H', strtotime($end_time));
    $start_minute = (int)date('i', strtotime($start_time));
    $end_minute = (int)date('i', strtotime($end_time));
    
    // Convert to decimal hours for easier comparison
    $start_decimal = $start_hour + ($start_minute / 60);
    $end_decimal = $end_hour + ($end_minute / 60);
    
    if ($start_decimal < 8 || $end_decimal > 21.5) {
        $errors[] = 'Booking must be within operating hours (8:00 AM - 9:30 PM)';
    }
    
    // Check minimum and maximum booking duration
    $duration_hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
    if ($duration_hours < 0.5) {
        $errors[] = 'Minimum booking duration is 30 minutes';
    }
    if ($duration_hours > 13.5) {
        $errors[] = 'Maximum booking duration is 13.5 hours (8:00 AM - 9:30 PM)';
    }
    if (empty($purpose)) {
        $errors[] = 'Please provide a purpose for the reservation';
    }
    
    // Validate phone number (Philippine format)
    if (empty($phone_number)) {
        $errors[] = 'Please provide your contact phone number';
    } else {
        // Remove any non-numeric characters
        $phone_clean = preg_replace('/[^0-9]/', '', $phone_number);
        
        // Check if it's exactly 10 digits
        if (strlen($phone_clean) !== 10) {
            $errors[] = 'Phone number must be exactly 10 digits (e.g., 9123456789)';
        } else {
            // Check if it starts with valid Philippine mobile prefixes
            $valid_prefixes = ['9', '8', '7', '6', '5', '4', '3', '2'];
            $first_digit = substr($phone_clean, 0, 1);
            
            if (!in_array($first_digit, $valid_prefixes)) {
                $errors[] = 'Phone number must start with a valid Philippine mobile prefix (2-9)';
            }
            
            // Store the cleaned phone number
            $phone_number = $phone_clean;
        }
    }
    // Check for conflicts with improved overlap detection
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, 
                   GROUP_CONCAT(
                       CONCAT(
                           DATE_FORMAT(start_time, '%h:%i %p'), 
                           ' - ', 
                           DATE_FORMAT(end_time, '%h:%i %p')
                       ) SEPARATOR ', '
                   ) as conflicting_times
            FROM reservations 
            WHERE facility_id = ? 
            AND status IN ('pending', 'confirmed')
            AND (
                -- New booking starts during existing booking
                (start_time >= ? AND start_time < ?) OR
                -- New booking ends during existing booking  
                (end_time > ? AND end_time <= ?) OR
                -- New booking completely contains existing booking
                (start_time <= ? AND end_time >= ?) OR
                -- New booking is completely within existing booking
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $stmt->execute([
            $facility_id, 
            $start_time, $end_time,  // start_time >= new_start AND start_time < new_end
            $start_time, $end_time,  // end_time > new_start AND end_time <= new_end  
            $start_time, $end_time,  // start_time <= existing_start AND end_time >= existing_end
            $start_time, $end_time   // start_time >= existing_start AND end_time <= existing_end
        ]);
        $conflict = $stmt->fetch();
        if ($conflict['count'] > 0) {
            $conflicting_times = $conflict['conflicting_times'];
            $errors[] = "This time slot conflicts with existing bookings: {$conflicting_times}";
        }
    }
    // Create reservation if no errors
    if (empty($errors)) {
        $hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
        
        // Get selected pricing options (multiple)
        $pricing_option_ids = $_POST['pricing_option_ids'] ?? [];
        if (!is_array($pricing_option_ids)) {
            $pricing_option_ids = array_filter([$pricing_option_ids]);
        }
        $total_amount = 0;
        $pricing_selections = [];
        
        // Base facility cost (hourly)
        $total_amount = $hours * $facility['hourly_rate'];
        
        // Validate that the facility hourly rate is reasonable (prevent errors)
        if ($facility['hourly_rate'] > 10000) {
            $errors[] = 'Facility hourly rate appears to be incorrect. Please contact support.';
        }
        
        // Validate that the total amount is reasonable (prevent calculation errors)
        if ($total_amount > 50000) {
            $errors[] = 'Total amount appears to be incorrect. Please contact support.';
        }

        if (!empty($pricing_option_ids)) {
            // Fetch all selected pricing options
            $placeholders = implode(',', array_fill(0, count($pricing_option_ids), '?'));
            $params = $pricing_option_ids;
            $params[] = $facility_id;
            $stmt = $pdo->prepare("SELECT * FROM facility_pricing_options WHERE id IN ($placeholders) AND facility_id = ? AND is_active = 1");
            $stmt->execute($params);
            $selected_options = $stmt->fetchAll();
            if (count($selected_options) !== count($pricing_option_ids)) {
                $errors[] = 'One or more selected packages are invalid.';
            } else {
                foreach ($selected_options as $opt) {
                    $total_amount += (float)$opt['price_per_hour'];
                    $pricing_selections[] = [
                        'pricing_option_id' => (int)$opt['id'],
                        'name' => $opt['name'],
                        'price' => (float)$opt['price_per_hour'],
                        'quantity' => 1,
                        'pricing_type' => 'flat_addon'
                    ];
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $reservationId = $paymentManager->createReservation(
                    $_SESSION['user_id'], 
                    $facility_id, 
                    $start_time, 
                    $end_time, 
                    $purpose, 
                    $total_amount,
                    $phone_number,
                    $booking_type,
                    $hours
                );
                
                // Save pricing selections if any
                if (!empty($pricing_selections)) {
                    $stmt = $pdo->prepare("
                        UPDATE reservations 
                        SET pricing_selections = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([json_encode($pricing_selections), $reservationId]);
                    
                    // Insert pricing selections into reservation_pricing_selections table
                    foreach ($pricing_selections as $selection) {
                        $stmt = $pdo->prepare("
                            INSERT INTO reservation_pricing_selections (reservation_id, pricing_option_id, quantity)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$reservationId, $selection['pricing_option_id'], $selection['quantity']]);
                    }
                }
                
                // Send email notifications
                $mailer = new EmailMailer();
                // Get user details
                $user_stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                $user_stmt->execute([$_SESSION['user_id']]);
                $user = $user_stmt->fetch();
                // Prepare reservation data for email
                $reservation_data = [
                    'id' => $reservationId,
                    'facility_name' => $facility['name'],
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'total_amount' => $total_amount,
                    'payment_due_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                    'user_name' => $user['full_name'],
                    'user_email' => $user['email']
                ];
                // Send confirmation email to user
                $mailer->sendReservationConfirmation(
                    $user['email'],
                    $user['full_name'],
                    $reservation_data
                );
                // Send notification email to admin
                $mailer->sendAdminNotification($reservation_data);
                $success_message = 'Reservation submitted successfully! You have 24 hours to make the physical payment. Please upload your payment slip once payment is made.';
                $reservation_id = $reservationId;
            } catch (Exception $e) {
                $errors[] = 'Failed to create reservation. Please try again.';
            }
        }
    }
}
// Handle payment slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_payment') {
    $reservation_id = $_POST['reservation_id'] ?? null;
    if ($reservation_id && isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_slip'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = 'Invalid file type. Please upload JPG, PNG, or PDF files only.';
        } elseif ($file['size'] > $max_size) {
            $errors[] = 'File size too large. Maximum size is 5MB.';
        } else {
            $upload_dir = 'uploads/payment_slips/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'payment_' . $reservation_id . '_' . time() . '.' . $file_extension;
            $filepath = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                if ($paymentManager->uploadPaymentSlip($reservation_id, $filepath)) {
                    $success_message = 'Payment slip uploaded successfully! An administrator will verify your payment.';
                } else {
                    $errors[] = 'Failed to upload payment slip. Please try again.';
                }
            } else {
                $errors[] = 'Failed to upload file. Please try again.';
            }
        }
    } else {
        $errors[] = 'Please select a payment slip file.';
    }
}
// Get pricing options for this facility
try {
    $stmt = $pdo->prepare("
        SELECT * FROM facility_pricing_options 
        WHERE facility_id = ? AND is_active = 1 
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([$facility_id]);
    $pricing_options = $stmt->fetchAll();
} catch (PDOException $e) {
    // If tables don't exist yet, set empty array
    $pricing_options = [];
}

// Get existing reservations for this facility (for calendar display)
$stmt = $pdo->prepare("
    SELECT start_time, end_time, status 
    FROM reservations 
    WHERE facility_id = ? 
    AND status IN ('pending', 'confirmed')
    AND start_time >= CURDATE()
    ORDER BY start_time
");
$stmt->execute([$facility_id]);
$existing_reservations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($facility['name']); ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <link rel="stylesheet" href="assets/css/mobile-responsive.css?v=1.0.0">
    <script src="assets/js/modal-system.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a'
                        },
                        secondary: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155'
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.6s ease-out',
                        'scale-in': 'scaleIn 0.4s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'shake': 'shake 0.5s ease-in-out',
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        @keyframes scaleIn {
            from { 
                opacity: 0; 
                transform: scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: scale(1); 
            }
        }
        @keyframes bounceIn {
            0% { opacity: 0; transform: scale(0.3); }
            50% { opacity: 1; transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .time-slot {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .time-slot::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .time-slot:hover::before {
            left: 100%;
        }
        .time-slot:hover {
            transform: translateY(-4px) scale(1.05);
        }
        .time-slot.selected {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            transform: scale(1.05);
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.4);
        }
        .time-slot.disabled {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            color: #9ca3af;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        /* Enhanced time slot animations */
        .time-slot:not(.disabled) {
            animation: pulse-available 2s infinite;
        }
        
        @keyframes pulse-available {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
        }
        .form-input-enhanced {
            transition: all 0.3s ease;
            background: #ffffff !important;
            color: #374151 !important;
            border: 2px solid #e5e7eb;
        }
        
        .form-input-enhanced::placeholder {
            color: #9ca3af !important;
        }
        
        input[type="date"].form-input-enhanced,
        input[type="time"].form-input-enhanced {
            color: #374151 !important;
            background: #ffffff !important;
        }
        
        /* Calendar highlighting styles */
        #availabilityCalendar > div {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        #availabilityCalendar > div:hover {
            transform: scale(1.05);
            z-index: 10;
        }
        
        #availabilityCalendar > div[data-date] {
            cursor: pointer;
            user-select: none;
        }
        
        /* Selected date highlighting */
        #availabilityCalendar > div.ring-4 {
            animation: selectedDatePulse 2s ease-in-out infinite;
        }
        
        @keyframes selectedDatePulse {
            0%, 100% { 
                transform: scale(1.05);
                box-shadow: 0 0 0 0 rgba(147, 51, 234, 0.7);
            }
            50% { 
                transform: scale(1.08);
                box-shadow: 0 0 0 8px rgba(147, 51, 234, 0.3);
            }
        }
        
        /* Today highlighting */
        #availabilityCalendar > div.ring-2 {
            animation: todayPulse 3s ease-in-out infinite;
        }
        
        @keyframes todayPulse {
            0%, 100% { 
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7);
            }
            50% { 
                transform: scale(1.02);
                box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.3);
            }
        }
        
        /* Responsive Current Time Display */
        @media (max-width: 640px) {
            #currentTimeDisplay {
                font-size: 1.5rem;
                padding: 0.5rem 1rem;
            }
            
            .current-time-container {
                text-align: center;
                flex-direction: column;
            }
            
            .current-time-icon {
                margin-bottom: 0.5rem;
                margin-right: 0;
            }
            
            .current-time-text {
                text-align: center;
            }
            
            .current-time-display {
                margin-top: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .current-time-layout {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .current-time-display {
                margin-top: 1rem;
                width: 100%;
            }
            
            .current-time-container {
                margin-bottom: 1rem;
            }
        }
        
        @media (min-width: 1024px) {
            .current-time-layout {
                flex-direction: row;
                align-items: center;
                text-align: left;
            }
            
            .current-time-display {
                margin-top: 0;
                width: auto;
            }
            
            .current-time-container {
                margin-bottom: 0;
            }
        }
        
        /* Enhanced mobile experience */
        @media (max-width: 480px) {
            #currentTimeDisplay {
                font-size: 1.25rem;
                padding: 0.75rem;
                border-radius: 0.5rem;
            }
            
            .current-time-container {
                margin-bottom: 1.5rem;
            }
            
            .current-time-icon {
                width: 3rem;
                height: 3rem;
            }
            
            .current-time-text h3 {
                font-size: 1.125rem;
            }
            
            .current-time-text p {
                font-size: 0.875rem;
            }
        }
        
        /* Time Slot Selection Highlighting */
        .time-slot-card.selected {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: white !important;
            border-color: #1e40af !important;
            transform: scale(1.05) !important;
            box-shadow: 0 20px 25px -5px rgba(59, 130, 246, 0.4), 0 10px 10px -5px rgba(59, 130, 246, 0.2) !important;
        }
        
        .time-slot-card.selected .text-gray-800,
        .time-slot-card.selected .text-blue-600,
        .time-slot-card.selected .text-gray-500 {
            color: white !important;
        }
        
        .time-slot-card.selected .text-gray-400 {
            color: rgba(255, 255, 255, 0.8) !important;
        }
        
        .time-slot-card.selected .text-green-600,
        .time-slot-card.selected .text-green-700,
        .time-slot-card.selected .text-green-800 {
            color: #dbeafe !important;
        }
        
        .time-slot-card.selected .text-red-500 {
            color: #fecaca !important;
        }
        
        /* Daily slot selection highlighting */
        .time-slot-card.selected.daily-selected {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            border-color: #047857 !important;
            box-shadow: 0 20px 25px -5px rgba(16, 185, 129, 0.4), 0 10px 10px -5px rgba(16, 185, 129, 0.2) !important;
        }
        
        /* Selection animation */
        .time-slot-card.selected {
            animation: slotSelected 0.3s ease-out;
        }
        
        @keyframes slotSelected {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1.05); }
        }
        
        /* Hover effect for selected slots */
        .time-slot-card.selected:hover {
            transform: scale(1.08) !important;
        }
        .form-input-enhanced:focus {
            background: #ffffff;
            color: #111827;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            transform: translateY(-2px);
            outline: none;
        }
        .booking-type-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .booking-type-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }
        .booking-type-card:hover::before {
            left: 100%;
        }
        .booking-type-card.selected {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-color: #3b82f6;
            transform: scale(1.02);
        }
        .booking-type-option.selected {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .booking-type-option.selected[data-type="hourly"] {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-color: #3b82f6;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
        }
        .booking-type-option.selected[data-type="daily"] {
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff);
            border-color: #a855f7;
            box-shadow: 0 0 20px rgba(147, 51, 234, 0.3);
        }
        .booking-type-option {
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .booking-type-option:hover:not(.selected) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        .error-shake {
            animation: shake 0.5s ease-in-out;
        }
        .success-pulse {
            animation: pulse 2s infinite;
        }
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            animation: bounce-in 0.8s ease-out;
        }
        .cost-preview-card {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 2px solid #0ea5e9;
        }
        .loading-overlay {
            backdrop-filter: blur(5px);
        }
        
        /* Conflict Error Styling */
        #conflictError {
            animation: slideInDown 0.3s ease-out;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        #conflictError.hidden {
            display: none;
        }
        
        /* Enhanced UI Animations */
        .quick-time-slot {
            position: relative;
            overflow: hidden;
        }
        
        .quick-time-slot::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }
        
        .quick-time-slot:hover::before {
            left: 100%;
        }
        
        /* Enhanced shadow effects */
        .shadow-3xl {
            box-shadow: 0 35px 60px -12px rgba(0, 0, 0, 0.25);
        }
        
        /* Enhanced form inputs - duplicate removed */
        
        /* Enhanced pricing option cards */
        .pricing-option-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .pricing-option-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(147, 51, 234, 0.1), transparent);
            transition: left 0.5s;
        }
        
        .pricing-option-card:hover::before {
            left: 100%;
        }
        
        .pricing-option-card:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }
        
        .pricing-option-card.selected {
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff) !important;
            border-color: #a855f7 !important;
            box-shadow: 0 20px 25px -5px rgba(147, 51, 234, 0.4), 0 10px 10px -5px rgba(147, 51, 234, 0.2) !important;
            transform: scale(1.05) !important;
        }
        
        .pricing-option-card.selected .text-gray-800,
        .pricing-option-card.selected .text-gray-600 {
            color: #581c87 !important;
        }
        
        .pricing-option-card.selected .text-purple-600 {
            color: #7c3aed !important;
        }
        
        .pricing-option-card.selected .text-gray-500 {
            color: #6b21a8 !important;
        }
        
        .pricing-option-card.selected .bg-gray-100 {
            background-color: #e9d5ff !important;
            color: #581c87 !important;
        }
        
        /* Pricing option selection animation */
        .pricing-option-card.selected {
            animation: pricingSelected 0.3s ease-out;
        }
        
        @keyframes pricingSelected {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1.05); }
        }
        
        /* Hover effect for selected pricing cards */
        .pricing-option-card.selected:hover {
            transform: scale(1.08) !important;
        }
        
        /* Enhanced loading animation */
        @keyframes pulse-glow {
            0%, 100% { 
                box-shadow: 0 0 5px rgba(59, 130, 246, 0.5);
            }
            50% { 
                box-shadow: 0 0 20px rgba(59, 130, 246, 0.8), 0 0 30px rgba(59, 130, 246, 0.6);
            }
        }
        
        .pulse-glow {
            animation: pulse-glow 2s infinite;
        }
        @media (max-width: 768px) {
            .reservation-form {
                padding: 1rem;
            }
            .facility-info {
                text-align: center;
            }
        }
        
        /* Form input styling for better visibility */
        #phone_number, #purpose, #attendees {
            color: #000000 !important;
            font-weight: 500 !important;
        }
        
        #phone_number:focus, #purpose:focus, #attendees:focus {
            color: #000000 !important;
            font-weight: 500 !important;
        }
        
        #phone_number::placeholder, #purpose::placeholder, #attendees::placeholder {
            color: #9ca3af !important;
            font-weight: 400 !important;
        }
         /* Enhanced Navigation */
         .nav-bar {
            background: #1e40af !important;
            border-bottom: 1px solid #1d4ed8 !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 100 !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .nav-container {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 0 2rem !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            height: 80px !important;
        }
        
        .nav-title {
            font-family: 'Inter', sans-serif !important;
            font-weight: 800 !important;
            color: white !important;
            font-size: 1.5rem !important;
        }
        
        .nav-user-name {
            font-family: 'Inter', sans-serif !important;
            font-weight: 600 !important;
            color: white !important;
        }
        
        /* Unified Navigation Buttons */
        .nav-btn {
            background: rgba(255, 255, 255, 0.15) !important;
            color: white !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            padding: 0.75rem 1.5rem !important;
            border-radius: 0.5rem !important;
            font-family: 'Inter', sans-serif !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            transition: all 0.3s ease !important;
            min-width: 140px !important;
            justify-content: center !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }
        
        .nav-btn.logout-btn {
            background: rgba(220, 38, 38, 0.8) !important;
            border: 1px solid rgba(220, 38, 38, 0.9) !important;
        }
        
        .nav-btn.logout-btn:hover {
            background: rgba(220, 38, 38, 1) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3) !important;
        }
        
        .nav-user-info {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            background: rgba(255, 255, 255, 0.1) !important;
            padding: 0.75rem 1rem !important;
            border-radius: 0.5rem !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-user-icon {
            color: white !important;
            font-size: 1rem !important;
        }
        
        /* Navigation Menu Visibility */
        .nav-menu {
            display: flex !important;
            align-items: center !important;
            gap: 1rem !important;
        }
        
        .nav-menu-mobile {
            display: none !important;
        }
        
        @media (max-width: 768px) {
            .nav-menu {
                display: none !important;
            }
            
            .nav-menu-mobile {
                display: block !important;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300 loading-overlay">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500 mx-auto mb-4"></div>
            <p class="text-gray-600 font-medium">Processing your reservation...</p>
        </div>
    </div>

    <!-- Enhanced Navigation -->
   <!-- Enhanced Navigation -->
    <nav class="nav-bar">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="nav-logo">
                    <i class="fas fa-building text-white"></i>
                </div>
                <h1 class="nav-title"><?php echo SITE_NAME; ?></h1>
            </div>
            
            <!-- Desktop Navigation -->
            <div class="nav-menu">
                <div class="nav-user-info">
                    <i class="fas fa-user nav-user-icon"></i>
                    <span class="nav-user-name">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
                <a href="index.php" class="nav-btn">
                    <span>Home</span>
                </a>
                <a href="facilities.php" class="nav-btn">
                    <span>Facilities</span>
                </a>
                <a href="my_reservations.php" class="nav-btn">
                            <span>My Reservations</span>
                        </a>
                <a href="auth/logout.php" class="nav-btn logout-btn" onclick="return confirmLogout()">
                    <span>Logout</span>
                </a>
            </div>
            
            <!-- Enhanced Mobile menu button -->
            <div class="nav-menu-mobile">
                <button id="mobile-menu-button" class="mobile-menu-btn" aria-label="Toggle mobile menu">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
            </div>
        </div>
            <!-- Mobile Navigation -->
        <div id="mobile-menu" class="hidden mobile-menu">
            <div class="container" style="padding: 1rem 0;">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="color: white; padding: 0.75rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; font-weight: 500; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-user" style="margin-right: 0.5rem;"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </div>
                    <a href="index.php" class="nav-btn" style="display: block; text-align: center;">
                        Home
                    </a>
                    <a href="facilities.php" class="nav-btn" style="display: block; text-align: center;">
                        Facilities
                    </a>
                    <a href="auth/logout.php" class="nav-btn logout-btn" style="display: block; text-align: center;" onclick="return confirmLogout()">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Enhanced Breadcrumb Navigation -->
        <nav class="mb-8 animate-fade-in">
            <ol class="flex items-center space-x-2 text-sm text-gray-600">
                <li>
                    <a href="index.php" class="hover:text-blue-600 transition-colors duration-200">
                        <i class="fas fa-home mr-1"></i>Home
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li>
                    <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" class="hover:text-blue-600 transition-colors duration-200">
                        <?php echo htmlspecialchars($facility['name']); ?>
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li class="text-blue-600 font-medium">
                    Book Now
                </li>
            </ol>
        </nav>
        
        <!-- Operating Hours Info Banner -->
        <div class="mb-6 animate-fade-in">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-blue-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-blue-800">Operating Hours</h3>
                            <p class="text-xs text-blue-600">8:00 AM - 10:00 PM • Last booking ends at 9:30 PM</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-blue-500">
                            <span class="font-medium">Today:</span><br>
                            <?php echo date('l, F j'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <script>
                // Show success modal when page loads
                document.addEventListener('DOMContentLoaded', function() {
                    showSuccessModal('<?php echo addslashes($success_message); ?>');
                });
            </script>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Enhanced Facility Information -->
            <div class="lg:col-span-1">
                <div class="enhanced-card card-hover p-6 facility-info animate-slide-up">
                    <div class="text-center mb-6">
                        <div class="h-32 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mb-4 shadow-lg">
                            <i class="fas fa-building text-white text-4xl"></i>
                        </div>
                        <h1 class="text-2xl font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($facility['name']); ?></h1>
                        <div class="flex items-center justify-center space-x-2 mb-4">
                            <span class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-3 py-1 rounded-full text-sm font-semibold">
                                ₱<?php echo number_format($facility['hourly_rate'], 2); ?>
                            </span>
                            <?php if (!empty($pricing_options)): ?>
                            <span class="bg-gradient-to-r from-purple-500 to-purple-600 text-white px-3 py-1 rounded-full text-sm font-semibold">
                                <?php echo count($pricing_options); ?> Pricing Options
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Capacity</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo $facility['capacity']; ?> people</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-tag text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Category</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo htmlspecialchars($facility['category_name']); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg border border-purple-200">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Operating Hours</p>
                                <p class="font-bold text-gray-900 text-lg">8:00 AM - 10:00 PM</p>
                                <p class="text-xs text-purple-600">Last booking ends at 9:30 PM</p>
                            </div>
                        </div>
                        
                        <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg p-4 border border-gray-200">
                            <h3 class="font-semibold text-gray-900 mb-2">Description</h3>
                            <p class="text-gray-600 leading-relaxed text-sm">
                                <?php echo htmlspecialchars($facility['description']); ?>
                            </p>
                        </div>
                        
                        <?php if (!empty($pricing_options)): ?>
                        <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                            <h3 class="font-semibold text-purple-900 mb-3 flex items-center">
                                <i class="fas fa-tags mr-2"></i>Available Pricing Options
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($pricing_options as $option): ?>
                                <div class="flex items-center justify-between p-2 bg-white/60 rounded-lg border border-purple-200">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-tag text-purple-600 text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-medium text-purple-800 text-sm"><?php echo htmlspecialchars($option['name']); ?></div>
                                            <?php if ($option['description']): ?>
                                            <div class="text-xs text-purple-600"><?php echo htmlspecialchars($option['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-purple-700 text-sm">₱<?php echo number_format($option['price_per_hour'], 2); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3 text-xs text-purple-600 bg-white/40 px-2 py-1 rounded border border-purple-200">
                                <i class="fas fa-info-circle mr-1"></i>Select your preferred pricing option below
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
                        <h3 class="font-semibold text-blue-800 mb-3 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i>Booking Information
                        </h3>
                        <ul class="text-sm text-blue-700 space-y-2">
                            <li class="flex items-center">
                                <i class="fas fa-clock text-blue-500 mr-2 w-4"></i>
                                Operating hours: 8:00 AM - 10:00 PM
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-calendar-day text-blue-500 mr-2 w-4"></i>
                                Last booking ends at 9:30 PM
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-hourglass-half text-blue-500 mr-2 w-4"></i>
                                Minimum booking: 1 hour
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-calendar-check text-blue-500 mr-2 w-4"></i>
                                Maximum booking: 13.5 hours
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-credit-card text-blue-500 mr-2 w-4"></i>
                                Payment required within 24 hours
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-times-circle text-blue-500 mr-2 w-4"></i>
                                Cancellation allowed up to 2 hours before
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- Enhanced Reservation Form -->
            <div class="lg:col-span-2">
                <div class="enhanced-card card-hover p-6 reservation-form animate-slide-up" style="animation-delay: 0.1s;">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-calendar-plus text-blue-600 mr-3"></i>Book Your Reservation
                    </h2>
                    
                    <!-- Reservation Guidelines -->
                    <div class="mb-6 p-4 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg">
                        <div class="flex items-start">
                            <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3 flex-shrink-0">
                                <i class="fas fa-lightbulb text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-green-800 mb-2">Reservation Guidelines</h3>
                                <div class="text-xs text-green-700 space-y-1">
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2 w-3"></i>
                                        <span>Operating hours: 8:00 AM - 10:00 PM</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-check mr-2 w-3"></i>
                                        <span>Last booking ends at 9:30 PM</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-hourglass-half mr-2 w-3"></i>
                                        <span>Minimum booking: 1 hour</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-day mr-2 w-3"></i>
                                        <span>Maximum booking: 13.5 hours</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <form method="POST" action="reservation.php?facility_id=<?php echo $facility['id']; ?>" id="reservationForm" class="space-y-6">
                        <input type="hidden" name="action" value="create_reservation">
                        <!-- Enhanced Booking Type Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-clock mr-2"></i>Booking Type
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="booking_type" value="hourly" checked 
                                           class="sr-only booking-type-radio">
                                    <div class="booking-type-card border-2 border-gray-300 rounded-xl p-6 text-center hover:border-blue-500 transition duration-200 booking-type-option relative" data-type="hourly">
                                        <!-- Selection indicator -->
                                        <div class="absolute top-3 right-3 w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center opacity-0 transition-opacity duration-200 selection-indicator">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                                            <i class="fas fa-clock text-white text-2xl"></i>
                                        </div>
                                        <div class="font-semibold text-gray-800 text-lg mb-2">Hourly Booking</div>
                                        <div class="text-sm text-gray-600 mb-2">Perfect for short meetings</div>
                                        <div class="text-lg font-bold text-blue-600">₱<?php echo number_format($facility['hourly_rate'], 2); ?></div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <!-- Enhanced Date and Time Selection -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Booking Date (via Modal) -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="block text-sm font-medium text-gray-700"><i class="fas fa-calendar mr-2"></i>Booking Date</span>
                                    <button type="button" id="openCalendarModal" class="text-xs bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md shadow"><i class="fas fa-calendar-alt mr-1"></i>Open Calendar</button>
                                </div>
                                <div class="w-full border border-gray-200 rounded-lg px-4 py-3 bg-gray-50 text-gray-700 flex items-center justify-between">
                                    <span id="booking_date_display" class="text-sm font-medium">Please select a date from the calendar</span>
                                    <span class="ml-3 inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-700"><i class="fas fa-info-circle mr-1"></i>Modal calendar only</span>
                                </div>
                                <input type="hidden" id="booking_date" name="booking_date">
                            </div>
                            <!-- Duration (for display only) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-clock mr-2"></i>Booking Duration
                                </label>
                                <div class="form-input-enhanced w-full border border-gray-300 rounded-lg px-4 py-3 bg-gray-50 text-gray-600">
                                    <i class="fas fa-info-circle mr-2"></i>Select start and end times below
                                </div>
                            </div>
                        </div>
                        <!-- Availability Calendar Preview -->
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Availability Preview (Next 7 Days)
                            </label>
                            <div id="availabilityCalendar" class="flex flex-wrap gap-2 p-3 bg-gray-50 rounded-lg">
                                <!-- Calendar will be populated by JavaScript -->
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                <div class="flex items-center space-x-4">
                                    <div class="flex items-center">
                                        <div class="w-3 h-3 bg-green-500 rounded mr-1"></div>
                                        <span>Available</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-3 h-3 bg-red-500 rounded mr-1"></div>
                                        <span>Fully Booked</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-3 h-3 bg-yellow-500 rounded mr-1"></div>
                                        <span>Partially Booked</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-3 h-3 bg-purple-500 rounded mr-1"></div>
                                        <span>Selected Date</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Existing Bookings Display -->
                        <div id="existingBookingsSection" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-calendar-check mr-2"></i>Existing Bookings on Selected Date
                            </label>
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-3">
                                <div class="flex items-center text-blue-800">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <span class="text-sm">Note: Bookings ending at 9:30 PM still allow for later bookings (9:30 PM onwards)</span>
                                </div>
                            </div>
                            <div id="existingBookingsList" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                                <!-- Existing bookings will be populated here by JavaScript -->
                            </div>
                        </div>
                        <!-- Full Day Booking Alert -->
                        <div id="fullDayBookingAlert" class="hidden">
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-ban text-red-400 text-xl"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-red-800">
                                            Facility Fully Booked for This Day
                                        </h3>
                                        <div class="mt-2 text-sm text-red-700">
                                            <p>This facility has been reserved for the entire day (before 8:00 AM to after 9:30 PM). No additional bookings are available for this date.</p>
                                        </div>
                                        <div class="mt-3">
                                            <button type="button" onclick="showAlternativeDates()" class="bg-red-100 hover:bg-red-200 text-red-800 px-3 py-1 rounded-md text-sm font-medium transition-colors duration-200">
                                                <i class="fas fa-calendar-alt mr-1"></i>Check Other Available Dates
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Enhanced Time Selection -->
                        <div class="enhanced-time-selection">
                            <div class="mb-6 p-6 bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 border-2 border-blue-200 rounded-xl shadow-lg">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full flex items-center justify-center mr-4 shadow-lg">
                                            <i class="fas fa-info-circle text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-bold text-blue-800">Time Selection Guide</h3>
                                            <p class="text-sm text-blue-600">Smart booking with real-time validation</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="bg-white/60 px-4 py-2 rounded-lg shadow-inner">
                                            <div class="text-sm font-bold text-blue-800">Operating Hours</div>
                                            <div class="text-lg font-black text-blue-600">8:00 AM - 9:30 PM</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-3">
                                        <div class="flex items-center p-3 bg-white/40 rounded-lg">
                                            <i class="fas fa-mouse-pointer text-blue-500 mr-3"></i>
                                            <span class="text-sm font-medium text-blue-800">Select start and end times</span>
                                        </div>
                                        <div class="flex items-center p-3 bg-white/40 rounded-lg">
                                            <i class="fas fa-bolt text-yellow-500 mr-3"></i>
                                            <span class="text-sm font-medium text-blue-800">Quick time slots available</span>
                                        </div>
                                    </div>
                                    <div class="space-y-3">
                                        <div class="flex items-center p-3 bg-white/40 rounded-lg">
                                            <i class="fas fa-calendar-day text-purple-500 mr-3"></i>
                                            <span class="text-sm font-medium text-blue-800">Daily: 8:00 AM - 9:30 PM</span>
                                        </div>
                                        <div class="flex items-center p-3 bg-white/40 rounded-lg">
                                            <i class="fas fa-shield-check text-green-500 mr-3"></i>
                                            <span class="text-sm font-medium text-blue-800">Real-time conflict checking</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Enhanced Current Time Display -->
                            <div class="mb-6 p-4 sm:p-6 lg:p-8 bg-gradient-to-br from-green-50 via-emerald-50 to-teal-50 border-2 border-green-200 rounded-xl lg:rounded-2xl shadow-xl hover:shadow-2xl transition-all duration-300">
                                <div class="flex flex-col lg:flex-row items-center justify-between space-y-4 lg:space-y-0 current-time-layout">
                                    <div class="flex items-center current-time-container">
                                        <div class="w-12 h-12 sm:w-14 sm:h-14 lg:w-16 lg:h-16 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center mr-3 sm:mr-4 lg:mr-6 shadow-xl current-time-icon">
                                            <i class="fas fa-clock text-white text-lg sm:text-xl lg:text-2xl"></i>
                                        </div>
                                        <div class="current-time-text">
                                            <h3 class="text-lg sm:text-xl lg:text-2xl font-bold text-green-800 mb-1 sm:mb-2">Live Current Time</h3>
                                            <p class="text-sm sm:text-base text-green-600">Real-time updates every second</p>
                                        </div>
                                    </div>
                                    <div class="text-center lg:text-right w-full lg:w-auto current-time-display">
                                        <div id="currentTimeDisplay" class="text-2xl sm:text-3xl lg:text-4xl font-black text-green-700 bg-white/70 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 rounded-lg lg:rounded-xl shadow-inner border border-green-200 transition-all duration-300 hover:scale-105">
                                            Loading...
                                        </div>
                                        <div class="mt-2 sm:mt-3 text-sm sm:text-base text-green-700 bg-white/50 px-3 sm:px-4 py-1.5 sm:py-2 rounded-full border border-green-200">
                                            <span class="font-semibold">Today:</span> <span id="currentDateDisplay" class="font-medium">Loading...</span>
                                        </div>
                                        <!-- Mobile-friendly time indicator -->
                                        <div class="mt-2 sm:hidden text-xs text-green-600 bg-green-100 px-2 py-1 rounded-full border border-green-200">
                                            <i class="fas fa-mobile-alt mr-1"></i>Tap to refresh
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Enhanced Conflict Information Panel -->
                            <div class="mb-8 p-8 bg-gradient-to-br from-amber-50 via-orange-50 to-red-50 border-2 border-amber-200 rounded-2xl shadow-xl">
                                <div class="flex items-start">
                                    <div class="w-16 h-16 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center mr-6 shadow-xl flex-shrink-0">
                                        <i class="fas fa-shield-alt text-white text-2xl"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-2xl font-bold text-amber-800 mb-6">Smart Conflict Prevention</h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            <div class="space-y-4">
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-times-circle text-red-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">No Double-booking</span>
                                                </div>
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-clock text-blue-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">8:00 AM - 9:30 PM Only</span>
                                                </div>
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-hourglass-half text-purple-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">30 min - 13.5 hours</span>
                                                </div>
                                            </div>
                                            <div class="space-y-4">
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-ban text-red-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">Past Times Blocked</span>
                                                </div>
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-stopwatch text-green-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">15 min Advance Required</span>
                                                </div>
                                                <div class="flex items-center p-4 bg-white/50 rounded-xl border border-amber-200">
                                                    <i class="fas fa-bolt text-yellow-500 mr-4 text-xl"></i>
                                                    <span class="text-base font-semibold text-amber-800">Real-time Validation</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-clock mr-2"></i>Select Time
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Start Time -->
                                <div>
                                    <label for="start_time_input" class="block text-sm font-medium text-gray-600 mb-2">Start Time</label>
                                    <input type="time" id="start_time_input" name="start_time_input" 
                                           min="08:00" max="21:30" value="08:00"
                                           class="form-input-enhanced w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" data-time="08:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-time">8:00</button>
                                        <button type="button" data-time="09:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-time">9:00</button>
                                        <button type="button" data-time="10:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-time">10:00</button>
                                        <button type="button" data-time="13:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-time">1:00 PM</button>
                                    </div>
                                </div>
                                <!-- End Time -->
                                <div>
                                    <label for="end_time_input" class="block text-sm font-medium text-gray-600 mb-2">End Time</label>
                                    <input type="time" id="end_time_input" name="end_time_input" 
                                           min="08:00" max="21:30" value="09:00"
                                           class="form-input-enhanced w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" data-time="12:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-end">12:00</button>
                                        <button type="button" data-time="15:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-end">3:00 PM</button>
                                        <button type="button" data-time="18:00" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-end">6:00 PM</button>
                                        <button type="button" data-time="21:30" class="px-3 py-1 text-xs rounded bg-gray-100 hover:bg-blue-100 quick-end">9:30 PM</button>
                                </div>
                            </div>
                        </div>
                        </div>
                       
                        <!-- Hidden time inputs for form submission -->
                        <input type="hidden" id="start_time" name="start_time" required>
                        <input type="hidden" id="end_time" name="end_time" required>
                        <!-- Enhanced Pricing Options Selection -->
                        <?php if (!empty($pricing_options)): ?>
                        <div class="pricing-options-section mb-8">
                            <div class="mb-6 p-6 bg-gradient-to-br from-purple-50 via-indigo-50 to-blue-50 border-2 border-purple-200 rounded-xl shadow-lg">
                                <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center">
                                        <div class="w-12 h-12 bg-gradient-to-br from-purple-400 to-indigo-500 rounded-full flex items-center justify-center mr-4 shadow-lg">
                                            <i class="fas fa-tags text-white text-xl"></i>
                                    </div>
                                    <div>
                                            <h3 class="text-xl font-bold text-purple-800 mb-1">Choose Your Pricing Package</h3>
                                            <p class="text-sm text-purple-600">Select the option that best fits your needs</p>
                                    </div>
                                </div>
                                    <div class="text-right">
                                        <div class="bg-white/60 px-4 py-2 rounded-lg shadow-inner">
                                            <div class="text-sm font-bold text-purple-800">Available Options</div>
                                            <div class="text-lg font-black text-purple-600"><?php echo count($pricing_options); ?> Packages</div>
                                </div>
                            </div>
                            </div>
                                </div>
                            
                            <label class="block text-sm font-medium text-gray-700 mb-4">
                                <i class="fas fa-tag mr-2"></i>Select Pricing Packages (multiple allowed)
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php foreach ($pricing_options as $index => $option): ?>
                                <label class="relative cursor-pointer pricing-option-label group">
                                    <input type="checkbox" name="pricing_option_ids[]" value="<?php echo $option['id']; ?>" 
                                           class="pricing-option-checkbox absolute top-3 right-3 w-5 h-5 accent-purple-600 rounded border-2 border-purple-400 shadow bg-white cursor-pointer"
                                           data-price="<?php echo $option['price_per_hour']; ?>" data-name="<?php echo htmlspecialchars($option['name'], ENT_QUOTES); ?>">
                                    <div class="pricing-option-card border-2 border-gray-300 rounded-xl p-6 text-center hover:border-purple-500 transition-all duration-300 relative group-hover:shadow-lg group-hover:scale-105" data-option-id="<?php echo $option['id']; ?>">
                                        
                                        <!-- Package icon -->
                                        <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-all duration-300">
                                            <i class="fas fa-tag text-white text-2xl"></i>
                                        </div>
                                        
                                        <!-- Package name -->
                                        <div class="font-semibold text-gray-800 text-lg mb-2"><?php echo htmlspecialchars($option['name']); ?></div>
                                        
                                        <!-- Package description -->
                                        <div class="text-sm text-gray-600 mb-4 min-h-[2.5rem] flex items-center justify-center">
                                            <?php echo htmlspecialchars($option['description'] ?: 'Standard package with basic amenities'); ?>
                                        </div>
                                        
                                        <!-- Price -->
                                        <div class="text-xl font-bold text-purple-600 mb-2">₱<?php echo number_format($option['price_per_hour'], 2); ?></div>
                                        
                                        <!-- Features indicator -->
                                        <div class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                                            <i class="fas fa-star mr-1"></i>Premium Option
                                        </div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pricing comparison info -->
                            <div class="mt-6 p-6 bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl shadow-lg">
                                <div class="flex items-start">
                                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-4 flex-shrink-0">
                                        <i class="fas fa-info-circle text-blue-600"></i>
                                </div>
                                    <div>
                                        <h4 class="font-semibold text-blue-800 mb-2">Pricing Information</h4>
                                        <div class="text-sm text-blue-700 space-y-1">
                                            <div class="flex items-center">
                                                <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                                <span>Choose the package that best fits your event needs</span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                                <span>All packages include basic facility access</span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                                <span>Premium packages may include additional amenities</span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                                <span>Final cost calculated based on booking duration</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Default pricing info when no custom options -->
                        <div class="mb-8 p-6 bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl shadow-lg">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full flex items-center justify-center mr-4 shadow-lg">
                                    <i class="fas fa-tag text-white text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-blue-800 mb-1">Standard Pricing</h3>
                                    <p class="text-sm text-blue-600">This facility uses standard hourly rates</p>
                                </div>
                            </div>
                            <div class="mt-4 p-4 bg-white/60 rounded-lg border border-blue-200">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-700 mb-1">₱<?php echo number_format($facility['hourly_rate'], 2); ?></div>
                                    <div class="text-sm text-blue-600">Standard facility rate</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Purpose -->
                        <div>
                            <label for="purpose" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-bullseye mr-2"></i>Purpose of Reservation
                            </label>
                            <textarea id="purpose" name="purpose" rows="3" required
                                      placeholder="Please describe the purpose of your reservation..."
                                      class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                                      style="color: #000000 !important; font-weight: 500 !important;"></textarea>
                        </div>
                        
                        <!-- Phone Number -->
                        <div>
                            <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-phone mr-2"></i>Contact Phone Number
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 text-sm">+63</span>
                                </div>
                                <input type="tel" id="phone_number" name="phone_number" required
                                       placeholder="912 345 6789"
                                       pattern="[0-9]{10}"
                                       maxlength="10"
                                       class="form-input w-full border border-gray-300 rounded-lg pl-12 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                                       style="color: #000000 !important; font-weight: 500 !important;"
                                       oninput="formatPhoneNumber(this)">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <span class="text-gray-400 text-xs" id="phone_format_hint">Philippine format</span>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Enter your 10-digit mobile number (e.g., 912 345 6789)</p>
                        </div>
                        
                        <!-- Enhanced Cost Preview -->
                        <div id="costPreview" class="hidden mb-10">
                            <div class="cost-preview-card rounded-3xl p-10 bg-gradient-to-br from-green-50 via-emerald-50 to-teal-50 border-2 border-green-200 shadow-2xl">
                                <div class="flex items-center justify-between mb-8">
                                    <div class="flex items-center">
                                        <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center mr-6 shadow-xl">
                                            <i class="fas fa-calculator text-white text-2xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-3xl font-bold text-green-800 mb-2">Cost Preview</h3>
                                            <p class="text-base text-green-600">Your booking summary</p>
                                        </div>
                                    </div>
                                    <div class="text-base text-green-600 bg-gradient-to-r from-green-100 to-emerald-100 px-6 py-3 rounded-full font-semibold border border-green-200">
                                        <i class="fas fa-check mr-2"></i>Ready to Book
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                    <div class="space-y-5">
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Booking Type:</span>
                                            <span id="bookingTypeDisplay" class="font-bold text-gray-800 bg-white px-4 py-2 rounded-full text-base border border-green-200"></span>
                                        </div>
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Duration:</span>
                                            <span id="duration" class="font-bold text-gray-800 bg-white px-4 py-2 rounded-full text-base border border-green-200"></span>
                                        </div>
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Facility Cost:</span>
                                            <span id="facilityCostDisplay" class="font-bold text-gray-800 bg-white px-4 py-2 rounded-full text-base border border-green-200"></span>
                                        </div>
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Facility Rate:</span>
                                            <span id="rateDisplay" class="font-bold text-gray-800 bg-white px-4 py-2 rounded-full text-base border border-green-200"></span>
                                        </div>
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Package Cost:</span>
                                            <span id="packageCostDisplay" class="font-bold text-purple-700 bg-white px-4 py-2 rounded-full text-base border border-purple-200"></span>
                                        </div>
                                        <div class="flex justify-between items-center p-4 bg-white/50 rounded-xl border border-green-200">
                                            <span class="text-gray-700 font-semibold text-base">Package:</span>
                                            <span id="packageDisplay" class="font-bold text-purple-700 bg-white px-4 py-2 rounded-full text-base border border-purple-200"></span>
                                        </div>
                                        <div class="p-4 bg-white/50 rounded-xl border border-green-200">
                                            <div class="text-gray-700 font-semibold text-base mb-2">Selected Packages:</div>
                                            <div id="packageList" class="flex flex-wrap gap-2"></div>
                                        </div>
                                    </div>
                                    <div class="flex flex-col justify-center">
                                        <div class="text-center p-8 bg-white/70 rounded-2xl border-2 border-green-200 shadow-lg">
                                            <div class="text-base text-gray-600 mb-3 font-medium">Total Amount</div>
                                            <div id="totalCost" class="text-5xl font-black text-green-600 mb-2"></div>
                                            <div class="text-sm text-gray-500">Inclusive of all fees</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Enhanced Submit Button Section -->
                        <div class="mb-8 p-6 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 border-2 border-blue-200 rounded-xl shadow-lg">
                            <div class="flex items-center text-blue-800 mb-4">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full flex items-center justify-center mr-4 shadow-lg">
                                    <i class="fas fa-info-circle text-white text-lg"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-blue-800">Booking Agreement</h4>
                                    <p class="text-sm text-blue-600">By clicking "Book Reservation", you agree to our booking terms and payment policy.</p>
                                </div>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row gap-6">
                                <button type="submit" id="submitBtn" 
                                        class="flex-1 group bg-gradient-to-br from-green-400 via-green-500 to-green-600 hover:from-green-500 hover:to-green-700 text-white py-5 px-8 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-offset-2 shadow-2xl hover:shadow-3xl">
                                    <div class="flex items-center justify-center">
                                        <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center mr-3 group-hover:bg-white/30 transition-colors">
                                            <i class="fas fa-calendar-check text-white text-lg"></i>
                                        </div>
                                        <span>Book Reservation</span>
                                    </div>
                                </button>
                                <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" 
                                   class="group bg-gradient-to-br from-gray-400 via-gray-500 to-gray-600 hover:from-gray-500 hover:to-gray-700 text-white py-5 px-8 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 text-center shadow-2xl hover:shadow-3xl">
                                    <div class="flex items-center justify-center">
                                        <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center mr-3 group-hover:bg-white/30 transition-colors">
                                            <i class="fas fa-arrow-left text-white text-lg"></i>
                                        </div>
                                        <span>Back to Details</span>
                                    </div>
                                </a>
                            </div>
                            
                            <div class="mt-6 p-4 bg-white/40 rounded-xl border border-blue-200">
                                <div class="flex items-center justify-center text-blue-700">
                                    <div class="w-8 h-8 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full flex items-center justify-center mr-3 shadow-lg">
                                        <i class="fas fa-shield-alt text-white"></i>
                                    </div>
                                    <span class="text-sm font-medium">Your reservation will be confirmed after payment verification</span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Enhanced Compact Calendar Modal -->
        <div id="calendarModal" class="fixed inset-0 bg-black bg-opacity-60 z-50 hidden backdrop-blur-sm">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[80vh] overflow-hidden transform transition-all duration-300 scale-95 opacity-0 flex flex-col" id="calendarModalContent">
                    <!-- Compact Header -->
                    <div class="p-4 border-b bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 text-white">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-calendar-alt text-white text-sm"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold">Select Booking Date</h3>
                                    <p class="text-blue-100 text-xs">Click any date to view availability</p>
                                </div>
                            </div>
                            <button id="closeCalendarModal" class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-all duration-200 hover:scale-110">
                                <i class="fas fa-times text-white text-sm"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-0 h-full flex-1 overflow-hidden">
                        <!-- Compact Calendar Section -->
                        <div class="p-4 overflow-y-auto bg-gradient-to-br from-gray-50 to-blue-50">
                            <div class="flex items-center justify-center mb-4">
                                <div id="calMonthLabel" class="text-lg font-bold text-gray-800 bg-white px-4 py-2 rounded-lg shadow-sm border border-gray-200"></div>
                            </div>
                            
                            <!-- Compact Day Headers -->
                            <div class="grid grid-cols-7 gap-2 mb-3">
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Sun</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Mon</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Tue</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Wed</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Thu</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Fri</div>
                                <div class="text-center py-2 text-xs font-bold text-gray-600 bg-white rounded shadow-sm border border-gray-200">Sat</div>
                            </div>
                            
                            <!-- Compact Calendar Grid -->
                            <div id="monthlyCalendar" class="grid grid-cols-7 gap-2"></div>
                        </div>
                        
                        <!-- Enhanced Day Details Panel -->
                        <div class="p-4 border-l bg-gradient-to-br from-indigo-50 to-purple-50 overflow-y-auto">
                            <div class="mb-4">
                                <h4 class="text-lg font-bold text-gray-800 mb-2 flex items-center">
                                    <i class="fas fa-info-circle mr-2 text-indigo-600"></i>
                                    <span id="selectedDateTitle">Select a Date</span>
                                </h4>
                                <p class="text-xs text-gray-600" id="selectedDateSubtitle">Click any date to view availability and reservations</p>
                            </div>
                            
                            <div id="dayDetails" class="space-y-3">
                                <div class="text-center py-6 text-gray-500 bg-white rounded-lg border border-gray-200 shadow-sm">
                                    <i class="fas fa-calendar-day text-3xl text-gray-300 mb-2"></i>
                                    <p class="text-sm font-medium">Select a day to view details</p>
                                    <p class="text-xs">See reservations, availability, and ongoing usage</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Footer with confirm button -->
                    <div class="p-4 border-t bg-gray-50 flex justify-end">
                        <!-- Confirm Button -->
                        <button id="confirmCalendarSelection" class="px-6 py-2 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg font-bold transition-all duration-200 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" disabled>
                            <i class="fas fa-check mr-2"></i>Select This Day
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Floating Action Button -->
    <div class="floating-action">
        <a href="my_reservations.php" 
           class="w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:scale-110">
            <i class="fas fa-calendar-alt text-xl"></i>
        </a>
    </div>
    <script>
        // Hide loading overlay when page is ready
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                }, 300);
            }
        });
        
        // Cache busting comment - v2.7
        
        // Phone number formatting function for Philippine numbers
        function formatPhoneNumber(input) {
            // Remove all non-numeric characters
            let value = input.value.replace(/\D/g, '');
            
            // Limit to 10 digits
            if (value.length > 10) {
                value = value.substring(0, 10);
            }
            
            // Update the input value
            input.value = value;
            
            // Update the format hint
            const hint = document.getElementById('phone_format_hint');
            if (hint) {
                if (value.length === 10) {
                    hint.textContent = 'Valid format';
                    hint.className = 'text-green-500 text-xs';
                } else if (value.length > 0) {
                    hint.textContent = `${value.length}/10 digits`;
                    hint.className = 'text-yellow-500 text-xs';
                } else {
                    hint.textContent = 'Philippine format';
                    hint.className = 'text-gray-400 text-xs';
                }
            }
            
            // Validate Philippine mobile prefixes
            if (value.length > 0) {
                const firstDigit = value.charAt(0);
                const validPrefixes = ['9', '8', '7', '6', '5', '4', '3', '2'];
                
                if (!validPrefixes.includes(firstDigit)) {
                    input.style.borderColor = '#ef4444';
                    input.style.backgroundColor = '#fef2f2';
                } else {
                    input.style.borderColor = '';
                    input.style.backgroundColor = '';
                }
            }
            
            // Ensure text color is always black and visible
            input.style.color = '#000000';
            input.style.fontWeight = '500';
        }
        
        // Global variables
         let bookingDateInput, startTimeInputField, endTimeInputField;
         let timeSlotsContainer, startTimeInput, endTimeInput, costPreview;
         let durationSpan, totalCostSpan, submitBtn, rateDisplay, bookingTypeDisplay;
         let facilityHourlyRate, existingReservations;
        let selectedPackageFlatCost = 0;
        let selectedPricingOptions = [];
         
         // Pricing options handling
         let selectedPricingOption = null;
         let selectedPricingOptionName = '';

        // Enhanced calendar renderer with beautiful styling
        function renderCalendarMonth(baseDate) {
            const calMonthLabelEl = document.getElementById('calMonthLabel');
            const monthlyCalendarEl = document.getElementById('monthlyCalendar');
            const confirmBtn = document.getElementById('confirmCalendarSelection');
            const selectedDateLabelEl = document.getElementById('selectedDateLabel');
            if (!monthlyCalendarEl || !calMonthLabelEl) return;
            const date = baseDate ? new Date(baseDate) : new Date();
            monthlyCalendarEl.innerHTML = '';
            const year = date.getFullYear();
            const month = date.getMonth();
            calMonthLabelEl.textContent = date.toLocaleString('en-US', { month:'long', year:'numeric' });
            const first = new Date(year, month, 1);
            const startWeekday = first.getDay();
            const daysInMonth = new Date(year, month+1, 0).getDate();
            
            // Add empty cells for days before month starts
            for (let i=0;i<startWeekday;i++){ 
                const pad = document.createElement('div'); 
                pad.className = 'h-16'; // Maintain grid alignment
                monthlyCalendarEl.appendChild(pad); 
            }
            
            function fmt(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
            const today = fmt(new Date());
            
            for (let d=1; d<=daysInMonth; d++) {
                const cell = document.createElement('button');
                const cellDate = new Date(year, month, d);
                const ymd = fmt(cellDate);
                const isToday = ymd === today;
                const isPast = cellDate < new Date().setHours(0,0,0,0);
                
                // Enhanced styling with animations
                cell.className = `h-16 rounded-xl border-2 text-sm font-semibold transition-all duration-200 transform hover:scale-105 hover:shadow-lg ${
                    isToday ? 'bg-gradient-to-br from-blue-500 to-indigo-600 text-white border-blue-400 shadow-lg' :
                    isPast ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed' :
                    'bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700'
                }`;
                
                cell.textContent = d;
                
                // Add day number styling
                const daySpan = document.createElement('div');
                daySpan.className = 'text-lg font-bold';
                daySpan.textContent = d;
                cell.innerHTML = '';
                cell.appendChild(daySpan);
                
                // Add hover effects and click handling
                if (!isPast) {
                    cell.addEventListener('click', () => {
                        // Remove previous selection
                        document.querySelectorAll('#monthlyCalendar button').forEach(btn => {
                            if (!btn.classList.contains('cursor-not-allowed')) {
                                btn.classList.remove('ring-4', 'ring-purple-400', 'bg-purple-100', 'border-purple-400');
                                btn.classList.add('bg-white', 'border-gray-200');
                            }
                        });
                        
                        // Add selection styling
                        cell.classList.add('ring-4', 'ring-purple-400', 'bg-purple-100', 'border-purple-400');
                        cell.classList.remove('bg-white', 'border-gray-200');
                        
                        // Update labels
                        if (selectedDateLabelEl) {
                            selectedDateLabelEl.textContent = cellDate.toLocaleDateString('en-US', { 
                                weekday: 'long', 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            });
                        }
                        if (confirmBtn) confirmBtn.disabled = false;
                        
                        // Render day details for popup
                        const dayDetailsEl = document.getElementById('dayDetails');
                        console.log('Day clicked, calling renderDayDetailsForPopup with:', ymd, dayDetailsEl);
                        if (typeof renderDayDetailsForPopup === 'function') { 
                            renderDayDetailsForPopup(ymd, dayDetailsEl); 
                        } else {
                            console.error('renderDayDetailsForPopup function not found');
                        }
                    });
                }
                
                monthlyCalendarEl.appendChild(cell);
            }
        }
         
         // Initialize pricing options
         function initializePricingOptions() {
             const pricingRadios = document.querySelectorAll('.pricing-option-radio');
             const pricingCards = document.querySelectorAll('.pricing-option-card');
             
             pricingRadios.forEach(radio => {
                 radio.addEventListener('change', function() {
                     // Update visual selection
                     pricingCards.forEach(card => {
                         card.classList.remove('border-purple-500', 'bg-purple-50', 'shadow-lg', 'scale-105');
                         card.classList.add('border-gray-300');
                         const indicator = card.querySelector('.pricing-selection-indicator');
                         if (indicator) {
                             indicator.style.opacity = '0';
                         }
                         // Reset icon shadow
                         const icon = card.querySelector('.w-16.h-16');
                         if (icon) {
                             icon.classList.remove('shadow-xl');
                             icon.classList.add('shadow-lg');
                         }
                     });
                     
                     // Highlight selected card
                     const selectedCard = document.querySelector(`[data-option-id="${this.value}"]`);
                     if (selectedCard) {
                         selectedCard.classList.remove('border-gray-300');
                         selectedCard.classList.add('border-purple-500', 'bg-purple-50', 'shadow-lg', 'scale-105');
                         const indicator = selectedCard.querySelector('.pricing-selection-indicator');
                         if (indicator) {
                             indicator.style.opacity = '1';
                         }
                         // Enhance icon shadow
                         const icon = selectedCard.querySelector('.w-16.h-16');
                         if (icon) {
                             icon.classList.remove('shadow-lg');
                             icon.classList.add('shadow-xl');
                         }
                         
                         // Add selection animation
                         selectedCard.style.transform = 'scale(1.05)';
                         setTimeout(() => {
                             selectedCard.style.transform = 'scale(1.05)';
                         }, 200);
                     }
                     
                     // Update selected pricing option
                     selectedPricingOption = {
                         id: this.value,
                         price: parseFloat(this.dataset.price)
                     };
                     selectedPricingOptionName = this.dataset.name || '';
                     selectedPackageFlatCost = parseFloat(this.dataset.price) || 0;
                     
                     // Update cost calculation
                     updateCostCalculation();
                 });
             });
             
             // Set initial selection
            // Backward compatibility: if radios exist, keep behavior; else use checkboxes
             const initialRadio = document.querySelector('.pricing-option-radio:checked');
             if (initialRadio) {
                selectedPricingOptionName = initialRadio.dataset.name || '';
                 initialRadio.dispatchEvent(new Event('change'));
                return;
            }
            const pricingCheckboxes = document.querySelectorAll('.pricing-option-checkbox');
            if (pricingCheckboxes.length) {
                // Attach handlers for checkboxes
                function recalcSelected() {
                    selectedPackageFlatCost = 0;
                    selectedPricingOptions = [];
                    const names = [];
                    pricingCheckboxes.forEach(cb => {
                        const card = document.querySelector(`[data-option-id="${cb.value}"]`);
                        const indicator = card ? card.querySelector('.pricing-selection-indicator') : null;
                        const icon = card ? card.querySelector('.w-16.h-16') : null;
                        if (cb.checked) {
                            const price = parseFloat(cb.dataset.price) || 0;
                            const name = cb.dataset.name || '';
                            selectedPackageFlatCost += price;
                            names.push(name);
                            selectedPricingOptions.push({ id: cb.value, name, price });
                            if (card) card.classList.add('border-purple-500', 'bg-purple-50', 'shadow-lg', 'scale-105');
                            if (indicator) indicator.style.opacity = '1';
                            if (icon) { icon.classList.remove('shadow-lg'); icon.classList.add('shadow-xl'); }
                        } else {
                            if (card) card.classList.remove('border-purple-500', 'bg-purple-50', 'shadow-lg', 'scale-105');
                            if (indicator) indicator.style.opacity = '0';
                            if (icon) { icon.classList.remove('shadow-xl'); icon.classList.add('shadow-lg'); }
                        }
                    });
                    const packageDisplay = document.getElementById('packageDisplay');
                    if (packageDisplay) packageDisplay.textContent = names.length ? names.join(', ') : 'Standard rate';
                }
                pricingCheckboxes.forEach(cb => cb.addEventListener('change', () => { recalcSelected(); updateCostCalculation(); }));
                recalcSelected();
                updateCostCalculation();
            }
         }
         
        // Update cost calculation with selected pricing option(s)
         function updateCostCalculation() {
            if (!startTimeInput || !endTimeInput) {
                 return;
             }
             
             const startTime = startTimeInput.value;
             const endTime = endTimeInput.value;
             
             if (!startTime || !endTime) {
                 return;
             }
             
            // startTime/endTime are full ISO-like datetime strings (YYYY-MM-DD HH:MM:SS)
            const start = new Date(startTime.replace(' ', 'T'));
            const end = new Date(endTime.replace(' ', 'T'));
            if (isNaN(start.getTime()) || isNaN(end.getTime())) {
                return;
            }
             
             if (end <= start) {
                 return;
             }
             
             const durationMs = end - start;
             const durationHours = durationMs / (1000 * 60 * 60);
             // Facility cost is based on facilityHourlyRate; package is flat add-on
             const facilityCost = durationHours * (facilityHourlyRate || 0);
             const packageCost = selectedPackageFlatCost || 0;
             const totalCost = facilityCost + packageCost;
             
             // Update display
             if (durationSpan) {
                 durationSpan.textContent = durationHours.toFixed(1);
             }
             if (totalCostSpan) {
                 totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
             }
             if (rateDisplay) {
                 rateDisplay.textContent = `₱${(facilityHourlyRate || 0).toFixed(2)}`;
             }
            const facilityCostDisplay = document.getElementById('facilityCostDisplay');
            if (facilityCostDisplay) {
                facilityCostDisplay.textContent = `₱${facilityCost.toFixed(2)}`;
            }
            const packageDisplay = document.getElementById('packageDisplay');
            if (packageDisplay) {
                if (selectedPricingOptions.length > 0) {
                    packageDisplay.textContent = selectedPricingOptions.map(p => p.name).join(', ');
                } else {
                    packageDisplay.textContent = 'Standard rate';
                }
            }
            const packageCostDisplay = document.getElementById('packageCostDisplay');
            if (packageCostDisplay) {
                const hasPkgs = selectedPricingOptions.length > 0;
                packageCostDisplay.textContent = hasPkgs ? `₱${packageCost.toFixed(2)}` : '₱0.00';
            }
            const packageList = document.getElementById('packageList');
            if (packageList) {
                packageList.innerHTML = '';
                if (selectedPricingOptions.length > 0) {
                    selectedPricingOptions.forEach(p => {
                        const pill = document.createElement('span');
                        pill.className = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200';
                        pill.textContent = `${p.name} (₱${(p.price || 0).toFixed(2)})`;
                        packageList.appendChild(pill);
                    });
                } else {
                    const none = document.createElement('span');
                    none.className = 'text-xs text-gray-500';
                    none.textContent = 'None';
                    packageList.appendChild(none);
                }
             }
         }
         
         // Helper function to format date consistently (local timezone)
         function formatDateToLocal(date) {
             return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
         }
         // Function to show existing bookings for selected date
         function showExistingBookings() {
             if (!bookingDateInput) {
                 console.error('bookingDateInput not initialized in showExistingBookings');
                 return;
             }
             const selectedDate = bookingDateInput.value;
             const existingBookingsSection = document.getElementById('existingBookingsSection');
             const existingBookingsList = document.getElementById('existingBookingsList');
             const fullDayBookingAlert = document.getElementById('fullDayBookingAlert');
             if (!selectedDate) {
                 existingBookingsSection.classList.add('hidden');
                 fullDayBookingAlert.classList.add('hidden');
                 return;
             }
             // Filter existing reservations for the selected date
             const dateBookings = existingReservations.filter(reservation => {
                 const reservationDate = formatDateToLocal(new Date(reservation.start_time));
                 return reservationDate === selectedDate && reservation.status !== 'cancelled';
             });
             // Check if there's a full day booking (covers 8:00 AM - 9:30 PM or longer)
             const hasFullDayBooking = dateBookings.some(booking => {
                 const startTime = new Date(booking.start_time).toTimeString().slice(0, 5);
                 const endTime = new Date(booking.end_time).toTimeString().slice(0, 5);
                 // Check if booking covers the full day (8:00 AM - 9:30 PM or longer)
                 // A booking is considered "full day" if it starts before 8:00 AM AND ends after 9:30 PM
                 // This allows for bookings that end exactly at 9:30 PM to still allow later bookings
                 return startTime < '08:00' && endTime > '21:30';
             });
             if (hasFullDayBooking) {
                 // Show full day booking alert and hide existing bookings section
                 existingBookingsSection.classList.add('hidden');
                 fullDayBookingAlert.classList.remove('hidden');
                 return;
             }
             if (dateBookings.length === 0) {
                 existingBookingsSection.classList.add('hidden');
                 fullDayBookingAlert.classList.add('hidden');
                 return;
             }
             // Show existing bookings section and hide full day alert
             existingBookingsSection.classList.remove('hidden');
             fullDayBookingAlert.classList.add('hidden');
             // Generate HTML for existing bookings
             let bookingsHTML = '';
             dateBookings.forEach(booking => {
                 const startTime = new Date(booking.start_time).toTimeString().slice(0, 5);
                 const endTime = new Date(booking.end_time).toTimeString().slice(0, 5);
                 const startDate = new Date(booking.start_time).toDateString();
                 const endDate = new Date(booking.end_time).toDateString();
                 let timeDisplay = `${startTime} - ${endTime}`;
                 if (startDate !== endDate) {
                     timeDisplay = `${startDate} ${startTime} - ${endDate} ${endTime}`;
                 }
                 // Check if this is a long booking (more than 4 hours)
                 const startDateTime = new Date(booking.start_time);
                 const endDateTime = new Date(booking.end_time);
                 const durationHours = (endDateTime - startDateTime) / (1000 * 60 * 60);
                 const isLongBooking = durationHours >= 4;
                 bookingsHTML += `
                     <div class="flex items-center justify-between py-2 border-b border-yellow-200 last:border-b-0">
                         <div class="flex items-center">
                             <div class="w-3 h-3 ${isLongBooking ? 'bg-orange-500' : 'bg-red-500'} rounded-full mr-3"></div>
                             <div>
                                 <span class="font-medium text-yellow-800">${timeDisplay}</span>
                                 <span class="text-sm text-yellow-600 ml-2">(${booking.status})</span>
                                 ${isLongBooking ? '<span class="text-xs bg-orange-100 text-orange-800 px-2 py-1 rounded ml-2">Extended Booking</span>' : ''}
                             </div>
                         </div>
                     </div>
                 `;
             });
             existingBookingsList.innerHTML = bookingsHTML;
         }
         // Function to show alternative available dates
         function showAlternativeDates() {
             const currentDate = new Date();
             const selectedDate = new Date(bookingDateInput.value);
             let availableDates = [];
             // Check next 30 days for availability
             for (let i = 1; i <= 30; i++) {
                 const checkDate = new Date(currentDate);
                 checkDate.setDate(currentDate.getDate() + i);
                 const dateString = formatDateToLocal(checkDate);
                 const dateBookings = existingReservations.filter(reservation => {
                     const reservationDate = formatDateToLocal(new Date(reservation.start_time));
                     return reservationDate === dateString && reservation.status !== 'cancelled';
                 });
                 const hasFullDayBooking = dateBookings.some(booking => {
                     const startTime = new Date(booking.start_time).toTimeString().slice(0, 5);
                     const endTime = new Date(booking.end_time).toTimeString().slice(0, 5);
                     return startTime <= '08:00' && endTime >= '21:30';
                 });
                 if (!hasFullDayBooking) {
                     availableDates.push({
                         date: dateString,
                         day: checkDate.toLocaleDateString('en-US', { weekday: 'short' }),
                         available: true
                     });
                 }
                 if (availableDates.length >= 5) break; // Show max 5 available dates
             }
             if (availableDates.length === 0) {
                 if (window.ModalSystem) {
                     window.ModalSystem.alert('No available dates found in the next 30 days. Please try a different facility or contact the administrator.', 'No Availability', 'warning');
                 } else {
                     alert('No available dates found in the next 30 days. Please try a different facility or contact the administrator.');
                 }
                 return;
             }
             // Create modal with available dates
             const modalHtml = `
                 <div id="alternative-dates-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                     <div class="bg-white rounded-lg shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0" id="alternative-dates-content">
                         <div class="p-6">
                             <div class="flex items-center justify-between mb-4">
                                 <h3 class="text-lg font-semibold text-gray-900">Available Alternative Dates</h3>
                                 <button onclick="closeAlternativeDatesModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                             </div>
                             <div class="space-y-3">
                                 ${availableDates.map(date => `
                                     <button onclick="selectAlternativeDate('${date.date}')" 
                                             class="w-full p-3 border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 transition-all duration-200 text-left">
                                         <div class="flex items-center justify-between">
                                             <div>
                                                 <div class="font-medium text-gray-900">${new Date(date.date).toLocaleDateString('en-US', { 
                                                     weekday: 'long', 
                                                     year: 'numeric', 
                                                     month: 'long', 
                                                     day: 'numeric' 
                                                 })}</div>
                                                 <div class="text-sm text-gray-500">${date.day}</div>
                                             </div>
                                             <i class="fas fa-chevron-right text-gray-400"></i>
                                         </div>
                                     </button>
                                 `).join('')}
                             </div>
                             <div class="mt-4 pt-4 border-t border-gray-200">
                                 <button onclick="closeAlternativeDatesModal()" 
                                         class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition duration-200">
                                     Cancel
                                 </button>
                             </div>
                         </div>
                     </div>
                 </div>
             `;
             // Remove existing modal if any
             const existingModal = document.getElementById('alternative-dates-modal');
             if (existingModal) {
                 existingModal.remove();
             }
             // Add modal to page
             document.body.insertAdjacentHTML('beforeend', modalHtml);
             // Animate modal in
             setTimeout(() => {
                 const modalContent = document.getElementById('alternative-dates-content');
                 modalContent.classList.remove('scale-95', 'opacity-0');
                 modalContent.classList.add('scale-100', 'opacity-100');
             }, 10);
         }
         // Function to select alternative date
         function selectAlternativeDate(dateString) {
             bookingDateInput.value = dateString;
             generateTimeSlots();
             showExistingBookings();
             updateCostPreview();
             closeAlternativeDatesModal();
         }
         // Function to close alternative dates modal
         function closeAlternativeDatesModal() {
             const modal = document.getElementById('alternative-dates-modal');
             if (modal) {
                 const modalContent = document.getElementById('alternative-dates-content');
                 modalContent.classList.add('scale-95', 'opacity-0');
                 modalContent.classList.remove('scale-100', 'opacity-100');
                 setTimeout(() => {
                     modal.remove();
                 }, 300);
             }
         }
         // Function to generate availability calendar
         function generateAvailabilityCalendar() {
             const calendarContainer = document.getElementById('availabilityCalendar');
             if (!calendarContainer) return;
             const today = new Date();
             let calendarHTML = '';
             
            // Generate next 7 days as compact pills
             for (let i = 0; i < 7; i++) {
                 const date = new Date(today);
                 date.setDate(today.getDate() + i);
                 const dateString = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                 const dayNumber = date.getDate();
                 
                 // Check availability for this date
                 const dateBookings = existingReservations.filter(reservation => {
                     const reservationDate = formatDateToLocal(new Date(reservation.start_time));
                     return reservationDate === dateString && reservation.status !== 'cancelled';
                 });
                 
                 let availabilityClass = 'bg-green-100 text-green-800'; // Available
                 let availabilityText = 'Available';
                 
                 if (dateBookings.length > 0) {
                     const hasFullDayBooking = dateBookings.some(booking => {
                         const startTime = new Date(booking.start_time).toTimeString().slice(0, 5);
                         const endTime = new Date(booking.end_time).toTimeString().slice(0, 5);
                         return startTime <= '08:00' && endTime >= '21:30';
                     });
                     if (hasFullDayBooking) {
                         availabilityClass = 'bg-red-100 text-red-800'; // Fully booked
                         availabilityText = 'Fully Booked';
                     } else {
                         availabilityClass = 'bg-yellow-100 text-yellow-800'; // Partially booked
                         availabilityText = 'Partially Booked';
                     }
                 }
                 
                 // Check if this is today
                 const isToday = i === 0;
                 const todayClass = isToday ? 'ring-2 ring-blue-500' : '';
                 
                 // Check if this date is currently selected
                 const isSelected = bookingDateInput.value === dateString;
                const selectedClass = isSelected ? 'ring-4 ring-purple-500 shadow-lg bg-gradient-to-br from-purple-50 to-purple-100' : '';
                 
                 calendarHTML += `
                    <button class="px-3 py-2 rounded-full text-xs font-semibold ${availabilityClass} ${todayClass} ${selectedClass} cursor-pointer hover:opacity-80 transition" 
                          title="Click to select ${date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} - ${availabilityText}"
                          onclick="selectDateFromCalendar('${dateString}')"
                          data-date="${dateString}"
                         role="button" tabindex="0">
                        ${date.toLocaleDateString('en-US', { weekday: 'short' })} ${dayNumber}
                    </button>
                 `;
             }
             
             calendarContainer.innerHTML = calendarHTML;
         }
         // Function to select date from calendar
         function selectDateFromCalendar(dateString) {
             // Add visual feedback for the clicked date
             const clickedDateElement = document.querySelector(`[data-date="${dateString}"]`);
             if (clickedDateElement) {
                 clickedDateElement.style.transform = 'scale(0.95)';
                 setTimeout(() => {
                     clickedDateElement.style.transform = '';
                 }, 150);
             }
             
             bookingDateInput.value = dateString;
             
             // Regenerate calendar to show the selected date highlighting
             generateAvailabilityCalendar();
             
             generateTimeSlots();
             showExistingBookings();
             updateCostPreview();
         }
         
         // Function to update calendar highlighting when date inputs change manually
         function updateCalendarHighlighting() {
             generateAvailabilityCalendar();
             
             // Clear time slot selections when date changes
             clearTimeSlotSelections();
             
             // Reset the description
             resetTimeDescription();
         }
         
        // Function to generate time slots
        function generateTimeSlots() {
            if (!bookingDateInput) {
                console.error('bookingDateInput not initialized in generateTimeSlots');
                return;
            }
            const selectedDate = bookingDateInput.value;
            if (!selectedDate) return;
            
            // Get the time slots container
            const timeSlotsContainer = document.getElementById('timeSlots');
            if (!timeSlotsContainer) return;
            
            timeSlotsContainer.innerHTML = '';
            const slots = [];
            
            // Check if there's a full day booking for this date
            const dateBookings = existingReservations.filter(reservation => {
                const reservationDate = formatDateToLocal(new Date(reservation.start_time));
                return reservationDate === selectedDate && reservation.status !== 'cancelled';
            });
            
            // Calendar modal logic
            const calendarModal = document.getElementById('calendarModal');
            const openCalBtn = document.getElementById('openCalendarModal');
            const closeCalBtn = document.getElementById('closeCalendarModal');
            const confirmCalBtn = document.getElementById('confirmCalendarSelection');
            const calMonthLabel = document.getElementById('calMonthLabel');
            const monthlyCalendar = document.getElementById('monthlyCalendar');
            const selectedDateLabel = document.getElementById('selectedDateLabel');
            const dayDetails = document.getElementById('dayDetails');
            const bookingDateInputEl = document.getElementById('booking_date');
            let calCurrent = new Date();
            let calSelected = null;

            function formatYMD(d) { return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }

            function renderMonth(date) {
                if (!monthlyCalendar) return;
                monthlyCalendar.innerHTML = '';
                const year = date.getFullYear();
                const month = date.getMonth();
                calMonthLabel.textContent = date.toLocaleString('en-US', { month:'long', year:'numeric' });
                const first = new Date(year, month, 1);
                const startWeekday = first.getDay();
                const daysInMonth = new Date(year, month+1, 0).getDate();
                for (let i=0;i<startWeekday;i++){ const pad = document.createElement('div'); monthlyCalendar.appendChild(pad); }
                for (let d=1; d<=daysInMonth; d++) {
                    const cell = document.createElement('button');
                    cell.className = 'p-3 rounded-lg border text-sm text-gray-700 hover:bg-blue-50 hover:border-blue-300 transition';
                    cell.textContent = d;
                    const cellDate = new Date(year, month, d);
                    const ymd = formatYMD(cellDate);
                    // Today marker
                    if (formatYMD(new Date()) === ymd) {
                        cell.classList.add('border-blue-400');
                    } else {
                        cell.classList.add('border-gray-200');
                    }
                    cell.addEventListener('click', () => {
                        calSelected = cellDate;
                        
                        // Create date display
                        const dateDisplay = cellDate.toLocaleDateString('en-US', { 
                            weekday: 'long', 
                            year: 'numeric', 
                            month: 'long', 
                            day: 'numeric' 
                        });
                        
                        // Update the selected date label
                        if (selectedDateLabelEl) {
                            selectedDateLabelEl.textContent = dateDisplay;
                        }
                        
                        // Show day details popup first
                        if (typeof showDayDetailsPopup === 'function') {
                            showDayDetailsPopup(ymd, dateDisplay);
                        } else {
                            // Fallback: direct selection
                            confirmDaySelection(ymd, dateDisplay);
                        }
                    });
                    monthlyCalendar.appendChild(cell);
                }
            }

            // Function to confirm day selection (fallback)
            function confirmDaySelection(ymd, dateDisplay) {
                // Update the booking form
                const bookingDateInputEl = document.getElementById('booking_date');
                if (bookingDateInputEl) {
                    bookingDateInputEl.value = ymd;
                }
                
                const bookingDateDisplay = document.getElementById('booking_date_display');
                if (bookingDateDisplay) {
                    bookingDateDisplay.textContent = dateDisplay;
                }
                
                // Close the calendar modal
                const calendarModal = document.getElementById('calendarModal');
                if (calendarModal) {
                    calendarModal.classList.add('hidden');
                }
                
                // Regenerate calendar and time slots
                if (typeof generateAvailabilityCalendar === 'function') {
                    generateAvailabilityCalendar();
                }
                if (typeof generateTimeSlots === 'function') {
                    generateTimeSlots();
                }
                if (typeof showExistingBookings === 'function') {
                    showExistingBookings();
                }
                if (typeof updateCostPreview === 'function') {
                    updateCostPreview();
                }
                
                // Show success notification
                if (typeof showSuccessNotification === 'function') {
                    showSuccessNotification(`Selected ${dateDisplay}`);
                }
            }
            
            // Function to show day details popup
            function showDayDetailsPopup(ymd, dateDisplay) {
                // Create popup overlay
                const popupOverlay = document.createElement('div');
                popupOverlay.className = 'fixed inset-0 bg-black bg-opacity-50 z-60 flex items-center justify-center p-4';
                popupOverlay.id = 'dayDetailsPopup';
                
                // Create popup content
                const popupContent = document.createElement('div');
                popupContent.className = 'bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden transform transition-all duration-300 scale-95 opacity-0';
                
                // Create header
                const header = document.createElement('div');
                header.className = 'p-4 border-b bg-gradient-to-r from-indigo-500 to-purple-600 text-white';
                header.innerHTML = `
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center mr-3">
                                <i class="fas fa-calendar-day text-white text-sm"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold">${dateDisplay}</h3>
                                <p class="text-indigo-100 text-xs">Reservation details and availability</p>
                            </div>
                        </div>
                        <button id="closeDayDetailsPopup" class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-all duration-200 hover:scale-110">
                            <i class="fas fa-times text-white text-sm"></i>
                        </button>
                    </div>
                `;
                
                // Create content area
                const content = document.createElement('div');
                content.className = 'p-4 overflow-auto max-h-[60vh]';
                content.id = 'dayDetailsContent';
                
                // Create footer with action buttons
                const footer = document.createElement('div');
                footer.className = 'p-4 border-t bg-gray-50 flex justify-end space-x-3';
                footer.innerHTML = `
                    <button id="cancelDaySelection" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-medium transition-colors duration-200">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button id="confirmDaySelection" class="px-6 py-2 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg font-bold transition-all duration-200 transform hover:scale-105">
                        <i class="fas fa-check mr-2"></i>Select This Day
                    </button>
                `;
                
                // Assemble popup
                popupContent.appendChild(header);
                popupContent.appendChild(content);
                popupContent.appendChild(footer);
                popupOverlay.appendChild(popupContent);
                document.body.appendChild(popupOverlay);
                
                // Animate in
                setTimeout(() => {
                    popupContent.style.transform = 'scale(1)';
                    popupContent.style.opacity = '1';
                }, 10);
                
                // Load day details
                renderDayDetailsForPopup(ymd, content);
                
                // Add event listeners
                document.getElementById('closeDayDetailsPopup').addEventListener('click', closeDayDetailsPopup);
                document.getElementById('cancelDaySelection').addEventListener('click', closeDayDetailsPopup);
                document.getElementById('confirmDaySelection').addEventListener('click', () => {
                    confirmDaySelection(ymd, dateDisplay);
                });
                
                // Close on overlay click
                popupOverlay.addEventListener('click', (e) => {
                    if (e.target === popupOverlay) {
                        closeDayDetailsPopup();
                    }
                });
            }
            
            // Function to close day details popup
            function closeDayDetailsPopup() {
                const popup = document.getElementById('dayDetailsPopup');
                if (popup) {
                    const content = popup.querySelector('div');
                    content.style.transform = 'scale(0.95)';
                    content.style.opacity = '0';
                    setTimeout(() => {
                        popup.remove();
                    }, 300);
                }
            }
            
            // Function to confirm day selection
            function confirmDaySelection(ymd, dateDisplay) {
                // Update the booking form
                const bookingDateInputEl = document.getElementById('booking_date');
                if (bookingDateInputEl) {
                    bookingDateInputEl.value = ymd;
                }
                
                const bookingDateDisplay = document.getElementById('booking_date_display');
                if (bookingDateDisplay) {
                    bookingDateDisplay.textContent = dateDisplay;
                }
                
                // Close the popup
                closeDayDetailsPopup();
                
                // Close the calendar modal
                const calendarModal = document.getElementById('calendarModal');
                if (calendarModal) {
                    calendarModal.classList.add('hidden');
                }
                
                // Regenerate calendar and time slots
                generateAvailabilityCalendar();
                generateTimeSlots();
                showExistingBookings();
                updateCostPreview();
                
                // Show success notification
                showSuccessNotification(`Selected ${dateDisplay}`);
            }
            
            // Function to show success notification
            function showSuccessNotification(message) {
                const notification = document.createElement('div');
                notification.className = 'fixed top-4 right-4 z-70 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center transform translate-x-full transition-transform duration-300';
                notification.innerHTML = `
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>${message}</span>
                `;
                document.body.appendChild(notification);
                
                // Animate in
                setTimeout(() => {
                    notification.classList.remove('translate-x-full');
                }, 100);
                
                // Animate out and remove
                setTimeout(() => {
                    notification.classList.add('translate-x-full');
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }, 3000);
            }
            
            // Function moved to global scope - see window.renderDayDetailsForPopup
            
            function renderDayDetails(ymd) {
                if (!dayDetails) return;
                dayDetails.innerHTML = '';
                
                // Parse the date for display
                const date = new Date(ymd);
                const dateDisplay = date.toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
                
                // Create header
                const header = document.createElement('div');
                header.className = 'mb-4 p-3 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-lg shadow-sm';
                header.innerHTML = `
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-calendar-day text-white text-sm"></i>
                        </div>
                        <div>
                            <h5 class="text-sm font-bold">${dateDisplay}</h5>
                            <p class="text-indigo-100 text-xs">Availability overview</p>
                        </div>
                    </div>
                `;
                dayDetails.appendChild(header);
                
                // Get reservations for this date
                const reservations = (existingReservations || []).filter(r => r.start_time && r.start_time.startsWith(ymd));
                
                // Ongoing usage indicator for today
                const now = new Date();
                const isToday = ymd === now.toISOString().split('T')[0];
                if (isToday && reservations.length > 0) {
                    const ongoing = reservations.find(r => {
                        const st = new Date(r.start_time);
                        const et = new Date(r.end_time);
                        return now >= st && now <= et;
                    });
                    
                    const ongoingCard = document.createElement('div');
                    ongoingCard.className = 'mb-3 p-3 rounded-lg shadow-sm border-2';
                    if (ongoing) {
                        ongoingCard.classList.add('bg-emerald-50', 'border-emerald-200');
                        ongoingCard.innerHTML = `
                            <div class="flex items-center">
                                <div class="w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center mr-2">
                                    <i class="fas fa-play text-white text-xs"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-emerald-800">Currently In Use</div>
                                    <div class="text-xs text-emerald-600">Active reservation</div>
                                </div>
                            </div>
                        `;
                    } else {
                        ongoingCard.classList.add('bg-blue-50', 'border-blue-200');
                        ongoingCard.innerHTML = `
                            <div class="flex items-center">
                                <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center mr-2">
                                    <i class="fas fa-pause text-white text-xs"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-blue-800">Available Now</div>
                                    <div class="text-xs text-blue-600">No current usage</div>
                                </div>
                            </div>
                        `;
                    }
                    dayDetails.appendChild(ongoingCard);
                }
                
                // Reserved times section
                const reservedSection = document.createElement('div');
                reservedSection.className = 'mb-3';
                reservedSection.innerHTML = `
                    <div class="flex items-center mb-2">
                        <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center mr-2">
                            <i class="fas fa-calendar-times text-red-600 text-xs"></i>
                        </div>
                        <h6 class="text-sm font-bold text-gray-800">Reserved Times</h6>
                    </div>
                `;
                
                if (reservations.length === 0) {
                    const emptyCard = document.createElement('div');
                    emptyCard.className = 'p-3 bg-green-50 border border-green-200 rounded-lg text-center';
                    emptyCard.innerHTML = `
                        <i class="fas fa-check-circle text-green-500 text-lg mb-1"></i>
                        <div class="text-sm font-semibold text-green-800">No Reservations</div>
                        <div class="text-xs text-green-600">Day is completely available!</div>
                    `;
                    reservedSection.appendChild(emptyCard);
                } else {
                    reservations.forEach(r => {
                        const reservationCard = document.createElement('div');
                        reservationCard.className = 'p-2 bg-white border border-gray-200 rounded-lg mb-1 shadow-sm';
                        const st = new Date(r.start_time);
                        const et = new Date(r.end_time);
                        const duration = (et - st) / (1000 * 60 * 60);
                        
                        reservationCard.innerHTML = `
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-4 h-4 ${r.status === 'confirmed' ? 'bg-green-500' : 'bg-yellow-500'} rounded-full flex items-center justify-center mr-2">
                                        <i class="fas fa-circle text-white text-xs"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold text-gray-800">
                                            ${st.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${et.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                        </div>
                                        <div class="text-xs text-gray-500">${duration.toFixed(1)}h</div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded-full text-xs font-medium ${r.status === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}">
                                    ${r.status}
                                </span>
                            </div>
                        `;
                        reservedSection.appendChild(reservationCard);
                    });
                }
                dayDetails.appendChild(reservedSection);
                
                // Available time ranges calculation
                const startOfDay = new Date(`${ymd}T08:00:00`);
                const endOfDay = new Date(`${ymd}T21:30:00`);
                const booked = reservations.map(r => ({ start: new Date(r.start_time), end: new Date(r.end_time) }));
                const ranges = [];
                let cursor = new Date(startOfDay);
                
                function overlaps(s1,e1,s2,e2){ return s1 < e2 && e1 > s2; }
                while (cursor < endOfDay) {
                    const next = new Date(cursor.getTime()+30*60000);
                    const slotBooked = booked.some(b => overlaps(cursor,next,b.start,b.end));
                    if (!slotBooked) {
                        if (ranges.length===0 || ranges[ranges.length-1].end.getTime() !== cursor.getTime()) {
                            ranges.push({ start: new Date(cursor), end: new Date(next) });
                        } else {
                            ranges[ranges.length-1].end = new Date(next);
                        }
                    }
                    cursor = next;
                }
                
                // Available ranges section
                const availableSection = document.createElement('div');
                availableSection.innerHTML = `
                    <div class="flex items-center mb-2">
                        <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center mr-2">
                            <i class="fas fa-clock text-green-600 text-xs"></i>
                        </div>
                        <h6 class="text-sm font-bold text-gray-800">Available Slots</h6>
                    </div>
                `;
                
                if (ranges.length === 0) {
                    const noAvailableCard = document.createElement('div');
                    noAvailableCard.className = 'p-3 bg-red-50 border border-red-200 rounded-lg text-center';
                    noAvailableCard.innerHTML = `
                        <i class="fas fa-ban text-red-500 text-lg mb-1"></i>
                        <div class="text-sm font-semibold text-red-800">Fully Booked</div>
                        <div class="text-xs text-red-600">No available times</div>
                    `;
                    availableSection.appendChild(noAvailableCard);
                } else {
                    ranges.forEach(r => {
                        const rangeCard = document.createElement('div');
                        rangeCard.className = 'p-2 bg-white border border-gray-200 rounded-lg mb-1 shadow-sm hover:shadow-md transition-shadow';
                        const duration = (r.end - r.start) / (1000 * 60 * 60);
                        
                        rangeCard.innerHTML = `
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-4 h-4 bg-green-500 rounded-full flex items-center justify-center mr-2">
                                        <i class="fas fa-check text-white text-xs"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold text-gray-800">
                                            ${r.start.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${r.end.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                        </div>
                                        <div class="text-xs text-gray-500">${duration.toFixed(1)}h available</div>
                                    </div>
                                </div>
                                <button class="px-2 py-1 bg-blue-500 hover:bg-blue-600 text-white text-xs rounded font-medium transition-colors duration-200 use-time-btn" 
                                        data-start="${r.start.toTimeString().slice(0,5)}" 
                                        data-end="${r.end.toTimeString().slice(0,5)}">
                                    <i class="fas fa-plus text-xs"></i>
                                </button>
                            </div>
                        `;
                        
                        // Add click handler for "Use" button
                        const useBtn = rangeCard.querySelector('.use-time-btn');
                        useBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const startTime = useBtn.dataset.start;
                            const endTime = useBtn.dataset.end;
                            
                            // Set the time inputs
                            const startTimeInput = document.getElementById('start_time');
                            const endTimeInput = document.getElementById('end_time');
                            if (startTimeInput && endTimeInput) {
                                startTimeInput.value = startTime;
                                endTimeInput.value = endTime;
                            }
                            
                            // Close modal and update UI
                            calendarModal.classList.add('hidden');
                            generateTimeSlots();
                            updateCostPreview();
                        });
                        
                        availableSection.appendChild(rangeCard);
                    });
                }
                dayDetails.appendChild(availableSection);
            }

            // Ensure calendar days are rendered as soon as script loads
            try { if (monthlyCalendar) { renderMonth(calCurrent); } } catch (e) { console.error('Calendar render error', e); }

            if (openCalBtn && calendarModal) {
                openCalBtn.addEventListener('click', () => {
                    calendarModal.classList.remove('hidden');
                    // Always show current month days automatically
                    calCurrent = new Date();
                    renderMonth(calCurrent);
                });
            }
            if (closeCalBtn && calendarModal) {
                closeCalBtn.addEventListener('click', () => calendarModal.classList.add('hidden'));
                calendarModal.addEventListener('click', (e) => { if (e.target === calendarModal) calendarModal.classList.add('hidden'); });
            }
            const calPrev = document.getElementById('calPrev');
            const calNext = document.getElementById('calNext');
            // Hide navigation - always show current month
            if (calPrev) calPrev.classList.add('hidden');
            if (calNext) calNext.classList.add('hidden');
            if (confirmCalBtn) {
                confirmCalBtn.addEventListener('click', () => {
                    if (!calSelected) return;
                    const ymd = formatYMD(calSelected);
                    if (bookingDateInputEl) bookingDateInputEl.value = ymd;
                    const disp = document.getElementById('booking_date_display');
                    if (disp) disp.textContent = new Date(ymd).toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
                    updateCalendarHighlighting();
                    generateTimeSlots();
                    showExistingBookings();
                    updateCostPreview();
                    calendarModal.classList.add('hidden');
                });
            }
            const hasFullDayBooking = dateBookings.some(booking => {
                const startTime = new Date(booking.start_time).toTimeString().slice(0, 5);
                const endTime = new Date(booking.end_time).toTimeString().slice(0, 5);
                return startTime <= '08:00' && endTime >= '21:30';
            });
            
            if (hasFullDayBooking) {
                // Show full day booking message instead of time slots
                const slot = document.createElement('div');
                slot.className = 'time-slot p-6 border rounded-lg text-center bg-red-50 border-red-200 col-span-full';
                slot.innerHTML = `
                    <div class="flex items-center justify-center mb-3">
                        <i class="fas fa-ban text-red-500 text-2xl mr-3"></i>
                        <div class="text-center">
                            <div class="font-semibold text-red-800 text-lg">Facility Fully Booked</div>
                            <div class="text-sm text-red-600">This facility is reserved for the entire day</div>
                         </div>
                     </div>
                     <div class="text-xs text-red-500">
                         <i class="fas fa-info-circle mr-1"></i>
                         Please select a different date or check other available facilities
                     </div>
                 `;
                 slots.push(slot);
                 timeSlotsContainer.append(...slots);
                 return;
             }
                         const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            
            
            // Generate hourly time slots from 8 AM to 9:30 PM
            for (let hour = 8; hour <= 21; hour++) {
                const time = `${hour.toString().padStart(2, '0')}:00`;
                let endTime = `${(hour + 1).toString().padStart(2, '0')}:00`;
                
                // Skip if this would go past 9:30 PM
                if (hour === 21) {
                    endTime = '21:30';
                }
                
                // Check if this slot conflicts with existing reservations
                const isBooked = existingReservations.some(reservation => {
                    const reservationDate = formatDateToLocal(new Date(reservation.start_time));
                    const reservationStart = new Date(reservation.start_time).toTimeString().slice(0, 5);
                    const reservationEnd = new Date(reservation.end_time).toTimeString().slice(0, 5);
                    // Check if the reservation is on the same date and not cancelled
                    if (reservationDate !== selectedDate || reservation.status === 'cancelled') {
                        return false;
                    }
                    // Check for time overlap
                    const slotStart = time;
                    const slotEnd = endTime;
                    // Check if there's any overlap between the time slots
                    return (slotStart < reservationEnd && slotEnd > reservationStart);
                });
                
                // Check if this time slot has already passed today
                const today = new Date().toISOString().split('T')[0];
                let isPastTime = false;
                if (selectedDate === today) {
                    const now = new Date();
                    const currentTime = now.getHours() * 60 + now.getMinutes();
                    const startTimeMinutes = hour * 60;
                    const bufferTime = currentTime + 15; // 15 minutes buffer
                    isPastTime = startTimeMinutes <= bufferTime;
                }
                
                const slot = document.createElement('div');
                slot.className = `time-slot-card p-4 border-2 rounded-xl text-center transition-all duration-300 transform hover:scale-105 ${
                    isBooked || isPastTime ? 'disabled bg-gray-100 text-gray-400 border-gray-300 cursor-not-allowed' : 'bg-gradient-to-br from-white to-blue-50 hover:from-blue-50 hover:to-indigo-50 border-blue-300 hover:border-blue-400 hover:shadow-lg cursor-pointer'
                }`;
                
                // Convert to 12-hour format
                const displayHour = hour % 12 || 12;
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const time12Hour = `${displayHour}:00 ${ampm}`;
                
                let statusText = 'Click to select';
                if (isBooked) {
                    statusText = 'Already Booked';
                } else if (isPastTime) {
                    statusText = 'Time Passed';
                }
                
                slot.innerHTML = `
                    <div class="text-2xl font-bold ${isBooked || isPastTime ? 'text-gray-500' : 'text-gray-800'} mb-1">${time}</div>
                    <div class="text-lg font-semibold ${isBooked || isPastTime ? 'text-gray-400' : 'text-blue-600'}">${time12Hour}</div>
                    <div class="text-xs text-gray-500 mt-1">${statusText}</div>
                `;
                
                if (!isBooked && !isPastTime) {
                    slot.addEventListener('click', (event) => selectTimeSlot(time, endTime, event));
                }
                slots.push(slot);
            }
            
            timeSlotsContainer.append(...slots);
        }
        
        // Function to select time slot
        function selectTimeSlot(startTime, endTime, event) {
            // Check if required variables are defined
            if (!startTimeInputField || !endTimeInputField || !facilityHourlyRate) {
                console.error('Required variables not initialized');
                return;
            }
            
            // Remove previous selection from all time slots
            document.querySelectorAll('.time-slot-card').forEach(slot => {
                slot.classList.remove('selected', 'daily-selected');
            });
            
            // Add selection to clicked slot
            const clickedSlot = event ? event.target.closest('.time-slot-card') : null;
            if (clickedSlot) {
                clickedSlot.classList.add('selected');
                
                // Add visual feedback
                clickedSlot.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    clickedSlot.style.transform = '';
                }, 150);
            }
            
            // Update time input fields
            startTimeInputField.value = startTime;
            endTimeInputField.value = endTime;
            
            // Update hidden inputs
            const selectedDate = bookingDateInput.value;
            startTimeInput.value = `${selectedDate} ${startTime}:00`;
            endTimeInput.value = `${selectedDate} ${endTime}:00`;
            
            // Show cost preview for hourly booking
            const duration = 1; // 1 hour
            const totalCost = duration * facilityHourlyRate;
            bookingTypeDisplay.textContent = 'Hourly';
            durationSpan.textContent = `${duration} hour`;
            rateDisplay.textContent = `₱${facilityHourlyRate.toFixed(2)}`;
            totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
            costPreview.classList.remove('hidden');
            
            // Update the description to show selected time
            updateSelectedTimeDescription(startTime, endTime);
        }
         
        // Function to update the selected time description
        function updateSelectedTimeDescription(startTime, endTime) {
            const descriptionElement = document.querySelector('.quick-time-description');
            if (descriptionElement) {
                const startTime12 = convertTo12Hour(startTime);
                const endTime12 = convertTo12Hour(endTime);
                descriptionElement.innerHTML = 
                    '<div class="flex items-center text-purple-700">' +
                        '<i class="fas fa-check-circle mr-4 text-green-500 text-xl"></i>' +
                        '<span class="text-base font-medium">' +
                            '<strong>Selected:</strong> ' + startTime12 + ' - ' + endTime12 + ' ' +
                            '<span class="text-sm text-purple-600">(Click any slot to change)</span>' +
                        '</span>' +
                    '</div>';
            }
        }
         
        // Function to convert 24-hour time to 12-hour format
        function convertTo12Hour(time24) {
            const [hours, minutes] = time24.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour % 12 || 12;
            return `${displayHour}:${minutes} ${ampm}`;
        }
         
        // Function to clear all time slot selections
        function clearTimeSlotSelections() {
            document.querySelectorAll('.time-slot-card').forEach(slot => {
                slot.classList.remove('selected', 'daily-selected');
            });
        }
        
        // Function to reset the time description to default
        function resetTimeDescription() {
            const descriptionElement = document.querySelector('.quick-time-description');
            if (descriptionElement) {
                descriptionElement.innerHTML = 
                    '<div class="flex items-center text-purple-700">' +
                        '<i class="fas fa-lightbulb mr-4 text-yellow-500 text-xl"></i>' +
                        '<span class="text-base font-medium">Quick time slots help you quickly select common booking durations. Click any slot to automatically set your start and end times.</span>' +
                    '</div>';
            }
        }
         
        // Function to update description for daily slot selection
        function updateDailySlotDescription() {
            const descriptionElement = document.querySelector('.quick-time-description');
            if (descriptionElement) {
                descriptionElement.innerHTML = 
                    '<div class="flex items-center text-purple-700">' +
                        '<i class="fas fa-check-circle mr-4 text-green-500 text-xl"></i>' +
                        '<span class="text-base font-medium">' +
                            '<strong>Selected:</strong> Full Day (8:00 AM - 9:30 PM) ' +
                            '<span class="text-sm text-purple-600">(Click any slot to change)</span>' +
                        '</span>' +
                    '</div>';
            }
        }
         
        // Function to check for time conflicts in real-time
        function checkTimeConflicts() {
            const selectedDate = bookingDateInput.value;
            const startTime = startTimeInputField.value;
            const endTime = endTimeInputField.value;
            
            if (!selectedDate || !startTime || !endTime) {
                return false; // Return false if validation fails
            }
            
            const startDateTime = `${selectedDate} ${startTime}:00`;
            const endDateTime = `${selectedDate} ${endTime}:00`;
            
            // Check if end time is after start time
            if (new Date(endDateTime) <= new Date(startDateTime)) {
                showConflictError('End time must be after start time');
                return false;
            }
            
            // Check if booking time has already passed today
            const today = new Date().toISOString().split('T')[0];
            if (selectedDate === today) {
                const now = new Date();
                const currentTime = now.getHours() * 60 + now.getMinutes(); // Current time in minutes
                const startTimeMinutes = parseInt(startTime.split(':')[0]) * 60 + parseInt(startTime.split(':')[1]);
                
                // Add 15 minutes buffer to prevent booking too close to current time
                const bufferTime = currentTime + 15;
                
                if (startTimeMinutes <= bufferTime) {
                    const currentTimeFormatted = now.toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit', 
                        hour12: true 
                    });
                    const startTimeFormatted = new Date(`2000-01-01 ${startTime}`).toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit', 
                        hour12: true 
                    });
                    const bufferTimeFormatted = new Date(now.getTime() + 15 * 60000).toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit', 
                        hour12: true 
                    });
                    showConflictError(`Cannot book a time slot that has already passed or is too close to current time. Current time: ${currentTimeFormatted}, Earliest available booking: ${bufferTimeFormatted}`);
                    return false;
                }
            }
            
            // Check operating hours
            const startHour = parseInt(startTime.split(':')[0]);
            const startMinute = parseInt(startTime.split(':')[1]);
            const endHour = parseInt(endTime.split(':')[0]);
            const endMinute = parseInt(endTime.split(':')[1]);
            
            const startDecimal = startHour + (startMinute / 60);
            const endDecimal = endHour + (endMinute / 60);
            
            if (startDecimal < 8 || endDecimal > 21.5) {
                showConflictError('Booking must be within operating hours (8:00 AM - 9:30 PM)');
                return false;
            }
            
            // Check duration limits
            const duration = (new Date(endDateTime) - new Date(startDateTime)) / (1000 * 60 * 60);
            if (duration < 0.5) {
                showConflictError('Minimum booking duration is 30 minutes');
                return false;
            }
            if (duration > 13.5) {
                showConflictError('Maximum booking duration is 13.5 hours');
                return false;
            }
            
            // If all checks pass, hide any conflict errors
            hideConflictError();
            return true; // Return true if validation passes
        }
        
        // Function to show conflict errors
        function showConflictError(message) {
            let conflictError = document.getElementById('conflictError');
            if (!conflictError) {
                conflictError = document.createElement('div');
                conflictError.id = 'conflictError';
                conflictError.className = 'bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4';
                conflictError.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="font-medium">Time Conflict:</span>
                        <span class="ml-2">${message}</span>
                    </div>
                `;
                
                // Insert after the time selection section
                const timeSelection = document.querySelector('.enhanced-time-selection');
                if (timeSelection) {
                    timeSelection.parentNode.insertBefore(conflictError, timeSelection.nextSibling);
                }
            } else {
                conflictError.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="font-medium">Time Conflict:</span>
                        <span class="ml-2">${message}</span>
                    </div>
                `;
                conflictError.classList.remove('hidden');
            }
        }
        
        // Function to hide conflict errors
        function hideConflictError() {
            const conflictError = document.getElementById('conflictError');
            if (conflictError) {
                conflictError.classList.add('hidden');
            }
        }
        
        // Function to update current time display
        function updateCurrentTime() {
            const now = new Date();
            const timeDisplay = document.getElementById('currentTimeDisplay');
            const dateDisplay = document.getElementById('currentDateDisplay');
            
            if (timeDisplay && dateDisplay) {
                // Enhanced 12-hour format with better styling
                const hours = now.getHours();
                const minutes = now.getMinutes();
                const seconds = now.getSeconds();
                const ampm = hours >= 12 ? 'PM' : 'AM';
                const displayHours = hours % 12 || 12;
                
                timeDisplay.innerHTML = `
                    <span class="text-2xl font-bold">${displayHours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}</span>
                    <span class="text-sm ml-1">${seconds.toString().padStart(2, '0')}</span>
                    <span class="text-lg ml-2 font-semibold">${ampm}</span>
                `;
                
                dateDisplay.textContent = now.toLocaleDateString('en-US', { 
                    weekday: 'long',
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric'
                });
            }
        }
        
        // Simple calendar renderer for modal
        function renderSimpleCalendar(baseDate) {
            const calMonthLabelEl = document.getElementById('calMonthLabel');
            const monthlyCalendarEl = document.getElementById('monthlyCalendar');
            
            if (!monthlyCalendarEl || !calMonthLabelEl) {
                console.error('Calendar elements not found');
                return;
            }
            
            const date = baseDate ? new Date(baseDate) : new Date();
            monthlyCalendarEl.innerHTML = '';
            
            const year = date.getFullYear();
            const month = date.getMonth();
            calMonthLabelEl.textContent = date.toLocaleString('en-US', { month:'long', year:'numeric' });
            
            const first = new Date(year, month, 1);
            const startWeekday = first.getDay();
            const daysInMonth = new Date(year, month+1, 0).getDate();
            
            // Add empty cells for days before month starts
            for (let i=0; i<startWeekday; i++){ 
                const pad = document.createElement('div'); 
                pad.className = 'h-12'; 
                monthlyCalendarEl.appendChild(pad); 
            }
            
            function formatYMD(d) { 
                return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); 
            }
            
            const today = formatYMD(new Date());
            
            for (let d=1; d<=daysInMonth; d++) {
                const cell = document.createElement('button');
                const cellDate = new Date(year, month, d);
                const ymd = formatYMD(cellDate);
                const isToday = ymd === today;
                const isPast = cellDate < new Date().setHours(0,0,0,0);
                
                // Simple styling
                cell.className = `h-12 rounded-lg border text-sm font-semibold transition-all duration-200 ${
                    isToday ? 'bg-blue-500 text-white border-blue-400' :
                    isPast ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed' :
                    'bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300'
                }`;
                
                cell.textContent = d;
                
                // Add click handling for non-past dates
                if (!isPast) {
                    cell.addEventListener('click', () => {
                        // Remove previous selection
                        document.querySelectorAll('#monthlyCalendar button').forEach(btn => {
                            if (!btn.classList.contains('cursor-not-allowed')) {
                                btn.classList.remove('ring-2', 'ring-purple-400', 'bg-purple-100');
                                btn.classList.add('bg-white');
                            }
                        });
                        
                        // Add selection styling
                        cell.classList.add('ring-2', 'ring-purple-400', 'bg-purple-100');
                        
                        // Create date display
                        const dateDisplay = cellDate.toLocaleDateString('en-US', { 
                            weekday: 'long', 
                            year: 'numeric', 
                            month: 'long', 
                            day: 'numeric' 
                        });
                        
                        // Update the selected date title and subtitle
                        const selectedDateTitle = document.getElementById('selectedDateTitle');
                        const selectedDateSubtitle = document.getElementById('selectedDateSubtitle');
                        
                        if (selectedDateTitle) {
                            selectedDateTitle.textContent = dateDisplay;
                        }
                        if (selectedDateSubtitle) {
                            selectedDateSubtitle.textContent = 'Click "Select This Day" to confirm';
                        }
                        
                        // Store selection for confirmation
                        cell.dataset.selectedDate = ymd;
                        cell.dataset.selectedDisplay = dateDisplay;
                        
                        // Enable confirm button
                        const confirmBtn = document.getElementById('confirmCalendarSelection');
                        if (confirmBtn) confirmBtn.disabled = false;
                        
                        // Load day details for popup
                        const dayDetailsEl = document.getElementById('dayDetails');
                        console.log('Day clicked in renderSimpleCalendar, calling renderDayDetailsForPopup with:', ymd, dayDetailsEl);
                        if (typeof renderDayDetailsForPopup === 'function') {
                            renderDayDetailsForPopup(ymd, dayDetailsEl);
                        } else {
                            console.error('renderDayDetailsForPopup function not found');
                        }
                    });
                }
                
                monthlyCalendarEl.appendChild(cell);
            }
        }
        
        // Function to update cost preview based on current inputs
        function updateCostPreview() {
            try {
                console.log('updateCostPreview called');
                
                if (!costPreview) {
                    console.error('costPreview element not found');
                    return;
                }
                
                const selectedDate = bookingDateInput.value;
                const startTime = startTimeInputField.value;
                const endTime = endTimeInputField.value;
                
                console.log('Cost preview inputs:', {
                    selectedDate,
                    startTime,
                    endTime,
                    costPreviewExists: !!costPreview
                });
                
                if (!selectedDate || !startTime || !endTime) {
                    console.log('Missing inputs, hiding cost preview');
                    costPreview.classList.add('hidden');
                    return;
                }
                
                const bookingTypeElement = document.querySelector('input[name="booking_type"]:checked');
                if (!bookingTypeElement) {
                    console.error('No booking type selected');
                    return;
                }
                
                const bookingType = bookingTypeElement.value;
                console.log('Booking type:', bookingType);
                
                // For hourly booking, use the actual selected times
                if (bookingType === 'hourly') {
                    // Update hidden inputs
                    if (startTimeInput) startTimeInput.value = `${selectedDate} ${startTime}:00`;
                    if (endTimeInput) endTimeInput.value = `${selectedDate} ${endTime}:00`;
                    
                    // Calculate based on actual duration
                    const startDateTime = new Date(`${selectedDate}T${startTime}`);
                    const endDateTime = new Date(`${selectedDate}T${endTime}`);
                    
                    if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
                        console.error('Invalid date/time values');
                        return;
                    }
                    
                    const durationHours = (endDateTime - startDateTime) / (1000 * 60 * 60);
                    console.log('Duration hours:', durationHours);
                    
                    // Facility cost uses facility rate; package is flat add-on if selected
                    const facilityCost = durationHours * (facilityHourlyRate || 0);
                    const packageCost = selectedPackageFlatCost || 0;
                    const totalCost = facilityCost + packageCost;
                    
                    console.log('Cost calculation:', {
                        facilityCost,
                        packageCost,
                        totalCost,
                        facilityHourlyRate
                    });
                    
                    // Update display elements
                    if (bookingTypeDisplay) bookingTypeDisplay.textContent = 'Hourly';
                    if (durationSpan) durationSpan.textContent = `${durationHours.toFixed(1)} hours`;
                    if (rateDisplay) rateDisplay.textContent = `₱${(facilityHourlyRate || 0).toFixed(2)}`;
                    if (totalCostSpan) totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
                    
                    const packageDisplay = document.getElementById('packageDisplay');
                    if (packageDisplay) packageDisplay.textContent = selectedPricingOptionName || 'Standard rate';
                    
                    const packageCostDisplay = document.getElementById('packageCostDisplay');
                    if (packageCostDisplay) packageCostDisplay.textContent = selectedPricingOptionName ? `₱${packageCost.toFixed(2)}` : '₱0.00';
                }
                
                console.log('Showing cost preview');
                costPreview.classList.remove('hidden');
            } catch (error) {
                console.error('Error in updateCostPreview:', error);
            }
        }
        // Enhanced booking type management function
        function updateBookingType() {
            const hourlyOption = document.querySelector('input[value="hourly"]');
            const hourlyDiv = document.querySelector('.booking-type-option[data-type="hourly"]');
            
            // Remove all selection classes first
            hourlyDiv.classList.remove('selected', 'border-blue-500', 'bg-blue-50', 'border-gray-300');
            
            // Update visual selection with enhanced highlighting
            if (hourlyOption.checked) {
                // Highlight hourly option
                hourlyDiv.classList.add('selected', 'border-blue-500', 'bg-blue-50');
                hourlyDiv.classList.remove('border-gray-300');
                
                // Add visual feedback
                hourlyDiv.style.transform = 'scale(1.02)';
                
                // Add glow effect
                hourlyDiv.style.boxShadow = '0 0 20px rgba(59, 130, 246, 0.3)';
                
                // Show/hide selection indicators
                const hourlyIndicator = hourlyDiv.querySelector('.selection-indicator');
                if (hourlyIndicator) hourlyIndicator.style.opacity = '1';
                
                // Enable time inputs for hourly booking
                startTimeInputField.disabled = false;
                endTimeInputField.disabled = false;
                startTimeInputField.classList.remove('bg-gray-100', 'cursor-not-allowed');
                endTimeInputField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            }
            
            // Update time slot generation and cost preview
            generateTimeSlots();
            showExistingBookings();
            updateCostPreview();
        }
        // Auto-fill form from URL parameters
        function autoFillFromURL() {
            const urlParams = new URLSearchParams(window.location.search);
            const startTime = urlParams.get('start_time');
            const endTime = urlParams.get('end_time');
            const bookingType = urlParams.get('booking_type');
            if (startTime && endTime) {
                try {
                    // Parse the datetime strings
                    const startDateTime = new Date(startTime);
                    const endDateTime = new Date(endTime);
                    // Check if dates are valid
                    if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
                        console.warn('Invalid date format in URL parameters');
                        return;
                    }
                    // Format dates for input fields
                    const startDate = formatDateToLocal(startDateTime);
                    const endDate = formatDateToLocal(endDateTime);
                    // Format times for input fields (HH:MM format)
                    const startTimeFormatted = startDateTime.toTimeString().slice(0, 5);
                    const endTimeFormatted = endDateTime.toTimeString().slice(0, 5);
                    // Set the form values
                    if (bookingDateInput) bookingDateInput.value = startDate;
                    if (startTimeInputField) startTimeInputField.value = startTimeFormatted;
                    if (endTimeInputField) endTimeInputField.value = endTimeFormatted;
                    // Set the hidden inputs for form submission
                    if (startTimeInput) startTimeInput.value = startTime;
                    if (endTimeInput) endTimeInput.value = endTime;
                    // Set booking type if provided
                    if (bookingType) {
                        const bookingTypeRadio = document.querySelector(`input[name="booking_type"][value="${bookingType}"]`);
                        if (bookingTypeRadio) {
                            bookingTypeRadio.checked = true;
                            updateBookingType();
                        }
                    }
                    // Update the UI
                    generateTimeSlots();
                    showExistingBookings();
                    updateCostPreview();
                    // Show success message
                    setTimeout(() => {
                        if (window.ModalSystem) {
                            window.ModalSystem.alert('Form has been auto-filled with your selected time slot!', 'Auto-fill Complete', 'success');
                        } else {
                            alert('Form has been auto-filled with your selected time slot!');
                        }
                    }, 500);
                } catch (error) {
                    console.error('Error auto-filling form:', error);
                }
            }
        }
         // Mobile menu functionality
         document.addEventListener('DOMContentLoaded', function() {
             const mobileMenuButton = document.getElementById('mobile-menu-button');
             const mobileMenu = document.getElementById('mobile-menu');
             if (mobileMenuButton && mobileMenu) {
                 mobileMenuButton.addEventListener('click', function() {
                     const isHidden = mobileMenu.classList.contains('hidden');
                     if (isHidden) {
                         mobileMenu.classList.remove('hidden');
                         mobileMenuButton.innerHTML = '<i class="fas fa-times text-xl"></i>';
                     } else {
                         mobileMenu.classList.add('hidden');
                         mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                     }
                 });
             }
             // Initialize form elements
             bookingDateInput = document.getElementById('booking_date');
             startTimeInputField = document.getElementById('start_time_input');
             endTimeInputField = document.getElementById('end_time_input');
             
             // Debug: Check if elements are found
             if (!bookingDateInput) {
                 console.error('bookingDateInput element not found');
             }
             if (!startTimeInputField) {
                 console.error('startTimeInputField element not found');
             }
             if (!endTimeInputField) {
                 console.error('endTimeInputField element not found');
             }
             timeSlotsContainer = document.getElementById('timeSlots');
             startTimeInput = document.getElementById('start_time');
             endTimeInput = document.getElementById('end_time');
             costPreview = document.getElementById('costPreview');
             durationSpan = document.getElementById('duration');
             totalCostSpan = document.getElementById('totalCost');
             submitBtn = document.getElementById('submitBtn');
             facilityHourlyRate = <?php echo $facility['hourly_rate'] ?? 0; ?>;
             rateDisplay = document.getElementById('rateDisplay');
             bookingTypeDisplay = document.getElementById('bookingTypeDisplay');
             // Existing reservations data
             existingReservations = <?php echo json_encode($existing_reservations ?? []); ?>;
             
             // Debug: Log existing reservations
             console.log('Existing reservations loaded:', existingReservations.length, existingReservations);
             
             // Global function to render day details for popup
             window.renderDayDetailsForPopup = function(ymd, container) {
                 console.log('renderDayDetailsForPopup called with:', ymd, container);
                 
                 if (!container) {
                     console.error('Container not provided to renderDayDetailsForPopup');
                     return;
                 }
                 
                 container.innerHTML = '';
                 
                 // Parse the date for display
                 const date = new Date(ymd);
                 const now = new Date();
                 const isToday = ymd === now.toISOString().split('T')[0];
                 const isPast = ymd < now.toISOString().split('T')[0];
                 
                 // Get reservations for this date
                 const reservations = (existingReservations || []).filter(r => r.start_time && r.start_time.startsWith(ymd));
                 
                 console.log('Reservations found:', reservations.length, reservations);
                 
                 // Current status section
                 const statusSection = document.createElement('div');
                 statusSection.className = 'mb-4 p-3 rounded-lg border-2';
                 
                 if (isPast) {
                     statusSection.classList.add('bg-gray-50', 'border-gray-200');
                     statusSection.innerHTML = `
                         <div class="flex items-center">
                             <div class="w-6 h-6 bg-gray-500 rounded-full flex items-center justify-center mr-2">
                                 <i class="fas fa-history text-white text-xs"></i>
                             </div>
                             <div>
                                 <div class="text-sm font-semibold text-gray-800">Past Date</div>
                                 <div class="text-xs text-gray-600">This date has already passed</div>
                             </div>
                         </div>
                     `;
                 } else if (isToday) {
                     const ongoing = reservations.find(r => {
                         const st = new Date(r.start_time);
                         const et = new Date(r.end_time);
                         return now >= st && now <= et;
                     });
                     
                     if (ongoing) {
                         statusSection.classList.add('bg-emerald-50', 'border-emerald-200');
                         statusSection.innerHTML = `
                             <div class="flex items-center">
                                 <div class="w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center mr-2">
                                     <i class="fas fa-play text-white text-xs"></i>
                                 </div>
                                 <div>
                                     <div class="text-sm font-semibold text-emerald-800">Currently In Use</div>
                                     <div class="text-xs text-emerald-600">Facility is occupied right now</div>
                                 </div>
                             </div>
                         `;
                     } else {
                         statusSection.classList.add('bg-blue-50', 'border-blue-200');
                         statusSection.innerHTML = `
                             <div class="flex items-center">
                                 <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center mr-2">
                                     <i class="fas fa-pause text-white text-xs"></i>
                                 </div>
                                 <div>
                                     <div class="text-sm font-semibold text-blue-800">Available Now</div>
                                     <div class="text-xs text-blue-600">No current usage</div>
                                 </div>
                             </div>
                         `;
                     }
                 } else {
                     statusSection.classList.add('bg-purple-50', 'border-purple-200');
                     statusSection.innerHTML = `
                         <div class="flex items-center">
                             <div class="w-6 h-6 bg-purple-500 rounded-full flex items-center justify-center mr-2">
                                 <i class="fas fa-calendar text-white text-xs"></i>
                             </div>
                             <div>
                                 <div class="text-sm font-semibold text-purple-800">Future Date</div>
                                 <div class="text-xs text-purple-600">Available for booking</div>
                             </div>
                         </div>
                     `;
                 }
                 container.appendChild(statusSection);
                 
                 // Reservations summary
                 const summarySection = document.createElement('div');
                 summarySection.className = 'mb-4';
                 summarySection.innerHTML = `
                     <div class="flex items-center mb-3">
                         <div class="w-6 h-6 bg-indigo-100 rounded-full flex items-center justify-center mr-2">
                             <i class="fas fa-chart-bar text-indigo-600 text-xs"></i>
                         </div>
                         <h6 class="text-sm font-bold text-gray-800">Reservation Summary</h6>
                     </div>
                 `;
                 
                 const confirmedReservations = reservations.filter(r => r.status === 'confirmed');
                 const pendingReservations = reservations.filter(r => r.status === 'pending');
                 
                 const summaryCard = document.createElement('div');
                 summaryCard.className = 'grid grid-cols-3 gap-2 mb-3';
                 summaryCard.innerHTML = `
                     <div class="p-2 bg-green-50 border border-green-200 rounded text-center">
                         <div class="text-lg font-bold text-green-800">${confirmedReservations.length}</div>
                         <div class="text-xs text-green-600">Confirmed</div>
                     </div>
                     <div class="p-2 bg-yellow-50 border border-yellow-200 rounded text-center">
                         <div class="text-lg font-bold text-yellow-800">${pendingReservations.length}</div>
                         <div class="text-xs text-yellow-600">Pending</div>
                     </div>
                     <div class="p-2 bg-blue-50 border border-blue-200 rounded text-center">
                         <div class="text-lg font-bold text-blue-800">${reservations.length}</div>
                         <div class="text-xs text-blue-600">Total</div>
                     </div>
                 `;
                 summarySection.appendChild(summaryCard);
                 container.appendChild(summarySection);
                 
                 // Confirmed reservations
                 if (confirmedReservations.length > 0) {
                     const confirmedSection = document.createElement('div');
                     confirmedSection.className = 'mb-4';
                     confirmedSection.innerHTML = `
                         <div class="flex items-center mb-2">
                             <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center mr-2">
                                 <i class="fas fa-check-circle text-green-600 text-xs"></i>
                             </div>
                             <h6 class="text-sm font-bold text-gray-800">Confirmed Reservations</h6>
                         </div>
                     `;
                     
                     confirmedReservations.forEach(r => {
                         const reservationCard = document.createElement('div');
                         reservationCard.className = 'p-2 bg-white border border-gray-200 rounded mb-1 shadow-sm';
                         const st = new Date(r.start_time);
                         const et = new Date(r.end_time);
                         const duration = (et - st) / (1000 * 60 * 60);
                         const isOngoing = isToday && now >= st && now <= et;
                         const isUpcoming = st > now;
                         
                         let statusIcon = 'fas fa-check-circle';
                         let statusColor = 'green';
                         let statusText = 'Confirmed';
                         
                         if (isOngoing) {
                             statusIcon = 'fas fa-play';
                             statusColor = 'emerald';
                             statusText = 'Ongoing';
                         } else if (isUpcoming) {
                             statusIcon = 'fas fa-clock';
                             statusColor = 'blue';
                             statusText = 'Upcoming';
                         }
                         
                         reservationCard.innerHTML = `
                             <div class="flex items-center justify-between">
                                 <div class="flex items-center">
                                     <div class="w-4 h-4 bg-${statusColor}-500 rounded-full flex items-center justify-center mr-2">
                                         <i class="${statusIcon} text-white text-xs"></i>
                                     </div>
                                     <div>
                                         <div class="text-xs font-semibold text-gray-800">
                                             ${st.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${et.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                         </div>
                                         <div class="text-xs text-gray-500">${duration.toFixed(1)}h • ${statusText}</div>
                                     </div>
                                 </div>
                                 <span class="px-2 py-1 rounded-full text-xs font-medium bg-${statusColor}-100 text-${statusColor}-800">
                                     ${statusText}
                                 </span>
                             </div>
                         `;
                         confirmedSection.appendChild(reservationCard);
                     });
                     container.appendChild(confirmedSection);
                 }
                 
                 // Pending reservations from other users
                 if (pendingReservations.length > 0) {
                     const pendingSection = document.createElement('div');
                     pendingSection.className = 'mb-4';
                     pendingSection.innerHTML = `
                         <div class="flex items-center mb-2">
                             <div class="w-6 h-6 bg-yellow-100 rounded-full flex items-center justify-center mr-2">
                                 <i class="fas fa-hourglass-half text-yellow-600 text-xs"></i>
                             </div>
                             <h6 class="text-sm font-bold text-gray-800">Pending Reservations</h6>
                             <span class="ml-2 px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full font-medium">
                                 ${pendingReservations.length} waiting
                             </span>
                         </div>
                         <div class="text-xs text-gray-600 mb-2 bg-yellow-50 border border-yellow-200 rounded p-2">
                             <i class="fas fa-info-circle mr-1"></i>
                             These time slots are being considered by other users. They may become unavailable if confirmed.
                         </div>
                     `;
                     
                     pendingReservations.forEach(r => {
                         const reservationCard = document.createElement('div');
                         reservationCard.className = 'p-2 bg-yellow-50 border border-yellow-200 rounded mb-1 shadow-sm';
                         const st = new Date(r.start_time);
                         const et = new Date(r.end_time);
                         const duration = (et - st) / (1000 * 60 * 60);
                         
                         // Get user info if available
                         const userName = r.full_name || r.user_name || 'Another User';
                         const userInitials = userName.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
                         
                         reservationCard.innerHTML = `
                             <div class="flex items-center justify-between">
                                 <div class="flex items-center">
                                     <div class="w-6 h-6 bg-yellow-500 rounded-full flex items-center justify-center mr-2 text-white text-xs font-bold">
                                         ${userInitials}
                                     </div>
                                     <div>
                                         <div class="text-xs font-semibold text-gray-800">
                                             ${st.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${et.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                         </div>
                                         <div class="text-xs text-gray-500">${duration.toFixed(1)}h • ${userName}</div>
                                     </div>
                                 </div>
                                 <div class="flex items-center space-x-2">
                                     <span class="px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                         Pending
                                     </span>
                                     <div class="w-2 h-2 bg-yellow-500 rounded-full animate-pulse" title="Awaiting confirmation"></div>
                                 </div>
                             </div>
                         `;
                         pendingSection.appendChild(reservationCard);
                     });
                     container.appendChild(pendingSection);
                 }
                 
                 // Available time ranges (excluding both confirmed and pending reservations)
                 const startOfDay = new Date(`${ymd}T08:00:00`);
                 const endOfDay = new Date(`${ymd}T21:30:00`);
                 const booked = reservations.map(r => ({ start: new Date(r.start_time), end: new Date(r.end_time) }));
                 const ranges = [];
                 let cursor = new Date(startOfDay);
                 
                 function overlaps(s1,e1,s2,e2){ return s1 < e2 && e1 > s2; }
                 while (cursor < endOfDay) {
                     const next = new Date(cursor.getTime()+30*60000);
                     const slotBooked = booked.some(b => overlaps(cursor,next,b.start,b.end));
                     if (!slotBooked) {
                         if (ranges.length===0 || ranges[ranges.length-1].end.getTime() !== cursor.getTime()) {
                             ranges.push({ start: new Date(cursor), end: new Date(next) });
                         } else {
                             ranges[ranges.length-1].end = new Date(next);
                         }
                     }
                     cursor = next;
                 }
                 
                 const availableSection = document.createElement('div');
                 availableSection.innerHTML = `
                     <div class="flex items-center mb-2">
                         <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center mr-2">
                             <i class="fas fa-clock text-green-600 text-xs"></i>
                         </div>
                         <h6 class="text-sm font-bold text-gray-800">Available Time Slots</h6>
                     </div>
                 `;
                 
                 if (pendingReservations.length > 0) {
                     const pendingNote = document.createElement('div');
                     pendingNote.className = 'text-xs text-blue-600 mb-2 bg-blue-50 border border-blue-200 rounded p-2';
                     pendingNote.innerHTML = `
                         <i class="fas fa-info-circle mr-1"></i>
                         Available slots exclude both confirmed and pending reservations. Check pending section above for slots under consideration.
                     `;
                     availableSection.appendChild(pendingNote);
                 }
                 
                 if (ranges.length === 0) {
                     const noAvailableCard = document.createElement('div');
                     noAvailableCard.className = 'p-3 bg-red-50 border border-red-200 rounded-lg text-center';
                     noAvailableCard.innerHTML = `
                         <i class="fas fa-ban text-red-500 text-lg mb-1"></i>
                         <div class="text-sm font-semibold text-red-800">Fully Booked</div>
                         <div class="text-xs text-red-600">No available times on this day</div>
                     `;
                     availableSection.appendChild(noAvailableCard);
                 } else {
                     const totalAvailableHours = ranges.reduce((total, r) => total + (r.end - r.start) / (1000 * 60 * 60), 0);
                     const summaryCard = document.createElement('div');
                     summaryCard.className = 'p-3 bg-green-50 border border-green-200 rounded-lg mb-2 text-center';
                     summaryCard.innerHTML = `
                         <div class="text-lg font-bold text-green-800">${totalAvailableHours.toFixed(1)} hours</div>
                         <div class="text-xs text-green-600">Total available time</div>
                     `;
                     availableSection.appendChild(summaryCard);
                     
                     // Show first few available slots
                     ranges.slice(0, 5).forEach(r => {
                         const rangeCard = document.createElement('div');
                         rangeCard.className = 'p-2 bg-white border border-gray-200 rounded mb-1 shadow-sm';
                         const duration = (r.end - r.start) / (1000 * 60 * 60);
                         
                         rangeCard.innerHTML = `
                             <div class="flex items-center justify-between">
                                 <div class="flex items-center">
                                     <div class="w-4 h-4 bg-green-500 rounded-full flex items-center justify-center mr-2">
                                         <i class="fas fa-check text-white text-xs"></i>
                                     </div>
                                     <div>
                                         <div class="text-xs font-semibold text-gray-800">
                                             ${r.start.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${r.end.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}
                                         </div>
                                         <div class="text-xs text-gray-500">${duration.toFixed(1)}h available</div>
                                     </div>
                                 </div>
                             </div>
                         `;
                         availableSection.appendChild(rangeCard);
                     });
                     
                     if (ranges.length > 5) {
                         const moreCard = document.createElement('div');
                         moreCard.className = 'p-2 bg-gray-50 border border-gray-200 rounded text-center';
                         moreCard.innerHTML = `
                             <div class="text-xs text-gray-600">+${ranges.length - 5} more time slots available</div>
                         `;
                         availableSection.appendChild(moreCard);
                     }
                 }
                 container.appendChild(availableSection);
                 
                 // Fallback: Ensure content is always shown
                 if (container.children.length === 0) {
                     console.log('No content added, showing fallback');
                     const fallbackSection = document.createElement('div');
                     fallbackSection.className = 'text-center py-6 text-gray-500 bg-white rounded-lg border border-gray-200 shadow-sm';
                     fallbackSection.innerHTML = `
                         <i class="fas fa-calendar-day text-3xl text-gray-300 mb-2"></i>
                         <p class="text-sm font-medium">No data available</p>
                         <p class="text-xs">Unable to load day details</p>
                     `;
                     container.appendChild(fallbackSection);
                 }
                 
                 console.log('renderDayDetailsForPopup completed, container has', container.children.length, 'children');
             };
             
             // Initialize pricing options
             initializePricingOptions();
             
             // Add click event listeners to pricing option cards
             document.querySelectorAll('.pricing-option-card').forEach(card => {
                 card.addEventListener('click', function() {
                     const radio = this.querySelector('input[type="radio"]');
                     if (radio && !radio.disabled) {
                         radio.checked = true;
                         radio.dispatchEvent(new Event('change'));
                         
                         // Add click animation
                         this.style.transform = 'scale(0.98)';
                         setTimeout(() => {
                             this.style.transform = 'scale(1.05)';
                         }, 150);
                     }
                 });
             });
             
             // Add event listeners for booking type radio buttons and cards
             document.querySelectorAll('.booking-type-radio').forEach(radio => {
                 radio.addEventListener('change', updateBookingType);
             });
             // Add click event listeners to booking type cards for better UX
             document.querySelectorAll('.booking-type-option').forEach(card => {
                 card.addEventListener('click', function() {
                     const radio = this.querySelector('input[type="radio"]');
                     if (radio && !radio.disabled) {
                         radio.checked = true;
                         updateBookingType();
                         // Add click animation
                         this.style.transform = 'scale(0.98)';
                         setTimeout(() => {
                             this.style.transform = 'scale(1.02)';
                         }, 150);
                     }
                 });
             });
             // Initialize booking type selection after elements are ready
             setTimeout(() => {
                 updateBookingType();
             }, 100);
             // Auto-fill form from URL parameters
             autoFillFromURL();
             // Event listeners
            // Date is selected exclusively via modal; keep a display label in sync when set elsewhere
             bookingDateInput.addEventListener('change', function() {
                const disp = document.getElementById('booking_date_display');
                if (disp && bookingDateInput.value) {
                    disp.textContent = new Date(bookingDateInput.value).toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
                }
                 updateCalendarHighlighting();
                 generateTimeSlots();
                 showExistingBookings();
                 updateCostPreview();
             });
            startTimeInputField.addEventListener('change', function() {
                updateCostPreview();
                checkTimeConflicts();
            });
            endTimeInputField.addEventListener('change', function() {
                updateCostPreview();
                checkTimeConflicts();
            });

            // Ensure calendar modal opens on click (bind at DOM ready)
            (function bindCalendarModalAtReady(){
                const calendarModal = document.getElementById('calendarModal');
                const openCalBtn = document.getElementById('openCalendarModal');
                const closeCalBtn = document.getElementById('closeCalendarModal');
                const calPrev = document.getElementById('calPrev');
                const calNext = document.getElementById('calNext');
                const monthlyCalendar = document.getElementById('monthlyCalendar');
                const confirmBtn = document.getElementById('confirmCalendarSelection');
                const selectedDateLabelEl = document.getElementById('selectedDateLabel');
                if (!openCalBtn || !calendarModal || !monthlyCalendar) return;
                const availabilityContainer = document.getElementById('availabilityCalendar');
                const availabilityWrapper = availabilityContainer ? availabilityContainer.parentElement : null;
                openCalBtn.addEventListener('click', function(){
                    calendarModal.classList.remove('hidden');
                    if (availabilityWrapper) availabilityWrapper.classList.add('hidden');
                    
                    // Simple calendar rendering
                    const selected = bookingDateInput && bookingDateInput.value ? new Date(bookingDateInput.value) : new Date();
                    renderSimpleCalendar(selected);
                    
                    // Test: Show initial content in day details
                    const testContainer = document.getElementById('dayDetails');
                    if (testContainer) {
                        testContainer.innerHTML = `
                            <div class="text-center py-6 text-gray-500 bg-white rounded-lg border border-gray-200 shadow-sm">
                                <i class="fas fa-calendar-day text-3xl text-gray-300 mb-2"></i>
                                <p class="text-sm font-medium">Select a day to view details</p>
                                <p class="text-xs">See reservations, availability, and ongoing usage</p>
                            </div>
                        `;
                        console.log('Initial content set in dayDetails');
                    }
                    
                    // Animate modal in
                    setTimeout(() => {
                        const modalContent = document.getElementById('calendarModalContent');
                        if (modalContent) {
                            modalContent.classList.remove('scale-95', 'opacity-0');
                            modalContent.classList.add('scale-100', 'opacity-100');
                        }
                    }, 10);
                });
                if (confirmBtn) confirmBtn.addEventListener('click', function(){
                    // Find the currently selected day
                    const selectedCell = document.querySelector('#monthlyCalendar button[data-selected-date]');
                    if (!selectedCell) {
                        alert('Please select a date first');
                        return;
                    }
                    
                    const ymd = selectedCell.dataset.selectedDate;
                    const dateDisplay = selectedCell.dataset.selectedDisplay;
                    
                    // Update the booking form
                    if (bookingDateInput) bookingDateInput.value = ymd;
                    const disp = document.getElementById('booking_date_display');
                    if (disp) disp.textContent = dateDisplay;
                    
                    // Regenerate calendar and time slots
                    if (typeof generateAvailabilityCalendar === 'function') {
                        generateAvailabilityCalendar();
                    }
                    if (typeof generateTimeSlots === 'function') {
                        generateTimeSlots();
                    }
                    if (typeof showExistingBookings === 'function') {
                        showExistingBookings();
                    }
                    if (typeof updateCostPreview === 'function') {
                        updateCostPreview();
                    }
                    
                    // Animate modal out
                    const modalContent = document.getElementById('calendarModalContent');
                    if (modalContent) {
                        modalContent.classList.add('scale-95', 'opacity-0');
                        modalContent.classList.remove('scale-100', 'opacity-100');
                    }
                    setTimeout(() => {
                        calendarModal.classList.add('hidden');
                        if (availabilityWrapper) availabilityWrapper.classList.remove('hidden');
                    }, 300);
                    
                    // Show success notification
                    if (typeof showSuccessNotification === 'function') {
                        showSuccessNotification(`Selected ${dateDisplay}`);
                    }
                });
                if (closeCalBtn) closeCalBtn.addEventListener('click', function(){
                    const modalContent = document.getElementById('calendarModalContent');
                    if (modalContent) {
                        modalContent.classList.add('scale-95', 'opacity-0');
                        modalContent.classList.remove('scale-100', 'opacity-100');
                    }
                    setTimeout(() => {
                        calendarModal.classList.add('hidden');
                        if (availabilityWrapper) availabilityWrapper.classList.remove('hidden');
                    }, 300);
                });
                
                // Scroll functionality removed - using native scrolling
                
                // Add keyboard navigation
                calendarModal.addEventListener('keydown', function(e) {
                    if (calendarModal.classList.contains('hidden')) return;
                    
                    switch(e.key) {
                        case 'Escape':
                            e.preventDefault();
                            if (closeCalBtn) closeCalBtn.click();
                            break;
                        case 'Enter':
                            e.preventDefault();
                            if (confirmBtn && !confirmBtn.disabled) {
                                confirmBtn.click();
                            }
                            break;
                    }
                });
                
                calendarModal.addEventListener('click', function(e){
                    if (e.target === calendarModal) {
                        const modalContent = document.getElementById('calendarModalContent');
                        if (modalContent) {
                            modalContent.classList.add('scale-95', 'opacity-0');
                            modalContent.classList.remove('scale-100', 'opacity-100');
                        }
                        setTimeout(() => {
                            calendarModal.classList.add('hidden');
                            if (availabilityWrapper) availabilityWrapper.classList.remove('hidden');
                        }, 300);
                    }
                });
                if (calPrev) calPrev.addEventListener('click', function(){ if (typeof calCurrent !== 'undefined' && typeof renderMonth==='function'){ calCurrent.setMonth(calCurrent.getMonth()-1); renderMonth(calCurrent); }});
                if (calNext) calNext.addEventListener('click', function(){ if (typeof calCurrent !== 'undefined' && typeof renderMonth==='function'){ calCurrent.setMonth(calCurrent.getMonth()+1); renderMonth(calCurrent); }});
            })();
            // Quick time chip handlers
            document.querySelectorAll('.quick-time').forEach(btn => {
                btn.addEventListener('click', function(){
                    if (startTimeInputField) {
                        startTimeInputField.value = this.getAttribute('data-time');
                        updateCostPreview();
                        checkTimeConflicts();
                    }
                });
            });
            document.querySelectorAll('.quick-end').forEach(btn => {
                btn.addEventListener('click', function(){
                    if (endTimeInputField) {
                        endTimeInputField.value = this.getAttribute('data-time');
                        updateCostPreview();
                        checkTimeConflicts();
                    }
                });
            });
            // Form submission with loading state
            document.getElementById('reservationForm').addEventListener('submit', function(e) {
                const selectedDate = bookingDateInput.value;
                const startTime = startTimeInputField.value;
                const endTime = endTimeInputField.value;
                const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
                
                // Check if all required fields are filled
                if (!selectedDate || !startTime || !endTime) {
                    e.preventDefault();
                    if (window.ModalSystem) {
                        window.ModalSystem.alert('Please fill in all date and time fields.', 'Date/Time Required', 'warning');
                    } else {
                        alert('Please fill in all date and time fields.');
                    }
                    return;
                }
                
                // Check for time conflicts before allowing submission
                if (!checkTimeConflicts()) {
                    e.preventDefault();
                    if (window.ModalSystem) {
                        window.ModalSystem.alert('Please fix the time conflicts before submitting.', 'Time Conflicts Detected', 'warning');
                    } else {
                        alert('Please fix the time conflicts before submitting.');
                    }
                    return;
                }
                // For hourly bookings, validate that end time is after start time
                const startDateTime = new Date(`${selectedDate}T${startTime}`);
                const endDateTime = new Date(`${selectedDate}T${endTime}`);
                if (endDateTime <= startDateTime) {
                    e.preventDefault();
                    if (window.ModalSystem) {
                        window.ModalSystem.alert('End date/time must be after start date/time.', 'Invalid Time Range', 'warning');
                    } else {
                        alert('End date/time must be after start date/time.');
                    }
                    return;
                }
                // Update hidden inputs for hourly booking
                startTimeInput.value = `${selectedDate} ${startTime}:00`;
                endTimeInput.value = `${selectedDate} ${endTime}:00`;
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                // Show loading overlay
                const loadingOverlay = document.getElementById('loading-overlay');
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.opacity = '1';
            });
            // Real-time validation
            document.getElementById('purpose').addEventListener('input', function() {
                if (this.value.length > 0) {
                    this.classList.remove('border-red-300');
                    this.classList.add('border-green-300');
                } else {
                    this.classList.remove('border-green-300');
                    this.classList.add('border-red-300');
                }
            });
                                                  // Initialize calendar and time slots
                         generateAvailabilityCalendar();
                         
                         // Initialize date display if prefilled (e.g., from URL)
                         if (bookingDateInput.value) {
                             const disp = document.getElementById('booking_date_display');
                             if (disp) disp.textContent = new Date(bookingDateInput.value).toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
                         }
                         updateCalendarHighlighting();
                         
                         if (bookingDateInput.value) {
                             generateTimeSlots();
                             showExistingBookings();
                             updateCostPreview();
                         }
                         
            // Initialize current time display and update every second
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            // Debug: Log when elements are found
            console.log('Calendar modal elements:', {
                calendarModal: !!document.getElementById('calendarModal'),
                openCalBtn: !!document.getElementById('openCalendarModal'),
                monthlyCalendar: !!document.getElementById('monthlyCalendar'),
                costPreview: !!document.getElementById('costPreview'),
                bookingDateInput: !!document.getElementById('booking_date'),
                startTimeInputField: !!document.getElementById('start_time_input'),
                endTimeInputField: !!document.getElementById('end_time_input')
            });
            
            // Add event listeners to time inputs to trigger cost preview update
            if (startTimeInputField) {
                startTimeInputField.addEventListener('change', updateCostPreview);
                startTimeInputField.addEventListener('input', updateCostPreview);
                console.log('Added event listeners to startTimeInputField');
            }
            if (endTimeInputField) {
                endTimeInputField.addEventListener('change', updateCostPreview);
                endTimeInputField.addEventListener('input', updateCostPreview);
                console.log('Added event listeners to endTimeInputField');
            }
            if (bookingDateInput) {
                bookingDateInput.addEventListener('change', updateCostPreview);
                console.log('Added event listener to bookingDateInput');
            }
            
            // Test cost preview immediately
            setTimeout(() => {
                console.log('Testing cost preview...');
                updateCostPreview();
            }, 1000);
        });
        // Success Modal Function
        function showSuccessModal(message) {
            const modalHtml = `
                <div id="success-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0" id="success-modal-content">
                        <div class="p-6 text-center">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Success!</h3>
                            <p class="text-gray-600 mb-6">${message}</p>
                            <div class="flex justify-center space-x-3">
                                <button onclick="closeSuccessModal()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-check mr-2"></i>Got it!
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            // Remove existing modal if any
            const existingModal = document.getElementById('success-modal');
            if (existingModal) {
                existingModal.remove();
            }
            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            // Animate modal in
            setTimeout(() => {
                const modalContent = document.getElementById('success-modal-content');
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            // Add event listeners
            const modal = document.getElementById('success-modal');
            // Close on backdrop click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeSuccessModal();
                }
            });
            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSuccessModal();
                }
            });
        }
        function closeSuccessModal() {
            const modal = document.getElementById('success-modal');
            if (modal) {
                const modalContent = document.getElementById('success-modal-content');
                modalContent.classList.add('scale-95', 'opacity-0');
                modalContent.classList.remove('scale-100', 'opacity-100');
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }
        }
    </script>
</body>
</html>
