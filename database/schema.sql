-- ============================================================
-- BraidedbyAGB — Full Database Schema
-- FILE: /database/schema.sql
-- Run this in cPanel → phpMyAdmin on database: jussxvwc_braidedbyagb
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ── CUSTOMERS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)  NOT NULL,
    email         VARCHAR(180)  NOT NULL UNIQUE,
    phone         VARCHAR(20)   DEFAULT NULL,
    email_optin   TINYINT(1)    DEFAULT 1,
    notes         TEXT          DEFAULT NULL,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SERVICES ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS services (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)  NOT NULL,
    slug          VARCHAR(140)  NOT NULL UNIQUE,
    description   TEXT          DEFAULT NULL,
    price_from    DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    duration_mins INT UNSIGNED  NOT NULL DEFAULT 60,
    category      VARCHAR(80)   DEFAULT NULL,
    image_url     VARCHAR(255)  DEFAULT NULL,
    prep_notes    TEXT          DEFAULT NULL,
    aftercare     TEXT          DEFAULT NULL,
    is_new        TINYINT(1)    DEFAULT 0,
    is_active     TINYINT(1)    DEFAULT 1,
    display_order INT UNSIGNED  DEFAULT 0,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SERVICE VARIANTS (Short/Medium/Long etc.) ───────────────
