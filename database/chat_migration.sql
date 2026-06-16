-- ============================================================
-- BraidedbyAGB — Live Chat Schema
-- Run once via phpMyAdmin or cPanel MySQL
-- ============================================================

CREATE TABLE IF NOT EXISTS chat_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    uuid           VARCHAR(36)   NOT NULL UNIQUE,
    customer_name  VARCHAR(100)  DEFAULT NULL,
    customer_email VARCHAR(150)  DEFAULT NULL,
    status         ENUM('active','closed') DEFAULT 'active',
    unread_admin   INT           DEFAULT 0,
    last_msg       TEXT          DEFAULT NULL,
    last_msg_at    DATETIME      DEFAULT NULL,
    created_at     DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX (status),
    INDEX (last_msg_at)
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    sender     ENUM('customer','admin') NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (session_id),
    INDEX (created_at)
);

CREATE TABLE IF NOT EXISTS fcm_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    token      VARCHAR(512) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
