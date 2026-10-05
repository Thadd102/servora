<?php

/**
 * Secure session configuration
 *
 * LOCAL DEVELOPMENT:
 * secure = false
 *
 * PRODUCTION WITH HTTPS:
 * secure = true
 */

if (session_status() === PHP_SESSION_NONE) {

    // Prevent PHP from revealing unnecessary session information
    ini_set('session.use_strict_mode', '1');

    // Use cookies only for session IDs
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => ((getenv('APP_ENV') ?: 'development') === 'production') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}



// Baseline browser security headers.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        exit('Your session security token is invalid or expired. Please go back, refresh the page and try again.');
    }
}

/**
 * Session timeout
 *
 * 30 minutes of inactivity.
 */
function enforceSessionTimeout(): void
{
    $timeout = 1800; // 30 minutes

    if (isset($_SESSION['last_activity'])) {

        if ((time() - $_SESSION['last_activity']) > $timeout) {

            logoutUser();

            header("Location: ../login.php?timeout=1");
            exit;
        }
    }

    $_SESSION['last_activity'] = time();
}


/**
 * Check whether a user is logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'])
        && is_numeric($_SESSION['user_id']);
}


/**
 * Get the currently logged-in user's ID.
 */
function currentUserId(): ?int
{
    if (!isLoggedIn()) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}


/**
 * Get the currently logged-in user's role.
 */
function currentUserRole(): ?string
{
    return isset($_SESSION['role'])
        ? (string) $_SESSION['role']
        : null;
}


/**
 * Require the user to be logged in.
 */
function requireLogin(): void
{
    enforceSessionTimeout();

    if (!isLoggedIn()) {

        header("Location: ../login.php");
        exit;
    }
}


/**
 * Require a specific role.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();

    $role = currentUserRole();

    if ($role === null || !in_array($role, $allowedRoles, true)) {

        http_response_code(403);

        exit("Access denied.");
    }
}


/**
 * Regenerate session ID after authentication.
 *
 * Helps protect against session fixation.
 */
function regenerateUserSession(): void
{
    session_regenerate_id(true);

    $_SESSION['last_activity'] = time();
}


/**
 * Completely log out the current user.
 */
function logoutUser(): void
{
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}