<?php
/**
 * GET /backend/api/products/list.php
 * Query params: category (men|women|kids|all), subcategory, search, sort (newest|price-low|price-high)
 *
 * Returns all matching products as a JSON array.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$category = trim($_GET['category'] ?? 'all');
$subcategory = trim($_GET['subcategory'] ?? 'all');
$search   = trim($_GET['search']   ?? '');
$sort     = trim($_GET['sort']     ?? 'newest');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$limit    = (int) ($_GET['limit'] ?? 12);
if ($limit < 1) {
    $limit = 12;
}
if ($limit > 50) {
    $limit = 50;
}
$offset = ($page - 1) * $limit;

// Whitelist sort values
if (!in_array($sort, ['newest', 'price-low', 'price-high'], true)) {
    $sort = 'newest';
}

// Whitelist category values
$filterCategory = in_array($category, ['men', 'women', 'kids'], true) ? $category : null;

$subcategoryMap = [
    'men' => ['shirts', 'trousers', 't-shirts', 'jackets', 'suits', 'hoodies', 'accessories'],
    'women' => ['dresses', 'tops', 'trousers', 'skirts', 'scarves', 'jackets', 'accessories'],
    'kids' => ['shirts', 'trousers', 'dresses', 'shorts', 'sweaters', 'school-wear', 'accessories'],
];

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

try {
    $db     = getDB();
    $hasSubcategoryColumn = productsHasSubcategoryColumn($db);
    $params = [];

    $whereSql = ' WHERE 1=1';

    if ($filterCategory !== null) {
        $whereSql .= ' AND p.category = :category';
        $params['category'] = $filterCategory;

        if ($hasSubcategoryColumn && $subcategory !== '' && $subcategory !== 'all') {
            $allowed = $subcategoryMap[$filterCategory] ?? [];
            if (in_array($subcategory, $allowed, true)) {
                $whereSql .= ' AND p.subcategory = :subcategory';
                $params['subcategory'] = $subcategory;
            }
        }
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereSql .= ' AND (p.name LIKE :s1 OR p.retailer_name LIKE :s2 OR p.description LIKE :s3)';
        $params['s1'] = $like;
        $params['s2'] = $like;
        $params['s3'] = $like;
    }

    $countSql = 'SELECT COUNT(*) AS total FROM products p' . $whereSql;
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $sql = 'SELECT
                p.id,
                p.retailer_id,
                p.retailer_name   AS retailer,
                p.name,
                p.price,
                p.category,
                ' . ($hasSubcategoryColumn ? 'p.subcategory' : 'NULL AS subcategory') . ',
                COALESCE(r.is_approved, 0) AS retailer_verified,
                r.county AS retailer_county,
                r.town AS retailer_town,
                p.image_url       AS image,
                p.sizes,
                p.description,
                UNIX_TIMESTAMP(p.posted_at) * 1000 AS timestamp
            FROM products p
            LEFT JOIN retailers r ON r.id = p.retailer_id' . $whereSql;

    switch ($sort) {
        case 'price-low':
            $sql .= ' ORDER BY p.price ASC, p.posted_at DESC';
            break;
        case 'price-high':
            $sql .= ' ORDER BY p.price DESC, p.posted_at DESC';
            break;
        default:
            $sql .= ' ORDER BY p.posted_at DESC';
    }

    $sql .= ' LIMIT :limit OFFSET :offset';

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Cast numeric types so JSON encodes them correctly
    foreach ($rows as &$row) {
        $row['id']        = (int)   $row['id'];
        $row['retailer_id'] = (int) $row['retailer_id'];
        $row['retailer_verified'] = (int) $row['retailer_verified'] === 1;
        $row['price']     = (float) $row['price'];
        $row['timestamp'] = (int)   $row['timestamp'];
    }
    unset($row);

    $totalPages = max(1, (int) ceil($total / $limit));

    jsonOk([
        'products' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
        ],
    ]);

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
