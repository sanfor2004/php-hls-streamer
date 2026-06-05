<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE DATABASE DRIVER & AUTO-MIGRATIONS CONFIGURATION (db.php)
 * =================================================================================
 * Exposes core PDO connection switches, automatically boots/migrates database schemas
 * on active requests, and calculates platform storage telemetry metrics.
 * =================================================================================
 */

/**
 * Detects if the active environment is running in Development mode.
 */
function isDevelopmentMode(?string $cliModeOverride = null): bool
{
    // Honor explicit CLI mode override if provided
    if ($cliModeOverride !== null) {
        return $cliModeOverride === 'dev';
    }

    // Check if executing via Command Line Interface (CLI)
    if (PHP_SAPI === 'cli') {
        global $argv;
        if (isset($argv) && is_array($argv)) {
            // Check if transcode.php forwarded an environment parameter
            if (count($argv) >= 3) {
                $argMode = strtolower(trim((string)$argv[2]));
                if ($argMode === 'prod') {
                    return false;
                }
                if ($argMode === 'dev') {
                    return true;
                }
            }
        }
        // Fallback: If local SQLite database file exists, default to dev
        return file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite');
    }

    // HTTP Web request context: check client remote IP address
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remoteIp === '127.0.0.1' || $remoteIp === '::1';
}

/**
 * Creates and returns the active PDO database connection with automatic schema migrations.
 */
function getDatabaseConnection(?string $cliModeOverride = null): PDO
{
    if (isDevelopmentMode($cliModeOverride)) {
        // SQLite Development DSN
        $dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite';
        $dsn = "sqlite:" . $dbFile;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Enable SQLite foreign keys constraint checks
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } else {
        // MySQL Production Connection DSN
        $host = 'localhost';
        $dbname = 'userpleyer_top';
        $username = 'userpleyer_top';
        $password = '8BXjxN5ppmD2Szjx';

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
    }

    // Auto-migrate tables schema if they do not exist
    initializeDatabaseSchema($pdo);

    return $pdo;
}

/**
 * Automigrates and initializes all tables and seed values.
 */
function initializeDatabaseSchema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        // 1. Streams Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `streams` (
            `id` VARCHAR(255) PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'uploading',
            `video_codec` VARCHAR(50) DEFAULT NULL,
            `duration` INT DEFAULT NULL,
            `resolutions_selected` TEXT NOT NULL,
            `hls_playlist_url` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. Stream Resolutions Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `stream_resolutions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `stream_id` VARCHAR(255) NOT NULL,
            `resolution` VARCHAR(50) NOT NULL,
            `width` INT NOT NULL,
            `height` INT NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
            `playlist_path` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 3. Ads Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ads` (
            `id` VARCHAR(255) PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `vast_url` TEXT NOT NULL,
            `offset_type` VARCHAR(50) NOT NULL,
            `offset_value` VARCHAR(255) NOT NULL,
            `is_active` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 4. Settings Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` VARCHAR(255) PRIMARY KEY,
            `value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 5. Users Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    } else {
        // SQLite Tables Schema
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS `stream_resolutions` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `stream_id` TEXT NOT NULL,
            `resolution` TEXT NOT NULL,
            `width` INTEGER NOT NULL,
            `height` INTEGER NOT NULL,
            `status` TEXT NOT NULL DEFAULT 'pending',
            `playlist_path` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ads` (
            `id` TEXT PRIMARY KEY,
            `name` TEXT NOT NULL,
            `vast_url` TEXT NOT NULL,
            `offset_type` TEXT NOT NULL,
            `offset_value` TEXT NOT NULL,
            `is_active` INTEGER NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` TEXT PRIMARY KEY,
            `value` TEXT NOT NULL
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `username` TEXT NOT NULL UNIQUE,
            `password_hash` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );");
    }

    // Seed default settings values if they are missing
    try {
        $defaults = [
            'video_codec'        => 'h264',
            'keyframe'           => '60',
            'bitrate_ratio'      => '1.07',
            'buffer_ratio'       => '1.5',
            'hls_time'           => '6',
            'add_top_quality'    => '1',
            'audio_codec'        => 'aac',
            'audio_bitrate'      => '128k',
            'audio_channels'     => 'stereo',
            'b2_key_id'          => '',
            'b2_application_key' => '',
            'b2_bucket_id'       => '',
            'b2_bucket_name'     => '',
            'renditions'         => json_encode([
                '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 25, 'vbitrate' => '4096k', 'abitrate' => '192k'],
                '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 26, 'vbitrate' => '2048k', 'abitrate' => '128k'],
                '540p'  => ['width' => 960,  'height' => 540,  'crf' => 27, 'vbitrate' => '1500k', 'abitrate' => '128k'],
                '480p'  => ['width' => 854,  'height' => 480,  'crf' => 28, 'vbitrate' => '750k',  'abitrate' => '128k'],
                '360p'  => ['width' => 640,  'height' => 360,  'crf' => 29, 'vbitrate' => '276k',  'abitrate' => '96k'],
            ])
        ];

        // For SQLite and MySQL, check/insert or update empty B2 credentials
        $checkStmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = :key");
        $insertStmt = $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (:key, :value)");
        $updateStmt = $pdo->prepare("UPDATE `settings` SET `value` = :value WHERE `key` = :key");

        foreach ($defaults as $k => $val) {
            $checkStmt->execute([':key' => $k]);
            $row = $checkStmt->fetch();
            if (!$row) {
                // Key does not exist, insert it
                $insertStmt->execute([':key' => $k, ':value' => $val]);
            } else {
                // If it is one of the B2 keys and currently empty, update it with the user's details
                $currentVal = trim((string)$row['value']);
                if (in_array($k, ['b2_key_id', 'b2_bucket_id', 'b2_bucket_name'], true) && $currentVal === '') {
                    $updateStmt->execute([':key' => $k, ':value' => $val]);
                }
            }
        }
    } catch (PDOException $e) {
        // Fallback or ignore write failures in concurrent environments
    }

    // Seed default admin account details if users table is empty
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
        if ($count === 0) {
            $defaultUsername = 'admin';
            $defaultPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password_hash`) VALUES (:username, :password_hash)");
            $stmt->execute([':username' => $defaultUsername, ':password_hash' => $defaultPasswordHash]);
        }
    } catch (PDOException $e) {
        // Fallback or ignore write failures in concurrent environments
    }
}

/**
 * Calculates the current database storage size based on the connection driver.
 */
function getDatabaseSize(PDO $pdo): string
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        try {
            $stmt = $pdo->prepare("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = :dbname");
            $stmt->execute([':dbname' => 'userpleyer_top']);
            $size = (int)$stmt->fetchColumn();
            return round($size / 1024, 1) . ' KB';
        } catch (PDOException $e) {
            return 'N/A';
        }
    } else {
        $dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite';
        if (is_file($dbFile)) {
            $size = filesize($dbFile);
            return round($size / 1024, 1) . ' KB';
        }
        return '0 KB';
    }
}
