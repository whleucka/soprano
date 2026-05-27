if (window.htmx) {
  window.htmx.config.scrollIntoViewOnBoost = false;
}

document.addEventListener('htmx:beforeRequest', () => {
  document.querySelector('.hx-indicator')?.classList.add('htmx-request');
});
document.addEventListener('htmx:afterRequest', () => {
  document.querySelector('.hx-indicator')?.classList.remove('htmx-request');
});

// Active track highlighting. Shared by player.js and playlist.js, which load
// as separate htmx fragments — keeping these here guarantees they're defined
// globally before either fragment runs. player_hash comes from the player
// template and only exists once the player fragment has loaded, so guard it.
function removeActiveTrack() {
  if (typeof player_hash === 'undefined') return;
  document.querySelectorAll(".track").forEach((track) => {
    if (track.id == player_hash) {
      track.focus();
      track.classList.add("active");
    } else {
      track.classList.remove("active");
    }
  });
}

function updateActiveTrack() {
  removeActiveTrack();
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

