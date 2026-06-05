<?php
declare(strict_types=1);
?>
<!-- TAB 4: VAST AD SCHEDULER -->
<section id="tab-ads" class="tab-content active flex flex-col gap-8">
  
  <!-- New Ad Insertion Form -->
  <div class="glassmorphic rounded-2xl p-6 shadow-xl flex flex-col gap-6">
    <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
      <i class="bi bi-megaphone text-brand-orange text-lg"></i>
      <h3 class="font-display font-bold text-lg text-white">Create VAST Ad Campaign</h3>
    </div>

    <form id="ad-campaign-form" onsubmit="createAdCampaign(event)" class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div class="flex flex-col gap-2">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Campaign Display Name</label>
        <input type="text" name="name" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. Midroll 15 Seconds Promo">
      </div>

      <div class="flex flex-col gap-2">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Ad Position Offset Target</label>
        <select id="ad-offset-type" name="offset_type" onchange="adjustOffsetValueInputBehavior()" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange transition-colors">
          <option value="preroll">Preroll (At Video Commencement)</option>
          <option value="midroll">Midroll (Inside Playback Timeline)</option>
          <option value="postroll">Postroll (Upon Video Conclusion)</option>
        </select>
      </div>

      <div class="flex flex-col gap-2" id="ad-offset-value-group">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">Offset Value Parameter</label>
        <input type="text" id="ad-offset-value" name="offset_value" class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="e.g. 10 (seconds), 25% (percentage), or 00:00:15:000">
      </div>

      <div class="flex flex-col gap-2">
        <label class="text-xs text-slate-400 font-bold uppercase tracking-wider">VAST XML Response endpoint URL</label>
        <input type="url" name="vast_url" required class="w-full bg-slate-900 border border-slate-700/80 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-brand-orange transition-colors" placeholder="https://pubads.g.doubleclick.net/gampad/ads?...">
      </div>

      <button type="submit" class="md:col-span-2 bg-brand-orange hover:bg-orange-600 active:scale-[0.99] font-display font-extrabold text-sm uppercase tracking-wider py-4 rounded-xl text-white shadow-lg shadow-brand-orange/20 transition-all duration-150 flex items-center justify-center gap-2">
        <i class="bi bi-megaphone text-lg"></i> Create Campaign schedule
      </button>
    </form>
  </div>

  <!-- VAST Active directory list -->
  <div class="glassmorphic rounded-2xl overflow-hidden shadow-xl">
    <div class="px-6 py-5 border-b border-slate-800 bg-slate-900 bg-opacity-35">
      <h3 class="font-display font-bold text-lg text-white">Active Ad Scheduler Registry</h3>
    </div>

    <div class="overflow-x-auto custom-scrollbar">
      <table class="w-full min-w-[700px] text-left border-collapse text-sm">
        <thead>
          <tr class="bg-slate-950/40 text-slate-400 font-semibold font-display text-xs tracking-wider border-b border-slate-800">
            <th class="py-3.5 px-6">Campaign Name</th>
            <th class="py-3.5 px-6">Position type</th>
            <th class="py-3.5 px-6">Offset Trigger</th>
            <th class="py-3.5 px-6">VAST Response endpoint</th>
            <th class="py-3.5 px-6 text-right">Action</th>
          </tr>
          </thead>
          <tbody id="ads-table-body" class="divide-y divide-slate-800/40 text-slate-300 font-medium">
            <tr>
              <td colspan="5" class="py-8 text-center text-slate-500">
                <span class="font-mono text-xs">Scanning ad directory...</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
