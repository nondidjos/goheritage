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

  // ── Custom section component ────────────────────────────────────────
  //
  //  project-overview is the single source of truth for the Aperçu tab.
  //  Renders a Matterport-style card with cover image, meta chips,
  //  description, tags, and asset-tile rows. Editing happens via
  //  k-form-dialog opened on click — no inline form chrome.
  sections: {
    'project-overview': {
      props: [
        'pageId', 'pageTitle',
        'coverUrl',
        'description', 'location', 'constructionDate', 'scanDate',
        'architect', 'style', 'dimensions', 'protectionStatus',
        'lat', 'lng',
        'tags', 'primaryTag',
        'has3dModel', 'modelSidesSummary',
        'galleryCount', 'plansCount', 'docsCount',
        'hotspotsCount', 'contentBlocksCount',
      ],
      template: /* html */`
        <section class="gh-pov">
          <!-- Cover image (or empty state with CTA) -->
          <button
            type="button"
            class="gh-pov__cover"
            :class="{ 'gh-pov__cover--empty': !coverUrl }"
            @click="editFields('cover', { cover: 'cover' })"
          >
            <img v-if="coverUrl" :src="coverUrl" :alt="pageTitle">
            <div v-else class="gh-pov__cover-empty">
              <k-icon type="image" />
              <span>Ajouter une image de couverture</span>
            </div>
            <span class="gh-pov__cover-edit">
              <k-icon type="edit" />
              Modifier
            </span>
          </button>

          <!-- Title bar + edit -->
          <header class="gh-pov__head">
            <h1 class="gh-pov__title">{{ pageTitle }}</h1>
            <button
              type="button"
              class="gh-pov__head-edit"
              @click="editFields('description', { description: 'description' })"
              title="Modifier la description"
            >
              <k-icon type="edit" />
            </button>
          </header>

          <!-- Description -->
          <p v-if="description" class="gh-pov__desc">{{ description }}</p>
          <p v-else class="gh-pov__desc gh-pov__desc--empty">
            Aucune description. Cliquez sur le crayon pour en ajouter une.
          </p>

          <!-- Meta chips: clicking any opens the Caractéristiques editor -->
          <div class="gh-pov__chips">
            <button class="gh-pov__chip" @click="editMeta">
              <k-icon type="pin" />
              <span>{{ location || 'Lieu inconnu' }}</span>
            </button>
            <button class="gh-pov__chip" @click="editMeta">
              <k-icon type="calendar" />
              <span>{{ constructionDate || 'Date inconnue' }}</span>
            </button>
            <button v-if="architect" class="gh-pov__chip" @click="editMeta">
              <k-icon type="user" />
              <span>{{ architect }}</span>
            </button>
            <button v-if="style" class="gh-pov__chip" @click="editMeta">
              <k-icon type="brush" />
              <span>{{ style }}</span>
            </button>
            <button v-if="dimensions" class="gh-pov__chip" @click="editMeta">
              <k-icon type="dimensions" />
              <span>{{ dimensions }}</span>
            </button>
            <button v-if="protectionStatus && protectionStatus !== 'Non protégé'" class="gh-pov__chip gh-pov__chip--accent" @click="editMeta">
              <k-icon type="protected" />
              <span>{{ protectionStatus }}</span>
            </button>
          </div>

          <!-- Tags -->
          <div class="gh-pov__tags-row">
            <span class="gh-pov__section-label">Tags</span>
            <div class="gh-pov__tags">
              <span v-for="t in tags" :key="t" class="gh-pov__tag" :class="{ 'is-primary': t === primaryTag }">{{ t }}</span>
              <span v-if="!tags.length" class="gh-pov__tag gh-pov__tag--empty">Aucun</span>
            </div>
            <button class="gh-pov__inline-edit" @click="editTags"><k-icon type="edit" />Modifier</button>
          </div>

          <!-- Assets grid: each tile shows what's there + opens to the right place -->
          <div class="gh-pov__assets">
            <button class="gh-pov__asset" @click="openTab('model')">
              <k-icon type="box" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Modèle 3D</strong>
                <span>{{ modelSidesSummary }}{{ hotspotsCount ? ' · ' + hotspotsCount + ' point' + (hotspotsCount > 1 ? 's' : '') + ' d’intérêt' : '' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('documents')">
              <k-icon type="image" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Plans &amp; relevés</strong>
                <span>{{ plansCount ? plansCount + ' fichier' + (plansCount > 1 ? 's' : '') : 'Aucun plan' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('documents')">
              <k-icon type="file-document" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Autres documents</strong>
                <span>{{ docsCount ? docsCount + ' fichier' + (docsCount > 1 ? 's' : '') : 'Aucun document' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="editGallery">
              <k-icon type="images" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Galerie</strong>
                <span>{{ galleryCount ? galleryCount + ' image' + (galleryCount > 1 ? 's' : '') : 'Aucune image' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="editContent">
              <k-icon type="text" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Contenu détaillé</strong>
                <span>{{ contentBlocksCount ? contentBlocksCount + ' bloc' + (contentBlocksCount > 1 ? 's' : '') + ' éditorial' + (contentBlocksCount > 1 ? 'aux' : '') : 'Vide' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="editFields('geo', { location_search: 'location-search', lat: 'number', lng: 'number' })">
              <k-icon type="map" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Géolocalisation</strong>
                <span>{{ lat && lng ? lat + ', ' + lng : 'Coordonnées non définies' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>
          </div>
        </section>
      `,

      methods: {
        // Switch to a different tab of the same page view.
        openTab(name) {
          if (window.panel?.view?.open) {
            window.panel.view.open(window.location.pathname + '?tab=' + name);
          } else {
            window.location.search = '?tab=' + name;
          }
        },

        // Opens a k-form-dialog with the requested fields, scoped to the
        // current page. submitButton publishes the changes via the panel
        // content API.
        editFields(slug, fieldsSpec) {
          const fields = {};
          const initialValue = {};
          // Expand the compact field spec into k-form-dialog field defs.
          const defs = {
            cover:       { type: 'files', label: 'Image de couverture', layout: 'cards', max: 1, multiple: false, query: 'page.images' },
            description: { type: 'textarea', label: 'Description courte', size: 'small' },
            location:    { type: 'text', label: 'Lieu', icon: 'pin' },
            construction_date: { type: 'text', label: 'Date de construction' },
            date:        { type: 'date', label: 'Date du scan' },
            architect:   { type: 'text', label: 'Architecte' },
            style:       { type: 'text', label: 'Style' },
            dimensions:  { type: 'text', label: 'Dimensions' },
            protection_status: {
              type: 'select', label: 'Statut de protection',
              options: {
                'classé':   'Classé Monument Historique',
                'unesco':   'Patrimoine mondial UNESCO',
                'regional': 'Inventaire Régional',
                'none':     'Non protégé',
              },
            },
            lat:         { type: 'number', label: 'Latitude', step: 0.000001 },
            lng:         { type: 'number', label: 'Longitude', step: 0.000001 },
            'location-search': { type: 'location-search', label: 'Recherche', pageId: this.pageId },
            tags:        { type: 'tags', label: 'Tags' },
            primary_tag: { type: 'text', label: 'Tag mis en avant' },
          };
          Object.keys(fieldsSpec).forEach(k => {
            const fieldDef = defs[k] || { type: 'text', label: k };
            fields[k] = fieldDef;
            initialValue[k] = this.$panel.view.props.model.content[k] || '';
          });

          this.$panel.dialog.open({
            component: 'k-form-dialog',
            props: {
              fields: fields,
              value: initialValue,
              submitButton: { text: 'Enregistrer', icon: 'check', theme: 'positive' },
              cancelButton: { text: 'Annuler' },
            },
            on: {
              submit: async (newValues) => {
                try {
                  await this.$panel.api.patch('pages/' + this.pageId.replace(/\//g, '+'), newValues);
                  this.$panel.dialog.close();
                  this.$panel.notification.success('Mis à jour');
                  await this.$panel.view.reload();
                } catch (e) {
                  this.$panel.notification.error('Erreur : ' + (e.message || 'inconnue'));
                }
              },
            },
          });
        },

        editMeta() {
          this.editFields('meta', {
            location:          'text',
            construction_date: 'text',
            date:              'date',
            architect:         'text',
            style:             'text',
            dimensions:        'text',
            protection_status: 'select',
          });
        },

        editTags() {
          this.editFields('tags', { tags: 'tags', primary_tag: 'text' });
        },

        editGallery() {
          // Gallery editing goes through the Galerie field on the page.
          // For now, navigate to the dedicated Galerie sub-tab if you add
          // one — otherwise open a files dialog.
          this.openTab('overview');
          this.$panel.notification.info('Pour gérer la galerie, ouvrez le menu fichiers en bas.');
        },

        editContent() {
          // Long-form content blocks live in their own editor screen.
          // Open a drawer-style editor via fiber if available.
          if (window.panel?.view?.open) {
            window.panel.view.open('/pages/' + this.pageId.replace(/\//g, '+') + '?tab=overview&edit=content');
          }
        },
      },
    },
  },

  // ── Header view button ──────────────────────────────────────────────
  viewButtons: {
    visibility: {
      template: /* html */`
        <div class="gh-visibility" :data-state="current">
          <button
            type="button"
            class="gh-visibility__trigger"
            :title="'Visibilité : ' + currentOption.label"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            @click="toggleOpen"
          >
            <k-icon :type="currentOption.icon" class="gh-visibility__icon" />
            <span class="gh-visibility__label">{{ currentOption.label }}</span>
            <k-icon
              type="angle-down"
              class="gh-visibility__chevron"
              :class="{ 'is-open': open }"
            />
          </button>

          <div
            v-show="open"
            ref="panel"
            class="gh-visibility__panel"
            role="menu"
            :style="panelStyle"
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
          // Computed each time we open — viewport-anchored top/right so
          // position:fixed lifts the panel out of every parent stacking
          // context (was rendering under the sticky .k-tabs strip).
          panelPos: { top: 0, right: 0 },
          // Three states — each maps to a (status, visibility) pair.
          // status=draft/listed is Kirby's native publish flag; visibility
          // is our own private/link/public layer. Brouillon means literally
          // unpublished. Avec un lien = published-but-unlisted, accessible
          // via the shared URL. Public = published + listed on the map.
          options: [
            { value: 'brouillon', label: 'Brouillon',     icon: 'edit',  help: 'Page non publiée — vous et les administrateurs uniquement.', status: 'draft',  visibility: 'private' },
            { value: 'link',      label: 'Avec un lien',  icon: 'url',   help: 'Page publiée mais non listée. Accessible via un lien partagé.', status: 'listed', visibility: 'link'    },
            { value: 'public',    label: 'Public',        icon: 'globe', help: 'Page publiée et listée sur la carte GoHéritage.',              status: 'listed', visibility: 'public'  },
          ],
        };
      },

      computed: {
        model()   { return this.$panel?.view?.props?.model ?? null; },
        content() { return this.model?.content ?? {};               },
        // Resolve the current option by inspecting both status + visibility.
        current() {
          if (this.model?.status === 'draft') return 'brouillon';
          const v = this.content.visibility;
          if (v === 'link')   return 'link';
          if (v === 'public') return 'public';
          // Listed page without explicit visibility — treat as public (the
          // legacy default before the visibility field existed).
          return 'public';
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
        // Inline style for the panel — viewport-anchored coords computed
        // at the moment the dropdown opens.
        panelStyle() {
          return {
            position: 'fixed',
            top:   this.panelPos.top   + 'px',
            right: this.panelPos.right + 'px',
            zIndex: 99999,
          };
        },
      },

      mounted() {
        // Teleport the panel to document.body so it escapes all parent
        // stacking contexts. position:fixed alone wasn't enough because
        // .k-header creates its own --z-toolbar context that trapped
        // the popup beneath the sticky .k-tabs strip. Moving it to body
        // gets us out of every ancestor's stack.
        this.$nextTick(() => {
          const panel = this.$refs.panel;
          if (panel && panel.parentNode !== document.body) {
            this._originalParent = panel.parentNode;
            document.body.appendChild(panel);
          }
        });

        this._docHandler = (e) => {
          if (!this.open) return;
          const inTrigger = this.$el && this.$el.contains(e.target);
          const inPanel   = this.$refs.panel && this.$refs.panel.contains(e.target);
          if (!inTrigger && !inPanel) this.open = false;
        };
        document.addEventListener('click', this._docHandler);
        this._escHandler = (e) => { if (e.key === 'Escape') this.open = false; };
        document.addEventListener('keydown', this._escHandler);

        this._repositionHandler = () => { if (this.open) this.recomputePosition(); };
        window.addEventListener('scroll', this._repositionHandler, true);
        window.addEventListener('resize', this._repositionHandler);
      },

      beforeDestroy() {
        // Pull the teleported panel out of body so Vue can finish its
        // own teardown without trying to remove a node from the wrong
        // parent. If we don't, Vue throws on hot-reload / view switch.
        const panel = this.$refs.panel;
        if (panel && panel.parentNode === document.body) {
          document.body.removeChild(panel);
        }

        if (this._docHandler) document.removeEventListener('click', this._docHandler);
        if (this._escHandler) document.removeEventListener('keydown', this._escHandler);
        if (this._repositionHandler) {
          window.removeEventListener('scroll', this._repositionHandler, true);
          window.removeEventListener('resize', this._repositionHandler);
        }
      },

      methods: {
        toggleOpen(e) {
          e?.stopPropagation?.();
          this.open = !this.open;
          if (this.open) this.recomputePosition();
        },

        // Anchor the dropdown to the trigger's CURRENT viewport position so
        // it floats correctly even when the page is scrolled. Re-call on
        // window resize/scroll while open. Right-aligned to the trigger
        // (panel grows leftwards) so it never overflows past the header.
        recomputePosition() {
          this.$nextTick(() => {
            // The trigger is rendered as a k-button; its root element is
            // .gh-visibility__trigger. Falling back to the wrapper if the
            // trigger isn't yet in the DOM tree.
            const trigger = this.$el?.querySelector?.('.gh-visibility__trigger')
                         || this.$el;
            if (!trigger) return;
            const r = trigger.getBoundingClientRect();
            this.panelPos = {
              top:   r.bottom + 6, // 6 px gap below the button
              right: Math.max(8, window.innerWidth - r.right),
            };
          });
        },

        async select(value) {
          if (value === this.current) { this.open = false; return; }

          // Confirm any transition that EXPOSES content — going from
          // draft/unlisted to publicly listed, or unlocking link sharing
          // for the first time. Going BACK down (public→link, anything→
          // brouillon) is silent since it only restricts access.
          const needsConfirm =
            (this.current === 'brouillon' && (value === 'link' || value === 'public')) ||
            (this.current === 'link' && value === 'public');
          if (needsConfirm) {
            const opt = this.options.find(o => o.value === value);
            const proceed = await new Promise((resolve) => {
              this.$panel.dialog.open({
                component: 'k-text-dialog',
                props: {
                  icon: opt.icon,
                  text: value === 'public'
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
          const opt = this.options.find(o => o.value === value);
          if (!opt) return;

          // Build the page ID Kirby expects in URLs (slashes → plus).
          const pageId = this.model.id.replace(/\//g, '+');

          try {
            // 1. Update the visibility field via the direct page-content
            //    PATCH endpoint. Earlier we routed through $panel.content
            //    but in Kirby 5.4 that path didn't reliably persist field
            //    updates from outside a fully-managed form view — selecting
            //    "Avec un lien" looked like it worked, then reverted on
            //    reload because nothing was actually written.
            await this.$api.patch('pages/' + pageId, {
              visibility: opt.visibility,
            });

            // 2. Update the page status (draft / listed) if it differs.
            //    Done as a separate PATCH because Kirby keeps status in
            //    its own endpoint.
            if (opt.status !== this.model.status) {
              await this.$api.patch('pages/' + pageId + '/status', {
                status: opt.status,
                position: null,
              });
            }

            // 3. Reload the panel view so the new state shows up.
            await this.$panel.view.reload();
            this.$panel.notification.success('Statut mis à jour : ' + opt.label);
            this.open = false;
          } catch (e) {
            this.$panel.notification.error(
              'Impossible de mettre à jour : ' + (e.message || 'erreur inconnue')
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
    // Primary: read the blueprint name from panel state.
    try {
      var props = window.panel?.view?.props;
      var bp = props?.blueprint || props?.model?.blueprint;
      if (bp === 'project' || (typeof bp === 'string' && bp.endsWith('/project'))) {
        return true;
      }
    } catch (_) {}
    // Fallback: URL pattern. Map pages all live under /panel/pages/map+...
    // and their blueprint is 'project'. This catches the case where the
    // panel state hasn't populated yet but the URL gives the answer.
    try {
      var path = window.location?.pathname || '';
      if (/\/panel\/pages\/(map\+|[a-z+]+map\+)/i.test(path)) return true;
    } catch (_) {}
    return false;
  }

  // True if the section contains at least one user-editable field — i.e.
  // we should attach an edit button. Skipped:
  //   • info / hidden fields (k-info-field, k-hidden-field)
  //   • page-files-list: has its own delete UI, edit dock would overlap
  //   • upload-overwrite: same — own dropzone + delete, doesn't need locking
  function sectionIsEditable(section) {
    if (!section) return false;
    var fields = section.querySelectorAll('.k-field');
    if (!fields.length) return false;
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      if (f.classList.contains('k-info-field'))                       continue;
      if (f.classList.contains('k-hidden-field'))                     continue;
      if (f.classList.contains('k-page-files-list-field'))            continue;
      if (f.classList.contains('k-upload-overwrite-field'))           continue;
      // Anything else counts as editable content.
      return true;
    }
    return false;
  }

  /*  Build an HTML element that mimics Kirby's own <k-button> output so
   *  Kirby's stylesheet handles hover / focus / disabled / dark theme /
   *  size tokens for us. We pass theme via data-theme; passive = neutral,
   *  positive = primary action (Save), negative = destructive.        */
  /*  Custom button — does NOT extend .k-button because that class only
   *  has minimal default styling when Kirby's Vue component isn't
   *  rendering it. Sets explicit dimensions inline so size is
   *  guaranteed regardless of which Kirby CSS scope wins. */
  function makeButton(label, iconType, variant) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gh-btn gh-btn--' + variant;
    btn.innerHTML =
      '<svg class="gh-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        iconSvg(iconType) +
      '</svg>' +
      '<span class="gh-btn__label">' + label + '</span>';
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

  /*  Hardcoded headline map — keyed by Kirby's k-section-name-* class
   *  (Kirby tags every section with .k-section-name-<blueprint_name>).
   *  This is the most reliable source: doesn't depend on Vue having
   *  populated the panel state, on a specific DOM nesting, or on the
   *  existing header element rendering. Source of truth = blueprint. */
  var HEADLINE_MAP = {
    sharing_section:     'Partage',
    cover_section:       'Aperçu',
    info_section:        'Présentation',
    meta_section:        'Caractéristiques',
    spec_section:        'Fiche technique',
    geo_section:         'Géolocalisation',
    content_section:     'Contenu détaillé',
    gallery_section:     'Galerie d’images',
    tags_section:        'Tags',
    viewer_settings:     'Réglages du viewer',
    pipeline_info:       'Pipeline de production',
    exterior_files:      'Modèle extérieur',
    interior_files:      'Modèle intérieur',
    annotations_section: 'Points d’intérêt',
    all_files:           'Inventaire des fichiers',
    plans:               'Plans & relevés',
    docs:                'Autres documents',
  };

  /*  ALWAYS inject our own headline at the top of every section card.
   *  Reads the section name from Kirby's k-section-name-* class (the
   *  most reliable identifier Kirby gives us), looks it up in the
   *  hardcoded blueprint map, falls back to humanising the name. */
  function ensureHeadline(section) {
    if (section.querySelector(':scope > .gh-section__title')) return;

    // Find section name from its class list.
    var name = '';
    var classes = section.className.split(/\s+/);
    for (var i = 0; i < classes.length; i++) {
      if (classes[i].indexOf('k-section-name-') === 0) {
        name = classes[i].slice('k-section-name-'.length);
        break;
      }
    }
    // Skip hidden / no-headline sections by name.
    if (name === 'visibility_meta') return;

    // 1. Hardcoded map (most reliable)
    var headlineText = HEADLINE_MAP[name];

    // 2. Fallback — pull from Kirby's existing header if present
    if (!headlineText) {
      var existing = section.querySelector('.k-section-header .k-headline, header .k-headline, header h2, header h3');
      if (existing) headlineText = (existing.textContent || '').trim();
    }

    // 3. Final fallback — humanize the section name
    if (!headlineText && name) {
      headlineText = name.replace(/_/g, ' ').replace(/\b\w/g, function (m) {
        return m.toUpperCase();
      });
    }
    if (!headlineText) return;

    var h = document.createElement('h2');
    h.className = 'gh-section__title';
    h.textContent = headlineText;
    section.prepend(h);
  }

  /*  Render a STATIC display of the section's current field values so
   *  the page reads as a polished document, not a form. Inputs are
   *  hidden via CSS in read mode; this element shows in their place.
   *  Re-built whenever the section enters / leaves edit mode (values
   *  may have changed). */
  function buildDisplay(section) {
    var existing = section.querySelector(':scope > .gh-section__display');
    if (existing) existing.remove();

    var dl = document.createElement('dl');
    dl.className = 'gh-section__display';

    // Only build the dl for SIMPLE form-y field types. Visual fields
    // (blocks / files / gallery / image / structure) keep Kirby's
    // native rendering since their on-screen presence already looks
    // like content rather than a form.
    var FORM_FIELD_CLASSES = [
      'k-text-field', 'k-textarea-field', 'k-number-field',
      'k-url-field', 'k-email-field', 'k-date-field',
      'k-select-field', 'k-tags-field',
      'k-toggle-field', 'k-toggles-field', 'k-checkboxes-field',
      'k-writer-field',
    ];

    var fields = section.querySelectorAll(':scope .k-field');
    var empty = true;
    fields.forEach(function (field) {
      // Skip if field isn't a form-y type we render.
      var isFormy = false;
      for (var k = 0; k < FORM_FIELD_CLASSES.length; k++) {
        if (field.classList.contains(FORM_FIELD_CLASSES[k])) { isFormy = true; break; }
      }
      if (!isFormy) return;

      var labelEl = field.querySelector('.k-field-header label, label.k-label, .k-label');
      var label   = labelEl ? labelEl.textContent.trim() : '';
      var value   = readFieldValue(field);

      // Skip rows that have NEITHER a label NOR a value — they'd just
      // render as "— · —" which is pure noise.
      if (!label && !value) return;

      var dt = document.createElement('dt');
      dt.className = 'gh-section__display-key';
      dt.textContent = label || '—';

      var dd = document.createElement('dd');
      dd.className = 'gh-section__display-val' + (value ? '' : ' is-empty');
      if (value instanceof Node) {
        dd.appendChild(value);
      } else {
        dd.textContent = value || '—';
      }

      dl.appendChild(dt);
      dl.appendChild(dd);
      empty = false;
    });

    // If literally nothing to display (rare — only happens for sections
    // composed of only blocks/files which already render visually),
    // skip the empty <dl> so we don't show a hollow card.
    if (empty) return;
    section.appendChild(dl);
  }

  /*  Extract a renderable value from a .k-field. Returns either a string
   *  or a DOM Node (for chips, multi-values, etc). Best-effort — we read
   *  from the actual rendered DOM since Kirby's content store isn't
   *  reliably reachable from plain JS at this layer. */
  function readFieldValue(field) {
    // Tags field → chip list
    if (field.classList.contains('k-tags-field')) {
      var pills = field.querySelectorAll('.k-tags .k-tag, .k-tags-input li');
      if (!pills.length) return '';
      var wrap = document.createElement('span');
      wrap.className = 'gh-chips';
      pills.forEach(function (p) {
        var label = p.textContent.replace(/×|✕|x/g, '').trim();
        if (!label) return;
        var chip = document.createElement('span');
        chip.className = 'gh-chip';
        chip.textContent = label;
        wrap.appendChild(chip);
      });
      return wrap.children.length ? wrap : '';
    }
    // Toggle → Oui / Non
    if (field.classList.contains('k-toggle-field')) {
      var input = field.querySelector('input[type=checkbox]');
      return input && input.checked ? 'Oui' : 'Non';
    }
    // Select / Toggles → show the selected option's TEXT
    if (field.classList.contains('k-select-field')) {
      var sel = field.querySelector('select');
      if (sel && sel.selectedIndex >= 0) {
        var opt = sel.options[sel.selectedIndex];
        return (opt && opt.textContent.trim()) || sel.value || '';
      }
      return '';
    }
    if (field.classList.contains('k-toggles-field')) {
      var checked = field.querySelector('input[type=radio]:checked, .k-toggles-option.is-active');
      if (checked) {
        var lbl = checked.closest('label')?.textContent || checked.textContent;
        return (lbl || '').trim();
      }
      return '';
    }
    // Date → format
    if (field.classList.contains('k-date-field')) {
      var d = field.querySelector('input');
      var v = d ? d.value : '';
      if (v) {
        try {
          var dt = new Date(v);
          if (!isNaN(dt.getTime())) {
            return dt.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
          }
        } catch (_) {}
      }
      return v;
    }
    // Textarea / writer / blocks → multi-line text
    if (field.classList.contains('k-textarea-field') || field.classList.contains('k-writer-field')) {
      var ta = field.querySelector('textarea, .k-writer-input');
      return ta ? (ta.value || ta.textContent || '').trim() : '';
    }
    // Generic input
    var generic = field.querySelector('input[type=text], input[type=email], input[type=url], input[type=number], input[type=date], input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea');
    if (generic) return (generic.value || '').trim();
    // Fallback — read displayed text
    return (field.textContent || '').trim();
  }

  function attach(section) {
    tag(section);
    ensureHeadline(section);
    if (section.dataset.ghAttached === '1') return;
    section.dataset.ghAttached = '1';

    var dock = document.createElement('div');
    dock.className = 'gh-section__dock';

    // Three buttons — "Terminé" reads more naturally for "I'm done
    // editing this section" than "Enregistrer" (which feels like
    // committing to a database record).
    var editBtn   = makeButton('Modifier', 'edit',   'edit');
    var saveBtn   = makeButton('Terminé',  'check',  'save');
    var cancelBtn = makeButton('Annuler',  'cancel', 'cancel');

    saveBtn.style.display   = 'none';
    cancelBtn.style.display = 'none';

    dock.appendChild(editBtn);
    dock.appendChild(cancelBtn);
    dock.appendChild(saveBtn);

    /*  Place the dock as a direct child of the section card. CSS will
     *  position it absolutely at top-right of the section. */
    section.appendChild(dock);

    // Build the initial read-only display of field values.
    buildDisplay(section);

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
        window.panel?.notification?.success?.('Modifications enregistrées');
        myToken = 0;
        editingToken = 0;
        setEditing(false);
        // Rebuild the static display so it shows the saved values.
        buildDisplay(section);
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

  
  /*  Inject a live 3D viewer preview at the top of the Modèle 3D tab
   *  in read mode. Hidden when any section is in edit mode so the
   *  iframe doesn't fight for vertical space while uploading files.   */
  function ensureModelPreview() {
    // Only on the Modèle 3D tab.
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab');
    if (tab !== 'model') {
      var stale = document.getElementById('gh-model-preview');
      if (stale) stale.remove();
      return;
    }

    // Look up the page slug from panel state.
    var slug = '';
    try {
      var id = window.panel && window.panel.view && window.panel.view.props
            && window.panel.view.props.id;
      if (typeof id === 'string') slug = id;
    } catch (_) {}
    if (!slug) return;

    if (document.getElementById('gh-model-preview')) return;

    // Prepend the preview as the FIRST sibling of the first section in
    // the current tab. The .k-sections wrapper (parent of .k-section
    // elements) is the natural content container — it shares whatever
    // width Kirby gives the tab content, so the iframe will span the
    // full content width.
    var firstSection = document.querySelector('.k-page-view .k-section');
    var host = firstSection && firstSection.parentNode;
    if (!host) return;

    var wrap = document.createElement('div');
    wrap.id = 'gh-model-preview';
    wrap.className = 'gh-model-preview';
    wrap.innerHTML =
      '<div class="gh-model-preview__head">' +
        '<span class="gh-model-preview__label">Aperçu du modèle</span>' +
        '<a class="gh-model-preview__open" href="/' + slug + '" target="_blank" rel="noopener">Ouvrir →</a>' +
      '</div>' +
      '<iframe class="gh-model-preview__frame" src="/' + slug + '?embed=1&viewer=only" ' +
        'allow="xr-spatial-tracking; fullscreen" loading="lazy"></iframe>';
    host.insertBefore(wrap, firstSection);
  }

  function scan() {
    if (!isProjectPage()) {
      document.body.classList.remove(BODY_FLAG);
      document.body.style.removeProperty('--gh-header-height');
      var stalePreview = document.getElementById('gh-model-preview');
      if (stalePreview) stalePreview.remove();
      return;
    }
    document.body.classList.add(BODY_FLAG);
    measureHeader();
    ensureModelPreview();

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
  // Kirby's view re-renders without coupling to its internals. ANY mutation
  // triggers a re-scan — we used to filter for unattached sections only,
  // but that missed navigations where Vue destroyed and re-created the
  // same section ID (data-gh-attached was on a node already removed).
  var _scanScheduled = false;
  var mo = new MutationObserver(function () {
    if (_scanScheduled) return;
    _scanScheduled = true;
    requestAnimationFrame(function () {
      _scanScheduled = false;
      rescan();
      measureHeader();
    });
  });
  mo.observe(document.body, { childList: true, subtree: true });

  // Window resize → h1 may wrap to a different line count, header height
  // changes. Re-measure so the sticky tab top: offset stays correct.
  window.addEventListener('resize', measureHeader, { passive: true });

  // ── Belt-and-suspenders activation ────────────────────────────────
  //
  // The MutationObserver above is the primary path, but panel boots
  // can race ahead of it (Vue mounts before the observer attaches in
  // some cold-load scenarios). Listen to panel.events.view.change for
  // explicit view-mount signals, and poll every 1s as a final fallback.
  // Idempotent — rescan() guards against duplicate attaches.
  function hookPanelEvents() {
    var ev = window.panel?.events;
    if (!ev || !ev.on || hookPanelEvents._done) return;
    hookPanelEvents._done = true;
    try {
      ev.on('view.change', function () { setTimeout(rescan, 50); });
      ev.on('view.update', function () { setTimeout(rescan, 50); });
    } catch (_) {}
  }
  setInterval(function () { hookPanelEvents(); rescan(); }, 1000);

  // Expose a tiny diagnostic so the user can verify the plugin is
  // actually running by checking `window.GH_PROJECT_UX` in DevTools.
  window.GH_PROJECT_UX = {
    version: 'v4-2026-05-27',
    rescan: rescan,
    isProjectPage: isProjectPage,
    bodyFlag: BODY_FLAG,
  };

  // Debug pill and top-right user menu were removed — they overlapped
  // Kirby's existing chrome and added clutter. Sidebar Account / Logout
  // stay in their default position.
  // Defensive cleanup: remove any stale instances from prior versions.
  var stalePill = document.getElementById('gh-debug');       if (stalePill) stalePill.remove();
  var staleMenu = document.getElementById('gh-user-menu');   if (staleMenu) staleMenu.remove();
})();
