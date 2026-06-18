CREATE DATABASE IF NOT EXISTS kredit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kredit;

-- ── customers ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    email      VARCHAR(120)     NOT NULL,
    full_name  VARCHAR(120)     NOT NULL,
    country    CHAR(2)          NOT NULL DEFAULT 'US',
    segment    ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free',
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email),
    KEY idx_country_segment (country, segment),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB;

-- ── products ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    sku         VARCHAR(32)   NOT NULL,
    category    VARCHAR(48)   NOT NULL,
    name        VARCHAR(160)  NOT NULL,
    description TEXT,
    price_cents INT UNSIGNED  NOT NULL DEFAULT 0,
    stock       INT           NOT NULL DEFAULT 0,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sku (sku),
    KEY idx_category (category),
    KEY idx_price (price_cents)
) ENGINE=InnoDB;

-- ── orders ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED    NOT NULL,
    status      ENUM('pending','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    total_cents INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer (customer_id),
    KEY idx_status_created (status, created_at),
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB;

-- ── order_items ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         BIGINT UNSIGNED NOT NULL,
    product_id       INT UNSIGNED    NOT NULL,
    quantity         INT UNSIGNED    NOT NULL DEFAULT 1,
    unit_price_cents INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_order   (order_id),
    KEY idx_product (product_id),
    CONSTRAINT fk_item_order   FOREIGN KEY (order_id)   REFERENCES orders   (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- ── product_reviews ───────────────────────────────────────────────────────────
-- NOTE: no index on `comment` — intentional for slow LIKE scan demo
CREATE TABLE IF NOT EXISTS product_reviews (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED    NOT NULL,
    customer_id INT UNSIGNED    NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL DEFAULT 3,
    comment     TEXT,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_review_product  FOREIGN KEY (product_id)  REFERENCES products  (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB;

-- ── events_log ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS events_log (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    level    VARCHAR(16)     NOT NULL,
    category VARCHAR(64)     NOT NULL DEFAULT 'app',
    message  TEXT,
    trace_id CHAR(32),
    PRIMARY KEY (id),
    KEY idx_ts       (ts),
    KEY idx_level_ts (level, ts)
) ENGINE=InnoDB;
