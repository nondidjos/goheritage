/**
 * viewer-modes.js
 *
 * Data-type switcher for the project viewer: a row of segmented buttons at the
 * top of the viewer lets the visitor swap between the available data types —
 * Modèle 3D / Galerie / Plans / Nuage de points. Kept visually distinct from
 * the in-canvas Extérieur/Intérieur toggle so the two never read as competing
 * controls.
 *
 * It is ALSO responsible for lazily creating the point-cloud pane's iframe
 * (into ?pointcloud=1) on first activation, so a second WebGL context only
 * boots when that pane is actually viewed. Crucially, that iframe creation must
 * run even when there's no button row — a project whose ONLY data type is a
 * point cloud renders a single pane with no switcher, and it still needs its
 * iframe injected. Switching a pane dispatches `viewer:mode-change` so
 * viewer.js / plan-viewer.js can lazy-load resources on first activation.
 *
 * Loaded as an external file (not inline) so it can never be rendered as
 * literal text by an upstream HTML-parsing hiccup in the template.
 */
(function () {
  function init() {
    var container = document.getElementById('viewer-container');
    if (!container) return;

    var panes = container.querySelectorAll('[data-mode-pane]');
    var btns = [];

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

    // Initialise the active mode + create the point-cloud iframe if it's the
    // default. This runs BEFORE (and independently of) the button row — a
    // point-cloud-only project has a single pane and no switcher, but still
    // needs its iframe injected, otherwise the pane is a blank black stage.
    var initial = 'model';
    panes.forEach(function (p) {
      if (p.classList.contains('is-active')) initial = p.getAttribute('data-mode-pane');
    });
    container.setAttribute('data-active-mode', initial);
    if (initial === 'pointcloud') ensurePointcloudIframe();

    // Button wiring — only present when there's more than one data type.
    var sw = document.getElementById('viewer-switch');
    if (!sw) return;
    btns = Array.prototype.slice.call(sw.querySelectorAll('.viewer-modes__btn'));
    if (!btns.length) return;

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
