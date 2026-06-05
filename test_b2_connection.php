<?php
declare(strict_types=1);

/**
 * =================================================================================
 * BACKBLAZE B2 CONNECTIVITY TESTER (test_b2_connection.php)
 * =================================================================================
 * Standalone script to verify authorization and bucket access for B2 Cloud Storage.
 * Dynamically queries active credentials from database.sqlite to protect keys.
 *
 * Usage: php test_b2_connection.php
 * =================================================================================
 */

if (PHP_SAPI !== 'cli') {
    die("Error: This script can only be run via CLI.\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'b2.php';

// 1. Fetch B2 credentials from database settings table
try {
    $pdo = getDatabaseConnection('dev');
    $stmt = $pdo->query("SELECT * FROM `settings`");
    $settings = [];
    if ($stmt) {
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
    }
} catch (Exception $e) {
    die("Error: Database connection failed: " . $e->getMessage() . "\n");
}

$keyId = $settings['b2_key_id'] ?? '';
$applicationKey = $settings['b2_application_key'] ?? '';
$bucketId = $settings['b2_bucket_id'] ?? '';
$bucketName = $settings['b2_bucket_name'] ?? '';

// 2. Validate credentials completeness
if (empty($keyId) || empty($applicationKey) || empty($bucketId) || empty($bucketName)) {
    echo "❌ Error: B2 credentials are not fully configured in your dashboard settings.\n";
    echo "Please visit http://127.0.0.1:8080/settings.php to configure Key ID, Application Key, Bucket ID, and Bucket Name first.\n";
    exit(1);
}

echo "Testing connection to Backblaze B2...\n";
echo "Bucket Name: {$bucketName}\n";
echo "Bucket ID:   {$bucketId}\n";
echo "Key ID:      {$keyId}\n";
echo "--------------------------------------------------\n";

try {
    $client = new B2Client($keyId, $applicationKey, $bucketId, $bucketName);
    
    // Attempt authorization
    $downloadPrefix = $client->getDownloadUrlPrefix();
    echo "✅ Success: B2 Account Authorized Successfully!\n";
    echo "Download URL Prefix: {$downloadPrefix}\n\n";

    // Attempt dummy file upload to fully test credentials
    $tempFile = __DIR__ . DIRECTORY_SEPARATOR . 'b2_test_temp.txt';
    file_put_contents($tempFile, "B2 Ingestion Connectivity Test File - " . date('Y-m-d H:i:s'));
    
    echo "Attempting test file upload...\n";
    $remotePath = "Output/tests/connectivity_test.txt";
    
    $uploadedUrl = $client->uploadFile($tempFile, $remotePath);
    @unlink($tempFile);
    
    echo "✅ Success: Test file uploaded successfully!\n";
    echo "Public Test URL: {$uploadedUrl}\n";
    echo "--------------------------------------------------\n";
    echo "B2 configuration is 100% correct and ready for transcoding uploads!\n";
    
} catch (Exception $e) {
    echo "❌ Connection Failed: " . $e->getMessage() . "\n";
    if (isset($tempFile) && is_file($tempFile)) {
        @unlink($tempFile);
    }
    exit(1);
}
