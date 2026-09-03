-- ============================================================
-- SCHOLAR — EMBEDDED BULK SMS MODULE
-- ============================================================
-- A per-school wallet/contacts/campaign system living entirely in
-- Scholar's own database -- NOT the bulksms database, NOT the shared
-- "Scholar" service account bulksms_client.php/message_parents.php use
-- today (that stays as-is, one shared account for the whole platform).
-- This is deliberately modeled on bulksms's own schema
-- (_setup/schema.sql: wallets/wallet_transactions/campaigns/
-- campaign_recipients) and topup_migration.sql (wallet_topup_requests),
-- just re-scoped to school_id instead of account_id so each school has
-- its own real balance.
--
-- Every table here has a school_id column, so scholar_purge_school()
-- (developer/schools/_school_purge.php) automatically discovers and
-- wipes these for a deleted school with zero extra code -- it walks
-- information_schema for exactly that column.
--
-- Apply with: mysql -u root scholar < sms_module_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS sms_wallets (
    school_id INT PRIMARY KEY,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only ledger, same shape as bulksms's wallet_transactions --
-- balance_after is a point-in-time snapshot so history stays legible
-- even if the running balance is ever recomputed.
CREATE TABLE IF NOT EXISTS sms_wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    type ENUM('deposit','send_debit','admin_credit','admin_debit','refund') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference VARCHAR(190) DEFAULT NULL,
    created_by VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per mobile-money top-up attempt -- survives a page reload,
-- and is the only place a wallet ever gets credited via deposit (see
-- topup_status.php's guarded UPDATE ... WHERE status='pending').
CREATE TABLE IF NOT EXISTS sms_topup_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    network ENUM('mtn','airtel') NOT NULL,
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
    reference VARCHAR(64) NOT NULL UNIQUE,
    provider_reference VARCHAR(190) DEFAULT NULL,
    status ENUM('pending','successful','failed','expired') NOT NULL DEFAULT 'pending',
    failure_reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A named recipient group: either a static imported list ('imported',
-- rows live in sms_contacts) or a live reference to one class/the whole
-- school ('class'/'whole_school' -- resolved fresh from
-- students/parent_students/guardians at send time, never snapshotted,
-- so it's always current).
CREATE TABLE IF NOT EXISTS sms_contact_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    group_type ENUM('imported','class','whole_school') NOT NULL DEFAULT 'imported',
    class_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Only populated for group_type='imported' groups.
CREATE TABLE IF NOT EXISTS sms_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    group_id INT NOT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_group (group_id),
    CONSTRAINT fk_sms_contacts_group FOREIGN KEY (group_id) REFERENCES sms_contact_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One flat platform-wide rate (not bulksms's full volume-discount tier
-- table) -- deliberately simple for v1, adjustable by a developer.
-- Single row, id fixed at 1.
CREATE TABLE IF NOT EXISTS sms_pricing_settings (
    id INT PRIMARY KEY,
    price_per_segment DECIMAL(10,4) NOT NULL DEFAULT 35.0000,
    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sms_pricing_settings (id, price_per_segment, currency) VALUES (1, 35.0000, 'UGX');

CREATE TABLE IF NOT EXISTS sms_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    message TEXT NOT NULL,
    segments INT NOT NULL DEFAULT 1,
    recipient_count INT NOT NULL DEFAULT 0,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_campaign_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    phone VARCHAR(30) NOT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    delivery_status ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(190) DEFAULT NULL,
    KEY idx_campaign (campaign_id),
    CONSTRAINT fk_sms_campaign_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;