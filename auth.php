<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE SECURITY SESSION GUARD (auth.php)
 * =================================================================================
 * Manages administrative session states, verifies authentication tokens,
 * and handles secure redirects for all protected console resources.
 * =================================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,              // Session cookie expires when browser closes
        'cookie_secure'   => isset($_SERVER['HTTPS']), // Secure cookie if HTTPS is active
        'cookie_httponly' => true,             // Prevent script access to session cookie
        'cookie_samesite' => 'Lax',            // Protect against cross-site request forgery
    ]);
}

/**
 * Checks if the user is authenticated in the current session.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['username']);
}

/**
 * Enforces authentication. Redirects to the login portal if unauthenticated.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login');
        exit;
    }
}
