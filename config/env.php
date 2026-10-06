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

$envCandidatePaths = [
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env'
];

$envFile = null;
foreach ($envCandidatePaths as $candidatePath) {
    if (file_exists($candidatePath) && is_readable($candidatePath)) {
        $envFile = $candidatePath;
        break;
    }
}

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

        // Set in getenv, $_ENV, and $_SERVER
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
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