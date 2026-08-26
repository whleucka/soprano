var progressBar = document.querySelector("#playback .progress .progress-bar");
var progressContainer = document.querySelector('.progress');
var radioBadge = document.querySelector('.radio-badge');
var audio = document.getElementById("audio");
var playBtn = document.getElementById("play");
var isRadio = audio && audio.dataset.type === 'radio';

// Crossfade overlap in seconds (tunable). Only used when the client opted in
// (data-crossfade) and there is a next track to fade into (data-next).
var CROSSFADE_SEC = 6;
// Tracks shorter than this never crossfade — a fade needs real audio on both
// sides of the overlap, and a <=CROSSFADE_SEC track would fade from its very
// start. Must be > CROSSFADE_SEC.
var CROSSFADE_MIN_TRACK = 2 * CROSSFADE_SEC;
// How long the early handoff gets to produce an incoming element before we
// give up on it (recoverStalledCrossfade). Must leave the outgoing track
// enough of its tail to advance the ordinary way instead.
var CROSSFADE_STALL_MS = 3000;
var crossfadeEnabled = !!(audio && audio.dataset.crossfade === '1');

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

// Volume (mobile control). The player partial is swapped on every track
// change, so the level lives in localStorage and gets re-applied to each new
// <audio> element rather than resetting to full on every skip.
var volumeSlider = document.getElementById('volume-slider');
var muteBtn = document.getElementById('mute');

function applyVolume(v, persist) {
  v = Math.min(1, Math.max(0, isFinite(v) ? v : 1));
  if (audio) audio.volume = v;
  if (volumeSlider) {
    volumeSlider.value = Math.round(v * 100);
    volumeSlider.style.setProperty('--vol', Math.round(v * 100) + '%');
  }
  if (muteBtn) {
    const icon = v === 0 ? 'bi-volume-mute-fill'
      : v < 0.5 ? 'bi-volume-down-fill'
      : 'bi-volume-up-fill';
    muteBtn.innerHTML = '<i class="bi ' + icon + '"></i>';
  }
  if (persist) {
    localStorage.setItem('soprano.volume', v);
    // Remember the last audible level so unmuting restores it.
    if (v > 0) localStorage.setItem('soprano.volume.last', v);
  }
}

function toggleMute() {
  const cur = audio ? audio.volume : 1;
  if (cur > 0) {
    applyVolume(0, true);
  } else {
    applyVolume(parseFloat(localStorage.getItem('soprano.volume.last') || '1'), true);
  }
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
    const target = Math.pow(10, db / 20);

    // Crossfade handoff: this is the incoming element and the outgoing one is
    // still playing on window.__audioGraph. Give the incoming element its OWN
    // (silent) chain rather than tearing the outgoing one down; the ramp starts
    // when it begins playing (beginRampIfNeeded).
    if (window.__crossfade && !window.__crossfade.torn) {
      const xf = window.__crossfade;
      if (!xf.inGraph && window.__audioGraph && window.__audioGraph.el === xf.outEl) {
        const source = ctx.createMediaElementSource(audio);
        const gain = ctx.createGain();
        source.connect(gain);
        gain.connect(ctx.destination);
        gain.gain.value = 0.0001; // start silent, ramp up on play
        xf.inGraph = { el: audio, source: source, gain: gain };
        xf.inTarget = target;
        window.__audioGraph = xf.inGraph; // the incoming chain is now "current"
        // Belt-and-suspenders: if the swap paused the outgoing element before we
        // re-parented it, resume it (full gain still on its node) so it keeps
        // sounding under the incoming track until beginRampIfNeeded fades it out.
        if (xf.outEl && xf.outEl.paused) xf.outEl.play().catch(function () {});
        return;
      }
      // Another swap landed mid-crossfade (e.g. a manual skip) — abort the fade
      // and fall through to a clean single-chain rebuild for the new element.
      abortCrossfade();
    }

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

    window.__audioGraph.gain.gain.value = target;
  } catch (e) {
    console.warn('[player] WebAudio gain unavailable', e);
  }
}

// --- Crossfade -------------------------------------------------------------
// When enabled, a track reaching its final CROSSFADE_SEC hands off to the next
// one: we advance the queue early (the same next-track request the natural end
// would send), keep the outgoing <audio> + its gain node alive through the
// partial swap, build a second gain chain for the incoming element, and ramp
// one down as the other comes up. Music tracks only. Crossfade state lives on
// window.__crossfade so it survives the swap that re-runs this script.
//
// NOTE: this script re-executes in global scope on every player swap, so the
// helpers here are function declarations reading the (reassigned) global
// `audio` — there is only ever one current element; the fade partner is held
// on window.__crossfade, never on a captured local.
function maybeStartCrossfade() {
  if (!crossfadeEnabled) return;
  if (!audio || audio.dataset.type) return;   // music tracks only
  if (audio.dataset.next !== '1') return;      // nothing to fade into
  if (window.__crossfade) return;              // one already in flight
  if (audio.paused) return;
  const d = audio.duration;
  if (!isFinite(d) || d < CROSSFADE_MIN_TRACK) return; // too short to fade
  const remaining = d - audio.currentTime;
  if (remaining > CROSSFADE_SEC || remaining <= 0) return;
  startCrossfade();
}

