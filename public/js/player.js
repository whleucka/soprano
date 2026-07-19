var progressBar = document.querySelector("#playback .progress .progress-bar");
var progressContainer = document.querySelector('.progress');
var radioBadge = document.querySelector('.radio-badge');
var audio = document.getElementById("audio");
var playBtn = document.getElementById("play");
var isRadio = audio && audio.dataset.type === 'radio';

// Progress bar stuff
function updateProgress() {
  // Radio streams are live (no fixed duration) and render a LIVE badge instead
  // of a progress bar, so progressBar can be absent — bail out cleanly.
  if (progressBar && !audio.paused && isFinite(audio.duration) && audio.duration > 0) {
    const percent = (audio.currentTime / audio.duration) * 100;
    progressBar.style.width = percent + '%';
    progressBar.setAttribute('aria-valuenow', percent);
  }
  requestAnimationFrame(updateProgress);
}

// Player controls
function play() {
  if (audio.paused) {
    audio.play();
  } else {
    audio.pause();
  }
}

function next() {
  // Try next track
  htmx.ajax('GET', next_track_url, {swap: 'none'});
}

function prev() {
  // Try prev track
  htmx.ajax('GET', prev_track_url, {swap: 'none'});
}

function updatePositionState() {
  if ('mediaSession' in navigator && 'setPositionState' in navigator.mediaSession
      && isFinite(audio.duration) && audio.duration > 0) {
    navigator.mediaSession.setPositionState({
      duration: audio.duration,
      playbackRate: audio.playbackRate,
      position: audio.currentTime,
    });
  }
}

function setupMediaSession() {
  if (!('mediaSession' in navigator)) return;
  if (typeof player_track === 'undefined') return;
  if (!audio.src || audio.src.endsWith('#')) return;

  const coverUrl = new URL(player_track.cover, location.origin).href;
  navigator.mediaSession.metadata = new MediaMetadata({
    title:  player_track.title,
    artist: player_track.artist,
    album:  player_track.album,
    artwork: [
      { src: coverUrl, sizes: '96x96',   type: 'image/png' },
      { src: coverUrl, sizes: '192x192', type: 'image/png' },
      { src: coverUrl, sizes: '256x256', type: 'image/png' },
      { src: coverUrl, sizes: '384x384', type: 'image/png' },
      { src: coverUrl, sizes: '512x512', type: 'image/png' },
    ],
  });

  navigator.mediaSession.setActionHandler('play',          () => audio.play());
  navigator.mediaSession.setActionHandler('pause',         () => audio.pause());
  navigator.mediaSession.setActionHandler('previoustrack', () => prev());
  navigator.mediaSession.setActionHandler('nexttrack',     () => next());
  navigator.mediaSession.setActionHandler('seekto', (details) => {
    if (details.fastSeek && 'fastSeek' in audio) {
      audio.fastSeek(details.seekTime);
    } else {
      audio.currentTime = details.seekTime;
    }
    updatePositionState();
  });
  try {
    navigator.mediaSession.setActionHandler('seekbackward', (details) => {
      audio.currentTime = Math.max(audio.currentTime - (details.seekOffset || 10), 0);
      updatePositionState();
    });
    navigator.mediaSession.setActionHandler('seekforward', (details) => {
      audio.currentTime = Math.min(audio.currentTime + (details.seekOffset || 10), audio.duration || 0);
      updatePositionState();
    });
  } catch (_) { /* ignore unsupported actions */ }
}

// --- ReplayGain (WebAudio) -------------------------------------------------
// Direct-streamed tracks (mp3/aac/…) get their ReplayGain applied here via a
// gain node; transcoded tracks arrive with data-gain="0" because the gain is
// baked into the cached Opus file server-side. Radio and podcasts never route
// through WebAudio: their sources are cross-origin, and CORS-tainted media
// plays silent through an AudioContext.
//
// The AudioContext persists on window across player partial swaps; the
// source/gain pair is rebuilt for each new <audio> element (a media element
// can only be wired to a context once).
function setupReplayGain() {
  if (audio.dataset.type) return; // tracks only — radio/podcast set data-type
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return;

  const db = parseFloat(audio.dataset.gain || '0') || 0;
  try {
    if (!window.__audioCtx) window.__audioCtx = new AC();
    const ctx = window.__audioCtx;

    // The old element is gone from the DOM after a swap — detach its nodes.
    if (window.__audioGraph && window.__audioGraph.el !== audio) {
      try { window.__audioGraph.source.disconnect(); } catch (_) { /* ignore */ }
      try { window.__audioGraph.gain.disconnect(); } catch (_) { /* ignore */ }
      window.__audioGraph = null;
    }

    if (!window.__audioGraph) {
      const source = ctx.createMediaElementSource(audio);
      const gain = ctx.createGain();
      source.connect(gain);
      gain.connect(ctx.destination);
      window.__audioGraph = { el: audio, source: source, gain: gain };
    }

    window.__audioGraph.gain.gain.value = Math.pow(10, db / 20);
  } catch (e) {
    console.warn('[player] WebAudio gain unavailable', e);
  }
}

