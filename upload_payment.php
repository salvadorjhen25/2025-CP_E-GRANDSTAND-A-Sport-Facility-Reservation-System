<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';

// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    
    if ($bookingType === 'daily') {
        $days = $duration->days + ($duration->h > 0 ? 1 : 0);
        return $days . ' day' . ($days > 1 ? 's' : '');
    } else {
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
}

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();
$paymentManager = new PaymentManager();

$reservation_id = $_GET['reservation_id'] ?? null;

if (!$reservation_id) {
    header('Location: my_reservations.php');
    exit();
}

// Get reservation details
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, f.daily_rate
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$reservation_id, $_SESSION['user_id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
    header('Location: my_reservations.php');
    exit();
}

// Handle payment slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_slip'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = 'Invalid file type. Please upload JPG, PNG, or PDF files only.';
        } elseif ($file['size'] > $max_size) {
            $errors[] = 'File size too large. Maximum size is 5MB.';
        } else {
            $upload_dir = 'uploads/payment_slips/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'payment_' . $reservation_id . '_' . time() . '.' . $file_extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                if ($paymentManager->uploadPaymentSlip($reservation_id, $filepath)) {
                    $success_message = 'Payment slip uploaded successfully! An administrator will verify your payment.';
                } else {
                    $errors[] = 'Failed to upload payment slip. Please try again.';
                }
            } else {
                $errors[] = 'Failed to upload file. Please try again.';
            }
        }
    } else {
        $errors[] = 'Please select a payment slip file.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Payment - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <script src="assets/js/modal-system.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1E40AF'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center">
                        <i class="fas fa-building text-primary text-2xl mr-3"></i>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?></h1>
                    </a>
                </div>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center space-x-4">
                    <span class="text-gray-700">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="my_reservations.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-gray-900 focus:outline-none focus:text-gray-900">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-4">
                <div class="space-y-2">
                    <div class="text-gray-700 py-2">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <a href="my_reservations.php" class="block bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="block bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 mt-2">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-2xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Upload Payment Slip</h1>
            <p class="text-gray-600">Upload your payment slip for reservation verification</p>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Reservation Details -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Reservation Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500">Facility</p>
                    <p class="font-medium"><?php echo htmlspecialchars($reservation['facility_name']); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Date Range</p>
                    <p class="font-medium">
                        <?php 
                        $startDate = date('M j, Y', strtotime($reservation['start_time']));
                        $endDate = date('M j, Y', strtotime($reservation['end_time']));
                        if ($startDate === $endDate) {
                            echo $startDate;
                        } else {
                            echo $startDate . ' - ' . $endDate;
                        }
                        ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Time Range</p>
                    <p class="font-medium">
                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Booking Type</p>
                    <p class="font-medium text-blue-600">
                        <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> Booking
                        (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                    </p>
                    <?php if ($startDate !== $endDate): ?>
                    <p class="text-xs text-purple-600 font-medium mt-1">
                        <i class="fas fa-calendar-week mr-1"></i>
                        Multi-day booking (<?php echo date_diff(date_create($reservation['start_time']), date_create($reservation['end_time']))->days + 1; ?> days)
                    </p>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total Amount</p>
                    <p class="font-medium text-lg text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Payment Due</p>
                    <p class="font-medium">
                        <?php echo date('M j, g:i A', strtotime($reservation['payment_due_at'])); ?>
                        <?php if (strtotime($reservation['payment_due_at']) < time()): ?>
                            <span class="text-red-600 text-sm">(Expired)</span>
                        <?php else: ?>
                            <span class="text-yellow-600 text-sm">
                                (<?php 
                                $timeLeft = strtotime($reservation['payment_due_at']) - time();
                                $hoursLeft = floor($timeLeft / 3600);
                                $minutesLeft = floor(($timeLeft % 3600) / 60);
                                echo "{$hoursLeft}h {$minutesLeft}m left";
                                ?>)
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Payment Upload Form -->
        <?php if ($reservation['payment_status'] === 'pending' && strtotime($reservation['payment_due_at']) > time()): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-semibold mb-4">Upload Payment Slip</h2>
                
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <div>
                        <label for="payment_slip" class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Slip File
                        </label>
                        <input type="file" id="payment_slip" name="payment_slip" accept="image/*,.pdf" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <p class="text-sm text-gray-500 mt-1">Accepted formats: JPG, PNG, PDF (Max 5MB)</p>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h3 class="font-medium text-blue-800 mb-2">Payment Instructions:</h3>
                        <ul class="text-sm text-blue-700 space-y-1">
                            <li>• Make the physical payment at the facility office</li>
                            <li>• Take a photo or scan of your payment receipt</li>
                            <li>• Upload the image or PDF file above</li>
                            <li>• An administrator will verify your payment</li>
                            <li>• Your reservation will be confirmed once verified</li>
                        </ul>
                    </div>
                    
                    <div class="flex space-x-4">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition duration-200">
                            <i class="fas fa-upload mr-2"></i>Upload Payment Slip
                        </button>
                        <a href="my_reservations.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg transition duration-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reservations
                        </a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
                <i class="fas fa-exclamation-triangle text-yellow-600 text-4xl mb-4"></i>
                <h3 class="text-lg font-semibold text-yellow-800 mb-2">Payment Upload Not Available</h3>
                <p class="text-yellow-700 mb-4">
                    <?php if ($reservation['payment_status'] !== 'pending'): ?>
                        This reservation's payment has already been processed.
                    <?php else: ?>
                        The payment deadline has expired for this reservation.
                    <?php endif; ?>
                </p>
                <a href="my_reservations.php" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded-lg transition duration-200">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Reservations
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');

            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });

                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                        mobileMenu.classList.add('hidden');
                    }
                });
            }
        });
    </script>
</body>
</html>
