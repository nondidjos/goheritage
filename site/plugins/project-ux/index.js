/**
 * project-ux panel plugin
 *
 * Two header-level UX pieces for project pages:
 *
 *   • k-visibility-view-button (viewButton)
 *       Compact pill in the page header that shows + switches the page's
 *       visibility (private / link / public). Theme-adaptive, neutral
 *       background, white icon + label; the dropdown surfaces the share
 *       URL inline when in "link" mode.
 *
 *   • per-section edit injector
 *       A DOM-level enhancement that finds every editable .k-section on a
 *       project page, tags it with a `.gh-section` class so our card CSS
 *       can take over (white card, rounded, padded — not Kirby's stock
 *       chrome), and injects a floating Modifier / Enregistrer / Annuler
 *       button group in the top-right corner. Sections start read-only;
 *       clicking Modifier unlocks just that section.
 *
 *       Why DOM-injection (not a custom field type): the prior approach
 *       used `_edit_*: { type: section-edit-control }` rows in the
 *       blueprint, which collided on field-name uniqueness and rendered
 *       awkwardly because k-field's wrapper imposes layout. The injector
 *       bypasses Kirby's field system entirely.
 *
 *   • share-link field (kept for backward compat)
 */

panel.plugin('goheritage/project-ux', {

  // ── Header view button ──────────────────────────────────────────────
  viewButtons: {
    visibility: {
      template: /* html */`
        <div class="gh-visibility" :data-state="current">
          <k-button
            class="gh-visibility__trigger"
            :icon="currentOption.icon"
            :text="currentOption.label"
            :title="'Visibilité : ' + currentOption.label"
            variant="filled"
            theme="passive"
            size="sm"
            @click="toggleOpen"
          />

          <div
            v-if="open"
            class="gh-visibility__panel"
            role="menu"
            @click.stop
          >
            <button
              v-for="opt in options"
              :key="opt.value"
              type="button"
              class="gh-visibility__opt"
              :class="{ 'is-active': current === opt.value }"
              role="menuitemradio"
              :aria-checked="current === opt.value ? 'true' : 'false'"
              @click="select(opt.value)"
            >
              <k-icon :type="opt.icon" class="gh-visibility__opt-icon" />
              <span class="gh-visibility__opt-text">
                <strong>{{ opt.label }}</strong>
                <span class="gh-visibility__opt-help">{{ opt.help }}</span>
              </span>
              <k-icon
                v-if="current === opt.value"
                type="check"
                class="gh-visibility__opt-check"
              />
            </button>

            <div v-if="current === 'link'" class="gh-visibility__share">
              <label class="gh-visibility__share-label">
                <k-icon type="url" /> Lien de partage
              </label>
              <div v-if="shareUrl" class="gh-visibility__share-row">
                <input
                  ref="shareInput"
                  type="text"
                  :value="shareUrl"
                  readonly
                  @click="$event.target.select()"
                />
                <button
                  type="button"
                  class="gh-visibility__share-copy"
                  @click="copyShareUrl"
                  title="Copier le lien"
                >
                  <k-icon type="copy" />
                </button>
              </div>
              <p v-else class="gh-visibility__share-empty">
                <k-icon type="info" /> Enregistrez la page pour générer un lien.
              </p>
            </div>
          </div>
        </div>
      `,

      data() {
        return {
          open: false,
          options: [
            { value: 'private', label: 'Privé',         icon: 'lock',  help: 'Vous et les administrateurs uniquement.' },
            { value: 'link',    label: 'Avec un lien',  icon: 'url',   help: 'Accessible via un lien partagé.'         },
            { value: 'public',  label: 'Public',        icon: 'globe', help: 'Listé sur la carte du site.'             },
          ],
        };
      },

      computed: {
        model()   { return this.$panel?.view?.props?.model ?? null; },
        content() { return this.model?.content ?? {};               },
        current() {
          const v = this.content.visibility;
          if (v === 'private' || v === 'link' || v === 'public') return v;
          return this.model?.status === 'listed' ? 'public' : 'private';
        },
        currentOption() {
          return this.options.find(o => o.value === this.current) || this.options[0];
        },
        shareUrl() {
          const token = this.content.share_token;
          const base  = this.model?.previewUrl || this.model?.link;
          if (!token || !base) return null;
          return base.split('?')[0].split('#')[0] + '?key=' + token;
        },
      },

      mounted() {
        this._docHandler = (e) => {
          if (!this.open) return;
          if (this.$el && !this.$el.contains(e.target)) this.open = false;
        };
        document.addEventListener('click', this._docHandler);
        this._escHandler = (e) => { if (e.key === 'Escape') this.open = false; };
        document.addEventListener('keydown', this._escHandler);
      },

      beforeDestroy() {
        if (this._docHandler) document.removeEventListener('click', this._docHandler);
        if (this._escHandler) document.removeEventListener('keydown', this._escHandler);
      },

      methods: {
        toggleOpen(e) { e?.stopPropagation?.(); this.open = !this.open; },

        async select(value) {
          if (value === this.current) { this.open = false; return; }

          const needsConfirm =
            this.current === 'private' && (value === 'link' || value === 'public');
          if (needsConfirm) {
            const isPublic = value === 'public';
            const proceed = await new Promise((resolve) => {
              this.$panel.dialog.open({
                component: 'k-text-dialog',
                props: {
                  icon: isPublic ? 'globe' : 'url',
                  text: isPublic
                    ? '<strong>Rendre cette page publique ?</strong><br><br>Elle apparaîtra sur la carte GoHéritage et pourra être indexée.'
                    : '<strong>Activer le partage par lien ?</strong><br><br>Toute personne avec le lien pourra accéder à la page sans compte.',
                  submitButton: { text: 'Confirmer', icon: 'check', theme: 'positive' },
                  cancelButton: { text: 'Annuler' },
                },
                on: {
                  submit: () => { this.$panel.dialog.close(); resolve(true);  },
                  cancel: () => { this.$panel.dialog.close(); resolve(false); },
                  close:  () => resolve(false),
                },
              });
            });
            if (!proceed) return;
          }

          await this.commit(value);
        },

        async commit(value) {
          if (!this.model) return;
          try {
            if (this.$panel.content?.update) {
              await this.$panel.content.update({ visibility: value });
              await this.$panel.content.publish();
            } else {
              await this.$api.patch(
                'pages/' + this.model.id.replace(/\//g, '+'),
                { visibility: value }
              );
            }
            await this.$panel.view.reload();
            this.$panel.notification.success('Visibilité mise à jour');
            this.open = false;
          } catch (e) {
            this.$panel.notification.error(
              'Impossible de changer la visibilité : ' + (e.message || 'erreur inconnue')
            );
          }
        },

        copyShareUrl() {
          const url = this.shareUrl;
          if (!url) return;
          const ok   = () => this.$panel.notification.success('Lien copié');
          const fail = () => this.$panel.notification.error('Impossible de copier — sélectionnez manuellement.');
          if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(url).then(ok, () => this._legacyCopy(ok, fail));
          } else {
            this._legacyCopy(ok, fail);
          }
        },

        _legacyCopy(ok, fail) {
          const el = this.$refs.shareInput;
          if (!el) return fail();
          el.select();
          try { document.execCommand('copy'); ok(); } catch (_) { fail(); }
        },
      },
    },
  },

  // ── Backward-compat share-link field ────────────────────────────────
  fields: {
    'share-link': {
      template: `
        <k-field v-bind="$props" class="k-share-link-field">
          <div v-if="shareUrl" class="goheritage-share-link">
            <k-icon type="url" class="goheritage-share-link__icon" />
            <input ref="urlInput" type="text" :value="shareUrl" readonly @click="$event.target.select()" />
            <k-button icon="copy" size="sm" variant="filled" @click="copyToClipboard">Copier</k-button>
          </div>
          <p v-else class="goheritage-share-link__empty">
            <k-icon type="info" /> Enregistrez la page pour générer un lien partageable.
          </p>
        </k-field>
      `,
      props: { shareUrl: { type: String, default: null } },
      methods: {
        copyToClipboard() {
          const url = this.shareUrl;
          if (!url) return;
          if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(url).then(
              () => this.$panel.notification.success('Lien copié'),
              () => this._legacy(),
            );
          } else {
            this._legacy();
          }
        },
        _legacy() {
          if (this.$refs.urlInput) {
            this.$refs.urlInput.select();
            document.execCommand('copy');
            this.$panel.notification.success('Lien copié');
          }
        },
      },
    },
  },
});


