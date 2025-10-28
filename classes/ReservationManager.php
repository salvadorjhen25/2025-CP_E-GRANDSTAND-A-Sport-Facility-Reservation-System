<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PaymentManager.php';
require_once __DIR__ . '/EmailMailer.php';
class ReservationManager {
    private $pdo;
    private $paymentManager;
    public function __construct() {
        $this->pdo = getDBConnection();
        $this->paymentManager = new PaymentManager();
    }
    /**
     * Cancel a reservation with refund logic
     */
    public function cancelReservation($reservationId, $userId, $reason = '', $adminOverride = false) {
        try {
            $this->pdo->beginTransaction();
            // Get reservation details
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name, u.email as user_email,
                       f.cancellation_policy, f.hourly_rate
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ? AND r.user_id = ?
            ");
            $stmt->execute([$reservationId, $userId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found or you do not have permission to cancel it');
            }
            // Check if reservation can be cancelled
            if (!$adminOverride && !$this->canBeCancelled($reservation)) {
                $cancelDeadline = $this->getCancellationDeadline($reservation);
                throw new Exception('Reservation cannot be cancelled. Cancellation deadline: ' . $cancelDeadline->format('M j, Y g:i A'));
            }
            // Calculate refund amount
            $refundData = $this->calculateRefund($reservation);
            // Update reservation status
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET status = 'cancelled',
                    cancelled_at = NOW(),
                    cancellation_reason = ?,
                    refund_amount = ?,
                    refund_percentage = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $reason,
                $refundData['amount'],
                $refundData['percentage'],
                $reservationId
            ]);
            // Log the cancellation
            $this->logReservationAction($reservationId, 'cancelled', $userId, $reason);
            // Send cancellation email
            try {
                $mailer = new EmailMailer();
                $mailer->sendCancellationConfirmation(
                    $reservation['user_email'],
                    $reservation['user_name'],
                    $reservation,
                    $refundData
                );
            } catch (Exception $e) {
                error_log("Cancellation email error: " . $e->getMessage());
            }
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Reservation cancelled successfully',
                'refund_data' => $refundData,
                'reservation' => $reservation
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    /**
     * Reschedule a reservation
     */
    public function rescheduleReservation($reservationId, $userId, $newStartTime, $newEndTime, $reason = '') {
        try {
            $this->pdo->beginTransaction();
            // Get current reservation
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name, u.email as user_email
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ? AND r.user_id = ?
            ");
            $stmt->execute([$reservationId, $userId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found or you do not have permission to reschedule it');
            }
            // Check if reservation can be rescheduled
            if (!$this->canBeRescheduled($reservation)) {
                throw new Exception('Reservation cannot be rescheduled. It may have already started or been completed.');
            }
            // Validate new time slot
            $validation = $this->validateTimeSlot($reservation['facility_id'], $newStartTime, $newEndTime, $reservationId);
            if (!$validation['valid']) {
                throw new Exception($validation['message']);
            }
            // Calculate new cost
            $newCost = $this->calculateCost($reservation['facility_id'], $newStartTime, $newEndTime, $reservation['booking_type']);
            $costDifference = $newCost - $reservation['total_amount'];
            // Store original details for logging
            $originalStartTime = $reservation['start_time'];
            $originalEndTime = $reservation['end_time'];
            // Update reservation
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET start_time = ?,
                    end_time = ?,
                    total_amount = ?,
                    rescheduled_at = NOW(),
                    reschedule_reason = ?,
                    original_start_time = ?,
                    original_end_time = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $newStartTime,
                $newEndTime,
                $newCost,
                $reason,
                $originalStartTime,
                $originalEndTime,
                $reservationId
            ]);
            // Log the rescheduling
            $logMessage = "Rescheduled from {$originalStartTime} - {$originalEndTime} to {$newStartTime} - {$newEndTime}";
            if ($reason) {
                $logMessage .= ". Reason: {$reason}";
            }
            $this->logReservationAction($reservationId, 'rescheduled', $userId, $logMessage);
            // Send rescheduling email
            try {
                $mailer = new EmailMailer();
                $reservation['start_time'] = $newStartTime;
                $reservation['end_time'] = $newEndTime;
                $reservation['total_amount'] = $newCost;
                $mailer->sendRescheduleConfirmation(
                    $reservation['user_email'],
                    $reservation['user_name'],
                    $reservation,
                    $costDifference
                );
            } catch (Exception $e) {
                error_log("Reschedule email error: " . $e->getMessage());
            }
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Reservation rescheduled successfully',
                'cost_difference' => $costDifference,
                'new_cost' => $newCost,
                'reservation' => $reservation
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    /**
     * Extend reservation time
     */
    public function extendReservation($reservationId, $userId, $newEndTime, $reason = '') {
        try {
            $this->pdo->beginTransaction();
            // Get current reservation
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name, u.email as user_email
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ? AND r.user_id = ?
            ");
            $stmt->execute([$reservationId, $userId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found or you do not have permission to extend it');
            }
            // Check if reservation can be extended
            if (!$this->canBeExtended($reservation)) {
                throw new Exception('Reservation cannot be extended. It may have already ended or been completed.');
            }
            // Validate that new end time is after current end time
            $currentEndTime = new DateTime($reservation['end_time']);
            $newEnd = new DateTime($newEndTime);
            if ($newEnd <= $currentEndTime) {
                throw new Exception('New end time must be after the current end time');
            }
            // Check for conflicts with other reservations
            $conflict = $this->checkTimeConflict($reservation['facility_id'], $reservation['end_time'], $newEndTime, $reservationId);
            if ($conflict) {
                throw new Exception('Cannot extend reservation. Another reservation is scheduled during the requested time.');
            }
            // Calculate additional cost
            $originalCost = $reservation['total_amount'];
            $newCost = $this->calculateCost($reservation['facility_id'], $reservation['start_time'], $newEndTime, $reservation['booking_type']);
            $additionalCost = $newCost - $originalCost;
            // Store original end time for logging
            $originalEndTime = $reservation['end_time'];
            // Update reservation
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET end_time = ?,
                    total_amount = ?,
                    extended_at = NOW(),
                    extension_reason = ?,
                    original_end_time = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $newEndTime,
                $newCost,
                $reason,
                $originalEndTime,
                $reservationId
            ]);
            // Log the extension
            $logMessage = "Extended from {$originalEndTime} to {$newEndTime}";
            if ($reason) {
                $logMessage .= ". Reason: {$reason}";
            }
            $this->logReservationAction($reservationId, 'extended', $userId, $logMessage);
            // Send extension email
            try {
                $mailer = new EmailMailer();
                $reservation['end_time'] = $newEndTime;
                $reservation['total_amount'] = $newCost;
                $mailer->sendExtensionConfirmation(
                    $reservation['user_email'],
                    $reservation['user_name'],
                    $reservation,
                    $additionalCost
                );
            } catch (Exception $e) {
                error_log("Extension email error: " . $e->getMessage());
            }
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Reservation extended successfully',
                'additional_cost' => $additionalCost,
                'new_cost' => $newCost,
                'new_end_time' => $newEndTime,
                'reservation' => $reservation
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    /**
     * Check if reservation can be cancelled
     */
    private function canBeCancelled($reservation) {
        // Cannot cancel if already cancelled, completed, or no-show
        if (in_array($reservation['status'], ['cancelled', 'completed', 'no_show'])) {
            return false;
        }
        // Cannot cancel if already started
        if ($reservation['usage_started_at']) {
            return false;
        }
        // Check cancellation deadline (24 hours before start time by default)
        $deadline = $this->getCancellationDeadline($reservation);
        $now = new DateTime();
        return $now <= $deadline;
    }
    /**
     * Check if reservation can be rescheduled
     */
    private function canBeRescheduled($reservation) {
        // Cannot reschedule if already cancelled, completed, or no-show
        if (in_array($reservation['status'], ['cancelled', 'completed', 'no_show'])) {
            return false;
        }
        // Cannot reschedule if already started
        if ($reservation['usage_started_at']) {
            return false;
        }
        // Check rescheduling deadline (2 hours before start time)
        $startTime = new DateTime($reservation['start_time']);
        $deadline = clone $startTime; // Clone to avoid modifying original
        $deadline->sub(new DateInterval('PT2H'));
        $now = new DateTime();
        return $now <= $deadline;
    }
    /**
     * Check if reservation can be extended
     */
    private function canBeExtended($reservation) {
        // Cannot extend if cancelled or completed
        if (in_array($reservation['status'], ['cancelled', 'completed', 'no_show'])) {
            return false;
        }
        // Can extend if currently in use or confirmed and within extension window
        $endTime = new DateTime($reservation['end_time']);
        $now = new DateTime();
        // Allow extension up to 30 minutes after end time
        $extensionDeadline = clone $endTime; // Clone to avoid modifying original
        $extensionDeadline->add(new DateInterval('PT30M'));
        return $now <= $extensionDeadline;
    }
    /**
     * Get cancellation deadline
     */
    private function getCancellationDeadline($reservation) {
        $startTime = new DateTime($reservation['start_time']);
        // Default: 24 hours before start time
        $deadline = clone $startTime; // Clone to avoid modifying original
        return $deadline->sub(new DateInterval('PT24H'));
    }
    /**
     * Calculate refund amount based on cancellation policy
     */
    private function calculateRefund($reservation) {
        $totalAmount = $reservation['total_amount'];
        $startTime = new DateTime($reservation['start_time']);
        $now = new DateTime();
        $hoursUntilStart = $now->diff($startTime)->days * 24 + $now->diff($startTime)->h;
        // Default refund policy
        if ($hoursUntilStart >= 24) {
            // 100% refund if cancelled 24+ hours before
            $percentage = 100;
        } elseif ($hoursUntilStart >= 12) {
            // 75% refund if cancelled 12-24 hours before
            $percentage = 75;
        } elseif ($hoursUntilStart >= 6) {
            // 50% refund if cancelled 6-12 hours before
            $percentage = 50;
        } elseif ($hoursUntilStart >= 2) {
            // 25% refund if cancelled 2-6 hours before
            $percentage = 25;
        } else {
            // No refund if cancelled less than 2 hours before
            $percentage = 0;
        }
        $refundAmount = ($totalAmount * $percentage) / 100;
        return [
            'amount' => $refundAmount,
            'percentage' => $percentage,
            'hours_until_start' => $hoursUntilStart
        ];
    }
    /**
     * Validate time slot availability
     */
    private function validateTimeSlot($facilityId, $startTime, $endTime, $excludeReservationId = null) {
        // Check basic time validation
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $now = new DateTime();
        if ($start >= $end) {
            return ['valid' => false, 'message' => 'End time must be after start time'];
        }
        if ($start <= $now) {
            return ['valid' => false, 'message' => 'Start time must be in the future'];
        }
        // Check for conflicts
        $sql = "
            SELECT COUNT(*) as conflicts
            FROM reservations 
            WHERE facility_id = ? 
            AND status NOT IN ('cancelled', 'no_show')
            AND (
                (start_time < ? AND end_time > ?) OR
                (start_time < ? AND end_time > ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ";
        $params = [$facilityId, $endTime, $startTime, $startTime, $endTime, $startTime, $endTime];
        if ($excludeReservationId) {
            $sql .= " AND id != ?";
            $params[] = $excludeReservationId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        if ($result['conflicts'] > 0) {
            return ['valid' => false, 'message' => 'Time slot is not available'];
        }
        return ['valid' => true, 'message' => 'Time slot is available'];
    }
    /**
     * Check for time conflicts
     */
    private function checkTimeConflict($facilityId, $startTime, $endTime, $excludeReservationId = null) {
        $validation = $this->validateTimeSlot($facilityId, $startTime, $endTime, $excludeReservationId);
        return !$validation['valid'];
    }
    /**
     * Calculate cost for a time period
     */
    private function calculateCost($facilityId, $startTime, $endTime, $bookingType) {
        // Get facility rates
        $stmt = $this->pdo->prepare("SELECT hourly_rate FROM facilities WHERE id = ?");
        $stmt->execute([$facilityId]);
        $facility = $stmt->fetch();
        if (!$facility) {
            throw new Exception('Facility not found');
        }
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $duration = $start->diff($end);
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
        return $facility['hourly_rate'] * $hours;
    }
    /**
     * Log reservation actions
     */
    private function logReservationAction($reservationId, $action, $userId, $notes = '') {
        $stmt = $this->pdo->prepare("
            INSERT INTO reservation_logs (reservation_id, action, user_id, notes, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$reservationId, $action, $userId, $notes]);
    }
}
?>
