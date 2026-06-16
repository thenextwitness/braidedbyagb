-- ============================================================
-- BraidedbyAGB — Schema Additions for Cron Tracking
-- FILE: /database/migrations.sql
-- Run after schema.sql in phpMyAdmin
-- ============================================================

-- Add cron tracking columns to bookings
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS reminder_24_sent    TINYINT(1) DEFAULT 0 AFTER admin_notes,
    ADD COLUMN IF NOT EXISTS reminder_2_sent     TINYINT(1) DEFAULT 0 AFTER reminder_24_sent,
    ADD COLUMN IF NOT EXISTS review_request_sent TINYINT(1) DEFAULT 0 AFTER reminder_2_sent;

-- Add cron tracking columns to orders
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS review_request_sent TINYINT(1) DEFAULT 0 AFTER notes;

-- Add low stock alert tracking to product variants
ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS low_stock_alerted_at DATETIME DEFAULT NULL AFTER low_stock_alert;

-- Add pipeline-originated flag to orders
-- (tracks whether the order came from a booking recommendation)
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS from_pipeline TINYINT(1) DEFAULT 0 AFTER booking_id;

-- ── Phase 2 Fix: Add receipt_url to bookings (run once) ──
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS receipt_url VARCHAR(255) DEFAULT NULL AFTER client_notes;

-- ── Widen booking_ref column (run once) ──────────────────────
ALTER TABLE bookings MODIFY COLUMN booking_ref VARCHAR(20) NOT NULL;

-- ── Make availability.time_slot nullable (NULL = full-day block, TIME = single slot) ──
-- Run once in phpMyAdmin
ALTER TABLE availability MODIFY COLUMN time_slot TIME DEFAULT NULL;

-- ── Phase 4: Admin Email Notifications (run once) ──────────────────────────
-- Tracks whether the 30-min pre-appointment admin alert has been sent per booking
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS admin_reminder_30_sent TINYINT(1) DEFAULT 0 AFTER review_request_sent;
-- Settings table already holds admin_morning_brief_last_sent and
-- admin_evening_preview_last_sent — no schema change needed for those.

-- ── Phase 3: Admin Payment Link columns (run once) ──────────────────────────
-- payment_token: unique hex token for direct payment links sent to clients
-- payment_method_allowed: what the client can pay with on the /pay page
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_token          VARCHAR(64)                          DEFAULT NULL AFTER receipt_url,
    ADD COLUMN IF NOT EXISTS payment_method_allowed ENUM('stripe','bank_transfer','both') DEFAULT 'both' AFTER payment_token;
-- Add unique index on token (safe to re-run: will error if already exists — ignore)
-- ALTER TABLE bookings ADD UNIQUE KEY ux_payment_token (payment_token);

-- ── STUB DATA CLEANUP (DISABLED — site already has real bookings) ─────────────
-- This block was for first-time setup only. DO NOT run on a live site with bookings,
-- as it will fail with FK constraint errors (bookings reference service_variants).
-- Left here for reference only — all lines are commented out.
-- DELETE FROM service_product_links WHERE service_id IN (SELECT id FROM services);
-- DELETE FROM booking_addons WHERE booking_id IN (
--     SELECT id FROM bookings WHERE service_id IN (SELECT id FROM services)
-- );
-- DELETE FROM service_variants WHERE service_id IN (SELECT id FROM services);
-- DELETE FROM service_addons WHERE service_id IN (SELECT id FROM services);
-- DELETE FROM services WHERE id NOT IN (SELECT DISTINCT service_id FROM bookings WHERE service_id IS NOT NULL);

-- ── Admin tokens table (mobile app auth — no JWT library needed) ──────────────
CREATE TABLE IF NOT EXISTS admin_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    admin_id   INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Phase 5: Loyalty Points, CRM & Accounting (run once)
-- ============================================================

-- ── CRM additions to customers ────────────────────────────
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS loyalty_points INT DEFAULT 0          AFTER email_optin,
    ADD COLUMN IF NOT EXISTS tags           VARCHAR(255) DEFAULT NULL AFTER loyalty_points,
    ADD COLUMN IF NOT EXISTS is_blocked     TINYINT(1)  DEFAULT 0  AFTER tags,
    ADD COLUMN IF NOT EXISTS block_reason   TEXT        DEFAULT NULL AFTER is_blocked,
    ADD COLUMN IF NOT EXISTS blocked_at     DATETIME    DEFAULT NULL AFTER block_reason,
    ADD COLUMN IF NOT EXISTS hair_notes     TEXT        DEFAULT NULL AFTER blocked_at;

-- ── Loyalty & archive columns on bookings ────────────────
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS loyalty_points_redeemed INT           DEFAULT 0    AFTER payment_method_allowed,
    ADD COLUMN IF NOT EXISTS loyalty_discount        DECIMAL(10,2) DEFAULT 0.00 AFTER loyalty_points_redeemed,
    ADD COLUMN IF NOT EXISTS is_archived             TINYINT(1)    DEFAULT 0    AFTER loyalty_discount;

-- ── Loyalty transaction log ───────────────────────────────
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    booking_id  INT DEFAULT NULL,
    type        ENUM('earn','redeem','manual_add','manual_remove','expire') NOT NULL,
    points      INT NOT NULL,
    description VARCHAR(255),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE SET NULL
);

-- ── Customer admin notes ──────────────────────────────────
CREATE TABLE IF NOT EXISTS customer_notes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    note        TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- ── Chart of accounts ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS accounts (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(10)  NOT NULL UNIQUE,
    name      VARCHAR(100) NOT NULL,
    type      ENUM('asset','liability','equity','income','expense') NOT NULL,
    is_active TINYINT(1) DEFAULT 1
);

