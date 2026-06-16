-- ─── BookWA Wallet System Migration v1.1 ─────────────────────────────────────
-- Run this on existing installations. Fresh installs use database.sql directly.

-- 1. Add wallet + payment gateway columns to businesses
ALTER TABLE businesses
    ADD COLUMN IF NOT EXISTS wallet_balance          DECIMAL(10,2) NOT NULL DEFAULT 0.00      AFTER subscription_ends_at,
    ADD COLUMN IF NOT EXISTS payment_mode            ENUM('platform','own') NOT NULL DEFAULT 'platform' AFTER wallet_balance,
    ADD COLUMN IF NOT EXISTS own_gateway_type        ENUM('razorpay','stripe') NULL            AFTER payment_mode,
    ADD COLUMN IF NOT EXISTS own_razorpay_key_id     VARCHAR(255) NULL                         AFTER own_gateway_type,
    ADD COLUMN IF NOT EXISTS own_razorpay_key_secret VARCHAR(255) NULL                         AFTER own_razorpay_key_id;

-- 2. Wallet transaction log
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id    INT UNSIGNED NOT NULL,
    type           ENUM('credit','debit') NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    description    VARCHAR(255),
    appointment_id INT UNSIGNED NULL,
    reference_id   VARCHAR(255) NULL COMMENT 'Razorpay payment_id or order_id',
    balance_after  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_business_created (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Recharge packages (superadmin manages)
CREATE TABLE IF NOT EXISTS recharge_packages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL COMMENT 'INR amount client pays',
    credits     DECIMAL(10,2) NOT NULL COMMENT 'wallet credits added',
    bonus       DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'extra bonus credits',
    description VARCHAR(255),
    is_popular  TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO recharge_packages (id, name, amount, credits, bonus, description, is_popular, sort_order) VALUES
(1, 'Starter',    500.00,  500.00,    0.00, '25 bookings via platform gateway',  0, 1),
(2, 'Popular',   1000.00, 1000.00,  100.00, '10% bonus — 55 bookings',           1, 2),
(3, 'Business',  2500.00, 2500.00,  300.00, '12% bonus — best for growing biz',  0, 3),
(4, 'Enterprise',5000.00, 5000.00, 1000.00, '20% bonus — lowest cost/booking',   0, 4);

-- 4. Add pricing + Razorpay columns to platform_settings
ALTER TABLE platform_settings
    ADD COLUMN IF NOT EXISTS rate_platform_gateway DECIMAL(8,2) NOT NULL DEFAULT 20.00 AFTER wa_verify_token,
    ADD COLUMN IF NOT EXISTS rate_own_gateway      DECIMAL(8,2) NOT NULL DEFAULT 5.00  AFTER rate_platform_gateway,
    ADD COLUMN IF NOT EXISTS min_recharge_amount   DECIMAL(10,2) NOT NULL DEFAULT 100.00 AFTER rate_own_gateway,
    ADD COLUMN IF NOT EXISTS low_balance_alert     DECIMAL(10,2) NOT NULL DEFAULT 100.00 AFTER min_recharge_amount,
    ADD COLUMN IF NOT EXISTS razorpay_key_id       VARCHAR(255) NULL AFTER low_balance_alert,
    ADD COLUMN IF NOT EXISTS razorpay_key_secret   VARCHAR(255) NULL AFTER razorpay_key_id;
