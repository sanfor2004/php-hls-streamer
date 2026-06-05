<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH-TIER CORE VANILLA PHP EMBEDDABLE VIDEO PLAYER (stream.php)
 * =================================================================================
 * A fully bespoke, custom-designed HTML5 streaming player.
 * Skin matching the user's reference design: orange accented scrubber timeline,
 * custom large translucent center play overlay, brand-specific control panels,
 * serif "A" closed captions (CC) translating script popups, gear playback settings,
 * skip back/forward, and horizontal volume sliders.
 *
 * Usage: stream.php?id={video_id} (or stream?id={video_id})
 * =================================================================================
 */

// ---------------------------------------------------------------------------------
// PLAYER CONFIGURATION FLAGS
// ---------------------------------------------------------------------------------
/**
 * USE_CUSTOM_CONTROLS — Toggle for the bespoke Zenith-Tier player overlay.
 *
 *   true  → Custom orange-accented scrubber, center-play overlay, settings panel,
 *            volume HUD, and all bespoke controls are rendered. The native
 *            Video.js control bar is completely hidden. (DEFAULT)
 *
 *   false → The native Video.js control bar is shown. The entire bespoke
 *            overlay is NOT rendered, so the page is lighter and the player
 *            falls back to the standard Video.js look-and-feel.
 */
const USE_CUSTOM_CONTROLS = true;

// ---------------------------------------------------------------------------------
// DATABASE CONNECTION CONFIGURATION
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

// ---------------------------------------------------------------------------------
// CAPTURE STREAM ID & VALIDATE RECORD
// ---------------------------------------------------------------------------------
$streamId = $_GET['id'] ?? '';
$streamId = preg_replace('/[^a-zA-Z0-9_-]/', '', $streamId);

$stream = null;
$ads = [];

if (!empty($streamId) && $dbError === null && $pdo !== null) {
  try {
    $stmt = $pdo->prepare("SELECT * FROM `streams` WHERE `id` = :id");
    $stmt->execute([':id' => $streamId]);
    $stream = $stmt->fetch();

    // Fetch active ad campaigns
    $adStmt = $pdo->query("SELECT * FROM `ads` WHERE `is_active` = 1");
    $ads = $adStmt->fetchAll();
  } catch (PDOException $e) {
    $dbError = $e->getMessage();
  }
}

// Set header for UTF-8 response
header('Content-Type: text/html; charset=utf-8');
// No-cache: force browser to always fetch fresh copy — no stale embed states
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($stream ? $stream['title'] : 'Stream Not Found'); ?> - Bespoke Player</title>
  <link rel="icon" type="image/png" href="favicon.png">

  <!-- Premium Fonts Setup -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap"
    rel="stylesheet">

  <!-- Video.js Default Core Skin -->
  <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
  <!-- Video.js Contrib Ads Skin (jsdelivr — cdnjs v6.9.0 does not exist) -->
  <link href="https://cdn.jsdelivr.net/npm/videojs-contrib-ads@6.6.4/dist/videojs-contrib-ads.css" rel="stylesheet" />
  <!-- Google IMA SDK plugin layout sheets -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/videojs-ima/1.11.0/videojs.ima.css" rel="stylesheet" />

  <link rel="stylesheet" href="assets/css/player.css">
</head>