// ──────────────────────────────────────────────────────────────────────
// Per-section edit injector
//
// Runs as a plain DOM enhancement, outside the Vue plugin system, so it
// works regardless of how Kirby's panel re-mounts components. The goal:
// every .k-fields-section on a project page gets a floating action chip
// at top-right (Modifier in view mode → Enregistrer / Annuler in edit
// mode) AND a `.gh-section` tag so our card CSS replaces Kirby's chrome.
//
// Single-section edit lock — only one section is editable at a time,
// so Annuler can safely call $panel.view.reload() to discard pending
// changes without trampling unsaved edits in another section.
// ──────────────────────────────────────────────────────────────────────
(function () {
  if (typeof window === 'undefined') return;

  // Body-class flag we'll add when on a project page. CSS uses it to
  // scope the whole custom-card style override to the project view only.
  var BODY_FLAG = 'gh-project-edit';
  var SECTION_CLASS = 'gh-section';
  var EDITING_CLASS = 'is-editing';
  var DISABLED_CLASS = 'is-disabled';

  // Global single-section edit lock.
  var editingToken = 0;
  var allButtons   = [];  // { el, refresh() } — for cross-section disable

  function isProjectPage() {
    try {
      var props = window.panel?.view?.props;
      var bp = props?.model?.blueprint || props?.blueprint;
      return bp === 'project' || (typeof bp === 'string' && bp.endsWith('/project'));
    } catch (_) { return false; }
  }

  // True if the section contains at least one user-editable field — i.e.
  // we should attach an edit button. Info-only sections, fully-hidden
  // sections, and the page-files-list inventory are skipped.
  function sectionIsEditable(section) {
    if (!section) return false;
    // Skip sections that are purely info display.
    var fields = section.querySelectorAll('.k-field');
    if (!fields.length) return false;
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      // Skip info / hidden / our own button wrapper.
      if (f.classList.contains('k-info-field'))   continue;
      if (f.classList.contains('k-hidden-field')) continue;
      // Anything else counts as editable content.
      return true;
    }
    return false;
  }

  /*  Build an HTML element that mimics Kirby's own <k-button> output so
   *  Kirby's stylesheet handles hover / focus / disabled / dark theme /
   *  size tokens for us. We pass theme via data-theme; passive = neutral,
   *  positive = primary action (Save), negative = destructive.        */
  function makeButton(label, iconType, theme) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'k-button gh-edit-btn';
    btn.setAttribute('data-variant', 'filled');
    btn.setAttribute('data-theme', theme);
    btn.setAttribute('data-size', 'sm');
    btn.innerHTML =
      '<span class="k-button-icon">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          iconSvg(iconType) +
        '</svg>' +
      '</span>' +
      '<span class="k-button-text">' + label + '</span>';
    return btn;
  }

  // Inline SVG paths so we don't depend on Kirby's icon font sprite being
  // available in every panel state.
  function iconSvg(name) {
    switch (name) {
      case 'edit':
        return '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
               '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>';
      case 'check':
        return '<polyline points="20 6 9 17 4 12"/>';
      case 'cancel':
      case 'x':
        return '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';
      case 'loader':
        return '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
               '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
               '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
               '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>';
      default:
        return '';
    }
  }

  function tag(section) {
    // Always tag (so the card styling applies to info / file sections too)
    if (!section.classList.contains(SECTION_CLASS)) {
      section.classList.add(SECTION_CLASS);
    }
  }

  function attach(section) {
    tag(section);
    if (section.dataset.ghAttached === '1') return;
    section.dataset.ghAttached = '1';

    var dock = document.createElement('div');
    dock.className = 'gh-section__dock';

    // Three Kirby-themed buttons — themes map onto Kirby's button colour
    // scale: passive (neutral), positive (primary save), passive (cancel).
    var editBtn   = makeButton('Modifier',    'edit',   'passive');
    var saveBtn   = makeButton('Enregistrer', 'check',  'positive');
    var cancelBtn = makeButton('Annuler',     'cancel', 'passive');

    saveBtn.style.display   = 'none';
    cancelBtn.style.display = 'none';

    dock.appendChild(editBtn);
    dock.appendChild(cancelBtn);
    dock.appendChild(saveBtn);

    /*  Place the dock INSIDE the section's .k-section-header so it lives
     *  inline with the headline (no more absolute overlap). Fall back to
     *  prepending to the section body if a section has no header at all
     *  (rare — only sections without a `headline:` in the blueprint).   */
    var header = section.querySelector(':scope > .k-section-header')
              || section.querySelector('.k-section-header');
    if (header) {
      header.appendChild(dock);
    } else {
      section.prepend(dock);
    }

    var myToken = 0;

    function setEditing(on) {
      section.classList.toggle(EDITING_CLASS, on);
      editBtn.style.display   = on ? 'none' : '';
      saveBtn.style.display   = on ? '' : 'none';
      cancelBtn.style.display = on ? '' : 'none';
    }

    function refresh() {
      var disabled = editingToken !== 0 && editingToken !== myToken;
      section.classList.toggle(DISABLED_CLASS, disabled && !section.classList.contains(EDITING_CLASS));
      editBtn.disabled = disabled;
    }
    allButtons.push({ refresh: refresh });

    editBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (editingToken !== 0 && editingToken !== myToken) return;
      myToken = Date.now() + Math.random();
      editingToken = myToken;
      setEditing(true);
      allButtons.forEach(function (b) { b.refresh(); });
    });

    cancelBtn.addEventListener('click', async function (e) {
      e.stopPropagation();
      try { await window.panel?.view?.reload?.(); } catch (_) {}
      myToken = 0;
      editingToken = 0;
      setEditing(false);
      allButtons.forEach(function (b) { b.refresh(); });
    });

    saveBtn.addEventListener('click', async function (e) {
      e.stopPropagation();
      saveBtn.classList.add('is-loading');
      saveBtn.disabled = true;
      try {
        if (window.panel?.content?.publish) {
          await window.panel.content.publish();
        }
        window.panel?.notification?.success?.('Section enregistrée');
        myToken = 0;
        editingToken = 0;
        setEditing(false);
        allButtons.forEach(function (b) { b.refresh(); });
      } catch (err) {
        window.panel?.notification?.error?.(err?.message || 'Impossible d\'enregistrer.');
      } finally {
        saveBtn.classList.remove('is-loading');
        saveBtn.disabled = false;
      }
    });

    refresh();
  }

  /*  Measure Kirby's .k-header height and publish it on <body> as
   *  --gh-header-height. CSS uses this var to stick the tabs just
   *  below the header (Kirby's own .k-header is already sticky at
   *  top:0). Throttled via rAF — runs cheap even on every mutation. */
  var _headerMeasureScheduled = false;
  function measureHeader() {
    if (_headerMeasureScheduled) return;
    _headerMeasureScheduled = true;
    requestAnimationFrame(function () {
      _headerMeasureScheduled = false;
      var header = document.querySelector('.k-page-view > .k-header')
                || document.querySelector('.k-header');
      if (!header) return;
      var h = header.getBoundingClientRect().height;
      if (h > 0) {
        document.body.style.setProperty('--gh-header-height', h + 'px');
      }
    });
  }

  function scan() {
    if (!isProjectPage()) {
      document.body.classList.remove(BODY_FLAG);
      document.body.style.removeProperty('--gh-header-height');
      return;
    }
    document.body.classList.add(BODY_FLAG);
    measureHeader();

    // Tag EVERY .k-section on the page so the card styling applies
    // uniformly (info-only sections + file sections get the same chrome
    // as fields sections). Only fields-sections with actual editable
    // fields get the action dock attached.
    var sections = document.querySelectorAll('.k-section');
    sections.forEach(function (s) {
      // Skip sections that hold only hidden fields (no visible content)
      // — they'd render as empty card boxes which is worse than missing.
      if (s.classList.contains('k-fields-section')) {
        var hasVisible = false;
        var fields = s.querySelectorAll('.k-field');
        for (var i = 0; i < fields.length; i++) {
          if (!fields[i].classList.contains('k-hidden-field')) {
            hasVisible = true;
            break;
          }
        }
        if (!hasVisible) return;
      }
      tag(s);
      if (s.classList.contains('k-fields-section') && sectionIsEditable(s)) {
        attach(s);
      }
    });
  }

  // Re-scan whenever the panel re-renders (route change, view reload).
  var rescan = function () {
    // Reset state — Vue may have torn down previous DOM.
    allButtons = [];
    editingToken = 0;
    setTimeout(scan, 50);
  };

  // Run once on load, then watch for SPA-style navigation.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rescan);
  } else {
    rescan();
  }

  // The panel mutates document.body and the view root on navigation. A
  // MutationObserver on body is the cheapest reliable way to detect
  // Kirby's view re-renders without coupling to its internals.
  var mo = new MutationObserver(function () {
    // Don't thrash — re-scan only when a candidate section appears
    // that we haven't tagged yet.
    var pending = document.querySelector('.k-fields-section:not([data-gh-attached])');
    if (pending) rescan();
    // Header height can shift as buttons/title load. Re-measure cheaply.
    measureHeader();
  });
  mo.observe(document.body, { childList: true, subtree: true });

  // Window resize → h1 may wrap to a different line count, header height
  // changes. Re-measure so the sticky tab top: offset stays correct.
  window.addEventListener('resize', measureHeader, { passive: true });
})();
