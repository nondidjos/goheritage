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

// Section component, factored out so we can register it under BOTH the
// panel.plugin() `sections` key (Kirby's intended API) AND the
// `components` key with its full `k-project-overview-section` name —
// some versions of Kirby's plugin loader auto-name sections via the
// `sections` key, others don't. Registering under both guarantees the
// component is found regardless of which version is running.
var ProjectOverviewSection = {
      data() {
        return {
          pageId:             '',
          pageTitle:          '',
          coverUrl:           null,
          description:        '',
          location:           '',
          constructionDate:   '',
          scanDate:           '',
          architect:          '',
          style:              '',
          dimensions:         '',
          protectionStatus:   '',
          lat:                '',
          lng:                '',
          tags:               [],
          primaryTag:         '',
          has3dModel:         false,
          modelSidesSummary:  '',
          galleryCount:       0,
          plansCount:         0,
          docsCount:          0,
          hotspotsCount:      0,
          contentBlocksCount: 0,

          // Dialog visibility
          coverDialogOpen: false,
          geoDialogOpen: false,
          infoDialogOpen: false,

          // Cover Dialog state
          pageImages: [],
          coverUuid: null,
          selectedCoverUuid: null,
          uploadingCover: false,

          // Geolocation Dialog state
          geoQuery: '',
          geoResults: [],
          geoSearching: false,
          geoSaving: false,
          localGeo: { location: '', lat: '', lng: '' },

          // General Info Dialog state
          dateRaw: '',
          protectionStatusRaw: '',
          localInfo: {
            title: '',
            description: '',
            location: '',
            constructionDate: '',
            scanDate: '',
            architect: '',
            style: '',
            dimensions: '',
            protectionStatus: 'none'
          },
          protectionOptions: [
            { value: 'classé', text: 'Classé Monument Historique' },
            { value: 'unesco', text: 'Patrimoine mondial UNESCO' },
            { value: 'regional', text: 'Inventaire Régional' },
            { value: 'none', text: 'Non protégé' }
          ]
        };
      },
      created() {
        this.load().then((r) => {
          Object.assign(this.$data, r);
        }).catch((e) => {
          if (window.console && window.console.warn) {
            window.console.warn('project-overview load failed:', e);
          }
        });
      },
      template: /* html */`
        <section class="gh-pov">
          <!-- Cover image. Clicking it opens the Cover Dialog directly on the page. -->
          <div class="gh-pov__cover" :class="{ 'gh-pov__cover--empty': !coverUrl }">
            <button type="button" class="gh-pov__cover-hit" @click="openCoverDialog" title="Modifier la couverture">
              <img v-if="coverUrl" :src="coverUrl" :alt="pageTitle">
              <div v-else class="gh-pov__cover-empty">
                <k-icon type="image" />
                <span>Ajouter une image de couverture</span>
              </div>
              <span class="gh-pov__cover-edit"><k-icon type="edit" /> Modifier</span>
            </button>
            <a class="gh-pov__cover-cta" :href="'/' + pageId" target="_blank" rel="noopener" @click.stop>
              <k-icon type="open" /> Voir la page publique
            </a>
          </div>

          <!-- Title bar + edit (opens Info Dialog) -->
          <header class="gh-pov__head">
            <h1 class="gh-pov__title">{{ pageTitle }}</h1>
            <button
              type="button"
              class="gh-pov__head-edit"
              @click="openInfoDialog"
              title="Modifier les informations"
            >
              <k-icon type="edit" /> Modifier
            </button>
          </header>

          <!-- Subtitle line: location · construction date -->
          <p v-if="location || constructionDate" class="gh-pov__subtitle">
            <span v-if="location"><k-icon type="pin" /> {{ location }}</span>
            <span v-if="constructionDate"> · {{ constructionDate }}</span>
          </p>

          <!-- Stat strip -->
          <div class="gh-pov__stats">
            <div class="gh-pov__stat">
              <span class="gh-pov__stat-num">{{ has3dModel ? '✓' : '—' }}</span>
              <span class="gh-pov__stat-label">Modèle 3D</span>
            </div>
            <div class="gh-pov__stat">
              <span class="gh-pov__stat-num">{{ hotspotsCount }}</span>
              <span class="gh-pov__stat-label">Points d’intérêt</span>
            </div>
            <div class="gh-pov__stat">
              <span class="gh-pov__stat-num">{{ galleryCount }}</span>
              <span class="gh-pov__stat-label">Images</span>
            </div>
            <div class="gh-pov__stat">
              <span class="gh-pov__stat-num">{{ plansCount }}</span>
              <span class="gh-pov__stat-label">Plans</span>
            </div>
          </div>

          <!-- Assets grid -->
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

            <button class="gh-pov__asset" @click="openTab('details', 'gallery_section')">
              <k-icon type="images" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Galerie</strong>
                <span>{{ galleryCount ? galleryCount + ' image' + (galleryCount > 1 ? 's' : '') : 'Aucune image' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('details', 'content_section')">
              <k-icon type="text" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Contenu détaillé</strong>
                <span>{{ contentBlocksCount ? contentBlocksCount + ' bloc' + (contentBlocksCount > 1 ? 's' : '') + ' éditorial' + (contentBlocksCount > 1 ? 'aux' : '') : 'Vide' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <!-- Geolocation opens popup editor instead of jumping tab -->
            <button class="gh-pov__asset" @click="openGeoDialog">
              <k-icon type="map" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Géolocalisation</strong>
                <span>{{ lat && lng ? lat + ', ' + lng : 'Coordonnées non définies' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>
          </div>

          <!-- ── Dialog Cover image ── -->
          <k-dialog
            v-if="coverDialogOpen"
            ref="coverDialog"
            :submit-button="{ text: 'Enregistrer', icon: 'check', theme: 'positive' }"
            :cancel-button="{ text: 'Annuler' }"
            @submit="saveCover"
            @cancel="coverDialogOpen = false"
            size="medium"
          >
            <div class="gh-cover-dialog">
              <k-text class="mb-4">Choisissez une image de couverture ou déposez-en une nouvelle :</k-text>
              
              <div class="gh-cover-grid">
                <div
                  v-for="img in pageImages"
                  :key="img.uuid"
                  class="gh-cover-grid__item"
                  :class="{ 'is-selected': selectedCoverUuid === img.uuid }"
                  @click="selectCover(img)"
                >
                  <img :src="img.thumb" :alt="img.name" />
                  <div class="gh-cover-grid__checkmark" v-if="selectedCoverUuid === img.uuid">
                    <k-icon type="check" />
                  </div>
                  <span class="gh-cover-grid__name">{{ img.name }}</span>
                </div>
              </div>

              <div class="gh-cover-upload">
                <k-button
                  icon="upload"
                  variant="filled"
                  size="sm"
                  :disabled="uploadingCover"
                  @click="$refs.coverFileInput.click()"
                >
                  {{ uploadingCover ? 'Téléversement...' : 'Téléverser une image' }}
                </k-button>
                <input
                  ref="coverFileInput"
                  type="file"
                  accept="image/*"
                  style="display:none"
                  @change="uploadCoverFile"
                />
                
                <k-button
                  v-if="coverUrl"
                  icon="trash"
                  theme="negative"
                  size="sm"
                  @click="deleteCover"
                >
                  Supprimer la couverture
                </k-button>
              </div>
            </div>
          </k-dialog>

          <!-- ── Dialog Geolocation ── -->
          <k-dialog
            v-if="geoDialogOpen"
            ref="geoDialog"
            :submit-button="{ text: 'Enregistrer', icon: 'check', theme: 'positive' }"
            :cancel-button="{ text: 'Annuler' }"
            @submit="saveGeo"
            @cancel="geoDialogOpen = false"
            size="medium"
          >
            <div class="gh-geo-dialog">
              <div class="k-fieldset">
                <div class="k-field k-text-field">
                  <label class="k-label">Rechercher une adresse / un lieu</label>
                  <div style="display:flex; gap:0.4rem; align-items:center; position:relative;">
                    <input
                      type="text"
                      v-model="geoQuery"
                      @input="onGeoInput"
                      placeholder="Tapez un lieu..."
                      class="k-text-input"
                      style="flex:1; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                    <k-icon v-if="geoSearching || geoSaving" type="loader" style="opacity:0.5;" />
                    
                    <ul v-if="geoResults.length" class="gh-geo-results">
                      <li
                        v-for="(r, i) in geoResults"
                        :key="i"
                        @click="pickGeoResult(r)"
                      >
                        <span class="gh-geo-results__name">{{ r.label }}</span>
                        <span class="gh-geo-results__coords">{{ r.lat.toFixed(6) }}, {{ r.lng.toFixed(6) }}</span>
                      </li>
                    </ul>
                  </div>
                </div>

                <div class="k-field k-text-field" style="margin-top:1rem;">
                  <label class="k-label">Lieu (Affichage textuel)</label>
                  <input
                    type="text"
                    v-model="localGeo.location"
                    placeholder="ex. Bruxelles, Belgique"
                    class="k-text-input"
                    style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                  />
                </div>

                <div style="display:flex; gap:1rem; margin-top:1rem;">
                  <div class="k-field k-number-field" style="flex:1;">
                    <label class="k-label">Latitude</label>
                    <input
                      type="number"
                      step="0.000001"
                      v-model.number="localGeo.lat"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                  <div class="k-field k-number-field" style="flex:1;">
                    <label class="k-label">Longitude</label>
                    <input
                      type="number"
                      step="0.000001"
                      v-model.number="localGeo.lng"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                </div>
              </div>
            </div>
          </k-dialog>

          <!-- ── Dialog Info (General Info & Characteristics) ── -->
          <k-dialog
            v-if="infoDialogOpen"
            ref="infoDialog"
            :submit-button="{ text: 'Enregistrer', icon: 'check', theme: 'positive' }"
            :cancel-button="{ text: 'Annuler' }"
            @submit="saveInfo"
            @cancel="infoDialogOpen = false"
            size="medium"
          >
            <div class="gh-info-dialog">
              <div class="k-fieldset">
                <div class="k-field k-text-field">
                  <label class="k-label">Titre du projet</label>
                  <input
                    type="text"
                    v-model="localInfo.title"
                    class="k-text-input"
                    style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                  />
                </div>

                <div class="k-field k-textarea-field" style="margin-top:1rem;">
                  <label class="k-label">Description courte</label>
                  <textarea
                    v-model="localInfo.description"
                    class="k-textarea-input"
                    rows="3"
                    style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text); font-family:inherit; font-size:inherit;"
                  ></textarea>
                </div>

                <div style="display:flex; gap:1rem; margin-top:1rem;">
                  <div class="k-field k-text-field" style="flex:1;">
                    <label class="k-label">Lieu</label>
                    <input
                      type="text"
                      v-model="localInfo.location"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                  <div class="k-field k-text-field" style="flex:1;">
                    <label class="k-label">Date de construction</label>
                    <input
                      type="text"
                      v-model="localInfo.constructionDate"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                </div>

                <div style="display:flex; gap:1rem; margin-top:1rem;">
                  <div class="k-field k-date-field" style="flex:1;">
                    <label class="k-label">Date du scan</label>
                    <k-date-input
                      :value="localInfo.scanDate"
                      @input="localInfo.scanDate = $event"
                      style="width:100%;"
                    />
                  </div>
                  <div class="k-field k-text-field" style="flex:1;">
                    <label class="k-label">Architecte / Créateur</label>
                    <input
                      type="text"
                      v-model="localInfo.architect"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                </div>

                <div style="display:flex; gap:1rem; margin-top:1rem;">
                  <div class="k-field k-text-field" style="flex:1;">
                    <label class="k-label">Style architectural</label>
                    <input
                      type="text"
                      v-model="localInfo.style"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                  <div class="k-field k-text-field" style="flex:1;">
                    <label class="k-label">Dimensions</label>
                    <input
                      type="text"
                      v-model="localInfo.dimensions"
                      class="k-text-input"
                      style="width:100%; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text);"
                    />
                  </div>
                </div>

                <div style="margin-top:1rem;">
                  <div class="k-field k-select-field">
                    <label class="k-label">Statut de protection</label>
                    <k-select-input
                      :value="localInfo.protectionStatus"
                      :options="protectionOptions"
                      @input="localInfo.protectionStatus = $event"
                      style="width:100%;"
                    />
                  </div>
                </div>
              </div>
            </div>
          </k-dialog>
        </section>
      `,

      methods: {
        openTab(name, scrollToSection) {
          var btn = document.querySelector('.k-tabs-button[data-tab="' + name + '"], .k-tabs-button[href*="tab=' + name + '"]');
          if (btn) {
            btn.click();
          } else {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            window.location.href = url.toString();
          }
          if (scrollToSection) {
            var tries = 0;
            var poll = setInterval(function () {
              tries++;
              var el = document.querySelector('.k-section-name-' + scrollToSection);
              if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                el.style.transition = 'background 0.3s';
                el.style.background = 'var(--color-back)';
                setTimeout(function () { el.style.background = ''; }, 1200);
                clearInterval(poll);
              }
              if (tries > 40) clearInterval(poll);
            }, 100);
          }
        },

        // Cover Dialog methods
        openCoverDialog() {
          this.selectedCoverUuid = this.coverUuid;
          this.coverDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.coverDialog) {
              this.$refs.coverDialog.open();
            }
          });
        },
        selectCover(img) {
          this.selectedCoverUuid = img.uuid;
        },
        async uploadCoverFile(e) {
          const file = e.target.files[0];
          if (!file) return;
          this.uploadingCover = true;
          const formData = new FormData();
          formData.append('file', file);
          formData.append('pageId', this.pageId);
          formData.append('template', 'image');
          formData.append('fieldName', 'cover');
          try {
            const resp = await fetch('/api/goheritage/upload-overwrite', {
              method: 'POST',
              headers: { 'X-CSRF': panel.csrf },
              body: formData
            });
            if (!resp.ok) throw new Error('Erreur de téléversement');
            const res = await resp.json();
            this.$panel.notification.success('Image téléversée : ' + res.filename);
            
            const sectionData = await this.load();
            Object.assign(this.$data, sectionData);
            this.selectedCoverUuid = res.id;
          } catch (err) {
            this.$panel.notification.error(err.message || 'Téléversement échoué');
          } finally {
            this.uploadingCover = false;
            e.target.value = '';
          }
        },
        async saveCover() {
          const pageId = this.pageId.replace(/\//g, '+');
          const value = this.selectedCoverUuid ? [this.selectedCoverUuid] : [];
          try {
            await this.$panel.api.patch('pages/' + pageId, { cover: value });
            this.$panel.notification.success('Image de couverture enregistrée');
            this.coverDialogOpen = false;
            await this.$panel.view.reload();
          } catch (err) {
            this.$panel.notification.error('Erreur lors de la sauvegarde : ' + err.message);
          }
        },
        async deleteCover() {
          const pageId = this.pageId.replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + pageId, { cover: [] });
            this.$panel.notification.success('Image de couverture supprimée');
            this.coverDialogOpen = false;
            await this.$panel.view.reload();
          } catch (err) {
            this.$panel.notification.error('Erreur : ' + err.message);
          }
        },

        // Geolocation Dialog methods
        openGeoDialog() {
          this.localGeo = {
            location: this.location || '',
            lat: this.lat ? parseFloat(this.lat) : '',
            lng: this.lng ? parseFloat(this.lng) : ''
          };
          this.geoQuery = '';
          this.geoResults = [];
          this.geoDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.geoDialog) {
              this.$refs.geoDialog.open();
            }
          });
        },
        onGeoInput() {
          clearTimeout(this._geoDebounce);
          this.geoResults = [];
          if (!this.geoQuery.trim()) return;
          this._geoDebounce = setTimeout(() => this.searchGeo(), 400);
        },
        async searchGeo() {
          this.geoSearching = true;
          try {
            const resp = await fetch('/api/goheritage/geocode?q=' + encodeURIComponent(this.geoQuery), {
              headers: { 'X-CSRF': panel.csrf }
            });
            if (!resp.ok) { this.geoResults = []; return; }
            const json = await resp.json();
            this.geoResults = (json.features || []).map(f => ({
              label: f.place_name || f.text || '',
              lat: f.geometry.coordinates[1],
              lng: f.geometry.coordinates[0]
            }));
          } catch (e) {
            this.geoResults = [];
          } finally {
            this.geoSearching = false;
          }
        },
        pickGeoResult(res) {
          this.localGeo.location = res.label;
          this.localGeo.lat = parseFloat(res.lat.toFixed(6));
          this.localGeo.lng = parseFloat(res.lng.toFixed(6));
          this.geoResults = [];
          this.geoQuery = res.label;
        },
        async saveGeo() {
          this.geoSaving = true;
          const pageId = this.pageId.replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + pageId, {
              location: this.localGeo.location,
              lat: this.localGeo.lat ? parseFloat(this.localGeo.lat) : '',
              lng: this.localGeo.lng ? parseFloat(this.localGeo.lng) : ''
            });
            this.$panel.notification.success('Coordonnées enregistrées');
            this.geoDialogOpen = false;
            await this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error(e.message || 'Erreur lors de la sauvegarde');
          } finally {
            this.geoSaving = false;
          }
        },

        // General Info Dialog methods
        openInfoDialog() {
          this.localInfo = {
            title: this.pageTitle || '',
            description: this.description || '',
            location: this.location || '',
            constructionDate: this.constructionDate || '',
            scanDate: this.dateRaw || '',
            architect: this.architect || '',
            style: this.style || '',
            dimensions: this.dimensions || '',
            protectionStatus: this.protectionStatusRaw || 'none'
          };
          this.infoDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.infoDialog) {
              this.$refs.infoDialog.open();
            }
          });
        },
        async saveInfo() {
          const pageId = this.pageId.replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + pageId, {
              title: this.localInfo.title,
              description: this.localInfo.description,
              location: this.localInfo.location,
              construction_date: this.localInfo.constructionDate,
              date: this.localInfo.scanDate,
              architect: this.localInfo.architect,
              style: this.localInfo.style,
              dimensions: this.localInfo.dimensions,
              protection_status: this.localInfo.protectionStatus
            });
            this.$panel.notification.success('Informations enregistrées');
            this.infoDialogOpen = false;
            await this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error('Erreur lors de la sauvegarde : ' + e.message);
          }
        }
      },
    };

