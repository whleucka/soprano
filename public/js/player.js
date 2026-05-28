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
