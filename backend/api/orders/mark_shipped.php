<?php
/**
 * POST /backend/api/orders/mark_shipped.php
 * Body: { order_id }
 * Retailer marks delivery as shipped.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

if (empty($_SESSION['retailer_id']) || empty($_SESSION['is_approved'])) {
    jsonError('Approved retailer session is required.', 401);
}

$retailerId = (int) $_SESSION['retailer_id'];
$body = getJsonBody();
$orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($orderId === false || $orderId === null) {
    jsonError('Valid order_id is required.');
}

try {
    $db = getDB();
    ensureOrdersTable($db);
    $lookup = $db->prepare(
        'SELECT id, seller_id, payment_status, delivery_status
         FROM orders
         WHERE id = :id
         LIMIT 1'
    );
    $lookup->execute(['id' => (int) $orderId]);
    $order = $lookup->fetch();

    if (!$order) {
        jsonError('Order not found.', 404);
    }
    if ((int) $order['seller_id'] !== $retailerId) {
        jsonError('You can only update your own order shipments.', 403);
    }
    if ($order['payment_status'] !== 'held') {
        jsonError('Only HELD orders can be shipped in this flow.', 400);
    }

    $update = $db->prepare(
        'UPDATE orders
         SET delivery_status = :delivery_status,
             shipped_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'delivery_status' => 'shipped',
        'id' => (int) $orderId,
    ]);

    jsonOk([
        'order_id' => (int) $orderId,
        'delivery_status' => 'shipped',
        'payment_status' => 'held',
    ], 'Order marked as shipped. Payment remains HELD.');
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
