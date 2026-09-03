<?php
/**
 * Database Setup/Migration Script
 * Run this file once to create all necessary tables for the Scholar system
 * Access via: http://localhost/scholar/db_setup.php
 */

require 'db.php';

try {
    $pdo->beginTransaction();

    // 1. Ensure schools table has required fields
    $pdo->exec("ALTER TABLE schools ADD COLUMN IF NOT EXISTS motto VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE schools ADD COLUMN IF NOT EXISTS location VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE schools ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE schools ADD COLUMN IF NOT EXISTS phone_contact VARCHAR(20) DEFAULT NULL");

    // 2. Create departments table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_id INT NOT NULL,
        department_name VARCHAR(255) NOT NULL,
        department_code VARCHAR(50) NOT NULL UNIQUE,
        head_of_dept VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    )");

    // 3. Create staff_departments junction table for many-to-many relationship
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_departments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id VARCHAR(50) NOT NULL,
        department_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_staff_dept (staff_id, department_id)
    )");

    // 4. Create staff_responsibilities table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_responsibilities (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id VARCHAR(50),
        responsibility VARCHAR(255),
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
    )");

    // 5. Create grading_scales table for report configuration
    $pdo->exec("CREATE TABLE IF NOT EXISTS grading_scales (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_id INT NOT NULL,
        grade_letter VARCHAR(2),
        grade_name VARCHAR(50),
        min_percentage INT,
        max_percentage INT,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    )");

    // 6. Create report_templates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_templates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_id INT NOT NULL,
        template_name VARCHAR(255),
        template_type ENUM('standard', 'detailed', 'custom'),
        template_config JSON,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    )");

    // 7. Ensure students table has photo_path column
    $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500) DEFAULT NULL");

    // 8. Ensure staff table has all necessary columns
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS staff_category VARCHAR(50) DEFAULT 'Teaching'");
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS primary_role VARCHAR(100) DEFAULT 'Regular Teacher'");
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS assign_class VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS assign_stream VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS assign_subject VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");

    // 9. Student portal support: allow role='student' and link users -> students
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('developer','school_admin','dos','teacher','headteacher','bursar','parent','student') NOT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id INT NULL DEFAULT NULL AFTER staff_id");
    $pdo->exec("ALTER TABLE users ADD KEY IF NOT EXISTS idx_users_student_id (student_id)");

    $pdo->commit();

    echo "<div style='background:#0f1115; color:#00A8A8; padding:20px; border-radius:8px; font-family:monospace; border:1px solid #1e293b;'>";
    echo "<strong>✓ Database Setup Complete!</strong><br><br>";
    echo "All tables created/updated successfully:<br>";
    echo "- schools (enhanced with motto, location, email, phone)<br>";
    echo "- departments (new table)<br>";
    echo "- staff_departments (new junction table)<br>";
    echo "- staff_responsibilities (new table)<br>";
    echo "- grading_scales (new table)<br>";
    echo "- report_templates (new table)<br>";
    echo "- students (enhanced)<br>";
    echo "- staff (enhanced)<br>";
    echo "- users (role enum + student_id, for the student portal)<br><br>";
    echo "<a href='dashboard.php' style='color:#34d399; text-decoration:none; font-weight:bold;'>→ Go to Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='background:#0f1115; color:#ef4444; padding:20px; border-radius:8px; font-family:monospace; border:1px solid #1e293b;'>";
    echo "<strong>✗ Database Setup Error:</strong><br>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