CREATE TABLE IF NOT EXISTS service_variants (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id    INT UNSIGNED  NOT NULL,
    variant_name  VARCHAR(80)   NOT NULL,
    price         DECIMAL(8,2)  NOT NULL,
    duration_mins INT UNSIGNED  DEFAULT NULL,
    display_order INT UNSIGNED  DEFAULT 0,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SERVICE ADD-ONS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_addons (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id    INT UNSIGNED  NOT NULL,
    name          VARCHAR(120)  NOT NULL,
    price         DECIMAL(8,2)  NOT NULL,
    is_active     TINYINT(1)    DEFAULT 1,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRODUCTS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)  NOT NULL,
    slug          VARCHAR(160)  NOT NULL UNIQUE,
    description   TEXT          DEFAULT NULL,
    price         DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    category      VARCHAR(80)   DEFAULT NULL,
    image_url     VARCHAR(255)  DEFAULT NULL,
    free_gift     TINYINT(1)    DEFAULT 0,
    free_gift_desc VARCHAR(255) DEFAULT NULL,
    is_active     TINYINT(1)    DEFAULT 1,
    display_order INT UNSIGNED  DEFAULT 0,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRODUCT VARIANTS (colour/size) ─────────────────────────
CREATE TABLE IF NOT EXISTS product_variants (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED  NOT NULL,
    colour        VARCHAR(80)   DEFAULT NULL,
    size          VARCHAR(80)   DEFAULT NULL,
    sku           VARCHAR(80)   DEFAULT NULL UNIQUE,
    stock_qty     INT UNSIGNED  DEFAULT 0,
    low_stock_alert INT UNSIGNED DEFAULT 3,
    display_order INT UNSIGNED  DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SERVICE–PRODUCT PIPELINE ────────────────────────────────
-- Maps every service to one or more recommended products
CREATE TABLE IF NOT EXISTS service_product_links (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id    INT UNSIGNED  NOT NULL,
    product_id    INT UNSIGNED  NOT NULL,
    display_label VARCHAR(255)  DEFAULT NULL,  -- Custom label e.g. "You'll need this for your style"
    display_order INT UNSIGNED  DEFAULT 0,
    is_active     TINYINT(1)    DEFAULT 1,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_link (service_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── AVAILABILITY ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS availability (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    avail_date    DATE          NOT NULL,
    time_slot     TIME          NOT NULL,
    is_blocked    TINYINT(1)    DEFAULT 0,
    block_reason  VARCHAR(255)  DEFAULT NULL,
    UNIQUE KEY unique_slot (avail_date, time_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── BOOKINGS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bookings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_ref      VARCHAR(12)  NOT NULL UNIQUE,  -- e.g. AGB-2026-0001
    customer_id      INT UNSIGNED NOT NULL,
    service_id       INT UNSIGNED NOT NULL,
    variant_id       INT UNSIGNED DEFAULT NULL,
    booked_date      DATE         NOT NULL,
    booked_time      TIME         NOT NULL,
    status           ENUM('pending','confirmed','rejected','completed','no_show','late_cancelled') DEFAULT 'pending',
    payment_method   ENUM('stripe','bank_transfer')  NOT NULL,
    deposit_amount   DECIMAL(8,2) DEFAULT 0.00,
    deposit_paid     TINYINT(1)   DEFAULT 0,
    total_price      DECIMAL(8,2) DEFAULT 0.00,
    remaining_balance DECIMAL(8,2) DEFAULT 0.00,
    client_notes     TEXT         DEFAULT NULL,
    admin_notes      TEXT         DEFAULT NULL,
    late_arrival     TINYINT(1)   DEFAULT 0,
    policy_accepted  TINYINT(1)   DEFAULT 0,   -- Late arrival + cancellation policy checkbox
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (service_id)  REFERENCES services(id),
    FOREIGN KEY (variant_id)  REFERENCES service_variants(id),
    INDEX idx_date   (booked_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── BOOKING ADD-ONS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS booking_addons (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT UNSIGNED  NOT NULL,
    addon_id      INT UNSIGNED  NOT NULL,
    price_charged DECIMAL(8,2)  NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (addon_id)   REFERENCES service_addons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDERS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_ref      VARCHAR(12)  NOT NULL UNIQUE,   -- e.g. AGB-ORD-0001
    customer_id    INT UNSIGNED NOT NULL,
    booking_id     INT UNSIGNED DEFAULT NULL,       -- If order came from a booking pipeline recommendation
    subtotal       DECIMAL(8,2) DEFAULT 0.00,
    discount_amount DECIMAL(8,2) DEFAULT 0.00,
    total          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    status         ENUM('pending','processing','dispatched','delivered','collected','cancelled') DEFAULT 'pending',
    delivery_type  ENUM('shipping','local_pickup') NOT NULL DEFAULT 'shipping',
    delivery_address TEXT        DEFAULT NULL,
    payment_method ENUM('stripe','bank_transfer')  NOT NULL,
    payment_confirmed TINYINT(1) DEFAULT 0,
    discount_code  VARCHAR(50)  DEFAULT NULL,
    notes          TEXT         DEFAULT NULL,
    created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (booking_id)  REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDER ITEMS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED  NOT NULL,
    product_id    INT UNSIGNED  NOT NULL,
    variant_id    INT UNSIGNED  DEFAULT NULL,
    quantity      INT UNSIGNED  NOT NULL DEFAULT 1,
    price_charged DECIMAL(8,2)  NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PAYMENTS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT UNSIGNED  DEFAULT NULL,
    order_id      INT UNSIGNED  DEFAULT NULL,
    stripe_id     VARCHAR(120)  DEFAULT NULL,   -- Stripe payment_intent ID
    amount        DECIMAL(8,2)  NOT NULL,
    currency      CHAR(3)       DEFAULT 'GBP',
    type          ENUM('deposit','full','partial') NOT NULL,
    method        ENUM('stripe','bank_transfer')   NOT NULL,
    status        ENUM('pending','succeeded','failed','refunded') DEFAULT 'pending',
    confirmed_by  VARCHAR(80)   DEFAULT NULL,  -- 'stripe_webhook' or 'admin'
    confirmed_at  DATETIME      DEFAULT NULL,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (order_id)   REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── REVIEWS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT UNSIGNED  NOT NULL,
    booking_id    INT UNSIGNED  DEFAULT NULL,
    order_id      INT UNSIGNED  DEFAULT NULL,
    service_id    INT UNSIGNED  DEFAULT NULL,
    product_id    INT UNSIGNED  DEFAULT NULL,
    review_type   ENUM('service','product') NOT NULL DEFAULT 'service',
    rating        TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
    review_text   TEXT          NOT NULL,
    photo_url     VARCHAR(255)  DEFAULT NULL,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    is_featured   TINYINT(1)    DEFAULT 0,
    feature_position VARCHAR(50) DEFAULT NULL,  -- 'homepage', 'service_page' etc.
    admin_note    VARCHAR(255)  DEFAULT NULL,
    submitted_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    approved_at   DATETIME      DEFAULT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (booking_id)  REFERENCES bookings(id),
    FOREIGN KEY (order_id)    REFERENCES orders(id),
    FOREIGN KEY (service_id)  REFERENCES services(id),
    FOREIGN KEY (product_id)  REFERENCES products(id),
    INDEX idx_status  (status),
    INDEX idx_service (service_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── REVIEW REQUESTS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS review_requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT UNSIGNED  NOT NULL,
    booking_id    INT UNSIGNED  DEFAULT NULL,
    order_id      INT UNSIGNED  DEFAULT NULL,
    review_type   ENUM('service','product') NOT NULL DEFAULT 'service',
    token         VARCHAR(64)   NOT NULL UNIQUE,   -- Secure one-time link token
    sent_at       DATETIME      DEFAULT NULL,
    opened_at     DATETIME      DEFAULT NULL,
    submitted_at  DATETIME      DEFAULT NULL,
    expires_at    DATETIME      DEFAULT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (booking_id)  REFERENCES bookings(id),
    FOREIGN KEY (order_id)    REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── DISCOUNT CODES ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS discount_codes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(50)   NOT NULL UNIQUE,
    type          ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    value         DECIMAL(8,2)  NOT NULL,
    min_spend     DECIMAL(8,2)  DEFAULT 0.00,
    uses_limit    INT UNSIGNED  DEFAULT NULL,   -- NULL = unlimited
    uses_count    INT UNSIGNED  DEFAULT 0,
    applies_to    ENUM('all','services','products') DEFAULT 'all',
    customer_id   INT UNSIGNED  DEFAULT NULL,   -- NULL = any customer; set for single-use review incentive codes
    expiry_date   DATE          DEFAULT NULL,
    is_active     TINYINT(1)    DEFAULT 1,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ADMIN USERS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(180)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    name          VARCHAR(120)  DEFAULT NULL,
    last_login    DATETIME      DEFAULT NULL,
    login_attempts INT UNSIGNED DEFAULT 0,
    locked_until  DATETIME      DEFAULT NULL,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SITE SETTINGS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(80)   NOT NULL PRIMARY KEY,
    setting_value TEXT          DEFAULT NULL,
    updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── DEFAULT SETTINGS ────────────────────────────────────────
INSERT INTO settings (setting_key, setting_value) VALUES
    ('deposit_percent',           '30'),
    ('cancellation_hours',        '48'),
    ('late_arrival_mins',         '20'),
    ('bank_transfer_hold_hours',  '24'),
    ('review_delay_hours',        '3'),
    ('product_review_delay_days', '2'),
    ('bank_account_name',         'BraidedbyAGB'),
    ('bank_sort_code',            ''),       -- ← Set in Admin Portal Settings
    ('bank_account_number',       ''),       -- ← Set in Admin Portal Settings
    ('instagram_handle',          '@BraidedbyAGB'),
    ('instagram_url',             'https://instagram.com/BraidedbyAGB'),
    ('tiktok_handle',             ''),       -- ← Confirm with client
    ('tiktok_url',                ''),
    ('facebook_handle',           ''),       -- ← Confirm with client
    ('facebook_url',              ''),
    ('whatsapp_number',           '07769064971'),
    ('review_incentive_enabled',  '0'),
    ('review_incentive_discount', '10'),     -- 10% off for leaving a review
    ('site_tagline',              'African Hair Braiding Specialist'),
    ('site_address',              'Farnborough, Hampshire, UK')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ── SEED: DEFAULT ADMIN USER ────────────────────────────────
-- Password: ChangeMe2026! (hashed below — CHANGE IMMEDIATELY after first login)
INSERT INTO admin_users (email, name, password_hash) VALUES (
    'claude@braidedbyagb.co.uk',
    'BraidedbyAGB Admin',
    '$2y$12$placeholder.hash.will.be.generated.on.first.setup'
) ON DUPLICATE KEY UPDATE email = email;

-- ── SEED: ALL SERVICES ──────────────────────────────────────
INSERT INTO services (name, slug, description, price_from, duration_mins, category, is_new, display_order) VALUES
    ('Box Braids',           'box-braids',           'Timeless classic box braids in all lengths, thicknesses and colours.',       90.00,  180, 'Braids',    0, 1),
    ('Knotless Braids',      'knotless-braids',      'A gentler, natural-looking braid starting with your own hair.',             100.00, 180, 'Braids',    0, 2),
    ('Feed-In Braids',       'feed-in-braids',       'Seamlessly added extensions for a flawless, natural finish.',               70.00,  120, 'Braids',    0, 3),
    ('Cornrows (Simple)',    'cornrows-simple',      'Classic straight-back cornrows, neat and versatile.',                       35.00,   60, 'Cornrows',  0, 4),
    ('Cornrows with Extensions','cornrows-extensions','Creative cornrow patterns with premium extensions added.',                  55.00,   90, 'Cornrows',  0, 5),
    ('Twists (Passion/Marley)','twists',             'Elegant rope-like twists — Passion or Marley style.',                      80.00,  180, 'Twists',    0, 6),
    ('Kids Styles',          'kids-styles',          'Gentle, beautiful braided styles designed especially for children.',        35.00,   60, 'Kids',      0, 7),
    ('Starter Locs',         'starter-locs',         'Begin your loc journey with either twist or interlock method.',             80.00,  180, 'Locs',      1, 8),
    ('Loc Retwists',         'loc-retwists',         'Professional loc maintenance — retwist, root tightening and treatment.',    45.00,   90, 'Locs',      1, 9)
ON DUPLICATE KEY UPDATE name = name;

-- ── SEED: SERVICE VARIANTS ──────────────────────────────────
INSERT INTO service_variants (service_id, variant_name, price, duration_mins, display_order)
SELECT s.id, v.variant_name, v.price, v.duration_mins, v.display_order
FROM services s
JOIN (
    SELECT 'box-braids' as slug, 'Medium' as variant_name, 90.00 as price, 180 as duration_mins, 1 as display_order UNION ALL
    SELECT 'box-braids', 'Small', 110.00, 300, 2 UNION ALL
    SELECT 'knotless-braids', 'Medium', 100.00, 180, 1 UNION ALL
    SELECT 'knotless-braids', 'Small', 120.00, 360, 2 UNION ALL
    SELECT 'cornrows-simple', 'Simple', 35.00, 60, 1 UNION ALL
    SELECT 'starter-locs', 'Short', 80.00, 180, 1 UNION ALL
    SELECT 'starter-locs', 'Medium', 100.00, 240, 2 UNION ALL
    SELECT 'starter-locs', 'Long', 120.00, 300, 3 UNION ALL
    SELECT 'loc-retwists', 'Short / Thin', 45.00, 90, 1 UNION ALL
    SELECT 'loc-retwists', 'Medium', 65.00, 150, 2 UNION ALL
    SELECT 'loc-retwists', 'Long / Thick', 75.00, 180, 3
) v ON s.slug = v.slug
ON DUPLICATE KEY UPDATE price = v.price;

-- ── SEED: ADD-ONS ───────────────────────────────────────────
INSERT INTO service_addons (service_id, name, price)
SELECT s.id, a.name, a.price
FROM services s
JOIN (
    SELECT 'box-braids' as slug, 'Wash & Prep' as name, 15.00 as price UNION ALL
    SELECT 'box-braids', 'Deep Condition', 20.00 UNION ALL
    SELECT 'knotless-braids', 'Wash & Prep', 15.00 UNION ALL
    SELECT 'knotless-braids', 'Deep Condition', 20.00 UNION ALL
    SELECT 'starter-locs', 'Wash & Prep', 15.00 UNION ALL
    SELECT 'starter-locs', 'Scalp Treatment', 15.00 UNION ALL
    SELECT 'loc-retwists', 'Loc Moisturising Treatment', 10.00
) a ON s.slug = a.slug;

SET foreign_key_checks = 1;
