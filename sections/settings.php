<?php
declare(strict_types=1);
// Assumes $settings is loaded by layout.php before inclusion
?>
<!-- TAB 2: TRANSCODING & AUDIO SETTINGS -->
<section id="tab-settings" class="tab-content active flex flex-col gap-8">
  <form id="transcoding-settings-form" onsubmit="saveTranscodingSettings(event)" class="flex flex-col gap-8">
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      
      <!-- General Video Settings card -->
      <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6">
        <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
          <i class="bi bi-film text-brand-orange text-lg"></i>
          <h3 class="font-display font-bold text-lg text-white">General Video Settings</h3>
        </div>
        
        <div class="grid grid-cols-2 gap-6">
          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Video Codec</label>
            <select name="video_codec" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
              <option value="h264" <?php echo $settings['video_codec'] === 'h264' ? 'selected' : ''; ?>>h264 (libx264 - Default)</option>
              <option value="hevc" <?php echo $settings['video_codec'] === 'hevc' ? 'selected' : ''; ?>>h265 (libx265 - HEVC)</option>
              <option value="copy" <?php echo $settings['video_codec'] === 'copy' ? 'selected' : ''; ?>>Direct stream copy</option>
            </select>
          </div>

          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Keyframe Interval (GOP)</label>
            <input type="number" name="keyframe" value="<?php echo htmlspecialchars((string)$settings['keyframe']); ?>" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 60">
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Bitrate Ratio</label>
            <input type="text" name="bitrate_ratio" value="<?php echo htmlspecialchars((string)$settings['bitrate_ratio']); ?>" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 1.07">
          </div>

          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Buffer Ratio</label>
            <input type="text" name="buffer_ratio" value="<?php echo htmlspecialchars((string)$settings['buffer_ratio']); ?>" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 1.5">
          </div>

          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Hls segment time</label>
            <input type="number" name="hls_time" value="<?php echo htmlspecialchars((string)$settings['hls_time']); ?>" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 6">
          </div>
        </div>
        
        <div class="text-[11px] text-slate-500 font-mono bg-slate-950/40 p-3 rounded-lg border border-slate-800">
          Formula: Maxrate = Bitrate &times; Bitrate ratio | Bufsize = Bitrate &times; Buffer ratio
        </div>

        <div class="grid grid-cols-2 gap-6">
          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">FFmpeg Preset</label>
            <select name="ffmpeg_preset" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
              <option value="ultrafast" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'ultrafast' ? 'selected' : ''; ?>>ultrafast (Fastest transcode, larger files)</option>
              <option value="superfast" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'superfast' ? 'selected' : ''; ?>>superfast</option>
              <option value="veryfast" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'veryfast' ? 'selected' : ''; ?>>veryfast (Recommended default)</option>
              <option value="faster" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'faster' ? 'selected' : ''; ?>>faster</option>
              <option value="fast" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'fast' ? 'selected' : ''; ?>>fast</option>
              <option value="medium" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'medium' ? 'selected' : ''; ?>>medium (Standard balancing)</option>
              <option value="slow" <?php echo ($settings['ffmpeg_preset'] ?? 'veryfast') === 'slow' ? 'selected' : ''; ?>>slow (Best quality/compression)</option>
            </select>
          </div>

          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">FFmpeg Threads</label>
            <select name="ffmpeg_threads" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
              <option value="0" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '0' ? 'selected' : ''; ?>>0 (Auto - Use all CPU cores)</option>
              <option value="1" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '1' ? 'selected' : ''; ?>>1 (Single-threaded)</option>
              <option value="2" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '2' ? 'selected' : ''; ?>>2 Threads</option>
              <option value="4" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '4' ? 'selected' : ''; ?>>4 Threads</option>
              <option value="8" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '8' ? 'selected' : ''; ?>>8 Threads</option>
              <option value="12" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '12' ? 'selected' : ''; ?>>12 Threads</option>
              <option value="16" <?php echo ($settings['ffmpeg_threads'] ?? '0') === '16' ? 'selected' : ''; ?>>16 Threads</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Add Top Quality Switch Toggle -->
          <div class="flex items-center justify-between p-4 rounded-xl bg-slate-900 bg-opacity-40 border border-slate-800">
            <div>
              <h4 class="font-display font-semibold text-sm text-white">Add top quality</h4>
              <p class="text-xs text-slate-500 mt-1">Add source quality to ladder if it exceeds specs.</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="add_top_quality" value="1" <?php echo $settings['add_top_quality'] === '1' ? 'checked' : ''; ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-focus:ring-2 peer-focus:ring-brand-orange peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-orange"></div>
            </label>
          </div>

          <!-- Parallel Transcoding Toggle -->
          <div class="flex items-center justify-between p-4 rounded-xl bg-slate-900 bg-opacity-40 border border-slate-800">
            <div>
              <h4 class="font-display font-semibold text-sm text-white">Parallel Transcoding</h4>
              <p class="text-xs text-slate-500 mt-1">Transcode selected resolutions concurrently.</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="parallel_transcode" value="1" <?php echo ($settings['parallel_transcode'] ?? '0') === '1' ? 'checked' : ''; ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-focus:ring-2 peer-focus:ring-brand-orange peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-orange"></div>
            </label>
          </div>
        </div>
      </div>

      <!-- General Audio Settings card -->
      <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6">
        <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
          <i class="bi bi-volume-up text-brand-orange text-lg"></i>
          <h3 class="font-display font-bold text-lg text-white">Audio Settings</h3>
        </div>
        
        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Audio Codec</label>
          <select name="audio_codec" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
            <option value="aac" <?php echo $settings['audio_codec'] === 'aac' ? 'selected' : ''; ?>>AAC (Advanced Audio Coding - Recommended)</option>
            <option value="mp3" <?php echo $settings['audio_codec'] === 'mp3' ? 'selected' : ''; ?>>MP3 (MPEG Layer 3)</option>
            <option value="copy" <?php echo $settings['audio_codec'] === 'copy' ? 'selected' : ''; ?>>Direct stream copy</option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-6">
          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Default Audio Bitrate</label>
            <select name="audio_bitrate" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors">
              <option value="96k" <?php echo $settings['audio_bitrate'] === '96k' ? 'selected' : ''; ?>>96k (Standard)</option>
              <option value="128k" <?php echo $settings['audio_bitrate'] === '128k' ? 'selected' : ''; ?>>128k (HD Stereo - Standard)</option>
              <option value="192k" <?php echo $settings['audio_bitrate'] === '192k' ? 'selected' : ''; ?>>192k (Hi-Fi HD Stereo)</option>
            </select>
          </div>

          <div class="flex flex-col gap-2">
            <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Audio Channel Layout</label>
            <select name="audio_channels" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
              <option value="stereo" <?php echo $settings['audio_channels'] === 'stereo' ? 'selected' : ''; ?>>Stereo Layout (2 Channel)</option>
              <option value="mono" <?php echo $settings['audio_channels'] === 'mono' ? 'selected' : ''; ?>>Mono Layout (1 Channel)</option>
            </select>
          </div>
        </div>
        
        <div class="p-4 rounded-xl bg-slate-950 bg-opacity-30 border border-slate-800 text-xs text-slate-500 leading-relaxed">
          <p><strong>Note:</strong> Alternative audio tracks embedded inside the source container are automatically transcoded to side-car AAC formats, creating multi-track language selector HLS manifests in the bespoke player.</p>
        </div>
      </div>
    </div>

    <!-- Backblaze B2 Cloud Storage Settings Card -->
    <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6">
      <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
        <i class="bi bi-cloud-arrow-up text-brand-orange text-lg"></i>
        <h3 class="font-display font-bold text-lg text-white">Backblaze B2 Cloud Storage Settings</h3>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Key ID</label>
          <input type="text" name="b2_key_id" value="<?php echo htmlspecialchars((string)($settings['b2_key_id'] ?? '')); ?>" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 004xxxxxxxxxxxx0000000001">
        </div>

        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Application Key</label>
          <input type="password" name="b2_application_key" value="<?php echo htmlspecialchars((string)($settings['b2_application_key'] ?? '')); ?>" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. K004xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
        </div>

        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Bucket ID</label>
          <input type="text" name="b2_bucket_id" value="<?php echo htmlspecialchars((string)($settings['b2_bucket_id'] ?? '')); ?>" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. xxxxxxxxxxxxxxxxxxxxxxxx">
        </div>

        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Bucket Name</label>
          <input type="text" name="b2_bucket_name" value="<?php echo htmlspecialchars((string)($settings['b2_bucket_name'] ?? '')); ?>" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. my-hls-bucket">
        </div>
      </div>
      
      <div class="p-4 rounded-xl bg-slate-950 bg-opacity-30 border border-slate-800 text-xs text-slate-500 leading-relaxed">
        <p><strong>Note:</strong> If credentials are left blank, HLS playlists and segments will remain on the local disk. When configured, transcoding processes will render the lowest quality first, upload progressively to B2, update stream metadata for immediate playback, and purge local temporary files to preserve server storage.</p>
      </div>
    </div>

    <!-- Dynamic Renditions Ladder Card -->
    <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6">
      <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <i class="bi bi-grid-3x3-gap text-brand-orange text-lg"></i>
          <h3 class="font-display font-bold text-lg text-white">Rendition Resolution Ladder</h3>
        </div>
        <button type="button" onclick="addNewRenditionRow()" class="px-3.5 py-1.5 rounded-lg bg-brand-orange bg-opacity-10 hover:bg-brand-orange/20 text-brand-orange text-xs font-semibold flex items-center gap-1.5 transition-colors border border-brand-orange/20">
          <i class="bi bi-plus-lg"></i> Add Custom Profile
        </button>
      </div>

      <div class="overflow-x-auto custom-scrollbar">
        <table class="w-full min-w-[700px] text-left border-collapse text-xs">
          <thead>
            <tr class="bg-slate-950/40 text-slate-400 font-semibold font-display text-xs tracking-wider border-b border-slate-800">
              <th class="py-3.5 px-4">Label</th>
              <th class="py-3.5 px-4">Width</th>
              <th class="py-3.5 px-4">Height</th>
              <th class="py-3.5 px-4">CRF Constant Quality</th>
              <th class="py-3.5 px-4">Video Max Bitrate</th>
              <th class="py-3.5 px-4">Audio Bitrate</th>
              <th class="py-3.5 px-4 text-right">Delete</th>
            </tr>
          </thead>
          <tbody id="renditions-ladder-body" class="divide-y divide-slate-800/40 text-slate-300 font-medium">
            <?php foreach ($settings['renditions'] as $resLabel => $conf): ?>
              <tr class="rendition-row" data-label="<?php echo htmlspecialchars($resLabel); ?>">
                <td class="py-3 px-4 font-bold text-white"><?php echo htmlspecialchars($resLabel); ?></td>
                <td class="py-3 px-4">
                  <input type="number" data-key="width" value="<?php echo (int)$conf['width']; ?>" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
                </td>
                <td class="py-3 px-4">
                  <input type="number" data-key="height" value="<?php echo (int)$conf['height']; ?>" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
                </td>
                <td class="py-3 px-4">
                  <input type="number" data-key="crf" value="<?php echo (int)$conf['crf']; ?>" required class="w-16 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
                </td>
                <td class="py-3 px-4">
                  <input type="text" data-key="vbitrate" value="<?php echo htmlspecialchars($conf['vbitrate']); ?>" required class="w-24 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
                </td>
                <td class="py-3 px-4">
                  <input type="text" data-key="abitrate" value="<?php echo htmlspecialchars($conf['abitrate']); ?>" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
                </td>
                <td class="py-3 px-4 text-right">
                  <button type="button" onclick="removeRenditionRow(this)" class="p-1.5 text-slate-500 hover:text-rose-500 transition-colors">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div>
      <button type="submit" class="w-full bg-brand-orange hover:bg-orange-600 active:scale-[0.99] font-display font-extrabold text-sm uppercase tracking-wider py-4 rounded-xl text-white shadow-lg shadow-brand-orange/20 transition-all duration-150 flex items-center justify-center gap-2">
        <i class="bi bi-cloud-check text-lg"></i> Save Server Transcoding Settings
      </button>
    </div>

  </form>

  <!-- Security Console card (Change Password) -->
  <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6 mt-8">
    <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
      <i class="bi bi-shield-check text-brand-orange text-lg"></i>
      <h3 class="font-display font-bold text-lg text-white">Administrative Security Console</h3>
    </div>

    <form id="change-password-form" onsubmit="changePassword(event)" class="flex flex-col gap-5">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Current Password</label>
          <input type="password" name="current_password" required autocomplete="current-password"
                 class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors"
                 placeholder="••••••••">
        </div>
        
        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">New Password</label>
          <input type="password" name="new_password" required autocomplete="new-password"
                 class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors"
                 placeholder="Min. 6 characters">
        </div>

        <div class="flex flex-col gap-2">
          <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Confirm New Password</label>
          <input type="password" name="confirm_password" required autocomplete="new-password"
                 class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors"
                 placeholder="Confirm new password">
        </div>
      </div>

      <button type="submit" 
              class="w-full bg-slate-800 hover:bg-slate-700 active:scale-[0.99] font-display font-extrabold text-sm uppercase tracking-wider py-4 rounded-xl text-white border border-slate-700/60 transition-all duration-150 flex items-center justify-center gap-2">
        <i class="bi bi-key-fill text-lg"></i> Update Security Credentials
      </button>
    </form>
  </div>
</section>
