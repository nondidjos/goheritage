/**
 * viewer-modes.js
 *
 * Floating chip swapper for the project viewer (3D / Galerie / Plans).
 * Active chip + pane share the .is-active class. Switching panes
 * dispatches a `viewer:mode-change` event so viewer.js / plan-viewer.js
 * can lazy-load resources on first activation.
 *
 * Loaded as an external file (not inline) so it can never be rendered as
 * literal text by an upstream HTML-parsing hiccup in the template.
 */
(function () {
  function init() {
    var container = document.getElementById('viewer-container');
    if (!container) return;

    var chips = container.querySelectorAll('.viewer-mode-chip');
    var panes = container.querySelectorAll('[data-mode-pane]');
    if (!chips.length) return;

    function setMode(target) {
      chips.forEach(function (c) {
        var match = c.getAttribute('data-mode-target') === target;
        c.classList.toggle('is-active', match);
        c.setAttribute('aria-selected', match ? 'true' : 'false');
      });
      panes.forEach(function (p) {
        var match = p.getAttribute('data-mode-pane') === target;
        p.classList.toggle('is-active', match);
      });
      // viewer.js appends its WebGL canvas + CSS2D label overlay DIRECTLY
      // to #viewer-container (not inside the model pane), so they'd stay
      // visible + capture the mouse in gallery/plans mode — making the
      // 3D model shift around behind the gallery. Tag the container with
      // the active mode so CSS can hide those stray elements off-model.
      container.setAttribute('data-active-mode', target);
      container.dispatchEvent(
        new CustomEvent('viewer:mode-change', { detail: { mode: target } })
      );
    }

    // Initialise the attribute to whichever pane starts active.
    var initial = 'model';
    panes.forEach(function (p) {
      if (p.classList.contains('is-active')) initial = p.getAttribute('data-mode-pane');
    });
    container.setAttribute('data-active-mode', initial);

    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        setMode(c.getAttribute('data-mode-target'));
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
