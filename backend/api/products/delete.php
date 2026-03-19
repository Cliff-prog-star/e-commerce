<?php
/**
 * POST /backend/api/products/delete.php
 * Body: { id }
 *
 * Deletes a product if and only if it belongs to the logged-in approved retailer.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

if (empty($_SESSION['retailer_id']) || empty($_SESSION['is_approved'])) {
    jsonError('You must be a verified retailer to delete products.', 401);
}

$retailerId = (int) $_SESSION['retailer_id'];
$body = getJsonBody();
$productId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($productId === false || $productId === null) {
    jsonError('A valid product ID is required.');
}

try {
    $db = getDB();

    $stmt = $db->prepare('SELECT id, retailer_id, image_url FROM products WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        jsonError('Product not found.', 404);
    }

    if ((int) $product['retailer_id'] !== $retailerId) {
        jsonError('You can only delete your own posts.', 403);
    }

    $delete = $db->prepare('DELETE FROM products WHERE id = :id LIMIT 1');
    $delete->execute(['id' => (int) $productId]);

    // If product image is a local upload path, remove file best-effort.
    $imageUrl = (string) ($product['image_url'] ?? '');
    if (strpos($imageUrl, 'backend/uploads/products/') === 0) {
        $relative = substr($imageUrl, strlen('backend/')); // uploads/products/...
        $fullPath = realpath(__DIR__ . '/../../../' . $relative);
        $uploadsRoot = realpath(__DIR__ . '/../../../uploads/products');

        if ($fullPath && $uploadsRoot && strpos($fullPath, $uploadsRoot) === 0 && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    jsonOk(['id' => (int) $productId], 'Product deleted successfully.');

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
