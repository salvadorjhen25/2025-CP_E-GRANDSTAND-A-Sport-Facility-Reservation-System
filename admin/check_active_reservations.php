<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdmin();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
if (!isset($_POST['facility_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Facility ID is required']);
    exit;
}
$facility_id = intval($_POST['facility_id']);
try {
    $pdo = getDBConnection();
    // Check if facility has any active reservations (not completed or cancelled)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ? AND status NOT IN ('completed', 'cancelled')");
    $stmt->execute([$facility_id]);
    $active_reservation_count = $stmt->fetch()['count'];
    echo json_encode([
        'hasActiveReservations' => $active_reservation_count > 0,
        'activeReservationCount' => $active_reservation_count
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}
?>
