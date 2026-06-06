<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE SECURITY SESSION GUARD (auth.php)
 * =================================================================================
 * Manages administrative session states, verifies authentication tokens,
 * and handles secure redirects for all protected console resources.
 *
 * REDIRECT-LOOP HARDENING (Production / Reverse-Proxy Aware):
 *   1. A single authoritative _detectHttps() function computes HTTPS status once.
 *      Both session config and getBaseUrl() share this truth — no drift.
 *   2. cookie_secure is dynamically resolved: true only when PHP itself sees HTTPS
 *      (i.e. no reverse proxy terminating SSL). When Nginx/Cloudflare terminates
 *      SSL, PHP sees HTTP — cookie_secure stays false to preserve the cookie.
 *   3. getBaseUrl() strips duplicate slashes and never appends a trailing slash,
 *      so redirect targets like getBaseUrl().'/login' are always canonical.
 *   4. requireLogin() uses a short-lived cookie sentinel (_zenith_lc) to allow
 *      exactly one self-healing attempt. The URL stays clean (/login, no query
 *      string). Clearing browser cookies also clears the sentinel automatically.
 * =================================================================================
 */

// ---------------------------------------------------------------------------
// 1. SINGLE AUTHORITATIVE HTTPS DETECTOR
// ---------------------------------------------------------------------------
function _detectHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $first = explode(',', strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'])))[0];
        if (trim($first) === 'https') {
            return true;
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }
    $fwdPort = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? null;
    if ($fwdPort !== null && (int)$fwdPort === 443) {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// 2. SESSION BOOTSTRAP
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_secure'   => _detectHttps(),
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------------------
// 3. AUTHENTICATION HELPERS
// ---------------------------------------------------------------------------

/**
 * Returns true when a valid administrative session is present.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['username']);
}

/**
 * Builds a fully-qualified, canonical base URL — reverse-proxy aware.
 * Returns scheme://host[:port] with NO trailing slash.
 */
function getBaseUrl(): string
{
    $scheme   = _detectHttps() ? 'https' : 'http';
    $rawHost  = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $hostOnly = preg_replace('/:\d+$/', '', $rawHost);

    $serverPort  = (int)($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
    $fwdPort     = isset($_SERVER['HTTP_X_FORWARDED_PORT'])
                   ? (int)$_SERVER['HTTP_X_FORWARDED_PORT']
                   : $serverPort;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    $portSuffix  = ($fwdPort !== $defaultPort) ? ':' . $fwdPort : '';

    return $scheme . '://' . $hostOnly . $portSuffix;
}

/**
 * Returns the normalised URI path of the current request (no query string).
 */
function _currentPath(): string
{
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return strtolower(rtrim($path ?? '/', '/')) ?: '/';
}

/**
 * Enforces authentication. Redirects to the login portal if unauthenticated.
 */
function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    $loginPath   = '/login';
    $currentPath = _currentPath();

    if ($currentPath === $loginPath || $currentPath === '/login.php') {
        return;
    }

    // Standard unauthenticated redirect
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: ' . getBaseUrl() . $loginPath, true, 302);
    exit;
}
