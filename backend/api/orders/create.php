<?php
/**
 * POST /backend/api/orders/create.php
 *
 * Real M-Pesa STK payment flow:
 * 1) buyer initiates payment with phone number
 * 2) M-Pesa sends STK prompt to buyer device
 * 3) callback endpoint confirms payment and moves orders to HELD
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/orders_schema.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();
$buyerName = trim((string) ($body['buyer_name'] ?? ''));
$buyerEmail = strtolower(trim((string) ($body['buyer_email'] ?? '')));
$paymentPhone = preg_replace('/\D+/', '', (string) ($body['payment_phone'] ?? ''));
$items = $body['items'] ?? [];

if ($buyerName === '') {
    jsonError('buyer_name is required.');
}
if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid buyer_email is required.');
}
if ($paymentPhone === '') {
    jsonError('payment_phone is required.');
}
if (!is_array($items) || count($items) === 0) {
    jsonError('At least one cart item is required.');
}

if (preg_match('/^07\d{8}$/', $paymentPhone)) {
    $paymentPhone = '254' . substr($paymentPhone, 1);
} elseif (preg_match('/^7\d{8}$/', $paymentPhone)) {
    $paymentPhone = '254' . $paymentPhone;
}

if (!preg_match('/^2547\d{8}$/', $paymentPhone)) {
    jsonError('payment_phone must be in format 2547XXXXXXXX, 07XXXXXXXX, or 7XXXXXXXX.');
}

$consumerKey = trim((string) (getenv('MPESA_CONSUMER_KEY') ?: ''));
$consumerSecret = trim((string) (getenv('MPESA_CONSUMER_SECRET') ?: ''));
$shortCode = trim((string) (getenv('MPESA_SHORTCODE') ?: ''));
$passKey = trim((string) (getenv('MPESA_PASSKEY') ?: ''));
$callbackUrl = trim((string) (getenv('MPESA_CALLBACK_URL') ?: ''));
$baseUrl = rtrim((string) (getenv('MPESA_BASE_URL') ?: 'https://sandbox.safaricom.co.ke'), '/');
$transactionType = trim((string) (getenv('MPESA_TRANSACTION_TYPE') ?: 'CustomerPayBillOnline'));
$accountReference = trim((string) (getenv('MPESA_ACCOUNT_REFERENCE') ?: 'FashionHub'));
$description = trim((string) (getenv('MPESA_TRANSACTION_DESC') ?: 'Fashion Hub purchase'));

if ($consumerKey === '' || $consumerSecret === '' || $shortCode === '' || $passKey === '' || $callbackUrl === '') {
    $missingKeys = [];
    if ($consumerKey === '') {
        $missingKeys[] = 'MPESA_CONSUMER_KEY';
    }
    if ($consumerSecret === '') {
        $missingKeys[] = 'MPESA_CONSUMER_SECRET';
    }
    if ($shortCode === '') {
        $missingKeys[] = 'MPESA_SHORTCODE';
    }
    if ($passKey === '') {
        $missingKeys[] = 'MPESA_PASSKEY';
    }
    if ($callbackUrl === '') {
        $missingKeys[] = 'MPESA_CALLBACK_URL';
    }

    jsonError('M-Pesa is not configured. Missing: ' . implode(', ', $missingKeys), 500);
}

if (!function_exists('curl_init')) {
    jsonError('Server is missing cURL extension required for M-Pesa requests.', 500);
}

function mpesaHttpPost(string $url, array $headers, array $payload, ?array $basicAuth = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Failed to initialize cURL.'];
    }

    $requestHeaders = $headers;
    if (!in_array('Content-Type: application/json', $requestHeaders, true)) {
        $requestHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON response from M-Pesa.'];
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $decoded];
}

function mpesaGetAccessToken(string $baseUrl, string $consumerKey, string $consumerSecret): string
{
    $url = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $ch = curl_init($url);
    if ($ch === false) {
        jsonError('Failed to initialize cURL for token request.', 500);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($errno !== 0) {
        jsonError('Failed to get M-Pesa access token: ' . $err, 502);
    }

    $decoded = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        $msg = is_array($decoded) ? ($decoded['errorMessage'] ?? $decoded['error_description'] ?? 'Token request rejected.') : 'Token request rejected.';
        jsonError('Failed to get M-Pesa access token: ' . $msg, 502);
    }

    return (string) $decoded['access_token'];
}

try {
    $db = getDB();
    ensureOrdersTable($db);

    $validatedItems = [];
    $lineTotal = 0.0;

    $productLookup = $db->prepare(
        'SELECT p.id, p.name, p.price, p.retailer_id, r.shop_name, r.is_approved
         FROM products p
         INNER JOIN retailers r ON r.id = p.retailer_id
         WHERE p.id = :id
         LIMIT 1'
    );

    foreach ($items as $item) {
        $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || $productId === null) {
            jsonError('Invalid product item in cart.');
        }

        $productLookup->execute(['id' => (int) $productId]);
        $product = $productLookup->fetch();
        if (!$product) {
            jsonError('Product not found while creating order.', 404);
        }
        if ((int) $product['is_approved'] !== 1) {
            jsonError('Seller is not approved for checkout.', 403);
        }

        $validatedItems[] = [
            'product_id' => (int) $product['id'],
            'product_name' => (string) $product['name'],
            'seller_id' => (int) $product['retailer_id'],
            'seller_name' => (string) $product['shop_name'],
            'amount' => (float) $product['price'],
        ];
        $lineTotal += (float) $product['price'];
    }

    $stkAmount = (int) max(1, round($lineTotal));
    $timestamp = date('YmdHis');
    $password = base64_encode($shortCode . $passKey . $timestamp);

    $accessToken = mpesaGetAccessToken($baseUrl, $consumerKey, $consumerSecret);

    $stkPayload = [
        'BusinessShortCode' => $shortCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $transactionType,
        'Amount' => $stkAmount,
        'PartyA' => $paymentPhone,
        'PartyB' => $shortCode,
        'PhoneNumber' => $paymentPhone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => $accountReference,
        'TransactionDesc' => $description,
    ];

    $stkRes = mpesaHttpPost(
        $baseUrl . '/mpesa/stkpush/v1/processrequest',
        ['Authorization: Bearer ' . $accessToken],
        $stkPayload,
        null
    );

    if (!$stkRes['ok']) {
        $msg = $stkRes['error'] ?? 'M-Pesa request failed.';
        if (!empty($stkRes['data']['errorMessage'])) {
            $msg = (string) $stkRes['data']['errorMessage'];
        }
        jsonError('M-Pesa request failed: ' . $msg, 502);
    }

    $stkData = $stkRes['data'];
    $responseCode = (string) ($stkData['ResponseCode'] ?? '');
    if ($responseCode !== '0') {
        $msg = (string) ($stkData['ResponseDescription'] ?? $stkData['errorMessage'] ?? 'Payment request was rejected.');
        jsonError('Payment request rejected: ' . $msg, 400);
    }

    $checkoutRequestId = (string) ($stkData['CheckoutRequestID'] ?? '');
    if ($checkoutRequestId === '') {
        jsonError('Payment request failed: missing CheckoutRequestID.', 502);
    }

    $db->beginTransaction();

    $insertOrder = $db->prepare(
        'INSERT INTO orders
            (buyer_name, buyer_email, seller_id, seller_name, product_id, product_name, amount,
             payment_status, delivery_status, callback_confirmed, callback_reference)
         VALUES
            (:buyer_name, :buyer_email, :seller_id, :seller_name, :product_id, :product_name, :amount,
             :payment_status, :delivery_status, :callback_confirmed, :callback_reference)'
    );

    $created = [];
    foreach ($validatedItems as $item) {
        $insertOrder->execute([
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'seller_id' => $item['seller_id'],
            'seller_name' => $item['seller_name'],
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'amount' => $item['amount'],
            'payment_status' => 'pending',
            'delivery_status' => 'pending',
            'callback_confirmed' => 0,
            'callback_reference' => $checkoutRequestId,
        ]);

        $created[] = [
            'order_id' => (int) $db->lastInsertId(),
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'seller_id' => $item['seller_id'],
            'seller_name' => $item['seller_name'],
            'amount' => (float) $item['amount'],
            'payment_status' => 'pending',
            'delivery_status' => 'pending',
            'callback_confirmed' => false,
            'callback_reference' => $checkoutRequestId,
        ];
    }

    $db->commit();

    jsonOk([
        'orders' => $created,
        'checkout_request_id' => $checkoutRequestId,
        'customer_message' => (string) ($stkData['CustomerMessage'] ?? ''),
        'message_flow' => [
            'payment_request_sent' => true,
            'escrow_status' => 'pending_callback',
        ],
    ], 'Payment request sent. Complete payment on your phone to move funds to HELD escrow.');
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Orders table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
