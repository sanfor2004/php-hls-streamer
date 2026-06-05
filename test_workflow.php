<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE END-TO-END WORKFLOW TESTER (test_workflow.php)
 * =================================================================================
 * Generates a synthetic test video, creates a mock upload record in SQLite,
 * runs the transcoding pipeline synchronously, and audits B2 upload results.
 * =================================================================================
 */

echo "=====================================================================\n";
echo "           STARTING END-TO-END PIPELINE AUDIT SEQUENCE\n";
echo "=====================================================================\n\n";

// 1. Setup paths
$rootDir = __DIR__;
$inputDir = $rootDir . DIRECTORY_SEPARATOR . 'Input';
$outputDir = $rootDir . DIRECTORY_SEPARATOR . 'Output';

if (!is_dir($inputDir)) {
    mkdir($inputDir, 0775, true);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

// 2. Generate a synthetic test.mp4 if it does not exist
$sourceVideo = $rootDir . DIRECTORY_SEPARATOR . 'test.mp4';
if (!is_file($sourceVideo)) {
    echo "Creating synthetic test.mp4 video file (5 seconds)... ";
    $generateCmd = 'ffmpeg -y -f lavfi -i "testsrc=duration=5:size=640x360:rate=30" -f lavfi -i "sine=frequency=1000:duration=5" -c:v libx264 -c:a aac -pix_fmt yuv420p "' . $sourceVideo . '" 2>&1';
    exec($generateCmd, $genOut, $genCode);
    if ($genCode !== 0) {
        echo "❌ FAILED!\n";
        echo implode("\n", $genOut) . "\n";
        exit(1);
    }
    echo "✅ SUCCESS!\n";
} else {
    echo "✅ Synthetic test.mp4 video file is already present.\n";
}

// 3. Connect to database and verify B2 settings
require_once $rootDir . DIRECTORY_SEPARATOR . 'db.php';
try {
    $pdo = getDatabaseConnection('dev');
    echo "✅ Database connection to database.sqlite is online.\n";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Print B2 settings from SQLite
$stmt = $pdo->query("SELECT * FROM `settings` WHERE `key` LIKE 'b2_%'");
$b2Settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo "\n---------------------------------------------------------------------\n";
echo "B2 CONFIGURATION AUDIT:\n";
echo "---------------------------------------------------------------------\n";
foreach (['b2_key_id', 'b2_application_key', 'b2_bucket_id', 'b2_bucket_name'] as $key) {
    $val = $b2Settings[$key] ?? '';
    if ($key === 'b2_application_key') {
        $maskedVal = empty($val) ? '[EMPTY]' : substr($val, 0, 4) . str_repeat('*', strlen($val) - 4);
        echo "  {$key}: {$maskedVal}\n";
    } else {
        echo "  {$key}: " . (empty($val) ? '[EMPTY]' : $val) . "\n";
    }
}
echo "---------------------------------------------------------------------\n\n";

// 4. Create database record for stream
$testStreamId = 'test_' . Math_random_str(8);
$finalFilename = $testStreamId . '_test.mp4';
$finalPath = $inputDir . DIRECTORY_SEPARATOR . $finalFilename;

// Copy test.mp4 to Input folder
if (!copy($sourceVideo, $finalPath)) {
    echo "❌ Error: Failed to copy test.mp4 to {$finalPath}\n";
    exit(1);
}
echo "✅ Copied test video file to Input directory: {$finalFilename}\n";

try {
    $resolutionsSelected = json_encode(['360p']); // Transcode only 360p to keep it fast
    $stmt = $pdo->prepare("INSERT INTO `streams` (`id`, `title`, `filename`, `status`, `resolutions_selected`) 
                           VALUES (:id, :title, :filename, :status, :resolutions_selected)");
    $stmt->execute([
        ':id' => $testStreamId,
        ':title' => 'End-to-End Audit Stream',
        ':filename' => $finalFilename,
        ':status' => 'pending',
        ':resolutions_selected' => $resolutionsSelected
    ]);
    echo "✅ Inserted pending stream record in database. ID: {$testStreamId}\n";
} catch (Exception $e) {
    echo "❌ Database insert failed: " . $e->getMessage() . "\n";
    @unlink($finalPath);
    exit(1);
}

// 5. Run transcode.php synchronously
echo "\n=====================================================================\n";
echo "          EXECUTING TRANSCODING PIPELINE WORKER\n";
echo "=====================================================================\n";
$transcodeCmd = 'php "' . $rootDir . DIRECTORY_SEPARATOR . 'transcode.php" ' . $testStreamId . ' dev';
echo "Running: {$transcodeCmd}\n\n";

$startTime = microtime(true);
$output = [];
$exitCode = -1;
exec($transcodeCmd, $output, $exitCode);
$elapsedTime = round(microtime(true) - $startTime, 2);

echo "Worker completed in {$elapsedTime} seconds. Exit Code: {$exitCode}\n";
echo "Console Output:\n";
echo "---------------------------------------------------------------------\n";
echo implode("\n", $output) . "\n";
echo "---------------------------------------------------------------------\n\n";

// 6. Audit post-transcode state
echo "=====================================================================\n";
echo "          AUDITING RESULTS AND TELEMETRY LOGS\n";
echo "=====================================================================\n";

// Query stream record
$stmt = $pdo->prepare("SELECT * FROM `streams` WHERE `id` = :id");
$stmt->execute([':id' => $testStreamId]);
$streamRecord = $stmt->fetch();

if (!$streamRecord) {
    echo "❌ Error: Stream record was deleted or not found!\n";
} else {
    echo "Database Record Audit:\n";
    echo "  Status:           " . $streamRecord['status'] . "\n";
    echo "  HLS Playlist URL: " . ($streamRecord['hls_playlist_url'] ?? '[NULL]') . "\n";
}

// Check if log file exists (which would mean transcode failed or B2 had errors)
$logFilePath = $outputDir . DIRECTORY_SEPARATOR . $testStreamId . DIRECTORY_SEPARATOR . 'transcode_worker.log';
if (is_file($logFilePath)) {
    echo "\n⚠️ Log file detected at Output/{$testStreamId}/transcode_worker.log.\n";
    echo "Transcoding Worker Internal Logs:\n";
    echo "---------------------------------------------------------------------\n";
    echo file_get_contents($logFilePath) . "\n";
    echo "---------------------------------------------------------------------\n";
} else {
    echo "\n✅ No log file remaining in Output directory (successfully cleaned up/transcode succeeded!).\n";
}

// Clean up synthetic source file from root if it's there (we keep it for future tests if needed)
echo "\nAudit complete.\n";

// Helper function to match random str
function Math_random_str(int $length = 8): string {
    return substr(bin2hex(random_bytes($length)), 0, $length);
}
