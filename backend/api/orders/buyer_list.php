<?php
/**
 * GET /backend/api/orders/buyer_list.php?buyer_email=<email>
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$buyerEmail = strtolower(trim((string) ($_GET['buyer_email'] ?? '')));
if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid buyer_email is required.');
}

try {
    $db = getDB();
    ensureOrdersTable($db);
    $stmt = $db->prepare(
        'SELECT
            id, buyer_name, buyer_email,
            seller_id, seller_name,
            product_id, product_name,
            amount,
            payment_status,
            delivery_status,
            callback_confirmed,
            callback_reference,
            UNIX_TIMESTAMP(created_at) * 1000 AS created_ts,
            UNIX_TIMESTAMP(paid_at) * 1000 AS paid_ts,
            UNIX_TIMESTAMP(held_at) * 1000 AS held_ts,
            UNIX_TIMESTAMP(shipped_at) * 1000 AS shipped_ts,
            UNIX_TIMESTAMP(delivered_at) * 1000 AS delivered_ts,
            UNIX_TIMESTAMP(released_at) * 1000 AS released_ts
         FROM orders
         WHERE buyer_email = :buyer_email
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['buyer_email' => $buyerEmail]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['seller_id'] = (int) $row['seller_id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['amount'] = (float) $row['amount'];
        $row['callback_confirmed'] = (int) $row['callback_confirmed'] === 1;
        $row['created_ts'] = (int) ($row['created_ts'] ?? 0);
        $row['paid_ts'] = (int) ($row['paid_ts'] ?? 0);
        $row['held_ts'] = (int) ($row['held_ts'] ?? 0);
        $row['shipped_ts'] = (int) ($row['shipped_ts'] ?? 0);
        $row['delivered_ts'] = (int) ($row['delivered_ts'] ?? 0);
        $row['released_ts'] = (int) ($row['released_ts'] ?? 0);
    }
    unset($row);

    jsonOk(['orders' => $rows]);
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
