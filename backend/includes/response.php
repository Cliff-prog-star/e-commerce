<?php
/**
 * JSON response helpers and input utilities.
 * Required by every API endpoint together with cors.php.
 */

/**
 * Register runtime guards so API responses remain JSON even on PHP warnings/fatals.
 */
function registerJsonRuntimeGuards(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    if (ob_get_level() === 0) {
        ob_start();
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $debug = strtolower((string) (getenv('APP_ENV') ?: 'development')) !== 'production';
        $detail = $debug ? " {$message} in {$file}:{$line}" : '';
        jsonError('Server runtime error.' . $detail, 500);
        return true;
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $debug = strtolower((string) (getenv('APP_ENV') ?: 'development')) !== 'production';
        $detail = $debug
            ? ' ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
            : '';

        echo json_encode([
            'status' => 'error',
            'message' => 'Server fatal error.' . $detail,
        ], JSON_UNESCAPED_UNICODE);
    });
}

/**
 * Convert php.ini-style byte values (e.g. 8M, 1G) to integer bytes.
 */
function iniBytes(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }

    if (!preg_match('/^(\d+)([kmg])?$/i', $trimmed, $m)) {
        return (int) $trimmed;
    }

    $bytes = (int) $m[1];
    $unit = strtolower($m[2] ?? '');

    if ($unit === 'k') {
        return $bytes * 1024;
    }
    if ($unit === 'm') {
        return $bytes * 1024 * 1024;
    }
    if ($unit === 'g') {
        return $bytes * 1024 * 1024 * 1024;
    }

    return $bytes;
}

/**
 * Guard oversized JSON requests before body parsing.
 */
function enforceJsonRequestLimit(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) {
        return;
    }

    $phpLimit = iniBytes((string) ini_get('post_max_size'));
    if ($phpLimit > 0 && $contentLength > $phpLimit) {
        $limitMb = round($phpLimit / (1024 * 1024), 2);
        jsonError("Request is too large for current server limit (post_max_size={$limitMb}MB).", 413);
    }
}

/**
 * Send a 200 OK JSON response and stop execution.
 */
function jsonOk(array $data = [], string $message = 'success'): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode([
        'status'  => 'ok',
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send an error JSON response with the given HTTP status code and stop.
 */
function jsonError(string $message, int $httpCode = 400): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);
    echo json_encode([
        'status'  => 'error',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Decode and return the JSON request body as an associative array.
 * Returns [] if the body is missing or invalid JSON.
 */
function getJsonBody(): array
{
    static $body = null;
    if ($body === null) {
        enforceJsonRequestLimit();
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }
    }
    return $body;
}

/**
 * Return a trimmed string value from the decoded JSON body,
 * falling back to $_POST, then $_GET, then $default.
 */
function input(string $key, string $default = ''): string
{
    $body = getJsonBody();
    $value = $body[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    return trim((string) $value);
}

registerJsonRuntimeGuards();
