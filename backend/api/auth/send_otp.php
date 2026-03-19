<?php
/**
 * POST /backend/api/auth/send_otp.php
 * Body: { "phone": "+254700000000" }
 *
 * Generates a 6-digit OTP and stores it in DB with 10-minute expiry.
 * Sends OTP via configured SMS provider (Twilio supported).
 * Demo OTP return can be toggled by env ALLOW_DEMO_VERIFICATION.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

function envFlagEnabled(string $name): bool
{
    $raw = strtolower(trim((string) (getenv($name) ?: '')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\s+|-/', '', trim($phone));
}

function sendSmsOtpTwilio(string $phone, string $otp): array
{
    $sid  = getenv('TWILIO_ACCOUNT_SID') ?: '';
    $auth = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $from = getenv('TWILIO_FROM_NUMBER') ?: '';

    if ($sid === '' || $auth === '' || $from === '') {
        return ['ok' => false, 'error' => 'Twilio environment variables are missing.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled on the server.'];
    }

    $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    $messageBody = 'Your FASHION HUB OTP is: ' . $otp . '. It expires in 10 minutes.';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $phone,
            'From' => $from,
            'Body' => $messageBody,
        ]),
        CURLOPT_USERPWD => $sid . ':' . $auth,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Handle cleanup is automatic in modern PHP versions.

    if ($response === false) {
        return ['ok' => false, 'error' => 'Twilio request failed: ' . $curlError];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'error' => 'Twilio API error (HTTP ' . $statusCode . ').'];
    }

    return ['ok' => true, 'error' => null];
}

function sendSmsOtp(string $phone, string $otp): array
{
    $provider = strtolower(trim((string) (getenv('SMS_PROVIDER') ?: 'twilio')));

    if ($provider === 'twilio') {
        return sendSmsOtpTwilio($phone, $otp);
    }

    return ['ok' => false, 'error' => 'Unsupported SMS provider: ' . $provider];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$phone = normalizePhone(input('phone'));

if ($phone === '') {
    jsonError('Phone number is required.');
}

// Basic phone sanity check
if (!preg_match('/^\+?[\d\s\-]{7,20}$/', $phone)) {
    jsonError('Invalid phone number format.');
}

$otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

try {
    $db = getDB();

    // Invalidate any existing unused OTPs for this phone
    $db->prepare('UPDATE otp_codes SET used = 1 WHERE phone = :phone AND used = 0')
       ->execute(['phone' => $phone]);

    $db->prepare(
        'INSERT INTO otp_codes (phone, otp_code, expires_at) VALUES (:phone, :otp, :expires)'
    )->execute(['phone' => $phone, 'otp' => $otp, 'expires' => $expires]);

    // Store phone in session so verify_otp can cross-check
    $_SESSION['otp_phone'] = $phone;

    $allowDemo = envFlagEnabled('ALLOW_DEMO_VERIFICATION') || strtolower((string) getenv('APP_ENV')) !== 'production';
    $smsResult = sendSmsOtp($phone, $otp);

    if ($smsResult['ok']) {
        jsonOk([], 'OTP sent successfully.');
    }

    if ($allowDemo) {
        jsonOk(['otp' => $otp], 'OTP generated. SMS gateway failed; using demo OTP response.');
    }

    jsonError('Failed to send OTP SMS. ' . ($smsResult['error'] ?? ''), 500);

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