function startCrossfade() {
  const graph = window.__audioGraph;
  // No WebAudio chain for this element (context blocked, etc.) — skip the fade
  // and let the untouched onended advance the queue the ordinary way.
  if (!window.__audioCtx || !graph || graph.el !== audio) return;

  const outEl = audio;
  const xf = window.__crossfade = {
    outEl: outEl,
    outGraph: graph,
    inGraph: null,
    inTarget: 1,
    incomingStarted: false,
    torn: false,
    timer: null,
    watchdog: null,
  };
  // This element has handed off — it must never arm a second fade, including
  // after a stalled handoff hands its timeupdate handler back.
  outEl.dataset.next = '0';
  // Silence the outgoing element's UI/media handlers first: re-parenting it (and
  // its natural end) fires pause/ended events, and those handlers write the
  // *shared* progress bar / play button / mediaSession — which now belong to the
  // incoming track, so leaving them bound greys the fading-in track's bar and
  // flips its play button. We only keep an 'ended' hook to close the crossfade.
  detachMediaHandlers(outEl);
  // The outgoing element must not fire its own next-track when it truly ends —
  // we're advancing now. Its real 'ended' just closes out the crossfade.
  outEl.onended = function () { finishCrossfade(); };
  // Keep the outgoing element audible THROUGH the swap. The swap replaces
  // #player's contents, and removing a media element from the DOM pauses it —
  // so re-parent it into <body> first. Moving it *within* the document keeps it
  // playing (it retains src + currentTime). We leave its id: the incoming
  // <audio> the swap inserts sits earlier in the tree, so getElementById
  // ('audio') still resolves to the incoming element; we drop this one in
  // teardown.
  try { document.body.appendChild(outEl); } catch (_) { /* ignore */ }
  // Advance + swap in the next track early (auto=1 so repeat mode applies).
  htmx.ajax('GET', next_track_url + '?auto=1', { swap: 'none' });
  // The handoff is now the ONLY thing that can advance the queue — arm a
  // watchdog in case it never lands. Recover with at least a second of the
  // outgoing track left, so its natural end still has something to fire.
  let grace = CROSSFADE_STALL_MS;
  if (isFinite(outEl.duration) && outEl.duration > 0) {
    grace = Math.min(grace, (outEl.duration - outEl.currentTime - 1) * 1000);
  }
  xf.watchdog = setTimeout(function () { recoverStalledCrossfade(xf); }, Math.max(500, grace));
}

// Whether the incoming half of a crossfade actually showed up — either it got
// its own gain chain, or a swap replaced the current element with a new one.
function crossfadePartnerArrived(xf) {
  if (xf.inGraph) return true;
  const cur = document.getElementById('audio');
  return !!cur && cur !== xf.outEl;
}

// The early handoff produced no incoming track (the request errored, the
// connection dropped, or the server had nothing to give). Without this the
// outgoing element just runs out and stops for good: startCrossfade silenced
// its handlers for a partner that never came, so nothing is left to advance
// the queue. Hand them back and let its real end do the ordinary advance.
function recoverStalledCrossfade(xf) {
  if (!xf || xf.torn || window.__crossfade !== xf) return;
  xf.watchdog = null;
  if (crossfadePartnerArrived(xf)) return;

  console.warn('[player] crossfade handoff stalled — falling back to a plain advance');
  xf.torn = true;
  if (xf.timer) { clearTimeout(xf.timer); xf.timer = null; }
  window.__crossfade = null;

  const el = xf.outEl;
  if (!el) return;
  reattachMediaHandlers(el);
  // Put it back where a swap looks for it: a response that lands late must
  // replace this element, not leave it playing under the new one.
  const host = document.getElementById('player');
  if (host && el.parentNode !== host) {
    try { host.appendChild(el); } catch (_) { /* ignore */ }
  }
}

function beginRampIfNeeded() {
  const xf = window.__crossfade;
  if (!xf || xf.torn || xf.incomingStarted) return;
  if (!xf.inGraph || xf.inGraph.el !== audio) return; // only the incoming element ramps
  const ctx = window.__audioCtx;
  if (!ctx) return;
  xf.incomingStarted = true;

  // Fade over the outgoing track's ACTUAL remaining tail so it reaches zero
  // right as it ends (absorbs swap latency); cap at CROSSFADE_SEC.
  let remaining = CROSSFADE_SEC;
  const o = xf.outEl;
  if (o && isFinite(o.duration) && o.duration > 0) {
    remaining = o.duration - o.currentTime;
  }
  const fadeDur = Math.max(0.1, Math.min(CROSSFADE_SEC, remaining));
  const t0 = ctx.currentTime;

  const inG = xf.inGraph.gain.gain;
  inG.cancelScheduledValues(t0);
  inG.setValueAtTime(Math.max(inG.value, 0.0001), t0);
  inG.linearRampToValueAtTime(Math.max(xf.inTarget, 0.0001), t0 + fadeDur);

  const outG = xf.outGraph.gain.gain;
  outG.cancelScheduledValues(t0);
  outG.setValueAtTime(Math.max(outG.value, 0.0001), t0);
  outG.linearRampToValueAtTime(0.0001, t0 + fadeDur);

  xf.timer = setTimeout(finishCrossfade, Math.ceil(fadeDur * 1000) + 100);
}

