-- ============================================================================
-- Huduma Campus: Campus Support & Inquiry Management System
-- Normalized MySQL Database Schema & Initial Sample Data
-- ============================================================================

-- Create the database using utf8mb4 to support full Unicode characters
CREATE DATABASE IF NOT EXISTS huduma_campus_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE huduma_campus_db;

-- ----------------------------------------------------------------------------
-- 1. Users Table: Stores registered student/staff accounts and hashed passwords
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    reg_number VARCHAR(40) NOT NULL DEFAULT 'N/A',
    department VARCHAR(80) NOT NULL DEFAULT 'General',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Indexes for fast lookup during login and duplicate checks
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 2. Categories Table: 3NF lookup table for campus service departments
-- ----------------------------------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    office_in_charge VARCHAR(100) NOT NULL,
    sla_hours INT NOT NULL DEFAULT 24,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. Submissions Table: Stores Contact Us inquiries linked to users & categories
-- ----------------------------------------------------------------------------
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    sender_email VARCHAR(120) NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Open', 'In Progress', 'Resolved') NOT NULL DEFAULT 'Open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    -- Foreign key constraints enforcing referential integrity
    CONSTRAINT fk_submissions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    -- Indexes on columns frequently used in WHERE search and ORDER BY sorting
    INDEX idx_submissions_subject (subject),
    INDEX idx_submissions_created (created_at),
    INDEX idx_submissions_status (status)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 4. Activity Logs Table: Relational audit trail for login and CRUD events
-- ----------------------------------------------------------------------------
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(50) NOT NULL,
    details VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_created (created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Sample Data: Campus Support Categories
-- ----------------------------------------------------------------------------
INSERT INTO categories (id, name, office_in_charge, sla_hours, description) VALUES
(1, 'ICT & Portal Support', 'Directorate of ICT', 12, 'Student portal login, campus Wi-Fi, and LMS technical issues'),
(2, 'Finance & Fee Clearance', 'Student Finance Office', 24, 'Fee statements, HELB disbursement updates, and exam card clearance'),
(3, 'Examinations & Records', 'Office of the Registrar', 48, 'Missing marks, timetable clashes, transcripts, and unit registration'),
(4, 'Student Welfare & Hostels', 'Dean of Students', 24, 'Accommodation inquiries, health services, and campus welfare feedback');

-- ----------------------------------------------------------------------------
-- Sample Data: Pre-registered Users (Demo password for both accounts: password)
-- ----------------------------------------------------------------------------
INSERT INTO users (id, username, email, password_hash, full_name, reg_number, department) VALUES
(1, 'ian_kibiwott', 'ian@hudumacampus.ac.ke', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ian Kibiwott', 'BCS-03-0111/2025', 'Computer Science'),
(2, 'faith_wanjiku', 'faith@hudumacampus.ac.ke', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Faith Wanjiku', 'BIT-02-0084/2025', 'Information Technology');

-- ----------------------------------------------------------------------------
-- Sample Data: 7 Contact Submissions (enables multi-page pagination immediately)
-- ----------------------------------------------------------------------------
INSERT INTO submissions (user_id, category_id, sender_name, sender_email, subject, message, status, created_at) VALUES
(1, 1, 'Ian Kibiwott', 'ian@hudumacampus.ac.ke', 'Student Portal Unit Registration Sync', 'My registered units for BSD 322 are not reflecting on the e-learning dashboard.', 'Resolved', '2026-11-12 09:20:00'),
(2, 2, 'Faith Wanjiku', 'faith@hudumacampus.ac.ke', 'Exam Card Fee Clearance Delay', 'Paid the semester balance via bank deposit two days ago; requesting clearance update.', 'In Progress', '2026-11-13 11:45:00'),
(1, 3, 'Ian Kibiwott', 'ian@hudumacampus.ac.ke', 'Friday Practical Lab Timetable Clash', 'Internet Programming II practical overlaps with Research Methods tutorial group B.', 'Open', '2026-11-14 14:10:00'),
(2, 1, 'Faith Wanjiku', 'faith@hudumacampus.ac.ke', 'Library Wi-Fi Authentication Timeout', 'Campus wireless network disconnects every 20 minutes on the second floor.', 'Open', '2026-11-15 16:05:00'),
(1, 4, 'Ian Kibiwott', 'ian@hudumacampus.ac.ke', 'Hostel Block C Study Room Lighting', 'Two fluorescent tubes in the evening study room require replacement.', 'Resolved', '2026-11-16 18:30:00'),
(2, 3, 'Faith Wanjiku', 'faith@hudumacampus.ac.ke', 'CAT 2 Score Verification Request', 'Requesting confirmation of my uploaded CAT 2 script status on Moodle.', 'In Progress', '2026-11-17 10:15:00'),
(1, 2, 'Ian Kibiwott', 'ian@hudumacampus.ac.ke', 'HELB Upkeep Allocation Inquiry', 'Checking whether the latest disbursement batch has been credited to student accounts.', 'Open', '2026-11-18 08:40:00');