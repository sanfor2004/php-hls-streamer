/**
 * ZENITH-TIER CORE STATIC EMBEDDED PLAYER CONTROLS (player.js)
 * Fully refactored and modularized client-side player engine.
 */
const activeAdCampaigns = window.PLAYER_CONFIG.activeAdCampaigns || [];
const availableResolutions = window.PLAYER_CONFIG.availableResolutions || [];
const hlsPlaylistUrl = window.PLAYER_CONFIG.hlsPlaylistUrl || '';
const useCustomControls = window.PLAYER_CONFIG.useCustomControls;

document.addEventListener('DOMContentLoaded', () => {

  // --- DOM ELEMENT SELECTORS ---
  const telemetryContainer = document.querySelector('.telemetry-badge-container');
  const telemetryBtn = document.getElementById('telemetry-toggle-btn');
  const telemetryDrawer = document.getElementById('telemetry-drawer');
  const statsBuffer = document.getElementById('tele-buffer');
  const statsLatency = document.getElementById('stat-latency');
  const statsRes = document.getElementById('tele-res');
  const statsSpeed = document.getElementById('tele-speed');
  const statsState = document.getElementById('tele-state');

  const customControls = document.getElementById('custom-controls-hud');
  const customCenterPlay = document.getElementById('custom-center-play');
  const playMainBtn = document.getElementById('custom-play-main');

  // ==========================================================================
  // CORE PLAYER BOOTSTRAP — runs unconditionally regardless of USE_CUSTOM_CONTROLS
  // ==========================================================================


  const player = videojs('zenith-player', {
    html5: { vhs: { overrideNative: true } },
    // When custom controls are OFF, let Video.js render its native control bar
    controls: !useCustomControls,
    bigPlayButton: !useCustomControls
  });

  // AD SCHEDULER ENGINE
  const triggeredAdIds = new Set();
  let mainVideoDuration = 0;
  let imaInitialized = false;
  let imaContainerReady = false;
  const adHud = document.getElementById('ad-hud-banner');
  const adTimerLabel = document.getElementById('ad-countdown-timer');
  let adTimerInterval = null;

  let initialAdTag = '';
  if (activeAdCampaigns.length > 0) {
    const preroll = activeAdCampaigns.find(ad => ad.offset_type === 'preroll');
    initialAdTag = preroll ? preroll.vast_url : activeAdCampaigns[0].vast_url;
  }

  if (activeAdCampaigns.length > 0 && typeof player.ima === 'function') {
    try {
      player.ima({
        id: 'zenith-player',
        adTagUrl: initialAdTag,
        showControlsForAds: true,
        debug: false,
        requestAdsOnPlay: true
      });
      imaInitialized = true;
      console.log('[Ad Engine] IMA plugin initialized synchronously.');
      bindImaEvents();
    } catch (err) {
      console.error('[Ad Engine] IMA plugin init failed:', err);
    }
  } else if (activeAdCampaigns.length === 0) {
    console.info('[Ad Engine] No active campaigns — ad system idle.');
  } else {
    console.warn('[Ad Engine] videojs-ima not loaded — ads disabled.');
  }

  player.src({ src: hlsPlaylistUrl, type: 'application/x-mpegURL' });

  function bootstrapIma() {
    if (!imaInitialized || imaContainerReady) return;
    try {
      if (typeof player.ima.initializeAdContainer === 'function') {
        player.ima.initializeAdContainer();
        imaContainerReady = true;
        console.log('[Ad Engine] Ad container initialized on user gesture.');
      }
    } catch (err) {
      console.error('[Ad Engine] initializeAdContainer failed:', err);
    }
  }

  function bindImaEvents() {
    player.on('ads-ad-started', () => {
      adHud.classList.add('active');
      // Hide custom center play if it exists
      if (customCenterPlay) customCenterPlay.style.display = 'none';
      if (statsState) { statsState.textContent = 'PLAYING AD'; statsState.style.color = 'var(--accent-ruby)'; }
      clearInterval(adTimerInterval);
      adTimerInterval = setInterval(() => {
        try {
          if (player.ima && typeof player.ima.getAdsManager === 'function') {
            const mgr = player.ima.getAdsManager();
            if (mgr && mgr.getCurrentAd()) {
              const rem = Math.round(mgr.getRemainingTime());
              adTimerLabel.textContent = rem > 0 ? `Ad ends in ${rem}s` : 'Ad playing...';
              return;
            }
          }
        } catch (e) { /* silent */ }
        clearInterval(adTimerInterval);
      }, 500);
    });

    const onAdFinished = () => {
      clearInterval(adTimerInterval);
      adHud.classList.remove('active');
      if (customCenterPlay) customCenterPlay.style.display = 'flex';
      resetAdState();
    };
    player.on('ads-ad-ended', onAdFinished);
    player.on('ads-ad-skipped', onAdFinished);
    player.on('adserror', (evt) => {
      console.error('[Ad Engine] Ad error event:', evt);
      clearInterval(adTimerInterval);
      adHud.classList.remove('active');
      if (customCenterPlay) customCenterPlay.style.display = 'flex';
      resetAdState();
      if (player.paused()) player.play();
    });
  }

  function resetAdState() {
    if (statsState) { statsState.textContent = 'READY'; statsState.style.color = 'var(--accent-orange)'; }
  }

  function executeVastAdInjection(vastTagUrl) {
    if (!imaInitialized || typeof player.ima !== 'object' || !player.ima) {
      console.warn('[Ad Engine] IMA not ready for injection, skipping ad.');
      return;
    }
    console.log('[Ad Engine] Injecting VAST midroll/postroll:', vastTagUrl);
    player.pause();
    if (statsState) { statsState.textContent = 'PLAYING AD'; statsState.style.color = 'var(--accent-ruby)'; }
    try {
      if (typeof player.ima.changeAdTag === 'function') player.ima.changeAdTag(vastTagUrl);
      if (typeof player.ima.requestAds === 'function') player.ima.requestAds();
    } catch (err) {
      console.error('[Ad Engine] Midroll injection failed:', err);
      player.play();
      resetAdState();
    }
  }

  function parseAdOffsetToSeconds(offsetString, videoDuration) {
    offsetString = String(offsetString).trim();
    if (offsetString.endsWith('%')) return videoDuration * (parseFloat(offsetString) / 100);
    if (offsetString.includes(':')) {
      const parts = offsetString.split(':');
      if (parts.length >= 3) return (parseFloat(parts[0]) || 0) * 3600 + (parseFloat(parts[1]) || 0) * 60 + (parseFloat(parts[2]) || 0);
    }
    return parseFloat(offsetString) || 0;
  }

  // Guard: only wire bespoke controls when they are rendered (USE_CUSTOM_CONTROLS = true)
  if (playMainBtn) {

    // --- CUSTOM CONTROLS DOM ELEMENT SELECTORS ---
    const timeCurrent = document.getElementById('custom-time-current');
    const timeTotal = document.getElementById('custom-time-total');
    const scrubFill = document.getElementById('custom-scrub-fill');
    const scrubHandle = document.getElementById('custom-scrub-handle');
    const skipBackBtn = document.getElementById('custom-skip-back');
    const skipForwardBtn = document.getElementById('custom-skip-forward');
    const playMainIcon = playMainBtn ? playMainBtn.querySelector('.play-icon') : null;
    const pauseMainIcon = playMainBtn ? playMainBtn.querySelector('.pause-icon') : null;
    const scrubberContainer = document.getElementById('custom-scrubber');
    const scrubTrack = document.getElementById('custom-scrub-track');
    const settingsDeck = document.getElementById('settings-deck-panel');
    const ccPopup = document.getElementById('cc-popup-panel');
    const ccPopupList = document.getElementById('cc-popup-list');
    const ccBtn = document.getElementById('custom-cc-btn');
    const audioBtn = document.getElementById('custom-audio-btn');
    const audioPopup = document.getElementById('audio-popup-panel');
    const audioPopupList = document.getElementById('audio-popup-list');
    const muteBtn = document.getElementById('custom-mute');
    const volumeSlider = document.getElementById('custom-volume-slider');
    const fullscreenBtn = document.getElementById('custom-fullscreen-btn');
    const settingsBtn = document.getElementById('custom-settings-btn');
    const volMuteIcon = muteBtn ? muteBtn.querySelector('.vol-mute-icon') : null;
    const volLowIcon = muteBtn ? muteBtn.querySelector('.vol-low-icon') : null;
    const volHighIcon = muteBtn ? muteBtn.querySelector('.vol-high-icon') : null;
    const sliderTrack = document.getElementById('deck-slider-track');
    const detailsTitle = document.getElementById('details-panel-title');
    const detailsList = document.getElementById('details-panel-list');
    const labelActiveQual = document.getElementById('label-active-quality');
    const labelActiveSpeed = document.getElementById('label-active-speed');
    const labelActiveAudio = document.getElementById('label-active-audio');
    const labelActiveSub = document.getElementById('label-active-sub');

    // ── PLAYER LIFECYCLE EVENTS (Custom Controls) ────────────────────────────

    player.on('loadedmetadata', () => {
      mainVideoDuration = player.duration();
      timeTotal.textContent = formatTime(mainVideoDuration);
      populateCcPopup();
      populateAudioPopup();
    });

    player.on('durationchange', () => {
      mainVideoDuration = player.duration();
      timeTotal.textContent = formatTime(mainVideoDuration);
    });

    player.on('timeupdate', () => {
      const currentTime = player.currentTime();
      if (mainVideoDuration <= 0) return;

      timeCurrent.textContent = formatTime(currentTime);

      // Progress bar synchronization
      if (!isDraggingScrubber) {
        const pct = (currentTime / mainVideoDuration) * 100;
        scrubFill.style.width = `${pct}%`;
        scrubHandle.style.left = `${pct}%`;
      }

      // Buffer health (for telemetry drawer)
      const buffered = player.buffered();
      if (buffered.length > 0) {
        const bufEnd = buffered.end(buffered.length - 1);
        const health = Math.max(0, bufEnd - currentTime);
        if (statsBuffer) statsBuffer.textContent = `${health.toFixed(1)}s`;
      }

      // Midroll scheduling
      activeAdCampaigns.forEach(ad => {
        if (triggeredAdIds.has(ad.id) || ad.offset_type !== 'midroll') return;
        const triggerAt = parseAdOffsetToSeconds(ad.offset_value, mainVideoDuration);
        if (currentTime >= triggerAt && currentTime <= triggerAt + 1.5) {
          triggeredAdIds.add(ad.id);
          executeVastAdInjection(ad.vast_url);
        }
      });
    });

    function parseAdOffsetToSeconds(offsetString, videoDuration) {
      offsetString = String(offsetString).trim();
      if (offsetString.endsWith('%')) {
        return videoDuration * (parseFloat(offsetString) / 100);
      }
      if (offsetString.includes(':')) {
        const parts = offsetString.split(':');
        if (parts.length >= 3) {
          return (parseFloat(parts[0]) || 0) * 3600
            + (parseFloat(parts[1]) || 0) * 60
            + (parseFloat(parts[2]) || 0);
        }
      }
      return parseFloat(offsetString) || 0;
    }

    // =========================================================================
    // COGNITIVE BINDINGS: CUSTOMBESPOKE CONTROLS PIPELINES
    // =========================================================================

    // Stop event propagation to prevent Video.js from capturing clicks and pausing the stream
    customControls.addEventListener('click', (e) => e.stopPropagation());
    customControls.addEventListener('mousedown', (e) => e.stopPropagation());
    customControls.addEventListener('touchstart', (e) => e.stopPropagation());

    customCenterPlay.addEventListener('click', (e) => e.stopPropagation());
    customCenterPlay.addEventListener('mousedown', (e) => e.stopPropagation());
    customCenterPlay.addEventListener('touchstart', (e) => e.stopPropagation());

    settingsDeck.addEventListener('click', (e) => e.stopPropagation());
    settingsDeck.addEventListener('mousedown', (e) => e.stopPropagation());
    settingsDeck.addEventListener('touchstart', (e) => e.stopPropagation());

    ccPopup.addEventListener('click', (e) => e.stopPropagation());
    ccPopup.addEventListener('mousedown', (e) => e.stopPropagation());
    ccPopup.addEventListener('touchstart', (e) => e.stopPropagation());

    audioPopup.addEventListener('click', (e) => e.stopPropagation());
    audioPopup.addEventListener('mousedown', (e) => e.stopPropagation());
    audioPopup.addEventListener('touchstart', (e) => e.stopPropagation());

    if (telemetryContainer) {
      telemetryContainer.addEventListener('click', (e) => e.stopPropagation());
      telemetryContainer.addEventListener('mousedown', (e) => e.stopPropagation());
      telemetryContainer.addEventListener('touchstart', (e) => e.stopPropagation());
    }

    // ── 1. PLAY / PAUSE ──────────────────────────────────────────────────────
    /**
     * Toggle playback AND bootstrap IMA on the very first user gesture.
     * IMA's initializeAdContainer MUST be called synchronously inside a
     * click/touchstart handler — browsers block ad playback otherwise.
     */
    function togglePlayback() {
      // On first click: bootstrap IMA (no-op if already done)
      bootstrapIma();

      if (player.paused()) {
        player.play().catch(err => {
          console.warn('[Player] play() rejected:', err);
        });
      } else {
        player.pause();
      }
    }

    // Also bootstrap IMA on any click/touch on the player wrapper (safety net)
    const wrapperEl = document.querySelector('.player-wrapper');
    if (wrapperEl) {
      const gestureBootstrap = () => {
        bootstrapIma();
        // Remove self only once IMA is initialized to avoid extra overhead
        if (imaInitialized) {
          wrapperEl.removeEventListener('click', gestureBootstrap);
          wrapperEl.removeEventListener('touchstart', gestureBootstrap);
        }
      };
      wrapperEl.addEventListener('click', gestureBootstrap, { passive: true });
      wrapperEl.addEventListener('touchstart', gestureBootstrap, { passive: true });
    }

    customCenterPlay.addEventListener('click', togglePlayback);
    playMainBtn.addEventListener('click', togglePlayback);

    // ── Sync play/pause button visual state ──────────────────────────────────
    function syncPlayBtnState(playing) {
      if (playing) {
        playMainBtn.classList.add('is-playing');
        playMainIcon.style.display = 'none';
        pauseMainIcon.style.display = 'block';
      } else {
        playMainBtn.classList.remove('is-playing');
        playMainIcon.style.display = 'block';
        pauseMainIcon.style.display = 'none';
      }
    }

    // ── Ripple helper ─────────────────────────────────────────────────────────
    function firePlayBtnRipple(e) {
      const btn = playMainBtn;
      const rect = btn.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const x = (e.clientX - rect.left) - size / 2;
      const y = (e.clientY - rect.top) - size / 2;

      const span = document.createElement('span');
      span.className = 'ripple';
      span.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px;`;
      btn.appendChild(span);
      span.addEventListener('animationend', () => span.remove(), { once: true });
    }

    playMainBtn.addEventListener('click', firePlayBtnRipple);

    player.on('play', () => {
      customCenterPlay.style.opacity = '0';
      customCenterPlay.style.pointerEvents = 'none';
      customCenterPlay.style.transform = 'translate(-50%, -50%) scale(0.85)';
      syncPlayBtnState(true);
    });

    player.on('pause', () => {
      customCenterPlay.style.opacity = '1';
      customCenterPlay.style.pointerEvents = 'auto';
      customCenterPlay.style.transform = 'translate(-50%, -50%) scale(1)';
      syncPlayBtnState(false);
    });

    player.on('ended', () => {
      // Postroll ad injection
      const postrollAd = activeAdCampaigns.find(ad => ad.offset_type === 'postroll');
      if (postrollAd && !triggeredAdIds.has(postrollAd.id) && imaInitialized) {
        triggeredAdIds.add(postrollAd.id);
        executeVastAdInjection(postrollAd.vast_url);
      }
    });

    // 2. Skip backward / forward 10 seconds
    skipBackBtn.addEventListener('click', () => {
      player.currentTime(Math.max(0, player.currentTime() - 10));
    });

    skipForwardBtn.addEventListener('click', () => {
      player.currentTime(Math.min(mainVideoDuration, player.currentTime() + 10));
    });

    // 3. Scrubbing/Seeking timeline
    let isDraggingScrubber = false;

    scrubberContainer.addEventListener('mousedown', (e) => {
      isDraggingScrubber = true;
      customControls.classList.add('active-focus');
      scrub(e);
    });

    document.addEventListener('mousemove', (e) => {
      if (isDraggingScrubber) {
        scrub(e);
      }
    });

    document.addEventListener('mouseup', () => {
      if (isDraggingScrubber) {
        isDraggingScrubber = false;
        customControls.classList.remove('active-focus');
      }
    });

    // Support touch scrubbing
    scrubberContainer.addEventListener('touchstart', (e) => {
      isDraggingScrubber = true;
      customControls.classList.add('active-focus');
      scrub(e.touches[0]);
    });

    document.addEventListener('touchmove', (e) => {
      if (isDraggingScrubber) {
        scrub(e.touches[0]);
      }
    });

    document.addEventListener('touchend', () => {
      if (isDraggingScrubber) {
        isDraggingScrubber = false;
        customControls.classList.remove('active-focus');
      }
    });

    function scrub(e) {
      const rect = scrubTrack.getBoundingClientRect();
      let percentage = (e.clientX - rect.left) / rect.width;
      percentage = Math.max(0, Math.min(1, percentage));

      scrubFill.style.width = `${percentage * 100}%`;
      scrubHandle.style.left = `${percentage * 100}%`;

      if (mainVideoDuration > 0) {
        player.currentTime(percentage * mainVideoDuration);
      }
    }

    // 4. Volume mute / slider
    volumeSlider.addEventListener('input', () => {
      const vol = parseFloat(volumeSlider.value);
      player.volume(vol);
      player.muted(vol === 0);
    });

    muteBtn.addEventListener('click', () => {
      if (player.muted()) {
        player.muted(false);
        player.volume(volumeSlider.value || 0.8);
      } else {
        player.muted(true);
      }
    });

    player.on('volumechange', () => {
      const vol = player.volume();
      const isMuted = player.muted();

      // Add dynamic micro-bounce animation
      muteBtn.classList.remove('animate-pulse-bounce');
      void muteBtn.offsetWidth; // Trigger reflow to restart animation
      muteBtn.classList.add('animate-pulse-bounce');

      if (volMuteIcon) volMuteIcon.style.display = 'none';
      if (volLowIcon) volLowIcon.style.display = 'none';
      if (volHighIcon) volHighIcon.style.display = 'none';

      if (isMuted || vol === 0) {
        volumeSlider.value = 0;
        if (volMuteIcon) volMuteIcon.style.display = 'block';
      } else {
        volumeSlider.value = vol;
        if (vol <= 0.4) {
          if (volLowIcon) volLowIcon.style.display = 'block';
        } else {
          if (volHighIcon) volHighIcon.style.display = 'block';
        }

        // Animate: scale active icon based on volume level
        const activeIcon = vol <= 0.4 ? volLowIcon : volHighIcon;
        if (activeIcon) {
          activeIcon.style.transform = `scale(${0.85 + vol * 0.25})`;
          activeIcon.style.transition = 'transform 0.15s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
        }
      }
    });

    // 5. Brackets Fullscreen
    fullscreenBtn.addEventListener('click', () => {
      if (player.isFullscreen()) {
        player.exitFullscreen();
      } else {
        player.requestFullscreen();
      }
    });

    // 6. Subtitle (CC) popup toggle
    ccBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      closeDeck();
      ccBtn.classList.toggle('active');
      ccPopup.classList.toggle('active');
      if (ccPopup.classList.contains('active')) {
        customControls.classList.add('active-focus');
      } else {
        customControls.classList.remove('active-focus');
      }
    });

    // 6.5. Audio Language popup toggle
    audioBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      closeDeck();
      // Reset CC popup specifically
      ccPopup.classList.remove('active');
      ccBtn.classList.remove('active');

      audioBtn.classList.toggle('active');
      audioPopup.classList.toggle('active');
      if (audioPopup.classList.contains('active')) {
        customControls.classList.add('active-focus');
      } else {
        customControls.classList.remove('active-focus');
      }
    });

    // 7. Settings Gear popup toggle
    settingsBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      ccPopup.classList.remove('active');
      ccBtn.classList.remove('active');
      audioPopup.classList.remove('active');
      audioBtn.classList.remove('active');

      if (!settingsDeck.classList.contains('active')) {
        settingsDeck.classList.add('active');
        settingsBtn.classList.add('active');
        customControls.classList.add('active-focus');
        slideDeckBack();
        rebuildSystemChecks();
      } else {
        closeDeck();
      }
    });

    function closeDeck() {
      settingsDeck.classList.remove('active');
      settingsBtn.classList.remove('active');
      ccPopup.classList.remove('active');
      ccBtn.classList.remove('active');
      audioPopup.classList.remove('active');
      audioBtn.classList.remove('active');
      customControls.classList.remove('active-focus');
    }

    // Live Telemetry Badge events
    if (telemetryBtn && telemetryDrawer) {
      telemetryBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        telemetryBtn.classList.toggle('active');
        telemetryDrawer.classList.toggle('active');
      });
    }

    // Close setting panel and CC popup if clicked outside
    document.addEventListener('click', (e) => {
      if (telemetryBtn && telemetryDrawer && !e.target.closest('#telemetry-toggle-btn') && !e.target.closest('#telemetry-drawer')) {
        telemetryBtn.classList.remove('active');
        telemetryDrawer.classList.remove('active');
      }
      if (!e.target.closest('#custom-cc-btn') && !e.target.closest('#cc-popup-panel')) {
        ccPopup.classList.remove('active');
        ccBtn.classList.remove('active');
      }
      if (!e.target.closest('#custom-audio-btn') && !e.target.closest('#audio-popup-panel')) {
        audioPopup.classList.remove('active');
        audioBtn.classList.remove('active');
      }
      if (!e.target.closest('#custom-settings-btn') && !e.target.closest('#settings-deck-panel')) {
        closeDeck();
      }
    });

    // =========================================================================
    // DYNAMIC ATTACHMENTS INTO PLAYER DOM FRAME (Z-INDEX PRESERVATION)
    // =========================================================================
    let currentHeightTarget = 'auto';
    let currentSpeedTarget = 1.0;
    let activeSubmenuType = '';

    player.ready(() => {
      const playerEl = player.el();

      // Inject all floating control layers inside Video.js to maintain fullscreen bounds
      playerEl.appendChild(customControls);
      playerEl.appendChild(customCenterPlay);
      playerEl.appendChild(settingsDeck);
      playerEl.appendChild(ccPopup);
      playerEl.appendChild(audioPopup);
      if (telemetryContainer) {
        playerEl.appendChild(telemetryContainer);
      }

      // Observe quality levels
      if (typeof player.qualityLevels === 'function') {
        const qualityLevels = player.qualityLevels();
        if (qualityLevels) {
          qualityLevels.on('change', () => {
            const index = qualityLevels.selectedIndex;
            if (index !== -1 && qualityLevels[index]) {
              const currentH = qualityLevels[index].height;
              if (statsRes) {
                statsRes.textContent = `${currentH}p`;
              }
              if (currentHeightTarget === 'auto') {
                labelActiveQual.textContent = `Auto (${currentH}p)`;
              }
            }
          });
        }
      } else {
        console.warn('[Quality Levels] qualityLevels method not available on player.');
      }

      // Observe audio tracks changes
      const playerAudioTracks = typeof player.audioTracks === 'function' ? player.audioTracks() : null;
      if (playerAudioTracks) {
        playerAudioTracks.on('addtrack', populateAudioPopup);
        playerAudioTracks.on('removetrack', populateAudioPopup);
      }

      // Cue point generators on progress bar on play commencement
      player.on('loadedmetadata', () => {
        const duration = player.duration();
        if (duration <= 0) return;

        activeAdCampaigns.forEach(ad => {
          if (ad.offset_type === 'midroll') {
            const seconds = parseAdOffsetToSeconds(ad.offset_value, duration);
            const percentage = (seconds / duration) * 100;

            if (percentage > 0 && percentage < 100) {
              const cue = document.createElement('div');
              cue.className = 'vjs-ad-cuepoint';
              cue.style.left = `${percentage}%`;
              scrubTrack.appendChild(cue);
            }
          }
        });
      });
    });

    // Recheck optional tracks (Audio, Subtitles)
    function rebuildSystemChecks() {
      const audioTracks = typeof player.audioTracks === 'function' ? player.audioTracks() : null;
      const textTracks = typeof player.textTracks === 'function' ? player.textTracks() : null;

      // Audio Checks
      const itemAudio = document.getElementById('menu-item-audio');
      const audioChevron = itemAudio.querySelector('.audio-chevron-icon');

      itemAudio.style.display = 'flex';

      if (audioTracks && audioTracks.length > 1) {
        // Enable: normal cursor, chevron visible, clickable
        itemAudio.style.cursor = 'pointer';
        itemAudio.style.opacity = '1';
        itemAudio.onclick = () => slideDeckToPanel('audio');
        if (audioChevron) audioChevron.style.display = 'block';

        let activeLbl = 'Primary';
        for (let i = 0; i < audioTracks.length; i++) {
          if (audioTracks[i].enabled) activeLbl = audioTracks[i].label || audioTracks[i].language;
        }
        labelActiveAudio.textContent = capitalizeFirstLetter(activeLbl);
      } else {
        // Disable: muted appearance, no chevron, click disabled
        itemAudio.style.cursor = 'default';
        itemAudio.style.opacity = '0.45';
        itemAudio.onclick = null;
        if (audioChevron) audioChevron.style.display = 'none';
        labelActiveAudio.textContent = 'Primary';
      }

      // CC Subtitles Checks — always visible; clickable only when tracks exist
      const itemSub = document.getElementById('menu-item-subtitles');
      const subChevron = itemSub.querySelector('.sub-chevron-icon');
      let hasSubs = false;
      let activeSubLbl = 'Off';
      if (textTracks) {
        for (let i = 0; i < textTracks.length; i++) {
          if (textTracks[i].kind === 'subtitles' || textTracks[i].kind === 'captions') {
            hasSubs = true;
            if (textTracks[i].mode === 'showing') {
              activeSubLbl = textTracks[i].label || textTracks[i].language;
            }
          }
        }
      }
      // Always show the item
      itemSub.style.display = 'flex';
      if (hasSubs) {
        // Enable: normal colour, chevron visible, clickable
        itemSub.style.cursor = 'pointer';
        itemSub.style.opacity = '1';
        itemSub.onclick = () => slideDeckToPanel('subtitles');
        labelActiveSub.textContent = capitalizeFirstLetter(activeSubLbl);
        if (subChevron) subChevron.style.display = 'block';
      } else {
        // Disable: muted appearance, no chevron, no click
        itemSub.style.cursor = 'default';
        itemSub.style.opacity = '0.45';
        itemSub.onclick = () => slideDeckToPanel('subtitles'); // still opens panel for "none" message
        labelActiveSub.textContent = 'None';
        if (subChevron) subChevron.style.display = 'none';
      }
    }

    // Populate Closed Captions CC dropdown dynamically
    function populateCcPopup() {
      ccPopupList.innerHTML = '';
      const textTracks = typeof player.textTracks === 'function' ? player.textTracks() : null;
      const subTracks = [];

      if (textTracks) {
        for (let i = 0; i < textTracks.length; i++) {
          const track = textTracks[i];
          if (track.kind === 'subtitles' || track.kind === 'captions') {
            subTracks.push({ index: i, track: track });
          }
        }
      }

      if (subTracks.length === 0) {
        ccBtn.style.display = 'none';
        return;
      } else {
        ccBtn.style.display = 'flex';
      }

      let isAnyShowing = false;
      subTracks.forEach(item => {
        if (item.track.mode === 'showing') isAnyShowing = true;
      });

      // Add Off item
      const offLi = document.createElement('li');
      offLi.className = `deck-item ${!isAnyShowing ? 'active' : ''}`;
      offLi.innerHTML = `
          <div class="deck-item-left">
            <span>Off</span>
          </div>
          <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
        `;
      offLi.addEventListener('click', () => {
        subTracks.forEach(item => item.track.mode = 'disabled');
        ccBtn.classList.remove('active');
        labelActiveSub.textContent = 'Off';
        populateCcPopup();
        ccPopup.classList.remove('active');
        customControls.classList.remove('active-focus');
      });
      ccPopupList.appendChild(offLi);

      // Add each discovered subtitle translation script track!
      subTracks.forEach(item => {
        const track = item.track;
        const li = document.createElement('li');
        const isShowing = track.mode === 'showing';
        li.className = `deck-item ${isShowing ? 'active' : ''}`;
        const label = track.label || track.language || `Translation Track ${item.index + 1}`;

        li.innerHTML = `
            <div class="deck-item-left">
              <span>${capitalizeFirstLetter(label)}</span>
            </div>
            <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
          `;
        li.addEventListener('click', () => {
          subTracks.forEach(sub => {
            sub.track.mode = (sub.index === item.index) ? 'showing' : 'disabled';
          });
          ccBtn.classList.add('active');
          labelActiveSub.textContent = capitalizeFirstLetter(label);
          populateCcPopup();
          ccPopup.classList.remove('active');
          customControls.classList.remove('active-focus');
        });
        ccPopupList.appendChild(li);
      });
    }

    // Populate Audio Tracks dropdown dynamically
    function populateAudioPopup() {
      audioPopupList.innerHTML = '';
      const audioTracks = typeof player.audioTracks === 'function' ? player.audioTracks() : null;
      const discoveredTracks = [];

      if (audioTracks) {
        for (let i = 0; i < audioTracks.length; i++) {
          discoveredTracks.push({ index: i, track: audioTracks[i] });
        }
      }

      // Only display the main bar audio button if we actually have multiple tracks to switch between
      if (discoveredTracks.length <= 1) {
        audioBtn.style.display = 'none';
        return;
      } else {
        audioBtn.style.display = 'flex';
      }

      discoveredTracks.forEach(item => {
        const track = item.track;
        const li = document.createElement('li');
        const isEnabled = track.enabled;
        li.className = `deck-item ${isEnabled ? 'active' : ''}`;
        const label = track.label || track.language || `Track ${item.index + 1}`;

        li.innerHTML = `
              <div class="deck-item-left">
                <span>${capitalizeFirstLetter(label)}</span>
              </div>
              <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            `;

        li.addEventListener('click', () => {
          for (let j = 0; j < audioTracks.length; j++) {
            audioTracks[j].enabled = (j === item.index);
          }
          audioBtn.classList.add('active');
          labelActiveAudio.textContent = capitalizeFirstLetter(label);
          populateAudioPopup();
          audioPopup.classList.remove('active');
          customControls.classList.remove('active-focus');
        });
        audioPopupList.appendChild(li);
      });
    }

    // Settings navigation triggers
    window.slideDeckToPanel = function (type) {
      activeSubmenuType = type;
      populateDetailsPanel(type);
      sliderTrack.style.transform = 'translateX(-50%)';
    };

    window.slideDeckBack = function () {
      sliderTrack.style.transform = 'translateX(0)';
    };

    function populateDetailsPanel(type) {
      detailsList.innerHTML = '';

      if (type === 'quality') {
        detailsTitle.textContent = 'Select Quality';

        const autoLi = document.createElement('li');
        autoLi.className = `deck-item ${currentHeightTarget === 'auto' ? 'active' : ''}`;

        let autoLabelText = 'Auto (Adaptive)';
        if (typeof player.qualityLevels === 'function') {
          const qLevels = player.qualityLevels();
          if (currentHeightTarget === 'auto' && qLevels && qLevels.selectedIndex !== -1 && qLevels[qLevels.selectedIndex]) {
            autoLabelText = `Auto (${qLevels[qLevels.selectedIndex].height}p)`;
          }
        }

        autoLi.innerHTML = `
            <div class="deck-item-left">
              <span>${autoLabelText}</span>
            </div>
            <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
          `;
        autoLi.addEventListener('click', () => {
          currentHeightTarget = 'auto';
          setManualQualityLevel('auto');

          // Re-evaluate auto label text dynamically
          let autoLabelText = 'Auto (Adaptive)';
          if (typeof player.qualityLevels === 'function') {
            const qLevels = player.qualityLevels();
            if (qLevels && qLevels.selectedIndex !== -1 && qLevels[qLevels.selectedIndex]) {
              autoLabelText = `Auto (${qLevels[qLevels.selectedIndex].height}p)`;
            }
          }
          const labelSpan = autoLi.querySelector('span');
          if (labelSpan) labelSpan.textContent = autoLabelText;
          labelActiveQual.textContent = 'Auto';

          // Transfer active class immediately in the DOM
          const items = detailsList.querySelectorAll('.deck-item');
          items.forEach(item => item.classList.remove('active'));
          autoLi.classList.add('active');

          setTimeout(() => {
            slideDeckBack();
            closeDeck();
          }, 200);
        });
        detailsList.appendChild(autoLi);

        // Build transcode resolutions
        const sortedLevels = [];
        availableResolutions.forEach(resStr => {
          const hVal = parseInt(resStr);
          if (hVal && !sortedLevels.includes(hVal)) {
            sortedLevels.push(hVal);
          }
        });
        sortedLevels.sort((a, b) => b - a);

        sortedLevels.forEach(height => {
          const li = document.createElement('li');
          li.className = `deck-item ${String(currentHeightTarget) === String(height) ? 'active' : ''}`;
          const isHd = height >= 720;
          const badgeHtml = isHd ? '<span class="hd-badge" style="border-color: var(--accent-orange); color: var(--accent-orange); background: rgba(255,122,0,0.12);">HD</span>' : '';

          li.innerHTML = `
              <div class="deck-item-left">
                <span>${height}p</span>
                ${badgeHtml}
              </div>
              <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            `;
          li.addEventListener('click', () => {
            currentHeightTarget = height;
            setManualQualityLevel(height);
            labelActiveQual.textContent = `${height}p`;

            // Transfer active class immediately in the DOM
            const items = detailsList.querySelectorAll('.deck-item');
            items.forEach(item => item.classList.remove('active'));
            li.classList.add('active');

            setTimeout(() => {
              slideDeckBack();
              closeDeck();
            }, 200);
          });
          detailsList.appendChild(li);
        });

      } else if (type === 'speed') {
        detailsTitle.textContent = 'Playback Speed';
        const speeds = [0.5, 1.0, 1.25, 1.5, 2.0];

        speeds.forEach(speed => {
          const li = document.createElement('li');
          li.className = `deck-item ${String(currentSpeedTarget) === String(speed) ? 'active' : ''}`;
          const lblText = speed === 1.0 ? 'Normal' : `${speed}x`;

          li.innerHTML = `
              <div class="deck-item-left">
                <span>${lblText}</span>
              </div>
              <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            `;
          li.addEventListener('click', () => {
            currentSpeedTarget = speed;
            player.playbackRate(speed);
            labelActiveSpeed.textContent = speed === 1.0 ? 'Normal' : `${speed}x`;
            if (statsSpeed) {
              statsSpeed.textContent = `${speed}x`;
            }

            // Transfer active class immediately in the DOM
            const items = detailsList.querySelectorAll('.deck-item');
            items.forEach(item => item.classList.remove('active'));
            li.classList.add('active');

            setTimeout(() => {
              slideDeckBack();
              closeDeck();
            }, 200);
          });
          detailsList.appendChild(li);
        });

      } else if (type === 'audio') {
        detailsTitle.textContent = 'Audio Language';
        const audioTracks = typeof player.audioTracks === 'function' ? player.audioTracks() : null;

        if (audioTracks) {
          for (let i = 0; i < audioTracks.length; i++) {
            const track = audioTracks[i];
            const li = document.createElement('li');
            li.className = `deck-item ${track.enabled ? 'active' : ''}`;
            const label = track.label || track.language || `Audio ${i + 1}`;

            li.innerHTML = `
                <div class="deck-item-left">
                  <span>${capitalizeFirstLetter(label)}</span>
                </div>
                <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
              `;
            li.addEventListener('click', () => {
              for (let j = 0; j < audioTracks.length; j++) {
                audioTracks[j].enabled = (j === i);
              }
              labelActiveAudio.textContent = capitalizeFirstLetter(label);

              // Transfer active class immediately in the DOM
              const items = detailsList.querySelectorAll('.deck-item');
              items.forEach(item => item.classList.remove('active'));
              li.classList.add('active');

              setTimeout(() => {
                slideDeckBack();
                closeDeck();
              }, 200);
            });
            detailsList.appendChild(li);
          }
        }

      } else if (type === 'subtitles') {
        detailsTitle.textContent = 'Subtitles';
        const textTracks = typeof player.textTracks === 'function' ? player.textTracks() : null;
        const subtitleTracks = [];

        if (textTracks) {
          for (let i = 0; i < textTracks.length; i++) {
            const track = textTracks[i];
            if (track.kind === 'subtitles' || track.kind === 'captions') {
              subtitleTracks.push({ index: i, track: track });
            }
          }
        }

        // ── Empty state — no subtitle tracks in this stream ────────────────
        if (subtitleTracks.length === 0) {
          const emptyLi = document.createElement('li');
          emptyLi.style.cssText = [
            'display:flex', 'flex-direction:column', 'align-items:center',
            'justify-content:center', 'gap:8px', 'padding:22px 12px',
            'cursor:default', 'pointer-events:none'
          ].join(';');
          emptyLi.innerHTML = `
              <svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:var(--text-muted);opacity:0.5;">
                <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-4H6V8h12v4z"/>
              </svg>
              <span style="font-family:var(--font-display);font-size:0.75rem;font-weight:600;
                           color:var(--text-muted);text-align:center;line-height:1.4;">
                No subtitle for this content
              </span>
            `;
          detailsList.appendChild(emptyLi);
          return;
        }

        // ── Normal state — subtitle tracks available ────────────────────
        let isAnyShowing = false;
        subtitleTracks.forEach(item => {
          if (item.track.mode === 'showing') isAnyShowing = true;
        });

        const offLi = document.createElement('li');
        offLi.className = `deck-item ${!isAnyShowing ? 'active' : ''}`;
        offLi.innerHTML = `
            <div class="deck-item-left">
              <span>Off</span>
            </div>
            <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
          `;
        offLi.addEventListener('click', () => {
          subtitleTracks.forEach(item => item.track.mode = 'disabled');
          labelActiveSub.textContent = 'Off';
          ccBtn.classList.remove('active');
          populateCcPopup();
          const items = detailsList.querySelectorAll('.deck-item');
          items.forEach(item => item.classList.remove('active'));
          offLi.classList.add('active');
          setTimeout(() => { slideDeckBack(); closeDeck(); }, 200);
        });
        detailsList.appendChild(offLi);

        subtitleTracks.forEach(item => {
          const track = item.track;
          const li = document.createElement('li');
          li.className = `deck-item ${track.mode === 'showing' ? 'active' : ''}`;
          const label = track.label || track.language || `Subtitles ${item.index + 1}`;
          li.innerHTML = `
              <div class="deck-item-left">
                <span>${capitalizeFirstLetter(label)}</span>
              </div>
              <svg class="check-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            `;
          li.addEventListener('click', () => {
            subtitleTracks.forEach(sub => {
              sub.track.mode = (sub.index === item.index) ? 'showing' : 'disabled';
            });
            labelActiveSub.textContent = capitalizeFirstLetter(label);
            ccBtn.classList.add('active');
            populateCcPopup();
            const items = detailsList.querySelectorAll('.deck-item');
            items.forEach(item => item.classList.remove('active'));
            li.classList.add('active');
            setTimeout(() => { slideDeckBack(); closeDeck(); }, 200);
          });
          detailsList.appendChild(li);
        });
      }
    }

  } // end if (playMainBtn) — custom controls guard

  // ── NATIVE CONTROLS SELECTORS (USE_CUSTOM_CONTROLS = false) ──
  if (!playMainBtn) {
    player.ready(() => {
      const controlBarEl = player.controlBar ? player.controlBar.el() : null;
      if (controlBarEl) {

        // 1. Sleek Quality Selector Dropdown
        if (availableResolutions.length > 0) {
          const selectWrapper = document.createElement('div');
          selectWrapper.className = 'vjs-control vjs-quality-selector-container';

          const selectEl = document.createElement('select');
          selectEl.className = 'vjs-quality-select-dropdown';
          selectEl.title = 'Select Quality';

          const autoOpt = document.createElement('option');
          autoOpt.value = 'auto';
          autoOpt.textContent = 'Auto';
          selectEl.appendChild(autoOpt);

          const sortedLevels = [...availableResolutions]
            .map(parseInt)
            .filter(h => !isNaN(h))
            .sort((a, b) => b - a);

          sortedLevels.forEach(height => {
            const opt = document.createElement('option');
            opt.value = height;
            opt.textContent = `${height}p`;
            selectEl.appendChild(opt);
          });

          selectEl.addEventListener('change', () => {
            setManualQualityLevel(selectEl.value);
          });

          selectWrapper.appendChild(selectEl);

          const fsControl = controlBarEl.querySelector('.vjs-fullscreen-control');
          if (fsControl) {
            controlBarEl.insertBefore(selectWrapper, fsControl);
          } else {
            controlBarEl.appendChild(selectWrapper);
          }
        }

        // 2. Sleek Audio Track Selector Dropdown
        const audioTracks = typeof player.audioTracks === 'function' ? player.audioTracks() : null;
        if (audioTracks) {
          let audioWrapper = null;

          const rebuildAudioDropdown = () => {
            // Clear any existing dropdown
            if (audioWrapper) {
              audioWrapper.remove();
              audioWrapper = null;
            }

            if (audioTracks.length > 1) {
              audioWrapper = document.createElement('div');
              audioWrapper.className = 'vjs-control vjs-audio-selector-container';

              const selectEl = document.createElement('select');
              selectEl.className = 'vjs-audio-select-dropdown';
              selectEl.title = 'Select Audio Language';

              for (let i = 0; i < audioTracks.length; i++) {
                const track = audioTracks[i];
                const opt = document.createElement('option');
                opt.value = i;
                opt.textContent = capitalizeFirstLetter(track.label || track.language || `Track ${i + 1}`);
                opt.selected = track.enabled;
                selectEl.appendChild(opt);
              }

              selectEl.addEventListener('change', () => {
                const selectedIdx = parseInt(selectEl.value);
                for (let j = 0; j < audioTracks.length; j++) {
                  audioTracks[j].enabled = (j === selectedIdx);
                }
              });

              audioWrapper.appendChild(selectEl);

              // Insert next to Quality selector
              const qualitySelector = controlBarEl.querySelector('.vjs-quality-selector-container');
              if (qualitySelector) {
                controlBarEl.insertBefore(audioWrapper, qualitySelector);
              } else {
                const fsControl = controlBarEl.querySelector('.vjs-fullscreen-control');
                if (fsControl) {
                  controlBarEl.insertBefore(audioWrapper, fsControl);
                } else {
                  controlBarEl.appendChild(audioWrapper);
                }
              }
            }
          };

          audioTracks.on('addtrack', rebuildAudioDropdown);
          audioTracks.on('removetrack', rebuildAudioDropdown);
          rebuildAudioDropdown();
        }

      }
    });
  }

  // ── REUSABLE CORE UTILITIES & ADAPTIVE ENGINES ──
  function setManualQualityLevel(height) {
    if (typeof player.qualityLevels !== 'function') {
      console.warn('[Quality Levels] qualityLevels method not available.');
      return;
    }
    const qualityLevels = player.qualityLevels();
    if (!qualityLevels) return;
    const targetHeight = height === 'auto' ? 'auto' : parseInt(height);

    for (let i = 0; i < qualityLevels.length; i++) {
      const level = qualityLevels[i];
      if (targetHeight === 'auto') {
        level.enabled = true;
      } else {
        level.enabled = (level.height === targetHeight);
      }
    }

    if (targetHeight !== 'auto' && statsRes) {
      statsRes.textContent = `${targetHeight}p`;
    }
  }

  function formatTime(seconds) {
    if (isNaN(seconds) || seconds === Infinity) return "00:00";
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    const pad = (num) => num.toString().padStart(2, '0');

    if (h > 0) {
      return `${pad(h)}:${pad(m)}:${pad(s)}`;
    } else {
      return `${pad(m)}:${pad(s)}`;
    }
  }

  function capitalizeFirstLetter(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
  }

}); // DOMContentLoaded