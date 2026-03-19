<?php
/**
 * POST /backend/api/auth/verify_email.php
 * Body: { "token": "<64-char hex>", "email": "user@example.com" }
 *
 * Validates the token against the DB, marks it used, and stores
 * the verified email in the session.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$token = input('token');
$email = strtolower(input('email'));

if ($token === '') {
    jsonError('Verification token is required.');
}

// Validate token format (64 lowercase hex chars)
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    jsonError('Invalid token format.');
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, email FROM email_tokens
         WHERE  token = :token
           AND  used = 0
           AND  expires_at > NOW()
         LIMIT  1'
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonError('Invalid or expired verification token.');
    }

    // Optional: cross-check that the email matches
    if ($email !== '' && strtolower($row['email']) !== $email) {
        jsonError('Token does not match the provided email address.');
    }

    // Mark token as used
    $db->prepare('UPDATE email_tokens SET used = 1 WHERE id = :id')
       ->execute(['id' => $row['id']]);

    // Persist in session
    $_SESSION['email_verified'] = $row['email'];

    jsonOk(['email' => $row['email']], 'Email address verified successfully.');

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
