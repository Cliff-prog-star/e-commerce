<?php
/**
 * GET /backend/api/retailers/status.php
 *
 * Returns the current session-based retailer verification state.
 * The frontend calls this on page load to restore the UI.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

// If there is a retailer in the session, return the latest review state from DB.
if (!empty($_SESSION['retailer_id'])) {
    $id = (int) $_SESSION['retailer_id'];
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT id, shop_name, is_approved, review_status, review_notes FROM retailers WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            $_SESSION['is_approved'] = (int) $row['is_approved'] === 1;
            $_SESSION['review_status'] = $row['review_status'];
            jsonOk([
                'is_approved'    => (int) $row['is_approved'] === 1,
                'review_status'  => $row['review_status'],
                'review_notes'   => $row['review_notes'],
                'retailer_id'    => (int) $row['id'],
                'shop_name'      => $row['shop_name'],
                'phone_verified' => true,
                'email_verified' => true,
            ]);
        }
    } catch (PDOException $e) {
        // Fall through to unapproved response
    }
}

// Not approved – return partial state so the frontend can restore OTP / email indicators
jsonOk([
    'is_approved'    => false,
    'review_status'  => 'unverified',
    'review_notes'   => null,
    'phone_verified' => !empty($_SESSION['phone_verified']),
    'email_verified' => !empty($_SESSION['email_verified']),
    'shop_name'      => null,
    'retailer_id'    => null,
]);
