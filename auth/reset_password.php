<?php
require_once __DIR__ . '/../config/database.php';
require_once 'auth.php';

$auth = new Auth();
$pdo = getDBConnection();

if (!$pdo) {
    die("Database connection failed");
}

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
$token = $_GET['token'] ?? '';
$user = null;
$token_valid = false;

// Validate token
if ($token) {
    try {
        // Check if token exists
        $token_check_stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = ?");
        $token_check_stmt->execute([$token]);
        $token_data = $token_check_stmt->fetch();
        
        if ($token_data) {
            // Token exists, check if it's valid using MySQL NOW() for accurate timezone comparison
            $stmt = $pdo->prepare("
                SELECT prt.*, u.id as user_id, u.full_name, u.email, u.role 
                FROM password_reset_tokens prt 
                JOIN users u ON prt.user_id = u.id 
                WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL
            ");
            $stmt->execute([$token]);
            $token_data = $stmt->fetch();
            
            if ($token_data) {
                $user = $token_data;
                $token_valid = true;
            } else {
                // Token exists but is invalid - check why using MySQL NOW() for comparison
                $stmt = $pdo->prepare("SELECT NOW() as mysql_now");
                $stmt->execute();
                $mysql_now = $stmt->fetch()['mysql_now'];
                
                if ($token_data['expires_at'] <= $mysql_now) {
                    $error = 'Reset token has expired. Please request a new password reset.';
                } elseif ($token_data['used_at'] !== null) {
                    $error = 'Reset token has already been used. Please request a new password reset.';
                } else {
                    $error = 'Invalid reset token. Please request a new password reset.';
                }
            }
        } else {
            $error = 'Reset token not found. Please request a new password reset.';
        }
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        $error = 'An error occurred while validating the reset token.';
    }
} else {
    $error = 'No reset token provided. Please use the link from your email.';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password' && $token_valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update user password
            $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user['user_id']]);
            
            // Mark token as used
            $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?");
            $stmt->execute([$token]);
            
            // Delete all tokens for this user
            $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            
            $message = 'Your password has been successfully reset. You can now sign in with your new password.';
            $token_valid = false; // Hide the form
            
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = 'An error occurred while resetting your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
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
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { background-color: #ef4444; width: 25%; }
        .strength-fair { background-color: #f59e0b; width: 50%; }
        .strength-good { background-color: #3b82f6; width: 75%; }
        .strength-strong { background-color: #10b981; width: 100%; }
    </style>
</head>
<body class="login-container">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Resetting your password...</p>
        </div>
    </div>
    
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo and Title -->
            <div class="text-center animate-fade-in">
                <a href="../index.php" class="inline-block">
                    <div class="mx-auto h-16 w-16 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center mb-4 shadow-lg">
                        <i class="fas fa-lock text-white text-2xl"></i>
                    </div>
                </a>
                <h2 class="text-3xl font-bold text-white mb-2">Reset Password</h2>
                <p class="text-blue-100 text-lg">Enter your new password</p>
                <?php if ($user): ?>
                    <p class="mt-2 text-sm text-blue-200">
                        Resetting password for: <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Reset Password Form -->
            <div class="glass-effect rounded-2xl shadow-2xl p-8 login-card animate-slide-up" style="animation-delay: 0.2s;">
                <?php if ($message): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in success-pulse">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                        <div class="mt-3">
                            <a href="login.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition duration-200">
                                <i class="fas fa-sign-in-alt mr-2"></i>
                                Go to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in error-shake">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        <?php if (strpos($error, 'Invalid or expired') !== false): ?>
                            <div class="mt-3">
                                <a href="forgot_password.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-200">
                                    <i class="fas fa-redo mr-2"></i>
                                    Request New Reset Link
                                </a>
                            </div>
                        <?php endif; ?>
                        
                    </div>
                <?php endif; ?>
                
                <?php if ($token_valid && !$message): ?>
                    <form class="space-y-6" method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" id="resetPasswordForm">
                        <input type="hidden" name="action" value="reset_password">
                        
                        <!-- New Password Field -->
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input id="new_password" name="new_password" type="password" required 
                                   class="form-input appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                                   style="color: #374151 !important; background-color: #ffffff !important;"
                                   placeholder="Enter new password">
                            <button type="button" id="toggleNewPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600 transition duration-200"></i>
                            </button>
                        </div>
                        
                        <!-- Password Strength Indicator -->
                        <div class="password-strength-container">
                            <div class="flex justify-between text-xs text-gray-500 mb-1">
                                <span>Password Strength:</span>
                                <span id="strength-text">Enter password</span>
                            </div>
                            <div class="bg-gray-200 rounded-full h-1">
                                <div id="strength-bar" class="password-strength bg-gray-300"></div>
                            </div>
                        </div>
                        
                        <!-- Confirm Password Field -->
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input id="confirm_password" name="confirm_password" type="password" required 
                                   class="form-input appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                                   style="color: #374151 !important; background-color: #ffffff !important;"
                                   placeholder="Confirm new password">
                            <button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600 transition duration-200"></i>
                            </button>
                        </div>
                        
                        <!-- Password Requirements -->
                        <div class="text-xs text-gray-600 space-y-1">
                            <p class="font-medium">Password Requirements:</p>
                            <ul class="space-y-1">
                                <li id="req-length" class="flex items-center">
                                    <i class="fas fa-times text-red-500 mr-2"></i>
                                    At least 6 characters
                                </li>
                                <li id="req-match" class="flex items-center">
                                    <i class="fas fa-times text-red-500 mr-2"></i>
                                    Passwords match
                                </li>
                            </ul>
                        </div>
                        
                        <!-- Submit Button -->
                        <div>
                            <button type="submit" id="submitBtn"
                                    class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-200 transform hover:scale-105">
                                <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                    <i class="fas fa-save text-white group-hover:text-blue-100 transition duration-200"></i>
                                </span>
                                Reset Password
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
                
                <!-- Additional Help -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="text-center">
                        <p class="text-sm text-gray-600 mb-3">Security Tips:</p>
                        <div class="space-y-2">
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-shield-alt mr-1"></i>
                                Use a strong, unique password
                            </p>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-user-secret mr-1"></i>
                                Don't share your password with anyone
                            </p>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-sync-alt mr-1"></i>
                                Consider changing passwords regularly
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="text-center animate-fade-in" style="animation-delay: 0.4s;">
                <p class="text-blue-200 text-sm">
                    <a href="login.php" class="text-white hover:text-blue-100 underline">Back to Login</a>
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
            const resetPasswordForm = document.getElementById('resetPasswordForm');
            const submitBtn = document.getElementById('submitBtn');
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const toggleNewPassword = document.getElementById('toggleNewPassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            
            // Password visibility toggles
            if (toggleNewPassword) {
                toggleNewPassword.addEventListener('click', function() {
                    const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    newPasswordInput.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                });
            }
            
            if (toggleConfirmPassword) {
                toggleConfirmPassword.addEventListener('click', function() {
                    const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    confirmPasswordInput.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                });
            }
            
            // Password strength checker
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    checkPasswordStrength(this.value);
                    checkPasswordMatch();
                });
            }
            
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', function() {
                    checkPasswordMatch();
                });
            }
            
            function checkPasswordStrength(password) {
                const strengthBar = document.getElementById('strength-bar');
                const strengthText = document.getElementById('strength-text');
                const reqLength = document.getElementById('req-length');
                
                let strength = 0;
                let strengthLabel = 'Weak';
                let strengthClass = 'strength-weak';
                
                // Length check
                if (password.length >= 6) {
                    strength += 1;
                    reqLength.querySelector('i').className = 'fas fa-check text-green-500 mr-2';
                } else {
                    reqLength.querySelector('i').className = 'fas fa-times text-red-500 mr-2';
                }
                
                // Additional strength checks
                if (password.length >= 8) strength += 1;
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^A-Za-z0-9]/.test(password)) strength += 1;
                
                if (strength >= 4) {
                    strengthLabel = 'Strong';
                    strengthClass = 'strength-strong';
                } else if (strength >= 3) {
                    strengthLabel = 'Good';
                    strengthClass = 'strength-good';
                } else if (strength >= 2) {
                    strengthLabel = 'Fair';
                    strengthClass = 'strength-fair';
                } else if (strength >= 1) {
                    strengthLabel = 'Weak';
                    strengthClass = 'strength-weak';
                }
                
                if (password.length === 0) {
                    strengthLabel = 'Enter password';
                    strengthClass = 'strength-weak';
                }
                
                strengthBar.className = `password-strength ${strengthClass}`;
                strengthText.textContent = strengthLabel;
            }
            
            function checkPasswordMatch() {
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const reqMatch = document.getElementById('req-match');
                
                if (confirmPassword.length > 0) {
                    if (newPassword === confirmPassword) {
                        reqMatch.querySelector('i').className = 'fas fa-check text-green-500 mr-2';
                        confirmPasswordInput.classList.remove('border-red-300');
                        confirmPasswordInput.classList.add('border-green-300');
                    } else {
                        reqMatch.querySelector('i').className = 'fas fa-times text-red-500 mr-2';
                        confirmPasswordInput.classList.remove('border-green-300');
                        confirmPasswordInput.classList.add('border-red-300');
                    }
                } else {
                    reqMatch.querySelector('i').className = 'fas fa-times text-red-500 mr-2';
                    confirmPasswordInput.classList.remove('border-red-300', 'border-green-300');
                }
            }
            
            // Form validation and submission
            if (resetPasswordForm) {
                resetPasswordForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (!newPassword || !confirmPassword) {
                        showError('Please fill in all fields');
                        return;
                    }
                    
                    if (newPassword.length < 6) {
                        showError('Password must be at least 6 characters long');
                        return;
                    }
                    
                    if (newPassword !== confirmPassword) {
                        showError('Passwords do not match');
                        return;
                    }
                    
                    // Show loading state
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Resetting...';
                    
                    // Show loading overlay
                    const loadingOverlay = document.getElementById('loading-overlay');
                    loadingOverlay.style.display = 'flex';
                    loadingOverlay.style.opacity = '1';
                    
                    // Submit form
                    this.submit();
                });
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
                resetPasswordForm.parentNode.insertBefore(errorDiv, resetPasswordForm);
            }
            
            function hideError() {
                const existingError = document.querySelector('.bg-red-100');
                if (existingError) {
                    existingError.remove();
                }
            }
            
            // Auto-focus new password field
            if (newPasswordInput) {
                newPasswordInput.focus();
            }
        });
    </script>
</body>
</html>
