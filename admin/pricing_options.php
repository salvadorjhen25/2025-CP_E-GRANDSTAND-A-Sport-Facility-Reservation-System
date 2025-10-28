<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdminOrStaff();
$pdo = getDBConnection();

$success_message = '';
$error_message = '';

// Handle pricing option operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_pricing_option':
                $facility_id = intval($_POST['facility_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $price_per_hour = floatval($_POST['price_per_hour']);
                $sort_order = intval($_POST['sort_order']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name) || $price_per_hour <= 0) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO facility_pricing_options (facility_id, name, description, price_per_hour, sort_order, is_active) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$facility_id, $name, $description, $price_per_hour, $sort_order, $is_active]);
                        $success_message = 'Pricing option added successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to add pricing option. Please try again.';
                    }
                }
                break;
                
            case 'update_pricing_option':
                $pricing_option_id = intval($_POST['pricing_option_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $price_per_hour = floatval($_POST['price_per_hour']);
                $sort_order = intval($_POST['sort_order']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name) || $price_per_hour <= 0) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE facility_pricing_options 
                            SET name = ?, description = ?, price_per_hour = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $description, $price_per_hour, $sort_order, $is_active, $pricing_option_id]);
                        $success_message = 'Pricing option updated successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to update pricing option. Please try again.';
                    }
                }
                break;
                
            case 'delete_pricing_option':
                $pricing_option_id = intval($_POST['pricing_option_id']);
                try {
                    // Check if pricing option is used in any reservations
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservation_pricing_selections WHERE pricing_option_id = ?");
                    $stmt->execute([$pricing_option_id]);
                    $result = $stmt->fetch();
                    
                    if ($result['count'] > 0) {
                        $error_message = 'Cannot delete pricing option that is used in existing reservations.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM facility_pricing_options WHERE id = ?");
                        $stmt->execute([$pricing_option_id]);
                        $success_message = 'Pricing option deleted successfully!';
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to delete pricing option. Please try again.';
                }
                break;
        }
    }
}

// Get facility ID from URL
$facility_id = $_GET['facility_id'] ?? null;
if (!$facility_id) {
    header('Location: facilities.php');
    exit();
}

// Get facility details
$stmt = $pdo->prepare("SELECT * FROM facilities WHERE id = ?");
$stmt->execute([$facility_id]);
$facility = $stmt->fetch();

if (!$facility) {
    header('Location: facilities.php');
    exit();
}

