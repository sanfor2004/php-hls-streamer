<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE VIDEOS INGESTION (upload.php)
 * =================================================================================
 * Bootstraps the layout engine, loads dynamic database variables, and includes
 * the modular videos ingestion upload section.
 * =================================================================================
 */

// 1. Include static variables, PDO connection, and layout wrapper engine
require_once __DIR__ . DIRECTORY_SEPARATOR . 'layout.php';

// 2. Render layout header template wrap (includes CSS links, left side navbar, stats metrics)
renderLayoutHeader($dbError, $settings, $dbFile, 'upload');
?>

<!-- 3. Include the video ingestion section -->
<?php include __DIR__ . DIRECTORY_SEPARATOR . 'sections' . DIRECTORY_SEPARATOR . 'upload.php'; ?>

<?php
// 4. Render layout footer wrap (includes previews player, notification toast, scripts links)
renderLayoutFooter();
?>
