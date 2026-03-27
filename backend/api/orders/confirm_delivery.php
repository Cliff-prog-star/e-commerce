<?php
/**
 * POST /backend/api/orders/confirm_delivery.php
 * Body: { order_id, buyer_email }
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();
$orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$buyerEmail = strtolower(trim((string) ($body['buyer_email'] ?? '')));

if ($orderId === false || $orderId === null) {
    jsonError('Valid order_id is required.');
}
if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid buyer_email is required.');
}

try {
    $db = getDB();
    ensureOrdersTable($db);

    $lookup = $db->prepare(
        'SELECT id, buyer_email, payment_status, delivery_status
         FROM orders
         WHERE id = :id
         LIMIT 1'
    );
    $lookup->execute(['id' => (int) $orderId]);
    $order = $lookup->fetch();

    if (!$order) {
        jsonError('Order not found.', 404);
    }
    if (strtolower((string) $order['buyer_email']) !== $buyerEmail) {
        jsonError('You are not allowed to confirm this order.', 403);
    }
    if ($order['payment_status'] !== 'held') {
        jsonError('Order payment is not in HELD state.', 400);
    }

    $update = $db->prepare(
        'UPDATE orders
         SET delivery_status = :delivery_status,
             payment_status = :payment_status,
             delivered_at = NOW(),
             released_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'delivery_status' => 'delivered',
        'payment_status' => 'released',
        'id' => (int) $orderId,
    ]);

    jsonOk([
        'order_id' => (int) $orderId,
        'delivery_status' => 'delivered',
        'payment_status' => 'released',
    ], 'Delivery confirmed. Payment marked as RELEASED.');
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
