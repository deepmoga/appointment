-- BookWA — WhatsApp Appointment Booking SaaS
-- Full Database Schema v1.0

CREATE DATABASE IF NOT EXISTS bookwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bookwa;

-- ─── BUSINESSES (core tenant table) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS businesses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(120) UNIQUE,
    email           VARCHAR(255) UNIQUE NOT NULL,
    password        VARCHAR(255) NOT NULL,
    phone           VARCHAR(30),
    business_type   ENUM('clinic','hospital','salon','spa','gym','restaurant','dental','legal','beauty','other') DEFAULT 'other',
    logo            VARCHAR(500),
    address         TEXT,
    city            VARCHAR(100),
    country         VARCHAR(100) DEFAULT 'US',
    timezone        VARCHAR(60)  DEFAULT 'UTC',
    currency        VARCHAR(10)  DEFAULT 'USD',
    pricing_mode    ENUM('per_service','fixed') DEFAULT 'per_service',
    fixed_price     DECIMAL(10,2) DEFAULT NULL,
    token_mode      ENUM('db_id','daily') DEFAULT 'db_id',
    time_required   TINYINT(1) NOT NULL DEFAULT 1,
    enable_parallel_bookings TINYINT(1) NOT NULL DEFAULT 0,
    subscription_plan ENUM('free','starter','pro','enterprise') DEFAULT 'free',
    subscription_ends_at TIMESTAMP NULL,
    wallet_balance          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_mode            ENUM('platform','own') NOT NULL DEFAULT 'platform',
    own_gateway_type        ENUM('razorpay','stripe') NULL,
    own_razorpay_key_id     VARCHAR(255) NULL,
    own_razorpay_key_secret VARCHAR(255) NULL,
    is_active       TINYINT(1) DEFAULT 1,
    email_verified_at TIMESTAMP NULL,
    verification_token VARCHAR(255),
    reset_token     VARCHAR(255),
    reset_expires   TIMESTAMP NULL,
    last_login      TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── SUPER ADMINS (platform-level administrators) ─────────────────────────────
CREATE TABLE IF NOT EXISTS super_admins (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    last_login  TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── PLATFORM SETTINGS (single-row, site-wide config) ─────────────────────────
CREATE TABLE IF NOT EXISTS platform_settings (
    id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    currency_symbol VARCHAR(5)   DEFAULT '$',
    contact_phone   VARCHAR(30)  DEFAULT '',
    contact_email   VARCHAR(255) DEFAULT '',
    demo_whatsapp   VARCHAR(30)  DEFAULT '',
    wa_verify_token        VARCHAR(255) DEFAULT '',
    rate_platform_gateway  DECIMAL(8,2) NOT NULL DEFAULT 20.00,
    rate_own_gateway       DECIMAL(8,2) NOT NULL DEFAULT 5.00,
    min_recharge_amount    DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    low_balance_alert      DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    razorpay_key_id        VARCHAR(255) NULL,
    razorpay_key_secret    VARCHAR(255) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO platform_settings (id, currency_symbol, contact_phone, contact_email, demo_whatsapp) VALUES
(1, '₹', '+91 97805-51900', 'gagansngh966@gmail.com', '+91 70096 21194');

-- ─── WHATSAPP INTEGRATION ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS whatsapp_configs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id         INT UNSIGNED NOT NULL,
    phone_number_id     VARCHAR(150),
    waba_id             VARCHAR(150),
    access_token        TEXT,
    webhook_verify_token VARCHAR(255),
    phone_number        VARCHAR(30),
    display_name        VARCHAR(255),
    is_connected        TINYINT(1) DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_business (business_id)
) ENGINE=InnoDB;

-- ─── BUSINESS HOURS ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS business_hours (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    day_of_week     TINYINT NOT NULL COMMENT '0=Sun 6=Sat',
    open_time       TIME,
    close_time      TIME,
    is_open         TINYINT(1) DEFAULT 1,
    slot_interval   INT DEFAULT 30 COMMENT 'minutes',
    break_start     TIME NULL,
    break_end       TIME NULL,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_day (business_id, day_of_week)
) ENGINE=InnoDB;

-- ─── STAFF ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255),
    phone           VARCHAR(30),
    role            VARCHAR(100),
    specialization  VARCHAR(255),
    avatar          VARCHAR(500),
    bio             TEXT,
    color           VARCHAR(7) DEFAULT '#6366f1',
    is_active       TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── STAFF SCHEDULES ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff_schedules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    is_working  TINYINT(1) DEFAULT 1,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    UNIQUE KEY uq_staff_day (staff_id, day_of_week)
) ENGINE=InnoDB;

