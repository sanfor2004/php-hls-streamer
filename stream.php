<?php
declare(strict_types=1);

$rootDir = __DIR__;
$inputDir = $rootDir . DIRECTORY_SEPARATOR . 'Input';
$outputDir = $rootDir . DIRECTORY_SEPARATOR . 'Output';
$registryFile = $rootDir . DIRECTORY_SEPARATOR . 'streams.json';

$allowedExtensions = ['mp4', 'mkv', 'ts', 'avi'];
$supportedDirectCodecs = ['h264', 'hevc'];

if (!is_dir($inputDir)) {
    mkdir($inputDir, 0775, true);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}
if (!is_file($registryFile)) {
    file_put_contents($registryFile, json_encode(['items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$action = $_GET['action'] ?? 'home';

switch ($action) {
    case 'upload':
        handleUpload($inputDir, $outputDir, $registryFile, $allowedExtensions, $supportedDirectCodecs);
        break;
    case 'list':
        handleList($registryFile);
        break;
    case 'player':
        handlePlayer($registryFile);
        break;
    default:
        renderHome($registryFile);
}

function handleUpload(string $inputDir, string $outputDir, string $registryFile, array $allowedExtensions, array $supportedDirectCodecs): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Use POST multipart/form-data with field name: video'], 405);
    }

    if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
        jsonResponse(['error' => 'Missing file field: video'], 400);
    }

    $file = $_FILES['video'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Upload failed with code: ' . (int) ($file['error'] ?? -1)], 400);
    }

    $originalName = (string) ($file['name'] ?? 'upload.bin');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        jsonResponse(['error' => 'Invalid extension. Allowed: ' . implode(', ', $allowedExtensions)], 400);
    }

    $id = bin2hex(random_bytes(8));
    $safeOriginalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $inputPath = $inputDir . DIRECTORY_SEPARATOR . $id . '_' . $safeOriginalName;

    if (!move_uploaded_file((string) $file['tmp_name'], $inputPath)) {
        jsonResponse(['error' => 'Failed to move uploaded file'], 500);
    }

    $codec = detectVideoCodec($inputPath);
    $isDirectCodecSupported = in_array($codec, $supportedDirectCodecs, true);

    $jobDir = $outputDir . DIRECTORY_SEPARATOR . $id;
    if (!mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        jsonResponse(['error' => 'Failed to create output directory'], 500);
    }

    $playlistPath = $jobDir . DIRECTORY_SEPARATOR . 'master.m3u8';
    $segmentPattern = $jobDir . DIRECTORY_SEPARATOR . 'seg_%03d.ts';

    $ffmpegCmd = 'ffmpeg -y -i "' . $inputPath . '"'
        . ' -map 0:v:0 -map 0:a?'
        . ' -c:v libx264 -preset veryfast -crf 23 -maxrate 2800k -bufsize 5600k'
        . ' -vf scale=-2:720 -c:a aac -b:a 128k -ac 2'
        . ' -hls_time 6 -hls_playlist_type vod'
        . ' -hls_segment_filename "' . $segmentPattern . '"'
        . ' "' . $playlistPath . '"'
        . ' 2>&1';

    exec($ffmpegCmd, $ffmpegOut, $ffmpegCode);
    if ($ffmpegCode !== 0 || !is_file($playlistPath)) {
        jsonResponse([
            'error' => 'FFmpeg processing failed',
            'ffmpeg_output' => $ffmpegOut,
        ], 500);
    }

    $baseUrl = getBaseUrl();
    $hlsUrl = $baseUrl . '/Output/' . rawurlencode($id) . '/master.m3u8';
    $iframeUrl = $baseUrl . '/stream.php?action=player&id=' . rawurlencode($id);

    $record = [
        'id' => $id,
        'title' => $originalName,
        'created_at' => gmdate('c'),
        'status' => 'ready',
        'video_codec' => $codec,
        'direct_codec_supported' => $isDirectCodecSupported,
        'hls_url' => $hlsUrl,
        'iframe_url' => $iframeUrl,
    ];

    $registry = readRegistry($registryFile);
    $registry['items'][] = $record;
    writeRegistry($registryFile, $registry);

    @unlink($inputPath);

    jsonResponse([
        'message' => 'Upload processed successfully',
        'record' => $record,
    ]);
}

