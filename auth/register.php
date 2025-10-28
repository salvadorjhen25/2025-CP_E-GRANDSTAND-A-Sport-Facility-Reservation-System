<?php
require_once __DIR__ . '/../config/database.php';
require_once 'auth.php';
$auth = new Auth();
// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}
// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    // Basic validation
    if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        $register_error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm_password) {
        $register_error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $register_error = 'Password must be at least 6 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = 'Please enter a valid email address.';
    } else {
        $result = $auth->register($full_name, $username, $email, $password);
        if ($result['success']) {
            $register_success = 'Registration successful! You can now login with your credentials.';
        } else {
            $register_error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Register - <?php echo SITE_NAME; ?></title>
    
    <!-- Google Fonts - Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="../assets/css/icon-fixes.css">
    
    <!-- Font Awesome Fallback - handled by icon-fixes.css -->
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/enhanced-ui.css">
    <link rel="stylesheet" href="../assets/css/mobile-responsive.css">
    <script src="../assets/js/modal-system.js"></script>
    
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
        
        /* Enhanced Mobile Registration Styles */
        .mobile-register-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .mobile-register-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .mobile-register-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }
        
        .mobile-form-input {
            background: rgba(255, 255, 255, 0.9) !important;
            border: 2px solid #e5e7eb !important;
            border-radius: 1rem !important;
            padding: 1rem 1.25rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 1rem !important;
            font-weight: 500 !important;
            color: #374151 !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            min-height: 56px !important;
        }
        
        .mobile-form-input:focus {
            outline: none !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1) !important;
            transform: translateY(-2px) !important;
            background: white !important;
        }
        
        .mobile-form-input::placeholder {
            color: #9ca3af !important;
            font-weight: 400 !important;
        }
        
        .mobile-form-label {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            color: #374151 !important;
            font-size: 0.95rem !important;
            margin-bottom: 0.5rem !important;
        }
        
        .mobile-submit-btn {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: white !important;
            border: none !important;
            border-radius: 1rem !important;
            padding: 1rem 2rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 700 !important;
            font-size: 1.1rem !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            min-height: 56px !important;
            position: relative !important;
            overflow: hidden !important;
        }
        
        .mobile-submit-btn:hover {
            transform: translateY(-2px) scale(1.02) !important;
            box-shadow: 0 20px 40px -12px rgba(59, 130, 246, 0.4) !important;
        }
        
        .mobile-submit-btn:active {
            transform: translateY(0) scale(0.98) !important;
        }
        
        .mobile-back-btn {
            background: rgba(255, 255, 255, 0.2) !important;
            color: white !important;
            border: 2px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 1rem !important;
            padding: 0.75rem 1.5rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            transition: all 0.3s ease !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .mobile-back-btn:hover {
            background: rgba(255, 255, 255, 0.3) !important;
            transform: translateY(-2px) !important;
            color: white !important;
        }
        
        .mobile-alert {
            border-radius: 1rem !important;
            padding: 1rem 1.25rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 500 !important;
            margin-bottom: 1.5rem !important;
            border: 2px solid !important;
        }
        
        .mobile-alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0) !important;
            border-color: #10b981 !important;
            color: #065f46 !important;
        }
        
        .mobile-alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca) !important;
            border-color: #ef4444 !important;
            color: #991b1b !important;
        }
        
        .mobile-checkbox {
            width: 20px !important;
            height: 20px !important;
            accent-color: #3b82f6 !important;
            border-radius: 0.5rem !important;
        }
        
        .mobile-checkbox-label {
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.9rem !important;
            color: #374151 !important;
            line-height: 1.5 !important;
        }
        
        .mobile-checkbox-label a {
            color: #3b82f6 !important;
            font-weight: 600 !important;
            text-decoration: none !important;
        }
        
        .mobile-checkbox-label a:hover {
            text-decoration: underline !important;
        }
        
        .mobile-password-toggle {
            color: #6b7280 !important;
            transition: color 0.3s ease !important;
            padding: 0.5rem !important;
            border-radius: 0.5rem !important;
        }
        
        .mobile-password-toggle:hover {
            color: #374151 !important;
            background: rgba(59, 130, 246, 0.1) !important;
        }
        
        .mobile-password-strength {
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            margin-top: 0.5rem !important;
        }
        
        .mobile-password-strength.weak {
            color: #ef4444 !important;
        }
        
        .mobile-password-strength.medium {
            color: #f59e0b !important;
        }
        
        .mobile-password-strength.strong {
            color: #10b981 !important;
        }
        
        .mobile-password-match {
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.875rem !important;
            font-weight: 600 !important;
            margin-top: 0.5rem !important;
        }
        
        .mobile-password-match.match {
            color: #10b981 !important;
        }
        
        .mobile-password-match.no-match {
            color: #ef4444 !important;
        }
        
        /* Mobile-specific responsive design */
        @media (max-width: 768px) {
            .mobile-register-container {
                padding: 1rem !important;
            }
            
            .mobile-register-card {
                margin: 0 !important;
                border-radius: 1.5rem !important;
                padding: 2rem 1.5rem !important;
            }
            
            .mobile-form-input {
                padding: 0.875rem 1rem !important;
                font-size: 1rem !important;
                min-height: 52px !important;
            }
            
            .mobile-submit-btn {
                padding: 0.875rem 1.5rem !important;
                font-size: 1rem !important;
                min-height: 52px !important;
            }
            
            .mobile-back-btn {
                padding: 0.625rem 1.25rem !important;
                font-size: 0.9rem !important;
            }
        }
        
        @media (max-width: 480px) {
            .mobile-register-container {
                padding: 0.5rem !important;
            }
            
            .mobile-register-card {
                padding: 1.5rem 1rem !important;
                border-radius: 1.25rem !important;
            }
            
            .mobile-form-input {
                padding: 0.75rem 0.875rem !important;
                font-size: 0.95rem !important;
                min-height: 48px !important;
            }
            
            .mobile-submit-btn {
                padding: 0.75rem 1.25rem !important;
                font-size: 0.95rem !important;
                min-height: 48px !important;
            }
        }
        
        /* Animation for form elements */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .mobile-form-group {
            animation: slideInUp 0.6s ease-out;
        }
        
        .mobile-form-group:nth-child(1) { animation-delay: 0.1s; }
        .mobile-form-group:nth-child(2) { animation-delay: 0.2s; }
        .mobile-form-group:nth-child(3) { animation-delay: 0.3s; }
        .mobile-form-group:nth-child(4) { animation-delay: 0.4s; }
        .mobile-form-group:nth-child(5) { animation-delay: 0.5s; }
        .mobile-form-group:nth-child(6) { animation-delay: 0.6s; }
        .mobile-form-group:nth-child(7) { animation-delay: 0.7s; }
        .mobile-form-group:nth-child(8) { animation-delay: 0.8s; }
        
        /* Loading animation for submit button */
        .mobile-submit-btn.loading {
            position: relative !important;
            color: transparent !important;
        }
        
        .mobile-submit-btn.loading::after {
            content: '';
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            width: 20px !important;
            height: 20px !important;
            margin: -10px 0 0 -10px !important;
            border: 2px solid transparent !important;
            border-top: 2px solid white !important;
            border-radius: 50% !important;
            animation: spin 1s linear infinite !important;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Touch-friendly focus states */
        .mobile-form-input:focus-visible {
            outline: 2px solid #3b82f6 !important;
            outline-offset: 2px !important;
        }
        
        .mobile-submit-btn:focus-visible {
            outline: 2px solid #3b82f6 !important;
            outline-offset: 2px !important;
        }
        
        /* Prevent zoom on input focus for iOS */
        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            select, textarea, input[type="text"], input[type="password"], input[type="datetime"], input[type="datetime-local"], input[type="date"], input[type="month"], input[type="time"], input[type="week"], input[type="number"], input[type="email"], input[type="url"], input[type="search"], input[type="tel"], input[type="color"] {
                font-size: 16px !important;
            }
        }
        
        /* Icon fixes for register page - ensure proper display */
        .mobile-register-container .fas, 
        .mobile-register-container .far, 
        .mobile-register-container .fab {
            font-size: 1rem !important;
            margin-right: 0.5rem !important;
            vertical-align: middle !important;
        }
        
        /* Ensure proper scrolling on mobile */
        html, body {
            overflow-x: hidden !important;
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch !important;
            height: 100% !important;
        }
        
        /* Fix for mobile scrolling issues */
        .mobile-register-container {
            position: relative !important;
            min-height: 100vh !important;
            height: auto !important;
            padding: 1rem 0 !important;
        }
        
        /* Ensure form is scrollable and properly spaced */
        .mobile-register-card {
            margin-bottom: 2rem !important;
        }
        
        /* Mobile-specific scrolling fixes */
        @media (max-width: 768px) {
            .mobile-register-container {
                padding: 0.5rem 0 !important;
                min-height: 100vh !important;
            }
            
            body {
                position: relative !important;
                height: auto !important;
                min-height: 100vh !important;
            }
        }
    </style>
