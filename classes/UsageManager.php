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
            
            // Check if reservation exists and is confirmed
            $stmt = $this->pdo->prepare("
                SELECT r.*, f.name as facility_name, u.full_name as user_name 
                FROM reservations r 
                JOIN facilities f ON r.facility_id = f.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ? AND r.status = 'confirmed' AND r.payment_status = 'paid'
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            
            if (!$reservation) {
                throw new Exception('Reservation not found or not eligible for usage');
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
            
            // Log the usage start
            $this->logUsageAction($reservationId, 'started', $adminId, $notes);
            
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
                WHERE r.id = ? AND r.status = 'in_use'
            ");
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            
            if (!$reservation) {
                throw new Exception('Reservation not found or usage not started');
            }
            
            // Update reservation to mark usage completed
            $stmt = $this->pdo->prepare("
                UPDATE reservations 
                SET usage_completed_at = NOW(), 
                    status = 'completed',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reservationId]);
            
            // Log the usage completion
            $this->logUsageAction($reservationId, 'completed', $adminId, $notes);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Facility usage completed successfully',
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
            
            // Log the usage verification
            $this->logUsageAction($reservationId, 'verified', $adminId, $notes);
            
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
            SELECT r.*, f.name as facility_name, u.full_name as user_name,
                   TIMESTAMPDIFF(MINUTE, r.usage_started_at, NOW()) as usage_duration_minutes
            FROM reservations r 
            JOIN facilities f ON r.facility_id = f.id 
            JOIN users u ON r.user_id = u.id 
            WHERE r.status = 'in_use' AND r.usage_started_at IS NOT NULL
        ";
        
        $params = [];
        if ($facilityId) {
            $sql .= " AND r.facility_id = ?";
            $params[] = $facilityId;
        }
        
        $sql .= " ORDER BY r.usage_started_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get pending usage verifications
     */
    public function getPendingVerifications() {
        $sql = "
            SELECT r.*, f.name as facility_name, u.full_name as user_name,
                   TIMESTAMPDIFF(MINUTE, r.usage_started_at, r.usage_completed_at) as usage_duration_minutes
            FROM reservations r 
            JOIN facilities f ON r.facility_id = f.id 
            JOIN users u ON r.user_id = u.id 
            WHERE r.status = 'completed' 
              AND r.usage_completed_at IS NOT NULL 
              AND r.usage_verified_by IS NULL
            ORDER BY r.usage_completed_at DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get usage history for a facility
     */
    public function getUsageHistory($facilityId, $limit = 50) {
        $sql = "
            SELECT r.*, f.name as facility_name, u.full_name as user_name,
                   TIMESTAMPDIFF(MINUTE, r.usage_started_at, r.usage_completed_at) as usage_duration_minutes,
                   admin.full_name as verified_by_name
            FROM reservations r 
            JOIN facilities f ON r.facility_id = f.id 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN users admin ON r.usage_verified_by = admin.id
            WHERE r.facility_id = ? 
              AND r.status = 'completed' 
              AND r.usage_completed_at IS NOT NULL
            ORDER BY r.usage_completed_at DESC
            LIMIT ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$facilityId, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Log usage actions
     */
    private function logUsageAction($reservationId, $action, $adminId = null, $notes = '') {
        $stmt = $this->pdo->prepare("
            INSERT INTO usage_logs (reservation_id, action, admin_id, notes) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$reservationId, $action, $adminId, $notes]);
    }
    
    /**
     * Auto-complete usage for expired reservations
     */
    public function autoCompleteExpiredUsage() {
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
        
        return $stmt->rowCount();
    }
}
?>
