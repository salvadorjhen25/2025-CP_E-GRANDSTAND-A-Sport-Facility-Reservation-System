<?php
require_once __DIR__ . '/../config/database.php';
class UsageManager {
    private $pdo;
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    /**
     * Start facility usage for a reservation
     */
    public function startUsage($reservationId, $adminId = null, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            // Check if reservation exists and is eligible for usage
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name 
                FROM reservations r 
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ? 
                AND r.status = 'confirmed'
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found or not confirmed.');
            }
            // Check if usage already started
            if ($reservation['usage_started_at']) {
                throw new Exception('Usage already started for this reservation');
            }
            // Update reservation to mark usage started
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET usage_started_at = NOW(), 
                    status = 'in_use',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reservationId]);
            // Update existing usage log entry instead of creating a new one
            $stmt = $this->pdo->prepare("
                UPDATE usage_logs 
                SET action = 'started', 
                    status = 'active', 
                    started_at = NOW(),
                    notes = CONCAT(IFNULL(notes, ''), ' | ', ?),
                    updated_at = NOW()
                WHERE reservation_id = ? AND status = 'ready' AND action = 'confirmed'
            ");
            $stmt->execute([$notes, $reservationId]);
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Facility usage started successfully',
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
     * Complete facility usage for a reservation
     */
    public function completeUsage($reservationId, $adminId = null, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            // Check if reservation exists and usage is started
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name 
                FROM reservations r 
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ?
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found');
            }
            // Check if usage is started in usage_logs table
            $stmt = $this->pdo->prepare("
                SELECT * FROM usage_logs 
                WHERE reservation_id = ? AND (status = 'active' OR status = 'ready') AND action IN ('started', 'confirmed')
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$reservationId]);
            $usageLog = $stmt->fetch();
            if (!$usageLog) {
                throw new Exception('Usage not found for this reservation');
            }
            // Check if usage is already completed
            $stmt = $this->pdo->prepare("
                SELECT * FROM usage_logs 
                WHERE reservation_id = ? AND status = 'completed' AND action = 'completed'
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$reservationId]);
            $completedLog = $stmt->fetch();
            if ($completedLog) {
                throw new Exception('Usage already completed for this reservation');
            }
            // If usage is not started yet, start it first by updating the existing entry
            if ($usageLog['action'] === 'confirmed' && $usageLog['status'] === 'ready') {
                // Start the usage by updating the existing entry
                $stmt = $this->pdo->prepare("
                    UPDATE usage_logs 
                    SET action = 'started', 
                        status = 'active', 
                        started_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$usageLog['id']]);
                // Update the usageLog variable with the new data
                $usageLog['action'] = 'started';
                $usageLog['status'] = 'active';
                $usageLog['started_at'] = date('Y-m-d H:i:s');
            }
            // Ensure we have a started_at time for duration calculation
            if (!$usageLog['started_at']) {
                $usageLog['started_at'] = date('Y-m-d H:i:s');
            }
            // Calculate duration
            $durationMinutes = null;
            if ($usageLog['started_at']) {
                $startTime = new DateTime($usageLog['started_at']);
                $endTime = new DateTime();
                $durationMinutes = $endTime->diff($startTime)->i + ($endTime->diff($startTime)->h * 60) + ($endTime->diff($startTime)->days * 24 * 60);
            }
            // Update reservation to mark usage completed and move to history
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET usage_completed_at = NOW(), 
                    status = 'completed',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reservationId]);
            // Update the existing usage log entry instead of creating a new one
            $stmt = $this->pdo->prepare("
                UPDATE usage_logs 
                SET action = 'completed', 
                    status = 'completed', 
                    completed_at = NOW(), 
                    duration_minutes = ?,
                    notes = CONCAT(IFNULL(notes, ''), ' | ', ?),
                    updated_at = NOW()
                WHERE reservation_id = ? AND (status = 'active' OR status = 'ready')
            ");
            $stmt->execute([$durationMinutes, $notes, $reservationId]);
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Facility usage completed successfully! Usage has been moved to usage history.',
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
     * Verify facility usage completion (admin verification)
     */
    public function verifyUsage($reservationId, $adminId, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            // Check if reservation exists and usage is completed
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name 
                FROM reservations r 
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ? AND r.status = 'completed'
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation) {
                throw new Exception('Reservation not found or usage not completed');
            }
            // Update reservation to mark usage verified
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET usage_verified_by = ?, 
                    usage_notes = ?,
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$adminId, $notes, $reservationId]);
            // Update usage log entry
            $stmt = $this->pdo->prepare("
                UPDATE usage_logs 
                SET action = 'verified', 
                    status = 'verified', 
                    verified_at = NOW(), 
                    verified_by = ?,
                    notes = CONCAT(IFNULL(notes, ''), ' | ', ?),
                    updated_at = NOW()
                WHERE reservation_id = ? AND status = 'completed'
            ");
            $stmt->execute([$adminId, $notes, $reservationId]);
            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Facility usage verified successfully',
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
     * Get current facility usage status
     */
    public function getCurrentUsage($facilityId = null) {
        $sql = "
            SELECT ul.*, r.start_time, r.end_time, r.total_amount, r.purpose,
                   r.or_number, r.verified_by_staff_name, r.payment_verified_at,
                   f.name as facility_name, u.full_name as user_name,
                   admin.full_name as verified_by_admin,
                   TIMESTAMPDIFF(MINUTE, ul.started_at, NOW()) as usage_duration_minutes
            FROM usage_logs ul
            JOIN reservations r ON ul.reservation_id = r.id
            JOIN facilities f ON ul.facility_id = f.id 
            JOIN users u ON ul.user_id = u.id 
            LEFT JOIN users admin ON r.payment_verified_by = admin.id
            WHERE ul.status = 'active' AND ul.action = 'started'
        ";
        $params = [];
        if ($facilityId) {
            $sql .= " AND ul.facility_id = ?";
            $params[] = $facilityId;
        }
        $sql .= " ORDER BY ul.started_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    /**
     * Get ready usage (confirmed reservations ready to start)
     */
    public function getReadyUsage($facilityId = null) {
        $sql = "
            SELECT ul.*, r.start_time, r.end_time, r.total_amount, r.purpose,
                   r.or_number, r.verified_by_staff_name, r.payment_verified_at,
                   f.name as facility_name, u.full_name as user_name,
                   admin.full_name as verified_by_admin
            FROM usage_logs ul
            JOIN reservations r ON ul.reservation_id = r.id
            JOIN facilities f ON ul.facility_id = f.id 
            JOIN users u ON ul.user_id = u.id 
            LEFT JOIN users admin ON r.payment_verified_by = admin.id
            WHERE ul.status = 'ready' AND ul.action = 'confirmed'
            AND NOT EXISTS (
                SELECT 1 FROM usage_logs ul2 
                WHERE ul2.reservation_id = ul.reservation_id 
                AND ul2.status = 'active' AND ul2.action = 'started'
            )
        ";
        $params = [];
        if ($facilityId) {
            $sql .= " AND ul.facility_id = ?";
            $params[] = $facilityId;
        }
        $sql .= " ORDER BY r.start_time ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    /**
     * Get pending usage verifications
     */
    public function getPendingVerifications() {
        try {
            $sql = "
                SELECT ul.*, r.start_time, r.end_time, r.total_amount, r.purpose,
                       f.name as facility_name, u.full_name as user_name,
                       COALESCE(ul.duration_minutes, 0) as usage_duration_minutes
                FROM usage_logs ul
                JOIN reservations r ON ul.reservation_id = r.id
                JOIN facilities f ON ul.facility_id = f.id 
                JOIN users u ON ul.user_id = u.id 
                WHERE ul.status = 'completed' 
                  AND ul.action = 'completed'
                  AND (ul.verified_at IS NULL OR ul.verified_at = '')
                ORDER BY ul.completed_at DESC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            // If table doesn't exist or columns are missing, return empty array
            return [];
        }
    }
    /**
     * Get usage history for a facility
     */
    public function getUsageHistory($facilityId, $limit = 50) {
        try {
            $sql = "
                SELECT ul.*, r.start_time, r.end_time, r.total_amount, r.purpose,
                       f.name as facility_name, u.full_name as user_name,
                       COALESCE(ul.duration_minutes, 0) as usage_duration_minutes,
                       admin.full_name as verified_by_name
                FROM usage_logs ul
                JOIN reservations r ON ul.reservation_id = r.id
                JOIN facilities f ON ul.facility_id = f.id 
                JOIN users u ON ul.user_id = u.id 
                LEFT JOIN users admin ON ul.verified_by = admin.id
                WHERE ul.facility_id = ? 
                  AND ul.status IN ('completed', 'verified')
                ORDER BY ul.completed_at DESC, ul.verified_at DESC
                LIMIT ?
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$facilityId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            // If table doesn't exist or columns are missing, return empty array
            return [];
        }
    }
    /**
     * Get all usage history
     */
    public function getAllUsageHistory($limit = 100) {
        try {
            $sql = "
                SELECT 
                    ul.id as usage_id,
                    ul.reservation_id,
                    ul.action,
                    ul.status,
                    ul.started_at,
                    ul.completed_at,
                    ul.duration_minutes,
                    ul.notes,
                    ul.created_at,
                    ul.updated_at,
                    r.total_amount,
                    r.purpose,
                    r.start_time,
                    r.end_time,
                    f.name as facility_name,
                    f.id as facility_id,
                    u.full_name as user_name,
                    u.id as user_id,
                    admin.full_name as verified_by_name
                FROM usage_logs ul
                JOIN reservations r ON ul.reservation_id = r.id
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN users admin ON ul.verified_by = admin.id
                WHERE ul.status IN ('completed', 'verified')
                ORDER BY ul.completed_at DESC, ul.verified_at DESC
                LIMIT ?
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            // If table doesn't exist or columns are missing, return empty array
            return [];
        }
    }

    /**
     * Get user's usage history
     */
    public function getUserUsageHistory($userId, $limit = 100) {
        try {
            $sql = "
                SELECT 
                    ul.id as usage_id,
                    ul.reservation_id,
                    ul.action,
                    ul.status,
                    ul.started_at,
                    ul.completed_at,
                    ul.duration_minutes,
                    ul.notes,
                    ul.created_at,
                    ul.updated_at,
                    r.total_amount,
                    r.purpose,
                    f.name as facility_name,
                    f.id as facility_id,
                    c.name as category_name,
                    u.full_name as user_name,
                    u.id as user_id,
                    admin.full_name as verified_by_name
                FROM usage_logs ul
                JOIN reservations r ON ul.reservation_id = r.id
                JOIN facilities f ON r.facility_id = f.id 
                LEFT JOIN categories c ON f.category_id = c.id
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN users admin ON ul.verified_by = admin.id
                WHERE r.user_id = ? AND ul.status IN ('completed', 'verified')
                ORDER BY ul.completed_at DESC, ul.verified_at DESC
                LIMIT ?
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            // If table doesn't exist or columns are missing, return empty array
            return [];
        }
    }
    /**
     * Log usage actions
     */
    private function logUsageAction($reservationId, $action, $adminId = null, $notes = '') {
        try {
            // Check if usage_logs table exists, if not, skip logging
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'usage_logs'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO usage_logs (reservation_id, action, admin_id, notes) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$reservationId, $action, $adminId, $notes]);
            }
        } catch (Exception $e) {
            // Silently ignore logging errors to prevent usage operations from failing
        }
    }
    /**
     * Auto-complete usage for expired reservations
     */
    public function autoCompleteExpiredUsage() {
        // First, auto-complete reservations that have passed their end time
        $sql = "
            UPDATE reservations 
            SET usage_completed_at = NOW(), 
                status = 'completed',
                updated_at = NOW() 
            WHERE status = 'in_use' 
              AND usage_started_at IS NOT NULL 
              AND usage_completed_at IS NULL 
              AND end_time < NOW()
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $reservationsUpdated = $stmt->rowCount();
        
        // Then, auto-complete usage logs that have been active for too long (e.g., 24 hours)
        $sql = "
            UPDATE usage_logs 
            SET action = 'completed', 
                status = 'completed', 
                completed_at = NOW(), 
                duration_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW()),
                notes = CONCAT(IFNULL(notes, ''), ' | Auto-completed due to expiration'),
                updated_at = NOW()
            WHERE status = 'active' 
              AND action = 'started'
              AND started_at IS NOT NULL 
              AND completed_at IS NULL 
              AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $usageLogsUpdated = $stmt->rowCount();
        
        return $reservationsUpdated + $usageLogsUpdated;
    }
    
    /**
     * Auto-start usage for reservations that have reached their start time
     */
    public function autoStartUsage() {
        try {
            $this->pdo->beginTransaction();
            
            // Find confirmed reservations that have reached their start time but haven't started usage yet
            $sql = "
                SELECT r.*, f.name as facility_name, u.full_name as user_name 
                FROM reservations r 
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.status = 'confirmed' 
                  AND r.usage_started_at IS NULL 
                  AND r.start_time <= NOW() 
                  AND r.end_time > NOW()
                  AND NOT EXISTS (
                      SELECT 1 FROM usage_logs ul 
                      WHERE ul.reservation_id = r.id 
                      AND ul.status = 'active' AND ul.action = 'started'
                  )
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $readyReservations = $stmt->fetchAll();
            
            $autoStarted = 0;
            foreach ($readyReservations as $reservation) {
                // Update reservation to mark usage started
                $stmt = $this->pdo->prepare("
                    UPDATE reservations 
                    SET usage_started_at = NOW(), 
                        status = 'in_use',
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reservation['id']]);
                
                // Update usage log entry to mark as started
                $stmt = $this->pdo->prepare("
                    UPDATE usage_logs 
                    SET action = 'started', 
                        status = 'active', 
                        started_at = NOW(),
                        notes = CONCAT(IFNULL(notes, ''), ' | Auto-started when reservation time arrived'),
                        updated_at = NOW()
                    WHERE reservation_id = ? AND status = 'ready' AND action = 'confirmed'
                ");
                $stmt->execute([$reservation['id']]);
                
                $autoStarted++;
            }
            
            $this->pdo->commit();
            return $autoStarted;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error in autoStartUsage: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get countdown information for reservations
     */
    public function getCountdownInfo() {
        try {
            $sql = "
                SELECT 
                    r.id as reservation_id,
                    r.start_time,
                    r.end_time,
                    r.status,
                    r.usage_started_at,
                    f.name as facility_name,
                    u.full_name as user_name,
                    CASE 
                        WHEN r.status = 'confirmed' AND r.usage_started_at IS NULL THEN 'ready'
                        WHEN r.status = 'in_use' THEN 'active'
                        ELSE 'other'
                    END as countdown_type,
                    TIMESTAMPDIFF(SECOND, NOW(), r.start_time) as seconds_until_start,
                    TIMESTAMPDIFF(SECOND, NOW(), r.end_time) as seconds_until_end
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.status IN ('confirmed', 'in_use')
                  AND r.start_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND r.end_time <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
                ORDER BY r.start_time ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error in getCountdownInfo: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create usage log entries for confirmed reservations that don't have them
     */
    public function createUsageLogsForConfirmedReservations() {
        try {
            // Find confirmed reservations that don't have usage log entries
            $sql = "
                SELECT r.*, f.name as facility_name, u.full_name as user_name
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN users u ON r.user_id = u.id
                WHERE r.status = 'confirmed' 
                AND r.payment_status = 'paid'
                AND NOT EXISTS (
                    SELECT 1 FROM usage_logs ul 
                    WHERE ul.reservation_id = r.id 
                    AND ul.action = 'confirmed'
                )
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $reservations = $stmt->fetchAll();
            
            $created = 0;
            foreach ($reservations as $reservation) {
                // Create usage log entry for confirmed reservation
                $stmt = $this->pdo->prepare("
                    INSERT INTO usage_logs (reservation_id, facility_id, user_id, action, status, notes, created_at) 
                    VALUES (?, ?, ?, 'confirmed', 'ready', 'Reservation confirmed and ready for usage', NOW())
                ");
                $stmt->execute([
                    $reservation['id'], 
                    $reservation['facility_id'], 
                    $reservation['user_id']
                ]);
                $created++;
            }
            
            return $created;
        } catch (Exception $e) {
            error_log("Error creating usage logs for confirmed reservations: " . $e->getMessage());
            return 0;
        }
    }
}
?>
