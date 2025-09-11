<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Handle category operation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_category':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                
                if (empty($name)) {
                    $error_message = 'Category name is required.';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, description, created_at) VALUES (?, ?, NOW())");
                        $stmt->execute([$name, $description]);
                        $success_message = 'Category added successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to add category. Please try again.';
                    }
                }
                break;
                
            case 'update_category':
                $category_id = intval($_POST['category_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                
                if (empty($name)) {
                    $error_message = 'Category name is required.';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                        $stmt->execute([$name, $description, $category_id]);
                        $success_message = 'Category updated successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to update category. Please try again.';
                    }
                }
                break;
                
            case 'delete_category':
                $category_id = intval($_POST['category_id']);
                try {
                    // Check if category has any facilities
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM facilities WHERE category_id = ?");
                    $stmt->execute([$category_id]);
                    $facility_count = $stmt->fetch()['count'];
                    
                    if ($facility_count > 0) {
                        $error_message = 'Cannot delete category with existing facilities.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                        $stmt->execute([$category_id]);
                        $success_message = 'Category deleted successfully!';
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to delete category. Please try again.';
                }
                break;
        }
    }
}

// Get all categories with facility counts
$stmt = $pdo->query("
    SELECT c.*, COUNT(f.id) as facility_count 
    FROM categories c 
    LEFT JOIN facilities f ON c.id = f.category_id 
    GROUP BY c.id 
    ORDER BY c.created_at DESC
");
$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - <?php echo SITE_NAME; ?></title>
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
        .category-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .category-card {
            pointer-events: auto !important;
        }
        .category-card * {
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
                    <a href="dashboard.php" class="flex items-center">
                        <i class="fas fa-building text-primary text-2xl mr-3"></i>
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
                    <a href="usage_management.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-clock mr-2"></i>Usage Management
                    </a>
                    <a href="users.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-users-cog mr-2"></i>Users
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
                        <i class="fas fa-tags text-primary mr-3"></i>Manage Categories
                    </h1>
                    <p class="text-gray-600">Create and manage facility categories</p>
                </div>
                <button onclick="openAddModal()" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105" style="pointer-events: auto;">
                    <i class="fas fa-plus mr-2"></i>Add New Category
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

        <!-- Categories Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($categories as $index => $category): ?>
                <div class="category-card bg-white rounded-lg shadow-md overflow-hidden animate-slide-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <div class="h-32 bg-gradient-to-br from-purple-400 to-pink-500 flex items-center justify-center relative">
                        <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                        <i class="fas fa-tag text-white text-4xl relative z-10"></i>
                        <div class="absolute top-2 right-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-building mr-1"></i>
                                <?php echo $category['facility_count']; ?> facilities
                            </span>
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($category['name']); ?></h3>
                        <p class="text-gray-600 mb-4 text-sm"><?php echo htmlspecialchars($category['description'] ?: 'No description'); ?></p>
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
                            <span><i class="fas fa-calendar mr-1"></i>Created: <?php echo date('M j, Y', strtotime($category['created_at'])); ?></span>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($category)); ?>)" 
                                    class="flex-1 bg-blue-500 hover:bg-blue-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-edit mr-2"></i>Edit
                            </button>
                            <button onclick="deleteCategory(<?php echo $category['id']; ?>)" 
                                    class="flex-1 bg-red-500 hover:bg-red-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-trash mr-2"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($categories)): ?>
            <div class="text-center py-12 animate-fade-in">
                <i class="fas fa-tags text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No categories found</h3>
                <p class="text-gray-500 mb-6">Get started by creating your first category.</p>
                <button onclick="openAddModal()" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-plus mr-2"></i>Add First Category
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Category Modal -->
    <div id="categoryModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 id="modalTitle" class="text-xl font-semibold text-gray-900">Add New Category</h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="categoryForm" method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="add_category">
                    <input type="hidden" id="category_id" name="category_id">
                    
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Category Name *</label>
                        <input type="text" id="name" name="name" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200">
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea id="description" name="description" rows="3"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200" placeholder="Optional description for this category"></textarea>
                    </div>
                    
                    <div class="flex space-x-3 pt-4">
                        <button type="submit" id="submitBtn" 
                                class="flex-1 bg-primary hover:bg-secondary text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Save Category
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

    <script>
        function openAddModal() {
            const modal = document.getElementById('categoryModal');
            document.getElementById('modalTitle').textContent = 'Add New Category';
            document.getElementById('action').value = 'add_category';
            document.getElementById('categoryForm').reset();
            document.getElementById('category_id').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Category';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function openEditModal(category) {
            const modal = document.getElementById('categoryModal');
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('action').value = 'update_category';
            document.getElementById('category_id').value = category.id;
            document.getElementById('name').value = category.name;
            document.getElementById('description').value = category.description || '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update Category';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeModal() {
            const modal = document.getElementById('categoryModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        async function deleteCategory(categoryId) {
            const confirmed = await ModalSystem.confirm(
                'Are you sure you want to delete this category? This action cannot be undone.',
                'Delete Category',
                'question'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="${categoryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal when clicking outside
        document.getElementById('categoryModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Ensure all buttons are clickable
        document.addEventListener('DOMContentLoaded', function() {
    
        });
    </script>
</body>
</html>

