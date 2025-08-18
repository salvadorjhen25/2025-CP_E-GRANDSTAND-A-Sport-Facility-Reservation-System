-- Facility Reservation System Database
-- Create database
CREATE DATABASE IF NOT EXISTS facility_reservation;
USE facility_reservation;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Facility categories
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Facilities table
CREATE TABLE facilities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category_id INT,
    capacity INT,
    hourly_rate DECIMAL(10,2) DEFAULT 0.00,
    daily_rate DECIMAL(10,2) DEFAULT 0.00,
    image_url VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Reservations table (updated with payment tracking)
CREATE TABLE reservations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    facility_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    total_amount DECIMAL(10,2),
    status ENUM('pending', 'confirmed', 'cancelled', 'completed', 'expired', 'no_show') DEFAULT 'pending',
    purpose VARCHAR(255),
    attendees INT DEFAULT 1,
    payment_status ENUM('pending', 'paid', 'expired') DEFAULT 'pending',
    payment_due_at TIMESTAMP NULL,
    payment_slip_url VARCHAR(255) NULL,
    payment_verified_at TIMESTAMP NULL,
    verified_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Waitlist table for priority queuing
CREATE TABLE waitlist (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    facility_id INT NOT NULL,
    desired_start_time DATETIME NOT NULL,
    desired_end_time DATETIME NOT NULL,
    priority_score INT DEFAULT 0,
    status ENUM('waiting', 'notified', 'expired') DEFAULT 'waiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

-- Payment verification logs
CREATE TABLE payment_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reservation_id INT NOT NULL,
    action ENUM('uploaded', 'verified', 'rejected', 'expired') NOT NULL,
    admin_id INT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert sample data
INSERT INTO categories (name, description) VALUES
('Conference Rooms', 'Professional meeting and conference spaces'),
('Sports Facilities', 'Gym, courts, and sports equipment'),
('Event Spaces', 'Large venues for events and gatherings'),
('Study Rooms', 'Quiet spaces for individual or group study');

INSERT INTO facilities (name, description, category_id, capacity, hourly_rate, daily_rate, image_url) VALUES
('Grand Conference Hall', 'Large conference room with projector and audio system', 1, 100, 150.00, 1200.00, 'conference-hall.jpg'),
('Executive Meeting Room', 'Intimate meeting room for small groups', 1, 20, 75.00, 600.00, 'executive-room.jpg'),
('Basketball Court', 'Indoor basketball court with equipment', 2, 30, 50.00, 'basketball-court.jpg'),
('Fitness Center', 'Fully equipped gym with cardio and strength training', 2, 50, 25.00, 'fitness-center.jpg'),
('Main Auditorium', 'Large auditorium for events and presentations', 3, 200, 200.00, 'auditorium.jpg'),
('Study Room A', 'Quiet study room with tables and chairs', 4, 10, 15.00, 'study-room.jpg'),
('Study Room B', 'Another quiet study room', 4, 8, 15.00, 'study-room-b.jpg');

-- Insert admin user (password: admin123)
INSERT INTO users (username, email, password, full_name, role) VALUES
('admin', 'admin@facility.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- Insert sample user (password: user123)
INSERT INTO users (username, email, password, full_name, role) VALUES
('user1', 'user1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Doe', 'user');

-- Create indexes for better performance
CREATE INDEX idx_reservations_facility_date ON reservations(facility_id, start_time, end_time);
CREATE INDEX idx_reservations_payment_status ON reservations(payment_status, payment_due_at);
CREATE INDEX idx_waitlist_facility_time ON waitlist(facility_id, desired_start_time, desired_end_time);
CREATE INDEX idx_waitlist_priority ON waitlist(priority_score DESC, created_at ASC);
