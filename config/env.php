<?php

/**
 * Simple .env loader for Subnext.
 *
 * Loads variables from:
 * service-platform/.env
 */

$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {

    $lines = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

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

        // Remove surrounding quotes
        $value = trim($value, "\"'");

        if ($name === '') {
            continue;
        }

        // Do not overwrite existing server environment variables
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}