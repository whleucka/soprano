/**
 * Interpolate between two hex colors by ratio (0–1).
 */
function lerpColor(a, b, t) {
  var ah = parseInt(a.slice(1), 16);
  var bh = parseInt(b.slice(1), 16);
  var ar = (ah >> 16) & 0xff, ag = (ah >> 8) & 0xff, ab = ah & 0xff;
  var br = (bh >> 16) & 0xff, bg = (bh >> 8) & 0xff, bb = bh & 0xff;
  var r = Math.round(ar + (br - ar) * t);
  var g = Math.round(ag + (bg - ag) * t);
  var b2 = Math.round(ab + (bb - ab) * t);
  return 'rgb(' + r + ',' + g + ',' + b2 + ')';
}

/**
 * Apply gradient fills to all active map regions.
 */
function applyMapColors(el, countryData, maxVal) {
  Object.keys(countryData).forEach(function(code) {
    var count = countryData[code];
    if (!count) return;
    var ratio = maxVal > 1 ? Math.log(count) / Math.log(maxVal) : 1;
    ratio = Math.max(0, Math.min(1, ratio));
    var color = lerpColor('#dcfce7', '#16a34a', ratio);
    var path = el.querySelector('[data-code="' + code + '"]');
    if (path) path.style.fill = color;
  });
}

/**
 * Initialize the activity world map widget
 */
function initActivityMap(attempt) {
  attempt = attempt || 0;
  var el = document.getElementById('activity-world-map');
  if (!el || el.dataset.init === '1') return;
  if (typeof jsVectorMap === 'undefined') return;

  // On mobile, layout may not be complete yet when htmx:afterSettle fires
  // (e.g. sidebar HTMX swap races with widget load, squeezing the container).
  // Retry with setTimeout — more reliable than rAF on mobile browsers.
  if (el.offsetWidth === 0) {
    if (attempt < 20) {
      setTimeout(function() { initActivityMap(attempt + 1); }, 50);
    }
    return;
  }

  var countryData = JSON.parse(el.dataset.countries || '{}');
  var maxVal = parseInt(el.dataset.max, 10) || 1;

  // Determine the top country for auto-focus
  var topCountry = null;
  var topCount = 0;
  Object.keys(countryData).forEach(function(code) {
    if (countryData[code] > topCount) {
      topCount = countryData[code];
      topCountry = code;
    }
  });

  el._mapCountryData = countryData;
  el._mapMaxVal = maxVal;

  // Build focusOn config if there is activity data
  var focusConfig = topCountry ? { region: topCountry, animate: true } : undefined;

  try {
  el._mapObject = new jsVectorMap({
    selector: el,
    map: 'world',
    backgroundColor: 'transparent',
    zoomButtons: false,
    zoomOnScroll: false,
    zoomOnScrollSpeed: 3,
    zoomMax: 12,
    zoomMin: 1,
    zoomAnimate: true,
    focusOn: focusConfig,
    showTooltip: true,
    regionStyle: {
      initial: {
        fill: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '#313244' : '#e5e7eb',
        stroke: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '#45475a' : '#d1d5db',
        strokeWidth: 0.5,
      },
      hover: {
        fillOpacity: 0.8,
        cursor: 'pointer',
      },
    },
    onRegionTooltipShow: function(_event, tooltip, code) {
      var count = countryData[code] || 0;
      var name = tooltip.text();
      tooltip.text(name + ': ' + count.toLocaleString() + ' unique IPs');
    },
    onLoaded: function() {
      // Apply gradient fills directly to SVG paths — bypasses jsvectormap's
      // series scale which mishandles large value ranges.
      applyMapColors(el, countryData, maxVal);
      // On mobile the sidebar HTMX swap may widen the container shortly after
      // init. Force a resize + recolor after a brief delay to catch this.
      setTimeout(function() {
        if (el._mapObject) {
          el._mapObject.updateSize();
          applyMapColors(el, el._mapCountryData, el._mapMaxVal);
        }
      }, 300);
      // Add custom zoom buttons — the library's built-in buttons get
      // repositioned by the focusOn animation, so we manage our own.
      if (!el.querySelector('.map-zoom-btn')) {
        var zoomIn = document.createElement('button');
        zoomIn.className = 'map-zoom-btn map-zoom-in';
        zoomIn.innerHTML = '+';
        zoomIn.addEventListener('click', function() {
          var map = el._mapObject;
          map._setScale(map.scale * map.params.zoomStep, map._width / 2, map._height / 2, false, map.params.zoomAnimate);
        });
        var zoomOut = document.createElement('button');
        zoomOut.className = 'map-zoom-btn map-zoom-out';
        zoomOut.innerHTML = '&minus;';
        zoomOut.addEventListener('click', function() {
          var map = el._mapObject;
          map._setScale(map.scale / map.params.zoomStep, map._width / 2, map._height / 2, false, map.params.zoomAnimate);
        });
        el.appendChild(zoomIn);
        el.appendChild(zoomOut);
      }
    },
  });
  el.dataset.init = '1';
  } catch (e) {
    return;
  }

  // Use ResizeObserver to detect container size changes and reapply colors
  // after updateSize() redraws the SVG paths.
  if (typeof ResizeObserver !== 'undefined') {
    var resizeTimer;
    el._mapResizeObserver = new ResizeObserver(function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function() {
        if (el._mapObject) {
          el._mapObject.updateSize();
          applyMapColors(el, el._mapCountryData, el._mapMaxVal);
        }
      }, 100);
    });
    el._mapResizeObserver.observe(el);
  }
}

