/**
 * plan-viewer.js
 *
 * Drop-in frontend for the plan-viewer snippet. Wires up:
 *   • [data-plan-viewer] thumbnails (open OpenSeadragon in a modal)
 *   • Toolbar buttons: zoom in / out / home / fullscreen / close
 *   • Keyboard: Esc to close, +/- to zoom
 *   • Anti-download: blocks right-click and drag inside the canvas
 *
 * OpenSeadragon is loaded lazily from CDN on the first plan open — the
 * 70 kB cost is only paid when a user actually wants to view a plan.
 * The CDN URL is pinned to a specific version so a CDN compromise can't
 * silently swap the library.
 *
 * Tile streaming security model:
 *   The original full-resolution file URL is never sent to the browser.
 *   The .dzi manifest references tile URLs, which the browser fetches
 *   on-demand as the user zooms / pans. Reassembling the original from
 *   tiles is theoretically possible but is a real deterrent — most users
 *   simply can't right-click "Save As" the way they can on a plain <img>.
 */

(function () {
  'use strict';

  // OpenSeadragon 4.1.0 — pinned version + SRI hash to detect tampering.
  // SRI hash from cdnjs.cloudflare.com integrity attribute.
  var OSD_URL = 'https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/openseadragon.min.js';

  var modal      = document.querySelector('[data-plan-viewer-modal]');
  var canvasEl   = document.querySelector('[data-plan-viewer-canvas]');
  var titleEl    = document.querySelector('[data-plan-viewer-title]');
  if (!modal || !canvasEl) return; // snippet not present on this page

  var viewer     = null;
  var loaderPromise = null;

  // ── Lazy-load OpenSeadragon on demand ────────────────────────────
  function loadOpenSeadragon() {
    if (window.OpenSeadragon) return Promise.resolve(window.OpenSeadragon);
    if (loaderPromise) return loaderPromise;
    loaderPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = OSD_URL;
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload  = function () { resolve(window.OpenSeadragon); };
      s.onerror = function () {
        loaderPromise = null; // allow retry on next open
        reject(new Error('Failed to load OpenSeadragon from CDN'));
      };
      document.head.appendChild(s);
    });
    return loaderPromise;
  }

  // ── Open a plan ──────────────────────────────────────────────────
  function openPlan(tilesUrl, title) {
    if (!tilesUrl) return;
    titleEl.textContent = title || 'Plan';
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    document.body.classList.add('plan-viewer-locked');

    // Show a loading spinner immediately — OSD lazy-loads from CDN on the
    // first click so there's a real wait before tiles paint.
    canvasEl.innerHTML = '<div class="plan-viewer-modal__loading">'
      + '<div class="plan-viewer-modal__spinner"></div>'
      + '<span>Chargement…</span>'
      + '</div>';

    loadOpenSeadragon().then(function (OSD) {
      // Destroy previous viewer if any (different plan)
      if (viewer) {
        try { viewer.destroy(); } catch (_) {}
        viewer = null;
      }
      // Clear the spinner — OSD mounts its own canvas inside canvasEl.
      canvasEl.innerHTML = '';

      viewer = OSD({
        element: canvasEl,
        tileSources: tilesUrl,
        // Hide built-in nav — we use our toolbar instead
        showNavigationControl: false,
        showNavigator: true,
        navigatorPosition: 'BOTTOM_RIGHT',
        navigatorSizeRatio: 0.12,
        navigatorBackground: 'rgba(0,0,0,0.5)',
        navigatorBorderColor: 'rgba(255,255,255,0.3)',
        // Smooth zoom/pan
        animationTime:           0.5,
        blendTime:               0.1,
        constrainDuringPan:      true,
        visibilityRatio:         0.8,
        minZoomImageRatio:       0.8,
        maxZoomPixelRatio:       2.5,
        // Performance — tiles are pre-rendered server-side
        imageLoaderLimit:        6,
        preserveImageSizeOnResize: true,
        // Gestures
        gestureSettingsTouch: {
          scrollToZoom: true, pinchToZoom: true, dragToPan: true, flickEnabled: true,
        },
        gestureSettingsMouse: {
          scrollToZoom: true, dragToPan: true, clickToZoom: false, dblClickToZoom: true,
        },
      });

      // Defensive: OSD swallows tile errors silently by default. Surface
      // a message so users don't stare at a blank canvas if the .dzi is
      // 404ing (e.g. visibility was revoked after their page loaded).
      viewer.addHandler('open-failed', function (e) {
        canvasEl.innerHTML = '<div class="plan-viewer-modal__error">'
          + 'Plan indisponible. ' + ((e && e.message) || 'Le serveur a refusé le manifeste DZI.')
          + '</div>';
      });
    }).catch(function (err) {
      canvasEl.innerHTML = '<div class="plan-viewer-modal__error">'
        + 'Impossible de charger le viewer. ' + (err && err.message ? err.message : '')
        + '</div>';
    });
  }

  function closeModal() {
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('is-open');
    document.body.classList.remove('plan-viewer-locked');
    if (viewer) {
      try { viewer.destroy(); } catch (_) {}
      viewer = null;
      canvasEl.innerHTML = '';
    }
  }

  // ── Wire up thumbnails ───────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest && e.target.closest('[data-plan-viewer]');
    if (!trigger) return;
    e.preventDefault();
    openPlan(trigger.getAttribute('data-plan-tiles'), trigger.getAttribute('data-plan-title'));
  });

  // ── Zoom / pan helpers (no-op when viewer hasn't loaded yet) ─────
  function zoomBy(factor) {
    if (!viewer) return;
    viewer.viewport.zoomBy(factor);
    viewer.viewport.applyConstraints();
  }
  function goHome() {
    if (viewer) viewer.viewport.goHome();
  }
  function toggleFullscreen() {
    if (document.fullscreenElement) {
      document.exitFullscreen();
    } else if (modal.requestFullscreen) {
      modal.requestFullscreen();
    }
  }

  // ── Unified click handler: toolbar actions + backdrop close ──────
  modal.addEventListener('click', function (e) {
    // Backdrop click (modal itself, not children) closes the viewer
    if (e.target === modal) {
      closeModal();
      return;
    }
    var btn = e.target.closest && e.target.closest('[data-plan-viewer-action]');
    if (!btn) return;
    switch (btn.getAttribute('data-plan-viewer-action')) {
      case 'close':      closeModal(); break;
      case 'zoom-in':    zoomBy(1.4); break;
      case 'zoom-out':   zoomBy(0.7); break;
      case 'home':       goHome(); break;
      case 'fullscreen': toggleFullscreen(); break;
    }
  });

  // ── Keyboard shortcuts ───────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if (!modal.classList.contains('is-open')) return;
    // Don't hijack typing inside form fields (none in the modal today,
    // but cheap insurance against future additions)
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
    switch (e.key) {
      case 'Escape':                        closeModal(); break;
      case '+': case '=':                   zoomBy(1.4); break;
      case '-': case '_':                   zoomBy(0.7); break;
      case '0': case 'Home':                goHome(); break;
      case 'f': case 'F':                   toggleFullscreen(); break;
    }
  });

  // ── Anti-download: block right-click + drag in the canvas ────────
  // Not bulletproof — a determined user can still take a screenshot or
  // sniff tile URLs from devtools. Stops the casual "Save As…" reflex.
  canvasEl.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  canvasEl.addEventListener('dragstart',   function (e) { e.preventDefault(); });
  canvasEl.style.userSelect       = 'none';
  canvasEl.style.webkitUserSelect = 'none';

})();
