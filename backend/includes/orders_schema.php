<?php
/**
 * Orders schema guard.
 * Ensures the orders table exists for legacy databases that predate this feature.
 */

function ensureOrdersTable(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS orders (
            id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            buyer_name         VARCHAR(100)     NOT NULL,
            buyer_email        VARCHAR(150)     NOT NULL,
            seller_id          INT UNSIGNED     NOT NULL,
            seller_name        VARCHAR(100)     NOT NULL,
            product_id         INT UNSIGNED     NOT NULL,
            product_name       VARCHAR(150)     NOT NULL,
            amount             DECIMAL(10,2)    NOT NULL,
            payment_status     ENUM('pending','paid','held','released') NOT NULL DEFAULT 'pending',
            delivery_status    ENUM('pending','shipped','delivered') NOT NULL DEFAULT 'pending',
            callback_confirmed TINYINT(1)       NOT NULL DEFAULT 0,
            callback_reference VARCHAR(80)      DEFAULT NULL,
            paid_at            DATETIME         DEFAULT NULL,
            held_at            DATETIME         DEFAULT NULL,
            shipped_at         DATETIME         DEFAULT NULL,
            delivered_at       DATETIME         DEFAULT NULL,
            released_at        DATETIME         DEFAULT NULL,
            created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_buyer_created (buyer_email, created_at),
            KEY idx_seller_created (seller_id, created_at),
            KEY idx_payment_delivery (payment_status, delivery_status),
            CONSTRAINT fk_orders_seller
                FOREIGN KEY (seller_id) REFERENCES retailers (id)
                ON DELETE CASCADE,
            CONSTRAINT fk_orders_product
                FOREIGN KEY (product_id) REFERENCES products (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $checked = true;
}
