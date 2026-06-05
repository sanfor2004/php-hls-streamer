<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE VIDEOS REGISTRY (index.php)
 * =================================================================================
 * Bootstraps the layout engine, loads dynamic database variables, and includes
 * the modular videos registry section.
 * =================================================================================
 */

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
