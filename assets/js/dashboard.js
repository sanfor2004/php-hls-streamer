/**
 * ZENITH CONSOLE ADMINISTRATIVE DASHBOARD ENGINE (dashboard.js)
 * Coordinates tabs navigation, dynamic setting synchronization, chunked file upload,
 * VAST ad campaign listings, real-time stream state tracking, inline table editing,
 * and safe directory resource purging.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Global active state properties
  let activeTab = window.ACTIVE_TAB || 'videos';
  let activeRenditionsLadder = {};
  
  // -------------------------------------------------------------
  // TAB NAVIGATION MANAGER (Re-routes to physical pages)
  // -------------------------------------------------------------
  window.switchTab = function (tabId) {
    const files = {
      videos: 'index.php',
      settings: 'settings.php',
      upload: 'upload.php',
      ads: 'ads.php'
    };
    window.location.href = files[tabId] || 'index.php';
  };

  // -------------------------------------------------------------
  // TOAST ALERT HUD
  // -------------------------------------------------------------
  function showToast(message, iconClass = 'bi-check-circle', isError = false) {
    const toast = document.getElementById('toast');
    const toastIcon = document.getElementById('toast-icon');
    const toastMsg = document.getElementById('toast-msg');
    
    if (!toast || !toastIcon || !toastMsg) return;
    
    toastMsg.textContent = message;
    toastIcon.className = `bi ${iconClass} ${isError ? 'text-red-500' : 'text-brand-orange'}`;
    
    toast.classList.remove('translate-y-[-20px]', 'opacity-0', 'pointer-events-none');
    toast.classList.add('translate-y-0', 'opacity-100');
    
    setTimeout(() => {
      toast.classList.add('translate-y-[-20px]', 'opacity-0', 'pointer-events-none');
      toast.classList.remove('translate-y-0', 'opacity-100');
    }, 4000);
  }

  // -------------------------------------------------------------
  // TRANSCODING SETTINGS MANAGEMENT
  // -------------------------------------------------------------
  window.addNewRenditionRow = function () {
    const ladderBody = document.getElementById('renditions-ladder-body');
    if (!ladderBody) return;
    
    const customLabel = prompt("Enter resolution label (e.g. 240p, 1440p):");
    if (!customLabel) return;
    
    const cleanLabel = customLabel.toLowerCase().trim();
    if (document.querySelector(`tr[data-label="${cleanLabel}"]`)) {
      alert("A rendition profile with this label already exists.");
      return;
    }
    
    const tr = document.createElement('tr');
    tr.className = "rendition-row";
    tr.setAttribute('data-label', cleanLabel);
    
    tr.innerHTML = `
      <td class="py-3 px-4 font-bold text-white">${cleanLabel}</td>
      <td class="py-3 px-4">
        <input type="number" data-key="width" value="640" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
      </td>
      <td class="py-3 px-4">
        <input type="number" data-key="height" value="360" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
      </td>
      <td class="py-3 px-4">
        <input type="number" data-key="crf" value="28" required class="w-16 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
      </td>
      <td class="py-3 px-4">
        <input type="text" data-key="vbitrate" value="500k" required class="w-24 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
      </td>
      <td class="py-3 px-4">
        <input type="text" data-key="abitrate" value="96k" required class="w-20 bg-slate-900 border border-slate-800 rounded px-2.5 py-1.5 font-mono focus:outline-none focus:border-brand-orange text-white">
      </td>
      <td class="py-3 px-4 text-right">
        <button type="button" onclick="removeRenditionRow(this)" class="p-1.5 text-slate-500 hover:text-rose-500 transition-colors">
          <i class="bi bi-trash"></i>
        </button>
      </td>
    `;
    
    ladderBody.appendChild(tr);
    showToast(`Added custom ${cleanLabel} rendition profile.`, 'bi-plus-circle');
  };

  window.removeRenditionRow = function (button) {
    if (!confirm("Are you sure you want to remove this rendition profile?")) return;
    const row = button.closest('tr');
    if (row) {
      const label = row.getAttribute('data-label');
      row.remove();
      showToast(`Removed ${label} rendition profile.`, 'bi-trash');
    }
  };

  window.saveTranscodingSettings = function (event) {
    event.preventDefault();
    const form = document.getElementById('transcoding-settings-form');
    if (!form) return;
    
    const formData = new FormData(form);
    
    // Parse dynamic renditions ladder table
    const renditions = {};
    document.querySelectorAll('.rendition-row').forEach(row => {
      const label = row.getAttribute('data-label');
      if (!label) return;
      
      const width = row.querySelector('[data-key="width"]').value;
      const height = row.querySelector('[data-key="height"]').value;
      const crf = row.querySelector('[data-key="crf"]').value;
      const vbitrate = row.querySelector('[data-key="vbitrate"]').value;
      const abitrate = row.querySelector('[data-key="abitrate"]').value;
      
      renditions[label] = {
        width: parseInt(width),
        height: parseInt(height),
        crf: parseInt(crf),
        vbitrate: vbitrate,
        abitrate: abitrate
      };
    });
    
    formData.set('renditions', JSON.stringify(renditions));
    
    // Add default zero fallback for unselected top quality switch checkbox
    if (!formData.has('add_top_quality')) {
      formData.set('add_top_quality', '0');
    }
    
    fetch('api.php?action=save_settings', {
      method: 'POST',
      body: formData
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast("Transcoding parameters successfully synced.", "bi-cloud-check");
          syncUploaderResolutionsList(renditions);
        } else {
          showToast("Failed to save settings: " + data.error, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => {
        showToast("Network connection issue: " + err.message, "bi-wifi-off", true);
      });
  };

  function syncUploaderResolutionsList(renditions) {
    const container = document.getElementById('upload-resolutions-container');
    if (!container) return;
    
    container.innerHTML = '';
    activeRenditionsLadder = renditions;
    
    Object.keys(renditions).forEach((resKey) => {
      const config = renditions[resKey];
      const div = document.createElement('div');
      div.className = "p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-3 cursor-pointer hover:border-brand-orange/40 transition-colors";
      div.setAttribute('id', `res-card-${resKey}`);
      div.onclick = () => toggleUploadResolution(resKey);
      
      const checked = (resKey === '720p' || resKey === '1080p') ? 'checked' : '';
      if (checked) {
        div.classList.add('border-brand-orange', 'bg-brand-orange/5');
      }
      
      div.innerHTML = `
        <input type="checkbox" id="res-${resKey}" name="resolutions" value="${resKey}" ${checked} onclick="event.stopPropagation(); syncCheckboxStyle('${resKey}')" class="accent-brand-orange w-4 h-4 cursor-pointer">
        <div class="flex flex-col select-none leading-tight">
          <span class="text-white font-bold font-display text-sm">${resKey}</span>
          <span class="text-[10px] text-slate-500">${config.width}x${config.height}</span>
        </div>
      `;
      container.appendChild(div);
    });
  }

  function toggleUploadResolution(resKey) {
    const checkbox = document.getElementById(`res-${resKey}`);
    if (checkbox) {
      checkbox.checked = !checkbox.checked;
      syncCheckboxStyle(resKey);
    }
  }

  window.syncCheckboxStyle = function (resKey) {
    const checkbox = document.getElementById(`res-${resKey}`);
    const card = document.getElementById(`res-card-${resKey}`);
    if (checkbox && card) {
      if (checkbox.checked) {
        card.classList.add('border-brand-orange', 'bg-brand-orange/5');
      } else {
        card.classList.remove('border-brand-orange', 'bg-brand-orange/5');
      }
    }
  };

  // -------------------------------------------------------------
  // -------------------------------------------------------------
  // CHUNKED INGESTION UPLOADER PIPELINE (MULTIPLE FILES)
  // -------------------------------------------------------------
  const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB Slices
  let isUploadingActive = false;
  let uploadQueue = [];
  
  const dropzone = document.getElementById('upload-dropzone');
  const fileInput = document.getElementById('file-input');
  const btnStartUpload = document.getElementById('btn-start-upload');
  const dragZoneText = document.getElementById('drag-zone-text');
  const dragZoneSub = document.getElementById('drag-zone-sub');
  
  const queueContainer = document.getElementById('upload-queue-container');
  const queueList = document.getElementById('upload-queue-list');
  
  const progressCard = document.getElementById('upload-progress-container');
  const progressBar = document.getElementById('progress-indicator');
  const percentLabel = document.getElementById('label-percent');
  const ratioLabel = document.getElementById('label-size-ratio');
  const speedLabel = document.getElementById('label-upload-speed');
  const filenameLabel = document.getElementById('label-filename');

  if (dropzone && fileInput) {
    dropzone.addEventListener('click', () => {
      if (isUploadingActive) return;
      fileInput.click();
    });

    fileInput.addEventListener('change', (e) => {
      if (e.target.files.length > 0) {
        handleFilesSelect(Array.from(e.target.files));
      }
    });

    // Drag-drop events
    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (isUploadingActive) return;
      dropzone.classList.add('drag-active');
    });

    dropzone.addEventListener('dragleave', () => {
      dropzone.classList.remove('drag-active');
    });

    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('drag-active');
      if (isUploadingActive) return;
      
      if (e.dataTransfer.files.length > 0) {
        handleFilesSelect(Array.from(e.dataTransfer.files));
      }
    });
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  function handleFilesSelect(files) {
    const validExtensions = ['.mp4', '.mkv', '.ts', '.avi', '.mov'];
    const filteredFiles = files.filter(file => {
      const ext = file.name.substring(file.name.lastIndexOf('.')).toLowerCase();
      return validExtensions.includes(ext);
    });

    if (filteredFiles.length === 0) {
      alert("Please select valid video files (.mp4, .mkv, .ts, .avi, .mov).");
      return;
    }

    uploadQueue = filteredFiles;
    
    // Render the queue in the UI
    if (queueList && queueContainer) {
      queueList.innerHTML = '';
      uploadQueue.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = "flex items-center justify-between p-4 bg-slate-900/60 border border-slate-800 rounded-xl transition-all";
        item.setAttribute('id', `queue-item-${index}`);
        item.innerHTML = `
          <div class="flex items-center gap-3 truncate max-w-[75%]">
            <div class="w-8 h-8 rounded-lg bg-slate-950 flex items-center justify-center text-slate-400">
              <i class="bi bi-file-earmark-play text-lg"></i>
            </div>
            <div class="flex flex-col truncate leading-tight">
              <span class="text-white font-semibold text-sm truncate" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
              <span class="text-[10px] text-slate-500 font-mono">${formatBytes(file.size)}</span>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <span class="queue-item-status text-xs text-slate-500 font-semibold uppercase font-display" id="queue-status-${index}">Queued</span>
          </div>
        `;
        queueList.appendChild(item);
      });
      queueContainer.classList.remove('hidden');
    }

    dragZoneText.textContent = `${uploadQueue.length} file(s) selected`;
    dragZoneSub.textContent = `Awaiting Ingestion (${formatBytes(uploadQueue.reduce((acc, f) => acc + f.size, 0))})`;
    btnStartUpload.classList.remove('hidden');
  }

  if (btnStartUpload) {
    btnStartUpload.addEventListener('click', () => {
      if (uploadQueue.length > 0 && !isUploadingActive) {
        startChunkedQueueUpload();
        btnStartUpload.classList.add('hidden');
      }
    });
  }

  async function startChunkedQueueUpload() {
    isUploadingActive = true;
    dropzone.style.opacity = '0.35';
    dropzone.style.cursor = 'not-allowed';

    // Collate target resolutions
    const checkboxes = document.querySelectorAll('input[name="resolutions"]:checked');
    const targetResolutions = Array.from(checkboxes).map(cb => cb.value);
    
    if (targetResolutions.length === 0) {
      alert("Please select at least one target resolution to transcode.");
      resetUploaderState();
      return;
    }

    const subtitleInput = document.getElementById('subtitle-file');
    const selectedSubtitle = (subtitleInput && subtitleInput.files.length > 0) ? subtitleInput.files[0] : null;

    if (uploadQueue.length > 1 && selectedSubtitle) {
      showToast("Subtitles will only be applied to the first video file.", "bi-info-circle");
    }

    const titleInput = document.getElementById('stream-display-title');
    const customTitleBase = titleInput ? titleInput.value.trim() : '';

    // Process the queue sequentially
    for (let index = 0; index < uploadQueue.length; index++) {
      const file = uploadQueue[index];
      const fileId = Math.random().toString(36).substring(2, 10) + Date.now().toString(36).substring(2, 10);
      
      const statusLabel = document.getElementById(`queue-status-${index}`);
      const itemCard = document.getElementById(`queue-item-${index}`);
      if (statusLabel && itemCard) {
        statusLabel.textContent = "Uploading...";
        statusLabel.className = "queue-item-status text-xs text-brand-orange font-bold uppercase font-display animate-pulse";
        itemCard.classList.add('border-brand-orange/30', 'bg-brand-orange/5');
      }

      filenameLabel.textContent = file.name;
      progressCard.classList.remove('hidden');
      progressBar.style.width = '0%';
      percentLabel.textContent = '0%';
      ratioLabel.textContent = `0.0 MB / ${(file.size / (1024 * 1024)).toFixed(1)} MB`;
      speedLabel.textContent = '0.0 MB/s';

      let fileTitle = file.name;
      if (customTitleBase !== '') {
        fileTitle = uploadQueue.length > 1 ? `${customTitleBase} - ${index + 1}` : customTitleBase;
      }

      const fileSubtitle = (index === 0) ? selectedSubtitle : null;

      try {
        await transmitSequencedChunks(file, fileId, targetResolutions, fileTitle, fileSubtitle);
        
        if (statusLabel && itemCard) {
          statusLabel.textContent = "Transcoding...";
          statusLabel.className = "queue-item-status text-xs text-emerald-500 font-bold uppercase font-display";
          itemCard.classList.remove('border-brand-orange/30', 'bg-brand-orange/5');
          itemCard.classList.add('border-emerald-500/20', 'bg-emerald-500/5');
        }
      } catch (error) {
        if (statusLabel && itemCard) {
          statusLabel.textContent = "Failed";
          statusLabel.className = "queue-item-status text-xs text-rose-500 font-bold uppercase font-display";
          itemCard.classList.remove('border-brand-orange/30', 'bg-brand-orange/5');
          itemCard.classList.add('border-rose-500/20', 'bg-rose-500/5');
        }
        showToast(`Failed uploading ${file.name}: ${error}`, "bi-exclamation-triangle", true);
      }
    }

    showToast("All video file uploads processed successfully!", "bi-check-circle-fill");
    setTimeout(() => {
      resetUploaderState();
      switchTab('videos');
    }, 2000);
  }

  async function transmitSequencedChunks(file, fileId, resolutions, customTitle, subtitleFile) {
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    const startTime = Date.now();

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
      const byteStart = chunkIndex * CHUNK_SIZE;
      const byteEnd = Math.min(byteStart + CHUNK_SIZE, file.size);
      const fileChunk = file.slice(byteStart, byteEnd);

      const payload = new FormData();
      payload.append('file_id', fileId);
      payload.append('chunk_index', chunkIndex.toString());
      payload.append('total_chunks', totalChunks.toString());
      payload.append('filename', file.name);
      payload.append('resolutions', resolutions.join(','));
      payload.append('title', customTitle);
      payload.append('video_chunk', fileChunk);
      payload.append('expected_chunk_size', fileChunk.size.toString());
      payload.append('total_file_size', file.size.toString());

      if (chunkIndex === 0 && subtitleFile) {
        payload.append('subtitle_file', subtitleFile);
      }

      let response = null;
      const maxRetries = 3;
      for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
          if (attempt > 1) {
            percentLabel.textContent = `Retry ${attempt-1}/${maxRetries-1}...`;
            await new Promise(r => setTimeout(r, 1500));
          }
          response = await transmitChunkAJAX(payload);
          break; // success
        } catch (err) {
          console.warn(`Chunk ${chunkIndex + 1}/${totalChunks} upload failed (Attempt ${attempt}/${maxRetries}):`, err);
          if (attempt === maxRetries) {
            throw new Error(`Chunk ${chunkIndex + 1}/${totalChunks} failed after ${maxRetries} attempts.`);
          }
        }
      }
      
      const bytesUploaded = byteEnd;
      const percent = Math.round((bytesUploaded / file.size) * 100);
      
      progressBar.style.width = `${percent}%`;
      percentLabel.textContent = `${percent}%`;

      const uploadedMb = (bytesUploaded / (1024 * 1024)).toFixed(1);
      const totalMb = (file.size / (1024 * 1024)).toFixed(1);
      ratioLabel.textContent = `${uploadedMb} MB / ${totalMb} MB`;

      const timeElapsed = (Date.now() - startTime) / 1000;
      if (timeElapsed > 0.1) {
        const speed = (uploadedMb / timeElapsed).toFixed(1);
        speedLabel.textContent = `${speed} MB/s`;
      }

      if (response && response.status === 'completed') {
        percentLabel.textContent = 'Queued!';
        progressBar.style.background = 'var(--accent-emerald)';
      }
    }
  }

  function transmitChunkAJAX(formData) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api.php?action=upload_chunk', true);

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const data = JSON.parse(xhr.responseText);
            resolve(data);
          } catch (e) {
            reject('Invalid server JSON payload.');
          }
        } else {
          reject(`Upload failed: Status ${xhr.status}`);
        }
      };

      xhr.onerror = () => reject('Network connection interrupted.');
      xhr.send(formData);
    });
  }

  function resetUploaderState() {
    isUploadingActive = false;
    uploadQueue = [];
    progressCard.classList.add('hidden');
    progressBar.style.width = '0%';
    percentLabel.textContent = '0%';
    ratioLabel.textContent = '0.0 MB / 0.0 MB';
    speedLabel.textContent = '0.0 MB/s';
    fileInput.value = '';
    
    const displayTitle = document.getElementById('stream-display-title');
    if (displayTitle) displayTitle.value = '';
    
    const subtitleInput = document.getElementById('subtitle-file');
    if (subtitleInput) subtitleInput.value = '';
    
    if (queueContainer) queueContainer.classList.add('hidden');
    if (queueList) queueList.innerHTML = '';
    
    dragZoneText.textContent = "Drag and drop your raw video file here";
    dragZoneSub.textContent = "Supports MP4, MKV, TS, AVI, MOV up to multi-gigabytes";
    
    dropzone.style.opacity = '1';
    dropzone.style.cursor = 'pointer';
    btnStartUpload.classList.add('hidden');
  }

  // -------------------------------------------------------------
  // DYNAMIC STREAM REGISTRY LIST (CRUD)
  // -------------------------------------------------------------
  const tableBody = document.getElementById('streams-table-body');
  
  window.refreshStreamsTable = function () {
    fetch('api.php?action=list')
      .then(res => res.json())
      .then(data => {
        if (!tableBody) return;
        tableBody.innerHTML = '';
        
        let transcodingCount = 0;
        
        if (data.length === 0) {
          tableBody.innerHTML = `
            <tr>
              <td colspan="6" class="py-12 text-center text-slate-500 font-mono text-xs">No video stream records in registry.</td>
            </tr>
          `;
          updateMetricsHUD(0, 0);
          return;
        }

        data.forEach(stream => {
          const tr = document.createElement('tr');
          tr.className = "border-b border-slate-800/40 hover:bg-slate-900/10 transition-colors";
          tr.setAttribute('id', `stream-row-${stream.id}`);
          
          let badgeClass = 'text-amber-500 bg-amber-500/10 border-amber-500/20';
          let statusText = stream.status;
          if (stream.status === 'ready') {
            badgeClass = 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20';
          } else if (stream.status === 'transcoding') {
            transcodingCount++;
            badgeClass = 'text-indigo-400 bg-indigo-500/10 border-indigo-500/20 animate-pulse';
          } else if (stream.status === 'failed') {
            badgeClass = 'text-rose-500 bg-rose-500/10 border-rose-500/20';
          }
          
          const created = new Date(stream.created_at).toLocaleString();
          const duration = stream.duration ? `${Math.floor(stream.duration / 60)}m ${stream.duration % 60}s` : 'Processing';
          const codec = stream.video_codec ? stream.video_codec : 'Detecting';
          
          // Decode dynamic active resolutions
          let resolutionsSelected = [];
          try {
            resolutionsSelected = JSON.parse(stream.resolutions_selected);
          } catch(e) {}
          
          let resBadges = resolutionsSelected.map(res => {
            return `<span class="px-2 py-0.5 rounded bg-slate-800 border border-slate-700/60 font-mono text-[10px] text-slate-400">${res}</span>`;
          }).join(' ');

          let playBtn = '';
          let copyButtons = '';
          if (stream.status === 'ready') {
            playBtn = `
              <button onclick="openPreviewModal('${stream.id}', '${escapeHtml(stream.title)}')" class="px-2.5 py-1 rounded bg-brand-orange hover:bg-orange-600 transition-colors text-white font-semibold text-xs flex items-center gap-1.5 shadow-md shadow-brand-orange/15">
                <i class="bi bi-play-fill text-sm"></i> Play
              </button>
            `;
            copyButtons = `
              <button onclick="copyStreamLink('${stream.id}')" class="p-1.5 rounded bg-slate-900 border border-slate-800 hover:bg-brand-indigo/20 hover:border-brand-indigo/40 text-slate-400 hover:text-brand-indigo transition-all shadow-sm" title="Copy stream link">
                <i class="bi bi-link-45deg text-sm"></i>
              </button>
              <button onclick="copyIframeCode('${stream.id}')" class="p-1.5 rounded bg-slate-900 border border-slate-800 hover:bg-brand-indigo/20 hover:border-brand-indigo/40 text-slate-400 hover:text-brand-indigo transition-all shadow-sm" title="Copy iframe embed code">
                <i class="bi bi-code-slash text-sm"></i>
              </button>
            `;
          } else {
            playBtn = `
              <button disabled class="px-2.5 py-1 rounded bg-slate-800 text-slate-600 border border-slate-700/40 text-xs font-semibold flex items-center gap-1.5 cursor-not-allowed">
                <i class="bi bi-hourglass-split"></i> Wait
              </button>
            `;
            copyButtons = `
              <button disabled class="p-1.5 rounded bg-slate-800 text-slate-600 border border-slate-700/40 cursor-not-allowed opacity-50">
                <i class="bi bi-link-45deg text-sm"></i>
              </button>
              <button disabled class="p-1.5 rounded bg-slate-800 text-slate-600 border border-slate-700/40 cursor-not-allowed opacity-50">
                <i class="bi bi-code-slash text-sm"></i>
              </button>
            `;
          }

          tr.innerHTML = `
            <td class="py-4 px-6 relative max-w-[280px]">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-500">
                  <i class="bi bi-film"></i>
                </div>
                <div class="flex-1 min-w-0">
                  <!-- Title text view node -->
                  <div class="stream-title-display flex items-center gap-2 group cursor-pointer" onclick="enableInlineTitleEdit('${stream.id}')" title="Double click to edit">
                    <span id="title-text-${stream.id}" class="text-white font-bold truncate max-w-[200px] block group-hover:text-brand-orange transition-colors">${escapeHtml(stream.title)}</span>
                    <i class="bi bi-pencil-square text-xs text-slate-600 group-hover:text-brand-orange/60 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                  <!-- Title input edit node -->
                  <div id="title-edit-container-${stream.id}" class="hidden flex items-center gap-1.5">
                    <input type="text" id="title-input-${stream.id}" value="${escapeHtml(stream.title)}" class="bg-slate-950 border border-brand-orange/60 rounded px-2 py-1 text-xs font-bold text-white focus:outline-none focus:ring-1 focus:ring-brand-orange max-w-[170px]" onkeydown="handleTitleInputKeydown(event, '${stream.id}')">
                    <button onclick="saveInlineTitle('${stream.id}')" class="p-1 text-emerald-500 hover:text-emerald-400 transition-colors">
                      <i class="bi bi-check-lg text-sm"></i>
                    </button>
                    <button onclick="disableInlineTitleEdit('${stream.id}')" class="p-1 text-rose-500 hover:text-rose-400 transition-colors">
                      <i class="bi bi-x-lg text-xs"></i>
                    </button>
                  </div>
                  <span class="text-[10px] text-slate-500 block mt-0.5">${created}</span>
                </div>
              </div>
            </td>
            
            <td class="py-4 px-6 font-mono text-xs text-slate-400">
              <span class="block truncate max-w-[150px]" title="${stream.id}">ID: <code>${stream.id}</code></span>
              <span class="block text-slate-500 truncate max-w-[150px] mt-0.5" title="${escapeHtml(stream.filename)}">${escapeHtml(stream.filename)}</span>
            </td>
            
            <td class="py-4 px-6">
              <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-mono font-bold uppercase tracking-wider ${badgeClass}">
                ${statusText}
              </span>
            </td>
            
            <td class="py-4 px-6 text-xs text-slate-400 font-mono">
              <span class="block"><i class="bi bi-hdd-network text-slate-600 mr-1"></i>${codec.toUpperCase()}</span>
              <span class="block text-slate-500 mt-0.5"><i class="bi bi-clock-history text-slate-600 mr-1"></i>${duration}</span>
            </td>
            
            <td class="py-4 px-6">
              <div class="flex flex-wrap gap-1.5 max-w-[180px]">
                ${resBadges}
              </div>
            </td>
            
            <td class="py-4 px-6 text-right">
              <div class="flex items-center justify-end gap-2">
                ${playBtn}
                ${copyButtons}
                <button onclick="deleteStream('${stream.id}')" class="p-1.5 rounded bg-slate-900 border border-slate-800 hover:bg-rose-950/20 hover:border-rose-900/30 text-slate-500 hover:text-rose-500 transition-all shadow-sm" title="Delete stream & purge disk">
                  <i class="bi bi-trash text-sm"></i>
                </button>
              </div>
            </td>
          `;
          
          tableBody.appendChild(tr);
        });
        
        updateMetricsHUD(data.length, transcodingCount);
      })
      .catch(err => {
        if (tableBody) {
          tableBody.innerHTML = `
            <tr>
              <td colspan="6" class="py-8 text-center text-rose-500 font-mono text-xs">Failed to fetch streams catalog: ${err.message}</td>
            </tr>
          `;
        }
      });
  };

  function updateMetricsHUD(total, transcoding) {
    const metricTotal = document.getElementById('metric-streams-count');
    const metricTrans = document.getElementById('metric-transcoding-count');
    const workerIndicator = document.getElementById('active-worker-indicator');
    
    if (metricTotal) metricTotal.textContent = total;
    if (metricTrans) metricTrans.textContent = transcoding;
    
    if (workerIndicator) {
      if (transcoding > 0) {
        workerIndicator.classList.remove('hidden');
      } else {
        workerIndicator.classList.add('hidden');
      }
    }
  }

  // --- Inline Title Editor logic ---
  window.enableInlineTitleEdit = function (streamId) {
    const displayNode = document.querySelector(`#stream-row-${streamId} .stream-title-display`);
    const editNode = document.getElementById(`title-edit-container-${streamId}`);
    const inputNode = document.getElementById(`title-input-${streamId}`);
    
    if (displayNode && editNode && inputNode) {
      displayNode.classList.add('hidden');
      editNode.classList.remove('hidden');
      inputNode.focus();
      inputNode.select();
    }
  };

  window.disableInlineTitleEdit = function (streamId) {
    const displayNode = document.querySelector(`#stream-row-${streamId} .stream-title-display`);
    const editNode = document.getElementById(`title-edit-container-${streamId}`);
    
    if (displayNode && editNode) {
      editNode.classList.add('hidden');
      displayNode.classList.remove('hidden');
    }
  };

  window.handleTitleInputKeydown = function (event, streamId) {
    if (event.key === 'Enter') {
      saveInlineTitle(streamId);
    } else if (event.key === 'Escape') {
      disableInlineTitleEdit(streamId);
    }
  };

  window.saveInlineTitle = function (streamId) {
    const inputNode = document.getElementById(`title-input-${streamId}`);
    if (!inputNode) return;
    
    const newTitle = inputNode.value.trim();
    if (newTitle === '') {
      alert('Title cannot be blank.');
      return;
    }

    const payload = new FormData();
    payload.append('id', streamId);
    payload.append('title', newTitle);

    fetch('api.php?action=update_stream', {
      method: 'POST',
      body: payload
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          const titleLabel = document.getElementById(`title-text-${streamId}`);
          if (titleLabel) titleLabel.textContent = newTitle;
          disableInlineTitleEdit(streamId);
          showToast("Stream title renamed successfully.", "bi-pencil-square");
        } else {
          showToast(`Rename error: ${data.error}`, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => {
        showToast("Connection issue: " + err.message, "bi-wifi-off", true);
      });
  };

  // --- Deletion and Purge logic ---
  window.deleteStream = function (streamId) {
    if (!confirm("⚠️ WARNING: This will permanently delete the stream record from SQLite, and completely PURGE all transcode HLS directories on disk. This action is irreversible. Proceed?")) return;

    const row = document.getElementById(`stream-row-${streamId}`);
    if (row) {
      row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      row.style.opacity = '0';
      row.style.transform = 'translateX(-20px)';
    }

    const payload = new FormData();
    payload.append('id', streamId);

    fetch('api.php?action=delete_stream', {
      method: 'POST',
      body: payload
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          setTimeout(() => {
            if (row) row.remove();
            refreshStreamsTable();
          }, 400);
          showToast("Stream and filesystem resources purged.", "bi-trash-fill");
        } else {
          if (row) {
            row.style.opacity = '1';
            row.style.transform = 'none';
          }
          showToast(`Purge failed: ${data.error}`, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => {
        if (row) {
          row.style.opacity = '1';
          row.style.transform = 'none';
        }
        showToast("Purge interrupted: " + err.message, "bi-wifi-off", true);
      });
  };

  // -------------------------------------------------------------
  // VAST AD SCHEDULER (CRUD)
  // -------------------------------------------------------------
  window.adjustOffsetValueInputBehavior = function () {
    const typeSelect = document.getElementById('ad-offset-type');
    const valueGroup = document.getElementById('ad-offset-value-group');
    const valueInput = document.getElementById('ad-offset-value');

    if (!typeSelect || !valueGroup || !valueInput) return;

    if (typeSelect.value === 'preroll') {
      valueInput.value = '0';
      valueGroup.style.opacity = '0.35';
      valueInput.setAttribute('disabled', 'disabled');
    } else if (typeSelect.value === 'postroll') {
      valueInput.value = 'postroll';
      valueGroup.style.opacity = '0.35';
      valueInput.setAttribute('disabled', 'disabled');
    } else {
      valueInput.value = '';
      valueGroup.style.opacity = '1';
      valueInput.removeAttribute('disabled');
      valueInput.focus();
    }
  };

  window.createAdCampaign = function (event) {
    event.preventDefault();
    const form = document.getElementById('ad-campaign-form');
    if (!form) return;
    
    const formData = new FormData(form);
    
    fetch('api.php?action=add_ad', {
      method: 'POST',
      body: formData
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast("VAST ad campaign saved successfully.", "bi-megaphone");
          form.reset();
          adjustOffsetValueInputBehavior();
          refreshAdsTable();
        } else {
          showToast("Failed to create campaign: " + data.error, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => showToast("Connection failed: " + err.message, "bi-wifi-off", true));
  };

  const adsTableBody = document.getElementById('ads-table-body');
  
  window.refreshAdsTable = function () {
    fetch('api.php?action=list_ads')
      .then(res => res.json())
      .then(data => {
        if (!adsTableBody) return;
        adsTableBody.innerHTML = '';
        
        const metricAds = document.getElementById('metric-ads-count');
        if (metricAds) metricAds.textContent = data.length;
        
        if (data.length === 0) {
          adsTableBody.innerHTML = `
            <tr>
              <td colspan="5" class="py-8 text-center text-slate-500 font-mono text-xs">No active VAST campaigns.</td>
            </tr>
          `;
          return;
        }

        data.forEach(ad => {
          const tr = document.createElement('tr');
          tr.className = "border-b border-slate-800/40 hover:bg-slate-900/10 transition-colors";
          
          let displayOffset = ad.offset_value;
          if (ad.offset_type === 'preroll') displayOffset = 'Start (0s)';
          if (ad.offset_type === 'postroll') displayOffset = 'End (Post)';

          tr.innerHTML = `
            <td class="py-4 px-6 font-bold text-white font-display">${escapeHtml(ad.name)}</td>
            <td class="py-4 px-6 text-xs text-slate-400 font-semibold capitalize">${escapeHtml(ad.offset_type)}</td>
            <td class="py-4 px-6"><span class="px-2 py-0.5 rounded bg-slate-900 border border-slate-800 font-mono text-[10px] text-brand-orange">${escapeHtml(displayOffset)}</span></td>
            <td class="py-4 px-6 text-xs text-slate-500 font-mono max-w-[200px] truncate" title="${escapeHtml(ad.vast_url)}">${escapeHtml(ad.vast_url)}</td>
            <td class="py-4 px-6 text-right">
              <button onclick="deleteAdCampaign('${ad.id}')" class="p-1.5 rounded bg-slate-900 border border-slate-800 hover:bg-rose-950/20 hover:border-rose-900/30 text-slate-500 hover:text-rose-500 transition-all shadow-sm">
                <i class="bi bi-trash text-sm"></i>
              </button>
            </td>
          `;
          adsTableBody.appendChild(tr);
        });
      })
      .catch(err => {
        if (adsTableBody) {
          adsTableBody.innerHTML = `
            <tr>
              <td colspan="5" class="py-8 text-center text-rose-500 font-mono text-xs">Failed to load ad scheduler: ${err.message}</td>
            </tr>
          `;
        }
      });
  };

  window.deleteAdCampaign = function (adId) {
    if (!confirm("Are you sure you want to delete this VAST campaign schedule?")) return;

    const payload = new FormData();
    payload.append('id', adId);

    fetch('api.php?action=delete_ad', {
      method: 'POST',
      body: payload
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          refreshAdsTable();
          showToast("Campaign deleted.", "bi-trash");
        } else {
          showToast("Failed deletion: " + data.error, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => showToast("Connection error: " + err.message, "bi-wifi-off", true));
  };

  // -------------------------------------------------------------
  // PREVIEW MODAL PLAYER
  // -------------------------------------------------------------
  const modalOverlay = document.getElementById('preview-modal');
  const previewIframe = document.getElementById('preview-iframe');
  const modalTitle = document.getElementById('preview-modal-title');
  const modalCard = document.getElementById('preview-modal-card');

  window.openPreviewModal = function(streamId, streamTitle) {
    if (!modalOverlay || !previewIframe || !modalTitle || !modalCard) return;
    
    modalTitle.textContent = `Streaming Preview: ${streamTitle}`;
    previewIframe.src = `stream.php?id=${streamId}`;
    
    modalOverlay.classList.remove('opacity-0', 'pointer-events-none');
    modalOverlay.classList.add('opacity-100');
    
    modalCard.classList.remove('scale-95');
    modalCard.classList.add('scale-100');
  };

  window.closePreviewModal = function() {
    if (!modalOverlay || !previewIframe || !modalCard) return;
    
    modalCard.classList.remove('scale-100');
    modalCard.classList.add('scale-95');
    
    modalOverlay.classList.remove('opacity-100');
    modalOverlay.classList.add('opacity-0', 'pointer-events-none');
    
    setTimeout(() => {
      previewIframe.src = '';
    }, 300);
  };

  // Dismiss modal triggers
  if (modalOverlay) {
    modalOverlay.addEventListener('click', (e) => {
      if (e.target === modalOverlay) closePreviewModal();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closePreviewModal();
    });
  }

  // -------------------------------------------------------------
  // CLIPBOARD UTILITY FUNCTIONS
  // -------------------------------------------------------------
  window.copyIframeCode = function (streamId) {
    const embedCode = `<iframe src="${window.location.origin}/stream.php?id=${streamId}" width="640" height="360" frameborder="0" allowfullscreen></iframe>`;
    navigator.clipboard.writeText(embedCode)
      .then(() => {
        showToast("Iframe embed code copied!", "bi-code-slash");
      })
      .catch(() => {
        // Fallback for secure contexts in older browsers
        const textarea = document.createElement('textarea');
        textarea.value = embedCode;
        textarea.style.position = 'fixed'; // Avoid scrolling to bottom
        document.body.appendChild(textarea);
        textarea.select();
        try {
          document.execCommand('copy');
          showToast("Iframe embed code copied!", "bi-code-slash");
        } catch (err) {
          showToast("Failed to copy embed code.", "bi-exclamation-triangle", true);
        }
        document.body.removeChild(textarea);
      });
  };

  window.copyStreamLink = function (streamId) {
    const streamLink = `${window.location.origin}/stream.php?id=${streamId}`;
    navigator.clipboard.writeText(streamLink)
      .then(() => {
        showToast("Stream play link copied!", "bi-link-45deg");
      })
      .catch(() => {
        // Fallback for secure contexts in older browsers
        const textarea = document.createElement('textarea');
        textarea.value = streamLink;
        textarea.style.position = 'fixed';
        document.body.appendChild(textarea);
        textarea.select();
        try {
          document.execCommand('copy');
          showToast("Stream play link copied!", "bi-link-45deg");
        } catch (err) {
          showToast("Failed to copy play link.", "bi-exclamation-triangle", true);
        }
        document.body.removeChild(textarea);
      });
  };

  window.changePassword = function (event) {
    event.preventDefault();
    const form = document.getElementById('change-password-form');
    if (!form) return;

    const currentPwd = form.querySelector('[name="current_password"]').value;
    const newPwd = form.querySelector('[name="new_password"]').value;
    const confirmPwd = form.querySelector('[name="confirm_password"]').value;

    if (newPwd.length < 6) {
      showToast("New password must be at least 6 characters long.", "bi-exclamation-triangle", true);
      return;
    }

    if (newPwd !== confirmPwd) {
      showToast("New passwords do not match.", "bi-exclamation-triangle", true);
      return;
    }

    const formData = new FormData(form);

    fetch('api.php?action=change_password', {
      method: 'POST',
      body: formData
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast(data.message, "bi-shield-check");
          form.reset();
        } else {
          showToast("Failed to update password: " + data.error, "bi-exclamation-triangle", true);
        }
      })
      .catch(err => {
        showToast("Network connection issue: " + err.message, "bi-wifi-off", true);
      });
  };

  // -------------------------------------------------------------
  // SYSTEM UTILS
  // -------------------------------------------------------------
  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // -------------------------------------------------------------
  // BOOT INITIALIZATION SCRIPT
  // -------------------------------------------------------------
  // Fetch active settings values from database to render Ingestion Portal checkbox options
  fetch('api.php?action=get_settings')
    .then(res => res.json())
    .then(data => {
      if (data && data.renditions) {
        syncUploaderResolutionsList(data.renditions);
      }
    })
    .catch(() => {
      // Database unavailable, load default checklist elements
      const defaults = {
        '1080p': {width: 1920, height: 1080},
        '720p': {width: 1280, height: 720},
        '540p': {width: 960, height: 540},
        '480p': {width: 854, height: 480},
        '360p': {width: 640, height: 360}
      };
      syncUploaderResolutionsList(defaults);
    });

  adjustOffsetValueInputBehavior();
  
  // Call relevant loaders based on active tab
  if (activeTab === 'videos') {
    refreshStreamsTable();
  } else if (activeTab === 'ads') {
    refreshAdsTable();
  }
  
  // Real-time polling updates every 5 seconds to track background transcoding worker progress
  setInterval(() => {
    if (activeTab === 'videos' && !isUploadingActive) {
      refreshStreamsTable();
    }
  }, 5000);
});
