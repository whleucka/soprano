// Toggles the rhs playlist view
function togglePlaylist() {
  var playlist = document.getElementById("playlist");
  var view = document.getElementById("view");
  playlist.classList.toggle("d-none");
  view.classList.toggle("d-none");
  view.classList.toggle("d-lg-block");
  updateActiveTrack();
}

function hidePlaylist() {
  var playlist = document.getElementById("playlist");
  var view = document.getElementById("view");
  // Mobile only
  // if the view is hidden, then we should 
  // hide the playlist and show the view
  if (window.innerWidth <= 768) {
    playlist.classList.add("d-none");
    view.classList.remove("d-none");
    view.classList.remove("d-lg-block");
    updateActiveTrack();
  }
}