-- ─── STAFF LEAVES ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff_leaves (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT UNSIGNED NOT NULL,
    leave_date  DATE NOT NULL,
    leave_type  ENUM('sick','vacation','personal','other') DEFAULT 'other',
    reason      VARCHAR(500),
    approved    TINYINT(1) DEFAULT 0,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── SERVICE CATEGORIES ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    icon        VARCHAR(80),
    color       VARCHAR(7) DEFAULT '#6366f1',
    image       VARCHAR(500),
    sort_order  INT DEFAULT 0,
    is_active   TINYINT(1) DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── SERVICES ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS services (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id      INT UNSIGNED NOT NULL,
    category_id      INT UNSIGNED NOT NULL,
    name             VARCHAR(255) NOT NULL,
    description      TEXT,
    duration         INT NOT NULL DEFAULT 30 COMMENT 'minutes',
    buffer_time      INT DEFAULT 0 COMMENT 'minutes after',
    price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_advance_days INT DEFAULT 30,
    image            VARCHAR(500),
    is_active        TINYINT(1) DEFAULT 1,
    sort_order       INT DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id)  REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id)  REFERENCES service_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── SERVICE ↔ STAFF (m:n) ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_staff (
    service_id  INT UNSIGNED NOT NULL,
    staff_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, staff_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id)   REFERENCES staff(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── HOLIDAYS / BLACKOUT DATES ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS holidays (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id  INT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    name         VARCHAR(255),
    is_recurring TINYINT(1) DEFAULT 0,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── CUSTOMERS (per business) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(255),
    phone           VARCHAR(30) NOT NULL,
    whatsapp_id     VARCHAR(60),
    email           VARCHAR(255),
    date_of_birth   DATE NULL,
    gender          ENUM('male','female','other','prefer_not') NULL,
    notes           TEXT,
    tags            VARCHAR(500),
    total_visits    INT DEFAULT 0,
    total_spent     DECIMAL(10,2) DEFAULT 0.00,
    loyalty_points  INT DEFAULT 0,
    is_blocked      TINYINT(1) DEFAULT 0,
    referral_source VARCHAR(100),
    language        VARCHAR(5) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_customer_phone (business_id, phone)
) ENGINE=InnoDB;

-- ─── APPOINTMENTS ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appointments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    daily_token      INT UNSIGNED NULL COMMENT 'Token number within the day, if token_mode=daily',
    business_id      INT UNSIGNED NOT NULL,
    customer_id      INT UNSIGNED NOT NULL,
    service_id       INT UNSIGNED NOT NULL,
    staff_id         INT UNSIGNED NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    end_time         TIME NOT NULL,
    duration         INT NOT NULL,
    status           ENUM('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending',
    customer_note    TEXT,
    admin_note       TEXT,
    total_price      DECIMAL(10,2),
    payment_status   ENUM('unpaid','paid','partial','refunded') DEFAULT 'unpaid',
    booking_source   ENUM('whatsapp','web','admin','phone') DEFAULT 'whatsapp',
    reminder_sent    TINYINT(1) DEFAULT 0,
    google_event_id  VARCHAR(255),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (service_id)  REFERENCES services(id),
    FOREIGN KEY (staff_id)    REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── WHATSAPP SESSIONS (bot state machine) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS whatsapp_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    customer_phone  VARCHAR(30) NOT NULL,
    session_state   VARCHAR(100) DEFAULT 'idle',
    session_data    JSON,
    appointment_id  INT UNSIGNED NULL,
    last_activity   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_session (business_id, customer_phone)
) ENGINE=InnoDB;

-- ─── WHATSAPP MESSAGE LOG ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    customer_phone  VARCHAR(30) NOT NULL,
    customer_id     INT UNSIGNED NULL,
    direction       ENUM('inbound','outbound') NOT NULL,
    message_type    ENUM('text','interactive','template','image','audio','document') DEFAULT 'text',
    content         TEXT,
    media_url       VARCHAR(500),
    wa_message_id   VARCHAR(255),
    is_read         TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── NOTIFICATIONS ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id  INT UNSIGNED NOT NULL,
    type         VARCHAR(100) NOT NULL,
    title        VARCHAR(255),
    message      TEXT,
    action_url   VARCHAR(500),
    is_read      TINYINT(1) DEFAULT 0,
    related_id   INT UNSIGNED NULL,
    related_type VARCHAR(60) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── MESSAGE TEMPLATES ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_templates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id   INT UNSIGNED NOT NULL,
    template_name VARCHAR(120) NOT NULL,
    template_type ENUM('welcome','booking_confirm','booking_cancel','reminder_24h','reminder_1h','follow_up','review_request','doctor_available','custom') NOT NULL,
    content       TEXT NOT NULL,
    variables     JSON COMMENT 'e.g. {{customer_name}}, {{service_name}}',
    language      VARCHAR(10) DEFAULT 'en',
    is_active     TINYINT(1) DEFAULT 1,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── REVIEWS ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    appointment_id  INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    rating          TINYINT NOT NULL,
    comment         TEXT,
    is_published    TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id)    REFERENCES businesses(id)    ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id)  ON DELETE CASCADE,
    FOREIGN KEY (customer_id)    REFERENCES customers(id)
) ENGINE=InnoDB;

