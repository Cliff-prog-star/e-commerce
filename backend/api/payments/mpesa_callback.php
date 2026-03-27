<?php
/**
 * POST /backend/api/payments/mpesa_callback.php
 * Receives M-Pesa STK callback and updates matching orders.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();
$stkCallback = $body['Body']['stkCallback'] ?? null;
if (!is_array($stkCallback)) {
    jsonError('Invalid callback payload.', 400);
}

$checkoutRequestId = trim((string) ($stkCallback['CheckoutRequestID'] ?? ''));
$resultCode = (int) ($stkCallback['ResultCode'] ?? -1);

if ($checkoutRequestId === '') {
    jsonError('Missing CheckoutRequestID.', 400);
}

try {
    $db = getDB();
    ensureOrdersTable($db);

    if ($resultCode === 0) {
        $update = $db->prepare(
            'UPDATE orders
             SET payment_status = :payment_status,
                 callback_confirmed = :callback_confirmed,
                 paid_at = NOW(),
                 held_at = NOW()
             WHERE callback_reference = :callback_reference'
        );
        $update->execute([
            'payment_status' => 'held',
            'callback_confirmed' => 1,
            'callback_reference' => $checkoutRequestId,
        ]);

        jsonOk([
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'updated_orders' => $update->rowCount(),
        ], 'Callback processed. Orders moved to HELD escrow.');
    }

    // Payment was cancelled or failed by customer/provider.
    $update = $db->prepare(
        'UPDATE orders
         SET payment_status = :payment_status,
             callback_confirmed = :callback_confirmed
         WHERE callback_reference = :callback_reference'
    );
    $update->execute([
        'payment_status' => 'pending',
        'callback_confirmed' => 0,
        'callback_reference' => $checkoutRequestId,
    ]);

    jsonOk([
        'checkout_request_id' => $checkoutRequestId,
        'result_code' => $resultCode,
        'updated_orders' => $update->rowCount(),
    ], 'Callback processed. Payment not completed.');
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error while processing callback.', 500);
}
