<?php
declare(strict_types=1);

// Enforce administrative authentication for dashboard views
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
requireLogin();


/**
 * =================================================================================
 * ZENITH CONSOLE STATIC LAYOUT & SETTINGS CONTAINER (layout.php)
 * =================================================================================
 * Exposes core SQLite database connections, dynamic settings data models, HSL
 * utility styles, head dependencies (Tailwind & Bootstrap CDNs), and the fixed
 * left navigation sidebar bar template engine.
 * =================================================================================
 */

// 1. DATABASE persistent connection variables & parameters
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
$dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite';
$pdo = null;
$dbError = null;

try {
    $pdo = getDatabaseConnection();
    
    // Boot database schema safely if fresh
    if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'api.php')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// 2. Transcode settings defaults & variable registry models
$settings = [
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
    'renditions'         => [
        '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 25, 'vbitrate' => '4096k', 'abitrate' => '192k'],
        '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 26, 'vbitrate' => '2048k', 'abitrate' => '128k'],
        '540p'  => ['width' => 960,  'height' => 540,  'crf' => 27, 'vbitrate' => '1500k', 'abitrate' => '128k'],
        '480p'  => ['width' => 854,  'height' => 480,  'crf' => 28, 'vbitrate' => '750k',  'abitrate' => '128k'],
        '360p'  => ['width' => 640,  'height' => 360,  'crf' => 29, 'vbitrate' => '276k',  'abitrate' => '96k'],
    ]
];

// Fetch active SQLite settings to override static models
if ($pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT * FROM `settings`");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if ($row['key'] === 'renditions') {
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) {
                    $settings['renditions'] = $decoded;
                }
            } else {
                $settings[$row['key']] = $row['value'];
            }
        }
    } catch (PDOException $e) {
        // Fallback to static model defaults
    }
}

