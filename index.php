<?php
declare(strict_types=1);

/**
 * =================================================================================
 * PHP BUILT-IN SERVER ROUTER  +  DASHBOARD ENTRY POINT (index.php)
 * =================================================================================
 * Dual-purpose file:
 *   • When run as a PHP cli-server router script, intercepts every request and
 *     dispatches it to the appropriate .php handler (clean-URL support for dev).
 *   • When loaded directly (as /index or / on the dashboard), bootstraps the
 *     layout engine and renders the Videos Registry tab.
 *
 * ROUTER SECURITY NOTE
 * ─────────────────────
 * The router ONLY serves PHP-handled routes. Requests for static assets (JS, CSS,
 * images, etc.) return false so the built-in server handles them natively.
 * This prevents the router from accidentally falling through to layout.php
 * (which calls requireLogin()) for any request that isn't a known PHP route.
 * =================================================================================
 */

$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath  = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// ── Static asset pass-through ──────────────────────────────────────────
// If the requested path maps to a real file on disk (CSS, JS, images, fonts,
// etc.), instruct the built-in server to serve it natively by returning false.
// This MUST happen before any route matching to avoid the router consuming
// asset requests and falling through to layout.php unexpectedly.
if (PHP_SAPI === 'cli-server' && $parsedPath !== '/' && is_file(__DIR__ . $parsedPath)) {
    return false; // Let built-in server handle static files directly
}

// ── Route dispatch ─────────────────────────────────────────────────────
$path        = ltrim($parsedPath, '/');
$segments    = explode('/', $path);
$baseSegment = $segments[0] ?? '';

// Treat empty segment (root /) as 'index'
if ($baseSegment === '') {
    $baseSegment = 'index';
}

$routes = ['index', 'settings', 'upload', 'ads', 'login', 'logout', 'api', 'stream', 'b2_gateway'];

if (in_array($baseSegment, $routes, true)) {
    // Patch $_SERVER so included scripts see the correct script identity
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . $baseSegment . '.php';
    $_SERVER['SCRIPT_NAME']     = '/' . $baseSegment . '.php';
    $_SERVER['PHP_SELF']        = '/' . $baseSegment . '.php';

    if (count($segments) > 1 && $segments[1] !== '') {
        $_SERVER['PATH_INFO'] = '/' . implode('/', array_slice($segments, 1));
    }

    // Non-index routes: hand off to their own .php file and stop.
    // index is handled below (falls through to layout.php + renderLayout*).
    if ($baseSegment !== 'index') {
        require __DIR__ . DIRECTORY_SEPARATOR . $baseSegment . '.php';
        exit;
    }

    // For 'index', fall through to the dashboard render below.
} else {
    // Unknown route — return 404 via built-in server default handler.
    // Do NOT fall through to layout.php; that would trigger requireLogin()
    // on routes that don't exist and create spurious redirect loops.
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:monospace;background:#030712;color:#94a3b8;padding:2rem">'
       . '<h1 style="color:#f97316">404 Not Found</h1>'
       . '<p>The requested resource was not found on this server.</p>'
       . '</body></html>';
    exit;
}

// =============================================================================
// DASHBOARD: Videos Registry (index / root)
// =============================================================================

// 1. Include static variables, PDO connection, and layout wrapper engine
require_once __DIR__ . DIRECTORY_SEPARATOR . 'layout.php';

// 2. Render layout header template wrap (includes CSS links, left side navbar, stats metrics)
renderLayoutHeader($dbError, $settings, $dbFile, 'videos');
?>

<!-- 3. Include the videos registry section -->
<?php include __DIR__ . DIRECTORY_SEPARATOR . 'sections' . DIRECTORY_SEPARATOR . 'videos.php'; ?>

<?php
// 4. Render layout footer wrap (includes previews player, notification toast, scripts links)
renderLayoutFooter();
?>
