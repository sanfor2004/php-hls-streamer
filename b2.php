<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE NATIVE BACKBLAZE B2 CLIENT (b2.php)
 * =================================================================================
 * Lightweight, dependency-free wrapper for Backblaze B2 Cloud Storage APIs.
 * Uses native PHP cURL requests to handle account authorization, upload endpoint
 * retrieval, and file byte streaming with verification.
 * =================================================================================
 */
class B2Client
{
    private string $keyId;
    private string $applicationKey;
    private string $bucketId;
    private string $bucketName;

    private ?string $authToken = null;
    private ?string $apiUrl = null;
    private ?string $downloadUrl = null;

    public function __construct(string $keyId, string $applicationKey, string $bucketId, string $bucketName)
    {
        $this->keyId = trim($keyId);
        $this->applicationKey = trim($applicationKey);
        $this->bucketId = trim($bucketId);
        $this->bucketName = trim($bucketName);
    }

    /**
     * Authenticates credentials with the Backblaze B2 API server.
     */
    private function authorize(): void
    {
        if ($this->authToken !== null) {
            return;
        }

        $credentials = base64_encode($this->keyId . ':' . $this->applicationKey);

        $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
        if ($ch === false) {
            throw new Exception("Unable to initialize cURL handle for B2 authorization.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$response) {
            throw new Exception("B2 authorization failed. HTTP code: " . $status . ", Response: " . ($response ?: 'Empty Response'));
        }

        $data = json_decode((string) $response, true);
        $this->authToken = $data['authorizationToken'] ?? null;
        $this->apiUrl = $data['apiUrl'] ?? null;
        $this->downloadUrl = $data['downloadUrl'] ?? null;

        if (!$this->authToken || !$this->apiUrl || !$this->downloadUrl) {
            throw new Exception("B2 authorization response is missing critical fields (token or endpoint URLs).");
        }
    }

    /**
     * Returns the base public access download URL prefix.
     */
    public function getDownloadUrlPrefix(): string
    {
        $this->authorize();
        return rtrim($this->downloadUrl, '/') . '/file/' . $this->bucketName;
    }

    /**
     * Uploads a local file to the targeted remote path in the B2 bucket.
     */
    public function uploadFile(string $localFilePath, string $remotePath): string
    {
        $this->authorize();

        if (!is_file($localFilePath)) {
            throw new Exception("Local source file does not exist: " . $localFilePath);
        }

        // 1. Get an upload URL specific to the B2 Bucket
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_get_upload_url');
        if ($ch === false) {
            throw new Exception("Unable to initialize cURL handle for B2 upload endpoint query.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'bucketId' => $this->bucketId
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$response) {
            throw new Exception("Failed to query B2 upload URL. HTTP code: " . $status . ", Response: " . ($response ?: 'Empty Response'));
        }

        $data = json_decode((string) $response, true);
        $uploadUrl = $data['uploadUrl'] ?? null;
        $uploadAuthToken = $data['authorizationToken'] ?? null;

        if (!$uploadUrl || !$uploadAuthToken) {
            throw new Exception("B2 upload endpoint response is missing token or target upload URL.");
        }

        // 2. Perform the file bytes upload operation
        $fileSize = filesize($localFilePath);
        $fileData = file_get_contents($localFilePath);
        if ($fileData === false) {
            throw new Exception("Unable to read local source file data: " . $localFilePath);
        }
        $sha1 = sha1($fileData);

        // Maintain relative URL directories and urlencode names properly (preserving forward slashes)
        $cleanRemotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        $pathParts = explode('/', $cleanRemotePath);
        $encodedParts = array_map('rawurlencode', $pathParts);
        $safeRemotePath = implode('/', $encodedParts);

        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Ensure we are authorized
                $this->authorize();

                // 1. Get an upload URL specific to the B2 Bucket
                $ch = curl_init($this->apiUrl . '/b2api/v2/b2_get_upload_url');
                if ($ch === false) {
                    throw new Exception("Unable to initialize cURL handle for B2 upload endpoint query.");
                }

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'bucketId' => $this->bucketId
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: ' . $this->authToken,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($status !== 200 || !$response) {
                    throw new Exception("Failed to query B2 upload URL. HTTP code: " . $status . ", Response: " . ($response ?: 'Empty Response'));
                }

                $data = json_decode((string) $response, true);
                $uploadUrl = $data['uploadUrl'] ?? null;
                $uploadAuthToken = $data['authorizationToken'] ?? null;

                if (!$uploadUrl || !$uploadAuthToken) {
                    throw new Exception("B2 upload endpoint response is missing token or target upload URL.");
                }

                // 2. Perform the file bytes upload operation
                $ch = curl_init($uploadUrl);
                if ($ch === false) {
                    throw new Exception("Unable to initialize cURL handle for B2 file transmission.");
                }

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: ' . $uploadAuthToken,
                    'X-Bz-File-Name: ' . $safeRemotePath,
                    'Content-Type: b2/x-auto',
                    'Content-Length: ' . $fileSize,
                    'X-Bz-Content-Sha1: ' . $sha1
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                // Set higher timeout for massive file transfers (e.g. 5 minutes)
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);

                $response = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($status !== 200 || !$response) {
                    throw new Exception("B2 file upload failed. HTTP code: " . $status . ", Response: " . ($response ?: 'Empty Response'));
                }

                return $this->getDownloadUrlPrefix() . '/' . $cleanRemotePath;

            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    // Exponential backoff sleep (1s, 2s)
                    usleep($attempt * 1000000);
                    // Force re-authorization in case auth token has expired
                    if ($attempt === $maxAttempts - 1) {
                        $this->authToken = null;
                    }
                }
            }
        }

        throw new Exception("B2 file transmission completely failed after {$maxAttempts} attempts. Last error: " . $lastException->getMessage());
    }
}
