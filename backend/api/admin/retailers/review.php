<?php
/**
 * POST /backend/api/admin/retailers/review.php
 * Body: { retailer_id, action: approved|rejected, notes }
 */

require_once __DIR__ . '/../../../includes/cors.php';
require_once __DIR__ . '/../../../includes/response.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

requireAdminAuth();

$body = getJsonBody();
$retailerId = filter_var($body['retailer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = trim($body['action'] ?? '');
$notes = trim($body['notes'] ?? '');

if ($retailerId === false || $retailerId === null) {
    jsonError('A valid retailer ID is required.');
}
if (!in_array($action, ['approved', 'rejected'], true)) {
    jsonError('Action must be approved or rejected.');
}

try {
    $db = getDB();
    $stmt = $db->prepare(
        'UPDATE retailers
         SET review_status = :review_status,
             is_approved = :is_approved,
             review_notes = :review_notes,
             reviewed_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'review_status' => $action,
        'is_approved' => $action === 'approved' ? 1 : 0,
        'review_notes' => $notes !== '' ? $notes : null,
        'id' => (int) $retailerId,
    ]);

    if ($stmt->rowCount() === 0) {
        jsonError('Retailer not found or no changes applied.', 404);
    }

    jsonOk([
        'retailer_id' => (int) $retailerId,
        'review_status' => $action,
    ], 'Retailer review updated successfully.');
} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
