<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailMailer.php';
class Auth {
    private $pdo;
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    // User login (for regular users only)
    public function login($username, $password) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                return ['success' => true, 'message' => 'Login successful'];
            }
            return ['success' => false, 'message' => 'Invalid username or password'];
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed'];
        }
    }
    // Admin login (for admins and staff)
    public function adminLogin($username, $password) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role IN ('admin', 'staff')");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                return ['success' => true, 'message' => 'Login successful'];
            }
            return ['success' => false, 'message' => 'Invalid credentials'];
        } catch (PDOException $e) {
            error_log("Admin login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed'];
        }
    }
    // User login (for regular users only - rejects admins and staff)
    public function userLogin($username, $password) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'user'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                return ['success' => true, 'message' => 'Login successful'];
            }
            return ['success' => false, 'message' => 'Invalid username or password'];
        } catch (PDOException $e) {
            error_log("User login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed'];
        }
    }
    // User registration
    public function register($full_name, $username, $email, $password) {
        try {
            // Check if user already exists
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            // Hash password and insert user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, full_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $email, $hashed_password, $full_name]);
            // Send welcome email
            try {
                $mailer = new EmailMailer();
                $mailer->sendWelcomeEmail($email, $full_name);
            } catch (Exception $e) {
                error_log("Welcome email error: " . $e->getMessage());
                // Don't fail registration if email fails
            }
            return ['success' => true, 'message' => 'Registration successful'];
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Registration failed'];
        }
    }
    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    // Check if user is admin
    public function isAdmin() {
        return $this->isLoggedIn() && $_SESSION['role'] === 'admin';
    }
    
    // Check if user is staff
    public function isStaff() {
        return $this->isLoggedIn() && $_SESSION['role'] === 'staff';
    }
    
    // Check if user is admin or staff
    public function isAdminOrStaff() {
        return $this->isLoggedIn() && in_array($_SESSION['role'], ['admin', 'staff']);
    }
    
    // Check if user is a regular user (not admin or staff)
    public function isRegularUser() {
        return $this->isLoggedIn() && $_SESSION['role'] === 'user';
    }
    // Get current user data
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT id, username, email, full_name, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get user error: " . $e->getMessage());
            return null;
        }
    }
    // Logout user
    public function logout() {
        session_destroy();
        return true;
    }
    // Require login
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
    // Require admin
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: index.php');
            exit();
        }
    }
    
    // Require admin or staff
    public function requireAdminOrStaff() {
        $this->requireLogin();
        if (!$this->isAdminOrStaff()) {
            header('Location: index.php');
            exit();
        }
    }
    
    // Require admin only (staff cannot access)
    public function requireAdminOnly() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: dashboard.php');
            exit();
        }
    }
    
    // Require regular user (not admin or staff)
    public function requireRegularUser() {
        $this->requireLogin();
        if ($this->isAdminOrStaff()) {
            header('Location: ../admin/dashboard.php');
            exit();
        }
    }
}
?>
