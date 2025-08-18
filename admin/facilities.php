<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Handle facility operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_facility':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $hourly_rate = floatval($_POST['hourly_rate']);
                $daily_rate = floatval($_POST['daily_rate']);
                $capacity = intval($_POST['capacity']);
                $category_id = intval($_POST['category_id']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name) || empty($description) || $hourly_rate <= 0 || $daily_rate <= 0 || $capacity <= 0) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        $image_url = null;
                        
                        // Handle image upload
                        if (isset($_FILES['facility_image']) && $_FILES['facility_image']['error'] === UPLOAD_ERR_OK) {
                            $upload_dir = '../uploads/facilities/';
                            $file_extension = strtolower(pathinfo($_FILES['facility_image']['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            
                            if (in_array($file_extension, $allowed_extensions)) {
                                $filename = 'facility_' . time() . '_' . uniqid() . '.' . $file_extension;
                                $upload_path = $upload_dir . $filename;
                                
                                if (move_uploaded_file($_FILES['facility_image']['tmp_name'], $upload_path)) {
                                    $image_url = 'uploads/facilities/' . $filename;
                                } else {
                                    $error_message = 'Failed to upload image. Please try again.';
                                    break;
                                }
                            } else {
                                $error_message = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                                break;
                            }
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO facilities (name, description, hourly_rate, daily_rate, capacity, category_id, image_url, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$name, $description, $hourly_rate, $daily_rate, $capacity, $category_id, $image_url, $is_active]);
                        $success_message = 'Facility added successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to add facility. Please try again.';
                    }
                }
                break;
                
            case 'update_facility':
                $facility_id = intval($_POST['facility_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $hourly_rate = floatval($_POST['hourly_rate']);
                $daily_rate = floatval($_POST['daily_rate']);
                $capacity = intval($_POST['capacity']);
                $category_id = intval($_POST['category_id']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name) || empty($description) || $hourly_rate <= 0 || $daily_rate <= 0 || $capacity <= 0) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        // Get current image URL
                        $stmt = $pdo->prepare("SELECT image_url FROM facilities WHERE id = ?");
                        $stmt->execute([$facility_id]);
                        $current_facility = $stmt->fetch();
                        $image_url = $current_facility['image_url'];
                        
                        // Handle image upload
                        if (isset($_FILES['facility_image']) && $_FILES['facility_image']['error'] === UPLOAD_ERR_OK) {
                            $upload_dir = '../uploads/facilities/';
                            $file_extension = strtolower(pathinfo($_FILES['facility_image']['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            
                            if (in_array($file_extension, $allowed_extensions)) {
                                $filename = 'facility_' . time() . '_' . uniqid() . '.' . $file_extension;
                                $upload_path = $upload_dir . $filename;
                                
                                if (move_uploaded_file($_FILES['facility_image']['tmp_name'], $upload_path)) {
                                    // Delete old image if exists
                                    if ($image_url && file_exists('../' . $image_url)) {
                                        unlink('../' . $image_url);
                                    }
                                    $image_url = 'uploads/facilities/' . $filename;
                                } else {
                                    $error_message = 'Failed to upload image. Please try again.';
                                    break;
                                }
                            } else {
                                $error_message = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                                break;
                            }
                        }
                        
                        $stmt = $pdo->prepare("
                            UPDATE facilities 
                            SET name = ?, description = ?, hourly_rate = ?, daily_rate = ?, capacity = ?, category_id = ?, image_url = ?, is_active = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $description, $hourly_rate, $daily_rate, $capacity, $category_id, $image_url, $is_active, $facility_id]);
                        $success_message = 'Facility updated successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to update facility. Please try again.';
                    }
                }
                break;
                
            case 'delete_facility':
                $facility_id = intval($_POST['facility_id']);
                
                // First check if the facility exists
                $stmt = $pdo->prepare("SELECT id, name FROM facilities WHERE id = ?");
                $stmt->execute([$facility_id]);
                $facility_exists = $stmt->fetch();
                
                if (!$facility_exists) {
                    $error_message = "Facility with ID {$facility_id} not found.";
                    break;
                }
                
                try {
                    // Check if facility has any active reservations (not completed or cancelled)
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ? AND status NOT IN ('completed', 'cancelled')");
                    $stmt->execute([$facility_id]);
                    $active_reservation_count = $stmt->fetch()['count'];
                    
                    // Check total reservations for display purposes
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ?");
                    $stmt->execute([$facility_id]);
                    $total_reservation_count = $stmt->fetch()['count'];
                    
                    // Check for any other references to this facility
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM waitlist WHERE facility_id = ?");
                    $stmt->execute([$facility_id]);
                    $waitlist_count = $stmt->fetch()['count'];
                    
                    if ($waitlist_count > 0) {
                        $error_message = "Cannot delete facility with existing waitlist entries. This facility has {$waitlist_count} waitlist entry(ies).";
                        break;
                    }
                    
                    if ($active_reservation_count > 0) {
                        // Get more details about the active reservations
                        $stmt = $pdo->prepare("
                            SELECT r.id, u.full_name as user_name, f.name as facility_name, 
                                   DATE(r.start_time) as reservation_date, r.start_time, r.end_time, r.status
                            FROM reservations r 
                            JOIN users u ON r.user_id = u.id
                            JOIN facilities f ON r.facility_id = f.id
                            WHERE r.facility_id = ? AND r.status NOT IN ('completed', 'cancelled')
                            ORDER BY r.start_time DESC
                        ");
                        $stmt->execute([$facility_id]);
                        $active_reservations = $stmt->fetchAll();
                        
                        $error_message = "Cannot delete facility with active reservations. This facility has {$active_reservation_count} active reservation(s).";
                        
                        if ($active_reservation_count <= 5) {
                            $error_message .= " Please complete or cancel the following reservations first:";
                            foreach ($active_reservations as $reservation) {
                                $error_message .= "<br>• Reservation #{$reservation['id']} - {$reservation['user_name']} on {$reservation['reservation_date']} ({$reservation['start_time']} - {$reservation['end_time']}) - Status: " . ucfirst($reservation['status']);
                            }
                        } else {
                            $error_message .= " Please complete or cancel all active reservations before deleting this facility.";
                        }
                        
                        if ($total_reservation_count > $active_reservation_count) {
                            $completed_count = $total_reservation_count - $active_reservation_count;
                            $error_message .= "<br><br><strong>Note:</strong> This facility also has {$completed_count} completed/cancelled reservation(s) that will be automatically removed when the facility is deleted.";
                        }
                    } else {
                        // Get facility image URL before deletion
                        $stmt = $pdo->prepare("SELECT image_url FROM facilities WHERE id = ?");
                        $stmt->execute([$facility_id]);
                        $facility = $stmt->fetch();
                        
                        // Check if facility exists
                        if (!$facility) {
                            throw new Exception("Facility with ID {$facility_id} not found");
                        }
                        
                        // Delete the facility
                        $stmt = $pdo->prepare("DELETE FROM facilities WHERE id = ?");
                        $result = $stmt->execute([$facility_id]);
                        
                        if (!$result) {
                            throw new Exception("Database deletion failed");
                        }
                        
                        $rowCount = $stmt->rowCount();
                        
                        if ($rowCount === 0) {
                            throw new Exception("No facility was deleted. Facility ID {$facility_id} may not exist.");
                        }
                        
                        // Delete the image file if it exists
                        if ($facility['image_url']) {
                            $imagePath = '../' . $facility['image_url'];
                            
                            if (file_exists($imagePath)) {
                                if (!unlink($imagePath)) {
                                    // Silent fail for image deletion
                                }
                            }
                        }
                        
                        $success_message = 'Facility deleted successfully!';
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to delete facility. Please try again.';
                }
                break;
                
            case 'toggle_status':
                $facility_id = intval($_POST['facility_id']);
                $new_status = intval($_POST['new_status']);
                try {
                    $stmt = $pdo->prepare("UPDATE facilities SET is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_status, $facility_id]);
                    $success_message = 'Facility status updated successfully!';
                } catch (Exception $e) {
                    $error_message = 'Failed to update facility status. Please try again.';
                }
                break;
        }
    }
}

// Get all facilities with categories
$stmt = $pdo->query("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    ORDER BY f.created_at DESC
");
$facilities = $stmt->fetchAll();

// Get categories for form
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Facilities - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/enhanced-ui.css">
    <script src="../assets/js/modal-system.js"></script>
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
                        'bounce-in': 'bounceIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s infinite',
                        'float': 'float 3s ease-in-out infinite',
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
                        bounceIn: {
                            '0%': { transform: 'scale(0.3)', opacity: '0' },
                            '50%': { transform: 'scale(1.05)' },
                            '70%': { transform: 'scale(0.9)' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .facility-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .facility-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .modal {
            transition: all 0.3s ease;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .action-button {
            transition: all 0.2s ease;
        }
        .action-button:hover {
            transform: scale(1.05);
        }
        .upload-zone {
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            border-color: #3B82F6;
            background-color: #F0F9FF;
        }
        .upload-zone.dragover {
            border-color: #3B82F6;
            background-color: #E0F2FE;
            transform: scale(1.02);
        }
        /* Ensure modal is properly positioned and clickable */
        .modal {
            pointer-events: auto;
        }
        .modal.show {
            pointer-events: auto;
        }
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .facility-card {
            pointer-events: auto !important;
        }
        .facility-card * {
            pointer-events: auto !important;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-green-50 via-white to-blue-50 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white/80 backdrop-blur-md shadow-lg sticky top-0 z-40 border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-blue-600 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-building text-white text-xl"></i>
                        </div>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Admin</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700 font-medium">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="dashboard.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="categories.php" class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-tags mr-2"></i>Categories
                    </a>
                    <a href="reservations.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-calendar-check mr-2"></i>Reservations
                    </a>
                    <a href="users.php" class="bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-users-cog mr-2"></i>Users
                    </a>
                    <a href="../index.php" class="bg-gradient-to-r from-teal-500 to-teal-600 hover:from-teal-600 hover:to-teal-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-home mr-2"></i>View Site
                    </a>
                    <a href="../auth/logout.php" class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="text-center mb-6">
                <h1 class="text-4xl font-bold text-gray-900 mb-2 flex items-center justify-center">
                    <i class="fas fa-building text-primary mr-3"></i>Manage Facilities
                </h1>
                <p class="text-gray-600 text-lg">Add, edit, and manage facility information with ease</p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100 text-sm font-medium">Total Facilities</p>
                        <p class="text-3xl font-bold"><?php echo count($facilities); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-building text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">Active Facilities</p>
                        <p class="text-3xl font-bold"><?php echo count(array_filter($facilities, function($f) { return $f['is_active']; })); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-orange-500 to-orange-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-100 text-sm font-medium">Categories</p>
                        <p class="text-3xl font-bold"><?php echo count($categories); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tags text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100 text-sm font-medium">Avg. Hourly Rate</p>
                        <p class="text-3xl font-bold">₱<?php echo number_format(array_sum(array_column($facilities, 'hourly_rate')) / max(count($facilities), 1), 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Facility Button -->
        <div class="text-center mb-8">
            <button onclick="openAddModal()" class="bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg text-lg" style="pointer-events: auto;">
                <i class="fas fa-plus mr-3"></i>Add New Facility
            </button>
        </div>



        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-gradient-to-r from-green-100 to-green-200 border border-green-300 text-green-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-gradient-to-r from-red-100 to-red-200 border border-red-300 text-red-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo $error_message; ?></span>
                </div>
            </div>
        <?php endif; ?>



        <!-- Facilities Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($facilities as $index => $facility): ?>
                <div class="facility-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 animate-slide-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <!-- Header -->
                    <div class="h-40 bg-gradient-to-br from-green-400 to-blue-500 flex items-center justify-center relative overflow-hidden">
                        <?php if ($facility['image_url']): ?>
                            <img src="../<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                            <div class="relative z-10 text-center">
                                <i class="fas fa-building text-white text-5xl mb-2"></i>
                                <p class="text-white text-sm font-medium">No Image</p>
                            </div>
                        <?php endif; ?>
                        <div class="absolute top-3 right-3">
                            <span class="status-badge inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?php echo $facility['is_active'] ? 'bg-green-400 text-green-900' : 'bg-red-400 text-red-900'; ?>">
                                <i class="<?php echo $facility['is_active'] ? 'fas fa-check' : 'fas fa-times'; ?> mr-1"></i>
                                <?php echo $facility['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="absolute bottom-3 left-3">
                            <span class="bg-black/50 text-white px-2 py-1 rounded-lg text-xs font-medium">
                                <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($facility['category_name']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($facility['name']); ?></h3>
                            <div class="text-right">
                                <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-3 py-1 rounded-full text-xs font-bold mb-1">
                                    ₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr
                                </div>
                                <div class="bg-gradient-to-r from-green-500 to-green-600 text-white px-3 py-1 rounded-full text-xs font-bold">
                                    ₱<?php echo number_format($facility['daily_rate'] ?? 0, 2); ?>/day
                                </div>
                            </div>
                        </div>
                        
                        <p class="text-gray-600 mb-4 text-sm leading-relaxed"><?php echo htmlspecialchars($facility['description']); ?></p>
                        
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-6 p-3 bg-gray-50 rounded-xl">
                            <span class="flex items-center">
                                <i class="fas fa-users text-blue-500 mr-2"></i>
                                <span class="font-semibold"><?php echo $facility['capacity']; ?> people</span>
                            </span>
                            <span class="flex items-center">
                                <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                                <span class="font-semibold">Available</span>
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($facility)); ?>)" 
                                        class="action-button bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </button>
                                <button onclick="toggleStatus(<?php echo $facility['id']; ?>, <?php echo $facility['is_active'] ? 0 : 1; ?>)" 
                                        class="action-button <?php echo $facility['is_active'] ? 'bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700' : 'bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700'; ?> text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                    <i class="<?php echo $facility['is_active'] ? 'fas fa-pause' : 'fas fa-play'; ?> mr-1"></i>
                                    <?php echo $facility['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </div>
                            <button onclick="deleteFacility(<?php echo $facility['id']; ?>)" 
                                    class="action-button w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                <i class="fas fa-trash mr-1"></i>Delete Facility
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($facilities)): ?>
            <div class="text-center py-16 animate-fade-in">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-building text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-semibold text-gray-600 mb-2">No facilities found</h3>
                <p class="text-gray-500 mb-6">Get started by adding your first facility to the system.</p>
                <button onclick="openAddModal()" class="bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg text-lg">
                    <i class="fas fa-plus mr-3"></i>Add First Facility
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Facility Modal -->
    <div id="facilityModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-8">
                <div class="flex justify-between items-center mb-8">
                    <h2 id="modalTitle" class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-building text-primary mr-3"></i>Add New Facility
                    </h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                
                <form id="facilityForm" method="POST" class="space-y-6" enctype="multipart/form-data">
                    <input type="hidden" id="action" name="action" value="add_facility">
                    <input type="hidden" id="facility_id" name="facility_id">
                    
                    <div>
                        <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">🏢 Facility Name *</label>
                        <input type="text" id="name" name="name" required 
                               class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">📝 Description *</label>
                        <textarea id="description" name="description" rows="4" required
                                  class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="hourly_rate" class="block text-sm font-semibold text-gray-700 mb-2">⏰ Hourly Rate (₱) *</label>
                            <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        </div>
                        <div>
                            <label for="daily_rate" class="block text-sm font-semibold text-gray-700 mb-2">📅 Daily Rate (₱) *</label>
                            <input type="number" id="daily_rate" name="daily_rate" step="0.01" min="0" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        </div>
                        <div>
                            <label for="capacity" class="block text-sm font-semibold text-gray-700 mb-2">👥 Capacity *</label>
                            <input type="number" id="capacity" name="capacity" min="1" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        </div>
                    </div>
                    
                    <div>
                        <label for="category_id" class="block text-sm font-semibold text-gray-700 mb-2">🏷️ Category *</label>
                        <select id="category_id" name="category_id" required 
                                class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="facility_image" class="block text-sm font-semibold text-gray-700 mb-2">📸 Facility Image</label>
                        <div class="upload-zone mt-1 flex justify-center px-8 pt-8 pb-8 border-2 border-gray-200 border-dashed rounded-xl hover:border-primary transition duration-200 bg-gray-50">
                            <div class="space-y-3 text-center">
                                <div class="w-16 h-16 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-cloud-upload-alt text-blue-500 text-2xl"></i>
                                </div>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="facility_image" class="relative cursor-pointer bg-white rounded-lg font-semibold text-primary hover:text-secondary focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary px-4 py-2 border border-primary hover:bg-primary hover:text-white transition duration-200">
                                        <span>📁 Upload a file</span>
                                        <input id="facility_image" name="facility_image" type="file" class="sr-only" accept="image/*">
                                    </label>
                                    <p class="pl-3 py-2">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF, WebP up to 10MB</p>
                            </div>
                        </div>
                        <div id="imagePreview" class="mt-4 hidden">
                            <div class="relative">
                                <img id="previewImg" src="" alt="Preview" class="w-full h-40 object-cover rounded-xl shadow-lg">
                                <button type="button" onclick="removeImage()" class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-8 h-8 flex items-center justify-center hover:bg-red-600 transition duration-200">
                                    <i class="fas fa-times text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center p-4 bg-blue-50 rounded-xl border border-blue-200">
                        <input type="checkbox" id="is_active" name="is_active" class="h-5 w-5 text-primary focus:ring-primary border-gray-300 rounded">
                        <label for="is_active" class="ml-3 block text-sm font-semibold text-gray-700">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>Active (available for booking)
                        </label>
                    </div>
                    
                    <div class="flex space-x-4 pt-6">
                        <button type="submit" id="submitBtn" 
                                class="flex-1 bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-save mr-2"></i>Save Facility
                        </button>
                        <button type="button" onclick="closeModal()" 
                                class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-500 mr-3"></i>Delete Facility
                    </h2>
                    <button onclick="closeDeleteConfirmModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                
                <div class="mb-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-building text-red-500 text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-gray-700 text-center mb-2 text-lg">Are you sure you want to delete this facility?</p>
                    <p class="text-sm text-gray-500 text-center mb-4">This action cannot be undone and will permanently remove the facility from the system.</p>
                    
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-red-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-red-700 mb-1">Important Notes:</p>
                                <ul class="text-sm text-red-600 space-y-1">
                                    <li>• All facility images will be permanently deleted</li>
                                    <li>• Facility information will be completely removed</li>
                                    <li>• This action cannot be reversed</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-yellow-700 mb-1">Restriction:</p>
                                <p class="text-sm text-yellow-600">Facilities with existing reservations cannot be deleted. You must cancel or complete all reservations first.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <form id="deleteConfirmForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="delete_facility">
                    <input type="hidden" id="delete_facility_id" name="facility_id">
                    <div class="flex space-x-4">
                        <button type="button" onclick="closeDeleteConfirmModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-trash mr-2"></i>Delete Facility
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() {
            const modal = document.getElementById('facilityModal');
            document.getElementById('modalTitle').textContent = 'Add New Facility';
            document.getElementById('action').value = 'add_facility';
            document.getElementById('facilityForm').reset();
            document.getElementById('facility_id').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Facility';
            document.getElementById('imagePreview').classList.add('hidden');
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function openEditModal(facility) {
            const modal = document.getElementById('facilityModal');
            document.getElementById('modalTitle').textContent = 'Edit Facility';
            document.getElementById('action').value = 'update_facility';
            document.getElementById('facility_id').value = facility.id;
            document.getElementById('name').value = facility.name;
            document.getElementById('description').value = facility.description;
            document.getElementById('hourly_rate').value = facility.hourly_rate;
            document.getElementById('daily_rate').value = facility.daily_rate || '';
            document.getElementById('capacity').value = facility.capacity;
            document.getElementById('category_id').value = facility.category_id;
            document.getElementById('is_active').checked = facility.is_active == 1;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update Facility';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeModal() {
            const modal = document.getElementById('facilityModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        async function toggleStatus(facilityId, newStatus) {
            try {
                // Check if ModalSystem is available
                if (!window.ModalSystem) {
                    console.error('ModalSystem not found, falling back to native confirm');
                    const confirmed = confirm('Are you sure you want to change the facility status?');
                    if (confirmed) {
                        submitToggleStatusForm(facilityId, newStatus);
                    }
                    return;
                }
                
                const confirmed = await window.ModalSystem.confirm(
                    'Are you sure you want to change the facility status?',
                    'Change Status',
                    'question'
                );
                
                if (confirmed) {
                    submitToggleStatusForm(facilityId, newStatus);
                }
            } catch (error) {
                console.error('Error in toggleStatus:', error);
                // Fallback to native confirm
                const confirmed = confirm('Are you sure you want to change the facility status?');
                if (confirmed) {
                    submitToggleStatusForm(facilityId, newStatus);
                }
            }
        }

        function submitToggleStatusForm(facilityId, newStatus) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="facility_id" value="${facilityId}">
                <input type="hidden" name="new_status" value="${newStatus}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deleteFacility(facilityId) {
            // Set the facility ID in the modal form
            document.getElementById('delete_facility_id').value = facilityId;
            
            // Show the custom delete confirmation modal
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }



        // Close facility modal when clicking outside
        document.getElementById('facilityModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close delete confirmation modal when clicking outside
        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirmModal();
            }
        });

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteConfirmModal();
            }
        });

        // Image preview functionality
        document.getElementById('facility_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });

        function removeImage() {
            document.getElementById('facility_image').value = '';
            document.getElementById('imagePreview').classList.add('hidden');
        }

        // Drag and drop functionality
        const dropZone = document.querySelector('.upload-zone');
        const fileInput = document.getElementById('facility_image');

        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });

        // Ensure all buttons are clickable
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize page functionality
            if (!window.ModalSystem) {
                console.warn('ModalSystem not found on DOMContentLoaded');
            }
        });
    </script>
</body>
</html>
