<?php
declare(strict_types=1);

/**
 * =================================================================================
 * ZENITH CONSOLE ADMINISTRATIVE PORTAL LOGIN (login.php)
 * =================================================================================
 * Secure credential validation gateway utilizing standard SQLite records and BCRYPT.
 * Renders a bespoke cinematic glassmorphic interface built on vanilla CSS.
 * =================================================================================
 */

// Include security session guard to verify if user is already logged in
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

// Redirect to dashboard index if session is already active
if (isLoggedIn()) {
    header('Location: ' . getBaseUrl() . '/index');
    exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
$errorMsg = null;
$logoutMsg = isset($_GET['logout']) && $_GET['logout'] === '1' ? 'Logged out successfully.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $errorMsg = 'All credentials fields are required.';
    } else {
        try {
            $pdo = getDatabaseConnection();

            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Generate new session ID to mitigate session fixation attacks
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                header('Location: ' . getBaseUrl() . '/index');
                exit;
            } else {
                $errorMsg = 'Incorrect username or password.';
            }
        } catch (PDOException $e) {
            $errorMsg = 'Database connection error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Zenith Stream Engine - Portal Login</title>
  <link rel="icon" type="image/png" href="favicon.png">

  <!-- Premium Fonts Setup -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
  
  <!-- Bootstrap Icons CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  
  <!-- Tailwind CSS Engine -->
  <script src="https://cdn.tailwindcss.com"></script>

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

  <style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: #030712;
      color: #f3f4f6;
    }

    .mesh-glow-1 {
      position: fixed;
      top: -200px;
      right: -200px;
      width: 700px;
      height: 700px;
      background: radial-gradient(circle, rgba(249, 115, 22, 0.07) 0%, rgba(249, 115, 22, 0) 70%);
      pointer-events: none;
      z-index: 0;
      filter: blur(100px);
    }

    .mesh-glow-2 {
      position: fixed;
      bottom: -200px;
      left: -200px;
      width: 700px;
      height: 700px;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.06) 0%, rgba(99, 102, 241, 0) 70%);
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

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(15px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .animate-entrance {
      animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
  </style>
</head>
<body class="min-h-screen relative flex flex-col items-center justify-center p-6 overflow-hidden">
  
  <div class="mesh-glow-1"></div>
  <div class="mesh-glow-2"></div>

  <div class="w-full max-w-md z-10 animate-entrance">
    
    <!-- Brand Logo Header -->
    <div class="flex flex-col items-center gap-4 mb-8">
      <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-orange to-brand-indigo flex items-center justify-center shadow-2xl shadow-brand-orange/25">
        <i class="bi bi-shield-lock text-white text-3xl"></i>
      </div>
      <div class="text-center">
        <h2 class="font-display font-extrabold text-2xl tracking-tight text-white leading-none">STREAM ENGINE</h2>
        <span class="text-[10px] text-brand-orange font-mono font-bold uppercase tracking-widest mt-1 block">Administrative Access Only</span>
      </div>
    </div>

    <!-- Feedback Banners -->
    <?php if ($errorMsg !== null): ?>
      <div class="mb-6 p-4 rounded-xl border border-red-500/20 bg-red-500/10 text-red-400 text-sm flex items-start gap-3">
        <i class="bi bi-exclamation-triangle-fill text-lg leading-none mt-0.5"></i>
        <div>
          <p class="font-semibold text-white">Access Denied</p>
          <p class="text-xs mt-0.5 text-red-300"><?php echo htmlspecialchars($errorMsg); ?></p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($logoutMsg !== null): ?>
      <div class="mb-6 p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-400 text-sm flex items-start gap-3">
        <i class="bi bi-check-circle-fill text-lg leading-none mt-0.5"></i>
        <div>
          <p class="font-semibold text-white">Safe Termination</p>
          <p class="text-xs mt-0.5 text-emerald-300"><?php echo htmlspecialchars($logoutMsg); ?></p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Login credentials Card -->
    <div class="glassmorphic rounded-3xl p-8 shadow-2xl">
      <form action="login" method="POST" class="flex flex-col gap-5">
        
        <div class="flex flex-col gap-1.5">
          <label for="username" class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Username</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
              <i class="bi bi-person"></i>
            </div>
            <input type="text" name="username" id="username" required autofocus
                   class="w-full bg-slate-900 border border-slate-800 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-orange transition-all"
                   placeholder="Enter username">
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <label for="password" class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Password</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
              <i class="bi bi-key"></i>
            </div>
            <input type="password" name="password" id="password" required
                   class="w-full bg-slate-900 border border-slate-800 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-orange transition-all"
                   placeholder="Enter password">
          </div>
        </div>

        <button type="submit" 
                class="w-full bg-brand-orange hover:bg-orange-600 active:scale-[0.98] font-display font-extrabold text-sm uppercase tracking-wider py-3.5 rounded-xl text-white shadow-lg shadow-brand-orange/20 transition-all duration-150 flex items-center justify-center gap-2 mt-2">
          <i class="bi bi-box-arrow-in-right"></i> Authenticate credentials
        </button>

      </form>
    </div>

    <!-- Help Sub-Footnote -->
    <p class="text-center text-slate-500 text-[11px] font-mono mt-6">
      Powered by Zenith Engine v1.0.0
    </p>

  </div>
  
</body>
</html>