// A media element routed through a suspended AudioContext plays silent, and
// contexts start suspended until a user gesture. Resume on playback and, as a
// fallback, on the first gesture anywhere (hooked once — window survives swaps).
function resumeAudioCtx() {
  const ctx = window.__audioCtx;
  if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
}
if (!window.__audioCtxResumeHooked) {
  window.__audioCtxResumeHooked = true;
  ['click', 'keydown', 'touchstart'].forEach(function (type) {
    document.addEventListener(type, resumeAudioCtx, { capture: true, passive: true });
  });
}

// --- Radio (HLS) playback --------------------------------------------------
// The player partial reloads on every track/station change, re-running this
// script. We keep the Hls instance on window so we can tear it down before
// (re)creating one, and destroy it whenever we switch back to a music track.
function teardownRadio() {
  if (window.__hls) {
    try { window.__hls.destroy(); } catch (_) { /* ignore */ }
    window.__hls = null;
  }
}

function setupRadio() {
  const src = audio.dataset.src;
  if (!src) return;
  if (radioBadge) radioBadge.classList.remove("active");
  teardownRadio();

  if (window.Hls && Hls.isSupported()) {
    const hls = new Hls();
    window.__hls = hls;
    hls.loadSource(src);
    hls.attachMedia(audio);
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      audio.play().then(() => {
      }).catch((e) => console.error('[radio] play() blocked', e));
    });
    hls.on(Hls.Events.ERROR, (_, data) => {
      if (!data.fatal) return;
      // Stations drop playback now and then — recover in place when we can,
      // otherwise tear down and rebuild the stream after a short backoff.
      switch (data.type) {
        case Hls.ErrorTypes.NETWORK_ERROR:
          console.warn('Radio network error, reloading stream', data.details);
          hls.startLoad();
          break;
        case Hls.ErrorTypes.MEDIA_ERROR:
          console.warn('Radio media error, recovering', data.details);
          hls.recoverMediaError();
          break;
        default:
          console.error('Radio fatal error, reconnecting', data.details);
          teardownRadio();
          setTimeout(() => { if (isRadio) setupRadio(); }, 3000);
          break;
      }
    });
  } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
    // Native HLS (Safari / iOS): point the element straight at the playlist.
    audio.src = src;
    audio.play().then(() => {
    });
  } else {
    console.error('HLS is not supported in this browser');
  }
}

// Close out the outgoing track's play row: attach its hash/position to every
// track-change request so the server can record ms_played/skipped, and flush
// a last report when the tab goes away. The player partial re-runs this
// script on every swap, so hook the (persistent) body/window exactly once and
// read the live DOM inside the handlers.
if (!window.__playReportHooked) {
  window.__playReportHooked = true;

  window.__playReport = function (params) {
    const a = document.getElementById('audio');
    if (!a || !a.dataset.hash || a.dataset.hash.length !== 32) return;
    if (typeof player_type !== 'undefined' && player_type !== 'track') return;
    if (!isFinite(a.currentTime)) return;
    params.cur = a.dataset.hash;
    params.pos = Math.round(a.currentTime * 1000);
    if (isFinite(a.duration) && a.duration > 0) {
      params.dur = Math.round(a.duration * 1000);
    }
  };

  document.body.addEventListener('htmx:configRequest', function (evt) {
    if (!/\/player\/(play|next-track|prev-track)/.test(evt.detail.path || '')) return;
    window.__playReport(evt.detail.parameters);
  });

  window.addEventListener('pagehide', function () {
    const params = {};
    window.__playReport(params);
    if (!params.cur || typeof progress_url === 'undefined') return;
    fetch(progress_url + '?' + new URLSearchParams(params), { keepalive: true });
  });
}

