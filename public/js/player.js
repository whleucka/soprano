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
    setupMediaSession();
    updatePositionState();
    // New media just loaded — always start it. Using the play() *toggle* here
    // races with radio's MANIFEST_PARSED autoplay: if playback already started,
    // the toggle would pause it ("play() interrupted by pause()").
    audio.play().catch((e) => console.error('[player] autoplay blocked', e));
  }

  audio.onpause = function () {
    if (radioBadge) radioBadge.classList.remove("active");
    if (progressBar) progressBar.classList.add("disabled");
    playBtn.innerHTML = `<i class="bi bi-play-circle-fill"></i>`;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
  }

  audio.onplaying = function () {
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
    next();
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
  }

  requestAnimationFrame(updateProgress);
})();