/**
 * Recolor the activity map base regions and data regions for the current theme.
 */
function updateMapTheme() {
  var el = document.getElementById('activity-world-map');
  if (!el || !el._mapObject) return;
  var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  var baseFill = isDark ? '#313244' : '#e5e7eb';
  var baseStroke = isDark ? '#45475a' : '#d1d5db';
  // Reset all regions to the base fill
  var paths = el.querySelectorAll('path[data-code]');
  paths.forEach(function(path) {
    path.style.fill = baseFill;
    path.style.stroke = baseStroke;
  });
  // Re-apply data colors on top
  if (el._mapCountryData && el._mapMaxVal) {
    applyMapColors(el, el._mapCountryData, el._mapMaxVal);
  }
}

/**
 * Read the dashboard palette out of the stylesheet.
 *
 * The charts are the reason these colours are CSS custom properties at all:
 * PHP used to ship a full Chart.js options object per chart with the grid and
 * axis colours baked into the JSON, so dark mode could not be fixed in the
 * stylesheet no matter what it said. Now the widget ships data plus a semantic
 * role and the colour is resolved here, against whichever theme is live.
 */
function chartPalette() {
  var css = getComputedStyle(document.documentElement);
  function v(name, fallback) {
    return (css.getPropertyValue(name) || '').trim() || fallback;
  }
  return {
    accent: v('--dash-accent', '#0d6e6e'),
    info: v('--dash-info', '#2563eb'),
    success: v('--dash-success', '#16a34a'),
    warning: v('--dash-warning', '#d97706'),
    danger: v('--dash-danger', '#dc2626'),
    primary: v('--dash-primary', '#7c3aed'),
    muted: v('--dash-muted', '#64748b'),
    grid: v('--dash-grid', 'rgba(15,23,42,0.07)'),
    tick: v('--dash-tick', '#64748b'),
    tooltipBg: v('--dash-tooltip-bg', '#1e293b'),
    tooltipFg: v('--dash-tooltip-fg', '#f8fafc'),
  };
}

/**
 * Translucent variant of a palette colour, for area fills.
 * Only #rrggbb is converted; anything else is returned untouched so a token
 * changed to rgb()/hsl() degrades to an opaque fill instead of breaking.
 */
