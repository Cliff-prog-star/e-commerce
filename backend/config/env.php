<?php
/**
 * Lightweight .env bootstrap for local/dev use.
 * Loads variables only when they are not already set by the host environment.
 */

if (!function_exists('appStrStartsWith')) {
    function appStrStartsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('appStrEndsWith')) {
    function appStrEndsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('appLoadEnvFile')) {
    function appLoadEnvFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || appStrStartsWith($trimmed, '#')) {
                continue;
            }

            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $pos));
            $value = trim(substr($trimmed, $pos + 1));

            if ($key === '') {
                continue;
            }

            if ((appStrStartsWith($value, '"') && appStrEndsWith($value, '"')) ||
                (appStrStartsWith($value, "'") && appStrEndsWith($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('appBootstrapEnv')) {
    function appBootstrapEnv(): void
    {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }

        appLoadEnvFile(__DIR__ . '/../../.env');
        appLoadEnvFile(__DIR__ . '/../../.env.local');

        $bootstrapped = true;
    }
}