// Silence a soon-to-be-discarded element's media events before we pause it —
// its onpause/onplaying handlers write the *shared* play button + mediaSession
// state, which now belong to the incoming track. Leaving them bound would flip
// the UI to "paused" mid-crossfade.
function detachMediaHandlers(el) {
  if (!el) return;
  // Keep the originals on the element: a handoff that never lands gives them
  // back (recoverStalledCrossfade) so the element can finish on its own. Only
  // the first detach saves — later ones would just store the nulls.
  if (!el.__handlers) {
    el.__handlers = {
      onended: el.onended,
      onerror: el.onerror,
      onpause: el.onpause,
      onplaying: el.onplaying,
      onloadedmetadata: el.onloadedmetadata,
      ontimeupdate: el.ontimeupdate,
    };
  }
  el.onended = null;
  el.onerror = null;
  el.onpause = null;
  el.onplaying = null;
  el.onloadedmetadata = null;
  el.ontimeupdate = null;
}

// Give a silenced element its media events back — it owns the shared UI again.
function reattachMediaHandlers(el) {
  if (!el || !el.__handlers) return;
  const h = el.__handlers;
  el.__handlers = null;
  el.onended = h.onended;
  el.onerror = h.onerror;
  el.onpause = h.onpause;
  el.onplaying = h.onplaying;
  el.onloadedmetadata = h.onloadedmetadata;
  el.ontimeupdate = h.ontimeupdate;
}

// Idempotent: safe to call from the fade timer AND the outgoing 'ended' event.
function finishCrossfade() {
  const xf = window.__crossfade;
  if (!xf || xf.torn) return;
  xf.torn = true;
  if (xf.timer) { clearTimeout(xf.timer); xf.timer = null; }
  if (xf.watchdog) { clearTimeout(xf.watchdog); xf.watchdog = null; }

  // Stop + release the outgoing element and detach its chain.
  try {
    if (xf.outEl) {
      detachMediaHandlers(xf.outEl);
      xf.outEl.pause();
      xf.outEl.removeAttribute('src');
      xf.outEl.load();
      xf.outEl.remove(); // drop the <body>-parked outgoing element
    }
  } catch (_) { /* ignore */ }
  try { xf.outGraph && xf.outGraph.source.disconnect(); } catch (_) { /* ignore */ }
  try { xf.outGraph && xf.outGraph.gain.disconnect(); } catch (_) { /* ignore */ }

  // Pin the incoming (now sole) track at its full ReplayGain — covers the case
  // where the outgoing track ended before the ramp could start.
  if (xf.inGraph && window.__audioCtx) {
    try {
      const g = xf.inGraph.gain.gain;
      const now = window.__audioCtx.currentTime;
      g.cancelScheduledValues(now);
      g.setValueAtTime(Math.max(xf.inTarget || 1, 0.0001), now);
    } catch (_) { /* ignore */ }
  }
  window.__crossfade = null;
}

// Tear down a fade in progress without a clean handoff (manual skip landed
// mid-crossfade). Both chains are dropped; the caller rebuilds for the new
// element.
function abortCrossfade() {
  const xf = window.__crossfade;
  if (!xf) return;
  if (xf.timer) { clearTimeout(xf.timer); xf.timer = null; }
  if (xf.watchdog) { clearTimeout(xf.watchdog); xf.watchdog = null; }
  try { if (xf.outEl) { detachMediaHandlers(xf.outEl); xf.outEl.pause(); xf.outEl.remove(); } } catch (_) { /* ignore */ }
  try { xf.outGraph && xf.outGraph.source.disconnect(); } catch (_) { /* ignore */ }
  try { xf.outGraph && xf.outGraph.gain.disconnect(); } catch (_) { /* ignore */ }
  try { if (xf.inGraph && xf.inGraph.el) { detachMediaHandlers(xf.inGraph.el); xf.inGraph.el.pause(); } } catch (_) { /* ignore */ }
  try { xf.inGraph && xf.inGraph.source.disconnect(); } catch (_) { /* ignore */ }
  try { xf.inGraph && xf.inGraph.gain.disconnect(); } catch (_) { /* ignore */ }
  window.__crossfade = null;
  window.__audioGraph = null; // force a clean single-chain rebuild
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

  // Crossfade trigger rides on timeupdate (fires ~4×/s during playback and,
  // unlike requestAnimationFrame, keeps firing in a backgrounded tab) so the
  // fade starts on time even when the tab isn't focused.
  audio.ontimeupdate = maybeStartCrossfade;

  audio.onplaying = function () {
    resumeAudioCtx();
    beginRampIfNeeded();
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

  applyVolume(parseFloat(localStorage.getItem('soprano.volume') || '1'), false);

  requestAnimationFrame(updateProgress);
})();
