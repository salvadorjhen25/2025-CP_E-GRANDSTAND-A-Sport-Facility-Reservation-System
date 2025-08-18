<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Handle user operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_admin':
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];
                $full_name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $role = 'admin'; // Force admin role
                
                if (empty($username) || empty($password) || empty($full_name) || empty($email)) {
                    $error_message = 'Please fill in all required fields.';
                } elseif ($password !== $confirm_password) {
                    $error_message = 'Passwords do not match.';
                } elseif (strlen($password) < 6) {
                    $error_message = 'Password must be at least 6 characters long.';
                } else {
                    try {
                        // Check if username already exists
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
                        $stmt->execute([$username]);
                        if ($stmt->fetch()['count'] > 0) {
                            $error_message = 'Username already exists.';
                        } else {
                            // Check if email already exists
                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email = ?");
                            $stmt->execute([$email]);
                            if ($stmt->fetch()['count'] > 0) {
                                $error_message = 'Email already exists.';
                            } else {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("
                                    INSERT INTO users (username, password, full_name, email, role, created_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([$username, $hashed_password, $full_name, $email, $role]);
                                $success_message = 'Admin user added successfully!';
                            }
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to add admin user. Please try again.';
                    }
                }
                break;
                
            case 'update_user':
                $user_id = intval($_POST['user_id']);
                $full_name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($full_name) || empty($email)) {
                    $error_message = 'Please fill in all required fields.';
                } else {
                    try {
                        // Check if email already exists for other users
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email = ? AND id != ?");
                        $stmt->execute([$email, $user_id]);
                        if ($stmt->fetch()['count'] > 0) {
                            $error_message = 'Email already exists.';
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, is_active = ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$full_name, $email, $is_active, $user_id]);
                            $success_message = 'User updated successfully!';
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to update user. Please try again.';
                    }
                }
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id']);
                
                // Prevent deleting self
                if ($user_id == $_SESSION['user_id']) {
                    $error_message = 'You cannot delete your own account.';
                } else {
                    try {
                        // Check if user has any reservations
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $reservation_count = $stmt->fetch()['count'];
                        
                        if ($reservation_count > 0) {
                            $error_message = 'Cannot delete user with existing reservations.';
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                            $stmt->execute([$user_id]);
                            $success_message = 'User deleted successfully!';
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to delete user. Please try again.';
                    }
                }
                break;
                
            case 'reset_password':
                $user_id = intval($_POST['user_id']);
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];
                
                if (empty($new_password)) {
                    $error_message = 'Please enter a new password.';
                } elseif ($new_password !== $confirm_password) {
                    $error_message = 'Passwords do not match.';
                } elseif (strlen($new_password) < 6) {
                    $error_message = 'Password must be at least 6 characters long.';
                } else {
                    try {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$hashed_password, $user_id]);
                        $success_message = 'Password reset successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to reset password. Please try again.';
                    }
                }
                break;
        }
    }
}