function withAlpha(color, alpha) {
  if (!/^#[0-9a-f]{6}$/i.test(color)) return color;
  var n = parseInt(color.slice(1), 16);
  return 'rgba(' + ((n >> 16) & 0xff) + ',' + ((n >> 8) & 0xff) + ',' + (n & 0xff) + ',' + alpha + ')';
}

/**
 * Build one Chart from a canvas's data-chart spec.
 *
 * Spec shape (see templates/admin/widgets/_chart.html.twig):
 *   { type, labels, stacked?, datasets: [{label, data, role, fill?, axis?, type?}],
 *     axes?: { y: {title?, position?}, ... } }
 */
function buildChart(canvas) {
  var spec;
  try {
    spec = JSON.parse(canvas.dataset.chart || 'null');
  } catch (e) {
    return;
  }
  if (!spec || !spec.datasets) return;

  var existing = Chart.getChart(canvas);
  if (existing) existing.destroy();

  var p = chartPalette();
  var stacked = !!spec.stacked;

  var datasets = spec.datasets.map(function(ds) {
    var color = p[ds.role] || p.accent;
    var type = ds.type || spec.type;
    var out = {
      label: ds.label,
      data: ds.data,
      yAxisID: ds.axis || 'y',
      borderColor: color,
      backgroundColor: type === 'bar' ? color : (ds.fill ? withAlpha(color, 0.16) : color),
      fill: !!ds.fill,
    };
    if (ds.type) out.type = ds.type;
    if (type === 'bar') {
      out.borderWidth = 0;
      out.borderRadius = 3;
      out.maxBarThickness = 28;
    } else {
      out.borderWidth = 2;
      out.tension = 0.35;
      // No dots: a 90-day series is 90 dots on a 280px canvas, which reads as
      // noise. They come back on hover, where they're actually useful.
      out.pointRadius = 0;
      out.pointHoverRadius = 4;
      out.pointBackgroundColor = color;
    }
    return out;
  });

  var scales = {
    x: {
      stacked: stacked,
      grid: { display: false },
      border: { color: p.grid },
      ticks: { color: p.tick, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
    },
  };

  var axes = spec.axes || { y: {} };
  Object.keys(axes).forEach(function(id) {
    var axis = axes[id] || {};
    var right = axis.position === 'right';
    scales[id] = {
      position: axis.position || 'left',
      stacked: stacked,
      beginAtZero: true,
      // Only one axis draws gridlines across the plot; two overlapping sets of
      // horizontal lines at different intervals is unreadable.
      grid: { color: p.grid, drawOnChartArea: !right, drawTicks: false },
      border: { display: false },
      ticks: { color: p.tick, font: { size: 10 }, maxTicksLimit: 6, precision: 0 },
      title: axis.title
        ? { display: true, text: axis.title, color: p.tick, font: { size: 10 } }
        : { display: false },
    };
  });

  new Chart(canvas, {
    type: spec.type || 'line',
    data: { labels: spec.labels || [], datasets: datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: datasets.length > 1,
          position: 'bottom',
          labels: { color: p.tick, boxWidth: 8, boxHeight: 8, usePointStyle: true, font: { size: 11 } },
        },
        tooltip: {
          backgroundColor: p.tooltipBg,
          titleColor: p.tooltipFg,
          bodyColor: p.tooltipFg,
          borderColor: p.grid,
          borderWidth: 1,
          padding: 8,
          displayColors: true,
          boxWidth: 8,
          boxHeight: 8,
          usePointStyle: true,
        },
      },
      scales: scales,
    },
  });
}

/**
 * Build every chart canvas on the page.
 *
 * Called on every settle, so by default it skips canvases that already carry a
 * Chart — an htmx swap that replaced one widget shouldn't rebuild the other
 * five. `force` is for a theme flip, where the palette is already baked into
 * every existing chart and they all have to be constructed again.
 */
function renderCharts(force) {
  if (typeof Chart === 'undefined') return;
  document.querySelectorAll('canvas[data-chart]').forEach(function(canvas) {
    if (!force && Chart.getChart(canvas)) return;
    buildChart(canvas);
  });
}

/**
 * Apply dark/light theme based on the current toggle icon state.
 * Sets data-bs-theme on <html> and injects/removes the dark stylesheet.
 */
var appliedTheme = null;

function applyTheme() {
  var toggle = document.querySelector('#theme-toggle i');
  var isDark = toggle && toggle.classList.contains('bi-sun-fill');
  var theme = isDark ? 'dark' : 'light';
  var changed = theme !== appliedTheme;
  appliedTheme = theme;
  document.documentElement.setAttribute('data-bs-theme', theme);
  updateMapTheme();
  renderCharts(changed);
}

// Initialize on HTMX content swaps (covers widget load, theme toggle, etc.)
document.addEventListener('htmx:afterSettle', function() {
  applyTheme();
  initActivityMap();
});

// Initialize on regular page load (non-HTMX)
document.addEventListener('DOMContentLoaded', function() {
  applyTheme();
  initActivityMap();
});
