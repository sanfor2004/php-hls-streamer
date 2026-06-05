<?php
declare(strict_types=1);
?>
<!-- TAB 3: VIDEOS INGESTION CONSOLE -->
<section id="tab-upload" class="tab-content active flex flex-col gap-8">
  <div class="glassmorphic rounded-2xl p-8 shadow-xl flex flex-col gap-6">
    
    <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
      <i class="bi bi-cloud-arrow-up text-brand-orange text-lg"></i>
      <h3 class="font-display font-bold text-lg text-white">Ingest Raw Video Stream</h3>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="flex flex-col gap-2">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Stream Appear Title (Custom Name)</label>
        <input type="text" id="stream-display-title" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. Big Buck Bunny Movie Stream">
      </div>
      <div class="flex flex-col gap-2">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Add Subtitle File (Optional, .vtt or .srt)</label>
        <input type="file" id="subtitle-file" accept=".vtt,.srt" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-1.5 text-sm text-slate-300 focus:outline-none focus:border-brand-orange transition-colors">
      </div>
    </div>

    <!-- Rendition Selectors for this Upload -->
    <div class="flex flex-col gap-3">
      <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Target Resolutions to Transcode</label>
      <div id="upload-resolutions-container" class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <!-- Populated via JavaScript dynamically on boot based on settings -->
      </div>
    </div>

    <!-- Drag & Drop container -->
    <div id="upload-dropzone" class="border-2 border-dashed border-slate-800 hover:border-brand-orange/60 bg-slate-950/40 rounded-2xl p-12 flex flex-col items-center justify-center gap-4 transition-all duration-200 cursor-pointer">
      <div class="w-16 h-16 rounded-full bg-slate-900 flex items-center justify-center border border-slate-800 text-slate-400 transition-transform duration-300 hover:scale-110">
        <i class="bi bi-cloud-arrow-up text-3xl"></i>
      </div>
      <div class="text-center">
        <h4 id="drag-zone-text" class="font-display font-bold text-white text-base">Drag and drop your raw video file here</h4>
        <p id="drag-zone-sub" class="text-xs text-slate-500 mt-1">Supports MP4, MKV, TS, AVI, MOV up to multi-gigabytes</p>
      </div>
      <input type="file" id="file-input" class="hidden" accept=".mp4,.mkv,.ts,.avi,.mov" multiple>
    </div>

    <!-- Upload Ingestion Queue list -->
    <div id="upload-queue-container" class="hidden flex flex-col gap-3">
      <h4 class="text-xs text-slate-400 font-bold uppercase tracking-wider">Upload Ingestion Queue</h4>
      <div id="upload-queue-list" class="flex flex-col gap-2.5">
        <!-- Queue items are dynamically rendered here -->
      </div>
    </div>

    <!-- Start Upload Ingestion Trigger Button -->
    <button id="btn-start-upload" class="hidden w-full bg-brand-orange hover:bg-orange-600 active:scale-[0.99] font-display font-extrabold text-sm uppercase tracking-wider py-4 rounded-xl text-white shadow-lg shadow-brand-orange/20 transition-all duration-150 flex items-center justify-center gap-2">
      <i class="bi bi-play-circle text-lg"></i> Begin Chunked Upload Ingestion
    </button>

    <!-- Progress HUD -->
    <div id="upload-progress-container" class="hidden p-6 bg-slate-900/60 border border-slate-800 rounded-2xl shadow-xl flex flex-col gap-3">
      <div class="flex items-center justify-between text-sm">
        <span id="label-filename" class="font-semibold text-white max-w-[65%] truncate">bunny.mp4</span>
        <span id="label-percent" class="font-mono font-extrabold text-brand-orange">0%</span>
      </div>
      <div class="w-full bg-slate-950 h-2.5 rounded-full overflow-hidden">
        <div id="progress-indicator" class="h-full bg-gradient-to-r from-brand-indigo to-brand-orange w-0 transition-all duration-200 shadow-md shadow-brand-orange/30"></div>
      </div>
      <div class="flex items-center justify-between text-xs text-slate-500 font-mono">
        <span id="label-size-ratio">0.0 MB / 0.0 MB</span>
        <span id="label-upload-speed">0.0 MB/s</span>
      </div>
    </div>

  </div>
</section>