// Get all users
$stmt = $pdo->query("
    SELECT u.*, 
           COUNT(r.id) as reservation_count,
           SUM(r.total_amount) as total_spent
    FROM users u 
    LEFT JOIN reservations r ON u.id = r.user_id 
    GROUP BY u.id 
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?php echo SITE_NAME; ?></title>
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
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .user-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .modal {
            transition: all 0.3s ease;
            pointer-events: auto;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .user-card, .user-card * {
            pointer-events: auto !important;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center">
                        <i class="fas fa-users-cog text-primary text-2xl mr-3"></i>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Admin</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="dashboard.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="facilities.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-building mr-2"></i>Facilities
                    </a>
                    <a href="categories.php" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-tags mr-2"></i>Categories
                    </a>
                    <a href="usage_management.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-clock mr-2"></i>Usage Management
                    </a>
                    <a href="../index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>View Site
                    </a>
                    <a href="../auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                        <i class="fas fa-users-cog text-primary mr-3"></i>Manage Users
                    </h1>
                    <p class="text-gray-600">Add, edit, and manage user accounts</p>
                </div>
                <button onclick="openAddModal()" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-user-plus mr-2"></i>Add New Admin
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Users Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($users as $index => $user): ?>
                <div class="user-card bg-white rounded-lg shadow-md overflow-hidden animate-slide-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <div class="h-32 bg-gradient-to-br from-purple-400 to-pink-500 flex items-center justify-center relative">
                        <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                        <div class="relative z-10">
                            <div class="h-16 w-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="absolute top-2 right-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $user['role'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'; ?>">
                                <i class="<?php echo $user['role'] === 'admin' ? 'fas fa-shield-alt' : 'fas fa-user'; ?> mr-1"></i>
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </div>
                        <?php if ($user['id'] == $_SESSION['user_id']): ?>
                            <div class="absolute top-2 left-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-user-check mr-1"></i>You
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                            <span class="text-sm text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <p class="text-gray-600 mb-3 text-sm"><?php echo htmlspecialchars($user['email']); ?></p>
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
                            <span><i class="fas fa-calendar mr-1"></i><?php echo $user['reservation_count']; ?> reservations</span>
                            <span><i class="fas fa-money-bill mr-1"></i>₱<?php echo number_format($user['total_spent'] ?? 0, 2); ?></span>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" 
                                    class="flex-1 bg-blue-500 hover:bg-blue-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-edit mr-2"></i>Edit
                            </button>
                            <button onclick="openResetPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" 
                                    class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-key mr-2"></i>Reset PW
                            </button>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button onclick="deleteUser(<?php echo $user['id']; ?>)" 
                                        class="flex-1 bg-red-500 hover:bg-red-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-trash mr-2"></i>Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($users)): ?>
            <div class="text-center py-12 animate-fade-in">
                <i class="fas fa-users text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No users found</h3>
                <p class="text-gray-500 mb-6">Get started by adding your first admin user.</p>
                <button onclick="openAddModal()" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-user-plus mr-2"></i>Add First Admin
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 id="modalTitle" class="text-xl font-semibold text-gray-900">Add New Admin</h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="userForm" method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="add_admin">
                    <input type="hidden" id="user_id" name="user_id">
                    
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                        <input type="text" id="username" name="username" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div id="passwordFields">
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                            <input type="password" id="password" name="password" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                        </div>
                        
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                        </div>
                    </div>
                    
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" id="email" name="email" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div id="activeField" class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                        <label for="is_active" class="ml-2 block text-sm text-gray-700">Active (can login)</label>
                    </div>
                    
                    <div class="flex space-x-3 pt-4">
                        <button type="submit" id="submitBtn" 
                                class="flex-1 bg-primary hover:bg-secondary text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Save User
                        </button>
                        <button type="button" onclick="closeModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold text-gray-900">Reset Password</h2>
                    <button onclick="closeResetPasswordModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="resetPasswordForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" id="reset_user_id" name="user_id">
                    
                    <div class="mb-4">
                        <p class="text-gray-600">Reset password for: <span id="resetUserName" class="font-semibold"></span></p>
                    </div>
                    
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                        <input type="password" id="new_password" name="new_password" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div>
                        <label for="confirm_new_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                        <input type="password" id="confirm_new_password" name="confirm_password" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div class="flex space-x-3 pt-4">
                        <button type="submit" 
                                class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-key mr-2"></i>Reset Password
                        </button>
                        <button type="button" onclick="closeResetPasswordModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-500 mr-3"></i>Delete User
                    </h2>
                    <button onclick="closeDeleteConfirmModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-times text-red-500 text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-gray-700 text-center mb-2">Are you sure you want to delete this user?</p>
                    <p class="text-sm text-gray-500 text-center">This action cannot be undone and will permanently remove the user account.</p>
                    <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                        <p class="text-sm text-red-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            <strong>Note:</strong> Users with existing reservations cannot be deleted.
                        </p>
                    </div>
                </div>
                
                <form id="deleteConfirmForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" id="delete_user_id" name="user_id">
                    
                    <div class="flex space-x-3">
                        <button type="button" onclick="closeDeleteConfirmModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-trash mr-2"></i>Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() {
            const modal = document.getElementById('userModal');
            document.getElementById('modalTitle').textContent = 'Add New Admin';
            document.getElementById('action').value = 'add_admin';
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('passwordFields').style.display = 'block';
            document.getElementById('activeField').style.display = 'none';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Admin';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function openEditModal(user) {
            const modal = document.getElementById('userModal');
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('action').value = 'update_user';
            document.getElementById('user_id').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('full_name').value = user.full_name;
            document.getElementById('email').value = user.email;
            document.getElementById('is_active').checked = user.is_active == 1;
            document.getElementById('passwordFields').style.display = 'none';
            document.getElementById('activeField').style.display = 'flex';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update User';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function openResetPasswordModal(userId, userName) {
            const modal = document.getElementById('resetPasswordModal');
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('resetUserName').textContent = userName;
            document.getElementById('resetPasswordForm').reset();
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeModal() {
            const modal = document.getElementById('userModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        function closeResetPasswordModal() {
            const modal = document.getElementById('resetPasswordModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        function deleteUser(userId) {
            const modal = document.getElementById('deleteConfirmModal');
            document.getElementById('delete_user_id').value = userId;
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        // Close modals when clicking outside
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('resetPasswordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetPasswordModal();
            }
        });

        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirmModal();
            }
        });

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeResetPasswordModal();
                closeDeleteConfirmModal();
            }
        });


    </script>
</body>
</html>
