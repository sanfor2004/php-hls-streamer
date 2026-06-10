/**
 * ZENITH BRANDED ADMINISTRATION CONSOLE LOGIC (console.js)
 * Implements chunked video ingestion, resolution presets, ad integration form handlers,
 * and live-updating stream directories.
 */

// A helper to let users toggle checkmarks and styling of the custom cards
function toggleResolutionCheckbox(checkboxId, cardId) {
  const checkbox = document.getElementById(checkboxId);
  if (checkbox) {
    checkbox.checked = !checkbox.checked;
    updateCheckboxStyle(checkboxId, cardId);
  }
}

function updateCheckboxStyle(checkboxId, cardId) {
  const checkbox = document.getElementById(checkboxId);
  const card = document.getElementById(cardId);
  if (checkbox && card) {
    if (checkbox.checked) {
      card.classList.add('selected');
    } else {
      card.classList.remove('selected');
    }
  }
}

// Adjusts input layout instructions depending on what Offset position standard is selected by the user.
function adjustOffsetValueInputBehavior() {
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
}

// Wait until the HTML document is fully parsed and loaded in the client browser before running logic.
document.addEventListener('DOMContentLoaded', () => {
  
  // --- DOM ELEMENT SELECTION REGION ---
  const dropzone = document.getElementById('upload-dropzone');
  const fileInput = document.getElementById('file-input');
  const progressCard = document.getElementById('upload-progress-container');
  const progressBar = document.getElementById('progress-indicator');
  const percentLabel = document.getElementById('label-percent');
  const ratioLabel = document.getElementById('label-size-ratio');
  const speedLabel = document.getElementById('label-upload-speed');
  const filenameLabel = document.getElementById('label-filename');
  const registryList = document.getElementById('registry-items-list');
  
  const adCampaignForm = document.getElementById('ad-campaign-form');
  const adsDirectory = document.getElementById('ad-campaigns-directory');

  // Preview Modal DOM Selections
  const modalOverlay = document.getElementById('preview-modal');
  const iframeContainer = document.getElementById('preview-iframe-container');
  const modalTitle = document.getElementById('modal-stream-title');

  window.openPreviewModal = function(streamId, streamTitle) {
    if (!modalOverlay || !iframeContainer || !modalTitle) return;
    modalTitle.textContent = `Preview: ${streamTitle}`;
    iframeContainer.innerHTML = `<iframe id="preview-iframe" src="stream?id=${streamId}" allowfullscreen></iframe>`;
    modalOverlay.style.display = 'flex';
    modalOverlay.offsetHeight; // trigger reflow
    modalOverlay.classList.add('active');
  };

  window.closePreviewModal = function() {
    if (!modalOverlay || !iframeContainer) return;
    modalOverlay.classList.remove('active');
    setTimeout(() => {
      iframeContainer.innerHTML = '';
      modalOverlay.style.display = 'none';
    }, 300);
  };

  // Close modal on escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
      closePreviewModal();
    }
  });

  // Close modal if clicking overlay backdrop
  modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) {
      closePreviewModal();
    }
  });

  adjustOffsetValueInputBehavior();

  // --- CONFIGURATION CONSTANTS ---
  // Size boundary of individual sliced blocks (5 Megabytes in raw bytes).
  const CHUNK_SIZE = 5 * 1024 * 1024;

  // --- STATE VARIABLES ---
  let isUploadingActive = false;
  let uploadQueue = [];

  const btnStartUpload = document.getElementById('btn-start-upload');
  const dragZoneText = document.getElementById('drag-zone-text');
  const dragZoneSub = document.getElementById('drag-zone-sub');
  const queueContainer = document.getElementById('upload-queue-container');
  const queueList = document.getElementById('upload-queue-list');

  // ---------------------------------------------------------
  // EVENT LISTENER: SELECT AND CLICK TRIGGERS
  // ---------------------------------------------------------
  dropzone.addEventListener('click', () => {
    if (isUploadingActive) return;
    fileInput.click();
  });

  fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
      handleFilesSelect(Array.from(e.target.files));
    }
  });

  btnStartUpload.addEventListener('click', () => {
    if (uploadQueue.length > 0 && !isUploadingActive) {
      startIngestionProcess();
      btnStartUpload.style.display = 'none';
    }
  });

  // ---------------------------------------------------------
  // EVENT LISTENERS: HTML5 DRAG & DROP PIPELINE
  // ---------------------------------------------------------
  dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (isUploadingActive) return;
    dropzone.classList.add('dragover');
  });

  dropzone.addEventListener('dragleave', () => {
    dropzone.classList.remove('dragover');
  });

  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    
    if (isUploadingActive) return;

    if (e.dataTransfer.files.length > 0) {
      handleFilesSelect(Array.from(e.dataTransfer.files));
    }
  });

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

    if (queueList && queueContainer) {
      queueList.innerHTML = '';
      uploadQueue.forEach((file, index) => {
        const item = document.createElement('div');
        item.style.display = "flex";
        item.style.alignItems = "center";
        item.style.justifyContent = "space-between";
        item.style.padding = "0.75rem 1rem";
        item.style.background = "rgba(255, 255, 255, 0.03)";
        item.style.border = "1px solid rgba(255, 255, 255, 0.05)";
        item.style.borderRadius = "8px";
        item.style.marginTop = "0.5rem";
        item.setAttribute('id', `queue-item-${index}`);
        item.innerHTML = `
          <div style="display: flex; align-items: center; gap: 0.75rem; overflow: hidden; max-width: 75%;">
            <div style="background: rgba(0,0,0,0.2); padding: 6px; border-radius: 6px; color: var(--text-muted); display: flex; align-items: center; justify-content: center;">
              <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"></path><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            </div>
            <div style="display: flex; flex-direction: column; overflow: hidden; line-height: 1.2;">
              <span style="color: #fff; font-weight: 600; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
              <span style="font-size: 0.7rem; color: var(--text-muted); font-family: var(--font-mono);">${formatBytes(file.size)}</span>
            </div>
          </div>
          <div>
            <span class="queue-item-status" style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;" id="queue-status-${index}">Queued</span>
          </div>
        `;
        queueList.appendChild(item);
      });
      queueContainer.style.display = 'flex';
    }

    dragZoneText.textContent = `${uploadQueue.length} file(s) selected`;
    dragZoneSub.textContent = `Awaiting Ingestion (${formatBytes(uploadQueue.reduce((acc, f) => acc + f.size, 0))})`;
    btnStartUpload.style.display = 'block';
  }

  // ---------------------------------------------------------
  // FUNCTION: START INGESTION PROCESS AND INITIALIZE VALUES
  // ---------------------------------------------------------
  async function startIngestionProcess() {
    isUploadingActive = true;
    dropzone.style.opacity = '0.35';
    dropzone.style.cursor = 'not-allowed';

    const checkboxes = document.querySelectorAll('input[name="resolutions"]:checked');
    const targetResolutions = Array.from(checkboxes).map(checkbox => checkbox.value);

    if (targetResolutions.length === 0) {
      alert("Please select at least one target resolution to transcode.");
      resetUploaderState();
      return;
    }

    const customTitleInput = document.getElementById('stream-display-title');
    const customTitleBase = customTitleInput ? customTitleInput.value.trim() : '';

    const subtitleInput = document.getElementById('subtitle-file');
    const selectedSubtitle = (subtitleInput && subtitleInput.files.length > 0) ? subtitleInput.files[0] : null;

    if (uploadQueue.length > 1 && selectedSubtitle) {
      alert("Notice: Subtitles will only be applied to the first video file in the queue.");
    }

    // Process sequentially
    for (let index = 0; index < uploadQueue.length; index++) {
      const file = uploadQueue[index];
      const fileId = Math.random().toString(36).substring(2, 10) + Date.now().toString(36).substring(2, 10);
      
      const statusLabel = document.getElementById(`queue-status-${index}`);
      const itemCard = document.getElementById(`queue-item-${index}`);
      if (statusLabel && itemCard) {
        statusLabel.textContent = "Uploading...";
        statusLabel.style.color = "var(--accent-glow, #3b82f6)";
        itemCard.style.borderColor = "var(--accent-glow, #3b82f6)";
        itemCard.style.background = "rgba(59, 130, 246, 0.05)";
      }

      filenameLabel.textContent = file.name;
      progressCard.style.display = 'block';
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
        await uploadFileInSequencedChunks(file, fileId, targetResolutions, fileTitle, fileSubtitle);
        if (statusLabel && itemCard) {
          statusLabel.textContent = "Transcoding...";
          statusLabel.style.color = "var(--accent-emerald, #10b981)";
          itemCard.style.borderColor = "var(--accent-emerald, #10b981)";
          itemCard.style.background = "rgba(16, 185, 129, 0.05)";
        }
      } catch (error) {
        if (statusLabel && itemCard) {
          statusLabel.textContent = "Failed";
          statusLabel.style.color = "var(--accent-ruby, #ef4444)";
          itemCard.style.borderColor = "var(--accent-ruby, #ef4444)";
          itemCard.style.background = "rgba(239, 68, 68, 0.05)";
        }
        alert(`Failed uploading ${file.name}: ${error}`);
      }
    }

    alert('All files uploaded and queued for transcoding successfully!');
    resetUploaderState();
    refreshCatalogList();
  }

  // ---------------------------------------------------------
  // FUNCTION: CHUNK SLICING AND SEQUENTIALLY UPLOADING ENGINE
  // ---------------------------------------------------------
  async function uploadFileInSequencedChunks(file, fileId, resolutions, customTitle, subtitleFile) {
    const totalChunksCount = Math.ceil(file.size / CHUNK_SIZE);
    const startTime = Date.now();

    for (let chunkIndex = 0; chunkIndex < totalChunksCount; chunkIndex++) {
      const byteStart = chunkIndex * CHUNK_SIZE;
      const byteEnd = Math.min(byteStart + CHUNK_SIZE, file.size);
      const fileChunkSlice = file.slice(byteStart, byteEnd);

      const payload = new FormData();
      payload.append('file_id', fileId);
      payload.append('chunk_index', chunkIndex.toString());
      payload.append('total_chunks', totalChunksCount.toString());
      payload.append('filename', file.name);
      payload.append('resolutions', resolutions.join(','));
      payload.append('title', customTitle);
      payload.append('video_chunk', fileChunkSlice);
      payload.append('expected_chunk_size', fileChunkSlice.size.toString());
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
          response = await transmitChunkToServer(payload);
          break; // success
        } catch (err) {
          console.warn(`Chunk ${chunkIndex + 1}/${totalChunksCount} upload failed (Attempt ${attempt}/${maxRetries}):`, err);
          if (attempt === maxRetries) {
            throw new Error(`Chunk ${chunkIndex + 1}/${totalChunksCount} failed after ${maxRetries} attempts.`);
          }
        }
      }
      
      const bytesUploaded = byteEnd;
      const totalBytes = file.size;
      const percentagePercent = Math.round((bytesUploaded / totalBytes) * 100);
      
      progressBar.style.width = percentagePercent + '%';
      percentLabel.textContent = percentagePercent + '%';

      const uploadedMb = (bytesUploaded / (1024 * 1024)).toFixed(1);
      const totalMb = (totalBytes / (1024 * 1024)).toFixed(1);
      ratioLabel.textContent = uploadedMb + ' MB / ' + totalMb + ' MB';

      const timeElapsed = (Date.now() - startTime) / 1000;
      if (timeElapsed > 0.1) {
        const currentSpeedMbSec = (uploadedMb / timeElapsed).toFixed(1);
        speedLabel.textContent = currentSpeedMbSec + ' MB/s';
      }

      if (response && response.status === 'completed') {
        percentLabel.textContent = 'Queued!';
        progressBar.style.background = 'var(--accent-emerald)';
      }
    }
  }

  // ---------------------------------------------------------
  // CORE UTILITY: PROMISIFIED XMLHTTPREQUEST UPLOADER
  // ---------------------------------------------------------
  function transmitChunkToServer(formData) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api?action=upload_chunk', true);

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const responseJson = JSON.parse(xhr.responseText);
            resolve(responseJson);
          } catch (e) {
            reject('Invalid server JSON response: ' + xhr.responseText);
          }
        } else {
          reject('HTTP upload failed with status code: ' + xhr.status);
        }
      };

      xhr.onerror = () => {
        reject('Network connectivity loss.');
      };

      xhr.send(formData);
    });
  }

  function resetUploaderState() {
    isUploadingActive = false;
    uploadQueue = [];
    progressCard.style.display = 'none';
    progressBar.style.width = '0%';
    progressBar.style.background = 'linear-gradient(90deg, var(--accent-indigo), var(--accent-glow))';
    percentLabel.textContent = '0%';
    ratioLabel.textContent = '0.0 MB / 0.0 MB';
    speedLabel.textContent = '0.0 MB/s';
    fileInput.value = '';
    
    const displayTitleInput = document.getElementById('stream-display-title');
    if (displayTitleInput) displayTitleInput.value = '';
    
    const subtitleInput = document.getElementById('subtitle-file');
    if (subtitleInput) subtitleInput.value = '';
    
    if (queueContainer) queueContainer.style.display = 'none';
    if (queueList) queueList.innerHTML = '';
    
    dragZoneText.textContent = "Drag and drop your raw video file here";
    dragZoneSub.textContent = "Supports MP4, MKV, TS, AVI, MOV up to multi-gigabytes";
    
    dropzone.style.opacity = '1';
    dropzone.style.cursor = 'pointer';
    btnStartUpload.style.display = 'none';
  }

  // ---------------------------------------------------------
  // AD CAMPAIGN CREATION FORM INTERCEPTION (PHASE 3 CRUD)
  // ---------------------------------------------------------
  adCampaignForm.addEventListener('submit', (e) => {
    e.preventDefault();

    const formData = new FormData(adCampaignForm);
    
    fetch('api?action=add_ad', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          alert(data.message);
          adCampaignForm.reset();
          adjustOffsetValueInputBehavior();
          refreshAdCampaignsTable();
        } else {
          alert('Operation Failure: ' + data.error);
        }
      })
      .catch(err => {
        alert('Network Error: ' + err.message);
      });
  });

  // ---------------------------------------------------------
  // AD CAMPAIGNS REFRESH LISTER (PHASE 3 CRUD)
  // ---------------------------------------------------------
  function refreshAdCampaignsTable() {
    fetch('api?action=list_ads')
      .then(res => res.json())
      .then(data => {
        adsDirectory.innerHTML = '';
        
        if (data.length === 0) {
          adsDirectory.innerHTML = '<tr><td colspan="5" style="color: var(--text-muted); text-align: center; padding: 1.5rem;">No VAST ad configurations registered.</td></tr>';
          return;
        }

        data.forEach(ad => {
          const tr = document.createElement('tr');
          
          let displayOffset = ad.offset_value;
          if (ad.offset_type === 'preroll') displayOffset = 'Start (0s)';
          if (ad.offset_type === 'postroll') displayOffset = 'End (Post)';

          tr.innerHTML = `
            <td style="font-weight: 700; color: #fff; font-family: var(--font-display);">${escapeHtml(ad.name)}</td>
            <td style="text-transform: capitalize;">${escapeHtml(ad.offset_type)}</td>
            <td><code style="background: rgba(0,0,0,0.3); padding: 3px 7px; border-radius:4px; font-family: var(--font-mono); font-size:0.75rem; border:1px solid rgba(255,255,255,0.05);">${escapeHtml(displayOffset)}</code></td>
            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: var(--font-mono); color: var(--text-secondary); font-size:0.75rem;">${escapeHtml(ad.vast_url)}</td>
            <td style="text-align: right;">
              <button class="btn-action btn-delete" onclick="deleteAdCampaign('${ad.id}')">Delete</button>
            </td>
          `;
          adsDirectory.appendChild(tr);
        });
      })
      .catch(err => {
        adsDirectory.innerHTML = `<tr><td colspan="5" style="color: var(--accent-ruby); text-align: center;">Failed to scan ad catalog: ${err.message}</td></tr>`;
      });
  }

  window.deleteAdCampaign = function (adId) {
    if (!confirm('Are you sure you want to delete this ad integration?')) return;

    const payload = new FormData();
    payload.append('id', adId);

    fetch('api?action=delete_ad', {
      method: 'POST',
      body: payload
    })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          refreshAdCampaignsTable();
        } else {
          alert('Delete failed: ' + data.error);
        }
      })
      .catch(err => alert('Network Error: ' + err.message));
  };

  // ---------------------------------------------------------
  // DYNAMIC STREAM DIRECTORY RENDERER
  // ---------------------------------------------------------
  function refreshCatalogList() {
    fetch('api?action=list')
      .then(response => {
        if (!response.ok) throw new Error('Query directory failed.');
        return response.json();
      })
      .then(data => {
        registryList.innerHTML = '';

        if (data.length === 0) {
          registryList.innerHTML = '<li class="stream-item" style="color: var(--text-muted); font-size: 0.9rem; justify-content: center; padding: 1.5rem;">No streams registered yet.</li>';
          return;
        }

        data.forEach(stream => {
          const li = document.createElement('li');
          li.className = 'stream-item';

          let badgeClass = 'pending';
          if (stream.status === 'ready') badgeClass = 'ready';
          else if (stream.status === 'transcoding') badgeClass = 'transcoding';
          else if (stream.status === 'failed') badgeClass = 'failed';

          const createdDate = new Date(stream.created_at).toLocaleString();

          let actionsHtml = '';
          if (stream.status === 'ready') {
            actionsHtml = `<button class="btn-view" onclick="openPreviewModal('${stream.id}', '${escapeHtml(stream.title)}')">Play Stream</button>`;
          } else if (stream.status === 'failed') {
            actionsHtml = `<button class="btn-action" style="cursor: not-allowed; opacity: 0.5; background-color: var(--accent-ruby); color: #fff; border-color: transparent;">Failed</button>`;
          } else {
            actionsHtml = `<button class="btn-action" style="cursor: not-allowed; opacity: 0.7; color: var(--accent-blue); border-color: rgba(59, 130, 246, 0.4);">Transcoding</button>`;
          }

          li.innerHTML = `
            <div class="stream-meta">
              <div class="stream-title">${escapeHtml(stream.title)}</div>
              <div class="stream-subinfo">
                <span>ID: <code>${stream.id}</code></span>
                <span>•</span>
                <span>Date: ${createdDate}</span>
                <span>•</span>
                <span class="status-badge ${badgeClass}">${escapeHtml(stream.status)}</span>
              </div>
            </div>
            <div>
              ${actionsHtml}
            </div>
          `;

          registryList.appendChild(li);
        });
      })
      .catch(err => {
        registryList.innerHTML = `<li class="stream-item" style="color: var(--accent-ruby); font-size: 0.9rem; justify-content: center;">Failed to fetch catalog: ${err.message}</li>`;
      });
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  refreshCatalogList();
  refreshAdCampaignsTable();
  
  setInterval(refreshCatalogList, 5000);
});