function handleList(string $registryFile): void
{
    jsonResponse(readRegistry($registryFile));
}

function handlePlayer(string $registryFile): void
{
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo 'Missing id';
        return;
    }

    $registry = readRegistry($registryFile);
    $item = null;

    foreach ($registry['items'] as $row) {
        if (($row['id'] ?? '') === $id) {
            $item = $row;
            break;
        }
    }

    if ($item === null) {
        http_response_code(404);
        echo 'Stream not found';
        return;
    }

    $title = htmlspecialchars((string) ($item['title'] ?? 'Video'), ENT_QUOTES, 'UTF-8');
    $hlsUrl = htmlspecialchars((string) ($item['hls_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html>';
    echo '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $title . '</title>';
    echo '<style>body{margin:0;background:#0f1117;color:#fff;font-family:Segoe UI,Arial,sans-serif}';
    echo '.wrap{min-height:100vh;display:grid;place-items:center;padding:16px}.card{width:min(920px,100%)}';
    echo 'video{width:100%;height:auto;border-radius:12px;background:#000}</style>';
    echo '<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script></head><body>';
    echo '<div class="wrap"><div class="card"><video id="video" controls playsinline></video></div></div>';
    echo '<script>';
    echo 'const video=document.getElementById("video");const src="' . $hlsUrl . '";';
    echo 'if(video.canPlayType("application/vnd.apple.mpegurl")){video.src=src;}';
    echo 'else if(window.Hls&&Hls.isSupported()){const hls=new Hls();hls.loadSource(src);hls.attachMedia(video);}';
    echo 'else{video.outerHTML="<p>HLS is not supported in this browser.</p>";}';
    echo '</script></body></html>';
}

function renderHome(string $registryFile): void
{
    $list = readRegistry($registryFile);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Streaming Script</title>';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:24px auto;padding:0 16px}';
    echo 'input,button{padding:10px}li{margin:10px 0}code{background:#f1f1f1;padding:2px 6px;border-radius:6px}</style>';
    echo '</head><body><h1>Streaming Script</h1>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=upload">';
    echo '<input type="file" name="video" accept=".mp4,.mkv,.ts,.avi" required> <button type="submit">Upload + Transcode</button>';
    echo '</form>';
    echo '<p>JSON API: <code>?action=list</code></p>';
    echo '<h2>Ready Streams</h2><ul>';

    foreach (($list['items'] ?? []) as $item) {
        $id = htmlspecialchars((string) ($item['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iframe = htmlspecialchars((string) ($item['iframe_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<li><strong>' . $title . '</strong><br>ID: ' . $id . '<br>Iframe: <a href="' . $iframe . '" target="_blank" rel="noopener">' . $iframe . '</a></li>';
    }

    echo '</ul></body></html>';
}

function detectVideoCodec(string $inputPath): string
{
    $cmd = 'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=nw=1:nk=1 ' . escapeshellarg($inputPath) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || empty($out)) {
        return 'unknown';
    }

    return strtolower(trim((string) $out[0]));
}

function readRegistry(string $registryFile): array
{
    $raw = @file_get_contents($registryFile);
    if ($raw === false || trim($raw) === '') {
        return ['items' => []];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['items' => []];
    }

    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
    }

    return $data;
}

function writeRegistry(string $registryFile, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        jsonResponse(['error' => 'Failed to encode JSON registry'], 500);
    }

    file_put_contents($registryFile, $json, LOCK_EX);
}

function getBaseUrl(): string
{
    $https = ($_SERVER['HTTPS'] ?? 'off') !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    if ($scriptDir === '') {
        $scriptDir = '/';
    }

    return rtrim($scheme . '://' . $host . $scriptDir, '/');
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