// Get pricing options for this facility
$stmt = $pdo->prepare("
    SELECT * FROM facility_pricing_options 
    WHERE facility_id = ? 
    ORDER BY sort_order ASC, name ASC
");
$stmt->execute([$facility_id]);
$pricing_options = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pricing Options - <?php echo htmlspecialchars($facility['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .gradient-bg {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.95) 0%, rgba(197, 176, 205, 0.95) 50%, rgba(65, 94, 114, 0.1) 100%);
            border-bottom: 3px solid rgba(65, 94, 114, 0.3);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(23, 49, 62, 0.15);
        }
        .card-shadow {
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-shadow:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
        }
        .btn-primary {
            background: linear-gradient(135deg, #415E72, #17313E);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.3);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #C5B0CD, #415E72);
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(197, 176, 205, 0.4);
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(243, 226, 212, 0.3), rgba(197, 176, 205, 0.2));
            transform: scale(1.01);
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        .modal.show {
            opacity: 1 !important;
            visibility: visible !important;
        }
        .modal {
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .animate-slide-up {
            animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Navigation -->
    <nav class="gradient-bg text-white shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20">
                <div class="flex items-center">
                    <a href="facilities.php" class="flex items-center space-x-3 bg-white/20 hover:bg-white/30 px-4 py-2 rounded-xl transition-all duration-300 backdrop-blur-sm">
                        <i class="fas fa-arrow-left text-xl"></i>
                        <span class="text-lg font-semibold">Back to Facilities</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-center">
                        <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($facility['name']); ?></h1>
                        <p class="text-sm opacity-90">Pricing Options Management</p>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if ($success_message): ?>
            <div class="bg-gradient-to-r from-green-100 to-green-200 border border-green-300 text-green-800 px-6 py-4 rounded-xl mb-6 animate-slide-up shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-gradient-to-r from-red-100 to-red-200 border border-red-300 text-red-800 px-6 py-4 rounded-xl mb-6 animate-slide-up shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Add Pricing Option Button -->
        <div class="mb-8 animate-slide-up" style="animation-delay: 0.1s;">
            <button type="button" onclick="openAddModal()" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg text-lg flex items-center space-x-3" style="pointer-events: auto;">
                <i class="fas fa-plus text-xl"></i>
                <span>Add New Pricing Option</span>
            </button>
        </div>

        <!-- Pricing Options List -->
        <div class="bg-white rounded-2xl card-shadow overflow-hidden animate-slide-up" style="animation-delay: 0.2s;">
            <div class="px-8 py-6 bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-tags text-[#415E72] mr-3"></i>Pricing Options
                        </h2>
                        <p class="text-gray-600 mt-2">Manage different pricing packages for this facility</p>
                    </div>
                    <div class="bg-gradient-to-r from-[#415E72] to-[#17313E] text-white px-4 py-2 rounded-xl">
                        <span class="text-sm font-semibold"><?php echo count($pricing_options); ?> Options</span>
                    </div>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Price/Hour</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Sort Order</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($pricing_options)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center text-gray-500">
                                    <div class="animate-fade-in">
                                        <div class="w-20 h-20 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full flex items-center justify-center mx-auto mb-6">
                                            <i class="fas fa-tag text-gray-400 text-3xl"></i>
                                        </div>
                                        <h3 class="text-xl font-semibold text-gray-600 mb-2">No pricing options found</h3>
                                        <p class="text-gray-500 mb-6">Add your first pricing option to get started</p>
                                        <button type="button" onclick="openAddModal()" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg" style="pointer-events: auto;">
                                            <i class="fas fa-plus mr-2"></i>Add First Option
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pricing_options as $index => $option): ?>
                                <tr class="table-row transition-all duration-300" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($option['name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-700"><?php echo htmlspecialchars($option['description'] ?: 'No description'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-green-600 bg-green-50 px-3 py-1 rounded-full">₱<?php echo number_format($option['price_per_hour'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 bg-gray-100 px-3 py-1 rounded-full w-fit"><?php echo $option['sort_order']; ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="status-badge inline-flex px-3 py-1 text-xs font-semibold rounded-full <?php echo $option['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <i class="<?php echo $option['is_active'] ? 'fas fa-check' : 'fas fa-times'; ?> mr-1"></i>
                                            <?php echo $option['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button type="button" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($option)); ?>)" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg transition duration-200 transform hover:scale-105" style="pointer-events: auto;">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                        </button>
                                            <button type="button" onclick="deletePricingOption(<?php echo $option['id']; ?>)" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg transition duration-200 transform hover:scale-105" style="pointer-events: auto;">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                        </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="pricingModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4">
            <div class="px-8 py-6 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100 rounded-t-2xl">
                <div class="flex justify-between items-center">
                    <h3 id="modalTitle" class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-tag text-[#415E72] mr-3"></i>Add Pricing Option
                    </h3>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                </div>
                
            <form id="pricingForm" method="POST" class="px-8 py-6">
                    <input type="hidden" name="action" id="formAction" value="add_pricing_option">
                    <input type="hidden" name="facility_id" value="<?php echo $facility_id; ?>">
                    <input type="hidden" name="pricing_option_id" id="pricing_option_id">
                    
                <div class="space-y-6">
                        <div>
                        <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">🏷️ Name *</label>
                            <input type="text" id="name" name="name" required 
                               class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#415E72] focus:border-[#415E72] transition duration-200 bg-white"
                               placeholder="e.g., With Aircon, Lights On, Premium Package">
                        </div>
                        
                        <div>
                        <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">📝 Description</label>
                            <textarea id="description" name="description" rows="3"
                                  class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#415E72] focus:border-[#415E72] transition duration-200 bg-white"
                                  placeholder="Describe what this pricing option includes..."></textarea>
                        </div>
                        
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="price_per_hour" class="block text-sm font-semibold text-gray-700 mb-2">💰 Price per Hour (₱) *</label>
                            <input type="number" id="price_per_hour" name="price_per_hour" step="0.01" min="0" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#415E72] focus:border-[#415E72] transition duration-200 bg-white"
                                   placeholder="0.00">
                        </div>
                        
                        <div>
                            <label for="sort_order" class="block text-sm font-semibold text-gray-700 mb-2">🔢 Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" min="0" value="0"
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#415E72] focus:border-[#415E72] transition duration-200 bg-white"
                                   placeholder="0">
                        </div>
                        </div>
                        
                    <div class="flex items-center p-4 bg-blue-50 rounded-xl border border-blue-200">
                            <input type="checkbox" id="is_active" name="is_active" checked
                               class="h-6 w-6 text-[#415E72] focus:ring-[#415E72] border-gray-300 rounded">
                        <label for="is_active" class="ml-3 block text-sm font-semibold text-gray-700">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>Active (available for selection)
                        </label>
                        </div>
                    </div>
                    
                <div class="flex space-x-4 pt-6">
                    <button type="button" onclick="closeModal()" class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    <button type="submit" class="flex-1 btn-primary text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-save mr-2"></i>Save Option
                        </button>
                    </div>
                </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
            <div class="px-8 py-6 border-b border-gray-200 bg-gradient-to-r from-red-50 to-red-100 rounded-t-2xl">
                <div class="flex justify-between items-center">
                    <h3 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-500 mr-3"></i>Confirm Delete
                    </h3>
                    <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
            </div>
            <div class="px-8 py-6">
                <div class="flex items-center justify-center mb-4">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-trash text-red-500 text-2xl"></i>
                    </div>
                </div>
                <p class="text-gray-700 text-center mb-4 text-lg">Are you sure you want to delete this pricing option?</p>
                <p class="text-sm text-gray-500 text-center mb-6">This action cannot be undone and will permanently remove the pricing option from the system.</p>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-yellow-500 mt-1 mr-3"></i>
                        <div>
                            <p class="text-sm font-semibold text-yellow-700 mb-1">Important:</p>
                            <p class="text-sm text-yellow-600">If this pricing option is used in existing reservations, it cannot be deleted.</p>
                        </div>
                    </div>
                </div>
                </div>
            <div class="px-8 py-6 bg-gray-50 flex space-x-4 rounded-b-2xl">
                <button onclick="closeDeleteModal()" class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                <form id="deleteForm" method="POST" class="flex-1">
                        <input type="hidden" name="action" value="delete_pricing_option">
                        <input type="hidden" name="pricing_option_id" id="delete_pricing_option_id">
                    <button type="submit" class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                        <i class="fas fa-trash mr-2"></i>Delete Option
                        </button>
                    </form>
            </div>
        </div>
    </div>

    <script>
        // Ensure all buttons are clickable
        document.addEventListener('DOMContentLoaded', function() {
            
            // Test button clicks
            const addButton = document.querySelector('button[onclick="openAddModal()"]');
            if (addButton) {
                addButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    openAddModal();
                });
            }
        });

        function openAddModal() {
            const modal = document.getElementById('pricingModal');
            if (modal) {
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-tag text-[#415E72] mr-3"></i>Add Pricing Option';
            document.getElementById('formAction').value = 'add_pricing_option';
            document.getElementById('pricingForm').reset();
            document.getElementById('pricing_option_id').value = '';
            document.getElementById('is_active').checked = true;
                modal.classList.add('show');
                modal.style.pointerEvents = 'auto';
                modal.style.zIndex = '9999';
            } else {
                console.error('Modal not found');
            }
        }

        function openEditModal(option) {
            const modal = document.getElementById('pricingModal');
            if (modal) {
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-tag text-[#415E72] mr-3"></i>Edit Pricing Option';
            document.getElementById('formAction').value = 'update_pricing_option';
            document.getElementById('pricing_option_id').value = option.id;
            document.getElementById('name').value = option.name;
            document.getElementById('description').value = option.description || '';
            document.getElementById('price_per_hour').value = option.price_per_hour;
            document.getElementById('sort_order').value = option.sort_order;
            document.getElementById('is_active').checked = option.is_active == 1;
                modal.classList.add('show');
                modal.style.pointerEvents = 'auto';
                modal.style.zIndex = '9999';
            } else {
                console.error('Modal not found');
            }
        }

        function closeModal() {
            const modal = document.getElementById('pricingModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.pointerEvents = 'none';
                modal.style.zIndex = '50';
            }
        }

        function deletePricingOption(id) {
            const modal = document.getElementById('deleteModal');
            if (modal) {
            document.getElementById('delete_pricing_option_id').value = id;
                modal.classList.add('show');
                modal.style.pointerEvents = 'auto';
                modal.style.zIndex = '9999';
            } else {
                console.error('Delete modal not found');
            }
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.pointerEvents = 'none';
                modal.style.zIndex = '50';
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const pricingModal = document.getElementById('pricingModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (pricingModal && e.target === pricingModal) {
                closeModal();
            }
            
            if (deleteModal && e.target === deleteModal) {
                closeDeleteModal();
            }
        });

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });

        // Debug: Log all button clicks
        document.addEventListener('click', function(e) {
            if (e.target.tagName === 'BUTTON') {
            }
        });
    </script>
</body>
</html>
