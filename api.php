<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH-TIER CORE VANILLA PHP VIDEO INGESTION & AD INTEGRATION PLATFORM (SQLITE)
 * =================================================================================
 * This is the unified entrypoint for routing, database interaction via SQLite PDO,
 * chunked video ingestion API, VAST ad manager endpoints, and the HTML dashboard.
 *
 * All operations are written in pure Vanilla PHP (PSR-12 compliant) and heavily
 * commented pure HTML, CSS, and JS.
 * =================================================================================
 */

// ---------------------------------------------------------------------------------
// DATABASE CONNECTION CONFIGURATION (SELF HEALING)
// ---------------------------------------------------------------------------------
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
$dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite';
$pdo = null;
$dbError = null;

try {
    $pdo = getDatabaseConnection();
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

/**
 * Automatically boots the database schema tables. (Legacy wrapper pointing to unified db.php migrator)
 */
function initializeSqliteDatabase(PDO $pdo): void
{
    initializeDatabaseSchema($pdo);
}

/**
 * Automatically boots the database schema tables inside SQLite. (Legacy placeholder)
 */
function initializeDatabaseSchemaLegacy(PDO $pdo): void
{
    // 1. Create streams table to store master uploads details
    $pdo->exec("CREATE TABLE IF NOT EXISTS `streams` (
        `id` TEXT PRIMARY KEY,
        `title` TEXT NOT NULL,
        `filename` TEXT NOT NULL,
        `status` TEXT NOT NULL DEFAULT 'uploading',
        `video_codec` TEXT DEFAULT NULL,
        `duration` INTEGER DEFAULT NULL,
        `resolutions_selected` TEXT NOT NULL,
        `hls_playlist_url` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );");

    // 2. Create stream_resolutions table to track individual format progress
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stream_resolutions` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `stream_id` TEXT NOT NULL,
        `resolution` TEXT NOT NULL,                -- '1080p', '720p', etc.
        `width` INTEGER NOT NULL,
        `height` INTEGER NOT NULL,
        `status` TEXT NOT NULL DEFAULT 'pending',   -- 'pending', 'processing', 'completed', 'failed'
        `playlist_path` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
    );");

    // 3. Create ads table to record VAST schedule rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ads` (
        `id` TEXT PRIMARY KEY,
        `name` TEXT NOT NULL,
        `vast_url` TEXT NOT NULL,
        `offset_type` TEXT NOT NULL,                -- 'preroll', 'midroll', 'postroll'
        `offset_value` TEXT NOT NULL,               -- '0', seconds, percentage, or timestamp
        `is_active` INTEGER NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );");

    // 4. Create settings table for dynamic transcode configurations
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key` TEXT PRIMARY KEY,
        `value` TEXT NOT NULL
    );");

    // 5. Create users table for dashboard access authentication
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `username` TEXT NOT NULL UNIQUE,
        `password_hash` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );");

    // Check if table is empty, and seed the default configuration values if so
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `settings`")->fetchColumn();
        if ($count === 0) {
            $defaults = [
                'video_codec'     => 'h264',
                'keyframe'        => '60',
                'bitrate_ratio'   => '1.07',
                'buffer_ratio'    => '1.5',
                'hls_time'        => '6',
                'add_top_quality' => '1',
                'audio_codec'     => 'aac',
                'audio_bitrate'   => '128k',
                'audio_channels'  => 'stereo',
                'ffmpeg_threads'  => '0',
                'ffmpeg_preset'   => 'veryfast',
                'parallel_transcode' => '0',
                'renditions'      => json_encode([
                    '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 25, 'vbitrate' => '4096k', 'abitrate' => '192k'],
                    '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 26, 'vbitrate' => '2048k', 'abitrate' => '128k'],
                    '540p'  => ['width' => 960,  'height' => 540,  'crf' => 27, 'vbitrate' => '1500k', 'abitrate' => '128k'],
                    '480p'  => ['width' => 854,  'height' => 480,  'crf' => 28, 'vbitrate' => '750k',  'abitrate' => '128k'],
                    '360p'  => ['width' => 640,  'height' => 360,  'crf' => 29, 'vbitrate' => '276k',  'abitrate' => '96k'],
                ])
            ];

            $stmt = $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (:key, :value)");
            foreach ($defaults as $key => $val) {
                $stmt->execute([':key' => $key, ':value' => $val]);
            }
        }
    } catch (PDOException $e) {
        // Fallback or ignore if write fails in concurrent environment
    }

    // Check if users table is empty, and seed default admin account
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
        if ($count === 0) {
            $defaultUsername = 'admin';
            $defaultPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password_hash`) VALUES (:username, :password_hash)");
            $stmt->execute([':username' => $defaultUsername, ':password_hash' => $defaultPasswordHash]);
        }
    } catch (PDOException $e) {
        // Fallback or ignore if write fails
    }
}

