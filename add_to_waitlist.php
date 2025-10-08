<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
$auth = new Auth();
$auth->requireRegularUser();
$paymentManager = new PaymentManager();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facility_id = $_POST['facility_id'] ?? null;
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    if ($facility_id && $start_time && $end_time) {
        try {
            $waitlist_id = $paymentManager->addToWaitlist($_SESSION['user_id'], $facility_id, $start_time, $end_time);
            if ($waitlist_id) {
                header('Location: my_reservations.php?success=waitlist_added');
                exit();
            } else {
                header('Location: my_reservations.php?error=already_on_waitlist');
                exit();
            }
        } catch (Exception $e) {
            header('Location: my_reservations.php?error=waitlist_failed');
            exit();
        }
    } else {
        header('Location: my_reservations.php?error=invalid_data');
        exit();
    }
} else {
    header('Location: my_reservations.php');
    exit();
}
?>
