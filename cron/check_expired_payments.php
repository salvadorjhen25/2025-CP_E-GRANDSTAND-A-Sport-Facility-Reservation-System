<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailMailer.php';

// This script should be run via cron job every hour
// Example cron job: 0 * * * * php /path/to/check_expired_payments.php

echo "Starting payment expiration check...\n";

try {
    $pdo = getDBConnection();
    $mailer = new EmailMailer();
    
    // Get current timestamp
    $current_time = date('Y-m-d H:i:s');
    
    // Find reservations with expired payments (past 24 hours on weekdays)
    $stmt = $pdo->prepare("
        SELECT r.*, u.email as user_email, u.full_name as user_name, f.name as facility_name
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN facilities f ON r.facility_id = f.id
        WHERE r.payment_status = 'pending'
        AND r.payment_due_at < ?
        AND r.status = 'pending'
        AND DAYOFWEEK(r.payment_due_at) NOT IN (1, 7) -- Exclude weekends (1=Sunday, 7=Saturday)
    ");
    
    $stmt->execute([$current_time]);
    $expired_reservations = $stmt->fetchAll();
    
    echo "Found " . count($expired_reservations) . " expired reservations.\n";
    
    foreach ($expired_reservations as $reservation) {
        // Update reservation status to expired
        $update_stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'expired', payment_status = 'expired', updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$reservation['id']]);
        
        // Log the expiration
        $log_stmt = $pdo->prepare("
            INSERT INTO payment_logs (reservation_id, action, notes, created_at)
            VALUES (?, 'expired', 'Automatic expiration due to non-payment', NOW())
        ");
        $log_stmt->execute([$reservation['id']]);
        
        // Send cancellation email to user
        $mailer->sendReservationCancelled(
            $reservation['user_email'],
            $reservation['user_name'],
            $reservation
        );
        
        echo "Expired reservation #{$reservation['id']} for {$reservation['user_name']}\n";
    }
    
    // Send payment reminders for reservations due within 2 hours
    $reminder_time = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $stmt = $pdo->prepare("
        SELECT r.*, u.email as user_email, u.full_name as user_name, f.name as facility_name
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN facilities f ON r.facility_id = f.id
        WHERE r.payment_status = 'pending'
        AND r.payment_due_at BETWEEN ? AND ?
        AND r.status = 'pending'
        AND r.payment_reminder_sent = 0
    ");
    
    $stmt->execute([$current_time, $reminder_time]);
    $reminder_reservations = $stmt->fetchAll();
    
    echo "Found " . count($reminder_reservations) . " reservations needing payment reminders.\n";
    
    foreach ($reminder_reservations as $reservation) {
        // Send payment reminder email
        $mailer->sendPaymentReminder(
            $reservation['user_email'],
            $reservation['user_name'],
            $reservation
        );
        
        // Mark reminder as sent
        $update_stmt = $pdo->prepare("
            UPDATE reservations 
            SET payment_reminder_sent = 1, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$reservation['id']]);
        
        echo "Sent payment reminder for reservation #{$reservation['id']} to {$reservation['user_name']}\n";
    }
    
    echo "Payment expiration check completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Payment expiration check error: " . $e->getMessage());
}
