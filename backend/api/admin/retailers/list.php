<?php
/**
 * GET /backend/api/admin/retailers/list.php?status=pending
 *
 * Lists retailer applications with their uploaded legitimacy documents.
 */

require_once __DIR__ . '/../../../includes/cors.php';
require_once __DIR__ . '/../../../includes/response.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireAdminAuth();

$status = trim($_GET['status'] ?? 'pending');
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status, $allowedStatuses, true)) {
    jsonError('Invalid status filter.');
}

try {
    $db = getDB();
    $params = [];

    $sql = 'SELECT
                id,
                full_name,
                national_id,
                phone,
                email,
                date_of_birth,
                shop_name,
                business_type,
                county,
                town,
                shop_address,
                shop_map_url,
                review_status,
                review_notes,
                reviewed_at,
                registered_at
            FROM retailers';

    if ($status !== 'all') {
        $sql .= ' WHERE review_status = :status';
        $params['status'] = $status;
    }

    $sql .= ' ORDER BY registered_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $retailers = $stmt->fetchAll();

    if (!$retailers) {
        jsonOk(['retailers' => []]);
    }

    $ids = array_map(static fn($row) => (int) $row['id'], $retailers);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $docStmt = $db->prepare(
        "SELECT id, retailer_id, document_type, file_path, mime_type, uploaded_at
         FROM retailer_documents
         WHERE retailer_id IN ($placeholders)
         ORDER BY uploaded_at ASC"
    );
    $docStmt->execute($ids);
    $documents = $docStmt->fetchAll();

    $documentsByRetailer = [];
    foreach ($documents as $doc) {
        $rid = (int) $doc['retailer_id'];
        if (!isset($documentsByRetailer[$rid])) {
            $documentsByRetailer[$rid] = [];
        }
        $documentsByRetailer[$rid][] = [
            'document_id' => (int) $doc['id'],
            'document_type' => $doc['document_type'],
            'file_path' => $doc['file_path'],
            'mime_type' => $doc['mime_type'],
            'uploaded_at' => $doc['uploaded_at'],
        ];
    }

    foreach ($retailers as &$retailer) {
        $retailer['id'] = (int) $retailer['id'];
        $retailer['documents'] = $documentsByRetailer[$retailer['id']] ?? [];
    }
    unset($retailer);

    jsonOk(['retailers' => $retailers]);
} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