// -------------------------------------------------------------
// HEADER LAYOUT TEMPLATE ENGINE
// -------------------------------------------------------------
function renderLayoutHeader(?string $dbError, array $settings, string $dbFile, string $activeTab = 'videos'): void {
    global $pdo;
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Zenith Dynamic Transcoder & Streaming Console</title>
      
      <!-- Premium Fonts Setup -->
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

      <!-- Tailwind CSS Engine -->
      <script src="https://cdn.tailwindcss.com"></script>
      
      <!-- Bootstrap Icons CDN -->
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
      
      <!-- Video.js Player dependencies for modal HLS previewing -->
      <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />

      <script>
        tailwind.config = {
          darkMode: 'class',
          theme: {
            extend: {
              fontFamily: {
                display: ['Outfit', 'system-ui', 'sans-serif'],
                body: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'monospace'],
              },
              colors: {
                brand: {
                  orange: '#f97316',
                  indigo: '#6366f1',
                  dark: '#030712',
                  surface: '#0f172a',
                  accent: '#f97316'
                }
              }
            }
          }
        }
      </script>
      
      <script>
        window.ACTIVE_TAB = "<?php echo htmlspecialchars($activeTab); ?>";
      </script>
      
      <style>
        body {
          font-family: 'Plus Jakarta Sans', sans-serif;
          background-color: #030712;
          color: #f3f4f6;
        }
        
        .mesh-glow-1 {
          position: fixed;
          top: -300px;
          right: -300px;
          width: 900px;
          height: 900px;
          background: radial-gradient(circle, rgba(249, 115, 22, 0.05) 0%, rgba(249, 115, 22, 0) 70%);
          pointer-events: none;
          z-index: 0;
          filter: blur(120px);
        }

        .mesh-glow-2 {
          position: fixed;
          bottom: -300px;
          left: -300px;
          width: 800px;
          height: 800px;
          background: radial-gradient(circle, rgba(99, 102, 241, 0.04) 0%, rgba(99, 102, 241, 0) 70%);
          pointer-events: none;
          z-index: 0;
          filter: blur(100px);
        }
        
        .glassmorphic {
          background: rgba(15, 23, 42, 0.65);
          backdrop-filter: blur(20px) saturate(180%);
          -webkit-backdrop-filter: blur(20px) saturate(180%);
          border: 1px solid rgba(255, 255, 255, 0.06);
        }
        
        .glassmorphic:hover {
          border-color: rgba(249, 115, 22, 0.2);
        }

        .tab-content {
          display: none;
          opacity: 0;
          transform: translateY(10px);
          transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .tab-content.active {
          display: block;
          opacity: 1;
          transform: translateY(0);
        }
        
        .drag-active {
          border-color: #f97316 !important;
          background: rgba(249, 115, 22, 0.06) !important;
          box-shadow: 0 0 20px rgba(249, 115, 22, 0.15);
        }
        
        .custom-scrollbar::-webkit-scrollbar {
          width: 6px;
          height: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
          background: transparent;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
          background: rgba(255, 255, 255, 0.1);
          border-radius: 4px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
          background: rgba(249, 115, 22, 0.3);
        }
      </style>
    </head>
    <body class="overflow-x-hidden min-h-screen relative flex">
      
      <div class="mesh-glow-1"></div>
      <div class="mesh-glow-2"></div>
      
      <!-- Toast Notification Overlay -->
      <div id="toast" class="fixed top-6 right-6 z-50 transform translate-y-[-20px] opacity-0 pointer-events-none transition-all duration-300 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl glassmorphic max-w-sm">
        <i id="toast-icon" class="bi text-brand-orange text-lg"></i>
        <div>
          <p id="toast-msg" class="text-sm font-semibold text-white"></p>
        </div>
      </div>

      <!-- Sidebar Navigation Console (Nav Bar) -->
      <aside class="w-72 min-h-screen bg-brand-dark bg-opacity-70 border-r border-slate-800 backdrop-blur-xl flex flex-col justify-between p-6 z-30 fixed left-0 top-0 h-full">
        <div class="flex flex-col gap-8">
          <!-- Brand Logo Header -->
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-orange to-brand-indigo flex items-center justify-center shadow-lg shadow-brand-orange/20 animate-pulse">
              <i class="bi bi-cpu text-white text-xl"></i>
            </div>
            <div>
              <h2 class="font-display font-extrabold text-lg text-transparent bg-clip-text bg-gradient-to-r from-white to-slate-400 leading-none">STREAM ENGINE</h2>
              <span class="text-[10px] text-brand-orange font-mono font-bold uppercase tracking-wider">Zenith Admin Console</span>
            </div>
          </div>

          <!-- Navigation tabs buttons links -->
          <nav class="flex flex-col gap-2">
            <?php
            $tabsDef = [
                'videos' => ['label' => 'Videos Registry', 'icon' => 'bi-collection-play', 'file' => 'index'],
                'settings' => ['label' => 'System Settings', 'icon' => 'bi-sliders', 'file' => 'settings'],
                'upload' => ['label' => 'Ingestion Portal', 'icon' => 'bi-cloud-arrow-up', 'file' => 'upload'],
                'ads' => ['label' => 'VAST Ad Scheduler', 'icon' => 'bi-megaphone', 'file' => 'ads']
            ];
            foreach ($tabsDef as $tabKey => $tabInfo):
                $isActive = ($activeTab === $tabKey);
                $btnClass = $isActive 
                    ? "bg-brand-orange bg-opacity-10 text-brand-orange border border-brand-orange/20" 
                    : "text-slate-400 hover:text-white hover:bg-slate-800/40 border border-transparent";
                $arrowOpacity = $isActive ? "opacity-60" : "opacity-40";
            ?>
            <a href="<?php echo $tabInfo['file']; ?>" id="nav-<?php echo $tabKey; ?>" class="group flex items-center justify-between px-4 py-3 rounded-xl transition-all duration-200 <?php echo $btnClass; ?>">
              <div class="flex items-center gap-3 font-display font-semibold text-sm">
                <i class="bi <?php echo $tabInfo['icon']; ?> text-lg transition-transform group-hover:scale-110"></i>
                <span><?php echo $tabInfo['label']; ?></span>
              </div>
              <i class="bi bi-chevron-right text-xs <?php echo $arrowOpacity; ?>"></i>
            </a>
            <?php endforeach; ?>
          </nav>
        </div>

        <!-- Authenticated User & DB Status Footer Badges -->
        <div class="flex flex-col gap-3">
          <!-- Active User Badge -->
          <div class="flex items-center gap-3 p-3 rounded-2xl bg-slate-900/40 border border-slate-800/80">
            <div class="w-8 h-8 rounded-full bg-brand-orange/15 border border-brand-orange/30 flex items-center justify-center text-brand-orange text-sm font-bold">
              <i class="bi bi-person-fill"></i>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-xs font-semibold text-white truncate"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></p>
              <span class="text-[9px] text-brand-orange font-mono font-bold uppercase tracking-wider">Authenticated</span>
            </div>
            <a href="logout" class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-rose-950/20 hover:text-rose-500 border border-slate-700/60 flex items-center justify-center text-slate-400 transition-colors" title="Logout Session">
              <i class="bi bi-box-arrow-right text-xs"></i>
            </a>
          </div>

          <!-- DB Status Footer Badge -->
          <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col gap-2">
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-400">Database Engine</span>
              <div class="flex items-center gap-1">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-md shadow-emerald-500/20"></span>
                <span class="text-[10px] text-emerald-500 font-bold font-mono">ONLINE</span>
              </div>
            </div>
            <?php
              $driverName = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'offline';
              $dbDesc = $driverName === 'mysql' ? 'MySQL: userpleyer_top' : 'SQLite: database.sqlite';
            ?>
            <p class="text-[11px] text-slate-500 font-mono"><?php echo htmlspecialchars($dbDesc); ?></p>
          </div>
        </div>
      </aside>

      <!-- Main Content Chassis -->
      <main class="flex-1 min-h-screen ml-72 p-10 z-10 relative">
        
        <!-- Top System Telemetry Stats Header -->
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
          <div>
            <h1 class="font-display font-extrabold text-3xl tracking-tight text-white flex items-center gap-3">
              Dashboard Overview
              <span id="active-worker-indicator" class="hidden flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-brand-orange bg-opacity-10 border border-brand-orange/20 text-brand-orange text-xs font-mono font-bold uppercase animate-pulse">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-orange"></span> Transcoding...
              </span>
            </h1>
            <p class="text-slate-400 text-sm mt-1">High-fidelity administration pipeline for automated HLS video multi-bitrate streams.</p>
          </div>

          <!-- Quick Metrics Grid -->
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 w-full md:w-auto min-w-[500px]">
            <div class="p-4 rounded-xl glassmorphic flex flex-col justify-between">
              <span class="text-xs text-slate-400 font-semibold font-display">Streams</span>
              <span id="metric-streams-count" class="text-2xl font-bold font-display text-white mt-1">0</span>
            </div>
            <div class="p-4 rounded-xl glassmorphic flex flex-col justify-between">
              <span class="text-xs text-slate-400 font-semibold font-display">Transcoding</span>
              <span id="metric-transcoding-count" class="text-2xl font-bold font-display text-white mt-1">0</span>
            </div>
            <div class="p-4 rounded-xl glassmorphic flex flex-col justify-between">
              <span class="text-xs text-slate-400 font-semibold font-display">Ad Campaigns</span>
              <span id="metric-ads-count" class="text-2xl font-bold font-display text-white mt-1">0</span>
            </div>
            <div class="p-4 rounded-xl glassmorphic flex flex-col justify-between">
              <span class="text-xs text-slate-400 font-semibold font-display">DB Size</span>
              <span class="text-md font-bold font-display text-white mt-2 font-mono">
                <?php echo $pdo ? getDatabaseSize($pdo) : '0 KB'; ?>
              </span>
            </div>
          </div>
        </header>
    <?php
}

