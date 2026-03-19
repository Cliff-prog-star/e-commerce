<?php
/**
 * GET /backend/api/products/detail.php?id=<int>
 *
 * Returns a single product by ID, or 404 if not found.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError('A valid product ID is required.');
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT
             p.id,
             p.retailer_id,
             p.retailer_name    AS retailer,
             p.name,
             p.price,
             p.category,
             p.image_url        AS image,
             p.sizes,
             p.description,
             UNIX_TIMESTAMP(p.posted_at) * 1000 AS timestamp
         FROM products p
         WHERE p.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $id]);
    $product = $stmt->fetch();

    if (!$product) {
        jsonError('Product not found.', 404);
    }

    $product['id']        = (int)   $product['id'];
    $product['retailer_id'] = (int) $product['retailer_id'];
    $product['price']     = (float) $product['price'];
    $product['timestamp'] = (int)   $product['timestamp'];

    jsonOk(['product' => $product]);

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
