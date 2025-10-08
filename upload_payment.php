<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    // For hourly bookings, calculate based on actual time difference
    $hours = $duration->h + ($duration->days * 24);
    if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
    return $hours . ' hour' . ($hours > 1 ? 's' : '');
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
    SELECT r.*, f.name as facility_name, f.hourly_rate
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
    
    <!-- Google Fonts - Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <script src="assets/js/modal-system.js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'poppins': ['Poppins', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a'
                        },
                        secondary: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155'
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        /* Global Poppins Font */
        * {
            font-family: 'Poppins', sans-serif !important;
        }
        
        /* Enhanced UI Components */
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .upload-area {
            border: 2px dashed #d1d5db;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #3b82f6;
            background-color: #f8fafc;
        }
        
        .upload-area.dragover {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        
        .file-preview {
            transition: all 0.3s ease;
        }
        
        .file-preview:hover {
            transform: scale(1.02);
        }
        
        /* Enhanced animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.8;
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out;
        }
        
        .animate-pulse {
            animation: pulse 2s infinite;
        }
        
        /* Enhanced form styling */
        .form-input-enhanced {
            color: #000000 !important;
            font-weight: 500 !important;
            transition: all 0.3s ease;
        }
        
        .form-input-enhanced:focus {
            color: #000000 !important;
            font-weight: 500 !important;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            border-color: #3b82f6;
        }
        
        /* Status indicators */
        .status-pending {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            border: 1px solid #f59e0b;
        }
        
        .status-expired {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        
        .status-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #10b981;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Enhanced Navigation -->
    <nav class="glass-effect shadow-xl border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-20">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center group">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mr-4 group-hover:scale-110 transition-transform duration-300">
                            <i class="fas fa-building text-white text-xl"></i>
                        </div>
                        <h1 class="text-2xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent"><?php echo SITE_NAME; ?></h1>
                    </a>
                </div>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center space-x-6">
                    <div class="flex items-center bg-white/50 rounded-full px-4 py-2">
                        <i class="fas fa-user-circle text-blue-500 text-xl mr-2"></i>
                        <span class="text-gray-700 font-medium">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                    <a href="my_reservations.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-6 py-3 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden flex items-center">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-blue-600 focus:outline-none focus:text-blue-600 transition-colors duration-200">
                        <i class="fas fa-bars text-2xl"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-6 animate-fadeInUp">
                <div class="space-y-3">
                    <div class="bg-white/50 rounded-xl px-4 py-3 flex items-center">
                        <i class="fas fa-user-circle text-blue-500 text-xl mr-3"></i>
                        <span class="text-gray-700 font-medium">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                    <a href="my_reservations.php" class="block bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl transition-all duration-300 shadow-lg">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="auth/logout.php" class="block bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-6 py-3 rounded-xl transition-all duration-300 shadow-lg">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <div class="max-w-4xl mx-auto px-4 py-12">
        <!-- Enhanced Page Header -->
        <div class="text-center mb-12 animate-fadeInUp">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full mb-6 shadow-2xl">
                <i class="fas fa-upload text-white text-3xl"></i>
            </div>
            <h1 class="text-4xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent mb-4">Upload Payment Slip</h1>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">Complete your reservation by uploading your payment receipt for verification</p>
        </div>
        <!-- Enhanced Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="bg-gradient-to-r from-red-50 to-red-100 border-2 border-red-200 text-red-800 px-6 py-4 rounded-2xl mb-8 shadow-lg animate-fadeInUp">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold mb-2">Please fix the following errors:</h3>
                        <ul class="space-y-1">
                            <?php foreach ($errors as $error): ?>
                                <li class="flex items-center">
                                    <i class="fas fa-dot-circle text-red-400 text-xs mr-2"></i>
                                    <?php echo htmlspecialchars($error); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Enhanced Reservation Details -->
        <div class="bg-white rounded-2xl shadow-2xl p-8 mb-8 card-hover animate-fadeInUp">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mr-4">
                    <i class="fas fa-calendar-check text-white text-xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Reservation Details</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Facility -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 border border-blue-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-building text-blue-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-blue-700 uppercase tracking-wide">Facility</p>
                    </div>
                    <p class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($reservation['facility_name']); ?></p>
                </div>
                
                <!-- Date Range -->
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6 border border-purple-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-calendar text-purple-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-purple-700 uppercase tracking-wide">Date</p>
                    </div>
                    <p class="text-lg font-bold text-gray-800">
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
                
                <!-- Time Range -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 border border-green-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-clock text-green-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-green-700 uppercase tracking-wide">Time</p>
                    </div>
                    <p class="text-lg font-bold text-gray-800">
                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                    </p>
                </div>
                
                <!-- Booking Type -->
                <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-6 border border-orange-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-tag text-orange-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-orange-700 uppercase tracking-wide">Booking Type</p>
                    </div>
                    <p class="text-lg font-bold text-gray-800">
                        <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> Booking
                    </p>
                    <p class="text-sm text-orange-600 font-medium mt-1">
                        (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                    </p>
                    <?php if ($startDate !== $endDate): ?>
                    <p class="text-xs text-orange-600 font-medium mt-2 flex items-center">
                        <i class="fas fa-calendar-week mr-1"></i>
                        Multi-day booking (<?php echo date_diff(date_create($reservation['start_time']), date_create($reservation['end_time']))->days + 1; ?> days)
                    </p>
                    <?php endif; ?>
                </div>
                
                <!-- Total Amount -->
                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-xl p-6 border border-emerald-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-dollar-sign text-emerald-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-emerald-700 uppercase tracking-wide">Total Amount</p>
                    </div>
                    <p class="text-2xl font-bold text-emerald-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></p>
                </div>
                
                <!-- Payment Due -->
                <div class="bg-gradient-to-br from-amber-50 to-amber-100 rounded-xl p-6 border border-amber-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-hourglass-half text-amber-500 text-xl mr-3"></i>
                        <p class="text-sm font-semibold text-amber-700 uppercase tracking-wide">Payment Due</p>
                    </div>
                    <p class="text-lg font-bold text-gray-800">
                        <?php echo date('M j, g:i A', strtotime($reservation['payment_due_at'])); ?>
                    </p>
                    <?php if (strtotime($reservation['payment_due_at']) < time()): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold status-expired mt-2">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Expired
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold status-pending mt-2">
                            <i class="fas fa-clock mr-1"></i>
                            <?php 
                            $timeLeft = strtotime($reservation['payment_due_at']) - time();
                            $hoursLeft = floor($timeLeft / 3600);
                            $minutesLeft = floor(($timeLeft % 3600) / 60);
                            echo "{$hoursLeft}h {$minutesLeft}m left";
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Enhanced Payment Upload Form -->
        <?php if ($reservation['payment_status'] === 'pending' && strtotime($reservation['payment_due_at']) > time()): ?>
            <div class="bg-white rounded-2xl shadow-2xl p-8 card-hover animate-fadeInUp">
                <div class="flex items-center mb-6">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center mr-4">
                        <i class="fas fa-upload text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800">Upload Payment Slip</h2>
                </div>
                
                <form method="POST" enctype="multipart/form-data" class="space-y-8" id="uploadForm">
                    <!-- Enhanced File Upload Area -->
                    <div class="upload-area rounded-2xl p-8 text-center" id="uploadArea">
                        <div class="mb-4">
                            <i class="fas fa-cloud-upload-alt text-6xl text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">Drop your payment slip here</h3>
                            <p class="text-gray-500 mb-4">or click to browse files</p>
                        </div>
                        <input type="file" id="payment_slip" name="payment_slip" accept="image/*,.pdf" required
                               class="hidden" onchange="handleFileSelect(this)">
                        <button type="button" onclick="document.getElementById('payment_slip').click()" 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-folder-open mr-2"></i>Choose File
                        </button>
                        <p class="text-sm text-gray-500 mt-4">Accepted formats: JPG, PNG, PDF (Max 5MB)</p>
                    </div>
                    
                    <!-- File Preview -->
                    <div id="filePreview" class="hidden">
                        <div class="bg-gradient-to-r from-green-50 to-green-100 border-2 border-green-200 rounded-xl p-6">
                            <div class="flex items-center">
                                <i class="fas fa-file-alt text-green-500 text-2xl mr-4"></i>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-green-800" id="fileName">File selected</h4>
                                    <p class="text-sm text-green-600" id="fileSize">Ready to upload</p>
                                </div>
                                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Enhanced Payment Instructions -->
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-2xl p-6">
                        <div class="flex items-center mb-4">
                            <i class="fas fa-info-circle text-blue-500 text-xl mr-3"></i>
                            <h3 class="text-lg font-semibold text-blue-800">Payment Instructions</h3>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <div class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">1</div>
                                    <p class="text-blue-700 font-medium">Make the physical payment at the facility office</p>
                                </div>
                                <div class="flex items-start">
                                    <div class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">2</div>
                                    <p class="text-blue-700 font-medium">Take a photo or scan of your payment receipt</p>
                                </div>
                                <div class="flex items-start">
                                    <div class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">3</div>
                                    <p class="text-blue-700 font-medium">Upload the image or PDF file above</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <div class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">4</div>
                                    <p class="text-blue-700 font-medium">An administrator will manually verify your payment</p>
                                </div>
                                <div class="flex items-start">
                                    <div class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">5</div>
                                    <p class="text-blue-700 font-medium">Your reservation will be confirmed after verification</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-upload mr-2"></i>Upload Payment Slip
                        </button>
                        <a href="my_reservations.php" class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg text-center">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reservations
                        </a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-gradient-to-r from-amber-50 to-yellow-50 border-2 border-amber-200 rounded-2xl p-8 text-center card-hover animate-fadeInUp">
                <div class="w-20 h-20 bg-gradient-to-br from-amber-400 to-yellow-500 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-white text-3xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-amber-800 mb-4">Payment Upload Not Available</h3>
                <p class="text-lg text-amber-700 mb-6">
                    <?php if ($reservation['payment_status'] !== 'pending'): ?>
                        This reservation's payment has already been processed and verified.
                    <?php else: ?>
                        The payment deadline has expired for this reservation.
                    <?php endif; ?>
                </p>
                <a href="my_reservations.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg inline-flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Reservations
                </a>
            </div>
        <?php endif; ?>
    </div>
    <!-- Success Payment Upload Modal -->
    <?php if (isset($success_message)): ?>
    <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="successModalContent">
            <div class="p-6">
                <!-- Success Icon -->
                <div class="flex items-center justify-center mb-4">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                    </div>
                </div>
                <!-- Success Title -->
                <div class="text-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">Payment Uploaded Successfully!</h3>
                    <p class="text-gray-600">Your payment slip has been uploaded and is now pending verification.</p>
                </div>
                <!-- Reservation Details in Modal -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-building text-blue-500 mr-2"></i>
                        <span class="font-medium text-gray-800"><?php echo htmlspecialchars($reservation['facility_name']); ?></span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="text-gray-500">Date:</span>
                            <div class="font-medium">
                                <?php 
                                $startDate = date('M j, Y', strtotime($reservation['start_time']));
                                $endDate = date('M j, Y', strtotime($reservation['end_time']));
                                echo $startDate === $endDate ? $startDate : $startDate . ' - ' . $endDate;
                                ?>
                            </div>
                        </div>
                        <div>
                            <span class="text-gray-500">Amount:</span>
                            <div class="font-medium text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></div>
                        </div>
                    </div>
                </div>
                <!-- Next Steps -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h4 class="font-medium text-blue-800 mb-2 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>What happens next?
                    </h4>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li>• An administrator will review your payment slip</li>
                        <li>• You'll receive an email confirmation once verified</li>
                        <li>• Your reservation will be confirmed after manual verification</li>
                        <li>• You can track the status in "My Reservations"</li>
                    </ul>
                </div>
                <!-- Action Buttons -->
                <div class="flex space-x-3">
                    <button onclick="closeSuccessModal()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition duration-200 font-medium">
                        <i class="fas fa-times mr-2"></i>Close
                    </button>
                    <a href="my_reservations.php" class="flex-1 bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 font-medium text-center">
                        <i class="fas fa-calendar mr-2"></i>View Reservations
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
            // Show success modal if it exists
            const successModal = document.getElementById('successModal');
            if (successModal) {
                const modalContent = document.getElementById('successModalContent');
                // Animate modal in
                setTimeout(() => {
                    modalContent.classList.remove('scale-95', 'opacity-0');
                    modalContent.classList.add('scale-100', 'opacity-100');
                }, 100);
            }
        });
        // Function to close success modal
        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            if (modal && modalContent) {
                modalContent.classList.add('scale-95', 'opacity-0');
                modalContent.classList.remove('scale-100', 'opacity-100');
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }
        }
        
        // Enhanced file upload functionality
        function handleFileSelect(input) {
            const file = input.files[0];
            if (file) {
                const fileName = file.name;
                const fileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                
                document.getElementById('fileName').textContent = fileName;
                document.getElementById('fileSize').textContent = fileSize;
                document.getElementById('filePreview').classList.remove('hidden');
                document.getElementById('uploadArea').classList.add('hidden');
            }
        }
        
        function clearFile() {
            document.getElementById('payment_slip').value = '';
            document.getElementById('filePreview').classList.add('hidden');
            document.getElementById('uploadArea').classList.remove('hidden');
        }
        
        // Drag and drop functionality
        const uploadArea = document.getElementById('uploadArea');
        if (uploadArea) {
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const fileInput = document.getElementById('payment_slip');
                    fileInput.files = files;
                    handleFileSelect(fileInput);
                }
            });
        }
        
        // Form submission with loading state
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                const submitBtn = uploadForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...';
                submitBtn.disabled = true;
                
                // Re-enable after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 10000);
            });
        }
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const successModal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            if (successModal && event.target === successModal) {
                closeSuccessModal();
            }
        });
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSuccessModal();
            }
        });
    </script>
</body>
</html>
