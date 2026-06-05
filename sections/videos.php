<?php
declare(strict_types=1);
?>
<!-- TAB 1: VIDEOS REGISTRY TABLE -->
<section id="tab-videos" class="tab-content active flex flex-col gap-6">
  <div class="w-full glassmorphic rounded-2xl overflow-hidden shadow-2xl">
    <div class="px-6 py-5 border-b border-slate-800 bg-slate-900 bg-opacity-35 flex items-center justify-between">
      <h3 class="font-display font-bold text-lg text-white">Stream Catalog Directory</h3>
      <button onclick="refreshStreamsTable()" class="px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 transition-colors text-slate-300 hover:text-white text-xs font-semibold flex items-center gap-2 border border-slate-700/60">
        <i class="bi bi-arrow-clockwise"></i> Refresh Catalog
      </button>
    </div>

    <!-- Catalog responsive Table -->
    <div class="overflow-x-auto custom-scrollbar">
      <table class="w-full min-w-[900px] text-left border-collapse text-sm">
        <thead>
          <tr class="bg-slate-950/40 text-slate-400 font-semibold font-display text-xs tracking-wider border-b border-slate-800">
            <th class="py-4 px-6">Stream Title (Double-click to inline edit)</th>
            <th class="py-4 px-6">System ID / Filename</th>
            <th class="py-4 px-6">Telemetry Status</th>
            <th class="py-4 px-6">Codec & Duration</th>
            <th class="py-4 px-6">Active Resolutions</th>
            <th class="py-4 px-6 text-right">Actions</th>
          </tr>
        </thead>
        <tbody id="streams-table-body" class="divide-y divide-slate-800/40 text-slate-300 font-medium">
          <tr>
            <td colspan="6" class="py-12 text-center text-slate-500 flex flex-col items-center justify-center gap-3">
              <div class="w-8 h-8 rounded-full border-2 border-brand-orange border-t-transparent animate-spin"></div>
              <span class="font-mono text-xs">Querying database streams registry...</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
