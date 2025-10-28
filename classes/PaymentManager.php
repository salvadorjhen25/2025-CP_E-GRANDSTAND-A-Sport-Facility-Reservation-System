<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailMailer.php';
class PaymentManager {
    private $pdo;
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    /**
     * Create a new reservation with 24-hour payment deadline
     */
    public function createReservation($userId, $facilityId, $startTime, $endTime, $purpose, $totalAmount, $phoneNumber, $bookingType = 'hourly', $durationHours = 0) {
        try {
            $this->pdo->beginTransaction();
            // Calculate payment due time (24 hours from now)
            $paymentDueAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $stmt = $this->pdo->prepare("
                INSERT INTO reservations (user_id, facility_id, booking_type, booking_duration_hours, start_time, end_time, purpose, phone_number, total_amount, payment_due_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $facilityId, $bookingType, $durationHours, $startTime, $endTime, $purpose, $phoneNumber, $totalAmount, $paymentDueAt]);
            $reservationId = $this->pdo->lastInsertId();
            // Log the reservation creation
            $this->logPaymentAction($reservationId, 'created', null, 'Reservation created with 24-hour payment deadline');
            $this->pdo->commit();
            return $reservationId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    /**
     * Upload payment slip
     */
    public function uploadPaymentSlip($reservationId, $slipUrl) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET payment_slip_url = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND payment_status = 'pending'
            ");
            $result = $stmt->execute([$slipUrl, $reservationId]);
            if ($result && $stmt->rowCount() > 0) {
                $this->logPaymentAction($reservationId, 'uploaded', null, 'Payment slip uploaded');
                // Auto-approval disabled - admin must manually verify payment
                // $this->autoApproveIfEligible($reservationId);
                return true;
            }
            return false;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Auto-approve reservation if payment slip is uploaded and within grace period
     */
    private function autoApproveIfEligible($reservationId) {
        try {
            // Get reservation details
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name, u.email as user_email
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ? AND r.payment_slip_url IS NOT NULL AND r.payment_status = 'pending'
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                return false;
            }
            // Check if reservation is within grace period (e.g., 2 hours before start time)
            $startTime = new DateTime($reservation['start_time']);
            $currentTime = new DateTime();
            $gracePeriod = new DateInterval('PT2H'); // 2 hours
            $graceDeadline = $startTime->sub($gracePeriod);
            // Auto-approve if within grace period or if reservation has already started
            if ($currentTime >= $graceDeadline) {
                return $this->verifyPayment($reservationId, null, true, 'Auto-approved: Payment slip uploaded within grace period');
            }
            return false;
        } catch (Exception $e) {
            error_log("Auto-approval error: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Verify payment by admin
     */
    public function verifyPayment($reservationId, $adminId, $approved = true, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            $paymentStatus = $approved ? 'paid' : 'pending';
            $verifiedAt = $approved ? date('Y-m-d H:i:s') : null;
            $reservationStatus = $approved ? 'confirmed' : 'pending';
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET payment_status = ?, payment_verified_at = ?, verified_by = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$paymentStatus, $verifiedAt, $adminId, $reservationStatus, $reservationId]);
            if ($stmt->rowCount() > 0) {
                $action = $approved ? 'verified' : 'rejected';
                $this->logPaymentAction($reservationId, $action, $adminId, $notes);
                // Send email notification if payment is approved
                if ($approved) {
                    try {
                        $mailer = new EmailMailer();
                        // Get reservation details for email
                        $stmt = $this->pdo->prepare("
                            SELECT r.*, u.email as user_email, u.full_name as user_name, f.name as facility_name
                            FROM reservations r
                            JOIN users u ON r.user_id = u.id
                            JOIN facilities f ON r.facility_id = f.id
                            WHERE r.id = ?
                        ");
                        $stmt->execute([$reservationId]);
                        $reservation = $stmt->fetch();
                        if ($reservation) {
                            $mailer->sendPaymentConfirmed(
                                $reservation['user_email'],
                                $reservation['user_name'],
                                $reservation
                            );
                        }
                    } catch (Exception $e) {
                        error_log("Payment confirmation email error: " . $e->getMessage());
                        // Don't fail payment verification if email fails
                    }
                }
                $this->pdo->commit();
                return true;
            }
            $this->pdo->rollBack();
            return false;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    /**
     * Check and expire overdue payments
     */
    public function checkExpiredPayments() {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET payment_status = 'expired', status = 'expired', updated_at = CURRENT_TIMESTAMP
                WHERE payment_status = 'pending' 
                AND payment_due_at < NOW()
                AND status = 'pending'
            ");
            $stmt->execute();
            $expiredCount = $stmt->rowCount();
            if ($expiredCount > 0) {
                // Get expired reservations to process waitlist
                $stmt = $this->pdo->prepare("
                    SELECT id, facility_id, start_time, end_time
                    FROM reservations 
                    WHERE payment_status = 'expired' 
                    AND status = 'expired'
                    AND updated_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                ");
                $stmt->execute();
                $expiredReservations = $stmt->fetchAll();
                foreach ($expiredReservations as $reservation) {
                    $this->processWaitlistForExpiredSlot($reservation);
                    $this->logPaymentAction($reservation['id'], 'expired', null, 'Payment expired - slot released');
                }
            }
            return $expiredCount;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Mark reservation as no-show
     */
    public function markAsNoShow($reservationId, $adminId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET status = 'no_show', updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'confirmed'
            ");
            $result = $stmt->execute([$reservationId]);
            if ($result && $stmt->rowCount() > 0) {
                // Get reservation details for email notification and waitlist processing
                $stmt = $this->pdo->prepare("
                    SELECT r.*, u.email as user_email, u.full_name as user_name, f.name as facility_name
                    FROM reservations r
                    JOIN users u ON r.user_id = u.id
                    JOIN facilities f ON r.facility_id = f.id
                    WHERE r.id = ?
                ");
                $stmt->execute([$reservationId]);
                $reservation = $stmt->fetch();
                if ($reservation) {
                    // Send no-show email notification
                    try {
                        $mailer = new EmailMailer();
                        $mailer->sendNoShowNotification(
                            $reservation['user_email'],
                            $reservation['user_name'],
                            $reservation
                        );
                    } catch (Exception $e) {
                        error_log("Failed to send no-show email: " . $e->getMessage());
                    }
                    // Process waitlist for the vacated slot
                    $this->processWaitlistForExpiredSlot($reservation);
                }
                $this->logPaymentAction($reservationId, 'no_show', $adminId, 'Reservation marked as no-show');
                return true;
            }
            return false;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Add user to waitlist
     */
    public function addToWaitlist($userId, $facilityId, $startTime, $endTime) {
        try {
            // Check if user is already on waitlist for this time slot
            $stmt = $this->pdo->prepare("
                SELECT id FROM waitlist 
                WHERE user_id = ? AND facility_id = ? 
                AND desired_start_time = ? AND desired_end_time = ?
                AND status = 'waiting'
            ");
            $stmt->execute([$userId, $facilityId, $startTime, $endTime]);
            if ($stmt->fetch()) {
                return false; // Already on waitlist
            }
            // Calculate priority score (based on how long they've been waiting)
            $priorityScore = time();
            $stmt = $this->pdo->prepare("
                INSERT INTO waitlist (user_id, facility_id, desired_start_time, desired_end_time, priority_score)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $facilityId, $startTime, $endTime, $priorityScore]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Process waitlist when a slot becomes available
     */
    private function processWaitlistForExpiredSlot($expiredReservation) {
        try {
            // Find the highest priority user on waitlist for this facility and time
            $stmt = $this->pdo->prepare("
                SELECT w.*, u.email, u.full_name
                FROM waitlist w
                JOIN users u ON w.user_id = u.id
                WHERE w.facility_id = ? 
                AND w.desired_start_time = ?
                AND w.desired_end_time = ?
                AND w.status = 'waiting'
                ORDER BY w.priority_score DESC, w.created_at ASC
                LIMIT 1
            ");
            $stmt->execute([
                $expiredReservation['facility_id'],
                $expiredReservation['start_time'],
                $expiredReservation['end_time']
            ]);
            $waitlistEntry = $stmt->fetch();
            if ($waitlistEntry) {
                // Mark as notified
                $stmt = $this->pdo->prepare("
                    UPDATE waitlist 
                    SET status = 'notified', updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$waitlistEntry['id']]);
                // Here you would typically send an email notification
                // For now, we'll just log it
                $this->logWaitlistNotification($waitlistEntry);
            }
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Get user's waitlist entries
     */
    public function getUserWaitlist($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT w.*, f.name as facility_name, f.hourly_rate
                FROM waitlist w
                JOIN facilities f ON w.facility_id = f.id
                WHERE w.user_id = ?
                ORDER BY w.created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Remove user from waitlist
     */
    public function removeFromWaitlist($waitlistId, $userId) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM waitlist 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$waitlistId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Get reservations with pending payments
     */
    public function getPendingPayments() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.*, u.full_name, u.email, f.name as facility_name
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN facilities f ON r.facility_id = f.id
                WHERE r.payment_status = 'pending'
                AND r.payment_due_at > NOW()
                ORDER BY r.payment_due_at ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Get expired payments
     */
    public function getExpiredPayments() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.*, u.full_name, u.email, f.name as facility_name
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN facilities f ON r.facility_id = f.id
                WHERE r.payment_status = 'expired'
                ORDER BY r.payment_due_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Log payment actions
     */
    private function logPaymentAction($reservationId, $action, $adminId, $notes) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_logs (reservation_id, action, admin_id, notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$reservationId, $action, $adminId, $notes]);
        } catch (Exception $e) {
            // Log error but don't throw to avoid breaking main operations
            error_log("Failed to log payment action: " . $e->getMessage());
        }
    }
    /**
     * Log waitlist notification
     */
    private function logWaitlistNotification($waitlistEntry) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_logs (reservation_id, action, admin_id, notes)
                VALUES (0, 'waitlist_notified', NULL, ?)
            ");
            $notes = "Waitlist notification sent to {$waitlistEntry['full_name']} ({$waitlistEntry['email']}) for facility slot";
            $stmt->execute([$notes]);
        } catch (Exception $e) {
            error_log("Failed to log waitlist notification: " . $e->getMessage());
        }
    }
    /**
     * Get payment statistics
     */
    public function getPaymentStats() {
        try {
            $stats = [];
            // Pending payments
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, SUM(total_amount) as total
                FROM reservations 
                WHERE payment_status = 'pending' AND payment_due_at > NOW()
            ");
            $stmt->execute();
            $stats['pending'] = $stmt->fetch();
            // Expired payments
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, SUM(total_amount) as total
                FROM reservations 
                WHERE payment_status = 'expired'
            ");
            $stmt->execute();
            $stats['expired'] = $stmt->fetch();
            // Paid payments
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, SUM(total_amount) as total
                FROM reservations 
                WHERE payment_status = 'paid'
            ");
            $stmt->execute();
            $stats['paid'] = $stmt->fetch();
            return $stats;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Check if reservation is within grace period for payment verification
     */
    public function isWithinGracePeriod($reservationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT start_time, payment_status, payment_slip_url
                FROM reservations 
                WHERE id = ?
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                return false;
            }
            // If payment is already verified, no grace period needed
            if ($reservation['payment_status'] === 'paid') {
                return true;
            }
            // If no payment slip uploaded, not eligible
            if (!$reservation['payment_slip_url']) {
                return false;
            }
            $startTime = new DateTime($reservation['start_time']);
            $currentTime = new DateTime();
            // Grace period: 2 hours before start time OR if reservation has already started
            $gracePeriod = new DateInterval('PT2H'); // 2 hours
            $graceDeadline = clone $startTime;
            $graceDeadline->sub($gracePeriod);
            return $currentTime >= $graceDeadline;
        } catch (Exception $e) {
            error_log("Grace period check error: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Get grace period status for a reservation
     */
    public function getGracePeriodStatus($reservationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT start_time, payment_status, payment_slip_url, status
                FROM reservations 
                WHERE id = ?
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                return ['eligible' => false, 'reason' => 'Reservation not found'];
            }
            if ($reservation['payment_status'] === 'paid') {
                return ['eligible' => true, 'reason' => 'Payment verified'];
            }
            if (!$reservation['payment_slip_url']) {
                return ['eligible' => false, 'reason' => 'No payment slip uploaded'];
            }
            $startTime = new DateTime($reservation['start_time']);
            $currentTime = new DateTime();
            $gracePeriod = new DateInterval('PT2H');
            $graceDeadline = clone $startTime;
            $graceDeadline->sub($gracePeriod);
            if ($currentTime >= $graceDeadline) {
                $timeUntilStart = $currentTime->diff($startTime);
                return [
                    'eligible' => true, 
                    'reason' => 'Within grace period',
                    'time_until_start' => $timeUntilStart->format('%h hours %i minutes'),
                    'grace_deadline' => $graceDeadline->format('Y-m-d H:i:s')
                ];
            } else {
                $timeUntilGrace = $currentTime->diff($graceDeadline);
                return [
                    'eligible' => false, 
                    'reason' => 'Outside grace period',
                    'time_until_grace' => $timeUntilGrace->format('%h hours %i minutes'),
                    'grace_deadline' => $graceDeadline->format('Y-m-d H:i:s')
                ];
            }
        } catch (Exception $e) {
            error_log("Grace period status error: " . $e->getMessage());
            return ['eligible' => false, 'reason' => 'Error checking status'];
        }
    }
}
?>
