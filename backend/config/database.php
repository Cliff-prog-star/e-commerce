<?php
/**
 * Database configuration.
 * Adjust DB_USER and DB_PASS to match your MySQL setup.
 * If you run XAMPP/WAMP with the default root and no password, leave DB_PASS as ''.
 */

require_once __DIR__ . '/env.php';
appBootstrapEnv();

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

define('DB_HOST',    envOrDefault('DB_HOST', 'localhost'));
define('DB_PORT',    envOrDefault('DB_PORT', '3306'));
define('DB_NAME',    envOrDefault('DB_NAME', 'fashion_marketplace'));
define('DB_USER',    envOrDefault('DB_USER', 'root'));
define('DB_PASS',    envOrDefault('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a singleton PDO connection.
 * Throws PDOException on connection failure.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
