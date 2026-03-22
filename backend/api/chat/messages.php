<?php
/**
 * GET /backend/api/chat/messages.php
 *
 * Query params:
 * - retailer_id: required
 * - client_email: required
 *
 * Returns ordered messages for one conversation thread.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$retailerId = (int) ($_GET['retailer_id'] ?? 0);
$clientEmail = strtolower(trim((string) ($_GET['client_email'] ?? '')));

if ($retailerId <= 0) {
    jsonError('Valid retailer_id is required.');
}
if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid client_email is required.');
}

$sessionRetailerId = (int) ($_SESSION['retailer_id'] ?? 0);
if ($sessionRetailerId > 0 && $sessionRetailerId !== $retailerId) {
    jsonError('Retailer is not authorized for this thread.', 403);
}

try {
    $db = getDB();

    $exists = $db->prepare('SELECT id FROM retailers WHERE id = :id LIMIT 1');
    $exists->execute(['id' => $retailerId]);
    if (!$exists->fetch()) {
        jsonError('Retailer not found.', 404);
    }

    $stmt = $db->prepare(
        'SELECT id, retailer_id, client_name, client_email, sender_role, sender_name, message,
                UNIX_TIMESTAMP(sent_at) * 1000 AS timestamp
         FROM chat_messages
         WHERE retailer_id = :rid AND client_email = :client_email
         ORDER BY sent_at ASC, id ASC
         LIMIT 300'
    );

    $stmt->execute([
        'rid' => $retailerId,
        'client_email' => $clientEmail,
    ]);

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['retailer_id'] = (int) $row['retailer_id'];
        $row['timestamp'] = (int) $row['timestamp'];
    }
    unset($row);

    jsonOk(['messages' => $rows]);

} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Chat tables are missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
