<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE SECURITY LOGOUT HANDLER (logout.php)
 * =================================================================================
 * Securely terminates the administrative session, clears auth cookies,
 * and redirects back to the login gateway.
 * =================================================================================
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

// Unset all session variables
$_SESSION = [];

// Destroy session cookie on client browser if active
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session record on server
session_destroy();

// Redirect back to login portal with success indicator
header('Location: login?logout=1');
exit;