// Podcast resume: report the playhead so the server can save a resume point.
// Fires every 15s while playing, plus on pause/ended/pagehide. Same
// hook-once-read-live-DOM pattern as the track play reporter above.
if (!window.__podcastReportHooked) {
  window.__podcastReportHooked = true;

  window.__podcastReport = function (keepalive) {
    const a = document.getElementById('audio');
    if (!a || a.dataset.type !== 'podcast' || !a.dataset.episode) return;
    if (typeof podcast_progress_url === 'undefined') return;
    if (!isFinite(a.currentTime) || a.currentTime <= 0) return;
    const params = new URLSearchParams({
      episode: a.dataset.episode,
      pos: Math.round(a.currentTime * 1000),
    });
    if (isFinite(a.duration) && a.duration > 0) {
      params.set('dur', Math.round(a.duration * 1000));
    }
    fetch(podcast_progress_url + '?' + params, keepalive ? { keepalive: true } : {});
  };

  setInterval(function () {
    const a = document.getElementById('audio');
    if (a && a.dataset.type === 'podcast' && !a.paused) window.__podcastReport(false);
  }, 15000);

  window.addEventListener('pagehide', function () {
    window.__podcastReport(true);
  });
}

(function() {
  if (progressContainer) {
    progressContainer.addEventListener('click', (e) => {
        const rect = progressContainer.getBoundingClientRect();
        const clickX = e.clientX - rect.left;
        const percent = clickX / rect.width;
        audio.currentTime = percent * audio.duration;
        updatePositionState();
        });
  }

  audio.onloadedmetadata = function() {
    // Podcast resume: the server stamps the saved position (ms) on the audio
    // element; seek before autoplay so playback picks up where it left off.
    const resume = parseInt(audio.dataset.resume || '0', 10);
    if (resume > 0 && isFinite(audio.duration) && resume / 1000 < audio.duration) {
      audio.currentTime = resume / 1000;
    }
    setupMediaSession();
    updatePositionState();
    // New media just loaded — always start it. Using the play() *toggle* here
    // races with radio's MANIFEST_PARSED autoplay: if playback already started,
    // the toggle would pause it ("play() interrupted by pause()").
    audio.play().catch((e) => console.error('[player] autoplay blocked', e));
  }

  audio.onpause = function () {
    if (audio.dataset.type === 'podcast') window.__podcastReport(false);
    if (radioBadge) radioBadge.classList.remove("active");
    if (progressBar) progressBar.classList.add("disabled");
    playBtn.innerHTML = `<i class="bi bi-play-circle-fill"></i>`;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
  }

  audio.onplaying = function () {
    resumeAudioCtx();
    if (radioBadge) radioBadge.classList.add("active");
    if (progressBar) progressBar.classList.remove("disabled");
    playBtn.innerHTML = `<i class="bi bi-pause-circle-fill"></i>`;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing';
    updatePositionState();
  }

  audio.onended = function () {
    // A live radio stream shouldn't "end" — if it does, the station dropped, so
    // reconnect rather than advancing the (empty) playlist.
    if (isRadio) {
      setTimeout(() => { if (isRadio) setupRadio(); }, 3000);
      return;
    }
    if (progressBar) progressBar.style.width = 0;
    // A finished podcast has no queue to advance — send the final position
    // (which clears the resume row server-side) instead of hitting next-track.
    if (audio.dataset.type === 'podcast') {
      window.__podcastReport(false);
      return;
    }
    // auto=1 tells the server this is a natural end-of-track advance, so the
    // repeat mode applies (repeat-one replays, repeat-off stops at queue end).
    htmx.ajax('GET', next_track_url + '?auto=1', {swap: 'none'});
  }

  audio.onerror = function () {
    // Surface undecodable sources (e.g. a codec the browser can't play) instead
    // of failing silently — onloadedmetadata never fires for these, so autoplay
    // would otherwise just never happen.
    const err = audio.error;
    console.error('Audio playback error', err && err.code, audio.currentSrc);
  }

  if (isRadio) {
    setupRadio();
  } else {
    // Switched back to music — make sure no radio stream keeps running.
    teardownRadio();
    setupReplayGain();
  }

  requestAnimationFrame(updateProgress);
})();
