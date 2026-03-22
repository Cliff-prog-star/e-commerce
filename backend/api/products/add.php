<?php
/**
 * POST /backend/api/products/add.php
 * Body: { name, price, category, subcategory, image, sizes, description }
 *
 * Requires an active, approved retailer session.
 * Validates all fields, verifies the retailer record in DB,
 * then inserts the product.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

function productsHasSubcategoryColumn(PDO $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => 'products',
        'column_name' => 'subcategory',
    ]);

    $cached = ((int) ($stmt->fetch()['cnt'] ?? 0)) > 0;
    return $cached;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$maxRequestBytes = 7 * 1024 * 1024;
if ($contentLength > $maxRequestBytes) {
    jsonError('Submission is too large. Please reduce image size and try again.', 413);
}

// ---- Auth check ----
if (empty($_SESSION['retailer_id']) || empty($_SESSION['is_approved'])) {
    jsonError('You must be a verified retailer to post products.', 401);
}

$retailerId = (int) $_SESSION['retailer_id'];

$body        = getJsonBody();
$name        = trim($body['name']        ?? '');
$priceRaw    =       $body['price']       ?? null;
$category    = trim($body['category']    ?? '');
$subcategory = trim($body['subcategory'] ?? '');
$imageInput  = trim($body['image']       ?? '');
$sizes       = trim($body['sizes']       ?? '');
$description = trim($body['description'] ?? '');

// ---- Field validation ----
if ($name === '') {
    jsonError('Product name is required.');
}
if ($priceRaw === null || $priceRaw === '') {
    jsonError('Price is required.');
}
$price = (float) $priceRaw;
if ($price <= 0) {
    jsonError('Price must be greater than zero.');
}
if (!in_array($category, ['men', 'women', 'kids'], true)) {
    jsonError('Category must be one of: men, women, kids.');
}

$subcategoryMap = [
    'men' => ['shirts', 'trousers', 't-shirts', 'jackets', 'suits', 'hoodies', 'accessories'],
    'women' => ['dresses', 'tops', 'trousers', 'skirts', 'scarves', 'jackets', 'accessories'],
    'kids' => ['shirts', 'trousers', 'dresses', 'shorts', 'sweaters', 'school-wear', 'accessories'],
];

$allowedSubcategories = $subcategoryMap[$category] ?? [];
if (!in_array($subcategory, $allowedSubcategories, true)) {
    jsonError('Invalid subcategory for the selected category.');
}

if ($sizes === '') {
    jsonError('Available sizes are required.');
}
if ($description === '') {
    jsonError('Product description is required.');
}

// Accept either a normal URL/path, or a data URL generated from file input.
$imageUrl = '';
if ($imageInput !== '') {
    if (preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,/i', $imageInput, $m)) {
        $parts = explode(',', $imageInput, 2);
        if (count($parts) !== 2) {
            jsonError('Invalid image payload.');
        }

        $binary = base64_decode($parts[1], true);
        if ($binary === false) {
            jsonError('Invalid base64 image data.');
        }

        // Keep upload size bounded for safety.
        if (strlen($binary) > 5 * 1024 * 1024) {
            jsonError('Image file is too large. Max size is 5MB.');
        }

        $uploadDir = __DIR__ . '/../../../uploads/products';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            jsonError('Unable to prepare upload directory.', 500);
        }

        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        try {
            $filename = 'prod_' . $retailerId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        } catch (Exception $e) {
            $filename = 'prod_' . $retailerId . '_' . uniqid('', true) . '.' . $ext;
        }

        $fullPath = $uploadDir . '/' . $filename;
        if (file_put_contents($fullPath, $binary, LOCK_EX) === false) {
            jsonError('Failed to save uploaded image.', 500);
        }

        $imageUrl = 'backend/uploads/products/' . $filename;
    } else {
        // Backward compatibility: still allow existing URL/path flows.
        $isValidUrl = filter_var($imageInput, FILTER_VALIDATE_URL) !== false;
        $isValidRelative = strpos($imageInput, 'backend/uploads/products/') === 0;
        if (!$isValidUrl && !$isValidRelative) {
            jsonError('Invalid image format. Upload an image file or provide a valid URL.');
        }
        $imageUrl = $imageInput;
    }
}

// ---- Verify retailer still active in DB ----
try {
    $db   = getDB();
    $hasSubcategoryColumn = productsHasSubcategoryColumn($db);
    $stmt = $db->prepare(
        'SELECT id, shop_name FROM retailers WHERE id = :id AND is_approved = 1 LIMIT 1'
    );
    $stmt->execute(['id' => $retailerId]);
    $retailer = $stmt->fetch();

    if (!$retailer) {
        jsonError('Retailer account not found or not approved.', 403);
    }

    $retailerName = $retailer['shop_name'];

    // ---- Insert product ----
    if ($hasSubcategoryColumn) {
        $insert = $db->prepare(
            'INSERT INTO products
                 (retailer_id, retailer_name, name, price, category, subcategory, image_url, sizes, description)
             VALUES
                 (:rid, :rname, :name, :price, :cat, :subcategory, :img, :sizes, :desc)'
        );
        $insert->execute([
            'rid'   => $retailerId,
            'rname' => $retailerName,
            'name'  => $name,
            'price' => $price,
            'cat'   => $category,
            'subcategory' => $subcategory,
            'img'   => $imageUrl !== '' ? $imageUrl : null,
            'sizes' => $sizes,
            'desc'  => $description,
        ]);
    } else {
        $insert = $db->prepare(
            'INSERT INTO products
                 (retailer_id, retailer_name, name, price, category, image_url, sizes, description)
             VALUES
                 (:rid, :rname, :name, :price, :cat, :img, :sizes, :desc)'
        );
        $insert->execute([
            'rid'   => $retailerId,
            'rname' => $retailerName,
            'name'  => $name,
            'price' => $price,
            'cat'   => $category,
            'img'   => $imageUrl !== '' ? $imageUrl : null,
            'sizes' => $sizes,
            'desc'  => $description,
        ]);
    }

    $newId = (int) $db->lastInsertId();

    jsonOk([
        'product' => [
            'id'          => $newId,
            'retailer_id' => $retailerId,
            'retailer'    => $retailerName,
            'name'        => $name,
            'price'       => $price,
            'category'    => $category,
            'subcategory' => $subcategory,
            'image'       => $imageUrl,
            'sizes'       => $sizes,
            'description' => $description,
            'timestamp'   => (int) (microtime(true) * 1000),
        ],
    ], 'Product added successfully.');

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
