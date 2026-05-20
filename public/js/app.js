if (window.htmx) {
  window.htmx.config.scrollIntoViewOnBoost = false;
}

document.addEventListener('htmx:beforeRequest', () => {
  document.querySelector('.hx-indicator')?.classList.add('htmx-request');
});
document.addEventListener('htmx:afterRequest', () => {
  document.querySelector('.hx-indicator')?.classList.remove('htmx-request');
});


// Toggles the rhs playlist view
function togglePlaylist() {
  playlist.classList.toggle("d-none");
  if (!playlist.classList.contains("d-none")) {
    document.getElementById("playlist-{{ hash }}")?.focus();
  }
}

async function copyToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }
  // Fallback for non-secure contexts (e.g. HTTP on a LAN IP).
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

