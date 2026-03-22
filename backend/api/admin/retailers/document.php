<?php
/**
 * GET /backend/api/admin/retailers/document.php?doc_id=<int>&admin_key=<key>
 *
 * Streams protected retailer verification documents for admin review.
 */

require_once __DIR__ . '/../../../includes/cors.php';
require_once __DIR__ . '/../../../includes/response.php';
require_once __DIR__ . '/../../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$expected = getenv('ADMIN_REVIEW_KEY') ?: '';
if ($expected === '') {
    jsonError('Admin review key is not configured.', 500);
}

$provided = trim((string) ($_GET['admin_key'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? '')));
if ($provided === '' || !hash_equals($expected, $provided)) {
    jsonError('Unauthorized admin request.', 401);
}

$docId = filter_var($_GET['doc_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($docId === false) {
    jsonError('A valid document ID is required.');
}

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, file_path, mime_type
         FROM retailer_documents
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        jsonError('Document not found.', 404);
    }

    $stored = (string) $doc['file_path'];
    $secureBase = realpath(__DIR__ . '/../../../storage/retailers');
    $legacyBase = realpath(__DIR__ . '/../../../uploads/retailers');

    $fullPath = null;

    // Preferred secure storage path.
    if ($secureBase) {
        $candidate = $secureBase . DIRECTORY_SEPARATOR . basename($stored);
        if (is_file($candidate)) {
            $fullPath = $candidate;
        }
    }

    // Backward compatibility for legacy stored public paths.
    if ($fullPath === null && $legacyBase && strpos($stored, 'backend/uploads/retailers/') === 0) {
        $legacyFile = basename($stored);
        $candidate = $legacyBase . DIRECTORY_SEPARATOR . $legacyFile;
        if (is_file($candidate)) {
            $fullPath = $candidate;
        }
    }

    if ($fullPath === null || !is_file($fullPath)) {
        jsonError('Document file is missing.', 404);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $mime = trim((string) ($doc['mime_type'] ?? 'application/octet-stream'));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($fullPath));
    header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    readfile($fullPath);
    exit;
} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