// ---------------------------------------------------------------------------------
// SYSTEM ROOT DIRECTORIES DEFINITIONS
// ---------------------------------------------------------------------------------
$rootDir = __DIR__;
$inputDir = $rootDir . DIRECTORY_SEPARATOR . 'Input';
$chunksDir = $inputDir . DIRECTORY_SEPARATOR . 'chunks';
$outputDir = $rootDir . DIRECTORY_SEPARATOR . 'Output';

if (!is_dir($inputDir)) {
    mkdir($inputDir, 0775, true);
}
if (!is_dir($chunksDir)) {
    mkdir($chunksDir, 0775, true);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

// ---------------------------------------------------------------------------------
// CORE API ROUTING
// ---------------------------------------------------------------------------------
// Only run the core router if api.php is requested directly as a web endpoint.
// This prevents execution side-effects when api.php is required/included in other files.
if (basename($_SERVER['SCRIPT_FILENAME']) === 'api.php') {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
    $action = $_GET['action'] ?? 'home';

    // Protect all API actions and views
    if (!isLoggedIn()) {
        if ($action === 'home') {
            header('Location: ' . getBaseUrl() . '/login');
            exit;
        } else {
            sendJsonResponse(['error' => 'Unauthorized. Please login first.'], 401);
        }
    }

    switch ($action) {
        case 'upload_chunk':
            handleChunkUpload($chunksDir, $inputDir, $pdo);
            break;
        case 'list':
            handleListStreams($pdo);
            break;
        case 'update_stream':
            handleUpdateStream($pdo);
            break;
        case 'delete_stream':
            handleDeleteStream($pdo, $inputDir, $outputDir);
            break;
        case 'get_settings':
            handleGetSettings($pdo);
            break;
        case 'save_settings':
            handleSaveSettings($pdo);
            break;
        case 'add_ad':
            handleAddAdCampaign($pdo);
            break;
        case 'list_ads':
            handleListAdCampaigns($pdo);
            break;
        case 'delete_ad':
            handleDeleteAdCampaign($pdo);
            break;
        case 'change_password':
            handleChangePassword($pdo);
            break;
        case 'home':
        default:
            renderUploadDashboard($dbError, $pdo);
            break;
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: CHUNKED UPLOAD HANDLER
// ---------------------------------------------------------------------------------
function handleChunkUpload(string $chunksDir, string $inputDir, ?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline. SQLite connection failed.'], 500);
    }

    $fileId = $_POST['file_id'] ?? '';
    $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : -1;
    $totalChunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : -1;
    $filename = $_POST['filename'] ?? '';
    $resolutionsString = $_POST['resolutions'] ?? '720p';
    $customTitle = $_POST['title'] ?? '';
    $expectedChunkSize = isset($_POST['expected_chunk_size']) ? (int)$_POST['expected_chunk_size'] : -1;
    $totalFileSize = isset($_POST['total_file_size']) ? (int)$_POST['total_file_size'] : -1;

    if (empty($fileId) || $chunkIndex < 0 || $totalChunks <= 0 || empty($filename)) {
        sendJsonResponse(['error' => 'Missing or invalid upload parameters.'], 400);
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedExtensions = ['mp4', 'mkv', 'ts', 'avi', 'mov'];
    if (!in_array($extension, $allowedExtensions, true)) {
        sendJsonResponse(['error' => 'Unsupported format. Allowed: ' . implode(', ', $allowedExtensions)], 400);
    }

    if (!isset($_FILES['video_chunk']) || $_FILES['video_chunk']['error'] !== UPLOAD_ERR_OK) {
        sendJsonResponse(['error' => 'Chunk file upload error.'], 400);
    }

    $fileChunkDir = $chunksDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_-]/', '', $fileId);
    if (!is_dir($fileChunkDir)) {
        mkdir($fileChunkDir, 0775, true);
    }

    $chunkFilePath = $fileChunkDir . DIRECTORY_SEPARATOR . 'chunk_' . $chunkIndex . '.part';
    if (!move_uploaded_file($_FILES['video_chunk']['tmp_name'], $chunkFilePath)) {
        sendJsonResponse(['error' => 'Failed to save uploaded chunk.'], 500);
    }

    if ($expectedChunkSize >= 0) {
        $actualChunkSize = filesize($chunkFilePath);
        if ($actualChunkSize !== $expectedChunkSize) {
            @unlink($chunkFilePath);
            sendJsonResponse(['error' => "Chunk size mismatch. Expected: {$expectedChunkSize}, Got: {$actualChunkSize}."], 400);
        }
    }

    if ($chunkIndex == 0 && isset($_FILES['subtitle_file']) && $_FILES['subtitle_file']['error'] === UPLOAD_ERR_OK) {
        $subExt = strtolower(pathinfo($_FILES['subtitle_file']['name'], PATHINFO_EXTENSION));
        if (in_array($subExt, ['vtt', 'srt'])) {
            $safeOriginalSubName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['subtitle_file']['name']);
            $subDest = $inputDir . DIRECTORY_SEPARATOR . $fileId . '_external_sub_' . $safeOriginalSubName;
            move_uploaded_file($_FILES['subtitle_file']['tmp_name'], $subDest);
        }
    }

    $files = glob($fileChunkDir . DIRECTORY_SEPARATOR . '*.part');
    $chunksSaved = count($files);

    if ($chunksSaved < $totalChunks) {
        sendJsonResponse([
            'status' => 'uploading',
            'message' => "Chunk {$chunkIndex} of {$totalChunks} saved successfully.",
            'progress' => round(($chunksSaved / $totalChunks) * 100, 2)
        ]);
    }

    $safeOriginalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    $finalFilename = $fileId . '_' . $safeOriginalName;
    $finalPath = $inputDir . DIRECTORY_SEPARATOR . $finalFilename;

    $outStream = @fopen($finalPath, 'wb');
    if (!$outStream) {
        sendJsonResponse(['error' => 'Stitching failed: unable to open final destination file.'], 500);
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $partPath = $fileChunkDir . DIRECTORY_SEPARATOR . 'chunk_' . $i . '.part';
        $inStream = @fopen($partPath, 'rb');
        
        if (!$inStream) {
            fclose($outStream);
            sendJsonResponse(['error' => "Stitching failed: missing chunk index {$i}."], 500);
        }

        while ($buff = fread($inStream, 4096)) {
            fwrite($outStream, $buff);
        }

        fclose($inStream);
    }

    fclose($outStream);

    // Verify stitched file integrity
    if ($totalFileSize >= 0) {
        $actualFileSize = filesize($finalPath);
        if ($actualFileSize !== $totalFileSize) {
            @unlink($finalPath);
            for ($i = 0; $i < $totalChunks; $i++) {
                @unlink($fileChunkDir . DIRECTORY_SEPARATOR . 'chunk_' . $i . '.part');
            }
            @rmdir($fileChunkDir);
            sendJsonResponse(['error' => "Assembled file size mismatch. Expected: {$totalFileSize}, Got: {$actualFileSize}. Video is corrupted and has been rejected."], 500);
        }
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        @unlink($fileChunkDir . DIRECTORY_SEPARATOR . 'chunk_' . $i . '.part');
    }
    @rmdir($fileChunkDir);

    try {
        $resolutionsSelected = json_encode(explode(',', $resolutionsString));
        $streamTitle = !empty($customTitle) ? trim($customTitle) : $filename;
        
        $sql = "INSERT INTO `streams` (`id`, `title`, `filename`, `status`, `resolutions_selected`) 
                VALUES (:id, :title, :filename, :status, :resolutions_selected)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $fileId,
            ':title' => $streamTitle,
            ':filename' => $finalFilename,
            ':status' => 'pending',
            ':resolutions_selected' => $resolutionsSelected
        ]);

        $transcodeScript = __DIR__ . DIRECTORY_SEPARATOR . 'transcode.php';
        $activeMode = isDevelopmentMode() ? 'dev' : 'prod';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = "start /B php \"" . $transcodeScript . "\" " . escapeshellarg($fileId) . " " . escapeshellarg($activeMode) . " > NUL 2>&1";
            pclose(popen($cmd, "r"));
        } else {
            $cmd = "php \"" . $transcodeScript . "\" " . escapeshellarg($fileId) . " " . escapeshellarg($activeMode) . " > /dev/null 2>&1 &";
            exec($cmd);
        }

        sendJsonResponse([
            'status' => 'completed',
            'message' => 'File uploaded and stitched successfully. Transcode background worker launched.',
            'id' => $fileId
        ]);

    } catch (PDOException $e) {
        @unlink($finalPath);
        sendJsonResponse(['error' => 'Database write failure: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: STREAM RETRIEVAL LIST
// ---------------------------------------------------------------------------------
function handleListStreams(?PDO $pdo): void
{
    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM `streams` ORDER BY `created_at` DESC");
        sendJsonResponse($stmt->fetchAll());
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Query error: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: ADD NEW VAST AD CAMPAIGN (PHASE 3)
// ---------------------------------------------------------------------------------
function handleAddAdCampaign(?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    $name = $_POST['name'] ?? '';
    $vastUrl = $_POST['vast_url'] ?? '';
    $offsetType = $_POST['offset_type'] ?? 'preroll';
    $offsetValue = $_POST['offset_value'] ?? '0';

    if (empty($name) || empty($vastUrl)) {
        sendJsonResponse(['error' => 'Name and VAST XML URL are required.'], 400);
    }

    if (!filter_var($vastUrl, FILTER_VALIDATE_URL)) {
        sendJsonResponse(['error' => 'Invalid VAST XML source URL format.'], 400);
    }

    if ($offsetType === 'preroll') {
        $offsetValue = '0';
    } elseif ($offsetType === 'midroll') {
        $offsetValue = trim($offsetValue);
        if ($offsetValue === '') {
            $offsetValue = '10';
        }
    } elseif ($offsetType === 'postroll') {
        $offsetValue = 'postroll';
    }

    try {
        $id = bin2hex(random_bytes(8));
        $sql = "INSERT INTO `ads` (`id`, `name`, `vast_url`, `offset_type`, `offset_value`, `is_active`) 
                VALUES (:id, :name, :vast_url, :offset_type, :offset_value, 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':vast_url' => $vastUrl,
            ':offset_type' => $offsetType,
            ':offset_value' => $offsetValue
        ]);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'VAST ad campaign saved successfully.',
            'id' => $id
        ]);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Failed to save ad campaign: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: LIST ALL VAST AD CAMPAIGNS (PHASE 3)
// ---------------------------------------------------------------------------------
function handleListAdCampaigns(?PDO $pdo): void
{
    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM `ads` ORDER BY `created_at` DESC");
        sendJsonResponse($stmt->fetchAll());
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Failed to fetch ad campaigns: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: DELETE AD CAMPAIGN (PHASE 3)
// ---------------------------------------------------------------------------------
function handleDeleteAdCampaign(?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        sendJsonResponse(['error' => 'Missing target campaign ID.'], 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM `ads` WHERE `id` = :id");
        $stmt->execute([':id' => $id]);
        sendJsonResponse([
            'status' => 'success',
            'message' => 'Campaign deleted successfully.'
        ]);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Database delete operation failed: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: GET DYNAMIC SETTINGS
// ---------------------------------------------------------------------------------
function handleGetSettings(?PDO $pdo): void
{
    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM `settings`");
        $settingsRaw = $stmt->fetchAll();
        $settings = [];
        foreach ($settingsRaw as $row) {
            if ($row['key'] === 'renditions') {
                $settings[$row['key']] = json_decode($row['value'], true);
            } else {
                $settings[$row['key']] = $row['value'];
            }
        }
        sendJsonResponse($settings);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Failed to fetch settings: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: SAVE DYNAMIC SETTINGS
// ---------------------------------------------------------------------------------
function handleSaveSettings(?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    try {
        $keys = [
            'video_codec', 'keyframe', 'bitrate_ratio', 'buffer_ratio',
            'hls_time', 'add_top_quality', 'audio_codec', 'audio_bitrate', 'audio_channels',
            'b2_key_id', 'b2_application_key', 'b2_bucket_id', 'b2_bucket_name',
            'ffmpeg_threads', 'ffmpeg_preset', 'parallel_transcode'
        ];

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("REPLACE INTO `settings` (`key`, `value`) VALUES (:key, :value)");

        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $stmt->execute([':key' => $key, ':value' => $_POST[$key]]);
            }
        }

        if (isset($_POST['renditions'])) {
            $renditions = json_decode($_POST['renditions'], true);
            if (is_array($renditions)) {
                $stmt->execute([':key' => 'renditions', ':value' => json_encode($renditions)]);
            } else {
                throw new Exception('Invalid renditions format');
            }
        }

        $pdo->commit();
        sendJsonResponse(['status' => 'success', 'message' => 'Settings saved successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJsonResponse(['error' => 'Failed to save settings: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: UPDATE STREAM METADATA
// ---------------------------------------------------------------------------------
function handleUpdateStream(?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    $id = $_POST['id'] ?? '';
    $title = $_POST['title'] ?? '';

    if (empty($id) || empty($title)) {
        sendJsonResponse(['error' => 'Missing required parameter: id or title.'], 400);
    }

    try {
        $stmt = $pdo->prepare("UPDATE `streams` SET `title` = :title WHERE `id` = :id");
        $stmt->execute([':title' => $title, ':id' => $id]);
        sendJsonResponse(['status' => 'success', 'message' => 'Stream metadata updated successfully.']);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Database update failed: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: DELETE STREAM & RESOURCE PURGE
// ---------------------------------------------------------------------------------
function handleDeleteStream(?PDO $pdo, string $inputDir, string $outputDir): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        sendJsonResponse(['error' => 'Missing target stream ID.'], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT `filename` FROM `streams` WHERE `id` = :id");
        $stmt->execute([':id' => $id]);
        $stream = $stmt->fetch();

        if ($stream) {
            $filename = $stream['filename'];

            $del = $pdo->prepare("DELETE FROM `streams` WHERE `id` = :id");
            $del->execute([':id' => $id]);

            $sourceFile = $inputDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($sourceFile)) {
                @unlink($sourceFile);
            }

            $externalSubFiles = glob($inputDir . DIRECTORY_SEPARATOR . $id . '_external_sub_*');
            if (is_array($externalSubFiles)) {
                foreach ($externalSubFiles as $subFile) {
                    if (is_file($subFile)) {
                        @unlink($subFile);
                    }
                }
            }

            $streamOutputDir = $outputDir . DIRECTORY_SEPARATOR . $id;
            if (is_dir($streamOutputDir)) {
                recursiveRemoveDirectory($streamOutputDir);
            }

            sendJsonResponse(['status' => 'success', 'message' => 'Stream deleted and resources purged successfully.']);
        } else {
            sendJsonResponse(['error' => 'Stream not found.'], 404);
        }
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Failed to delete stream: ' . $e->getMessage()], 500);
    }
}

/**
 * Recursively deletes a folder and all its contents from disk.
 */
function recursiveRemoveDirectory(string $dir): void
{
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            recursiveRemoveDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------------
// CORE HELPER: SEND JSON RESPONSE
// ---------------------------------------------------------------------------------
function sendJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------------------
// PAGE RENDERER: DUAL DASHBOARD VIEW
// ---------------------------------------------------------------------------------
function renderUploadDashboard(?string $dbError, ?PDO $pdo): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Video Platform Master Console</title>
      
      <!-- Premium Google Fonts -->
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

      <link rel="stylesheet" href="assets/css/console.css">
    </head>
    <body>

      <!-- Background design meshes -->
      <div class="bg-mesh-glow"></div>
      <div class="bg-mesh-glow-left"></div>

      <div class="container">
        <!-- Dashboard Header -->
        <header>
          <h1>Video Hosting & Ingestion Console</h1>
          <p class="subtitle">Vanilla PHP backend with SQLite database persistence and VAST Ad Scheduler</p>
        </header>

        <!-- Database Offline Warning (Self-healing assistance helper) -->
        <?php if ($dbError !== null): ?>
          <div class="error-banner animate-card">
            <h3>⚠️ SQLite Initialization Error</h3>
            <p>The SQLite database could not be initialized or opened. Ensure the project directory is writable by your PHP server process.</p>
            <p style="margin-top: 0.75rem;"><strong>Error Message:</strong> <code><?php echo htmlspecialchars($dbError); ?></code></p>
          </div>
        <?php endif; ?>

        <!-- Step 1 & 2 Ingestion Card -->
        <div class="card">
          <div class="card-title">Upload Video Stream</div>
          
          <!-- Target Resolution Selectors -->
          <div class="resolution-grid">
            <div class="resolution-item" id="res-card-1080p" onclick="toggleResolutionCheckbox('res-1080p', 'res-card-1080p')">
              <input type="checkbox" id="res-1080p" name="resolutions" value="1080p" onclick="event.stopPropagation(); updateCheckboxStyle('res-1080p', 'res-card-1080p')">
              <div class="resolution-label">
                <span>1080p</span>
                <span class="resolution-sub">1920×1080 (HD)</span>
              </div>
            </div>
            
            <div class="resolution-item selected" id="res-card-720p" onclick="toggleResolutionCheckbox('res-720p', 'res-card-720p')">
              <input type="checkbox" id="res-720p" name="resolutions" value="720p" checked onclick="event.stopPropagation(); updateCheckboxStyle('res-720p', 'res-card-720p')">
              <div class="resolution-label">
                <span>720p</span>
                <span class="resolution-sub">1280×720 (HD)</span>
              </div>
            </div>
            
            <div class="resolution-item" id="res-card-540p" onclick="toggleResolutionCheckbox('res-540p', 'res-card-540p')">
              <input type="checkbox" id="res-540p" name="resolutions" value="540p" onclick="event.stopPropagation(); updateCheckboxStyle('res-540p', 'res-card-540p')">
              <div class="resolution-label">
                <span>540p</span>
                <span class="resolution-sub">960×540 (qHD)</span>
              </div>
            </div>
            
            <div class="resolution-item" id="res-card-480p" onclick="toggleResolutionCheckbox('res-480p', 'res-card-480p')">
              <input type="checkbox" id="res-480p" name="resolutions" value="480p" onclick="event.stopPropagation(); updateCheckboxStyle('res-480p', 'res-card-480p')">
              <div class="resolution-label">
                <span>480p</span>
                <span class="resolution-sub">854×480 (SD)</span>
              </div>
            </div>
            
            <div class="resolution-item" id="res-card-360p" onclick="toggleResolutionCheckbox('res-360p', 'res-card-360p')">
              <input type="checkbox" id="res-360p" name="resolutions" value="360p" onclick="event.stopPropagation(); updateCheckboxStyle('res-360p', 'res-card-360p')">
              <div class="resolution-label">
                <span>360p</span>
                <span class="resolution-sub">640×360 (SD)</span>
              </div>
            </div>
          </div>

          <!-- Stream Display Title Input -->
          <div class="form-group" style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.45rem;">
            <label class="field-label" for="stream-display-title">Stream Appear Title (Custom Name)</label>
            <input type="text" id="stream-display-title" class="form-input" placeholder="Enter custom display name (optional, defaults to filename)" style="width: 100%; max-width: 100%;">
          </div>

          <!-- Optional Subtitle File Input -->
          <div class="form-group" style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.45rem;">
            <label class="field-label" for="subtitle-file">Add Subtitle File (Optional, .vtt or .srt)</label>
            <input type="file" id="subtitle-file" accept=".vtt,.srt" class="form-input" style="padding: 0.5rem; background: rgba(0,0,0,0.2);">
          </div>

          <!-- Drag and Drop UI Area -->
          <div class="drag-zone" id="upload-dropzone">
            <!-- Upload Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="17 8 12 3 7 8"></polyline>
              <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
            <div class="drag-zone-text" id="drag-zone-text">Drag and drop your video file here</div>
            <div class="drag-zone-sub" id="drag-zone-sub">or click anywhere in this box to select a local file</div>
            <input type="file" id="file-input" accept=".mp4,.mkv,.ts,.avi,.mov" multiple>
          </div>

          <!-- Upload Ingestion Queue list -->
          <div id="upload-queue-container" style="display: none; margin-top: 1.5rem; flex-direction: column; gap: 0.50rem;">
            <label class="field-label">Upload Ingestion Queue</label>
            <div id="upload-queue-list" style="display: flex; flex-direction: column; gap: 0.50rem;">
              <!-- Queue items are dynamically rendered here -->
            </div>
          </div>

          <button class="btn btn-primary" id="btn-start-upload" style="width: 100%; margin-top: 1rem; display: none;">
            Upload Video Stream
          </button>

          <!-- Upload Progress Status panel -->
          <div class="progress-card" id="upload-progress-container">
            <div class="progress-header">
              <div class="progress-filename" id="label-filename">filename.mp4</div>
              <div class="progress-percentage" id="label-percent">0%</div>
            </div>
            <div class="progress-track">
              <div class="progress-bar" id="progress-indicator"></div>
            </div>
            <div class="progress-details">
              <span id="label-size-ratio">0.0 MB / 0.0 MB</span>
              <span id="label-upload-speed">0.0 MB/s</span>
            </div>
          </div>
        </div>

        <!-- Phase 3: VAST/VPAID Ad Management Panel Card -->
        <div class="card">
          <div class="card-title">VAST/VPAID Ad Campaign Settings</div>
          
          <!-- New Ad Insertion Form -->
          <form id="ad-campaign-form" style="margin-bottom: 2rem;">
            <div class="form-row">
              <div class="form-group">
                <label class="field-label" for="ad-name">Campaign Display Name</label>
                <input type="text" id="ad-name" name="name" required class="form-input" placeholder="e.g. Midroll 15 Seconds Promo">
              </div>
              <div class="form-group">
                <label class="field-label" for="ad-offset-type">Ad Position Offset Target</label>
                <select id="ad-offset-type" name="offset_type" class="form-input" onchange="adjustOffsetValueInputBehavior()">
                  <option value="preroll">Preroll (At Video Commencement)</option>
                  <option value="midroll">Midroll (Inside Playback Timeline)</option>
                  <option value="postroll">Postroll (Upon Video Conclusion)</option>
                </select>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group" id="ad-offset-value-group">
                <label class="field-label" for="ad-offset-value">Offset Value Parameter</label>
                <input type="text" id="ad-offset-value" name="offset_value" class="form-input" placeholder="e.g. 10 (seconds), 25% (percentage), or 00:00:15:000">
              </div>
              <div class="form-group">
                <label class="field-label" for="ad-vast-url">VAST XML Response endpoint URL</label>
                <input type="url" id="ad-vast-url" name="vast_url" required class="form-input" placeholder="https://pubads.g.doubleclick.net/gampad/ads?...">
              </div>
            </div>
            
            <button type="submit" class="btn-submit">Add Ad Campaign</button>
          </form>

          <!-- Dynamic Listings Table -->
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Campaign Name</th>
                  <th>Position Type</th>
                  <th>Offset Value</th>
                  <th>VAST XML Endpoint</th>
                  <th style="width: 80px; text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody id="ad-campaigns-directory">
                <tr>
                  <td colspan="5" style="color: var(--text-muted); text-align: center;">Scanning ad directory...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Registry List Display Card -->
        <div class="card">
          <div class="card-title">Streams Registry Directory</div>
          <ul class="stream-list" id="registry-items-list">
            <li class="stream-item" style="color: var(--text-muted); font-size: 0.9rem;">
              Scanning catalog stream registry...
            </li>
          </ul>
        </div>
      </div>

      <!-- Glassmorphic Preview Modal -->
      <div id="preview-modal" class="modal-overlay">
        <div class="modal-content">
          <div class="modal-header">
            <span class="modal-title" id="modal-stream-title">Stream Preview</span>
            <button class="modal-close-btn" onclick="closePreviewModal()" aria-label="Close Preview">
              <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="iframe-container">
              <iframe id="preview-iframe" src="" allowfullscreen></iframe>
            </div>
          </div>
        </div>
      </div>

      <!-- Frontend Client Scripts -->
      <script src="assets/js/console.js"></script>
    </body>
    </html>
    <?php
}

// ---------------------------------------------------------------------------------
// API ENDPOINT: CHANGE USER PASSWORD
// ---------------------------------------------------------------------------------
function handleChangePassword(?PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
    }

    if ($pdo === null) {
        sendJsonResponse(['error' => 'Database offline.'], 500);
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        sendJsonResponse(['error' => 'All password fields are required.'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        sendJsonResponse(['error' => 'New passwords do not match.'], 400);
    }

    if (strlen($newPassword) < 6) {
        sendJsonResponse(['error' => 'New password must be at least 6 characters long.'], 400);
    }

    $username = $_SESSION['username'] ?? '';
    if (empty($username)) {
        sendJsonResponse(['error' => 'Session expired. Please log in again.'], 401);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            sendJsonResponse(['error' => 'Incorrect current password.'], 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE `users` SET `password_hash` = :hash WHERE `id` = :id");
        $update->execute([':hash' => $newHash, ':id' => $user['id']]);

        sendJsonResponse(['status' => 'success', 'message' => 'Password updated successfully.']);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => 'Database write failure: ' . $e->getMessage()], 500);
    }
}
