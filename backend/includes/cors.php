<?php
/**
 * CORS + common HTTP headers for all JSON API endpoints.
 * Also starts the PHP session and handles OPTIONS pre-flight.
 *
 * Every API file must require this before any output.
 */

require_once __DIR__ . '/../config/env.php';
appBootstrapEnv();

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Admin-Key');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS allowlist (comma-separated), e.g. "https://shop.example.com,https://admin.example.com"
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginsRaw = getenv('CORS_ALLOWED_ORIGINS') ?: (getenv('ALLOWED_ORIGINS') ?: '');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsRaw))));

if ($origin !== '' && (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true))) {
    // With credentials, Access-Control-Allow-Origin cannot be '*'.
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

// Respond to OPTIONS pre-flight and stop
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin !== '' && !empty($allowedOrigins) && !in_array($origin, $allowedOrigins, true) && !in_array('*', $allowedOrigins, true)) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

// Start session for retailer auth state with stricter cookie defaults.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
