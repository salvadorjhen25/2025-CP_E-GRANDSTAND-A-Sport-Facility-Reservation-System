<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/UsageManager.php';
// This script should be run as a cron job every 5-10 minutes
// Example: */5 * * * * php /path/to/cron/auto_complete_usage.php
try {
    $usageManager = new UsageManager();
    // Auto-complete usage for expired reservations
    $completedCount = $usageManager->autoCompleteExpiredUsage();
    if ($completedCount > 0) {
        echo "Auto-completed usage for {$completedCount} expired reservations.\n";
        // Log the action
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO usage_logs (reservation_id, action, admin_id, notes) 
            VALUES (0, 'auto_completed', NULL, ?)
        ");
        $stmt->execute(["Auto-completed {$completedCount} expired reservations"]);
    } else {
        echo "No expired reservations to auto-complete.\n";
    }
    echo "Usage auto-completion completed successfully at " . date('Y-m-d H:i:s') . "\n";
} catch (Exception $e) {
    echo "Error in auto-completion script: " . $e->getMessage() . "\n";
    error_log("Usage auto-completion error: " . $e->getMessage());
}
?>