<body>

  <?php if (!$stream): ?>
    <!-- SYSTEM OFFLINE OR STREAM NOT FOUND PANELS -->
    <div class="cinematic-error-overlay">
      <div class="grid-mesh"></div>
      <div class="error-radial-blur"></div>
      <div class="error-glass-card">
        <div class="error-icon-container">
          <svg viewBox="0 0 24 24" class="error-svg-pulse">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
          </svg>
        </div>
        <h2 class="error-title">Stream Not Found</h2>
        <p class="error-guidance">The request could not resolve the required HLS transcode stream record in the SQLite
          database.</p>

        <div class="technical-terminal">
          <div class="terminal-bar">
            <span class="term-dot"></span><span class="term-dot"></span><span class="term-dot"></span>
            <span class="terminal-title">System Diagnostic</span>
          </div>
          <code class="terminal-code">
                      <?php
                      if ($dbError !== null) {
                        echo 'SQLITE_CONN_ERROR: ' . htmlspecialchars($dbError);
                      } else {
                        echo 'HLS_STREAM_RESOLVE_404: stream "' . htmlspecialchars($streamId) . '" not in directory';
                      }
                      ?>
                    </code>
        </div>

        <div class="error-actions">
          <a href="index" class="error-btn-primary">Open Console</a>
          <button onclick="window.location.reload()" class="error-btn-secondary">Retry</button>
        </div>
      </div>
    </div>
    <?php exit; ?>
  <?php endif; ?>

  <!-- Active Player View -->
  <div class="player-wrapper">
    <!-- Top Ad Progress HUD Overlay -->
    <div class="ad-hud-banner" id="ad-hud-banner">
      <div style="display: flex; align-items: center;">
        <span class="ad-badge-neon">ADVERTISEMENT</span>
        <span class="ad-hud-text" style="font-family: var(--font-display); color: #fff;">Sponsored Video Segment</span>
      </div>
      <div>
        <span class="ad-countdown" id="ad-countdown-timer">Ad playing...</span>
      </div>
    </div>

    <!-- Floating Live Telemetry Badge + statistics Drawer (Removed in favor of bottom-left watermark branding) -->

    <?php if (USE_CUSTOM_CONTROLS): ?>
      <!-- Custom Translucent Center Play Button overlay -->
      <div class="custom-center-play-overlay" id="custom-center-play">
        <svg viewBox="0 0 24 24">
          <path d="M8 5v14l11-7z" />
        </svg>
      </div>
    <?php endif; ?>

    <!-- Primary Video Element: NO source element here —
         src is set via player.src() in JS so Video.js VHS intercepts
         the HLS stream before the browser rejects the m3u8 MIME type. -->
    <video id="zenith-player" class="video-js vjs-default-skin vjs-big-play-centered" <?php if (!USE_CUSTOM_CONTROLS): ?>controls<?php endif; ?> playsinline preload="auto">
      <p class="vjs-no-js">
        To view this video please enable JavaScript, and upgrade to a browser supporting HTML5 HLS.
      </p>
    </video>

    <?php if (USE_CUSTOM_CONTROLS): ?>
      <!-- Custom bespoken controls overlay panel matching the reference screenshot exactly -->
      <div class="custom-controls-container" id="custom-controls-hud">

        <!-- Watermark label on top of Time Indicators -->
        <div class="custom-watermark-left-down">Ahmed Streamer Player</div>

        <!-- Time Indicators placed above timeline -->
        <div class="custom-time-labels">
          <div class="time-current" id="custom-time-current">00:00</div>
          <div class="time-total" id="custom-time-total">00:00</div>
        </div>

        <!-- Scrubber Orange Progress Timeline bar -->
        <div class="custom-scrub-container" id="custom-scrubber">
          <div class="custom-scrub-track" id="custom-scrub-track">
            <div class="custom-scrub-fill" id="custom-scrub-fill"></div>
            <div class="custom-scrub-handle" id="custom-scrub-handle"></div>
          </div>
        </div>

        <!-- Main Controls Deck -->
        <div class="custom-main-controls">

          <!-- Left: Dynamic Titles (Brand badge removed) -->
          <div class="custom-controls-left">
            <div class="custom-title-stack">
              <span class="custom-video-title">
                <?php
                $titleWords = explode(' ', $stream['title']);
                if (count($titleWords) > 3) {
                  echo htmlspecialchars(implode(' ', array_slice($titleWords, 0, 3)) . '...');
                } else {
                  echo htmlspecialchars($stream['title']);
                }
                ?>
              </span>
              <span class="custom-video-subtitle">Ahmed Streamer player</span>
            </div>
          </div>

          <!-- Middle: Skip back, central orange circle play/pause button, skip forward -->
          <div class="custom-controls-middle">
            <button class="custom-btn" id="custom-skip-back" title="Skip Backward 10s">
              <svg viewBox="0 0 24 24">
                <path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z" />
              </svg>
            </button>

            <button class="custom-btn custom-btn-play-orange" id="custom-play-main" title="Play/Pause Stream">
              <svg class="play-icon" viewBox="0 0 24 24">
                <path d="M8 5v14l11-7z" />
              </svg>
              <svg class="pause-icon" viewBox="0 0 24 24" style="display:none;">
                <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
              </svg>
            </button>

            <button class="custom-btn" id="custom-skip-forward" title="Skip Forward 10s">
              <svg viewBox="0 0 24 24">
                <path d="M4 18l8.5-6L4 6v12zm9-12v12l8.5-6L13 6z" />
              </svg>
            </button>
          </div>

          <!-- Right: Volume HUD, Serif A CC toggling, settings gear flyouts, brackets fullscreen -->
          <div class="custom-controls-right">

            <div class="custom-volume-hud">
              <button class="custom-btn" id="custom-mute" title="Mute/Unmute Audio" style="padding: 0;">
                <!-- Speaker Loud (High Volume) -->
                <svg class="vol-high-icon" viewBox="0 0 24 24" style="width: 17px; height: 17px;" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M11 5L6 9H2v6h4l5 4V5z" />
                  <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                  <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
                </svg>
                <!-- Speaker Soft (Low Volume) -->
                <svg class="vol-low-icon" viewBox="0 0 24 24" style="width: 17px; height: 17px; display: none;"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M11 5L6 9H2v6h4l5 4V5z" />
                  <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                </svg>
                <!-- Speaker Muted -->
                <svg class="vol-mute-icon" viewBox="0 0 24 24" style="width: 17px; height: 17px; display: none;"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M11 5L6 9H2v6h4l5 4V5z" />
                  <line x1="23" y1="9" x2="17" y2="15" />
                  <line x1="17" y1="9" x2="23" y2="15" />
                </svg>
              </button>
              <input type="range" class="custom-volume-slider" id="custom-volume-slider" min="0" max="1" step="0.05"
                value="1">
            </div>

            <!-- Dedicated Audio Tracks Language Trigger -->
            <button class="custom-audio-button" id="custom-audio-btn" title="Alternative Audio Languages">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M3 18v-6a9 9 0 0 1 18 0v6" />
                <path
                  d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z" />
              </svg>
            </button>

            <!-- Dedicated Subtitles Script CC Trigger (Standard CC Icon) -->
            <button class="custom-cc-button" id="custom-cc-btn" title="Subtitles & Closed Captions (CC)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="16" rx="2" ry="2" />
                <path d="M7 8h10M7 12h10M7 16h6" />
              </svg>
            </button>

            <button class="custom-btn custom-settings-button" id="custom-settings-btn" title="Playback Settings Options">
              <svg viewBox="0 0 24 24">
                <path
                  d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z" />
              </svg>
            </button>

            <button class="custom-btn custom-fullscreen-button" id="custom-fullscreen-btn" title="Fullscreen brackets">
              <svg viewBox="0 0 24 24">
                <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z" />
              </svg>
            </button>

          </div>
        </div>
      </div>

      <!-- Double-Nested Sliding Settings Panel Deck -->
      <div class="glass-settings-deck" id="settings-deck-panel">
        <div class="deck-slider-track" id="deck-slider-track">

          <!-- Panel 1: Primary settings items menu -->
          <div class="deck-panel" id="deck-panel-main">
            <div class="deck-panel-header">Playback Settings</div>
            <ul class="deck-list">
              <li class="deck-item" onclick="slideDeckToPanel('quality')">
                <div class="deck-item-left">
                  <svg viewBox="0 0 24 24">
                    <path
                      d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H7c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.04-.42 1.99-1.07 2.75z" />
                  </svg>
                  <span>Quality</span>
                </div>
                <div class="deck-item-indicator">
                  <span id="label-active-quality">Auto</span>
                  <svg viewBox="0 0 24 24">
                    <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                  </svg>
                </div>
              </li>

              <li class="deck-item" onclick="slideDeckToPanel('speed')">
                <div class="deck-item-left">
                  <svg viewBox="0 0 24 24">
                    <path
                      d="M20.38 8.57l-1.23 1.85a8 8 0 0 1-.22 7.58H5.07A8 8 0 0 1 15.58 6.85l1.85-1.23A10 10 0 0 0 3.35 15a10 10 0 0 0 17.03 3.57 10 10 0 0 0 .2-10l-.2-.2zM12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm1-5h-2v4h2V5z" />
                  </svg>
                  <span>Playback Speed</span>
                </div>
                <div class="deck-item-indicator">
                  <span id="label-active-speed">1.0x</span>
                  <svg viewBox="0 0 24 24">
                    <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                  </svg>
                </div>
              </li>

              <li class="deck-item" id="menu-item-audio" onclick="slideDeckToPanel('audio')">
                <div class="deck-item-left">
                  <svg viewBox="0 0 24 24">
                    <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
                  </svg>
                  <span>Audio Tracks</span>
                </div>
                <div class="deck-item-indicator">
                  <span id="label-active-audio">Primary</span>
                  <svg class="audio-chevron-icon" viewBox="0 0 24 24">
                    <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                  </svg>
                </div>
              </li>

              <li class="deck-item" id="menu-item-subtitles">
                <div class="deck-item-left">
                  <svg viewBox="0 0 24 24">
                    <path
                      d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-4H6V8h12v4z" />
                  </svg>
                  <span>Subtitles</span>
                </div>
                <div class="deck-item-indicator">
                  <span id="label-active-sub">Off</span>
                  <svg class="sub-chevron-icon" viewBox="0 0 24 24">
                    <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                  </svg>
                </div>
              </li>
            </ul>
          </div>

          <!-- Panel 2: Sliding secondary options card -->
          <div class="deck-panel" id="deck-panel-details">
            <div class="deck-panel-header">
              <button class="deck-back-btn" onclick="slideDeckBack()">
                <svg viewBox="0 0 24 24">
                  <path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
                </svg>
                <span>Back</span>
              </button>
              <span id="details-panel-title">Options</span>
            </div>
            <ul class="deck-list" id="details-panel-list">
              <!-- Dynamically populated options list -->
            </ul>
          </div>

        </div>
      </div>

      <!-- Closed Caption (CC) translating script popups (gorgeous glass overlay right above the CC button) -->
      <div class="glass-cc-popup" id="cc-popup-panel">
        <div class="cc-popup-header">Subtitles Translations</div>
        <ul class="deck-list" id="cc-popup-list">
          <!-- Dynamically populated translating subtitles -->
        </ul>
      </div>

      <!-- Bespoke Audio Tracks popup (gorgeous glass overlay right above the headphones button) -->
      <div class="glass-audio-popup" id="audio-popup-panel">
        <div class="audio-popup-header">Audio Languages</div>
        <ul class="deck-list" id="audio-popup-list">
          <!-- Dynamically populated audio languages list -->
        </ul>
      </div>

    <?php endif; // USE_CUSTOM_CONTROLS ?>

  </div>

  <!-- Core Video.js Engine (includes qualityLevels + VHS built-in) -->
  <script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
  <!-- Video.js Contrib Ads Engine (jsdelivr — cdnjs v6.9.0 does not exist) -->
  <script src="https://cdn.jsdelivr.net/npm/videojs-contrib-ads@6.6.4/dist/videojs-contrib-ads.min.js"></script>
  <!-- Google IMA SDK Framework required for VAST/VPAID parsing -->
  <script src="https://imasdk.googleapis.com/js/sdkloader/ima3.js"></script>
  <!-- Video.js IMA plugin linkage -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/videojs-ima/1.11.0/videojs.ima.js"></script>
  <!-- NOTE: videojs-contrib-quality-levels is bundled inside Video.js 8.x -->

  <script>
    // Dynamic PHP-to-JS configurations bridge
    window.PLAYER_CONFIG = {
      hlsPlaylistUrl: <?php 
        $isLocalDev = (PHP_SAPI === 'cli-server' || ($_SERVER['SERVER_PORT'] ?? '') === '8080' || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
        $gatewayUrl = $isLocalDev ? 'b2_gateway.php' : 'b2_gateway';
        echo json_encode("{$gatewayUrl}/{$streamId}/master.m3u8"); 
      ?>,
      useCustomControls: <?php echo USE_CUSTOM_CONTROLS ? 'true' : 'false'; ?>,
      availableResolutions: <?php
      $raw = $stream['resolutions_selected'] ?? '[]';
      $decoded = json_decode($raw, true);
      echo json_encode(is_array($decoded) ? $decoded : []);
      ?>,
      activeAdCampaigns: <?php echo json_encode($ads); ?>
    };
  </script>
  <script src="assets/js/player.js"></script>
</body>

</html>