</head>
<body class="mobile-register-container">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-md mx-auto space-y-8">
        <!-- Mobile Navigation Header -->
        <div class="flex items-center justify-between mb-6">
            <a href="../index.php" class="mobile-back-btn">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
            <div class="text-center flex-1">
                <a href="../index.php" class="inline-block">
                   
                </a>
            </div>
            <div class="w-20"></div> <!-- Spacer for centering -->
        </div>
        
        <!-- Mobile Registration Card -->
        <div class="mobile-register-card py-8 px-6">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-user-plus text-white text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Create Account</h2>
                <p class="text-sm text-gray-600">
                    Join us today and start booking facilities
                </p>
                <p class="mt-3 text-sm text-gray-600">
                    Already have an account?
                    <a href="login.php" class="font-semibold text-blue-600 hover:text-blue-700 transition-colors">
                        Sign in here
                    </a>
                </p>
            </div>
            <!-- Mobile Alert Messages -->
            <?php if (isset($register_success)): ?>
                <div class="mobile-alert mobile-alert-success">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-xl mr-3 mt-0.5"></i>
                        <div>
                            <p class="font-semibold mb-2">Registration Successful!</p>
                            <p class="text-sm mb-3"><?php echo htmlspecialchars($register_success); ?></p>
                            <a href="login.php" class="inline-flex items-center text-sm font-semibold hover:underline">
                                <i class="fas fa-sign-in-alt mr-2"></i>
                                Click here to login
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($register_error)): ?>
                <div class="mobile-alert mobile-alert-error">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-xl mr-3 mt-0.5"></i>
                        <div>
                            <p class="font-semibold mb-1">Registration Error</p>
                            <p class="text-sm"><?php echo htmlspecialchars($register_error); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Mobile Registration Form -->
            <form class="space-y-6" method="POST" id="registerForm">
                <input type="hidden" name="action" value="register">
                
                <!-- Full Name Field -->
                <div class="mobile-form-group">
                    <label for="full_name" class="mobile-form-label">
                        <i class="fas fa-user mr-2"></i>Full Name
                    </label>
                    <div class="relative">
                        <input id="full_name" name="full_name" type="text" required 
                               class="mobile-form-input w-full pl-12 pr-4"
                               placeholder="Enter your full name"
                               autocomplete="name">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                          
                        </div>
                    </div>
                </div>
                
                <!-- Username Field -->
                <div class="mobile-form-group">
                    <label for="username" class="mobile-form-label">
                        <i class="fas fa-at mr-2"></i>Username
                    </label>
                    <div class="relative">
                        <input id="username" name="username" type="text" required 
                               class="mobile-form-input w-full pl-12 pr-4"
                               placeholder="Choose a username"
                               autocomplete="username">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            
                        </div>
                    </div>
                </div>
                
                <!-- Email Field -->
                <div class="mobile-form-group">
                    <label for="email" class="mobile-form-label">
                        <i class="fas fa-envelope mr-2"></i>Email Address
                    </label>
                    <div class="relative">
                        <input id="email" name="email" type="email" required 
                               class="mobile-form-input w-full pl-12 pr-4"
                               placeholder="Enter your email address"
                               autocomplete="email">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                           
                        </div>
                    </div>
                </div>
                <!-- Password Field -->
                <div class="mobile-form-group">
                    <label for="password" class="mobile-form-label">
                      
                    </label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required 
                               class="mobile-form-input w-full pl-12 pr-12"
                               placeholder="Create a password"
                               autocomplete="new-password">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                           
                        </div>
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center">
                            <button type="button" id="togglePassword" class="mobile-password-toggle">
                                <i class="fas fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div id="passwordStrength" class="mobile-password-strength">
                        Password must be at least 6 characters long
                    </div>
                </div>
                
                <!-- Confirm Password Field -->
                <div class="mobile-form-group">
                    <label for="confirm_password" class="mobile-form-label">
                       
                    </label>
                    <div class="relative">
                        <input id="confirm_password" name="confirm_password" type="password" required 
                               class="mobile-form-input w-full pl-12 pr-4"
                               placeholder="Confirm your password"
                               autocomplete="new-password">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            
                        </div>
                    </div>
                    <div id="passwordMatch" class="mobile-password-match hidden"></div>
                </div>
                <!-- Terms and Conditions -->
                <div class="mobile-form-group">
                    <div class="flex items-start space-x-3">
                    <input id="terms" name="terms" type="checkbox" required
                               class="mobile-checkbox mt-1">
                        <label for="terms" class="mobile-checkbox-label">
                        I agree to the 
                            <a href="#" class="hover:underline">Terms of Service</a>
                        and
                            <a href="#" class="hover:underline">Privacy Policy</a>
                    </label>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="mobile-form-group">
                    <button type="submit" id="submitBtn" class="mobile-submit-btn w-full flex items-center justify-center">
                        <i class="fas fa-user-plus mr-2"></i>
                        <span>Create Account</span>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Mobile Footer -->
        <div class="text-center mt-6">
            <p class="text-white text-sm mb-4">
                By creating an account, you agree to our terms and conditions
            </p>
            <a href="../index.php" class="mobile-back-btn">
                <i class="fas fa-home mr-2"></i>
                <span>Back to Home</span>
            </a>
        </div>
        </div>
    </div>
    
    <!-- Font Awesome Test -->
    <script>
        // Simple test to ensure Font Awesome is working
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Font Awesome test: Icons should be visible now');
            
            // Add a small delay to ensure all styles are loaded
            setTimeout(() => {
                const icons = document.querySelectorAll('.fas, .far, .fab');
                console.log(`Found ${icons.length} icons on the page`);
            }, 100);
        });
    </script>
    
    <script>
        // Enhanced Mobile Registration Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality with mobile optimization
            const togglePassword = document.getElementById('togglePassword');
            const password = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (togglePassword && password && eyeIcon) {
                togglePassword.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
            if (password.type === 'password') {
                password.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
                        togglePassword.setAttribute('aria-label', 'Hide password');
            } else {
                password.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
                        togglePassword.setAttribute('aria-label', 'Show password');
                    }
                });
            }
            
            // Enhanced password strength checker
            function checkPasswordStrength(password) {
                const strengthDiv = document.getElementById('passwordStrength');
                if (!strengthDiv) return;
                
                let strength = 0;
                let message = '';
                let className = 'mobile-password-strength';
                
                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                if (password.length === 0) {
                    message = 'Password must be at least 6 characters long';
                    className += '';
                } else if (password.length < 6) {
                    message = 'Password too short (minimum 6 characters)';
                    className += ' weak';
                } else if (strength <= 2) {
                    message = 'Weak password - add numbers and special characters';
                    className += ' weak';
                } else if (strength <= 4) {
                    message = 'Medium strength password';
                    className += ' medium';
                } else {
                    message = 'Strong password!';
                    className += ' strong';
                }
                
                strengthDiv.textContent = message;
                strengthDiv.className = className;
            }
            
            // Password confirmation check with enhanced mobile styling
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
                
                if (!matchDiv) return;
                
            if (confirmPassword === '') {
                matchDiv.classList.add('hidden');
                return;
            }
                
            if (password === confirmPassword) {
                    matchDiv.innerHTML = '<i class="fas fa-check mr-1"></i>Passwords match';
                    matchDiv.className = 'mobile-password-match match';
                matchDiv.classList.remove('hidden');
            } else {
                    matchDiv.innerHTML = '<i class="fas fa-times mr-1"></i>Passwords do not match';
                    matchDiv.className = 'mobile-password-match no-match';
                matchDiv.classList.remove('hidden');
            }
        }
            
            // Add event listeners
            if (password) {
                password.addEventListener('input', function() {
                    checkPasswordStrength(this.value);
                    checkPasswordMatch();
                });
            }
            
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword) {
                confirmPassword.addEventListener('input', checkPasswordMatch);
            }
            // Enhanced mobile form validation
            const registerForm = document.getElementById('registerForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (registerForm && submitBtn) {
                registerForm.addEventListener('submit', async function(e) {
            const fullName = document.getElementById('full_name').value.trim();
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms').checked;
                    
                    // Show loading state
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                    
            // Basic validation
            if (!fullName || !username || !email || !password || !confirmPassword) {
                e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Please fill in all required fields', 'error');
                return false;
            }
                    
            if (password.length < 6) {
                e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Password must be at least 6 characters long', 'error');
                return false;
            }
                    
            if (password !== confirmPassword) {
                e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Passwords do not match', 'error');
                return false;
            }
                    
            if (!terms) {
                e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Please agree to the terms and conditions', 'warning');
                return false;
            }
                    
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Please enter a valid email address', 'error');
                        return false;
                    }
                    
                    // Username validation
                    if (username.length < 3) {
                        e.preventDefault();
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                        showMobileAlert('Username must be at least 3 characters long', 'error');
                return false;
                    }
                    
                    // If all validations pass, allow form submission
                    // The loading state will be maintained until page reload
                });
            }
            
            // Mobile alert function
            function showMobileAlert(message, type = 'error') {
                // Create mobile alert element
                const alertDiv = document.createElement('div');
                alertDiv.className = `mobile-alert mobile-alert-${type} fixed top-4 left-4 right-4 z-50`;
                alertDiv.innerHTML = `
                    <div class="flex items-start">
                        <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'info-circle'} text-xl mr-3 mt-0.5"></i>
                        <div>
                            <p class="font-semibold mb-1">${type === 'error' ? 'Error' : 'Warning'}</p>
                            <p class="text-sm">${message}</p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-lg hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                
                document.body.appendChild(alertDiv);
                
                // Auto remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentElement) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
            
            // Mobile-specific optimizations
            if (window.innerWidth <= 768) {
                // Prevent zoom on input focus for iOS
                const inputs = document.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]');
                inputs.forEach(input => {
                    input.addEventListener('focus', function() {
                        this.style.fontSize = '16px';
                    });
                });
                
                // Add touch feedback for buttons
                const buttons = document.querySelectorAll('button, .mobile-back-btn');
                buttons.forEach(button => {
                    button.addEventListener('touchstart', function() {
                        this.style.transform = 'scale(0.98)';
                    }, { passive: true });
                    
                    button.addEventListener('touchend', function() {
                        this.style.transform = '';
                    }, { passive: true });
                });
                
                // Smooth scroll to focused input
                inputs.forEach(input => {
                    input.addEventListener('focus', function() {
                        setTimeout(() => {
                            this.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center' 
                            });
                        }, 300);
                    });
                });
            }
        });
    </script>
</body>
</html>
