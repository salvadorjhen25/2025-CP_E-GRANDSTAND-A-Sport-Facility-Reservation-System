<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdmin();
$pdo = getDBConnection();
// Set JSON header
header('Content-Type: application/json');
try {
    $email = $_GET['email'] ?? '';
    if (empty($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Email parameter is required'
        ]);
        exit;
    }
    // Get user information
    $user_stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ?");
    $user_stmt->execute([$email]);
    $user = $user_stmt->fetch();
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
        exit;
    }
    // Get user's cancelled reservations
    $cancellations_stmt = $pdo->prepare("
        SELECT r.*, f.name as facility_name, f.hourly_rate
        FROM reservations r 
        JOIN facilities f ON r.facility_id = f.id 
        WHERE r.user_id = ? AND r.status = 'cancelled'
        ORDER BY r.updated_at DESC
    ");
    $cancellations_stmt->execute([$user['id']]);
    $cancellations = $cancellations_stmt->fetchAll();
    // Return success response
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email']
        ],
        'cancellations' => $cancellations
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
