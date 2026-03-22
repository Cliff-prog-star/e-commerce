<?php
/**
 * POST /backend/api/reports/submit.php
 *
 * Body:
 * - report_type: product|chat
 * - reporter_name
 * - reporter_email
 * - reason
 * - notes (optional)
 * - product_id (required for product)
 * - retailer_id (required for chat)
 * - chat_client_email (required for chat)
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();

$reportType = trim((string) ($body['report_type'] ?? ''));
$reporterName = trim((string) ($body['reporter_name'] ?? ''));
$reporterEmail = strtolower(trim((string) ($body['reporter_email'] ?? '')));
$reason = trim((string) ($body['reason'] ?? ''));
$notes = trim((string) ($body['notes'] ?? ''));
$productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$retailerId = filter_var($body['retailer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$chatClientEmail = strtolower(trim((string) ($body['chat_client_email'] ?? '')));

if (!in_array($reportType, ['product', 'chat'], true)) {
    jsonError('Invalid report_type. Use product or chat.');
}
if ($reporterName === '' || mb_strlen($reporterName) > 100) {
    jsonError('Valid reporter_name is required (max 100 chars).');
}
if (!filter_var($reporterEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('Valid reporter_email is required.');
}
if ($reason === '' || mb_strlen($reason) > 120) {
    jsonError('Reason is required (max 120 chars).');
}

if ($reportType === 'product') {
    if ($productId === false || $productId === null) {
        jsonError('Valid product_id is required for product reports.');
    }
} else {
    if ($retailerId === false || $retailerId === null) {
        jsonError('Valid retailer_id is required for chat reports.');
    }
    if (!filter_var($chatClientEmail, FILTER_VALIDATE_EMAIL)) {
        jsonError('Valid chat_client_email is required for chat reports.');
    }
}

try {
    $db = getDB();

    if ($reportType === 'product') {
        $stmt = $db->prepare(
            'INSERT INTO reports
                (report_type, reporter_name, reporter_email, reason, notes, product_id)
             VALUES
                (:report_type, :reporter_name, :reporter_email, :reason, :notes, :product_id)'
        );
        $stmt->execute([
            'report_type' => $reportType,
            'reporter_name' => $reporterName,
            'reporter_email' => $reporterEmail,
            'reason' => $reason,
            'notes' => $notes !== '' ? $notes : null,
            'product_id' => (int) $productId,
        ]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO reports
                (report_type, reporter_name, reporter_email, reason, notes, retailer_id, chat_client_email)
             VALUES
                (:report_type, :reporter_name, :reporter_email, :reason, :notes, :retailer_id, :chat_client_email)'
        );
        $stmt->execute([
            'report_type' => $reportType,
            'reporter_name' => $reporterName,
            'reporter_email' => $reporterEmail,
            'reason' => $reason,
            'notes' => $notes !== '' ? $notes : null,
            'retailer_id' => (int) $retailerId,
            'chat_client_email' => $chatClientEmail,
        ]);
    }

    jsonOk(['report_id' => (int) $db->lastInsertId()], 'Report submitted.');
} catch (PDOException $e) {
    $sqlState = (string) $e->getCode();
    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Report table is missing. Import database/schema.sql and try again.', 500);
    }
    jsonError('Database error. Please try again.', 500);
}