-- ─── WAITLIST ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS waitlist (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id     INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    service_id      INT UNSIGNED NOT NULL,
    staff_id        INT UNSIGNED NULL,
    preferred_date  DATE,
    preferred_time  TIME,
    status          ENUM('waiting','notified','booked','cancelled') DEFAULT 'waiting',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (service_id)  REFERENCES services(id)
) ENGINE=InnoDB;

-- ─── AVAILABILITY ALERTS (doctor/staff available notifications) ───────────────
CREATE TABLE IF NOT EXISTS availability_alerts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id  INT UNSIGNED NOT NULL,
    customer_id  INT UNSIGNED NOT NULL,
    staff_id     INT UNSIGNED NULL,
    notify_date  DATE NOT NULL,
    status       ENUM('waiting','sent') DEFAULT 'waiting',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_biz_date (business_id, notify_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── WALLET TRANSACTIONS ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id    INT UNSIGNED NOT NULL,
    type           ENUM('credit','debit') NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    description    VARCHAR(255),
    appointment_id INT UNSIGNED NULL,
    reference_id   VARCHAR(255) NULL COMMENT 'Razorpay payment_id',
    balance_after  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_business_created (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── RECHARGE PACKAGES ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS recharge_packages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL COMMENT 'INR amount client pays',
    credits     DECIMAL(10,2) NOT NULL COMMENT 'wallet credits added',
    bonus       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255),
    is_popular  TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO recharge_packages (name, amount, credits, bonus, description, is_popular, sort_order) VALUES
('Starter',    500.00,  500.00,    0.00, '25 bookings via platform gateway',  0, 1),
('Popular',   1000.00, 1000.00,  100.00, '10% bonus — 55 bookings',           1, 2),
('Business',  2500.00, 2500.00,  300.00, '12% bonus — best for growing biz',  0, 3),
('Enterprise',5000.00, 5000.00, 1000.00, '20% bonus — lowest cost/booking',   0, 4);

-- ─── SUBSCRIPTION PLANS ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_plans (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                        VARCHAR(100) NOT NULL,
    slug                        VARCHAR(50) UNIQUE NOT NULL,
    price_monthly               DECIMAL(10,2) NOT NULL,
    price_yearly                DECIMAL(10,2) NOT NULL,
    max_staff                   INT DEFAULT 1  COMMENT '-1 = unlimited',
    max_services                INT DEFAULT 10 COMMENT '-1 = unlimited',
    max_appointments_per_month  INT DEFAULT 100 COMMENT '-1 = unlimited',
    features                    JSON,
    is_active                   TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO subscription_plans (name, slug, price_monthly, price_yearly, max_staff, max_services, max_appointments_per_month, features) VALUES
('Starter',      'starter',    9.99,   99.99,   1,  10,   100,  '["WhatsApp Booking Bot","Service Catalog","Basic Analytics","Email Notifications","1 Staff Member"]'),
('Professional', 'pro',       29.99,  299.99,   5,  50,   500,  '["Everything in Starter","5 Staff Members","Advanced Analytics","Google Calendar Sync","Custom Message Templates","Waitlist Management","Priority Support"]'),
('Enterprise',   'enterprise', 79.99, 799.99,  -1,  -1,    -1,  '["Everything in Pro","Unlimited Staff & Services","API Access","White-label Option","Online Payments","Multi-language Bot","Dedicated Account Manager"]');
