<?php

/**
 * Subnext Environment Configuration Loader
 *
 * Securely loads environment settings from .env file:
 * - Checks current project root (.env)
 * - Checks parent directory (for shared hosting installations where .env is stored outside public_html)
 * - Populates putenv(), $_ENV, and $_SERVER
 * - Configures production error handling and secure logging
 */

if (!isset($GLOBALS['SUBNEXT_ENV_DATA'])) {
    $GLOBALS['SUBNEXT_ENV_DATA'] = [];
}

$envCandidatePaths = array_unique(array_filter([
    __DIR__ . '/../.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
    !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/.env' : null,
    !empty($_SERVER['DOCUMENT_ROOT']) ? dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) . '/.env' : null,
    getcwd() ? rtrim(getcwd(), '/\\') . '/.env' : null,
]));

$envFile = null;
foreach ($envCandidatePaths as $candidatePath) {
    if (file_exists($candidatePath) && is_readable($candidatePath)) {
        $envFile = realpath($candidatePath) ?: $candidatePath;
        break;
    }
}

$GLOBALS['SUBNEXT_ENV_FILE'] = $envFile;

if ($envFile !== null) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Ignore comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Ignore invalid lines
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        // Remove surrounding single or double quotes
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($name === '') {
            continue;
        }

        // Set in memory store, getenv, $_ENV, and $_SERVER
        $GLOBALS['SUBNEXT_ENV_DATA'][$name] = $value;
        @putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
} else {
    error_log('[ENV LOADER] Notice: No readable .env file found in candidate paths: ' . implode(' | ', $envCandidatePaths));
}

/**
 * Robust environment variable accessor that bypasses disabled putenv() or missing $_ENV orders
 */
if (!function_exists('subnextEnv')) {
    function subnextEnv(string $key, ?string $default = null): ?string
    {
        if (isset($GLOBALS['SUBNEXT_ENV_DATA'][$key]) && $GLOBALS['SUBNEXT_ENV_DATA'][$key] !== '') {
            return (string)$GLOBALS['SUBNEXT_ENV_DATA'][$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }
        return $default;
    }
}

/**
 * Returns the exact absolute path of the loaded .env file, or null if not found
 */
if (!function_exists('getEnvFilePath')) {
    function getEnvFilePath(): ?string
    {
        return $GLOBALS['SUBNEXT_ENV_FILE'] ?? null;
    }
}

// Determine environment (defaults to production for safety)
$appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? 'production'));

// Production error reporting and logging configuration
if ($appEnv === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

ini_set('log_errors', '1');