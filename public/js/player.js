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

// Active track
async function removeActiveTrack() {
  var tracks = document.querySelectorAll(".track");
  // remove the active class from the current rows
  tracks.forEach((track) => {
    // player_hash comes from player template
    if (track.id == player_hash) {
      track.focus();
      track.classList.add("active");
    } else {
      track.classList.remove("active");
    }
  })
}

async function updateActiveTrack() {
  await removeActiveTrack();
}


(function() {
  progressContainer.addEventListener('click', (e) => {
      const rect = progressContainer.getBoundingClientRect();
      const clickX = e.clientX - rect.left;
      const percent = clickX / rect.width;
      media.currentTime = percent * media.duration;
      });

  audio.onloadedmetadata = function() {
    play();
  }

  audio.onloadeddata = function () {
    updateActiveTrack();
  }

  audio.onpause = function () {
    progressBar.classList.add("disabled");
    playBtn.innerHTML = `<i class="bi bi-play-circle-fill"></i>`;
  }

  audio.onplaying = function () {
    progressBar.classList.remove("disabled");
    playBtn.innerHTML = `<i class="bi bi-pause-circle-fill"></i>`;
  }

  audio.onended = function () {
    progressBar.style.width = 0; 
    next();
  }

  requestAnimationFrame(updateProgress);
})();

// Player controls
function play() {
  if (audio.paused) {
    audio.play();
  } else {
    audio.pause();
  }
}

// These next/prev handlers are for playlists
// urls come from player template
function next() {
  // Try next track
  htmx.ajax('GET', next_track_url, {swap: 'none'});
}

function prev() {
  // Try prev track
  htmx.ajax('GET', prev_track_url, {swap: 'none'});
}
