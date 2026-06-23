if (window.htmx) {
  window.htmx.config.scrollIntoViewOnBoost = false;
}

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      console.warn('SW registration failed:', err);
    });
  });
}

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

