<?php
/**
 * POST /backend/api/auth/send_email.php
 * Body: { "email": "user@example.com" }
 *
 * Creates a 64-character verification token valid for 30 minutes.
 * Sends verification instructions to email.
 * Demo token return can be toggled by env ALLOW_DEMO_VERIFICATION.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

function envFlagEnabled(string $name): bool
{
    $raw = strtolower(trim((string) (getenv($name) ?: '')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function sendVerificationEmail(string $email, string $token): array
{
    $appName = getenv('APP_NAME') ?: 'FASHION HUB';
    $mailFrom = getenv('MAIL_FROM') ?: 'no-reply@fashionhub.local';
    $baseUrl = getenv('APP_BASE_URL') ?: '';

    $subject = $appName . ' Email Verification Token';
    $bodyLines = [
        'Your email verification token is:',
        $token,
        '',
        'This token expires in 30 minutes.',
    ];

    if ($baseUrl !== '') {
        $verifyUrl = rtrim($baseUrl, '/')
            . '/retailer_registration.html?token=' . urlencode($token)
            . '&email=' . urlencode($email);

        $bodyLines[] = '';
        $bodyLines[] = 'Optional verification link:';
        $bodyLines[] = $verifyUrl;
    }

    $body = implode("\r\n", $bodyLines);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $appName . ' <' . $mailFrom . '>',
    ];

    $sent = @mail($email, $subject, $body, implode("\r\n", $headers));
    if ($sent) {
        return ['ok' => true, 'error' => null];
    }

    return ['ok' => false, 'error' => 'mail() failed. Configure SMTP/sendmail on the server.'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$email = strtolower(input('email'));

if ($email === '') {
    jsonError('Email address is required.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Invalid email address format.');
}

$token   = bin2hex(random_bytes(32)); // 64-char hex token
$expires = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

try {
    $db = getDB();

    // Invalidate previous unused tokens for this email
    $db->prepare('UPDATE email_tokens SET used = 1 WHERE email = :email AND used = 0')
       ->execute(['email' => $email]);

    $db->prepare(
        'INSERT INTO email_tokens (email, token, expires_at) VALUES (:email, :token, :expires)'
    )->execute(['email' => $email, 'token' => $token, 'expires' => $expires]);

    // Store email in session for cross-checking during verify
    $_SESSION['email_pending'] = $email;

    $allowDemo = envFlagEnabled('ALLOW_DEMO_VERIFICATION') || strtolower((string) getenv('APP_ENV')) !== 'production';
    $emailResult = sendVerificationEmail($email, $token);

    if ($emailResult['ok']) {
        jsonOk([], 'Email verification message sent.');
    }

    if ($allowDemo) {
        jsonOk(['token' => $token], 'Email gateway failed; using demo token response.');
    }

    jsonError('Failed to send verification email. ' . ($emailResult['error'] ?? ''), 500);

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
