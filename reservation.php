<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
require_once 'classes/EmailMailer.php';

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();
$paymentManager = new PaymentManager();

// Get facility details an id
$facility_id = $_GET['facility_id'] ?? null;
if (!$facility_id) {
    header('Location: index.php');
    exit();
}

$stmt = $pdo->prepare("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE f.id = ? AND f.is_active = 1
");
$stmt->execute([$facility_id]);
$facility = $stmt->fetch();

if (!$facility) {
    header('Location: index.php');
    exit();
}

// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $booking_type = $_POST['booking_type'] ?? 'hourly';
    $purpose = $_POST['purpose'] ?? '';
    $attendees = $_POST['attendees'] ?? 1;
    
    // Validate inputs
    $errors = [];
    
    if (empty($start_time) || empty($end_time)) {
        $errors[] = 'Please select start and end times';
    }
    
    if (strtotime($start_time) >= strtotime($end_time)) {
        $errors[] = 'End time must be after start time';
    }
    
    if (strtotime($start_time) < time()) {
        $errors[] = 'Cannot book in the past';
    }
    
    if (empty($purpose)) {
        $errors[] = 'Please provide a purpose for the reservation';
    }
    
    if ($attendees > $facility['capacity']) {
        $errors[] = 'Number of attendees cannot exceed facility capacity';
    }
    
    // Check for conflicts
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM reservations 
            WHERE facility_id = ? 
            AND status IN ('pending', 'confirmed')
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $stmt->execute([$facility_id, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
        $conflict = $stmt->fetch();
        
        if ($conflict['count'] > 0) {
            $errors[] = 'This time slot is already booked';
        }
    }
    
    // Create reservation if no errors
    if (empty($errors)) {
        $hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
        
        // Calculate total amount based on booking type
        if ($booking_type === 'daily') {
            $total_amount = $facility['daily_rate'];
        } else {
            $total_amount = $hours * $facility['hourly_rate'];
        }
        
        try {
            $reservationId = $paymentManager->createReservation(
                $_SESSION['user_id'], 
                $facility_id, 
                $start_time, 
                $end_time, 
                $purpose, 
                $attendees, 
                $total_amount,
                $booking_type,
                $hours
            );
            
            // Send email notifications
            $mailer = new EmailMailer();
            
            // Get user details
            $user_stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->execute([$_SESSION['user_id']]);
            $user = $user_stmt->fetch();
            
            // Prepare reservation data for email
            $reservation_data = [
                'id' => $reservationId,
                'facility_name' => $facility['name'],
                'start_time' => $start_time,
                'end_time' => $end_time,
                'total_amount' => $total_amount,
                'payment_due_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'user_name' => $user['full_name'],
                'user_email' => $user['email']
            ];
            
            // Send confirmation email to user
            $mailer->sendReservationConfirmation(
                $user['email'],
                $user['full_name'],
                $reservation_data
            );
            
            // Send notification email to admin
            $mailer->sendAdminNotification($reservation_data);
            
            $success_message = 'Reservation submitted successfully! You have 24 hours to make the physical payment. Please upload your payment slip once payment is made.';
            $reservation_id = $reservationId;
        } catch (Exception $e) {
            $errors[] = 'Failed to create reservation. Please try again.';
        }
    }
}

// Handle payment slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_payment') {
    $reservation_id = $_POST['reservation_id'] ?? null;
    
    if ($reservation_id && isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] === UPLOAD_ERR_OK) {
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

// Get existing reservations for this facility (for calendar display)
$stmt = $pdo->prepare("
    SELECT start_time, end_time, status 
    FROM reservations 
    WHERE facility_id = ? 
    AND status IN ('pending', 'confirmed')
    AND start_time >= CURDATE()
    ORDER BY start_time
");
$stmt->execute([$facility_id]);
$existing_reservations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($facility['name']); ?> - <?php echo SITE_NAME; ?></title>
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
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                        'shake': 'shake 0.5s ease-in-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        shake: {
                            '0%, 100%': { transform: 'translateX(0)' },
                            '25%': { transform: 'translateX(-5px)' },
                            '75%': { transform: 'translateX(5px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .form-input:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .error-shake {
            animation: shake 0.5s ease-in-out;
        }
        .success-pulse {
            animation: pulse 2s infinite;
        }
        .time-slot {
            transition: all 0.2s ease;
        }
        .time-slot:hover {
            transform: scale(1.05);
        }
        .time-slot.selected {
            background-color: #3B82F6;
            color: white;
        }
        .time-slot.disabled {
            background-color: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        @media (max-width: 768px) {
            .reservation-form {
                padding: 1rem;
            }
            .facility-info {
                text-align: center;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Processing your reservation...</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg sticky top-0 z-40">
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
                    <a href="index.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="my_reservations.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-gray-900 focus:outline-none focus:text-gray-900 p-2 rounded-lg hover:bg-gray-100 transition duration-200" aria-label="Toggle mobile menu">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-4 border-t border-gray-200">
                <div class="space-y-2 pt-4">
                    <div class="text-gray-700 py-2 px-4 bg-gray-50 rounded-lg">
                        <i class="fas fa-user mr-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </div>
                    <a href="index.php" class="block bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="my_reservations.php" class="block bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105 mt-2">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="block bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105 mt-2">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Breadcrumb -->
        <nav class="flex mb-8" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-3">
                <li class="inline-flex items-center">
                    <a href="index.php" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-primary">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" class="text-sm font-medium text-gray-700 hover:text-primary">
                            <?php echo htmlspecialchars($facility['name']); ?>
                        </a>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <span class="text-sm font-medium text-gray-500">Book Now</span>
                    </div>
                </li>
            </ol>
        </nav>

        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <script>
                // Show success modal when page loads
                document.addEventListener('DOMContentLoaded', function() {
                    showSuccessModal('<?php echo addslashes($success_message); ?>');
                });
            </script>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Facility Information -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6 facility-info animate-slide-up">
                    <div class="text-center mb-6">
                        <div class="h-32 bg-gradient-to-br from-blue-400 to-purple-500 rounded-lg flex items-center justify-center mb-4">
                            <i class="fas fa-building text-white text-4xl"></i>
                        </div>
                        <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($facility['name']); ?></h1>
                        <span class="bg-primary text-white px-3 py-1 rounded-full text-lg font-semibold mt-2 inline-block">
                            ₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr
                        </span>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-users text-primary mr-3 w-5"></i>
                            <span>Capacity: <?php echo $facility['capacity']; ?> people</span>
                        </div>
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-tag text-primary mr-3 w-5"></i>
                            <span>Category: <?php echo htmlspecialchars($facility['category_name']); ?></span>
                        </div>
                        <div class="flex items-start text-gray-600">
                            <i class="fas fa-info-circle text-primary mr-3 w-5 mt-1"></i>
                            <p class="text-sm"><?php echo htmlspecialchars($facility['description']); ?></p>
                        </div>
                    </div>

                    <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                        <h3 class="font-semibold text-blue-800 mb-2 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i>Booking Information
                        </h3>
                        <ul class="text-sm text-blue-700 space-y-1">
                            <li>• Minimum booking: 1 hour</li>
                            <li>• Maximum booking: 8 hours</li>
                            <li>• Payment required within 24 hours</li>
                            <li>• Cancellation allowed up to 2 hours before</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Reservation Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md p-6 reservation-form animate-slide-up" style="animation-delay: 0.1s;">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-calendar-plus text-primary mr-3"></i>Book Your Reservation
                    </h2>

                    <form method="POST" action="reservation.php?facility_id=<?php echo $facility['id']; ?>" id="reservationForm" class="space-y-6">
                        <input type="hidden" name="action" value="create_reservation">
                        
                        <!-- Booking Type Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock mr-2"></i>Booking Type
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                                                 <label class="relative cursor-pointer">
                                     <input type="radio" name="booking_type" value="hourly" checked 
                                            class="sr-only booking-type-radio">
                                     <div class="border-2 border-gray-300 rounded-lg p-4 text-center hover:border-primary transition duration-200 booking-type-option" data-type="hourly">
                                         <i class="fas fa-clock text-2xl text-gray-400 mb-2"></i>
                                         <div class="font-semibold text-gray-800">Hourly Booking</div>
                                         <div class="text-sm text-gray-600">₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hour</div>
                                     </div>
                                 </label>
                                 <label class="relative cursor-pointer">
                                     <input type="radio" name="booking_type" value="daily" 
                                            class="sr-only booking-type-radio" <?php echo ($facility['daily_rate'] ?? 0) <= 0 ? 'disabled' : ''; ?>>
                                     <div class="border-2 border-gray-300 rounded-lg p-4 text-center hover:border-primary transition duration-200 booking-type-option <?php echo ($facility['daily_rate'] ?? 0) <= 0 ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'; ?>" data-type="daily">
                                         <i class="fas fa-calendar-day text-2xl <?php echo ($facility['daily_rate'] ?? 0) <= 0 ? 'text-gray-300' : 'text-gray-400'; ?> mb-2"></i>
                                         <div class="font-semibold text-gray-800">Daily Booking</div>
                                         <div class="text-sm text-gray-600">
                                             <?php if (($facility['daily_rate'] ?? 0) > 0): ?>
                                                 ₱<?php echo number_format($facility['daily_rate'], 2); ?>/day
                                                 <div class="text-xs text-green-600 mt-1">✓ Available</div>
                                             <?php else: ?>
                                                 <span class="text-red-500">Not Available</span>
                                             <?php endif; ?>
                                         </div>
                                     </div>
                                 </label>
                            </div>
                        </div>

                        <!-- Date and Time Selection -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Start Date -->
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar mr-2"></i>Start Date
                                </label>
                                <input type="date" id="start_date" name="start_date" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                            </div>

                            <!-- End Date -->
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar mr-2"></i>End Date
                                </label>
                                <input type="date" id="end_date" name="end_date" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                            </div>
                        </div>

                        <!-- Time Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock mr-2"></i>Select Time
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Start Time -->
                                <div>
                                    <label for="start_time_input" class="block text-sm font-medium text-gray-600 mb-1">Start Time</label>
                                    <input type="time" id="start_time_input" name="start_time_input" 
                                           min="08:00" max="20:00" value="08:00"
                                           class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                                </div>
                                <!-- End Time -->
                                <div>
                                    <label for="end_time_input" class="block text-sm font-medium text-gray-600 mb-1">End Time</label>
                                    <input type="time" id="end_time_input" name="end_time_input" 
                                           min="08:00" max="20:00" value="09:00"
                                           class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                                </div>
                            </div>
                        </div>

                        <!-- Quick Time Slots (for convenience) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-bolt mr-2"></i>Quick Time Slots
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3" id="timeSlots">
                                <!-- Time slots will be populated by JavaScript -->
                            </div>
                        </div>

                        <!-- Hidden time inputs for form submission -->
                        <input type="hidden" id="start_time" name="start_time" required>
                        <input type="hidden" id="end_time" name="end_time" required>

                        <!-- Purpose -->
                        <div>
                            <label for="purpose" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-bullseye mr-2"></i>Purpose of Reservation
                            </label>
                            <textarea id="purpose" name="purpose" rows="3" required
                                      placeholder="Please describe the purpose of your reservation..."
                                      class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"></textarea>
                        </div>

                        <!-- Number of Attendees -->
                        <div>
                            <label for="attendees" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-users mr-2"></i>Number of Attendees
                            </label>
                            <input type="number" id="attendees" name="attendees" min="1" max="<?php echo $facility['capacity']; ?>" value="1" required
                                   class="form-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                            <p class="text-sm text-gray-500 mt-1">Maximum capacity: <?php echo $facility['capacity']; ?> people</p>
                        </div>

                        <!-- Cost Preview -->
                        <div id="costPreview" class="hidden bg-gray-50 rounded-lg p-4">
                            <h3 class="font-semibold text-gray-800 mb-2">Cost Preview</h3>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Booking Type:</span>
                                <span id="bookingTypeDisplay" class="font-medium"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Duration:</span>
                                <span id="duration" class="font-medium"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Rate:</span>
                                <span id="rateDisplay" class="font-medium"></span>
                            </div>
                            <div class="border-t border-gray-300 mt-2 pt-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-lg font-semibold text-gray-800">Total:</span>
                                    <span id="totalCost" class="text-lg font-bold text-primary"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex flex-col sm:flex-row gap-4">
                            <button type="submit" id="submitBtn" 
                                    class="flex-1 bg-primary hover:bg-secondary text-white py-3 px-6 rounded-lg font-semibold transition duration-200 transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                                <i class="fas fa-calendar-check mr-2"></i>Book Reservation
                            </button>
                            <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" 
                               class="bg-gray-500 hover:bg-gray-600 text-white py-3 px-6 rounded-lg font-semibold transition duration-200 transform hover:scale-105 text-center">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Details
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Hide loading overlay when page is ready
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                }, 300);
            }
        });

                 // Global variables
         let startDateInput, endDateInput, startTimeInputField, endTimeInputField;
         let timeSlotsContainer, startTimeInput, endTimeInput, costPreview;
         let durationSpan, totalCostSpan, submitBtn, rateDisplay, bookingTypeDisplay;
         let facilityHourlyRate, facilityDailyRate, existingReservations;

         // Function to generate time slots
         function generateTimeSlots() {
             const selectedDate = startDateInput.value;
             if (!selectedDate) return;

             timeSlotsContainer.innerHTML = '';
             const slots = [];
             
             const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
             
             if (bookingType === 'daily') {
                 // Check if daily rate is available
                 if (facilityDailyRate > 0) {
                     // For daily bookings, show full day option
                     const slot = document.createElement('div');
                     slot.className = 'time-slot p-4 border rounded-lg text-center cursor-pointer transition-all duration-200 bg-white hover:bg-blue-50 border-gray-300';
                     slot.innerHTML = `
                         <div class="font-semibold text-gray-800">Full Day</div>
                         <div class="text-sm text-gray-600">8:00 AM - 8:00 PM</div>
                         <div class="text-xs text-primary mt-1">₱${facilityDailyRate.toFixed(2)}</div>
                     `;
                     slot.addEventListener('click', function() {
                         selectDailySlot();
                     });
                     slots.push(slot);
                 } else {
                     // Show message that daily booking is not available
                     const slot = document.createElement('div');
                     slot.className = 'time-slot p-4 border rounded-lg text-center bg-gray-100 border-gray-300 col-span-full';
                     slot.innerHTML = `
                         <div class="font-semibold text-gray-600">Daily Booking Not Available</div>
                         <div class="text-sm text-gray-500">Please contact administrator to set up daily rates</div>
                     `;
                     slots.push(slot);
                 }
             } else {
                 // Generate hourly time slots from 8 AM to 8 PM
                 for (let hour = 8; hour <= 20; hour++) {
                     const time = `${hour.toString().padStart(2, '0')}:00`;
                     const endTime = `${(hour + 1).toString().padStart(2, '0')}:00`;
                     
                     // Check if this slot conflicts with existing reservations
                     const isBooked = existingReservations.some(reservation => {
                         const reservationDate = new Date(reservation.start_time).toISOString().split('T')[0];
                         const reservationStart = new Date(reservation.start_time).toTimeString().slice(0, 5);
                         const reservationEnd = new Date(reservation.end_time).toTimeString().slice(0, 5);
                         
                         return reservationDate === selectedDate && 
                                reservation.status !== 'cancelled' &&
                                ((time >= reservationStart && time < reservationEnd) ||
                                 (endTime > reservationStart && endTime <= reservationEnd));
                     });

                     const slot = document.createElement('div');
                     slot.className = `time-slot p-3 border rounded-lg text-center cursor-pointer transition-all duration-200 ${
                         isBooked ? 'disabled bg-gray-100 text-gray-400' : 'bg-white hover:bg-blue-50 border-gray-300'
                     }`;
                     slot.textContent = time;
                     
                     if (!isBooked) {
                         slot.addEventListener('click', () => selectTimeSlot(time, endTime));
                     }
                     
                     slots.push(slot);
                 }
             }
             
             timeSlotsContainer.append(...slots);
         }

         // Function to select time slot
         function selectTimeSlot(startTime, endTime) {
             // Remove previous selection
             document.querySelectorAll('.time-slot').forEach(slot => {
                 slot.classList.remove('selected');
             });

             // Add selection to clicked slot
             const clickedSlot = event.target.closest('.time-slot');
             if (clickedSlot) {
                 clickedSlot.classList.add('selected');
             }
             
             // Update time input fields
             startTimeInputField.value = startTime;
             endTimeInputField.value = endTime;
             
             // Update hidden inputs
             const selectedDate = startDateInput.value;
             startTimeInput.value = `${selectedDate} ${startTime}:00`;
             endTimeInput.value = `${selectedDate} ${endTime}:00`;

             // Show cost preview for hourly booking
             const duration = 1; // 1 hour
             const totalCost = duration * facilityHourlyRate;
             
             bookingTypeDisplay.textContent = 'Hourly';
             durationSpan.textContent = `${duration} hour`;
             rateDisplay.textContent = `₱${facilityHourlyRate.toFixed(2)}/hour`;
             totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
             costPreview.classList.remove('hidden');
         }
         
         // Function to select daily slot
         function selectDailySlot() {
             // Remove previous selection
             document.querySelectorAll('.time-slot').forEach(slot => {
                 slot.classList.remove('selected');
             });

             // Add selection to clicked slot
             const clickedSlot = event.target.closest('.time-slot');
             if (clickedSlot) {
                 clickedSlot.classList.add('selected');
             }
             
             // Update time input fields for full day (8 AM to 8 PM)
             startTimeInputField.value = '08:00';
             endTimeInputField.value = '20:00';
             
             // Update hidden inputs for full day (8 AM to 8 PM)
             const selectedDate = startDateInput.value;
             startTimeInput.value = `${selectedDate} 08:00:00`;
             endTimeInput.value = `${selectedDate} 20:00:00`;

             // Show cost preview for daily booking
             const duration = 12; // 12 hours (8 AM to 8 PM)
             const totalCost = facilityDailyRate;
             
             bookingTypeDisplay.textContent = 'Daily';
             durationSpan.textContent = `${duration} hours (Full Day)`;
             rateDisplay.textContent = `₱${facilityDailyRate.toFixed(2)}/day`;
             totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
             costPreview.classList.remove('hidden');
         }

         // Function to update cost preview based on current inputs
         function updateCostPreview() {
             const startDate = startDateInput.value;
             const endDate = endDateInput.value;
             const startTime = startTimeInputField.value;
             const endTime = endTimeInputField.value;
             
             if (!startDate || !endDate || !startTime || !endTime) {
                 costPreview.classList.add('hidden');
                 return;
             }
             
             // Update hidden inputs
             startTimeInput.value = `${startDate} ${startTime}:00`;
             endTimeInput.value = `${endDate} ${endTime}:00`;
             
             const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
             
             if (bookingType === 'daily') {
                 // For daily booking, use daily rate
                 const totalCost = facilityDailyRate;
                 bookingTypeDisplay.textContent = 'Daily';
                 durationSpan.textContent = '12 hours (Full Day)';
                 rateDisplay.textContent = `₱${facilityDailyRate.toFixed(2)}/day`;
                 totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
             } else {
                 // For hourly booking, calculate based on actual duration
                 const startDateTime = new Date(`${startDate} ${startTime}`);
                 const endDateTime = new Date(`${endDate} ${endTime}`);
                 const durationHours = (endDateTime - startDateTime) / (1000 * 60 * 60);
                 const totalCost = durationHours * facilityHourlyRate;
                 
                 bookingTypeDisplay.textContent = 'Hourly';
                 durationSpan.textContent = `${durationHours.toFixed(1)} hours`;
                 rateDisplay.textContent = `₱${facilityHourlyRate.toFixed(2)}/hour`;
                 totalCostSpan.textContent = `₱${totalCost.toFixed(2)}`;
             }
             
             costPreview.classList.remove('hidden');
         }

         // Booking type management function
         function updateBookingType() {
             const hourlyOption = document.querySelector('input[value="hourly"]');
             const dailyOption = document.querySelector('input[value="daily"]');
             const hourlyDiv = document.querySelector('.booking-type-option[data-type="hourly"]');
             const dailyDiv = document.querySelector('.booking-type-option[data-type="daily"]');
             
             // Update visual selection
             if (hourlyOption.checked) {
                 hourlyDiv.classList.add('border-primary', 'bg-blue-50');
                 dailyDiv.classList.remove('border-primary', 'bg-blue-50');
                 hourlyDiv.classList.add('border-gray-300');
                 dailyDiv.classList.add('border-gray-300');
             } else {
                 dailyDiv.classList.add('border-primary', 'bg-blue-50');
                 hourlyDiv.classList.remove('border-primary', 'bg-blue-50');
                 dailyDiv.classList.add('border-gray-300');
                 hourlyDiv.classList.add('border-gray-300');
             }
             
             // Update time slot generation and cost preview
             generateTimeSlots();
             updateCostPreview();
         }

         // Mobile menu functionality
         document.addEventListener('DOMContentLoaded', function() {
             const mobileMenuButton = document.getElementById('mobile-menu-button');
             const mobileMenu = document.getElementById('mobile-menu');

             if (mobileMenuButton && mobileMenu) {
                 mobileMenuButton.addEventListener('click', function() {
                     const isHidden = mobileMenu.classList.contains('hidden');
                     
                     if (isHidden) {
                         mobileMenu.classList.remove('hidden');
                         mobileMenuButton.innerHTML = '<i class="fas fa-times text-xl"></i>';
                     } else {
                         mobileMenu.classList.add('hidden');
                         mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                     }
                 });
             }

             // Initialize form elements
             startDateInput = document.getElementById('start_date');
             endDateInput = document.getElementById('end_date');
             startTimeInputField = document.getElementById('start_time_input');
             endTimeInputField = document.getElementById('end_time_input');
             timeSlotsContainer = document.getElementById('timeSlots');
             startTimeInput = document.getElementById('start_time');
             endTimeInput = document.getElementById('end_time');
             costPreview = document.getElementById('costPreview');
             durationSpan = document.getElementById('duration');
             totalCostSpan = document.getElementById('totalCost');
             submitBtn = document.getElementById('submitBtn');
             facilityHourlyRate = <?php echo $facility['hourly_rate']; ?>;
             facilityDailyRate = <?php echo $facility['daily_rate'] ?? 0; ?>;
             rateDisplay = document.getElementById('rateDisplay');
             bookingTypeDisplay = document.getElementById('bookingTypeDisplay');

             // Existing reservations data
             existingReservations = <?php echo json_encode($existing_reservations); ?>;
             
             // Add event listeners for booking type radio buttons
             document.querySelectorAll('.booking-type-radio').forEach(radio => {
                 radio.addEventListener('change', updateBookingType);
             });
             
             // Initialize booking type selection after elements are ready
             setTimeout(() => {
                 updateBookingType();
             }, 100);

             // Event listeners
             startDateInput.addEventListener('change', function() {
                 // Set end date to same as start date if not set
                 if (!endDateInput.value) {
                     endDateInput.value = startDateInput.value;
                 }
                 generateTimeSlots();
                 updateCostPreview();
             });
             
             endDateInput.addEventListener('change', updateCostPreview);
             startTimeInputField.addEventListener('change', updateCostPreview);
             endTimeInputField.addEventListener('change', updateCostPreview);

            // Form submission with loading state
            document.getElementById('reservationForm').addEventListener('submit', function(e) {
                const startDate = startDateInput.value;
                const endDate = endDateInput.value;
                const startTime = startTimeInputField.value;
                const endTime = endTimeInputField.value;
                
                if (!startDate || !endDate || !startTime || !endTime) {
                    e.preventDefault();
                    ModalSystem.alert('Please fill in all date and time fields.', 'Date/Time Required', 'warning');
                    return;
                }
                
                // Validate that end time is after start time
                const startDateTime = new Date(`${startDate} ${startTime}`);
                const endDateTime = new Date(`${endDate} ${endTime}`);
                
                if (endDateTime <= startDateTime) {
                    e.preventDefault();
                    ModalSystem.alert('End date/time must be after start date/time.', 'Invalid Time Range', 'warning');
                    return;
                }

                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                
                // Show loading overlay
                const loadingOverlay = document.getElementById('loading-overlay');
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.opacity = '1';
            });

            // Real-time validation
            document.getElementById('purpose').addEventListener('input', function() {
                if (this.value.length > 0) {
                    this.classList.remove('border-red-300');
                    this.classList.add('border-green-300');
                } else {
                    this.classList.remove('border-green-300');
                    this.classList.add('border-red-300');
                }
            });

            document.getElementById('attendees').addEventListener('input', function() {
                const value = parseInt(this.value);
                const max = parseInt(this.max);
                
                if (value > max) {
                    this.value = max;
                } else if (value < 1) {
                    this.value = 1;
                }
            });

            // Initialize time slots if date is pre-selected
            if (startDateInput.value) {
                generateTimeSlots();
                updateCostPreview();
            }
        });

        // Success Modal Function
        function showSuccessModal(message) {
            const modalHtml = `
                <div id="success-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0" id="success-modal-content">
                        <div class="p-6 text-center">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Success!</h3>
                            <p class="text-gray-600 mb-6">${message}</p>
                            <div class="flex justify-center space-x-3">
                                <button onclick="closeSuccessModal()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-check mr-2"></i>Got it!
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('success-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Animate modal in
            setTimeout(() => {
                const modalContent = document.getElementById('success-modal-content');
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            
            // Add event listeners
            const modal = document.getElementById('success-modal');
            
            // Close on backdrop click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeSuccessModal();
                }
            });
            
            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSuccessModal();
                }
            });
        }
        
        function closeSuccessModal() {
            const modal = document.getElementById('success-modal');
            if (modal) {
                const modalContent = document.getElementById('success-modal-content');
                modalContent.classList.add('scale-95', 'opacity-0');
                modalContent.classList.remove('scale-100', 'opacity-100');
                
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }
        }
    </script>
</body>
</html>
