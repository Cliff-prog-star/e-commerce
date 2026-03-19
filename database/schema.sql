-- ============================================================
-- FASHION HUB – Database Schema
-- Run this file once to initialise the database:
--   mysql -u root -p < database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS fashion_marketplace
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fashion_marketplace;

-- ----------------------------------------------------------------
-- Verified retailers
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retailers (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    full_name        VARCHAR(100)     NOT NULL,
    national_id      VARCHAR(25)      NOT NULL,
    phone            VARCHAR(25)      NOT NULL,
    email            VARCHAR(150)     NOT NULL,
    date_of_birth    DATE             NOT NULL,
    shop_name        VARCHAR(100)     NOT NULL,
    business_type    ENUM('individual','retail','wholesale') NOT NULL,
    county           VARCHAR(60)      NOT NULL,
    town             VARCHAR(60)      NOT NULL,
    shop_address     TEXT             NOT NULL,
    shop_map_url     VARCHAR(500)     DEFAULT NULL,
    phone_verified   TINYINT(1)       NOT NULL DEFAULT 0,
    email_verified   TINYINT(1)       NOT NULL DEFAULT 0,
    review_status    ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_notes     TEXT             DEFAULT NULL,
    reviewed_at      DATETIME         DEFAULT NULL,
    is_approved      TINYINT(1)       NOT NULL DEFAULT 0,
    registered_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email      (email),
    UNIQUE KEY uq_national_id (national_id),
    KEY idx_review_status (review_status),
    KEY idx_is_approved (is_approved)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- Legitimacy / verification documents submitted by retailers
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retailer_documents (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    retailer_id    INT UNSIGNED  NOT NULL,
    document_type  VARCHAR(50)   NOT NULL,
    file_path      VARCHAR(500)  NOT NULL,
    mime_type      VARCHAR(100)  NOT NULL,
    uploaded_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_retailer_document (retailer_id, document_type),
    KEY idx_document_type (document_type),
    CONSTRAINT fk_retailer_document_retailer
        FOREIGN KEY (retailer_id) REFERENCES retailers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- One-time phone OTP codes
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    phone       VARCHAR(25)   NOT NULL,
    otp_code    CHAR(6)       NOT NULL,
    expires_at  DATETIME      NOT NULL,
    used        TINYINT(1)    NOT NULL DEFAULT 0,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_phone   (phone),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- Email verification tokens
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_tokens (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    email       VARCHAR(150)  NOT NULL,
    token       CHAR(64)      NOT NULL,
    expires_at  DATETIME      NOT NULL,
    used        TINYINT(1)    NOT NULL DEFAULT 0,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_email   (email),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- Products listed by verified retailers
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    retailer_id   INT UNSIGNED       NOT NULL,
    retailer_name VARCHAR(100)       NOT NULL,
    name          VARCHAR(150)       NOT NULL,
    price         DECIMAL(10,2)      NOT NULL,
    category      ENUM('men','women','kids') NOT NULL,
    image_url     VARCHAR(500)       DEFAULT NULL,
    sizes         VARCHAR(100)       DEFAULT NULL,
    description   TEXT,
    posted_at     TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_category   (category),
    KEY idx_retailer   (retailer_id),
    KEY idx_posted_at  (posted_at),
    CONSTRAINT fk_product_retailer
        FOREIGN KEY (retailer_id) REFERENCES retailers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- Contact form messages
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    sender_name   VARCHAR(100)  NOT NULL,
    sender_email  VARCHAR(150)  NOT NULL,
    subject       VARCHAR(100)  NOT NULL,
    message       TEXT          NOT NULL,
    sent_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB;
