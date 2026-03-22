<?php
/**
 * POST /backend/api/retailers/register.php
 * Body: full registration form JSON (all 3 steps combined on final submit)
 *
 * Validates that:
 *  - All required fields are present
 *  - No duplicate email or national ID exists
 *  - Retailer is at least 18 years old
 * Then inserts the record for admin legitimacy review.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

/**
 * Save a base64-encoded verification document to protected backend storage.
 * Supports images and PDFs only.
 * Returns [stored_path, full_path, mime].
 */
function saveRetailerDocument(int $retailerId, string $documentType, string $dataUrl): array
{
    if (!preg_match('/^data:(image\/(png|jpe?g|webp)|application\/pdf);base64,/i', $dataUrl, $matches)) {
        jsonError("Invalid file format for {$documentType}. Only images and PDF are allowed.");
    }

    $parts = explode(',', $dataUrl, 2);
    if (count($parts) !== 2) {
        jsonError("Invalid upload payload for {$documentType}.");
    }

    $binary = base64_decode($parts[1], true);
    if ($binary === false) {
        jsonError("Invalid base64 data for {$documentType}.");
    }

    if (strlen($binary) > 8 * 1024 * 1024) {
        jsonError("{$documentType} is too large. Max file size is 8MB.");
    }

    $mime = strtolower($matches[1]);
    $extMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $ext = $extMap[$mime] ?? null;
    if ($ext === null) {
        jsonError("Unsupported document type for {$documentType}.");
    }

    $uploadDir = __DIR__ . '/../../../storage/retailers';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        jsonError('Unable to prepare secure retailer storage directory.', 500);
    }

    try {
        $filename = $documentType . '_' . $retailerId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = $documentType . '_' . $retailerId . '_' . uniqid('', true) . '.' . $ext;
    }

    $fullPath = $uploadDir . '/' . $filename;
    if (file_put_contents($fullPath, $binary, LOCK_EX) === false) {
        jsonError("Failed to save {$documentType}.", 500);
    }

    return [
        'stored_path' => $filename,
        'full_path' => $fullPath,
        'mime' => $mime,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$maxRequestBytes = 10 * 1024 * 1024;
if ($contentLength > $maxRequestBytes) {
    jsonError('Submission is too large. Please reduce document sizes and try again.', 413);
}

$body = getJsonBody();

// ---- Required field presence ----
$required = [
    'full_name', 'national_id', 'phone', 'email', 'date_of_birth',
    'shop_name', 'business_type', 'county', 'town', 'shop_address',
];
foreach ($required as $field) {
    if (trim($body[$field] ?? '') === '') {
        jsonError("Field '{$field}' is required.");
    }
}

$email        = strtolower(trim($body['email']));
$phone        = preg_replace('/\s+|-/', '', trim($body['phone']));
$nationalId   = trim($body['national_id']);
$dob          = trim($body['date_of_birth']);
$businessType = trim($body['business_type']);
$shopName     = trim($body['shop_name']);
$documents    = $body['documents'] ?? [];

$requiredDocuments = ['id_photo', 'selfie_photo', 'shop_photo', 'signboard_photo'];
foreach ($requiredDocuments as $documentKey) {
    if (trim((string) ($documents[$documentKey] ?? '')) === '') {
        jsonError("Required verification document '{$documentKey}' is missing.");
    }
}

// ---- Format validation ----
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Invalid email address format.');
}

if (!in_array($businessType, ['individual', 'retail', 'wholesale'], true)) {
    jsonError('Invalid business type.');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || strtotime($dob) === false) {
    jsonError('Invalid date of birth (expected YYYY-MM-DD).');
}

// Must be at least 18 years old
$age = (int) date('Y') - (int) date('Y', strtotime($dob));
if ($age < 18) {
    jsonError('Retailer must be at least 18 years old.');
}

// ---- Duplicate check ----
try {
    $db = getDB();
    $db->beginTransaction();
    $savedFiles = [];

    $dup = $db->prepare(
        'SELECT id FROM retailers WHERE email = :email OR national_id = :nid LIMIT 1'
    );
    $dup->execute(['email' => $email, 'nid' => $nationalId]);
    if ($dup->fetch()) {
        jsonError('An account with this email or National ID already exists.');
    }

    // ---- Insert ----
    $stmt = $db->prepare(
          "INSERT INTO retailers
             (full_name, national_id, phone, email, date_of_birth,
              shop_name, business_type, county, town, shop_address,
              shop_map_url, phone_verified, email_verified, review_status, is_approved)
         VALUES
             (:full_name, :national_id, :phone, :email, :dob,
              :shop_name, :business_type, :county, :town, :shop_address,
              :shop_map_url, 0, 0, 'pending', 0)"
    );
    $stmt->execute([
        'full_name'     => trim($body['full_name']),
        'national_id'   => $nationalId,
        'phone'         => $phone,
        'email'         => $email,
        'dob'           => $dob,
        'shop_name'     => $shopName,
        'business_type' => $businessType,
        'county'        => trim($body['county']),
        'town'          => trim($body['town']),
        'shop_address'  => trim($body['shop_address']),
        'shop_map_url'  => trim($body['shop_map_url'] ?? '') ?: null,
    ]);

    $retailerId = (int) $db->lastInsertId();

    $documentTypes = [
        'id_photo',
        'selfie_photo',
        'shop_photo',
        'signboard_photo',
        'business_permit',
        'kra_pin',
        'business_certificate',
    ];

    $insertDocument = $db->prepare(
        'INSERT INTO retailer_documents (retailer_id, document_type, file_path, mime_type)
         VALUES (:retailer_id, :document_type, :file_path, :mime_type)'
    );

    foreach ($documentTypes as $documentType) {
        $payload = trim((string) ($documents[$documentType] ?? ''));
        if ($payload === '') {
            continue;
        }

        $saved = saveRetailerDocument($retailerId, $documentType, $payload);
        $savedFiles[] = $saved['full_path'];

        $insertDocument->execute([
            'retailer_id' => $retailerId,
            'document_type' => $documentType,
            'file_path' => $saved['stored_path'],
            'mime_type' => $saved['mime'],
        ]);
    }

    $db->commit();

    // Store pending retailer in session so frontend can show review status.
    $_SESSION['retailer_id']   = $retailerId;
    $_SESSION['retailer_name'] = $shopName;
    $_SESSION['is_approved']   = false;
    $_SESSION['review_status'] = 'pending';

    jsonOk(
        [
            'retailer_id' => $retailerId,
            'shop_name' => $shopName,
            'documents_uploaded' => true,
            'review_status' => 'pending',
        ],
        'Retailer registration submitted successfully. Documents are pending admin review.'
    );

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if (!empty($savedFiles)) {
        foreach ($savedFiles as $savedFile) {
            if (is_file($savedFile)) {
                @unlink($savedFile);
            }
        }
    }

    $sqlState = (string) $e->getCode();
    $isProduction = strtolower((string) (getenv('APP_ENV') ?: 'development')) === 'production';

    if ($sqlState === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        jsonError('Database schema is missing required tables. Import database/schema.sql and try again.', 500);
    }

    if (!$isProduction) {
        jsonError('Database error: ' . $e->getMessage(), 500);
    }

    jsonError('Database error. Please try again.', 500);
}
