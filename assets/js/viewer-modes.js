/**
 * viewer-modes.js
 *
 * Data-type switcher for the project viewer. A single dropdown (NOT a
 * segmented pill row) lets the visitor swap between the available data types
 * — Modèle 3D / Galerie / Plans / Nuage de points. Kept visually distinct
 * from the in-canvas Extérieur/Intérieur toggle so the two never read as
 * competing controls.
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

    var trigger   = sw.querySelector('.viewer-switch__trigger');
    var menu      = sw.querySelector('.viewer-switch__menu');
    var opts      = sw.querySelectorAll('.viewer-switch__opt');
    var labelEl   = sw.querySelector('[data-switch-label]');
    var icoEl     = sw.querySelector('[data-switch-ico]');

    var optList = Array.prototype.slice.call(opts);
    // Roving tabindex: options are not in the tab order; arrow keys move a
    // single focusable option (ARIA listbox pattern).
    optList.forEach(function (o) { o.setAttribute('tabindex', '-1'); });

    function activeIndex() {
      var i = optList.findIndex(function (o) {
        return o.getAttribute('aria-selected') === 'true';
      });
      return i < 0 ? 0 : i;
    }
    function focusOption(i) {
      if (!optList.length) return;
      var idx = (i + optList.length) % optList.length;
      optList[idx].focus();
    }

    function closeMenu(returnFocus) {
      sw.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      if (returnFocus) trigger.focus();
    }
    function openMenu(focusIdx) {
      sw.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      focusOption(typeof focusIdx === 'number' ? focusIdx : activeIndex());
    }

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
      // Reflect selection in the dropdown trigger.
      opts.forEach(function (o) {
        var match = o.getAttribute('data-mode-target') === target;
        o.classList.toggle('is-active', match);
        o.setAttribute('aria-selected', match ? 'true' : 'false');
        if (match) {
          var ico = o.querySelector('.viewer-switch__opt-ico');
          var txt = o.querySelector('span:not(.viewer-switch__opt-ico)');
          if (ico && icoEl)  icoEl.innerHTML = ico.innerHTML;
          if (txt && labelEl) labelEl.textContent = txt.textContent;
        }
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

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (sw.classList.contains('is-open')) closeMenu(); else openMenu();
    });

    // Keyboard on the trigger: Down/Up or Enter/Space opens and focuses.
    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openMenu();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        openMenu(optList.length - 1);
      }
    });

    function choose(o) {
      setMode(o.getAttribute('data-mode-target'));
      closeMenu(true);
    }

    optList.forEach(function (o, i) {
      o.addEventListener('click', function () { choose(o); });
      o.addEventListener('keydown', function (e) {
        switch (e.key) {
          case 'ArrowDown': e.preventDefault(); focusOption(i + 1); break;
          case 'ArrowUp':   e.preventDefault(); focusOption(i - 1); break;
          case 'Home':      e.preventDefault(); focusOption(0); break;
          case 'End':       e.preventDefault(); focusOption(optList.length - 1); break;
          case 'Enter':
          case ' ':         e.preventDefault(); choose(o); break;
          case 'Escape':    e.preventDefault(); closeMenu(true); break;
          case 'Tab':       closeMenu(); break;
        }
      });
    });

    // Dismiss on outside click / Escape (when focus isn't already in the menu).
    document.addEventListener('click', function (e) {
      if (!sw.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sw.classList.contains('is-open')) closeMenu(true);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
