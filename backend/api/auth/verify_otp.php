<?php
/**
 * POST /backend/api/auth/verify_otp.php
 * Body: { "phone": "+254700000000", "otp": "123456" }
 *
 * Validates the OTP against the DB, marks it used, and stores
 * the verified phone in the session.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

function normalizePhone(string $phone): string
{
    return preg_replace('/\s+|-/', '', trim($phone));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$phone = normalizePhone(input('phone'));
$otp   = input('otp');

if ($phone === '' || $otp === '') {
    jsonError('Phone number and OTP are required.');
}

if (!preg_match('/^\d{6}$/', $otp)) {
    jsonError('OTP must be exactly 6 digits.');
}

if (!preg_match('/^\+?[\d\s\-]{7,20}$/', $phone)) {
    jsonError('Invalid phone number format.');
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id FROM otp_codes
         WHERE  phone = :phone
           AND  otp_code = :otp
           AND  used = 0
           AND  expires_at > NOW()
         ORDER  BY created_at DESC
         LIMIT  1'
    );
    $stmt->execute(['phone' => $phone, 'otp' => $otp]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonError('Invalid or expired OTP. Please request a new one.');
    }

    // Mark as used
    $db->prepare('UPDATE otp_codes SET used = 1 WHERE id = :id')
       ->execute(['id' => $row['id']]);

    // Persist verification in session
    $_SESSION['phone_verified'] = $phone;

    jsonOk([], 'Phone number verified successfully.');

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
