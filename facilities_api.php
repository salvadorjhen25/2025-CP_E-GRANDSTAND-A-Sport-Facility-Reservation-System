<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();

try {
    // Get facilities with categories
    $stmt = $pdo->prepare("
        SELECT f.*, c.name as category_name 
        FROM facilities f 
        LEFT JOIN categories c ON f.category_id = c.id 
        WHERE f.is_active = 1
        ORDER BY f.name ASC
    ");
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get categories for filter
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pricing options for each facility
    foreach ($facilities as &$facility) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM facility_pricing_options 
                WHERE facility_id = ? AND is_active = 1 
                ORDER BY sort_order ASC, name ASC
                LIMIT 3
            ");
            $stmt->execute([$facility['id']]);
            $facility['pricing_options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $facility['pricing_options'] = [];
        }
    }
    unset($facility);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'facilities' => $facilities,
        'categories' => $categories,
        'count' => count($facilities)
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load facilities: ' . $e->getMessage()
    ]);
}
?>