<?php
/**
 * GET /backend/api/orders/retailer_list.php
 * Requires approved retailer session.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

if (empty($_SESSION['retailer_id']) || empty($_SESSION['is_approved'])) {
    jsonError('Approved retailer session is required.', 401);
}

$retailerId = (int) $_SESSION['retailer_id'];

try {
    $db = getDB();
    ensureOrdersTable($db);
    $stmt = $db->prepare(
        'SELECT
            id,
            buyer_name,
            buyer_email,
            product_id,
            product_name,
            amount,
            payment_status,
            delivery_status,
            UNIX_TIMESTAMP(created_at) * 1000 AS created_ts,
            UNIX_TIMESTAMP(held_at) * 1000 AS held_ts,
            UNIX_TIMESTAMP(shipped_at) * 1000 AS shipped_ts,
            UNIX_TIMESTAMP(delivered_at) * 1000 AS delivered_ts,
            UNIX_TIMESTAMP(released_at) * 1000 AS released_ts
         FROM orders
         WHERE seller_id = :seller_id
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['seller_id' => $retailerId]);
    $rows = $stmt->fetchAll();

    $heldAmount = 0.0;
    $releasedAmount = 0.0;

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['amount'] = (float) $row['amount'];
        $row['created_ts'] = (int) ($row['created_ts'] ?? 0);
        $row['held_ts'] = (int) ($row['held_ts'] ?? 0);
        $row['shipped_ts'] = (int) ($row['shipped_ts'] ?? 0);
        $row['delivered_ts'] = (int) ($row['delivered_ts'] ?? 0);
        $row['released_ts'] = (int) ($row['released_ts'] ?? 0);
        $row['can_withdraw'] = $row['payment_status'] === 'released';

        if ($row['payment_status'] === 'held') {
            $heldAmount += $row['amount'];
        }
        if ($row['payment_status'] === 'released') {
            $releasedAmount += $row['amount'];
        }
    }
    unset($row);

    jsonOk([
        'orders' => $rows,
        'summary' => [
            'held_amount' => round($heldAmount, 2),
            'released_amount' => round($releasedAmount, 2),
        ],
    ]);
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
