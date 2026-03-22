<?php
/**
 * POST /backend/api/chat/send.php
 *
 * Body:
 * - sender_role: client|retailer
 * - retailer_id: required
 * - client_name: required when sender_role=client
 * - client_email: required
 * - message: required
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();

$senderRole = strtolower(trim((string) ($body['sender_role'] ?? '')));
$retailerId = (int) ($body['retailer_id'] ?? 0);
$clientName = trim((string) ($body['client_name'] ?? ''));
$clientEmail = strtolower(trim((string) ($body['client_email'] ?? '')));
$message = trim((string) ($body['message'] ?? ''));

if (!in_array($senderRole, ['client', 'retailer'], true)) {
    jsonError('sender_role must be client or retailer.');
}
if ($retailerId <= 0) {
    jsonError('Valid retailer_id is required.');
}
if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid client_email is required.');
}
if ($message === '') {
    jsonError('Message is required.');
}
if (mb_strlen($message) > 1500) {
    jsonError('Message is too long. Max length is 1500 characters.');
}

try {
    $db = getDB();

    $retailerStmt = $db->prepare('SELECT id, shop_name, is_approved FROM retailers WHERE id = :id LIMIT 1');
    $retailerStmt->execute(['id' => $retailerId]);
    $retailer = $retailerStmt->fetch();

    if (!$retailer) {
        jsonError('Retailer not found.', 404);
    }

    if ($senderRole === 'retailer') {
        $sessionRetailerId = (int) ($_SESSION['retailer_id'] ?? 0);
        if ($sessionRetailerId <= 0 || $sessionRetailerId !== $retailerId) {
            jsonError('Retailer session required for this action.', 401);
        }

        if ((int) $retailer['is_approved'] !== 1) {
            jsonError('Retailer is not approved for chat.', 403);
        }

        $senderName = trim((string) ($_SESSION['retailer_name'] ?? $retailer['shop_name']));
        if ($senderName === '') {
            $senderName = 'Retailer';
        }

        if ($clientName === '') {
            $clientName = 'Client';
        }
    } else {
        if ($clientName === '') {
            jsonError('client_name is required for client messages.');
        }
        if ((int) $retailer['is_approved'] !== 1) {
            jsonError('You can only message approved retailers.', 403);
        }
        $senderName = $clientName;
    }

    $insert = $db->prepare(
        'INSERT INTO chat_messages
            (retailer_id, client_name, client_email, sender_role, sender_name, message)
         VALUES
            (:rid, :client_name, :client_email, :sender_role, :sender_name, :message)'
    );

    $insert->execute([
        'rid' => $retailerId,
        'client_name' => $clientName,
        'client_email' => $clientEmail,
        'sender_role' => $senderRole,
        'sender_name' => $senderName,
        'message' => $message,
    ]);

    $id = (int) $db->lastInsertId();

    jsonOk([
        'message' => [
            'id' => $id,
            'retailer_id' => $retailerId,
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'sender_role' => $senderRole,
            'sender_name' => $senderName,
            'message' => $message,
            'timestamp' => (int) (microtime(true) * 1000),
        ],
    ], 'Message sent.');

} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Chat tables are missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
