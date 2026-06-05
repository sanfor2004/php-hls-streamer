<?php
declare(strict_types=1);

// Local PHP Development Server Routing Bridge (MultiViews / .htaccess fallback)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = ltrim($path, '/');
    $segments = explode('/', $path);
    $baseSegment = $segments[0] ?? '';
    
    $routes = ['index', 'settings', 'upload', 'ads', 'login', 'logout', 'api', 'stream', 'b2_gateway'];
    if (in_array($baseSegment, $routes)) {
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . $baseSegment . '.php';
        $_SERVER['SCRIPT_NAME'] = '/' . $baseSegment . '.php';
        $_SERVER['PHP_SELF'] = '/' . $baseSegment . '.php';
        if (count($segments) > 1) {
            $_SERVER['PATH_INFO'] = '/' . implode('/', array_slice($segments, 1));
        }
        if ($baseSegment !== 'index') {
            require __DIR__ . DIRECTORY_SEPARATOR . $baseSegment . '.php';
            exit;
        }
    }
}

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
