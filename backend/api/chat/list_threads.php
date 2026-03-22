<?php
/**
 * GET /backend/api/chat/list_threads.php
 *
 * Query params:
 * - role: client|retailer
 * - client_email: required when role=client
 *
 * Returns conversation threads for chat sidebar/selectors.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$role = strtolower(trim((string) ($_GET['role'] ?? '')));

if (!in_array($role, ['client', 'retailer'], true)) {
    jsonError('Invalid role. Use client or retailer.');
}

try {
    $db = getDB();

    if ($role === 'retailer') {
        if (empty($_SESSION['retailer_id'])) {
            jsonError('Retailer session required.', 401);
        }

        $retailerId = (int) $_SESSION['retailer_id'];

        $sql = "SELECT
                    cm.client_email,
                    MAX(cm.client_name) AS client_name,
                    MAX(cm.sent_at) AS last_sent_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(cm.message ORDER BY cm.sent_at DESC SEPARATOR '\\n'),
                        '\\n',
                        1
                    ) AS last_message
                FROM chat_messages cm
                WHERE cm.retailer_id = :rid
                GROUP BY cm.client_email
                ORDER BY last_sent_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute(['rid' => $retailerId]);
        $threads = $stmt->fetchAll();

        jsonOk(['threads' => $threads]);
    }

    $clientEmail = strtolower(trim((string) ($_GET['client_email'] ?? '')));
    if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        jsonError('Valid client_email is required for client threads.');
    }

    $sql = "SELECT
                cm.retailer_id,
                r.shop_name AS retailer_name,
                MAX(cm.sent_at) AS last_sent_at,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(cm.message ORDER BY cm.sent_at DESC SEPARATOR '\\n'),
                    '\\n',
                    1
                ) AS last_message
            FROM chat_messages cm
            INNER JOIN retailers r ON r.id = cm.retailer_id
            WHERE cm.client_email = :client_email
            GROUP BY cm.retailer_id, r.shop_name
            ORDER BY last_sent_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute(['client_email' => $clientEmail]);
    $threads = $stmt->fetchAll();

    foreach ($threads as &$thread) {
        $thread['retailer_id'] = (int) $thread['retailer_id'];
    }
    unset($thread);

    jsonOk(['threads' => $threads]);

} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Chat tables are missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
