<?php
declare(strict_types=1);

/**
 * =================================================================================
 * BACKBLAZE B2 CONNECTIVITY TESTER (test_b2_connection.php)
 * =================================================================================
 * Standalone script to verify authorization and bucket access for B2 Cloud Storage.
 *
 * Usage: php test_b2_connection.php {application_key}
 * =================================================================================
 */

if (PHP_SAPI !== 'cli') {
    die("Error: This script can only be run via CLI.\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'b2.php';

// Hardcoded details from user input
$keyId = '003cd8abcad0cb20000000001';
$bucketId = 'dcbd188aeb0cbabd90cc0b12';
$bucketName = 'gowatch';

// Retrieve Application Key from CLI argument
if ($argc < 2 || empty($argv[1])) {
    echo "Error: Missing Application Key.\n";
    echo "Usage: php test_b2_connection.php YOUR_SECRET_APPLICATION_KEY\n";
    exit(1);
}

$applicationKey = trim($argv[1]);

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
