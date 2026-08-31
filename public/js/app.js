if (window.htmx) {
  window.htmx.config.scrollIntoViewOnBoost = false;

  // Every view here is painted from live server state — the session-backed
  // search results, the player, likes, playlists. Restoring a DOM snapshot out
  // of htmx's localStorage history cache shows whatever was on screen the last
  // time you were on that URL, which is how back off a genre page came back
  // with the genre's tracks still in it. Zero also makes htmx drop the cache
  // key outright, so snapshots saved before this shipped can't come back.
  window.htmx.config.historyCacheSize = 0;
}

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      console.warn('SW registration failed:', err);
    });
  });
}

// Something replaced the whole queue — an album, a playlist, an artist radio,
// a station, "play all" on a search. Reveal the queue panel so the swap is
// visible; otherwise the queue silently becomes something else behind a panel
// you have to know to open, which on mobile is entirely off-screen.
//
// Bound on document, not body: htmx dispatches HX-Trigger events on body and
// they bubble, and this file runs in <head> before body exists. showPlaylist
// lives in playlist.js, which loads with the panel fragment — by the time an
// event can fire, it's there.
document.addEventListener('queueReplaced', () => {
  if (typeof showPlaylist === 'function') showPlaylist();
});

// The top bar re-renders on loadTop from static markup that can't know whether
// the queue panel is currently open, so the button's pressed state would reset
// out from under it. Resync after any swap that could have replaced it.
document.addEventListener('htmx:afterSettle', () => {
  if (typeof syncPlaylistToggle === 'function') syncPlaylistToggle();
});

document.addEventListener('htmx:beforeRequest', () => {
  document.querySelector('.hx-indicator')?.classList.add('htmx-request');
});
document.addEventListener('htmx:afterRequest', () => {
  document.querySelector('.hx-indicator')?.classList.remove('htmx-request');
});


function updateActiveTrack(hash) {
  document.querySelectorAll(".track").forEach((track) => {
    if (track.id == hash) {
      track.focus();
      track.classList.add("active");
    } else {
      track.classList.remove("active");
    }
  });
}

async function copyClipboard(e) {
    const icon = e.currentTarget.querySelector('i');
    const original = icon ? icon.className : null;
    try {
      await _copyClipboard(window.location.href);
      if (icon) {
        icon.className = 'bi bi-check2 active';
        setTimeout(() => { icon.className = original; }, 1500);
      }
    } catch (err) {
      console.error('Clipboard copy failed', err);
    }
}

// Fallback for non-secure contexts (e.g. HTTP on a LAN IP).
async function _copyClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  const ok = document.execCommand('copy');
  document.body.removeChild(ta);
  if (!ok) throw new Error('execCommand copy failed');
}

