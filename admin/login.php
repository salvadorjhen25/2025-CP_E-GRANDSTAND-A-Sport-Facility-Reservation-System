<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
// Redirect if already logged in as admin or staff
if ($auth->isLoggedIn() && $auth->isAdminOrStaff()) {
    header('Location: dashboard.php');
    exit();
}
// Redirect if already logged in as regular user
if ($auth->isLoggedIn() && $auth->isRegularUser()) {
    header('Location: ../index.php');
    exit();
}
// Handle admin login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $login_error = 'Please enter both username and password.';
    } else {
        $result = $auth->adminLogin($username, $password);
        if ($result['success']) {
            header('Location: dashboard.php');
            exit();
        } else {
            $login_error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        .admin-login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #1e40af 100%);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .admin-badge {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
        }
        @media (max-width: 640px) {
            .login-card {
                margin: 1rem;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body class="admin-login-container">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-red-600 mx-auto mb-4"></div>
            <p class="text-gray-600">Authenticating...</p>
        </div>
    </div>
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo and Title -->
            <div class="text-center animate-fade-in">
                <a href="../index.php" class="inline-block">
                    <div class="mx-auto h-20 w-20 admin-badge rounded-full flex items-center justify-center mb-4 shadow-lg">
                        <i class="fas fa-shield-alt text-white text-3xl"></i>
                    </div>
                </a>
                <h2 class="text-3xl font-bold text-white mb-2">Admin Access</h2>
                <p class="text-blue-100 text-lg">Secure administrative login</p>
                <div class="mt-4 inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Restricted Area
                </div>
            </div>
            <!-- Login Form -->
            <div class="glass-effect rounded-2xl shadow-2xl p-8 login-card animate-slide-up" style="animation-delay: 0.2s;">
                <?php if (isset($login_error)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php echo htmlspecialchars($login_error); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <form class="space-y-6" method="POST" action="login.php" id="adminLoginForm">
                    <input type="hidden" name="action" value="admin_login">
                    <!-- Username/Email Field -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user-shield text-gray-400"></i>
                        </div>
                        <input id="username" name="username" type="text" required 
                               class="form-input appearance-none block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 transition duration-200"
                               placeholder="Enter admin username">
                    </div>
                    <!-- Password Field -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" required 
                               class="form-input appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 transition duration-200"
                               placeholder="Enter admin password">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <i class="fas fa-eye text-gray-400 hover:text-gray-600 transition duration-200"></i>
                        </button>
                    </div>
                    <!-- Security Notice -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <i class="fas fa-shield-alt text-yellow-600 mr-2"></i>
                            <div class="text-sm text-yellow-800">
                                <p class="font-medium">Security Notice</p>
                                <p>This area is restricted to authorized administrators only.</p>
                            </div>
                        </div>
                    </div>
                    <!-- Submit Button -->
                    <div>
                        <button type="submit" id="submitBtn"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200 transform hover:scale-105">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="fas fa-sign-in-alt text-white group-hover:text-red-100 transition duration-200"></i>
                            </span>
                            Access Admin Panel
                        </button>
                    </div>
                </form>
            </div>
            <!-- Footer -->
            <div class="text-center animate-fade-in" style="animation-delay: 0.4s;">
                <p class="text-blue-200 text-sm">
                    <a href="../index.php" class="text-white hover:text-blue-100 underline">
                        <i class="fas fa-arrow-left mr-1"></i>Back to Main Site
                    </a>
                </p>
                <p class="text-blue-200 text-xs mt-2">
                    &copy; 2024 <?php echo SITE_NAME; ?>. Admin access only.
                </p>
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
        document.addEventListener('DOMContentLoaded', function() {
            const adminLoginForm = document.getElementById('adminLoginForm');
            const submitBtn = document.getElementById('submitBtn');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const usernameInput = document.getElementById('username');
            // Password visibility toggle
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
            // Form validation and submission
            adminLoginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                // Basic validation
                const username = usernameInput.value.trim();
                const password = passwordInput.value;
                if (!username || !password) {
                    showError('Please fill in all fields');
                    return;
                }
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Authenticating...';
                // Show loading overlay
                const loadingOverlay = document.getElementById('loading-overlay');
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.opacity = '1';
                // Submit form
                this.submit();
            });
            // Real-time validation
            usernameInput.addEventListener('input', function() {
                validateField(this, 'Please enter admin username');
            });
            passwordInput.addEventListener('input', function() {
                validateField(this, 'Password is required');
            });
            function validateField(field, message) {
                const value = field.value.trim();
                const isValid = value.length > 0;
                if (isValid) {
                    field.classList.remove('border-red-300');
                    field.classList.add('border-green-300');
                    hideError();
                } else {
                    field.classList.remove('border-green-300');
                    field.classList.add('border-red-300');
                }
            }
            function showError(message) {
                // Remove existing error
                hideError();
                // Create error element
                const errorDiv = document.createElement('div');
                errorDiv.className = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake';
                errorDiv.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        ${message}
                    </div>
                `;
                // Insert before form
                adminLoginForm.parentNode.insertBefore(errorDiv, adminLoginForm);
            }
            function hideError() {
                const existingError = document.querySelector('.bg-red-100');
                if (existingError) {
                    existingError.remove();
                }
            }
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    adminLoginForm.dispatchEvent(new Event('submit'));
                }
            });
            // Auto-focus username field
            usernameInput.focus();
        });
    </script>
</body>
</html>
