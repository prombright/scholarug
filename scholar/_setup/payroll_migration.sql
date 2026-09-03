-- ============================================================
-- SCHOLAR — PAYROLL (simple monthly pay ledger)
-- ============================================================
-- Deliberately NOT a tax/statutory computation engine (no PAYE/NSSF
-- brackets) -- a record of what was paid each month per staff member,
-- printable as a basic payslip. No UNIQUE constraint on
-- (staff_id, month, year): correcting a mistaken entry is a second row,
-- not an edit-in-place, matching this app's general preference for
-- append-friendly financial records (see fee_payments).
--
-- Apply with: mysql -u root scholar < payroll_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS payroll_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    staff_id INT NOT NULL,
    pay_period_month TINYINT UNSIGNED NOT NULL,
    pay_period_year INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school_staff (school_id, staff_id),
    KEY idx_school_period (school_id, pay_period_year, pay_period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
