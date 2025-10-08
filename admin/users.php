<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdmin();
$pdo = getDBConnection();
// Get statistics for notification badges
$stats = [];
// Total users (excluding admins and staff)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$result = $stmt->fetch();
    $stats['users'] = $result ? $result['count'] : 0;
// Total facilities
$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$result = $stmt->fetch();
    $stats['facilities'] = $result ? $result['count'] : 0;
// Total reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$result = $stmt->fetch();
    $stats['reservations'] = $result ? $result['count'] : 0;
// Pending reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
// No-show reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
// Active usage count for notifications
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usage_logs WHERE status = 'active'");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_usages'] = 0;
}
// Pending payments count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'confirmed' AND payment_status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending_payments'] = 0;
}
// New users count (last 7 days)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['new_users'] = 0;
}
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
            case 'add_staff':
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];
                $full_name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $role = 'staff'; // Force staff role
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
                                $success_message = 'Staff member added successfully!';
                            }
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to add staff member. Please try again.';
                    }
                }
                break;
            case 'update_user':
                $user_id = intval($_POST['user_id']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                try {
                    // Only update the is_active status since other fields are read-only
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$is_active, $user_id]);
                    $success_message = 'User status updated successfully!';
                } catch (Exception $e) {
                    $error_message = 'Failed to update user. Please try again.';
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

        }
    }
}
// Get all users (including admins and staff)
$stmt = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.email, u.role, u.created_at, u.updated_at
    FROM users u 
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <script src="../assets/js/modal-system.js"></script>
    </script>
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
    <script>
        // View toggle functionality
        function toggleView() {
            const gridView = document.getElementById('gridView');
            const listView = document.getElementById('listView');
            const viewToggle = document.getElementById('viewToggle');
            const listToggle = document.getElementById('listToggle');
            if (gridView.classList.contains('hidden')) {
                // Show grid view
                gridView.classList.remove('hidden');
                listView.classList.add('hidden');
                viewToggle.className = 'bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 text-white px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg';
                listToggle.className = 'bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg';
            } else {
                // Show list view
                gridView.classList.add('hidden');
                listView.classList.remove('hidden');
                viewToggle.className = 'bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg';
                listToggle.className = 'bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 text-white px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg';
            }
        }
        // Filter and search functionality
        function filterUsers() {
            const roleFilter = document.getElementById('roleFilter').value;
            const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
            const sortFilter = document.getElementById('sortFilter').value;
            const userCards = document.querySelectorAll('.user-card');
            const userRows = document.querySelectorAll('#listView tbody tr');
            // Filter grid view
            userCards.forEach(card => {
                let show = true;
                const role = card.getAttribute('data-role');
                const search = card.getAttribute('data-search');
                if (roleFilter && role !== roleFilter) show = false;
                if (searchFilter && !search.includes(searchFilter)) show = false;
                card.style.display = show ? 'block' : 'none';
            });
            // Filter list view
            userRows.forEach(row => {
                let show = true;
                const role = row.getAttribute('data-role');
                const search = row.getAttribute('data-search');
                if (roleFilter && role !== roleFilter) show = false;
                if (searchFilter && !search.includes(searchFilter)) show = false;
                row.style.display = show ? 'table-row' : 'none';
            });
            // Sort functionality
            if (sortFilter) {
                sortUsers(sortFilter);
            }
        }
        // Sort users based on selected criteria
        function sortUsers(sortBy) {
            const userCards = Array.from(document.querySelectorAll('.user-card'));
            const userRows = Array.from(document.querySelectorAll('#listView tbody tr'));
            const sortFunction = (a, b) => {
                let aValue, bValue;
                switch (sortBy) {
                    case 'full_name':
                        aValue = a.querySelector('h3').textContent.toLowerCase();
                        bValue = b.querySelector('h3').textContent.toLowerCase();
                        break;

                    case 'created_at':
                    default:
                        return 0; // Keep original order for date
                }
                if (aValue < bValue) return -1;
                if (aValue > bValue) return 1;
                return 0;
            };
            // Sort grid view
            userCards.sort(sortFunction);
            const gridContainer = document.getElementById('gridView');
            userCards.forEach(card => gridContainer.appendChild(card));
            // Sort list view
            userRows.sort(sortFunction);
            const listContainer = document.querySelector('#listView tbody');
            userRows.forEach(row => listContainer.appendChild(row));
        }
        // Open user details modal
        function openUserDetailsModal(user) {
            const modal = document.getElementById('userDetailsModal');
            document.getElementById('modalUserName').textContent = user.full_name;
            document.getElementById('modalUserRole').textContent = user.role.charAt(0).toUpperCase() + user.role.slice(1);
            document.getElementById('modalFullName').textContent = user.full_name;
            document.getElementById('modalEmail').textContent = user.email;
            document.getElementById('modalUsername').textContent = '@' + (user.username || 'N/A');
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        
        // Close user details modal
        function closeUserDetailsModal() {
            const modal = document.getElementById('userDetailsModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
    </script>
    <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
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
        /* Enhanced Prettier Navbar Design (Copied from Dashboard) */
        .nav-container {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.95) 0%, rgba(197, 176, 205, 0.95) 50%, rgba(65, 94, 114, 0.1) 100%);
            border-bottom: 3px solid rgba(65, 94, 114, 0.3);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(23, 49, 62, 0.15);
            position: sticky;
            top: 0;
            z-index: 50;
            transition: all 0.3s ease;
            min-height: 80px;
        }
        .nav-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD, #415E72, #17313E, #415E72, #C5B0CD, #F3E2D4);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        /* Enhanced Navigation Layout */
        .nav-links-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #415E72 rgba(243, 226, 212, 0.3);
            flex-wrap: wrap;
            min-height: 60px;
        }
        .nav-links-container::-webkit-scrollbar {
            height: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(90deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(90deg, #17313E, #415E72);
        }
        .nav-link {
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 16px;
            padding: 10px 16px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #17313E;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid transparent;
            backdrop-filter: blur(15px);
            font-family: "Poppins", sans-serif;
            font-size: 0.9rem;
            white-space: nowrap;
            min-width: fit-content;
            flex-shrink: 0;
        }
        .nav-link::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(197, 176, 205, 0.4), transparent);
            transition: left 0.6s ease;
        }
        .nav-link:hover::before {
            left: 100%;
        }
        .nav-link:hover {
            color: #415E72;
            background: rgba(197, 176, 205, 0.4);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .nav-link.active {
            color: white;
            background: linear-gradient(135deg, #415E72, #17313E);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.4);
            border-color: #415E72;
            transform: translateY(-2px);
        }
        .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 4px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD);
            border-radius: 2px;
            box-shadow: 0 2px 6px rgba(243, 226, 212, 0.5);
        }
        .nav-link i {
            font-size: 16px;
            transition: all 0.4s ease;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .nav-link:hover i {
            transform: scale(1.15) rotate(5deg);
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.2));
        }
        .nav-link.active i {
            transform: scale(1.1);
            filter: drop-shadow(0 3px 6px rgba(255,255,255,0.3));
        }
        .nav-link span {
            font-size: 0.85rem;
            font-weight: 500;
        }
        /* Enhanced Brand and Admin Badge */
        .nav-brand {
            font-family: "Poppins", sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
            background: linear-gradient(135deg, #415E72, #17313E, #C5B0CD);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 4px rgba(23, 49, 62, 0.1);
            transition: all 0.3s ease;
        }
        .nav-brand:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }
        .admin-badge {
            background: linear-gradient(135deg, #415E72, #17313E);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.3);
            font-family: "Poppins", sans-serif;
            border: 2px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .admin-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.4);
            border-color: rgba(243, 226, 212, 0.5);
        }
        .admin-badge i {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        /* Enhanced Mobile Navigation */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.4s ease;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            border: 2px solid rgba(197, 176, 205, 0.3);
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.4);
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: linear-gradient(90deg, #17313E, #415E72);
            margin: 3px 0;
            transition: 0.4s;
            border-radius: 2px;
            box-shadow: 0 2px 4px rgba(23, 49, 62, 0.2);
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        /* Enhanced Mobile Layout */
        @media (max-width: 1024px) {
            .nav-links-container {
            gap: 6px;
                padding: 10px 0;
            }
            .nav-link {
                padding: 8px 12px;
                font-size: 0.8rem;
                gap: 6px;
            }
            .nav-link span {
                font-size: 0.8rem;
            }
            .nav-link i {
                font-size: 14px;
            }
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                display: none;
            }
            .nav-links-container.show {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: linear-gradient(180deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.95) 100%);
                border-top: 3px solid rgba(65, 94, 114, 0.3);
                box-shadow: 0 12px 35px rgba(23, 49, 62, 0.2);
                z-index: 40;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(20px);
                padding: 20px;
                gap: 12px;
            }
            .nav-links-container .nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 16px 20px;
                border-radius: 12px;
                margin-bottom: 8px;
                font-size: 1rem;
                gap: 12px;
            }
            .nav-links-container .nav-link:hover {
                transform: translateX(8px);
                border-left: 4px solid #415E72;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #F3E2D4;
            }
        }
        /* Enhanced Navbar animations on scroll */
        .nav-container.scrolled {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.98) 100%);
            box-shadow: 0 12px 40px rgba(23, 49, 62, 0.2);
            border-bottom: 3px solid rgba(65, 94, 114, 0.4);
        }
        /* Enhanced scrollbar for mobile menu */
        .nav-links-container::-webkit-scrollbar {
            width: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #17313E, #415E72);
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        /* Enhanced Mobile Navigation Styles */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.3);
            transform: scale(1.05);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: #17313E;
            margin: 3px 0;
            transition: 0.3s;
            border-radius: 2px;
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
        }
        .nav-links-container {
            transition: all 0.3s ease;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 0 1rem;
        }
        .nav-links-container::-webkit-scrollbar {
            display: none;
        }
        .nav-links-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: max-content;
            padding: 0 0.5rem;
        }
        @media (max-width: 768px) {
            .nav-links-container {
                padding: 0;
            }
            .nav-links-wrapper {
                flex-direction: column;
                gap: 0;
                min-width: auto;
                padding: 0;
            }
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: white;
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
            }
            .nav-links-container.show {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }
            .nav-links-container .nav-link {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-radius: 0;
                border-bottom: 1px solid #f3f4f6;
                margin: 0;
                width: 100%;
            }
            .nav-links-container .nav-link:hover {
                background: rgba(59, 130, 246, 0.05);
                transform: none;
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #3B82F6, #1E40AF);
                color: white;
                border-bottom: 1px solid #3B82F6;
            }
            .nav-links-container .nav-link.active::before {
                display: none;
            }
            .nav-links-container .nav-link span {
                font-size: 16px;
                font-weight: 500;
            }
            .nav-links-container .nav-link i {
                font-size: 18px;
            }
            .nav-divider {
                height: 1px;
                background: #e5e7eb;
                margin: 8px 20px;
            }
            .brand-text {
                display: none;
            }
            .brand-text-mobile {
                display: block;
            }
        }
        @media (min-width: 769px) {
            .brand-text {
                display: block;
            }
            .brand-text-mobile {
                display: none;
            }
        }
        /* Enhanced Mobile Responsiveness */
        @media (max-width: 640px) {
            /* Mobile-first grid adjustments */
            .grid {
                grid-template-columns: 1fr !important;
            }
            /* Mobile card adjustments */
            .stat-card, .facility-card, .category-card {
                margin-bottom: 1rem;
            }
            /* Mobile table responsiveness */
            .overflow-x-auto {
                font-size: 0.875rem;
            }
            /* Mobile form adjustments */
            .space-y-4 > * {
                margin-bottom: 1rem;
            }
            /* Mobile button adjustments */
            .btn, button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            /* Mobile modal adjustments */
            .modal-content {
                margin: 1rem;
                max-height: calc(100vh - 2rem);
            }
            /* Mobile navigation improvements */
            .nav-links-container {
                padding: 0;
            }
            .nav-links-container .nav-link {
                padding: 1rem 1.25rem;
                font-size: 1rem;
            }
            /* Mobile header adjustments */
            .text-3xl, .text-4xl {
                font-size: 1.5rem !important;
            }
            .text-2xl {
                font-size: 1.25rem !important;
            }
            /* Mobile spacing adjustments */
            .p-6, .p-8 {
                padding: 1rem !important;
            }
            .mb-8, .mb-6 {
                margin-bottom: 1rem !important;
            }
            /* Mobile filter adjustments */
            .filter-section {
                flex-direction: column;
            }
            .filter-section > * {
                margin-bottom: 0.5rem;
            }
            /* Mobile table cell adjustments */
            .px-6 {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            .py-4 {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
        }
        @media (max-width: 768px) {
            /* Tablet adjustments */
            .grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
            .grid-cols-3, .grid-cols-4 {
                grid-template-columns: repeat(2, 1fr);
            }
            /* Tablet navigation */
            .nav-links-container {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .nav-links-container .nav-link {
                flex: 1 1 auto;
                min-width: 120px;
            }
        }
        @media (max-width: 1024px) {
            /* Small desktop adjustments */
            .max-w-7xl {
                max-width: 100%;
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            /* Touch device optimizations */
            .nav-link, .btn, button, a {
                min-height: 44px;
                min-width: 44px;
            }
            .nav-link {
                padding: 12px 16px;
            }
            /* Larger touch targets for mobile */
            .mobile-menu-toggle {
                min-height: 44px;
                min-width: 44px;
                padding: 12px;
            }
            /* Improved touch feedback */
            .nav-link:active, .btn:active, button:active {
                transform: scale(0.95);
            }
        }
        /* Accessibility improvements */
        .nav-link:focus, .btn:focus, button:focus {
            outline: 2px solid #3B82F6;
            outline-offset: 2px;
        }
        /* Loading states for mobile */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        /* Mobile-specific animations */
        @media (max-width: 768px) {
            .animate-slide-up {
                animation: slideUpMobile 0.3s ease-out;
            }
            @keyframes slideUpMobile {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        }
        /* Mobile Table Enhancements */
        @media (max-width: 768px) {
            /* Mobile table card layout */
            .mobile-table-card {
                display: block;
            }
            .mobile-table-card .table-row {
                display: block;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
                background: white;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            .mobile-table-card .table-row > * {
                display: block;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .mobile-table-card .table-row > *:last-child {
                border-bottom: none;
            }
            .mobile-table-card .table-header {
                display: none;
            }
            /* Mobile table labels */
            .mobile-table-card .table-row > *::before {
                content: attr(data-label) ": ";
                font-weight: 600;
                color: #6b7280;
                display: inline-block;
                min-width: 100px;
            }
            /* Hide regular table on mobile */
            .table-responsive {
                display: none;
            }
            .mobile-table-card {
                display: block;
            }
        }
        @media (min-width: 769px) {
            /* Show regular table on desktop */
            .table-responsive {
                display: block;
            }
            .mobile-table-card {
                display: none;
            }
        }
        /* Mobile Filter Enhancements */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-section > * {
                width: 100%;
            }
            .filter-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .filter-buttons > * {
                width: 100%;
            }
            /* Mobile date inputs */
            input[type="date"], input[type="datetime-local"] {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            /* Mobile select dropdowns */
            select {
                font-size: 16px;
                padding: 0.75rem;
            }
            /* Mobile search inputs */
            input[type="search"], input[type="text"] {
                font-size: 16px;
                padding: 0.75rem;
            }
        }
        /* Mobile Modal Enhancements */
        @media (max-width: 768px) {
            .modal-content {
                margin: 1rem;
                max-width: calc(100vw - 2rem);
                max-height: calc(100vh - 2rem);
                overflow-y: auto;
            }
            .modal-content .p-6 {
                padding: 1rem;
            }
            /* Mobile form in modals */
            .modal-content form {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            .modal-content form > * {
                width: 100%;
            }
            .modal-content .flex.space-x-3 {
                flex-direction: column;
                gap: 0.5rem;
            }
            .modal-content .flex.space-x-3 > * {
                width: 100%;
            }
        }
        /* Mobile Button Enhancements */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-buttons > * {
                width: 100%;
                justify-content: center;
            }
            /* Mobile card actions */
            .card-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .card-actions > * {
                width: 100%;
            }
        }
        /* Mobile Statistics Cards */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .stat-card .text-3xl {
                font-size: 1.5rem !important;
            }
            .stat-card .text-lg {
                font-size: 1rem !important;
            }
        }
        /* Mobile Navigation Final Touches */
        @media (max-width: 768px) {
            .nav-links-container .nav-link {
                border-radius: 0;
                margin: 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .nav-links-container .nav-link:last-child {
                border-bottom: none;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #3B82F6;
                background: linear-gradient(90deg, #3B82F6, #1E40AF);
            }
            /* Mobile brand text */
            .brand-text-mobile {
                font-size: 1rem;
            }
        }
        /* Enhanced Notification Badge Styles - Fixed Visibility */
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: pulse-notification 2s infinite;
            z-index: 9999;
            transition: all 0.3s ease;
            pointer-events: none;
        }
        .notification-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
        }
        .notification-badge.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .notification-badge.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        @keyframes pulse-notification {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
            50% {
                transform: scale(1.15);
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
            }
        }
        .nav-link {
            position: relative;
            overflow: visible !important;
        }
        .nav-links-container {
            position: relative;
            z-index: 1000;
        }
        .nav-link .notification-badge {
            animation: pulse-notification 2s infinite;
            z-index: 9999;
        }
        .nav-link:hover .notification-badge {
            animation: none;
            transform: scale(1.2);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
        }
        .nav-link:hover .notification-badge.warning {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.6);
        }
        .nav-link:hover .notification-badge.info {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.6);
        }
        .nav-link:hover .notification-badge.success {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.6);
        }
        /* Ensure nav-container doesn't clip badges */
        .nav-container {
            overflow: visible !important;
        }
        /* Mobile responsive notification badge */
        @media (max-width: 768px) {
            .notification-badge {
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
                top: -5px;
                right: -5px;
                border-width: 2px;
            }
        }
        /* Enhanced nav-link hover effects */
        .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
        }
        .nav-link.active {
            box-shadow: 0 4px 16px rgba(65, 94, 114, 0.3);
        }
        .notification-badge {
            /* Force visibility */
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <!-- Enhanced Navigation -->
        <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                        <i class="fas fa-users-cog text-primary mr-3"></i>Manage Users
                    </h1>
                    <p class="text-gray-600">Add, edit, and manage user accounts</p>
                </div>
                <div class="flex space-x-3">
                    <button onclick="openAddModal()" class="bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-[#17313E] hover:to-[#415E72] text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                        <i class="fas fa-user-plus mr-2"></i>Add New Admin
                    </button>
                    <button onclick="openAddStaffModal()" class="bg-gradient-to-r from-[#10B981] to-[#059669] hover:from-[#059669] hover:to-[#10B981] text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                        <i class="fas fa-user-tie mr-2"></i>Add Staff
                    </button>
                </div>
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
        <!-- View Toggle Buttons -->
        <div class="mb-6 flex flex-wrap gap-4 justify-center">
            <button id="viewToggle" onclick="toggleView()" class="bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 text-white px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                <i class="fas fa-th-large mr-2"></i>Card View
            </button>
            <button id="listToggle" onclick="toggleView()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                <i class="fas fa-list mr-2"></i>List View
            </button>
        </div>
        <!-- Enhanced Filter Section -->
        <div class="mb-8 bg-gradient-to-br from-white/90 to-gray-50/90 backdrop-blur-sm rounded-3xl shadow-2xl p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Role Filter</label>
                    <select id="roleFilter" onchange="filterUsers()" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300">
                        <option value="">All Roles</option>
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                        <option value="user">User</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Search</label>
                    <input type="text" id="searchFilter" onkeyup="filterUsers()" placeholder="Search users..." class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300">
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Sort By</label>
                    <select id="sortFilter" onchange="filterUsers()" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300">
                        <option value="created_at">Date Created</option>
                        <option value="full_name">Name</option>
                    </select>
                </div>
            </div>
        </div>
        <!-- Users Grid View (Default) -->
        <div id="gridView" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($users as $index => $user): ?>
                <div class="user-card bg-white rounded-xl shadow-lg overflow-hidden animate-slide-up hover:shadow-2xl transition-all duration-300 transform hover:scale-105" style="animation-delay: <?php echo $index * 0.1; ?>s;" data-role="<?php echo htmlspecialchars($user['role'] ?? ''); ?>" data-status="1" data-search="<?php echo htmlspecialchars(strtolower(($user['full_name'] ?? '') . ' ' . ($user['username'] ?? '') . ' ' . ($user['email'] ?? ''))); ?>">
                    <div class="h-32 bg-gradient-to-br from-[#415E72] to-[#17313E] flex items-center justify-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                        <div class="absolute inset-0 bg-gradient-to-br from-transparent via-transparent to-black opacity-30"></div>
                        <div class="relative z-10">
                            <div class="h-16 w-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-user text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="absolute top-2 right-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                $role = $user['role'] ?? 'user';
                                if ($role === 'admin') {
                                    echo 'bg-red-100 text-red-800 border border-red-200';
                                } elseif ($role === 'staff') {
                                    echo 'bg-green-100 text-green-800 border border-green-200';
                                } else {
                                    echo 'bg-blue-100 text-blue-800 border border-blue-200';
                                }
                            ?> shadow-sm">
                                <i class="<?php 
                                    $role = $user['role'] ?? 'user';
                                    if ($role === 'admin') {
                                        echo 'fas fa-shield-alt';
                                    } elseif ($role === 'staff') {
                                        echo 'fas fa-user-tie';
                                    } else {
                                        echo 'fas fa-user';
                                    }
                                ?> mr-1"></i>
                                <?php echo ucfirst($user['role'] ?? 'user'); ?>
                            </span>
                        </div>
                        <?php if (($user['id'] ?? 0) == $_SESSION['user_id']): ?>
                            <div class="absolute top-2 left-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200 shadow-sm">
                                    <i class="fas fa-user-check mr-1"></i>You
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="absolute bottom-2 left-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200 shadow-sm">
                                <i class="fas fa-circle text-xs mr-1"></i>
                                Active
                            </span>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                            <span class="text-sm text-gray-500 font-mono">@<?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <p class="text-gray-600 mb-4 text-sm truncate"><?php echo htmlspecialchars($user['email']); ?></p>

                        <!-- Action Buttons -->
                        <div class="flex flex-col space-y-2">
                            <button onclick="openUserDetailsModal(<?php echo htmlspecialchars(json_encode([
                                'full_name' => $user['full_name'] ?? 'N/A',
                                'role' => $user['role'] ?? 'user',
                                'email' => $user['email'] ?? 'N/A',
                                'username' => $user['username'] ?? 'N/A'
                            ])); ?>)" 
                                    class="w-full bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-[#17313E] hover:to-[#415E72] text-white text-center py-3 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-md">
                                <i class="fas fa-eye mr-2"></i>View Details
                            </button>
                            <div class="flex space-x-2">
                                <?php if (($user['id'] ?? 0) != $_SESSION['user_id']): ?>
                                    <button onclick="deleteUser(<?php echo $user['id'] ?? 0; ?>)" 
                                            class="flex-1 bg-gradient-to-r from-[#C5B0CD] to-[#415E72] hover:from-[#415E72] hover:to-[#C5B0CD] text-white text-center py-2 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-md">
                                        <i class="fas fa-trash mr-1"></i>Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Users List View (Hidden by default) -->
        <div id="listView" class="hidden">
            <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-user mr-2"></i>User
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-envelope mr-2"></i>Contact
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-shield-alt mr-2"></i>Role
                                </th>

                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-circle mr-2"></i>Status
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-cog mr-2"></i>Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 transition-all duration-300" data-role="<?php echo htmlspecialchars($user['role'] ?? ''); ?>" data-status="1" data-search="<?php echo htmlspecialchars(strtolower(($user['full_name'] ?? '') . ' ' . ($user['username'] ?? '') . ' ' . ($user['email'] ?? ''))); ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-[#415E72] to-[#17313E] rounded-full flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A'; ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                            $role = $user['role'] ?? 'user';
                                            if ($role === 'admin') {
                                                echo 'bg-red-100 text-red-800 border border-red-200';
                                            } elseif ($role === 'staff') {
                                                echo 'bg-green-100 text-green-800 border border-green-200';
                                            } else {
                                                echo 'bg-blue-100 text-blue-800 border border-blue-200';
                                            }
                                        ?>">
                                            <i class="<?php 
                                                $role = $user['role'] ?? 'user';
                                                if ($role === 'admin') {
                                                    echo 'fas fa-shield-alt';
                                                } elseif ($role === 'staff') {
                                                    echo 'fas fa-user-tie';
                                                } else {
                                                    echo 'fas fa-user';
                                                }
                                            ?> mr-1"></i>
                                            <?php echo ucfirst($user['role'] ?? 'user'); ?>
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                            <i class="fas fa-circle text-xs mr-1"></i>
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openUserDetailsModal(<?php echo htmlspecialchars(json_encode([
                                                'full_name' => $user['full_name'] ?? 'N/A',
                                                'role' => $user['role'] ?? 'user',
                                                'email' => $user['email'] ?? 'N/A',
                                                'username' => $user['username'] ?? 'N/A'
                                            ])); ?>)" 
                                                    class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg transition-all duration-300 text-xs">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </button>

                                            <?php if (($user['id'] ?? 0) != $_SESSION['user_id']): ?>
                                                <button onclick="deleteUser(<?php echo $user['id'] ?? 0; ?>)" 
                                                        class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition-all duration-300 text-xs">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Empty State -->
        <?php if (empty($users)): ?>
            <div class="text-center py-12 animate-fade-in">
                <i class="fas fa-users text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No users found</h3>
                <p class="text-gray-500 mb-6">Get started by adding your first admin user.</p>
                <button onclick="openAddModal()" class="bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-[#17313E] hover:to-[#415E72] text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-user-plus mr-2"></i>Add First Admin
                </button>
            </div>
        <?php endif; ?>
    </div>
    <!-- User Details Modal -->
    <div id="userDetailsModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold text-gray-900">User Details</h2>
                    <button onclick="closeUserDetailsModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-[#415E72] to-[#17313E] rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 id="modalUserName" class="text-lg font-semibold text-gray-900"></h3>
                            <p id="modalUserRole" class="text-sm text-gray-500"></p>
                        </div>
                    </div>
                    <div class="border-t pt-4">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                                <p id="modalFullName" class="text-sm text-gray-900 mt-1"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Email</label>
                                <p id="modalEmail" class="text-sm text-gray-900 mt-1"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Username</label>
                                <p id="modalUsername" class="text-sm text-gray-900 mt-1"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end pt-4">
                    <button onclick="closeUserDetailsModal()" 
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-semibold transition duration-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
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
                                class="flex-1 bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-[#17313E] hover:to-[#415E72] text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
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
                                class="flex-1 bg-gradient-to-r from-[#C5B0CD] to-[#415E72] hover:from-[#415E72] hover:to-[#C5B0CD] text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-trash mr-2"></i>Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        // Global function definitions
        window.openAddModal = function() {
            const modal = document.getElementById('userModal');
            document.getElementById('modalTitle').textContent = 'Add New Admin';
            document.getElementById('action').value = 'add_admin';
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            // Reset fields to editable state
            const usernameField = document.getElementById('username');
            const fullNameField = document.getElementById('full_name');
            const emailField = document.getElementById('email');
            usernameField.readOnly = false;
            fullNameField.readOnly = false;
            emailField.readOnly = false;
            // Remove disabled styling
            usernameField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            fullNameField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            emailField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            document.getElementById('passwordFields').style.display = 'block';
            document.getElementById('activeField').style.display = 'none';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Admin';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        };
        
        window.openAddStaffModal = function() {
            const modal = document.getElementById('userModal');
            document.getElementById('modalTitle').textContent = 'Add New Staff';
            document.getElementById('action').value = 'add_staff';
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            // Reset fields to editable state
            const usernameField = document.getElementById('username');
            const fullNameField = document.getElementById('full_name');
            const emailField = document.getElementById('email');
            usernameField.readOnly = false;
            fullNameField.readOnly = false;
            emailField.readOnly = false;
            // Remove disabled styling
            usernameField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            fullNameField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            emailField.classList.remove('bg-gray-100', 'cursor-not-allowed');
            document.getElementById('passwordFields').style.display = 'block';
            document.getElementById('activeField').style.display = 'none';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Staff';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        };
        window.openEditModal = function(user) {
            const modal = document.getElementById('userModal');
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('action').value = 'update_user';
            document.getElementById('user_id').value = user.id;
            // Set values and make fields read-only
            const usernameField = document.getElementById('username');
            const fullNameField = document.getElementById('full_name');
            const emailField = document.getElementById('email');
            usernameField.value = user.username;
            fullNameField.value = user.full_name;
            emailField.value = user.email;
            // Make fields read-only and add visual styling
            usernameField.readOnly = true;
            fullNameField.readOnly = true;
            emailField.readOnly = true;
            // Add disabled styling
            usernameField.classList.add('bg-gray-100', 'cursor-not-allowed');
            fullNameField.classList.add('bg-gray-100', 'cursor-not-allowed');
            emailField.classList.add('bg-gray-100', 'cursor-not-allowed');
            document.getElementById('is_active').checked = user.is_active == 1;
            document.getElementById('passwordFields').style.display = 'none';
            document.getElementById('activeField').style.display = 'flex';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update User';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        };

        window.deleteUser = function(userId) {
            const modal = document.getElementById('deleteConfirmModal');
            document.getElementById('delete_user_id').value = userId;
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        };
        function closeModal() {
            const modal = document.getElementById('userModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
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

        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirmModal();
            }
        });
        document.getElementById('userDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeUserDetailsModal();
            }
        });
        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteConfirmModal();
                closeUserDetailsModal();
            }
        });
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            mobileToggle.classList.toggle("active");
            navLinksContainer.classList.toggle("show");
        }
        // Combined mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Mobile menu functionality
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener("click", function(e) {
                    // Don't prevent default for logout link
                    if (this.getAttribute("href") === "../auth/logout.php") {
                        return;
                    }
                    // Close mobile menu if open
                    const mobileToggle = document.querySelector(".mobile-menu-toggle");
                    const navContainer = document.querySelector(".nav-links-container");
                    if (mobileToggle && navContainer) {
                        mobileToggle.classList.remove("active");
                        navContainer.classList.remove("show");
                    }
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener("click", function(e) {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navContainer = document.querySelector(".nav-links-container");
                if (mobileToggle && navContainer && !mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                }
            });
            // Highlight current page
            const currentPage = window.location.pathname.split("/").pop();
            mobileNavLinks.forEach(link => {
                const href = link.getAttribute("href");
                if (href && href.includes(currentPage) && currentPage !== "") {
                    link.classList.add("active");
                }
            });
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
            });
        });
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtonGroups = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtonGroups.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
        // Enhanced navbar scroll effects
        window.addEventListener("scroll", function() {
            const navbar = document.querySelector(".nav-container");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });
    </script>
</body>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</html>
