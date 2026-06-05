<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE SECURE B2 STREAMING GATEWAY (b2_gateway.php)
 * =================================================================================
 * Proxy script that abstracts and hides all Backblaze B2 details from the client.
 * Streams segment slices and HLS playlists securely with zero memory buffering,
 * and falls back to local storage if cloud parameters are incomplete.
 * =================================================================================
 */

// 1. Database Connection & Schema Bootstrapping
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

try {
    $pdo = getDatabaseConnection();
} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    die("Database Connection Offline.");
}

// 2. Parse Path-based routing: /b2_gateway.php/{streamId}/{filePath...}
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$parts = explode('/', ltrim($pathInfo, '/'));

if (count($parts) < 2) {
    header("HTTP/1.1 400 Bad Request");
    die("Invalid gateway routing path. Format: b2_gateway.php/{stream_id}/{file_path}");
}

$streamId = preg_replace('/[^a-zA-Z0-9_-]/', '', array_shift($parts));
$filePath = implode('/', $parts);

// Basic sanitation of requested file path to prevent directory traversal attacks
if (strpos($filePath, '..') !== false || strpos($filePath, '\\') !== false) {
    header("HTTP/1.1 403 Forbidden");
    die("Forbidden path traversal detected.");
}

// 3. Verify Stream Record Exists (Security Access check)
$stmt = $pdo->prepare("SELECT * FROM `streams` WHERE `id` = :id");
$stmt->execute([':id' => $streamId]);
$stream = $stmt->fetch();

if (!$stream) {
    header("HTTP/1.1 404 Not Found");
    die("Stream record not found.");
}

// 4. Fetch Backblaze Settings
$settingsStmt = $pdo->query("SELECT * FROM `settings`");
$settings = [];
if ($settingsStmt) {
    foreach ($settingsStmt->fetchAll() as $row) {
        $settings[$row['key']] = $row['value'];
    }
}

$b2KeyId = $settings['b2_key_id'] ?? '';
$b2AppKey = $settings['b2_application_key'] ?? '';
$b2BucketId = $settings['b2_bucket_id'] ?? '';
$b2BucketName = $settings['b2_bucket_name'] ?? '';

// Determine HLS mime-type for standard browser compatibility headers
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';
if ($extension === 'm3u8') {
    $contentType = 'application/x-mpegURL';
} elseif ($extension === 'ts') {
    $contentType = 'video/MP2T';
} elseif ($extension === 'vtt') {
    $contentType = 'text/vtt';
}

// Set base response headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Cache-Control: max-age=86400, public");

// 5. If B2 settings are active, stream from B2
if (!empty($b2KeyId) && !empty($b2AppKey) && !empty($b2BucketId) && !empty($b2BucketName)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'b2.php';
    try {
        $b2Client = new B2Client($b2KeyId, $b2AppKey, $b2BucketId, $b2BucketName);
        $remotePath = "Output/{$streamId}/{$filePath}";
        
        $status = $b2Client->downloadFile($remotePath);
        
        if ($status === 200) {
            exit();
        }
        
        // If B2 returned a non-200 status (e.g. 404), fall back to checking local
    } catch (Exception $e) {
        // Log error silently, fall back to local disk
    }
}

// 6. Fallback: Stream locally if B2 is disabled or if file is missing from B2
$localPath = __DIR__ . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . $streamId . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath);

if (is_file($localPath)) {
    $size = filesize($localPath);
    header("Content-Type: " . $contentType);
    header("Content-Length: " . $size);
    
    // Disable output buffering to stream file directly
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($localPath);
    exit();
}

// 7. Not found in B2 and not found locally
header("HTTP/1.1 404 Not Found");
die("Requested file does not exist.");