// -------------------------------------------------------------
// FOOTER LAYOUT TEMPLATE ENGINE
// -------------------------------------------------------------
function renderLayoutFooter(): void {
    ?>
      </main>

      <!-- Bespoke Glassmorphic Stream Player Preview Modal -->
      <div id="preview-modal" class="fixed inset-0 z-50 bg-slate-950/90 backdrop-blur-2xl flex items-center justify-center p-6 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-brand-dark border border-slate-800/80 rounded-3xl w-full max-w-4xl shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300" id="preview-modal-card">
          
          <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-900/40">
            <div class="flex items-center gap-2">
              <i class="bi bi-collection-play text-brand-orange text-lg"></i>
              <span id="preview-modal-title" class="font-display font-bold text-white text-base truncate max-w-[500px]">Video Stream Preview</span>
            </div>
            <button onclick="closePreviewModal()" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700/80 flex items-center justify-center text-slate-400 hover:text-white transition-colors">
              <i class="bi bi-x-lg text-sm"></i>
            </button>
          </div>

          <div class="bg-black aspect-video flex items-center justify-center">
            <iframe id="preview-iframe" src="" allowfullscreen class="w-full h-full border-none"></iframe>
          </div>
        </div>
      </div>

      <!-- JavaScript Modules links -->
      <script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
      <script src="assets/js/dashboard.js"></script>
    </body>
    </html>
    <?php
}
