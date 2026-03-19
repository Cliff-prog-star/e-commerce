<?php
/**
 * Admin authentication helper for review endpoints.
 */

require_once __DIR__ . '/response.php';

function requireAdminAuth(): void
{
    $expected = getenv('ADMIN_REVIEW_KEY') ?: '';
    if ($expected === '') {
        jsonError('Admin review key is not configured.', 500);
    }

    $provided = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    if (!hash_equals($expected, $provided)) {
        jsonError('Unauthorized admin request.', 401);
    }
}
