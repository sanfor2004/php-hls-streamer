<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH-TIER CORE VANILLA PHP BACKGROUND TRANSCODING WORKER
 * =================================================================================
 * This background worker is designed to be executed via Command Line Interface (CLI).
 * It runs asynchronously, spawned by api.php using non-blocking shell exec().
 *
 * Usage: php transcode.php {stream_id}
 * =================================================================================
 */

// Force CLI execution only to protect against direct web browser invocation
if (PHP_SAPI !== 'cli') {
    die("Error: This background worker can only be executed via the command line (CLI).\n");
}

// 1. Capture the Stream ID and environment mode from command line arguments
if ($argc < 2 || empty($argv[1])) {
    die("Error: Missing mandatory stream ID argument. Usage: php transcode.php {stream_id} [mode]\n");
}

$streamId = preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1]);
$activeMode = $argv[2] ?? null;

// ---------------------------------------------------------------------------------
// DATABASE CONNECTION CONFIGURATION
// ---------------------------------------------------------------------------------
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
$dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite';

try {
    $pdo = getDatabaseConnection($activeMode);
} catch (PDOException $e) {
    die("Database Connection Offline: " . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------------
// SYSTEM ROOT DIRECTORIES DEFINITIONS
// ---------------------------------------------------------------------------------
$rootDir = __DIR__;
$inputDir = $rootDir . DIRECTORY_SEPARATOR . 'Input';
$outputDir = $rootDir . DIRECTORY_SEPARATOR . 'Output';

// ---------------------------------------------------------------------------------
// FETCH STREAM DETAILS FROM THE DATABASE
// ---------------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM `streams` WHERE `id` = :id");
$stmt->execute([':id' => $streamId]);
$stream = $stmt->fetch();

if (!$stream) {
    die("Error: Stream record with ID '{$streamId}' not found in database.\n");
}

// Ensure the uploaded source video file exists on disk
$sourceFilePath = $inputDir . DIRECTORY_SEPARATOR . $stream['filename'];
if (!is_file($sourceFilePath)) {
    // Update stream status to failed since source file is missing
    $upd = $pdo->prepare("UPDATE `streams` SET `status` = 'failed' WHERE `id` = :id");
    $upd->execute([':id' => $streamId]);
    die("Error: Uploaded source video file '{$sourceFilePath}' not found.\n");
}

// Parse selected target resolutions from JSON database column
$resolutionsSelected = json_decode($stream['resolutions_selected'], true);
if (!is_array($resolutionsSelected) || empty($resolutionsSelected)) {
    $resolutionsSelected = ['720p']; // Fallback to 720p standard
}

// ---------------------------------------------------------------------------------
// DYNAMIC TRANSCODING SETTINGS LOADER
// ---------------------------------------------------------------------------------
$videoCodecSetting   = 'h264';
$keyframeSetting     = 60;
$bitrateRatioSetting = 1.07;
$bufferRatioSetting  = 1.5;
$hlsTimeSetting      = 6;
$audioCodecSetting   = 'aac';
$audioBitrateSetting = '128k';
$audioChannelsSetting= 'stereo';
$b2KeyIdSetting          = '';
$b2ApplicationKeySetting = '';
$b2BucketIdSetting       = '';
$b2BucketNameSetting     = '';
$ffmpegThreadsSetting    = '0';
$ffmpegPresetSetting     = 'veryfast';
$parallelTranscodeSetting = '0';
$renditionLadder = [
    '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 25, 'vbitrate' => '4096k', 'abitrate' => '192k'],
    '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 26, 'vbitrate' => '2048k', 'abitrate' => '128k'],
    '540p'  => ['width' => 960,  'height' => 540,  'crf' => 27, 'vbitrate' => '1500k', 'abitrate' => '128k'],
    '480p'  => ['width' => 854,  'height' => 480,  'crf' => 28, 'vbitrate' => '750k',  'abitrate' => '128k'],
    '360p'  => ['width' => 640,  'height' => 360,  'crf' => 29, 'vbitrate' => '276k',  'abitrate' => '96k'],
];

try {
    $stmtSettings = $pdo->query("SELECT * FROM `settings`");
    $settingsRaw = $stmtSettings->fetchAll();
    foreach ($settingsRaw as $row) {
        switch ($row['key']) {
            case 'video_codec':
                $videoCodecSetting = $row['value'];
                break;
            case 'keyframe':
                $keyframeSetting = (int)$row['value'];
                break;
            case 'bitrate_ratio':
                $bitrateRatioSetting = (float)$row['value'];
                break;
            case 'buffer_ratio':
                $bufferRatioSetting = (float)$row['value'];
                break;
            case 'hls_time':
                $hlsTimeSetting = (int)$row['value'];
                break;
            case 'audio_codec':
                $audioCodecSetting = $row['value'];
                break;
            case 'audio_bitrate':
                $audioBitrateSetting = $row['value'];
                break;
            case 'audio_channels':
                $audioChannelsSetting = $row['value'];
                break;
            case 'b2_key_id':
                $b2KeyIdSetting = $row['value'];
                break;
            case 'b2_application_key':
                $b2ApplicationKeySetting = $row['value'];
                break;
            case 'b2_bucket_id':
                $b2BucketIdSetting = $row['value'];
                break;
            case 'b2_bucket_name':
                $b2BucketNameSetting = $row['value'];
                break;
            case 'ffmpeg_threads':
                $ffmpegThreadsSetting = $row['value'];
                break;
            case 'ffmpeg_preset':
                $ffmpegPresetSetting = $row['value'];
                break;
            case 'parallel_transcode':
                $parallelTranscodeSetting = $row['value'];
                break;
            case 'renditions':
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) {
                    $renditionLadder = $decoded;
                }
                break;
        }
    }
} catch (PDOException $e) {
    // Database failed, fallback to defaults
}

// Update stream status to 'transcoding'
$upd = $pdo->prepare("UPDATE `streams` SET `status` = 'transcoding' WHERE `id` = :id");
$upd->execute([':id' => $streamId]);

// Create output folder for the stream
$streamOutputDir = $outputDir . DIRECTORY_SEPARATOR . $streamId;
if (!is_dir($streamOutputDir)) {
    mkdir($streamOutputDir, 0775, true);
}
$masterPlaylistPath = $streamOutputDir . DIRECTORY_SEPARATOR . 'master.m3u8';

// Create worker transcode log file to capture background stderr
$logFilePath = $streamOutputDir . DIRECTORY_SEPARATOR . 'transcode_worker.log';
file_put_contents($logFilePath, "Transcoding worker started at " . date('Y-m-d H:i:s') . "\n\n", FILE_APPEND);

// Initialize Backblaze B2 Client
$b2Client = null;
if (!empty($b2KeyIdSetting) && !empty($b2ApplicationKeySetting) && !empty($b2BucketIdSetting) && !empty($b2BucketNameSetting)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'b2.php';
    try {
        $b2Client = new B2Client($b2KeyIdSetting, $b2ApplicationKeySetting, $b2BucketIdSetting, $b2BucketNameSetting);
        file_put_contents($logFilePath, "Initialized B2 client successfully.\n", FILE_APPEND);
    } catch (Exception $e) {
        file_put_contents($logFilePath, "B2 initialization failed: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

$hasB2Errors = false;

/**
 * Uploads a local file to B2 and purges the local source on success.
 */
function uploadToB2AndCleanup(string $localFile, string $remotePath, ?B2Client $b2Client, string $logFilePath): ?string
{
    global $hasB2Errors;
    if (!$b2Client) {
        return null;
    }
    try {
        file_put_contents($logFilePath, "Uploading '{$localFile}' to B2 as '{$remotePath}'...\n", FILE_APPEND);
        $publicUrl = $b2Client->uploadFile($localFile, $remotePath);
        file_put_contents($logFilePath, "Uploaded successfully. Public URL: {$publicUrl}\n", FILE_APPEND);
        @unlink($localFile);
        return $publicUrl;
    } catch (Exception $e) {
        file_put_contents($logFilePath, "B2 upload failed for '{$localFile}': " . $e->getMessage() . "\n", FILE_APPEND);
        $hasB2Errors = true;
        return null;
    }
}

// Sort targeted resolutions Selected by height in ascending order (lowest quality first)
usort($resolutionsSelected, function ($a, $b) use ($renditionLadder) {
    $heightA = isset($renditionLadder[$a]) ? (int)$renditionLadder[$a]['height'] : 0;
    $heightB = isset($renditionLadder[$b]) ? (int)$renditionLadder[$b]['height'] : 0;
    return $heightA <=> $heightB;
});

// ---------------------------------------------------------------------------------
// STEP 1: DETECT VIDEO DURATION AND CODEC VIA FFPROBE
// ---------------------------------------------------------------------------------
$duration = null;
$codec = 'unknown';

// Run FFprobe to detect stream duration in seconds
$ffprobeDurationCmd = "ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 " . escapeshellarg($sourceFilePath) . " 2>&1";
exec($ffprobeDurationCmd, $durationOut, $durationCode);
if ($durationCode === 0 && !empty($durationOut)) {
    $duration = (int)round((float)$durationOut[0]);
}

// Run FFprobe to detect video codec
$ffprobeCodecCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=nw=1:nk=1 " . escapeshellarg($sourceFilePath) . " 2>&1";
exec($ffprobeCodecCmd, $codecOut, $codecCode);
if ($codecCode === 0 && !empty($codecOut)) {
    $codec = strtolower(trim((string)$codecOut[0]));
}

// Run FFprobe to detect subtitle streams
$subtitleStreams = [];
$ffprobeSubCmd = "ffprobe -v error -show_entries stream=index:stream_tags=language,title -select_streams s -of json " . escapeshellarg($sourceFilePath) . " 2>&1";
exec($ffprobeSubCmd, $subOut, $subCode);
if ($subCode === 0 && !empty($subOut)) {
    $subJson = json_decode(implode('', $subOut), true);
    if (isset($subJson['streams']) && is_array($subJson['streams'])) {
        foreach ($subJson['streams'] as $sIndex => $sData) {
            $lang = isset($sData['tags']['language']) ? $sData['tags']['language'] : 'eng';
            $title = isset($sData['tags']['title']) ? $sData['tags']['title'] : strtoupper($lang);
            $subtitleStreams[] = [
                'index' => $sData['index'],
                'lang' => strtolower($lang),
                'title' => $title,
                'id' => 'sub' . $sIndex
            ];
        }
    }
}

// Run FFprobe to detect audio streams
$audioStreams = [];
$ffprobeAudioCmd = "ffprobe -v error -show_entries stream=index:stream_tags=language,title -select_streams a -of json " . escapeshellarg($sourceFilePath) . " 2>&1";
exec($ffprobeAudioCmd, $audioOut, $audioCode);
if ($audioCode === 0 && !empty($audioOut)) {
    $audioJson = json_decode(implode('', $audioOut), true);
    if (isset($audioJson['streams']) && is_array($audioJson['streams'])) {
        foreach ($audioJson['streams'] as $aIndex => $aData) {
            $lang = isset($aData['tags']['language']) ? $aData['tags']['language'] : 'eng';
            $title = isset($aData['tags']['title']) ? $aData['tags']['title'] : strtoupper($lang);
            $audioStreams[] = [
                'index' => $aData['index'],
                'lang' => strtolower($lang),
                'title' => $title,
                'id' => 'audio' . $aIndex
            ];
        }
    }
}

// Save detected properties back to primary database entry
$updProps = $pdo->prepare("UPDATE `streams` SET `video_codec` = :codec, `duration` = :duration WHERE `id` = :id");
$updProps->execute([
    ':codec' => $codec,
    ':duration' => $duration,
    ':id' => $streamId
]);

// ---------------------------------------------------------------------------------
// STEP 1.5: EXTRACT SUBTITLE STREAMS TO STANDALONE WEBVTT HLS PLAYLISTS
// ---------------------------------------------------------------------------------
$subtitlePlaylists = [];
$subtitlesDir = $streamOutputDir . DIRECTORY_SEPARATOR . 'subtitles';

// Check for external uploaded subtitle
$externalSubFiles = glob($inputDir . DIRECTORY_SEPARATOR . $streamId . '_external_sub_*');
$externalSubPath = !empty($externalSubFiles) ? $externalSubFiles[0] : null;

if ($externalSubPath) {
    if (!is_dir($subtitlesDir)) {
        mkdir($subtitlesDir, 0775, true);
    }
    
    $trackDir = $subtitlesDir . DIRECTORY_SEPARATOR . 'sub_ext';
    if (!is_dir($trackDir)) {
        mkdir($trackDir, 0775, true);
    }
    
    $filename = basename($externalSubPath);
    // Remove the prefix to get the original filename
    $originalName = preg_replace('/^' . preg_quote($streamId . '_external_sub_', '/') . '/', '', $filename);
    $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
    
    // Language detection dictionary
    $langMap = [
        'en' => ['lang' => 'eng', 'name' => 'English'], 'eng' => ['lang' => 'eng', 'name' => 'English'],
        'es' => ['lang' => 'spa', 'name' => 'Spanish'], 'spa' => ['lang' => 'spa', 'name' => 'Spanish'],
        'fr' => ['lang' => 'fra', 'name' => 'French'],  'fra' => ['lang' => 'fra', 'name' => 'French'],
        'de' => ['lang' => 'deu', 'name' => 'German'],  'ger' => ['lang' => 'deu', 'name' => 'German'],
        'it' => ['lang' => 'ita', 'name' => 'Italian'], 'ita' => ['lang' => 'ita', 'name' => 'Italian'],
        'pt' => ['lang' => 'por', 'name' => 'Portuguese'],'por' => ['lang' => 'por', 'name' => 'Portuguese'],
        'ru' => ['lang' => 'rus', 'name' => 'Russian'], 'rus' => ['lang' => 'rus', 'name' => 'Russian'],
        'zh' => ['lang' => 'zho', 'name' => 'Chinese'], 'chi' => ['lang' => 'zho', 'name' => 'Chinese'],
        'ja' => ['lang' => 'jpn', 'name' => 'Japanese'],'jpn' => ['lang' => 'jpn', 'name' => 'Japanese'],
        'ko' => ['lang' => 'kor', 'name' => 'Korean'],  'kor' => ['lang' => 'kor', 'name' => 'Korean'],
        'ar' => ['lang' => 'ara', 'name' => 'Arabic'],  'ara' => ['lang' => 'ara', 'name' => 'Arabic'],
        'tr' => ['lang' => 'tur', 'name' => 'Turkish'], 'tur' => ['lang' => 'tur', 'name' => 'Turkish']
    ];
    
    $detectedLang = 'eng'; // Default
    // Create a fallback title from the filename (e.g. "movie_subs" -> "Movie Subs")
    $detectedName = ucwords(str_replace(['_', '-'], ' ', $nameWithoutExt)); 

    // Look for language codes at the end of the filename (e.g. movie.en, movie_es, subs-fr)
    if (preg_match('/[._-]([a-zA-Z]{2,3})$/', $nameWithoutExt, $matches)) {
        $code = strtolower($matches[1]);
        if (isset($langMap[$code])) {
            $detectedLang = $langMap[$code]['lang'];
            $detectedName = $langMap[$code]['name'];
        }
    }
    
    // Also check if the whole filename contains a language name like "Arabic"
    foreach ($langMap as $key => $val) {
        if (stripos($nameWithoutExt, $val['name']) !== false) {
            $detectedLang = $val['lang'];
            $detectedName = $val['name'];
            break;
        }
    }
    
    $vttFile = $trackDir . DIRECTORY_SEPARATOR . 'sub.vtt';
    
    // Convert to webvtt
    $ffmpegExtSubCmd = "ffmpeg -y -i " . escapeshellarg($externalSubPath) . " -c:s webvtt " . escapeshellarg($vttFile) . " 2>&1";
    file_put_contents($logFilePath, "Extracting External Subtitle: {$ffmpegExtSubCmd}\n", FILE_APPEND);
    exec($ffmpegExtSubCmd, $ffmpegExtSubOut, $ffmpegExtSubCode);
    
    if ($ffmpegExtSubCode === 0 && is_file($vttFile)) {
        $m3u8Path = $trackDir . DIRECTORY_SEPARATOR . 'playlist.m3u8';
        $safeDuration = $duration ? $duration : 100000;
        $subM3u8 = "#EXTM3U\n#EXT-X-TARGETDURATION:{$safeDuration}\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:{$safeDuration}.0,\nsub.vtt\n#EXT-X-ENDLIST\n";
        file_put_contents($m3u8Path, $subM3u8);

        if ($b2Client) {
            uploadToB2AndCleanup($vttFile, "Output/{$streamId}/subtitles/sub_ext/sub.vtt", $b2Client, $logFilePath);
            uploadToB2AndCleanup($m3u8Path, "Output/{$streamId}/subtitles/sub_ext/playlist.m3u8", $b2Client, $logFilePath);
        }

        $subtitlePlaylists[] = [
            'group' => 'subs',
            'name' => $detectedName,
            'lang' => $detectedLang,
            'uri' => "subtitles/sub_ext/playlist.m3u8",
            'default' => 'YES'
        ];
    }
    @unlink($externalSubPath); // Clean up the raw upload
}

if (!empty($subtitleStreams)) {
    if (!is_dir($subtitlesDir)) {
        mkdir($subtitlesDir, 0775, true);
    }

    foreach ($subtitleStreams as $subIndex => $subMeta) {
        $trackDir = $subtitlesDir . DIRECTORY_SEPARATOR . $subMeta['id'];
        if (!is_dir($trackDir)) {
            mkdir($trackDir, 0775, true);
        }

        $vttFile = $trackDir . DIRECTORY_SEPARATOR . 'sub.vtt';
        
        // Extract this specific subtitle track to WebVTT
        $ffmpegSubCmd = "ffmpeg -y -i " . escapeshellarg($sourceFilePath) . " -map 0:s:" . $subIndex . " -c:s webvtt " . escapeshellarg($vttFile) . " 2>&1";
        file_put_contents($logFilePath, "Extracting Embedded Subtitle: {$ffmpegSubCmd}\n", FILE_APPEND);
        exec($ffmpegSubCmd, $ffmpegSubOut, $ffmpegSubCode);

        if ($ffmpegSubCode === 0 && is_file($vttFile)) {
            // Write HLS playlist for the subtitle
            $m3u8Path = $trackDir . DIRECTORY_SEPARATOR . 'playlist.m3u8';
            $safeDuration = $duration ? $duration : 100000;
            $subM3u8 = "#EXTM3U\n#EXT-X-TARGETDURATION:{$safeDuration}\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:{$safeDuration}.0,\nsub.vtt\n#EXT-X-ENDLIST\n";
            file_put_contents($m3u8Path, $subM3u8);

            if ($b2Client) {
                uploadToB2AndCleanup($vttFile, "Output/{$streamId}/subtitles/{$subMeta['id']}/sub.vtt", $b2Client, $logFilePath);
                uploadToB2AndCleanup($m3u8Path, "Output/{$streamId}/subtitles/{$subMeta['id']}/playlist.m3u8", $b2Client, $logFilePath);
            }

            // If an external subtitle was already added, it took the YES default. Otherwise, the first embedded sub gets it.
            $isDefault = empty($subtitlePlaylists) ? 'YES' : 'NO';

            $subtitlePlaylists[] = [
                'group' => 'subs',
                'name' => $subMeta['title'],
                'lang' => $subMeta['lang'],
                'uri' => "subtitles/{$subMeta['id']}/playlist.m3u8",
                'default' => $isDefault
            ];
        } else {
            file_put_contents($logFilePath, "Failed to extract subtitle track {$subIndex}.\n", FILE_APPEND);
        }
    }
}

// ---------------------------------------------------------------------------------
// STEP 1.7: EXTRACT AND TRANSCODE ALTERNATIVE AUDIO TRACKS TO SIDE-CAR HLS PLAYLISTS
// ---------------------------------------------------------------------------------
$audioPlaylists = [];
$audioOutputDir = $streamOutputDir . DIRECTORY_SEPARATOR . 'audio';

if (count($audioStreams) > 1) {
    if (!is_dir($audioOutputDir)) {
        mkdir($audioOutputDir, 0775, true);
    }
    
    $ac = ($audioChannelsSetting === 'mono') ? 1 : 2;
    $targetAudioBitrate = !empty($audioBitrateSetting) ? $audioBitrateSetting : '128k';
    
    foreach ($audioStreams as $aIndex => $audioMeta) {
        $trackDir = $audioOutputDir . DIRECTORY_SEPARATOR . $audioMeta['id'];
        if (!is_dir($trackDir)) {
            mkdir($trackDir, 0775, true);
        }
        
        $audioPlaylistFilename = 'playlist.m3u8';
        $audioPlaylistPath = $trackDir . DIRECTORY_SEPARATOR . $audioPlaylistFilename;
        $audioSegmentPattern = $trackDir . DIRECTORY_SEPARATOR . 'seg_%03d.ts';
        
        file_put_contents($logFilePath, "--- Transcoding Audio Track {$audioMeta['id']} ({$audioMeta['title']}) ---\n", FILE_APPEND);
        
        // Transcode specific audio track to audio-only HLS using correct parameters
        $ffmpegAudioCmd = "ffmpeg -y -i " . escapeshellarg($sourceFilePath)
            . " -map 0:a:" . $aIndex . " -c:a " . $audioCodecSetting . " -b:a " . $targetAudioBitrate . " -ac " . $ac
            . " -hls_time " . $hlsTimeSetting . " -hls_playlist_type vod"
            . " -hls_segment_filename \"" . $audioSegmentPattern . "\""
            . " \"" . $audioPlaylistPath . "\" 2>&1";
            
        file_put_contents($logFilePath, "Executing Audio Command: {$ffmpegAudioCmd}\n", FILE_APPEND);
        exec($ffmpegAudioCmd, $ffmpegAudioOut, $ffmpegAudioCode);
        
        file_put_contents($logFilePath, implode("\n", $ffmpegAudioOut) . "\n", FILE_APPEND);
        file_put_contents($logFilePath, "Audio FFmpeg Exit Code: {$ffmpegAudioCode}\n\n", FILE_APPEND);
        
        if ($ffmpegAudioCode === 0 && is_file($audioPlaylistPath)) {
            if ($b2Client) {
                $audioFiles = glob($trackDir . DIRECTORY_SEPARATOR . '*');
                if (is_array($audioFiles)) {
                    // Upload segments first, playlist last
                    usort($audioFiles, function ($a, $b) {
                        $isPlaylistA = (pathinfo($a, PATHINFO_EXTENSION) === 'm3u8');
                        $isPlaylistB = (pathinfo($b, PATHINFO_EXTENSION) === 'm3u8');
                        return $isPlaylistA <=> $isPlaylistB;
                    });
                    foreach ($audioFiles as $audioFile) {
                        if (is_file($audioFile)) {
                            $remoteAudioPath = "Output/{$streamId}/audio/{$audioMeta['id']}/" . basename($audioFile);
                            uploadToB2AndCleanup($audioFile, $remoteAudioPath, $b2Client, $logFilePath);
                        }
                    }
                }
            }

            $audioPlaylists[] = [
                'group' => 'audio_group',
                'name' => $audioMeta['title'],
                'lang' => $audioMeta['lang'],
                'uri' => "audio/{$audioMeta['id']}/playlist.m3u8",
                'default' => ($aIndex === 0) ? 'YES' : 'NO'
            ];
        }
        unset($ffmpegAudioOut);
    }
}

// ---------------------------------------------------------------------------------
// STEP 2: LOOP AND TRANSCODE TARGET RESOLUTIONS
// ---------------------------------------------------------------------------------
$successfulRenditionsCount = 0;
$masterPlaylistEntries = [];

if ($parallelTranscodeSetting === '1') {
    file_put_contents($logFilePath, "Launching parallel HLS transcode engine...\n", FILE_APPEND);
    $runningProcesses = [];

    foreach ($resolutionsSelected as $resKey) {
        if (!isset($renditionLadder[$resKey])) {
            continue;
        }

        $config = $renditionLadder[$resKey];
        $width = $config['width'];
        $height = $config['height'];
        $crf = $config['crf'];
        $vbitrate = $config['vbitrate'];
        $abitrate = $config['abitrate'];

        // Write initial resolution track placeholder record to database
        $stmtRes = $pdo->prepare("INSERT INTO `stream_resolutions` (`stream_id`, `resolution`, `width`, `height`, `status`) 
                                  VALUES (:stream_id, :resolution, :width, :height, 'pending')");
        $stmtRes->execute([
            ':stream_id' => $streamId,
            ':resolution' => $resKey,
            ':width' => $width,
            ':height' => $height
        ]);
        $resolutionRecordId = (int)$pdo->lastInsertId();

        // Create sub-directory for this specific resolution representation
        $resOutputDir = $streamOutputDir . DIRECTORY_SEPARATOR . $resKey;
        if (!is_dir($resOutputDir)) {
            mkdir($resOutputDir, 0775, true);
        }

        $playlistFilename = 'playlist.m3u8';
        $playlistPath = $resOutputDir . DIRECTORY_SEPARATOR . $playlistFilename;
        $segmentPattern = $resOutputDir . DIRECTORY_SEPARATOR . 'seg_%03d.ts';

        $bitrateNum = (int)preg_replace('/[^0-9]/', '', $vbitrate);
        $bufsize = (int)($bitrateNum * $bufferRatioSetting) . 'k';
        $maxrate = (int)($bitrateNum * $bitrateRatioSetting) . 'k';

        $c_v = 'libx264';
        if ($videoCodecSetting === 'hevc' || $videoCodecSetting === 'h265') {
            $c_v = 'libx265';
        } elseif ($videoCodecSetting !== 'h264' && !empty($videoCodecSetting)) {
            $c_v = $videoCodecSetting;
        }

        $ac = ($audioChannelsSetting === 'mono') ? 1 : 2;
        $targetAudioBitrate = !empty($audioBitrateSetting) ? $audioBitrateSetting : $abitrate;

        $audioMapping = ' -map 0:a:0?';
        $audioOpts = ' -c:a ' . $audioCodecSetting . ' -b:a ' . $targetAudioBitrate . ' -ac ' . $ac;
        if (count($audioStreams) > 1) {
            $audioMapping = '';
            $audioOpts = '';
        }

        // Build command with customized preset and threads
        $ffmpegCmd = 'ffmpeg -y -i ' . escapeshellarg($sourceFilePath)
            . ' -map 0:v:0' . $audioMapping
            . ' -c:v ' . $c_v . ' -preset ' . $ffmpegPresetSetting
            . ' -crf ' . $crf
            . ' -b:v ' . $vbitrate
            . ' -maxrate ' . $maxrate
            . ' -bufsize ' . $bufsize
            . ' -threads ' . $ffmpegThreadsSetting
            . ' -g ' . $keyframeSetting . ' -keyint_min ' . $keyframeSetting . ' -sc_threshold 0'
            . ' -vf "scale=w=' . $width . ':h=' . $height . ':force_original_aspect_ratio=decrease,pad=' . $width . ':' . $height . ':(ow-iw)/2:(oh-ih)/2,format=yuv420p"'
            . $audioOpts
            . ' -hls_time ' . $hlsTimeSetting . ' -hls_playlist_type vod'
            . ' -hls_segment_filename "' . $segmentPattern . '"'
            . ' "' . $playlistPath . '"';

        $resLogFilePath = $resOutputDir . DIRECTORY_SEPARATOR . 'transcode.log';
        file_put_contents($resLogFilePath, "--- Starting parallel transcode for {$resKey} rendition ---\nExecuting Command: {$ffmpegCmd}\n\n");

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["file", $resLogFilePath, "w"],
            2 => ["file", $resLogFilePath, "w"]
        ];

        $process = proc_open($ffmpegCmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $bandwidth = $bitrateNum * 1000;
            $relativePlaylistPath = 'Output/' . $streamId . '/' . $resKey . '/' . $playlistFilename;

            $runningProcesses[$resKey] = [
                'process' => $process,
                'pipes' => $pipes,
                'db_id' => $resolutionRecordId,
                'playlist_path' => $playlistPath,
                'relative_playlist_path' => $relativePlaylistPath,
                'res_output_dir' => $resOutputDir,
                'playlist_filename' => $playlistFilename,
                'bandwidth' => $bandwidth,
                'width' => $width,
                'height' => $height,
                'res_key' => $resKey,
                'log_file' => $resLogFilePath
            ];

            $stmtUpdStatus = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'processing' WHERE `id` = :id");
            $stmtUpdStatus->execute([':id' => $resolutionRecordId]);
        } else {
            $stmtUpdStatus = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'failed' WHERE `id` = :id");
            $stmtUpdStatus->execute([':id' => $resolutionRecordId]);
            file_put_contents($logFilePath, "Failed to spawn parallel FFmpeg process for resolution {$resKey}\n", FILE_APPEND);
        }
    }

    // Monitoring loop for parallel processes
    while (count($runningProcesses) > 0) {
        foreach ($runningProcesses as $resKey => $info) {
            $status = proc_get_status($info['process']);
            if (!$status['running']) {
                fclose($info['pipes'][0]);
                $exitCode = $status['exitcode'];
                proc_close($info['process']);

                file_put_contents($info['log_file'], "\nFFmpeg Exit Code: {$exitCode}\n", FILE_APPEND);

                if ($exitCode === 0 && is_file($info['playlist_path'])) {
                    file_put_contents($logFilePath, "Rendition {$resKey} transcoded successfully.\n", FILE_APPEND);
                    
                    $relativePlaylistPath = $info['relative_playlist_path'];
                    if ($b2Client) {
                        $resFiles = glob($info['res_output_dir'] . DIRECTORY_SEPARATOR . '*');
                        if (is_array($resFiles)) {
                            usort($resFiles, function ($a, $b) {
                                $isPlaylistA = (pathinfo($a, PATHINFO_EXTENSION) === 'm3u8');
                                $isPlaylistB = (pathinfo($b, PATHINFO_EXTENSION) === 'm3u8');
                                return $isPlaylistA <=> $isPlaylistB;
                            });
                            foreach ($resFiles as $resFile) {
                                if (is_file($resFile) && basename($resFile) !== 'transcode.log') {
                                    $remoteResPath = "Output/{$streamId}/{$resKey}/" . basename($resFile);
                                    $uploadedUrl = uploadToB2AndCleanup($resFile, $remoteResPath, $b2Client, $logFilePath);
                                    if (basename($resFile) === $info['playlist_filename'] && $uploadedUrl) {
                                        $relativePlaylistPath = $uploadedUrl;
                                    }
                                }
                            }
                        }
                    }

                    $stmtUpdRes = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'completed', `playlist_path` = :path WHERE `id` = :id");
                    $stmtUpdRes->execute([
                        ':path' => $relativePlaylistPath,
                        ':id' => $info['db_id']
                    ]);

                    $successfulRenditionsCount++;

                    $masterPlaylistEntries[] = [
                        'bandwidth' => $info['bandwidth'],
                        'resolution' => "{$info['width']}x{$info['height']}",
                        'playlist' => "{$resKey}/{$info['playlist_filename']}"
                    ];

                    buildMasterPlaylist(
                        $streamOutputDir,
                        $masterPlaylistEntries,
                        $audioPlaylists,
                        $subtitlePlaylists,
                        $pdo,
                        $streamId,
                        $b2Client,
                        $logFilePath
                    );
                } else {
                    file_put_contents($logFilePath, "Rendition {$resKey} transcoding failed with exit status {$exitCode}.\n", FILE_APPEND);
                    $stmtUpdRes = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'failed' WHERE `id` = :id");
                    $stmtUpdRes->execute([':id' => $info['db_id']]);
                }

                if (is_file($info['log_file']) && $b2Client && $exitCode === 0) {
                    @unlink($info['log_file']);
                }

                unset($runningProcesses[$resKey]);
            }
        }
        usleep(200000);
    }

} else {
    // Sequential transcode path
    file_put_contents($logFilePath, "Launching sequential HLS transcode engine...\n", FILE_APPEND);
    foreach ($resolutionsSelected as $resKey) {
        if (!isset($renditionLadder[$resKey])) {
            continue;
        }

        $config = $renditionLadder[$resKey];
        $width = $config['width'];
        $height = $config['height'];
        $crf = $config['crf'];
        $vbitrate = $config['vbitrate'];
        $abitrate = $config['abitrate'];

        // Write initial resolution track placeholder record to database
        $stmtRes = $pdo->prepare("INSERT INTO `stream_resolutions` (`stream_id`, `resolution`, `width`, `height`, `status`) 
                                  VALUES (:stream_id, :resolution, :width, :height, 'processing')");
        $stmtRes->execute([
            ':stream_id' => $streamId,
            ':resolution' => $resKey,
            ':width' => $width,
            ':height' => $height
        ]);
        $resolutionRecordId = (int)$pdo->lastInsertId();

        // Create sub-directory for this specific resolution representation
        $resOutputDir = $streamOutputDir . DIRECTORY_SEPARATOR . $resKey;
        if (!is_dir($resOutputDir)) {
            mkdir($resOutputDir, 0775, true);
        }

        $playlistFilename = 'playlist.m3u8';
        $playlistPath = $resOutputDir . DIRECTORY_SEPARATOR . $playlistFilename;
        $segmentPattern = $resOutputDir . DIRECTORY_SEPARATOR . 'seg_%03d.ts';

        $bitrateNum = (int)preg_replace('/[^0-9]/', '', $vbitrate);
        $bufsize = (int)($bitrateNum * $bufferRatioSetting) . 'k';
        $maxrate = (int)($bitrateNum * $bitrateRatioSetting) . 'k';

        file_put_contents($logFilePath, "--- Starting sequential transcode for {$resKey} rendition ---\n", FILE_APPEND);

        $c_v = 'libx264';
        if ($videoCodecSetting === 'hevc' || $videoCodecSetting === 'h265') {
            $c_v = 'libx265';
        } elseif ($videoCodecSetting !== 'h264' && !empty($videoCodecSetting)) {
            $c_v = $videoCodecSetting;
        }

        $ac = ($audioChannelsSetting === 'mono') ? 1 : 2;
        $targetAudioBitrate = !empty($audioBitrateSetting) ? $audioBitrateSetting : $abitrate;

        $audioMapping = ' -map 0:a:0?';
        $audioOpts = ' -c:a ' . $audioCodecSetting . ' -b:a ' . $targetAudioBitrate . ' -ac ' . $ac;
        if (count($audioStreams) > 1) {
            $audioMapping = '';
            $audioOpts = '';
        }

        $ffmpegCmd = 'ffmpeg -y -i ' . escapeshellarg($sourceFilePath)
            . ' -map 0:v:0' . $audioMapping
            . ' -c:v ' . $c_v . ' -preset ' . $ffmpegPresetSetting
            . ' -crf ' . $crf
            . ' -b:v ' . $vbitrate
            . ' -maxrate ' . $maxrate
            . ' -bufsize ' . $bufsize
            . ' -threads ' . $ffmpegThreadsSetting
            . ' -g ' . $keyframeSetting . ' -keyint_min ' . $keyframeSetting . ' -sc_threshold 0'
            . ' -vf "scale=w=' . $width . ':h=' . $height . ':force_original_aspect_ratio=decrease,pad=' . $width . ':' . $height . ':(ow-iw)/2:(oh-ih)/2,format=yuv420p"'
            . $audioOpts
            . ' -hls_time ' . $hlsTimeSetting . ' -hls_playlist_type vod'
            . ' -hls_segment_filename "' . $segmentPattern . '"'
            . ' "' . $playlistPath . '"'
            . ' 2>&1';

        file_put_contents($logFilePath, "Executing Command: {$ffmpegCmd}\n", FILE_APPEND);

        exec($ffmpegCmd, $ffmpegOut, $ffmpegCode);

        file_put_contents($logFilePath, implode("\n", $ffmpegOut) . "\n", FILE_APPEND);
        file_put_contents($logFilePath, "FFmpeg Exit Code: {$ffmpegCode}\n\n", FILE_APPEND);

        unset($ffmpegOut);

        if ($ffmpegCode === 0 && is_file($playlistPath)) {
            $relativePlaylistPath = 'Output/' . $streamId . '/' . $resKey . '/' . $playlistFilename;

            if ($b2Client) {
                $resFiles = glob($resOutputDir . DIRECTORY_SEPARATOR . '*');
                if (is_array($resFiles)) {
                    usort($resFiles, function ($a, $b) {
                        $isPlaylistA = (pathinfo($a, PATHINFO_EXTENSION) === 'm3u8');
                        $isPlaylistB = (pathinfo($b, PATHINFO_EXTENSION) === 'm3u8');
                        return $isPlaylistA <=> $isPlaylistB;
                    });
                    foreach ($resFiles as $resFile) {
                        if (is_file($resFile)) {
                            $remoteResPath = "Output/{$streamId}/{$resKey}/" . basename($resFile);
                            $uploadedUrl = uploadToB2AndCleanup($resFile, $remoteResPath, $b2Client, $logFilePath);
                            if (basename($resFile) === $playlistFilename && $uploadedUrl) {
                                $relativePlaylistPath = $uploadedUrl;
                            }
                        }
                    }
                }
            }

            $stmtUpdRes = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'completed', `playlist_path` = :path WHERE `id` = :id");
            $stmtUpdRes->execute([
                ':path' => $relativePlaylistPath,
                ':id' => $resolutionRecordId
            ]);

            $successfulRenditionsCount++;

            $bandwidth = $bitrateNum * 1000;
            $masterPlaylistEntries[] = [
                'bandwidth' => $bandwidth,
                'resolution' => "{$width}x{$height}",
                'playlist' => "{$resKey}/{$playlistFilename}"
            ];

            buildMasterPlaylist(
                $streamOutputDir,
                $masterPlaylistEntries,
                $audioPlaylists,
                $subtitlePlaylists,
                $pdo,
                $streamId,
                $b2Client,
                $logFilePath
            );

        } else {
            $stmtUpdRes = $pdo->prepare("UPDATE `stream_resolutions` SET `status` = 'failed' WHERE `id` = :id");
            $stmtUpdRes->execute([':id' => $resolutionRecordId]);
        }
    }
}

if ($successfulRenditionsCount === 0 || $hasB2Errors) {
    // If every selected rendition failed, or if B2 uploading encountered errors, update status to failed
    $updStatus = $pdo->prepare("UPDATE `streams` SET `status` = 'failed' WHERE `id` = :id");
    $updStatus->execute([':id' => $streamId]);
    file_put_contents($logFilePath, "Transcode process aborted or B2 upload errors encountered. Status set to FAILED.\n", FILE_APPEND);
}

// ---------------------------------------------------------------------------------
// STEP 4: CLEAN UP TEMPORARY STITCHED SOURCE VIDEO FILE
// ---------------------------------------------------------------------------------
if (is_file($sourceFilePath)) {
    @unlink($sourceFilePath);
    file_put_contents($logFilePath, "Cleaned up temporary original source video file.\n", FILE_APPEND);
}

file_put_contents($logFilePath, "Background worker terminated at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// ---------------------------------------------------------------------------------
// STEP 5: CLEAN UP LOCAL HLS FILES IF B2 WAS ACTIVE AND TRANSCODE SUCCEEDED WITHOUT B2 ERRORS
// ---------------------------------------------------------------------------------
if ($b2Client && $successfulRenditionsCount > 0 && !$hasB2Errors) {
    // Remove local master.m3u8
    if (is_file($masterPlaylistPath)) {
        @unlink($masterPlaylistPath);
    }
    // Remove the transcode worker log file itself to leave 0 trace on disk
    if (is_file($logFilePath)) {
        @unlink($logFilePath);
    }
    
    // Helper function definition for directory removal (already defined in api.php but needed here since CLI runs independently)
    if (!function_exists('recursiveRemoveDirectory')) {
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
    }
    
    // Remove empty stream Output folder recursively
    recursiveRemoveDirectory($streamOutputDir);
}

/**
 * Compiles and updates the master.m3u8 playlist file, uploads it to B2 if active,
 * and updates the stream status in the database to 'ready'.
 */
function buildMasterPlaylist(
    string $streamOutputDir,
    array $masterPlaylistEntries,
    array $audioPlaylists,
    array $subtitlePlaylists,
    PDO $pdo,
    string $streamId,
    ?B2Client $b2Client,
    string $logFilePath
): void {
    $masterPlaylistPath = $streamOutputDir . DIRECTORY_SEPARATOR . 'master.m3u8';
    
    // Sort rendition streams by bandwidth to support adaptive quality descending ladders
    usort($masterPlaylistEntries, function ($a, $b) {
        return $b['bandwidth'] <=> $a['bandwidth'];
    });

    // Write primary HLS headers and playlist references
    $m3u8Content = "#EXTM3U\n#EXT-X-VERSION:3\n";
    
    // Add audio tracks to master playlist
    $audioGroup = "";
    if (!empty($audioPlaylists)) {
        foreach ($audioPlaylists as $aud) {
            $m3u8Content .= "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$aud['group']}\",NAME=\"{$aud['name']}\",DEFAULT={$aud['default']},AUTOSELECT=YES,LANGUAGE=\"{$aud['lang']}\",URI=\"{$aud['uri']}\"\n";
        }
        $audioGroup = ",AUDIO=\"audio_group\"";
    }

    // Add subtitle tracks to master playlist
    $subGroup = "";
    if (!empty($subtitlePlaylists)) {
        foreach ($subtitlePlaylists as $sub) {
            $m3u8Content .= "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$sub['group']}\",NAME=\"{$sub['name']}\",DEFAULT={$sub['default']},AUTOSELECT={$sub['default']},LANGUAGE=\"{$sub['lang']}\",URI=\"{$sub['uri']}\"\n";
        }
        $subGroup = ",SUBTITLES=\"subs\"";
    }

    foreach ($masterPlaylistEntries as $entry) {
        $m3u8Content .= "#EXT-X-STREAM-INF:BANDWIDTH={$entry['bandwidth']},RESOLUTION={$entry['resolution']}{$audioGroup}{$subGroup}\n";
        $m3u8Content .= "{$entry['playlist']}\n";
    }

    file_put_contents($masterPlaylistPath, $m3u8Content);

    // Upload master.m3u8 if B2 is enabled (overwrite it)
    $hlsPlaylistUrl = 'Output/' . $streamId . '/master.m3u8';
    if ($b2Client) {
        try {
            file_put_contents($logFilePath, "Uploading master.m3u8 to B2...\n", FILE_APPEND);
            $publicMasterUrl = $b2Client->uploadFile($masterPlaylistPath, "Output/{$streamId}/master.m3u8");
            $hlsPlaylistUrl = $publicMasterUrl;
        } catch (Exception $e) {
            file_put_contents($logFilePath, "Failed to upload master playlist to B2: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // Save/Update master playlist path into database and transition status to 'ready'
    $updStatus = $pdo->prepare("UPDATE `streams` SET `status` = 'ready', `hls_playlist_url` = :url WHERE `id` = :id");
    $updStatus->execute([
        ':url' => $hlsPlaylistUrl,
        ':id' => $streamId
    ]);
    
    file_put_contents($logFilePath, "Manifest updated. Stream status: READY. Playlist URL: {$hlsPlaylistUrl}\n", FILE_APPEND);
}
