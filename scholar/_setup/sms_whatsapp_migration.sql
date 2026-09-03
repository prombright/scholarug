-- ============================================================
-- SCHOLAR — BULK SMS: WHATSAPP CHANNEL
-- ============================================================
-- Per-school WhatsApp Business connection (mirrors bulksms's
-- account_whatsapp_settings, just school_id-scoped) -- each school
-- connects its OWN WhatsApp Business number, same as a real customer
-- would on the standalone product. Every send attempts WhatsApp first
-- for a school that has one connected, falling back to SMS
-- automatically when it's not available for that number -- see
-- ScholarCampaignSender::send().
--
-- Apply with: mysql -u root scholar < sms_whatsapp_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS sms_whatsapp_settings (
    school_id INT PRIMARY KEY,
    sender_name VARCHAR(150) NOT NULL,
    whatsapp_number VARCHAR(30) NOT NULL,
    phone_number_id VARCHAR(190) NOT NULL,
    access_token_encrypted TEXT NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records which channel a recipient actually got the message on --
-- defaults to 'sms' so existing rows from before this migration stay
-- correctly labeled (they were all SMS-only sends).
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='sms_campaign_recipients' AND COLUMN_NAME='channel');
SET @sql = IF(@col_exists = 0, "ALTER TABLE sms_campaign_recipients ADD COLUMN channel ENUM('sms','whatsapp') NOT NULL DEFAULT 'sms' AFTER phone", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;