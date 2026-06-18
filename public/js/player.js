var progressBar = document.querySelector("#playback .progress .progress-bar");
var progressContainer = document.querySelector('.progress');
var audio = document.getElementById("audio");
var playBtn = document.getElementById("play");

// Progress bar stuff
function updateProgress() {
  if (!audio.paused) {
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

(function() {
  progressContainer.addEventListener('click', (e) => {
      const rect = progressContainer.getBoundingClientRect();
      const clickX = e.clientX - rect.left;
      const percent = clickX / rect.width;
      audio.currentTime = percent * audio.duration;
      updatePositionState();
      });

  audio.onloadedmetadata = function() {
    setupMediaSession();
    updatePositionState();
    play();
  }

  audio.onpause = function () {
    progressBar.classList.add("disabled");
    playBtn.innerHTML = `<i class="bi bi-play-circle-fill"></i>`;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
  }

  audio.onplaying = function () {
    progressBar.classList.remove("disabled");
    playBtn.innerHTML = `<i class="bi bi-pause-circle-fill"></i>`;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing';
    updatePositionState();
  }

  audio.onended = function () {
    progressBar.style.width = 0;
    next();
  }

  audio.onerror = function () {
    // Surface undecodable sources (e.g. a codec the browser can't play) instead
    // of failing silently — onloadedmetadata never fires for these, so autoplay
    // would otherwise just never happen.
    const err = audio.error;
    console.error('Audio playback error', err && err.code, audio.currentSrc);
  }

  requestAnimationFrame(updateProgress);
})();
