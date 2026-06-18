/**
 * viewer-modes.js
 *
 * Data-type switcher for the project viewer: a row of segmented buttons at the
 * top of the viewer lets the visitor swap between the available data types —
 * Modèle 3D / Galerie / Plans / Nuage de points. Kept visually distinct from
 * the in-canvas Extérieur/Intérieur toggle so the two never read as competing
 * controls.
 *
 * Switching a pane dispatches a `viewer:mode-change` event so viewer.js /
 * plan-viewer.js can lazy-load resources on first activation. The point-cloud
 * pane is itself a lazy iframe into ?pointcloud=1, created on first open so we
 * don't boot a second WebGL context unless it's actually viewed.
 *
 * Loaded as an external file (not inline) so it can never be rendered as
 * literal text by an upstream HTML-parsing hiccup in the template.
 */
(function () {
  function init() {
    var container = document.getElementById('viewer-container');
    if (!container) return;

    var sw = document.getElementById('viewer-switch');
    var panes = container.querySelectorAll('[data-mode-pane]');
    if (!sw) return;

    var btns = Array.prototype.slice.call(sw.querySelectorAll('.viewer-modes__btn'));
    if (!btns.length) return;

    // Create the point-cloud iframe the first time that pane is shown.
    function ensurePointcloudIframe() {
      var pane = container.querySelector('[data-mode-pane="pointcloud"]');
      if (!pane) return;
      if (pane.querySelector('iframe')) return;
      var src = pane.getAttribute('data-pc-src');
      if (!src) return;
      var ifr = document.createElement('iframe');
      ifr.src = src;
      ifr.className = 'viewer-pane__frame';
      ifr.setAttribute('allow', 'xr-spatial-tracking; fullscreen');
      ifr.setAttribute('allowfullscreen', '');
      pane.appendChild(ifr);
    }

    function setMode(target) {
      btns.forEach(function (b) {
        var match = b.getAttribute('data-mode-target') === target;
        b.classList.toggle('is-active', match);
        b.setAttribute('aria-selected', match ? 'true' : 'false');
      });

      if (target === 'pointcloud') ensurePointcloudIframe();

      panes.forEach(function (p) {
        p.classList.toggle('is-active', p.getAttribute('data-mode-pane') === target);
      });

      // viewer.js appends its WebGL canvas + CSS2D label overlay DIRECTLY to
      // #viewer-container (not inside the model pane), so they'd stay visible
      // and capture the mouse off-model. Tag the container with the active
      // mode so CSS can hide those stray elements.
      container.setAttribute('data-active-mode', target);
      container.dispatchEvent(
        new CustomEvent('viewer:mode-change', { detail: { mode: target } })
      );
    }

    // Initialise data-active-mode from whichever pane starts active.
    var initial = 'model';
    panes.forEach(function (p) {
      if (p.classList.contains('is-active')) initial = p.getAttribute('data-mode-pane');
    });
    container.setAttribute('data-active-mode', initial);
    if (initial === 'pointcloud') ensurePointcloudIframe();

    btns.forEach(function (b, i) {
      b.addEventListener('click', function () {
        setMode(b.getAttribute('data-mode-target'));
      });
      // Left/Right arrows move between buttons (ARIA tablist pattern).
      b.addEventListener('keydown', function (e) {
        var j = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') j = (i + 1) % btns.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') j = (i - 1 + btns.length) % btns.length;
        else if (e.key === 'Home') j = 0;
        else if (e.key === 'End') j = btns.length - 1;
        else return;
        e.preventDefault();
        btns[j].focus();
        setMode(btns[j].getAttribute('data-mode-target'));
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