// Plugin registration — `sections` key handles the k-{type}-section
// name internally; no need to also register under `components`.
panel.plugin('goheritage/project-ux', {

  sections: {
    'project-overview': ProjectOverviewSection,
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

            <!-- Dropdown footer: settings and share links link -->
            <div class="gh-visibility__panel-footer">
              <button
                type="button"
                class="gh-visibility__manage-btn"
                @click="openShareDialog"
              >
                <k-icon type="users" /> Gérer les liens & accès...
              </button>
            </div>
          </div>

          <!-- ── Google Drive-Style Share Dialog ── -->
          <k-dialog
            v-if="shareDialogOpen"
            ref="shareDialog"
            :submit-button="{ text: 'Terminé', icon: 'check', theme: 'positive' }"
            :cancel-button="false"
            @submit="shareDialogOpen = false"
            @close="shareDialogOpen = false"
            size="medium"
          >
            <div class="gh-share-dialog">
              <div class="gh-share-dialog__section">
                <h3 class="gh-share-dialog__subtitle"><k-icon type="users" /> Accès général</h3>
                <div class="gh-share-dialog__access-row">
                  <k-select-input
                    :value="dialogVisibility"
                    :options="[
                      { value: 'private', text: '🔒 Privé (Brouillon)' },
                      { value: 'link', text: '🔗 Limité (Avec un lien)' },
                      { value: 'public', text: '🌐 Public' }
                    ]"
                    @input="updateVisibilityFromDialog"
                    style="max-width: 250px;"
                  />
                  <p class="gh-share-dialog__access-help">
                    {{ visibilityHelpText }}
                  </p>
                </div>
              </div>

              <!-- Links section, only shown if general access is 'link' (Avec un lien) -->
              <div v-if="dialogVisibility === 'link'" class="gh-share-dialog__section gh-share-dialog__links">
                <div class="gh-share-dialog__section-header">
                  <h3 class="gh-share-dialog__subtitle"><k-icon type="url" /> Liens de partage actifs</h3>
                  <k-button icon="add" size="sm" variant="filled" theme="positive" @click="createShareLink">
                    Créer un lien
                  </k-button>
                </div>

                <div v-if="localShareLinks.length === 0" class="gh-share-dialog__empty">
                  <k-icon type="info" /> Aucun lien de partage actif. Créez-en un pour commencer à partager.
                </div>

                <div v-else class="gh-share-dialog__list">
                  <div v-for="(link, index) in localShareLinks" :key="link.id" class="gh-share-dialog__link-item">
                    <div class="gh-share-dialog__link-top">
                      <!-- Editable Name -->
                      <input
                        type="text"
                        v-model="link.label"
                        @change="saveShareLinks"
                        class="gh-share-dialog__link-name"
                        placeholder="Nom du lien (ex: Partage client)"
                      />
                      <div class="gh-share-dialog__link-actions">
                        <k-button icon="copy" size="xs" variant="filled" @click="copyLinkUrl(link)">Copier</k-button>
                        <k-button icon="trash" size="xs" theme="negative" @click="deleteShareLink(link.id)">Supprimer</k-button>
                      </div>
                    </div>

                    <!-- URL preview -->
                    <div class="gh-share-dialog__url-preview">
                      <code>{{ getFullLinkUrl(link.token) }}</code>
                    </div>

                    <!-- Permissions checkboxes -->
                    <div class="gh-share-dialog__permissions">
                      <span class="gh-share-dialog__perm-title">Autoriser l'accès aux sections :</span>
                      <div class="gh-share-dialog__perm-checkboxes">
                        <label v-for="sec in sectionOptions" :key="sec.value" class="gh-share-dialog__perm-checkbox">
                          <input
                            type="checkbox"
                            :value="sec.value"
                            :checked="link.visible_sections.includes(sec.value)"
                            @change="toggleLinkSection(link, sec.value)"
                          />
                          <span>{{ sec.label }}</span>
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </k-dialog>
        </div>
      `,

      data() {
        return {
          open: false,
          shareDialogOpen: false,
          dialogVisibility: 'private',
          localShareLinks: [],
          sectionOptions: [
            { value: 'model', label: 'Modèle 3D' },
            { value: 'info', label: 'Fiche technique' },
            { value: 'gallery', label: 'Galerie' },
            { value: 'plans', label: 'Plans & docs' },
            { value: 'annotations', label: 'Points d\'intérêt' }
          ],
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
        current() {
          if (this.model?.status === 'draft') return 'brouillon';
          const v = this.content.visibility;
          if (v === 'link')   return 'link';
          if (v === 'public') return 'public';
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
        shareLinks() {
          let raw = this.content.share_links;
          if (!raw) return [];
          if (typeof raw === 'string') {
            const items = [];
            let currentItem = null;
            const lines = raw.split(/\r?\n/);
            for (let line of lines) {
              let trimmed = line.trim();
              if (!trimmed) continue;
              
              // Check if it's a new list item
              let isNewItem = false;
              if (trimmed === '-') {
                isNewItem = true;
                trimmed = '';
              } else if (trimmed.startsWith('- ')) {
                isNewItem = true;
                trimmed = trimmed.substring(2).trim();
              }
              
              if (isNewItem) {
                if (currentItem) items.push(currentItem);
                currentItem = {};
              }
              
              if (trimmed) {
                const idx = trimmed.indexOf(':');
                if (idx > 0) {
                  let key = trimmed.substring(0, idx).trim();
                  let val = trimmed.substring(idx + 1).trim();
                  
                  // Strip surrounding quotes
                  if ((val.startsWith("'") && val.endsWith("'")) || (val.startsWith('"') && val.endsWith('"'))) {
                    val = val.substring(1, val.length - 1);
                  }
                  if (!currentItem) currentItem = {};
                  currentItem[key] = val;
                }
              }
            }
            if (currentItem) items.push(currentItem);
            raw = items;
          }
          if (Array.isArray(raw)) return raw.map(l => ({
            id: l.id || '',
            token: l.token || '',
            label: l.label || '',
            visible_sections: Array.isArray(l.visible_sections)
              ? l.visible_sections
              : (typeof l.visible_sections === 'string' ? l.visible_sections.split(',').filter(Boolean) : [])
          }));
          return [];
        },
        pageTitle() {
          return this.model?.title || 'le projet';
        },
        visibilityHelpText() {
          if (this.dialogVisibility === 'private') return 'Page non publiée — uniquement accessible aux éditeurs et administrateurs.';
          if (this.dialogVisibility === 'link') return 'Page accessible à toute personne disposant d\'un lien de partage actif. Non listée publiquement.';
          return 'Page publique et répertoriée sur la carte GoHéritage.';
        }
      },

      mounted() {
        this._docHandler = (e) => {
          if (!this.open) return;
          const inTrigger = this.$el && this.$el.contains(e.target);
          if (!inTrigger) this.open = false;
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
        toggleOpen(e) {
          e?.stopPropagation?.();
          this.open = !this.open;
        },

        async select(value) {
          if (value === this.current) { this.open = false; return; }
          await this.commit(value);
        },

        async commit(value) {
          if (!this.model) return;
          var opt = this.options.find(function (o) { return o.value === value; });
          if (!opt) return;
          var pageId = this.model.id.replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + pageId, { visibility: opt.visibility });
            if (opt.status !== this.model.status) {
              const res = await this.$panel.api.patch('pages/' + pageId + '/status', {
                status: opt.status,
                position: null,
              });
              if (res && res.id) {
                const newPageId = res.id.replace(/\//g, '+');
                window.location.href = '/panel/pages/' + newPageId + '?tab=overview';
                return;
              }
            }
            this.open = false;
            this.$panel.notification.success('Statut : ' + opt.label);
            await this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error(
              'Impossible de mettre à jour : ' + (e && e.message ? e.message : 'erreur inconnue')
            );
          }
        },

        openShareDialog() {
          this.open = false;
          this.dialogVisibility = this.model.status === 'draft' ? 'private' : (this.content.visibility || 'public');
          this.localShareLinks = JSON.parse(JSON.stringify(this.shareLinks));
          this.shareDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.shareDialog) {
              this.$refs.shareDialog.open();
            }
          });
        },

        async saveShareLinks() {
          if (!this.model) return;
          const pageId = this.model.id.replace(/\//g, '+');
          const formatted = this.localShareLinks.map(l => ({
            id: l.id,
            token: l.token,
            label: l.label,
            visible_sections: l.visible_sections.join(',')
          }));
          try {
            await this.$panel.api.patch('pages/' + pageId, { share_links: formatted });
            await this.$panel.view.reload();
            this.$nextTick(() => {
              this.localShareLinks = JSON.parse(JSON.stringify(this.shareLinks));
            });
          } catch (e) {
            this.$panel.notification.error('Erreur lors de la sauvegarde : ' + e.message);
          }
        },

        createShareLink() {
          var token = '';
          var chars = '0123456789abcdef';
          for (var i = 0; i < 16; i++) token += chars[Math.floor(Math.random() * 16)];
          const newLink = {
            id: 'link_' + Date.now() + '_' + Math.floor(Math.random() * 100),
            token: token,
            label: 'Lien sans titre',
            visible_sections: ['model', 'info', 'gallery', 'plans', 'annotations']
          };
          this.localShareLinks.push(newLink);
          this.saveShareLinks();
        },

        deleteShareLink(id) {
          this.localShareLinks = this.localShareLinks.filter(l => l.id !== id);
          this.saveShareLinks();
        },

        toggleLinkSection(link, value) {
          const idx = link.visible_sections.indexOf(value);
          if (idx >= 0) {
            link.visible_sections.splice(idx, 1);
          } else {
            link.visible_sections.push(value);
          }
          this.saveShareLinks();
        },

        async updateVisibilityFromDialog(value) {
          this.dialogVisibility = value;
          let status = 'listed';
          let visibility = 'public';
          if (value === 'private') {
            status = 'draft';
            visibility = 'private';
          } else if (value === 'link') {
            status = 'listed';
            visibility = 'link';
          } else {
            status = 'listed';
            visibility = 'public';
          }
          const pageId = this.model.id.replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + pageId, { visibility: visibility });
            if (status !== this.model.status) {
              const res = await this.$panel.api.patch('pages/' + pageId + '/status', {
                status: status,
                position: null
              });
              if (res && res.id) {
                const newPageId = res.id.replace(/\//g, '+');
                window.location.href = '/panel/pages/' + newPageId + '?tab=overview';
                return;
              }
            }
            this.$panel.notification.success('Accès général mis à jour.');
            await this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error('Impossible de modifier la visibilité : ' + e.message);
          }
        },

        getFullLinkUrl(token) {
          const base = this.model?.previewUrl || this.model?.link || '';
          if (!base) return '';
          return base.split('?')[0].split('#')[0] + '?key=' + token;
        },

        copyLinkUrl(link) {
          const url = this.getFullLinkUrl(link.token);
          if (!url) return;
          if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(url).then(
              () => this.$panel.notification.success('Lien copié !'),
              () => this.$copyFallback(url)
            );
          } else {
            this.$copyFallback(url);
          }
        },

        $copyFallback(url) {
          const ta = document.createElement('textarea');
          ta.value = url;
          ta.style.position = 'fixed';
          ta.style.top = '-1000px';
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); this.$panel.notification.success('Lien copié !'); }
          catch (_) { this.$panel.notification.error('Copie impossible.'); }
          document.body.removeChild(ta);
        }
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
  //   • project-overview: our own custom section, owns its own chrome
  function sectionIsEditable(section) {
    if (!section) return false;
    // Custom section type — never inject our edit dock or card chrome.
    if (section.classList.contains('k-project-overview-section')) return false;
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

  /*  Modèle 3D tab — read mode shows ONLY the live viewer preview;
   *  edit mode shows the technical upload sections. A toggle button in
   *  the preview header flips between the two. Body data-attr drives the
   *  CSS that hides/shows sections + preview. */
  function ensureModelPreview() {
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab');

    // Off the model tab → clean up flags + preview, bail.
    if (tab !== 'model') {
      var stale = document.getElementById('gh-model-preview');
      if (stale) stale.remove();
      document.body.classList.remove('gh-on-model-tab');
      document.body.removeAttribute('data-gh-model-mode');
      return;
    }

    document.body.classList.add('gh-on-model-tab');
    // Default to read mode unless the user already toggled to edit.
    if (!document.body.getAttribute('data-gh-model-mode')) {
      document.body.setAttribute('data-gh-model-mode', 'read');
    }

    var slug = '';
    try {
      var id = window.panel && window.panel.view && window.panel.view.props
            && window.panel.view.props.id;
      if (typeof id === 'string') slug = id;
    } catch (_) {}
    if (!slug) return;

    // Dedup: if exactly one bar + one preview already exist, nothing to
    // do (avoids reloading the iframe every scan tick). If counts are
    // off (0, or >1 from a tab-swap race), wipe and rebuild cleanly.
    var existingBars  = document.querySelectorAll('#gh-model-bar');
    var existingPrevs = document.querySelectorAll('#gh-model-preview');
    if (existingBars.length === 1 && existingPrevs.length === 1) return;
    existingBars.forEach(function (e) { e.remove(); });
    existingPrevs.forEach(function (e) { e.remove(); });

    var firstSection = document.querySelector('.k-page-view .k-section');
    var host = firstSection && firstSection.parentNode;
    if (!host) return;

    // SVG icons: pencil (enter edit) and X (exit edit).
    var penSvg =
      '<svg class="gh-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var xSvg =
      '<svg class="gh-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    // Persistent toolbar — a single right-aligned toggle button. Plain
    // outlined .gh-btn (same as the section "Modifier" buttons): pen +
    // "Modifier" in read mode, X + "Fermer" in edit mode.
    var bar = document.createElement('div');
    bar.id = 'gh-model-bar';
    bar.className = 'gh-model-bar';
    bar.innerHTML =
      '<span class="gh-model-bar__banner">' +
        '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>' +
        'Mode édition — vous gérez les fichiers du modèle 3D.' +
      '</span>' +
      '<button type="button" class="gh-btn" data-gh-model-toggle></button>';
    host.insertBefore(bar, firstSection);

    var wrap = document.createElement('div');
    wrap.id = 'gh-model-preview';
    wrap.className = 'gh-model-preview';
    wrap.innerHTML =
      '<iframe class="gh-model-preview__frame" src="/' + slug + '?embed=1&viewer=only" ' +
        'allow="xr-spatial-tracking; fullscreen" loading="lazy"></iframe>';
    host.insertBefore(wrap, firstSection);

    var toggle = bar.querySelector('[data-gh-model-toggle]');
    function paintToggle() {
      var editing = document.body.getAttribute('data-gh-model-mode') === 'edit';
      toggle.innerHTML = (editing ? xSvg : penSvg) +
        '<span class="gh-btn__label">' + (editing ? 'Fermer' : 'Modifier') + '</span>';
    }
    paintToggle();
    toggle.addEventListener('click', function () {
      var mode = document.body.getAttribute('data-gh-model-mode') === 'edit' ? 'read' : 'edit';
      document.body.setAttribute('data-gh-model-mode', mode);
      paintToggle();
    });
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
      // SKIP our custom section entirely — it owns its own chrome via
      // the Vue component. Tagging it as .gh-section would slap our
      // card padding/border on top of the component's own layout.
      if (s.classList.contains('k-project-overview-section')) return;

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
      // Every tagged section gets a title (even non-editable ones like
      // the file-upload sections).
      ensureHeadline(s);
      // ONLY the exterior/interior model file groups are collapsible —
      // they're the bulky upload sections you want to fold away. Other
      // sections (viewer settings, etc.) are just plain titled cards.
      if (s.classList.contains('k-section-name-exterior_files')
       || s.classList.contains('k-section-name-interior_files')) {
        makeCollapsible(s);
      }
      // NOTE: the per-section edit dock (Modifier/Terminé) has been
      // retired — it was fragile and produced duplicate/non-working
      // buttons. Editing surfaces (Détails, Modèle 3D) now show their
      // fields directly editable, like normal Kirby. The read-only
      // "document" view lives on the Aperçu tab (custom overview).
    });
    reworkSettingsDropdown();
  }

  function reworkSettingsDropdown() {
    if (!isProjectPage()) return;
    var dropdown = document.querySelector('.k-dropdown-content');
    if (!dropdown) return;

    var hasDelete = false;
    var items = dropdown.querySelectorAll('.k-dropdown-item');
    items.forEach(function(item) {
      var textEl = item.querySelector('.k-button-text');
      if (textEl) {
        var text = textEl.textContent.trim().toLowerCase();
        if (text.includes('supprimer') || text.includes('delete') || text.includes('dupliquer') || text.includes('duplicate')) {
          hasDelete = true;
        }
      }
    });

    if (!hasDelete) return;

    items.forEach(function(item) {
      var textEl = item.querySelector('.k-button-text');
      if (!textEl) return;
      var text = textEl.textContent.trim().toLowerCase();
      
      var iconEl = item.querySelector('.k-icon');
      var iconType = '';
      if (iconEl) {
        iconEl.classList.forEach(function(cls) {
          if (cls.startsWith('k-icon-')) {
            iconType = cls.substring(7);
          }
        });
      }
      
      var shouldRemove = false;
      if (text.includes('statut') || text.includes('status') || iconType === 'status' || iconType === 'preview') {
        shouldRemove = true;
      } else if (text.includes('modèle') || text.includes('template') || iconType === 'template') {
        shouldRemove = true;
      } else if (text.includes('déplacer') || text.includes('move') || iconType === 'move') {
        shouldRemove = true;
      } else if (text.includes('position') || iconType === 'sort') {
        shouldRemove = true;
      }

      if (shouldRemove) {
        item.style.display = 'none';
        var next = item.nextElementSibling;
        if (next && next.tagName === 'HR') {
          next.style.display = 'none';
        }
        var prev = item.previousElementSibling;
        if (prev && prev.tagName === 'HR') {
          prev.style.display = 'none';
        }
      }
    });

    var children = Array.from(dropdown.children);
    var deleteIndex = -1;

    children.forEach(function(child, index) {
      if (child.tagName === 'HR') {
        child.style.display = 'none';
      } else if (child.classList.contains('k-dropdown-item') && child.style.display !== 'none') {
        var textEl = child.querySelector('.k-button-text');
        if (textEl) {
          var text = textEl.textContent.trim().toLowerCase();
          if (text.includes('supprimer') || text.includes('delete')) {
            deleteIndex = index;
          }
        }
      }
    });

    if (deleteIndex > 0) {
      for (var j = deleteIndex - 1; j >= 0; j--) {
        if (children[j].tagName === 'HR') {
          children[j].style.setProperty('display', 'block', 'important');
          break;
        }
        if (children[j].style.display !== 'none') {
          break;
        }
      }
    }
  }

  document.addEventListener('click', function(e) {
    if (e.target.closest('.k-settings-view-button')) {
      setTimeout(reworkSettingsDropdown, 30);
      setTimeout(reworkSettingsDropdown, 100);
      setTimeout(reworkSettingsDropdown, 250);
    }
  }, { passive: true });

  /*  Make a section card collapsible by clicking its title. A chevron is
   *  appended to the title; clicking toggles .gh-collapsed on the
   *  section (CSS hides everything but the title). Used for the
   *  exterior/interior model file groups so they fold like before. */
  function makeCollapsible(section) {
    if (section.dataset.ghCollapsible === '1') return;
    var title = section.querySelector(':scope > .gh-section__title');
    if (!title) return;
    section.dataset.ghCollapsible = '1';
    title.classList.add('gh-section__title--toggle');

    var chevron = document.createElement('span');
    chevron.className = 'gh-section__chevron';
    chevron.innerHTML =
      '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    title.appendChild(chevron);

    title.addEventListener('click', function (e) {
      // Don't toggle when clicking the edit dock buttons (they're not in
      // the title, but guard anyway).
      if (e.target.closest('.gh-section__dock')) return;
      section.classList.toggle('gh-collapsed');
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
