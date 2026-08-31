// Toggles the rhs queue view
function togglePlaylist() {
  var hash = document.getElementById("audio").dataset.hash;
  var playlist = document.getElementById("playlist");
  var view = document.getElementById("view");
  playlist.classList.toggle("d-none");
  view.classList.toggle("d-none");
  view.classList.toggle("d-md-block");
  updateActiveTrack(hash)
  syncPlaylistToggle();
}

// The queue button is a switch, but nothing about it said so — it looked like
// the nav links beside it, which is half of why an open queue read as "a
// section I navigated to". Mirror the panel's state onto the button.
function syncPlaylistToggle() {
  var btn = document.getElementById("show-playlist");
  var playlist = document.getElementById("playlist");
  if (!btn || !playlist) return;
  var open = !playlist.classList.contains("d-none");
  btn.classList.toggle("active", open);
  btn.setAttribute("aria-pressed", open ? "true" : "false");
}

// Reveal the queue panel. Unlike togglePlaylist this is idempotent — it's
// fired by an event (queueReplaced), so it must not close a panel that's
// already open.
function showPlaylist() {
  var playlist = document.getElementById("playlist");
  if (!playlist || !playlist.classList.contains("d-none")) return;
  togglePlaylist();
}

// Paint the queue skeleton while a replacement's rows are in flight. Only for
// replacements — an "add to queue" leaves the list correct bar one row, and
// flashing ten placeholders over it would be worse than the wait.
function paintQueueSkeleton() {
  var tracks = document.querySelector("#playlist .tracks");
  var tpl = document.getElementById("queue-skeleton");
  if (!tracks || !tpl) return;
  tracks.innerHTML = tpl.innerHTML;
}

function hidePlaylist() {
  var playlist = document.getElementById("playlist");
  var view = document.getElementById("view");
  // if the view is hidden, then we should 
  // hide the playlist and show the view
  if (window.innerWidth <= 1200) {
    playlist.classList.add("d-none");
    view.classList.remove("d-none");
    view.classList.remove("d-md-block");
    syncPlaylistToggle();
  }
}
