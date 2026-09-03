-- ============================================================
-- iLEARNING — LIVE-CLASS ADD-ON BILLING
-- ============================================================
-- Structural mirror of payments/_setup/subscriptions_migration.sql's
-- scholar section (subscriptions/subscription_charges) -- a school has
-- exactly one base `subscriptions` row (UNIQUE(school_id)), so this
-- add-on cannot be another status on that row without corrupting "the
-- school's base plan". It gets its own table, same shape, same
-- idempotency guarantees via payments/SubscriptionCharge.php (which
-- gains 'ilearning_addon_charges' in its table whitelist).
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_addon_billing_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS ilearning_addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL UNIQUE,
    plan_code VARCHAR(40) NOT NULL,
    billing_cycle ENUM('trial','monthly','termly','yearly') NOT NULL DEFAULT 'trial',
    status ENUM('trialing','active','past_due','expired','canceled') NOT NULL DEFAULT 'trialing',
    trial_ends_at DATETIME NULL,
    current_period_start DATETIME NULL,
    current_period_end DATETIME NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_addon_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ilearning_addon_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    addon_id INT NOT NULL,
    plan_code VARCHAR(40) NULL,
    network ENUM('mtn','airtel','stub') NOT NULL,
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
    reference VARCHAR(64) NOT NULL UNIQUE,
    provider_reference VARCHAR(190) DEFAULT NULL,
    status ENUM('pending','successful','failed','expired') NOT NULL DEFAULT 'pending',
    failure_reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_addon_charges_addon (addon_id),
    CONSTRAINT fk_addon_charge_addon FOREIGN KEY (addon_id) REFERENCES ilearning_addons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
