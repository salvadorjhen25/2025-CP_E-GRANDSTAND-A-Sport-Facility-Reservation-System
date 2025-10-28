<?php
require_once __DIR__ . '/../config/database.php';
require_once 'auth.php';
require_once __DIR__ . '/../classes/EmailMailer.php';

$auth = new Auth();
$emailMailer = new EmailMailer();

// Set timezone to match MySQL
date_default_timezone_set('Asia/Manila'); // Adjust to your timezone

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    if ($auth->isAdminOrStaff()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../index.php');
    }
    exit();
}

$message = '';
$error = '';

// Handle forgot password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Check if user exists and is not admin/staff
            $stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'No account found with that email address.';
            } elseif (in_array($user['role'], ['admin', 'staff'])) {
                $error = 'Admin and staff users must contact the system administrator for password reset.';
            } else {
                // Generate reset token
                $reset_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Delete any existing tokens for this user
                $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                
                // Insert new token
                $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $reset_token, $expires_at]);
                
                // Generate reset URL
                $reset_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                            '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . 
                            '/reset_password.php?token=' . $reset_token;
                
                // Send email
                if ($emailMailer->sendPasswordResetEmail($user['email'], $user['full_name'], $reset_token, $reset_url)) {
                    $message = 'Password reset instructions have been sent to your email address. Please check your inbox and follow the instructions to reset your password.';
                } else {
                    $error = 'Failed to send password reset email. Please try again later.';
                }
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/enhanced-ui.css">
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
        input[type="password"], input[type="text"], input[type="email"] {
            color: #374151 !important;
            background-color: #ffffff !important;
        }
        
        /* Ensure text is visible when typing */
        input[type="password"]:focus, input[type="text"]:focus, input[type="email"]:focus {
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
            <p class="text-gray-600">Processing your request...</p>
        </div>
    </div>
    
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo and Title -->
            <div class="text-center animate-fade-in">
                <a href="../index.php" class="inline-block">
                    <div class="mx-auto h-16 w-16 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center mb-4 shadow-lg">
                        <i class="fas fa-key text-white text-2xl"></i>
                    </div>
                </a>
                <h2 class="text-3xl font-bold text-white mb-2">Forgot Password?</h2>
                <p class="text-blue-100 text-lg">No worries, we'll help you reset it</p>
                <p class="mt-2 text-sm text-blue-200">
                    Remember your password?
                    <a href="login.php" class="font-medium text-white hover:text-blue-100 transition duration-200 underline">
                        Sign in here
                    </a>
                </p>
            </div>
            
            <!-- Forgot Password Form -->
            <div class="glass-effect rounded-2xl shadow-2xl p-8 login-card animate-slide-up" style="animation-delay: 0.2s;">
                <?php if ($message): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in success-pulse">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form class="space-y-6" method="POST" action="forgot_password.php" id="forgotPasswordForm">
                    <input type="hidden" name="action" value="forgot_password">
                    
                    <!-- Email Field -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input id="email" name="email" type="email" required 
                               class="form-input appearance-none block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                               style="color: #374151 !important; background-color: #ffffff !important;"
                               placeholder="Enter your email address"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <!-- Submit Button -->
                    <div>
                        <button type="submit" id="submitBtn"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-200 transform hover:scale-105">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="fas fa-paper-plane text-white group-hover:text-blue-100 transition duration-200"></i>
                            </span>
                            Send Reset Instructions
                        </button>
                    </div>
                </form>
                
                <!-- Additional Help -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="text-center">
                        <p class="text-sm text-gray-600 mb-3">Need more help?</p>
                        <div class="space-y-2">
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Check your spam folder if you don't receive the email
                            </p>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-clock mr-1"></i>
                                Reset links expire after 1 hour for security
                            </p>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-shield-alt mr-1"></i>
                                Admin and staff accounts must contact support
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="text-center animate-fade-in" style="animation-delay: 0.4s;">
                <p class="text-blue-200 text-sm">
                    By using this service, you agree to our 
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
            const forgotPasswordForm = document.getElementById('forgotPasswordForm');
            const submitBtn = document.getElementById('submitBtn');
            const emailInput = document.getElementById('email');
            
            // Form validation and submission
            forgotPasswordForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Basic validation
                const email = emailInput.value.trim();
                if (!email) {
                    showError('Please enter your email address');
                    return;
                }
                
                if (!isValidEmail(email)) {
                    showError('Please enter a valid email address');
                    return;
                }
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';
                
                // Show loading overlay
                const loadingOverlay = document.getElementById('loading-overlay');
                loadingOverlay.style.display = 'flex';
                loadingOverlay.style.opacity = '1';
                
                // Submit form
                this.submit();
            });
            
            // Real-time validation
            emailInput.addEventListener('input', function() {
                validateEmailField(this);
            });
            
            function validateEmailField(field) {
                const value = field.value.trim();
                const isValid = value.length > 0 && isValidEmail(value);
                
                if (isValid) {
                    field.classList.remove('border-red-300');
                    field.classList.add('border-green-300');
                    hideError();
                } else if (value.length > 0) {
                    field.classList.remove('border-green-300');
                    field.classList.add('border-red-300');
                } else {
                    field.classList.remove('border-red-300', 'border-green-300');
                }
            }
            
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
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
                forgotPasswordForm.parentNode.insertBefore(errorDiv, forgotPasswordForm);
            }
            
            function hideError() {
                const existingError = document.querySelector('.bg-red-100');
                if (existingError) {
                    existingError.remove();
                }
            }
            
            // Auto-focus email field
            emailInput.focus();
        });
    </script>
</body>
</html>