INSERT IGNORE INTO accounts (code, name, type) VALUES
    ('1000', 'Stripe Account',           'asset'),
    ('1020', 'Bank Account',             'asset'),
    ('2000', 'Customer Deposits Held',   'liability'),
    ('4000', 'Service Revenue',          'income'),
    ('4020', 'Late Cancellation Fees',   'income'),
    ('5000', 'Cost of Sales',            'expense'),
    ('5100', 'Business Expenses',        'expense'),
    ('5200', 'Owner\'s Draw',            'expense');

-- ── Journal entries (double-entry header) ────────────────
CREATE TABLE IF NOT EXISTS journal_entries (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    entry_date  DATE         NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference   VARCHAR(50)  DEFAULT NULL,
    source      ENUM('booking_payment','expense','owner_draw','manual') NOT NULL,
    source_id   INT          DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ── Journal entry lines (debit / credit) ─────────────────
CREATE TABLE IF NOT EXISTS journal_entry_lines (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT           NOT NULL,
    account_id       INT           NOT NULL,
    debit            DECIMAL(10,2) DEFAULT 0.00,
    credit           DECIMAL(10,2) DEFAULT 0.00,
    memo             VARCHAR(255)  DEFAULT NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)       REFERENCES accounts(id)
);

-- ── Expenses log ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE          NOT NULL,
    description  VARCHAR(255)  NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    category     VARCHAR(100)  DEFAULT 'Business Expenses',
    notes        TEXT          DEFAULT NULL,
    created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP
);

-- ── Owner draws log ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS owner_draws (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    draw_date DATE          NOT NULL,
    amount    DECIMAL(10,2) NOT NULL,
    notes     TEXT          DEFAULT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ── Loyalty settings (safe re-run) ───────────────────────
INSERT INTO settings (setting_key, setting_value) VALUES
    ('loyalty_enabled',   '1'),
    ('loyalty_earn_rate', '1'),
    ('loyalty_redeem_rate','100'),
    ('loyalty_min_redeem','500')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ── Global Add-ons (run once) ────────────────────────────────────────────────
-- Makes service_id nullable so add-ons can exist without a specific service.
-- is_global=1 means the add-on appears on every service at booking time.
ALTER TABLE service_addons
    MODIFY COLUMN service_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- ── Backfill: convert all existing per-service add-ons to global (run once) ──
-- After running the ALTER above, this makes all existing add-ons global so they
-- appear on every service at booking. Safe to re-run (idempotent).
UPDATE service_addons
SET service_id = NULL, is_global = 1
WHERE service_id IS NOT NULL;

-- ── Backfill: auto-complete all past bookings (run once) ─────────────────────
-- Marks every confirmed/pending booking whose appointment date has already
-- passed as completed. Matches the cron auto-complete logic.
UPDATE bookings
SET status = 'completed'
WHERE status IN ('confirmed', 'pending')
  AND booked_date < CURDATE();

-- ── Booking duration override (run once) ────────────────────────────────────
-- Allows admin to set/override the actual job duration per booking.
-- NULL = fall back to services.duration_mins or service_variants.duration_mins.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS duration_mins INT DEFAULT NULL AFTER booked_time;

-- ── Custom Style bookings (run once) ─────────────────────────────────────────
-- custom_style_name: the style name the admin types (e.g. "Senegalese Twists")
-- custom_style_desc: optional description / notes about the style
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS custom_style_name VARCHAR(255) DEFAULT NULL AFTER admin_notes,
    ADD COLUMN IF NOT EXISTS custom_style_desc  TEXT        DEFAULT NULL AFTER custom_style_name;

-- Sentinel service row used as FK target for custom-style bookings.
-- is_active=0 means it never appears on the public booking page.
INSERT INTO services (name, slug, description, price_from, duration_mins, is_active, display_order)
SELECT 'Custom Style', 'custom-style', 'Admin-created custom style booking', 0, 60, 0, 9999
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Custom Style');

-- ── Add-on exclusions (run once) ─────────────────────────────────────────────
-- Tracks which global add-ons are excluded from specific services.
CREATE TABLE IF NOT EXISTS service_addon_exclusions (
    service_id INT NOT NULL,
    addon_id   INT NOT NULL,
    PRIMARY KEY (service_id, addon_id)
);

-- ── Multi-booking cart (run once) ────────────────────────────────────────────
-- guest_name:     who the appointment is for (NULL = the paying customer themselves).
-- cart_group_ref: shared reference linking all bookings made together in one
--                 checkout, so a family booking can be grouped in admin + emails.
--                 Each booking still keeps its own unique booking_ref.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS guest_name     VARCHAR(120) DEFAULT NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS cart_group_ref VARCHAR(24)  DEFAULT NULL AFTER booking_ref;

-- Index for grouping lookups (safe to re-run: ignore "Duplicate key name" error).
-- ALTER TABLE bookings ADD INDEX idx_cart_group (cart_group_ref);

-- ── Review fix: add the 'cancelled' status the code already writes (run once) ─
-- admin/bookings.php, admin/booking-detail.php and api/admin.php all set
-- status='cancelled', but the original ENUM omitted it. On a strict-mode MySQL
-- those updates fail and the slot never frees. This adds the missing value.
ALTER TABLE bookings MODIFY COLUMN status
    ENUM('pending','confirmed','cancelled','rejected','completed','no_show','late_cancelled')
    DEFAULT 'pending';
