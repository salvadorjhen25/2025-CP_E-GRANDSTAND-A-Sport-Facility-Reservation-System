<?php
require_once __DIR__ . '/../config/database.php';
require_once 'auth.php';
$auth = new Auth();
// Redirect if already logged in as regular user
if ($auth->isLoggedIn() && $auth->isRegularUser()) {
    header('Location: ../index.php');
    exit();
}
// Redirect if already logged in as admin or staff
if ($auth->isLoggedIn() && $auth->isAdminOrStaff()) {
    header('Location: ../admin/dashboard.php');
    exit();
}
// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $login_error = 'Please enter both username and password.';
    } else {
        // First check if the user exists and is an admin
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT role FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && in_array($user['role'], ['admin', 'staff'])) {
                $login_error = 'Admin and staff users must login through the admin portal. Please use the admin login page.';
            } else {
                $result = $auth->userLogin($username, $password);
                if ($result['success']) {
                    header('Location: ../index.php');
                    exit();
                } else {
                    $login_error = $result['message'];
                }
            }
        } catch (Exception $e) {
            $login_error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login - <?php echo SITE_NAME; ?></title>
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
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .floating-label {
            transition: all 0.3s ease;
        }
        .form-input:focus + .floating-label,
        .form-input:not(:placeholder-shown) + .floating-label {
            transform: translateY(-1.5rem) scale(0.85);
            color: #3B82F6;
        }
        @media (max-width: 640px) {
            .login-card {
                margin: 1rem;
                padding: 1.5rem;
            }
        }
        
        /* Fix for password field text visibility */
        input[type="password"], input[type="text"] {
            color: #374151 !important;
            background-color: #ffffff !important;
        }
        
        /* Ensure text is visible when typing */
        input[type="password"]:focus, input[type="text"]:focus {
            color: #374151 !important;
            background-color: #ffffff !important;
        }
        
        /* Fix for all form inputs */
        .form-input, .appearance-none {
            color: #374151 !important;
            background-color: #ffffff !important;
        }
    </style>
</head>
<body class="login-container">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Signing you in...</p>
        </div>
    </div>
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo and Title -->
            <div class="text-center animate-fade-in">
                <a href="../index.php" class="inline-block">
                    <div class="mx-auto h-16 w-16 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center mb-4 shadow-lg">
                        <i class="fas fa-building text-white text-2xl"></i>
                    </div>
                </a>
                <h2 class="text-3xl font-bold text-white mb-2">Welcome Back</h2>
                <p class="text-blue-100 text-lg">Sign in to your account</p>
                <p class="mt-2 text-sm text-blue-200">
                    Don't have an account?
                    <a href="register.php" class="font-medium text-white hover:text-blue-100 transition duration-200 underline">
                        Create one here
                    </a>
                </p>
            </div>
            <!-- Login Form -->
            <div class="glass-effect rounded-2xl shadow-2xl p-8 login-card animate-slide-up" style="animation-delay: 0.2s;">
                <?php if (isset($login_error)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo htmlspecialchars($login_error); ?>
                        </div>
                        <?php if (strpos($login_error, 'Admin users must login') !== false): ?>
                            <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                                    <div class="text-sm text-blue-700">
                                        <p class="font-medium">Admin Access</p>
                                        <p>Administrators should use the <a href="../admin/login.php" class="font-semibold underline">Admin Login Portal</a> instead.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form class="space-y-6" method="POST" action="login.php" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    <!-- Username/Email Field -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input id="username" name="username" type="text" required 
                               class="form-input appearance-none block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                               style="color: #374151 !important; background-color: #ffffff !important;"
                               placeholder="Enter your username or email">
                    </div>
                    <!-- Password Field -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input id="password" name="password" type="password" required 
                               class="form-input appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                               style="color: #374151 !important; background-color: #ffffff !important;"
                               placeholder="Enter your password">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <i class="fas fa-eye text-gray-400 hover:text-gray-600 transition duration-200"></i>
                        </button>
                    </div>
                    <!-- Remember Me and Forgot Password -->
                    <div class="flex items-center justify-between">
                        <div class="text-sm">
                            <a href="forgot_password.php" class="font-medium text-primary hover:text-secondary transition duration-200">
                                Forgot password?
                            </a>
                        </div>
                    </div>
                    <!-- Submit Button -->
                    <div>
                        <button type="submit" id="submitBtn"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-200 transform hover:scale-105">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="fas fa-sign-in-alt text-white group-hover:text-blue-100 transition duration-200"></i>
                            </span>
                            Sign in
                        </button>
                    </div>
                </form>
            </div>
            <!-- Footer -->
            <div class="text-center animate-fade-in" style="animation-delay: 0.4s;">
                <p class="text-blue-200 text-sm">
                    By signing in, you agree to our 
                    <a href="#" class="text-white hover:text-blue-100 underline">Terms of Service</a> 
                    and 
                    <a href="#" class="text-white hover:text-blue-100 underline">Privacy Policy</a>
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
            const loginForm = document.getElementById('loginForm');
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
            loginForm.addEventListener('submit', function(e) {
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
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Signing in...';
                // Show loading overlay
                const loadingOverlay = document.getElementById('loading-overlay');
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.opacity = '1';
                // Submit form
                this.submit();
            });
            // Real-time validation
            usernameInput.addEventListener('input', function() {
                validateField(this, 'Please enter a valid username or email');
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
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        ${message}
                    </div>
                `;
                // Insert before form
                loginForm.parentNode.insertBefore(errorDiv, loginForm);
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
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });
            // Auto-focus username field
            usernameInput.focus();
            // Add floating label effect
            [usernameInput, passwordInput].forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement.classList.remove('focused');
                    }
                });
            });
        });
    </script>
</body>
</html>
