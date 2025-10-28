<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();

try {
    $facility_id = $_GET['facility_id'] ?? null;
    $date = $_GET['date'] ?? null;
    
    if (!$facility_id || !$date) {
        throw new Exception('Facility ID and date are required');
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    
    // Get existing reservations for the facility on the specified date
    $stmt = $pdo->prepare("
        SELECT start_time, end_time, status
        FROM reservations 
        WHERE facility_id = ? 
        AND DATE(start_time) = ? 
        AND status IN ('confirmed', 'pending')
        ORDER BY start_time ASC
    ");
    $stmt->execute([$facility_id, $date]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate available time slots
    $available_slots = generateAvailableTimeSlots($reservations);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'date' => $date,
        'facility_id' => $facility_id,
        'reservations' => $reservations,
        'available_slots' => $available_slots,
        'total_slots' => count($available_slots)
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function generateAvailableTimeSlots($reservations) {
    $slots = [];
    $start_hour = 8; // 8 AM
    $end_hour = 21.5; // 9:30 PM (21.5 = 9:30 PM)
    
    // Convert reservations to time ranges
    $booked_ranges = [];
    foreach ($reservations as $reservation) {
        $start = strtotime($reservation['start_time']);
        $end = strtotime($reservation['end_time']);
        
        $booked_ranges[] = [
            'start' => $start,
            'end' => $end,
            'start_hour' => date('H', $start) + (date('i', $start) / 60),
            'end_hour' => date('H', $end) + (date('i', $end) / 60)
        ];
    }
    
    // Generate hourly slots from 8 AM to 9:30 PM
    for ($hour = $start_hour; $hour < $end_hour; $hour += 0.5) {
        $slot_start = $hour;
        $slot_end = $hour + 1;
        
        // Check if this slot conflicts with any existing reservation
        $is_available = true;
        foreach ($booked_ranges as $range) {
            if (($slot_start < $range['end_hour'] && $slot_end > $range['start_hour'])) {
                $is_available = false;
                break;
            }
        }
        
        if ($is_available) {
            $slots[] = [
                'start_time' => formatTime($slot_start),
                'end_time' => formatTime($slot_end),
                'start_hour' => $slot_start,
                'end_hour' => $slot_end,
                'display' => formatTime($slot_start) . ' - ' . formatTime($slot_end)
            ];
        }
    }
    
    return $slots;
}

function formatTime($hour) {
    $hours = floor($hour);
    $minutes = ($hour - $hours) * 60;
    
    $period = $hours >= 12 ? 'PM' : 'AM';
    $display_hour = $hours > 12 ? $hours - 12 : ($hours == 0 ? 12 : $hours);
    $display_minutes = $minutes > 0 ? sprintf('%02d', $minutes) : '00';
    
    return $display_hour . ':' . $display_minutes . ' ' . $period;
}
?>
