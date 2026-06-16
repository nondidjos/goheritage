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
 *   • DOM injector
 *       A DOM-level enhancement that tags every .k-section on a project
 *       page with a `.gh-section` class so our card CSS can take over,
 *       injects section headlines, makes the bulky model-file sections
 *       collapsible, and drives the read/edit "showcase" toggles on the
 *       Modèle 3D and Détails tabs (body data-attr + CSS swaps between a
 *       polished preview and the raw Kirby form).
 *
 *       Why DOM-injection (not a custom field type): it bypasses Kirby's
 *       field system entirely, so it works regardless of how the panel
 *       re-mounts components on navigation.
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

          // Tags Dialog state — separate dialog so the overview can offer
          // distinct Caractéristiques and Tags tiles. Edits happen on drafts
          // (tagsDraft/primaryDraft) so Cancel discards cleanly.
          tagsDialogOpen: false,
          tagsInput: '',
          tagsDraft: [],
          // Curated THEMATIC vocabulary — styles/periods + a few functions.
          // Deliberately excludes places, centuries and "patrimoine" (those are
          // map locations / noise, not themes). Shown as one-click preset chips.
          tagPresets: [
            'religieux', 'art-nouveau', 'gothique', 'baroque', 'médiéval',
            'historicisme', 'belle-époque', 'orientalisme', 'jardin',
            'sculpture', 'cistercien', 'sgraffito', 'unesco',
          ],

          // Embed Dialog state
          embedDialogOpen: false,

          // General Info Dialog state
          dateRaw: '',
          protectionStatusRaw: '',
          localInfo: {
            title: '',
            description: '',
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
            <button type="button" class="gh-pov__cover-hit" :disabled="!canUpdate" @click="canUpdate && openCoverDialog()" title="Modifier la couverture">
              <img v-if="coverUrl" :src="coverUrl" :alt="pageTitle">
              <div v-else class="gh-pov__cover-empty">
                <k-icon type="image" />
                <span>Image de couverture</span>
              </div>
              <span class="gh-pov__cover-edit" v-if="canUpdate"><k-icon type="edit" /> Modifier</span>
            </button>
          </div>

          <!-- Title bar + edit (opens Info Dialog) -->
          <header class="gh-pov__head">
            <h1 class="gh-pov__title">{{ pageTitle }}</h1>
            <button
              type="button"
              class="gh-pov__head-edit"
              v-if="canUpdate"
              @click="openInfoDialog"
              title="Modifier les informations"
            >
              <k-icon type="edit" /> Modifier
            </button>
          </header>

          <!-- Subtitle line: location · construction date -->
          <p v-if="location || constructionDate" class="gh-pov__subtitle">
            <span v-if="location" class="gh-pov__subtitle-loc"><k-icon type="pin" />{{ location }}</span>
            <span v-if="constructionDate" class="gh-pov__subtitle-date"> · {{ constructionDate }}</span>
          </p>

          <!-- Short description -->
          <p v-if="description" class="gh-pov__desc">{{ description }}</p>
          <p v-else-if="canUpdate" class="gh-pov__desc gh-pov__desc--empty" @click="openInfoDialog" style="cursor:pointer;">Ajouter une description — cliquez « Modifier » pour renseigner ce champ.</p>

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

          <!-- ── Données — the raw scan/source material. These exist on
                 their own merits (research, archive, analysis); the visit is
                 just one of the things you can do with them. ── -->
          <div class="gh-pov__group-label">Données</div>
          <div class="gh-pov__assets">
            <button class="gh-pov__asset" @click="openTab('model')">
              <k-icon type="box" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Modèle 3D</strong>
                <span>{{ modelSidesSummary }}{{ hotspotsCount ? ' · ' + hotspotsCount + ' point' + (hotspotsCount > 1 ? 's' : '') + ' d’intérêt' : '' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('pointcloud')">
              <k-icon type="gh-pointcloud" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Nuage de points</strong>
                <span>Visualiseur &amp; données brutes</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <!-- Geolocation opens popup editor instead of jumping tab. -->
            <button class="gh-pov__asset" @click="canUpdate ? openGeoDialog() : openTab('details', 'geo_section')">
              <k-icon type="map" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Géolocalisation</strong>
                <span>{{ lat && lng ? lat + ', ' + lng : 'Coordonnées non définies' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('documents', 'plans')">
              <k-icon type="image" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Plans &amp; relevés</strong>
                <span>{{ plansCount ? plansCount + ' fichier' + (plansCount > 1 ? 's' : '') : 'Aucun plan' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <button class="gh-pov__asset" @click="openTab('documents', 'docs')">
              <k-icon type="file-document" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Autres documents</strong>
                <span>{{ docsCount ? docsCount + ' fichier' + (docsCount > 1 ? 's' : '') : 'Aucun document' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>
          </div>

          <!-- ── Visite virtuelle — things curated *for* the public visit:
                 the editorial story, the curated images, the spec card and
                 the tag chips. Independent of the raw data above. ── -->
          <div class="gh-pov__group-label">Visite virtuelle</div>
          <div class="gh-pov__assets">
            <!-- Image de couverture — the hero shown on cards & the public
                 page header. Opens the same dialog as clicking the cover. -->
            <button class="gh-pov__asset" @click="canUpdate && openCoverDialog()">
              <k-icon type="image" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Image de couverture</strong>
                <span>{{ coverUrl ? 'Définie' : 'Aucune image' }}</span>
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

            <!-- Caractéristiques — architect/style/dimensions/protection, opens the Info dialog. -->
            <button class="gh-pov__asset" @click="canUpdate && openInfoDialog()">
              <k-icon type="info" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Caractéristiques</strong>
                <span>{{ architect || style || 'Architecte, style, protection…' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <!-- Tags — its own tile (and its own dialog) so it's a first-class concept. -->
            <button class="gh-pov__asset" @click="canUpdate && openTagsDialog()">
              <k-icon type="tag" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Tags</strong>
                <span>{{ tags && tags.length ? tags.length + ' tag' + (tags.length > 1 ? 's' : '') + (primaryTag ? ' · ' + primaryTag + ' en avant' : '') : 'Aucun tag' }}</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>
          </div>

          <!-- ── Diffusion — how the project reaches the outside world ── -->
          <div class="gh-pov__group-label">Diffusion</div>
          <div class="gh-pov__assets">
            <!-- Visibility + share — surfaces the header pill dropdown so the
                 user can manage access without having to find the header button.
                 Edit-only: hidden for read-only viewers. -->
            <button v-if="canUpdate" class="gh-pov__asset" @click="openVisibility()">
              <k-icon type="share" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Visibilité &amp; partage</strong>
                <span>Gérer l'accès et les liens de partage</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <!-- Embed — iframe snippet to drop the 3D viewer into another site. -->
            <button class="gh-pov__asset" @click="openEmbedDialog()">
              <k-icon type="code" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Intégrer</strong>
                <span>Code iframe pour un autre site</span>
              </div>
              <k-icon type="angle-right" class="gh-pov__asset-arrow" />
            </button>

            <!-- Structured ZIP download — renames files to project-slug prefix
                 and organises them by category into subfolders. -->
            <button class="gh-pov__asset" @click="downloadPackage($event)">
              <k-icon type="download" class="gh-pov__asset-ico" />
              <div class="gh-pov__asset-body">
                <strong>Télécharger le dossier</strong>
                <span>Archive ZIP structurée et renommée</span>
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

              <div class="gh-cover-grid" role="group" aria-label="Images disponibles pour la couverture">
                <div
                  v-for="img in pageImages"
                  :key="img.uuid"
                  class="gh-cover-grid__item"
                  :class="{ 'is-selected': selectedCoverUuid === img.uuid }"
                  role="button"
                  tabindex="0"
                  :aria-pressed="selectedCoverUuid === img.uuid ? 'true' : 'false'"
                  :aria-label="'Couverture : ' + img.name"
                  @click="selectCover(img)"
                  @keydown.enter.prevent="selectCover(img)"
                  @keydown.space.prevent="selectCover(img)"
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
                  {{ uploadingCover ? 'Envoi…' : 'Ajouter une image' }}
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
          <!-- Location is intentionally absent — it lives exclusively in
               the Géolocalisation dialog which also saves lat/lng. -->
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

              <div class="gh-info-dialog__field">
                <label class="gh-info-dialog__label">Titre du projet</label>
                <input type="text" v-model="localInfo.title" class="gh-info-dialog__input" />
              </div>

              <div class="gh-info-dialog__field">
                <label class="gh-info-dialog__label">Description courte</label>
                <textarea v-model="localInfo.description" class="gh-info-dialog__input gh-info-dialog__textarea" rows="3"></textarea>
              </div>

              <div class="gh-info-dialog__row">
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Architecte / Créateur</label>
                  <input type="text" v-model="localInfo.architect" class="gh-info-dialog__input" />
                </div>
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Style architectural</label>
                  <input type="text" v-model="localInfo.style" class="gh-info-dialog__input" />
                </div>
              </div>

              <div class="gh-info-dialog__row">
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Date de construction</label>
                  <input type="text" v-model="localInfo.constructionDate" class="gh-info-dialog__input" placeholder="ex. 1847–1852" />
                </div>
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Date du scan</label>
                  <input type="date" v-model="localInfo.scanDate" class="gh-info-dialog__input" />
                </div>
              </div>

              <div class="gh-info-dialog__row">
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Dimensions</label>
                  <input type="text" v-model="localInfo.dimensions" class="gh-info-dialog__input" placeholder="ex. 45 m × 18 m" />
                </div>
                <div class="gh-info-dialog__field">
                  <label class="gh-info-dialog__label">Statut de protection</label>
                  <div class="gh-info-dialog__select-wrap">
                    <select v-model="localInfo.protectionStatus" class="gh-info-dialog__input gh-info-dialog__select">
                      <option v-for="opt in protectionOptions" :key="opt.value" :value="opt.value">{{ opt.text }}</option>
                    </select>
                  </div>
                </div>
              </div>

            </div>
          </k-dialog>

          <!-- ── Dialog Tags ── -->
          <k-dialog
            v-if="tagsDialogOpen"
            ref="tagsDialog"
            :submit-button="{ text: 'Enregistrer', icon: 'check', theme: 'positive' }"
            :cancel-button="{ text: 'Annuler' }"
            @submit="saveTags"
            @cancel="tagsDialogOpen = false"
            size="medium"
          >
            <div class="gh-tags-dialog">
              <label class="k-label">Tags</label>
              <div class="gh-tagchips">
                <span v-for="t in tagsDraft" :key="t" class="gh-tagchip">
                  <span class="gh-tagchip__label">{{ t }}</span>
                  <button type="button" class="gh-tagchip__x" @click="removeTag(t)" aria-label="Retirer le tag">×</button>
                </span>
                <input
                  class="gh-tagchips__input"
                  v-model="tagsInput"
                  @keydown.enter.prevent="commitInput"
                  @keydown="onTagKey"
                  placeholder="Ajouter…"
                />
              </div>
              <p class="gh-tags-hint">Entrée ou virgule pour ajouter un tag.</p>

              <label class="k-label gh-tags-presets-label">Catégories</label>
              <div class="gh-tagpresets">
                <button
                  v-for="p in tagPresets"
                  :key="p"
                  type="button"
                  class="gh-tagpreset"
                  :class="{ 'is-on': tagsDraft.includes(p) }"
                  @click="toggleTag(p)"
                >{{ p }}</button>
              </div>
            </div>
          </k-dialog>

          <!-- ── Dialog Embed ── -->
          <k-dialog
            v-if="embedDialogOpen"
            ref="embedDialog"
            :cancel-button="{ text: 'Fermer' }"
            :submit-button="{ text: 'Copier le code', icon: 'copy', theme: 'positive' }"
            @submit="copyEmbedCode"
            @cancel="embedDialogOpen = false"
            size="medium"
          >
            <div class="gh-embed-dialog">
              <k-text class="mb-4">Collez ce code sur un autre site pour y intégrer la visite 3D :</k-text>
              <textarea
                ref="embedCode"
                readonly
                rows="4"
                class="k-textarea-input"
                style="width:100%; padding:0.6rem 0.75rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-bg); color:var(--color-text); font-family:var(--font-mono, monospace); font-size:0.8rem; line-height:1.5;"
                @focus="$event.target.select()"
              >{{ embedCode }}</textarea>
              <p style="margin-top:0.6rem; font-size:0.78rem; color:var(--color-text-dimmed);">
                La visite s'affiche en lecture seule. Pour un projet privé ou « avec un lien », générez d'abord un lien de partage « Visite 3D » et ajoutez son paramètre <code>?key=…</code> à l'URL.
              </p>
            </div>
          </k-dialog>
        </section>
      `,

      computed: {
        canUpdate() {
          // Page permissions are a TOP-LEVEL view prop, not under model.
          return this.$panel?.view?.props?.permissions?.update ?? false;
        },
        protectionLabel() {
          const map = { 'classé': 'Classé MH', 'unesco': 'UNESCO', 'regional': 'Inventaire Régional', 'none': '' };
          return map[this.protectionStatus] || this.protectionStatus;
        },
        // Live public URL of this page — same source the header view
        // button reads from, so the "Aperçu public" tile points at the
        // real front-end page.
        previewUrl() {
          return this.$panel?.view?.props?.model?.previewUrl || '';
        },
        // Ready-to-paste iframe snippet embedding the 3D viewer.
        embedCode() {
          var base = this.previewUrl;
          if (!base) return '';
          var url = base.split('?')[0].split('#')[0] + '?embed=1';
          return '<iframe src="' + url + '" width="100%" height="600" ' +
            'style="border:0" allow="xr-spatial-tracking; fullscreen" allowfullscreen></iframe>';
        }
      },

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

        // Open the live public page in a new tab.
        openPublic() {
          if (this.previewUrl) {
            window.open(this.previewUrl, '_blank', 'noopener');
          }
        },
        // Dispatch a custom event that the viewButton component listens for.
        // Direct programmatic .click() on the trigger button doesn't work
        // because the viewButton's document outside-click handler fires in
        // the same tick and immediately closes the dropdown again.
        openVisibility() {
          document.dispatchEvent(new CustomEvent('gh:open-share-dialog'));
        },
        // Trigger a structured ZIP download for this project. Routes through
        // the shared helper (spinner feedback during server-side compression,
        // streamed via hidden iframe). Falls back to a plain redirect if the
        // helper isn't on window yet.
        downloadPackage(e) {
          var encoded = this.pageId.replace(/\//g, '+');
          var btn = e && (e.currentTarget || e.target);
          if (btn && window.ghStartZipDownload) {
            window.ghStartZipDownload(encoded, btn);
          } else {
            window.location = '/gh/download/' + encoded;
          }
        },

        // Embed dialog — shows the iframe snippet to paste elsewhere.
        openEmbedDialog() {
          this.embedDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.embedDialog) this.$refs.embedDialog.open();
          });
        },
        copyEmbedCode() {
          var code = this.embedCode;
          if (code && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(
              () => this.$panel.notification.success('Code d\'intégration copié !'),
              () => this.$panel.notification.error('Copie impossible — sélectionnez le texte manuellement.')
            );
          }
          this.embedDialogOpen = false;
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
            if (!resp.ok) throw new Error('Erreur de envoi');
            const res = await resp.json();
            this.$panel.notification.success('Image envoyée : ' + res.filename);

            const sectionData = await this.load();
            Object.assign(this.$data, sectionData);
            this.selectedCoverUuid = res.id;
          } catch (err) {
            this.$panel.notification.error(err.message || 'Envoi échoué');
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
            const sectionData = await this.load();
            Object.assign(this.$data, sectionData);
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
            const sectionData = await this.load();
            Object.assign(this.$data, sectionData);
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

        // Info Dialog methods — edits the project's characteristics (the
        // Tags dialog is separate so the overview can offer distinct tiles).
        openInfoDialog() {
          this.localInfo = {
            title: this.pageTitle || '',
            description: this.description || '',
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
        },

        // ── Tags Dialog (chip editor) ───────────────────────────────────────
        normTag(t) {
          return String(t || '').trim().toLowerCase().replace(/\s+/g, '-');
        },
        addTag(raw) {
          const t = this.normTag(raw);
          if (t && !this.tagsDraft.includes(t)) this.tagsDraft.push(t);
        },
        removeTag(t) {
          this.tagsDraft = this.tagsDraft.filter((x) => x !== t);
        },
        toggleTag(t) {
          if (this.tagsDraft.includes(t)) this.removeTag(t);
          else this.addTag(t);
        },
        commitInput() {
          this.tagsInput.split(',').forEach((t) => this.addTag(t));
          this.tagsInput = '';
        },
        onTagKey(e) {
          if (e.key === ',') {
            e.preventDefault();
            this.commitInput();
          } else if (e.key === 'Backspace' && !this.tagsInput && this.tagsDraft.length) {
            this.removeTag(this.tagsDraft[this.tagsDraft.length - 1]);
          }
        },
        openTagsDialog() {
          this.tagsDraft = Array.isArray(this.tags) ? this.tags.slice() : [];
          this.tagsInput = '';
          this.tagsDialogOpen = true;
          this.$nextTick(() => {
            if (this.$refs.tagsDialog) this.$refs.tagsDialog.open();
          });
        },
        async saveTags() {
          const pageId = this.pageId.replace(/\//g, '+');
          this.commitInput();                       // fold a half-typed tag in the input
          const tagsArr = this.tagsDraft.slice();
          try {
            await this.$panel.api.patch('pages/' + pageId, {
              tags: tagsArr.join(',')
            });
            this.$panel.notification.success('Tags enregistrés');
            this.tagsDialogOpen = false;
            await this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error('Erreur : ' + (e.message || 'Erreur inconnue'));
          }
        }
      },
    };

// Plugin registration — `sections` key handles the k-{type}-section
// name internally; no need to also register under `components`.
panel.plugin('goheritage/project-ux', {

  // ── Custom panel icons ──────────────────────────────────────────────
  // A radial dot cluster that reads as a "nuage de points": a big dot in
  // the centre with a ring of smaller dots around it, like points
  // converging on a scanned object. Kirby fills icon shapes with
  // currentColor (.k-icon { fill: currentColor }), so the circles need no
  // explicit fill. Used by the Nuage de points tab (blueprint
  // `icon: gh-pointcloud`) and its overview tile.
  icons: {
    // Centre dot is the largest; eight satellites sit on a circle of
    // radius 7 around (12,12) at 45° steps, forming a round cluster.
    'gh-pointcloud':
      // Centre (biggest)
      '<circle cx="12" cy="12" r="2.6"/>' +
      // Ring of 8, clockwise from top
      '<circle cx="12"   cy="5"    r="1.3"/>' +
      '<circle cx="16.95" cy="7.05" r="1.3"/>' +
      '<circle cx="19"   cy="12"   r="1.3"/>' +
      '<circle cx="16.95" cy="16.95" r="1.3"/>' +
      '<circle cx="12"   cy="19"   r="1.3"/>' +
      '<circle cx="7.05" cy="16.95" r="1.3"/>' +
      '<circle cx="5"    cy="12"   r="1.3"/>' +
      '<circle cx="7.05" cy="7.05" r="1.3"/>',
  },

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
            <span class="gh-visibility__label">
              Visibilité : <strong>{{ currentOption.label }}</strong>
            </span>
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
              :disabled="!canUpdate"
              @click="canUpdate ? select(opt.value) : null"
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
                <k-icon type="url" /> Gérer les liens de partage
              </button>
            </div>
          </div>

          <!-- ── Share / Access Manager — custom overlay, no deprecated k-dialog ── -->
          <div
            v-if="shareDialogOpen"
            class="gh-share-overlay"
            @mousedown.self="closeShareDialog"
          >
            <div class="gh-share-modal" role="dialog" aria-modal="true" @click.stop>
              <!-- Header -->
              <div class="gh-share-modal__header">
                <h2 class="gh-share-modal__title">
                  <k-icon type="url" /> Liens de partage
                </h2>
                <button type="button" class="gh-share-modal__close" @click="closeShareDialog" title="Fermer">
                  <k-icon type="cancel-small" />
                </button>
              </div>

              <!-- Links manager. Page-level visibility (Privé / Avec un lien /
                   Public) is set from the header pill — NOT duplicated here, so
                   there's one obvious place to change it. -->
              <div class="gh-share-modal__body">

                <!-- Private page: links can't resolve — point back to the pill. -->
                <div v-if="dialogVisibility === 'private'" class="gh-share-dialog__locked">
                  <k-icon type="lock" />
                  <p>Cette page est privée. Passez-la sur <strong>« Avec un lien »</strong> via le bouton <strong>Visibilité</strong> pour créer des liens de partage.</p>
                </div>

                <div v-else class="gh-share-dialog__links">
                  <div class="gh-share-dialog__section-header">
                    <p class="gh-share-dialog__intro">Toute personne disposant d'un lien y accède selon le niveau choisi.</p>
                    <k-button v-if="canUpdate" icon="add" size="sm" variant="filled" @click="createShareLink">
                      Créer un lien
                    </k-button>
                  </div>

                  <div v-if="localShareLinks.length === 0" class="gh-share-dialog__empty">
                    <k-icon type="info" /> Aucun lien pour l'instant. Créez-en un et choisissez son niveau d'accès.
                  </div>

                  <div v-else class="gh-share-dialog__list">
                    <div
                      v-for="link in localShareLinks"
                      :key="link.id"
                      class="gh-share-dialog__link-item"
                    >
                      <!-- Main row: [type btn] [url box w/ copy inside] [trash] -->
                      <div class="gh-share-dialog__link-row">

                        <!-- Type switch button — icon + label + chevron -->
                        <button
                          type="button"
                          class="gh-share-dialog__type-btn"
                          :class="'gh-share-dialog__type-btn--' + (link.access || 'visit')"
                          :title="currentLevel(link).label + ' — cliquez pour modifier'"
                          @click.stop="toggleTypePopover(link, $event)"
                        >
                          <k-icon :type="currentLevel(link).icon" class="gh-share-dialog__type-btn-ico" />
                          <span class="gh-share-dialog__type-btn-label">{{ currentLevel(link).label }}</span>
                          <k-icon type="angle-down" class="gh-share-dialog__type-btn-chevron" :class="{ 'is-open': typePopoverId === link.id }" />
                        </button>

                        <!-- URL box — copy icon lives inside the box -->
                        <div class="gh-share-dialog__link-url-box">
                          <code class="gh-share-dialog__link-url">{{ getLinkUrl(link) }}</code>
                          <button type="button" class="gh-share-dialog__url-copy-btn" @click.stop="copyLinkUrl(link)" title="Copier le lien">
                            <k-icon type="copy" />
                          </button>
                        </div>

                        <!-- Trash only -->
                        <button v-if="canUpdate" type="button" class="gh-share-dialog__icon-btn gh-share-dialog__icon-btn--danger" @click="deleteShareLink(link.id)" title="Supprimer le lien">
                          <k-icon type="trash" />
                        </button>
                      </div>

                      <!-- Section pills — only for Visite type -->
                      <div v-if="(link.access || 'visit') === 'visit'" class="gh-share-dialog__link-perms">
                        <button
                          v-for="sec in sectionOptions"
                          :key="sec.value"
                          type="button"
                          class="gh-share-dialog__perm-pill"
                          :class="{ 'is-active': link.visible_sections.includes(sec.value) }"
                          :disabled="!canUpdate"
                          @click="toggleLinkSection(link, sec.value)"
                        >{{ sec.label }}</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Access-level popover — position:fixed so it escapes modal overflow clipping -->
              <div
                v-if="typePopoverId"
                class="gh-share-dialog__type-popover"
                :style="{ top: typePopoverPos.top + 'px', left: typePopoverPos.left + 'px' }"
                @click.stop
              >
                <div class="gh-share-dialog__type-popover-title">Niveau d'accès</div>
                <button
                  v-for="lvl in accessLevels"
                  :key="lvl.value"
                  type="button"
                  class="gh-share-dialog__type-opt"
                  :class="{ 'is-active': activeLinkAccess === lvl.value }"
                  :disabled="!canUpdate"
                  @click="applyTypePopover(lvl.value)"
                >
                  <k-icon :type="lvl.icon" class="gh-share-dialog__type-opt-icon" />
                  <span class="gh-share-dialog__type-opt-text">
                    <strong>{{ lvl.label }}</strong>
                    <span>{{ lvl.help }}</span>
                  </span>
                  <k-icon v-if="activeLinkAccess === lvl.value" type="check" class="gh-share-dialog__type-opt-check" />
                </button>
                <div v-if="activeLinkAccess === 'editor'" class="gh-share-dialog__type-warn">
                  <k-icon type="alert" /> Donne un accès en modification au projet. Réservé aux personnes de confiance.
                </div>
              </div>

              <!-- Footer -->
              <div class="gh-share-modal__footer">
                <k-button variant="filled" theme="positive" icon="check" @click="closeShareDialog">
                  Terminé
                </k-button>
              </div>
            </div>
          </div>
        </div>
      `,

      data() {
        return {
          open: false,
          shareDialogOpen: false,
          dialogVisibility: 'private',
          localShareLinks: [],
          localVisibility: null,
          // Type-popover state: which link's popover is open + its screen position
          typePopoverId: null,
          typePopoverPos: { top: 0, left: 0 },
          sectionOptions: [
            { value: 'model',       label: '3D' },
            { value: 'pointcloud',  label: 'Nuage' },
            { value: 'info',        label: 'Fiche' },
            { value: 'gallery',     label: 'Galerie' },
            { value: 'plans',       label: 'Plans' },
            { value: 'annotations', label: 'Annotations' }
          ],
          accessLevels: [
            { value: 'visit',  icon: 'box',  label: 'Visite 3D',     help: 'Page publique de présentation. Lecture seule, sans accès aux fichiers.' },
            { value: 'viewer', icon: 'url',  label: 'Lecture seule', help: 'Accès lecture au panel, limité à ce projet. Fichiers consultables et téléchargeables. Connexion automatique à la première ouverture du lien.' },
            { value: 'editor', icon: 'edit', label: 'Éditeur',       help: 'Accès en modification au panel, limité à ce projet. Réservé aux personnes de confiance.' },
          ],
          options: [
            { value: 'brouillon', label: 'Privé',         icon: 'lock',  help: 'Page non publiée — vous et les administrateurs uniquement.', status: 'draft',  visibility: 'private' },
            { value: 'link',      label: 'Avec un lien',  icon: 'url',   help: 'Page publiée mais non listée. Accessible via un lien partagé.', status: 'listed', visibility: 'link'    },
            { value: 'public',    label: 'Public',        icon: 'globe', help: 'Page publiée et listée sur la carte GoHéritage.',              status: 'listed', visibility: 'public'  },
          ],
        };
      },

      computed: {
        model()   { return this.$panel?.view?.props?.model ?? null; },
        // The current view's saved content lives in versions.latest (Kirby 5),
        // NOT on props.model — model only carries id/status/title/etc. Reading
        // the wrong place is why visibility/share_links never loaded.
        content() { return this.$panel?.view?.props?.versions?.latest ?? {}; },
        current() {
          if (this.localVisibility) return this.localVisibility;
          if (this.model?.status === 'draft') return 'brouillon';
          const v = this.content.visibility;
          if (v === 'public') return 'public';
          if (v === 'link')   return 'link';
          // Safe default: a listed page with no/unknown visibility is
          // link-only, never silently public.
          return 'link';
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
            access: l.access || 'visit',
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
        },
        canUpdate() {
          // Page permissions are a TOP-LEVEL view prop, not under model.
          return this.$panel?.view?.props?.permissions?.update ?? false;
        },

        // Current access level of the link whose type popover is open.
        activeLinkAccess() {
          if (!this.typePopoverId) return null;
          const link = this.localShareLinks.find(l => l.id === this.typePopoverId);
          return link ? (link.access || 'visit') : null;
        },
      },

      mounted() {
        // Listen for the overview tile's open-share-dialog event.
        // CustomEvent is more reliable than DOM property assignment across
        // Vue re-renders and panel SPA navigation.
        this._ghShareEvt = () => this.openShareDialog();
        document.addEventListener('gh:open-share-dialog', this._ghShareEvt);

        this._docHandler = (e) => {
          const inTrigger = this.$el && this.$el.contains(e.target);
          // Close the visibility dropdown when clicking outside
          if (this.open && !inTrigger) this.open = false;
          // Close the type popover when clicking outside a .gh-share-dialog__type-btn
          if (this.typePopoverId && !e.target.closest('.gh-share-dialog__type-btn') && !e.target.closest('.gh-share-dialog__type-popover')) {
            this.typePopoverId = null;
          }
        };
        document.addEventListener('click', this._docHandler);
        this._escHandler = (e) => {
          if (e.key === 'Escape') {
            this.open = false;
            this.typePopoverId = null;
          }
        };
        document.addEventListener('keydown', this._escHandler);

        // Auto-restore share dialog across redirects
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('share') === 'true') {
          this.openShareDialog();
          const cleanSearch = window.location.search.replace(/[?&]share=true/, '').replace(/^&/, '?');
          const newUrl = window.location.pathname + (cleanSearch === '?' ? '' : cleanSearch);
          window.history.replaceState({}, '', newUrl);
        }
      },

      beforeDestroy() {
        if (this._docHandler)  document.removeEventListener('click',   this._docHandler);
        if (this._escHandler)  document.removeEventListener('keydown', this._escHandler);
        if (this._ghShareEvt)  document.removeEventListener('gh:open-share-dialog', this._ghShareEvt);
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
            // Always use custom PHP endpoint for consistent behavior & impersonation
            const res = await this.$panel.api.patch('gh/pages/' + pageId + '/visibility', {
              visibility: opt.visibility,
            });
            if (res && res.status === 'error') throw new Error(res.message);

            // DO NOT mutate this.content.visibility here — that writes into
            // Kirby's reactive form state and marks the page "dirty".  Any
            // subsequent section-save then re-sends the hidden visibility field
            // with the OLD form value, reverting the change.  Instead, always
            // reload the view so the form re-reads from disk (correct value +
            // clean dirty flag).

            var openLinks = (value === 'link');

            // If folder was renamed (draft→listed adds numeric prefix), redirect.
            if (res && res.panelId && res.panelId !== pageId) {
              window.location.href = '/panel/pages/' + res.panelId + '?tab=overview' + (openLinks ? '&share=true' : '');
              return;
            }

            this.open = false;
            this.$panel.notification.success('Visibilité : ' + opt.label);

            // Reload syncs form with new on-disk value and clears dirty flag.
            // viewButton components survive view.reload() unmounted, so
            // openShareDialog() below is safe.
            await this.$panel.view.reload();
            this.localVisibility = null;

            if (openLinks) {
              this.openShareDialog();
            }
          } catch (e) {
            this.localVisibility = null;
            this.$panel.notification.error(
              'Impossible de mettre à jour : ' + (e && e.message ? e.message : 'erreur inconnue')
            );
          }
        },

        openShareDialog() {
          this.open = false;
          this._dialogNeedsReload = false;
          this.typePopoverId = null;
          this.dialogVisibility = this.model.status === 'draft' ? 'private' : (this.content.visibility || 'link');
          this.localShareLinks = JSON.parse(JSON.stringify(this.shareLinks));
          this.shareDialogOpen = true;
          this.localVisibility = this.dialogVisibility === 'private' ? 'brouillon' : this.dialogVisibility;
        },

        async closeShareDialog() {
          this.typePopoverId = null;
          this.shareDialogOpen = false;
          if (this._dialogNeedsReload) {
            this._dialogNeedsReload = false;
            await this.$panel.view.reload();
            this.localVisibility = null; // Clear override to sync with fresh props
          }
        },
        async saveShareLinks() {
          if (!this.model) return;
          const pageId = this.model.id.replace(/\//g, '+');
          const formatted = this.localShareLinks.map(l => ({
            id: l.id,
            token: l.token,
            label: l.label,
            access: l.access || 'visit',
            visible_sections: l.visible_sections.join(',')
          }));
          try {
            await this.$panel.api.patch('pages/' + pageId, { share_links: formatted });
            // Don't reload here — we're inside the dialog and reload remounts
            // the component (resetting shareDialogOpen). Mark for reload on close.
            this._dialogNeedsReload = true;
          } catch (e) {
            this.$panel.notification.error('Erreur lors de la sauvegarde : ' + e.message);
          }
        },

        createShareLink() {
          // 32 bytes from the CSPRNG → 64 hex chars, 256 bits of entropy.
          // Math.random() was used before — it's a predictable PRNG, not
          // suitable for security tokens.
          var tokenBytes = new Uint8Array(32);
          var idBytes    = new Uint8Array(8);
          crypto.getRandomValues(tokenBytes);
          crypto.getRandomValues(idBytes);
          var toHex = function(buf) {
            return Array.from(buf).map(function(b) { return b.toString(16).padStart(2, '0'); }).join('');
          };
          const newLink = {
            id:               'lnk_' + toHex(idBytes),
            token:            toHex(tokenBytes),
            label:            '',
            access:           'visit',
            visible_sections: ['model', 'pointcloud', 'info', 'gallery', 'plans', 'annotations']
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
          const prev = this.dialogVisibility;
          this.dialogVisibility = value; // Immediate UI feedback
          const visMap = { private: 'private', link: 'link', public: 'public' };
          const visibility = visMap[value] || 'public';
          const pageId = this.model.id.replace(/\//g, '+');
          try {
            // Always use custom PHP endpoint for consistent behavior & impersonation
            const res = await this.$panel.api.patch('gh/pages/' + pageId + '/visibility', {
              visibility: visibility,
            });
            if (res && res.status === 'error') throw new Error(res.message);

            // DO NOT mutate this.content.visibility — same reason as commit():
            // would mark form dirty and cause save to revert the change.
            // _dialogNeedsReload triggers a view.reload() when dialog closes.
            this.localVisibility = visibility === 'private' ? 'brouillon' : visibility;

            // If folder was renamed, redirect but automatically reopen the dialog!
            if (res && res.panelId && res.panelId !== pageId) {
              window.location.href = '/panel/pages/' + res.panelId + '?tab=overview&share=true';
              return;
            }

            this._dialogNeedsReload = true;
            this.$panel.notification.success('Accès général mis à jour.');
          } catch (e) {
            this.dialogVisibility = prev; // Revert on error
            this.localVisibility = prev === 'private' ? 'brouillon' : prev;
            this.$panel.notification.error('Impossible de modifier la visibilité : ' + e.message);
          }
        },

        currentLevel(link) {
          const a = (link && link.access) || 'visit';
          return this.accessLevels.find(l => l.value === a) || this.accessLevels[0];
        },

        // ── Type popover ─────────────────────────────────────────────────
        // activeLinkAccess is computed from the open link — used in the popover
        // template to highlight the current selection without the full link obj.
        toggleTypePopover(link, event) {
          if (this.typePopoverId === link.id) {
            this.typePopoverId = null;
            return;
          }
          // Position the fixed popover just below the icon button
          const btn = event.currentTarget;
          const rect = btn.getBoundingClientRect();
          // Clamp to viewport right edge so it doesn't overflow off-screen
          const popoverWidth = 300;
          const left = Math.min(rect.left, window.innerWidth - popoverWidth - 12);
          this.typePopoverPos = { top: rect.bottom + 6, left: Math.max(8, left) };
          this.typePopoverId = link.id;
        },

        applyTypePopover(access) {
          const link = this.localShareLinks.find(l => l.id === this.typePopoverId);
          if (link) this.setLinkAccess(link, access);
          this.typePopoverId = null;
        },

        shortToken(token) {
          return token ? String(token).slice(-6) : '';
        },

        setLinkAccess(link, access) {
          if ((link.access || 'visit') === access) return;
          this.$set ? this.$set(link, 'access', access) : (link.access = access);
          this.saveShareLinks();
        },

        // ── URL builders, one per access level ──────────────────────────
        getVisitUrl(token) {
          const base = this.model?.previewUrl || this.model?.link || '';
          if (!base) return '';
          return base.split('?')[0].split('#')[0] + '?key=' + token;
        },
        getViewerUrl(token) {
          const slug = this.model.id.split('/').pop();
          return window.location.origin + '/gh-share-login/' + slug + '?key=' + token;
        },
        getEditorUrl(token) {
          const slug = this.model.id.split('/').pop();
          return window.location.origin + '/gh-share-login/' + slug + '?key=' + token;
        },

        // The single URL appropriate to a link's chosen access level.
        getLinkUrl(link) {
          const access = (link && link.access) || 'visit';
          if (access === 'editor')  return this.getEditorUrl(link.token);
          if (access === 'viewer')  return this.getViewerUrl(link.token);
          if (access === 'dossier') return this.getViewerUrl(link.token); // legacy
          return this.getVisitUrl(link.token);
        },

        copyLinkUrl(link) {
          const url = this.getLinkUrl(link);
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

    // ── "Voir la page publique" header button ────────────────────────
    // Only rendered when the page is actually reachable publicly:
    //   • public   → plain page URL (no key)
    //   • link     → page URL + ?key=<long share token> (first viewer link)
    //   • private  → component renders nothing
    'visit-page': {
      template: /* html */`
        <a
          v-if="visibility !== 'private'"
          class="gh-visit-page-btn"
          :href="publicUrl"
          target="_blank"
          rel="noopener"
          title="Voir la page publique"
        >
          <k-icon type="open" />
          <span>Voir la page publique</span>
        </a>
      `,
      computed: {
        visibility() {
          try {
            return this.$panel?.view?.props?.versions?.latest?.visibility
                || this.$panel?.view?.props?.content?.visibility
                || 'private';
          } catch (_) { return 'private'; }
        },
        publicUrl() {
          try {
            // model.url isn't reliably present in panel props; previewUrl is
            // the canonical frontend URL. Strip its query (Kirby's preview
            // token) so public pages get a clean visitor URL.
            var m  = this.$panel?.view?.props?.model || {};
            var pv = m.previewUrl || m.url || '';
            var base = String(pv).split('?')[0];
            if (!base) return '#';
            if (this.visibility === 'public') return base;
            // Link-only page: append our long cryptographic share token as ?key=
            var raw = this.$panel?.view?.props?.versions?.latest?.share_links
                   || this.$panel?.view?.props?.content?.share_links
                   || '';
            var token = '';
            String(raw).replace(/token:\s*([^\s\n]+)/g, function (_, t) {
              if (!token) token = t.trim();
            });
            return token ? base + '?key=' + encodeURIComponent(token) : base;
          } catch (_) { return '#'; }
        },
      }
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

  // True when the current user may edit this page. Read-only viewers (share
  // link "Lecture seule") get `permissions.update === false`, so we use this
  // to suppress every edit affordance we inject (toggles, edit bars). This is
  // UX only — the server enforces the real lockdown via the viewer role + the
  // write-guard hook; this just stops dead "Modifier" buttons from showing.
  function ghCanUpdate() {
    try {
      var p = window.panel?.view?.props?.permissions;
      // Default to TRUE when permissions aren't populated yet, so admins/
      // editors never get a flicker of hidden controls during boot.
      if (p && typeof p.update === 'boolean') return p.update;
    } catch (_) {}
    return true;
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
    content_section:     'Contenu détaillé',
    gallery_section:     'Galerie d’images',
    viewer_settings:     'Réglages du viewer',
    exterior_files:      'Modèle extérieur',
    interior_files:      'Modèle intérieur',
    annotations_section: 'Points d’intérêt',
    pointcloud_settings: 'Visualiseur',
    pointcloud_files:    'Données brutes',
    all_files:           'Inventaire des fichiers',
    plans:               'Plans & relevés',
    docs:                'Autres documents',
  };

  // ── Read/edit toggle infrastructure (shared by the Modèle 3D + Détails
  //    tabs) ──────────────────────────────────────────────────────────
  // Pen = enter edit, X = exit edit. Hoisted to module scope so both the
  // injectors and the delegated click handler can reuse them.
  var GH_PEN_SVG =
    '<svg class="gh-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
  var GH_X_SVG =
    '<svg class="gh-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

  // Repaint a toggle button (pen+Modifier in read mode, X+Fermer in edit
  // mode) to match the current body mode attribute.
  function paintToggleBtn(btn, modeAttr) {
    if (!btn) return;
    var editing = document.body.getAttribute(modeAttr) === 'edit';
    btn.innerHTML = (editing ? GH_X_SVG : GH_PEN_SVG) +
      '<span class="gh-btn__label">' + (editing ? 'Fermer' : 'Modifier') + '</span>';
  }

  // Delegated click handling for EVERY read/edit toggle (model, point cloud,
  // details). Each toggle button carries `data-gh-toggle="<body mode attr>"`.
  // After switching to edit mode we force a rescan so sections that were hidden
  // (display:none) when scan() first ran get their .gh-section tagging now
  // that they're visible. Without this, sections appear with raw Kirby styling.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-gh-toggle]');
    if (!btn) return;
    var attr = btn.getAttribute('data-gh-toggle');
    var mode = document.body.getAttribute(attr) === 'edit' ? 'read' : 'edit';
    document.body.setAttribute(attr, mode);
    paintToggleBtn(btn, attr);
    if (mode === 'edit') rescan();
  });

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

  /*  Viewer tabs (Modèle 3D, Nuage de points) — read mode shows a full-bleed
   *  iframe viewer; edit mode shows the Kirby upload sections. A compact
   *  titled header (rendered IN the tab content, so it's cleared whenever the
   *  panel swaps the view) carries the pen/X toggle. Body data-attr drives
   *  the show/hide CSS.
   *
   *  cfg = { tab, bodyClass, modeAttr, previewId, headId, title, query } */
  function ensureViewerTab(cfg) {
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab');

    // Off this tab → remove ALL our chrome for it (header + preview) and
    // clear the body flags. (The old code removed only the preview, so the
    // toggle header leaked onto other tabs — that's the "won't disappear".)
    if (tab !== cfg.tab) {
      document.querySelectorAll('#' + cfg.previewId + ', #' + cfg.headId)
        .forEach(function (e) { e.remove(); });
      document.body.classList.remove(cfg.bodyClass);
      document.body.removeAttribute(cfg.modeAttr);
      return;
    }

    document.body.classList.add(cfg.bodyClass);
    var readOnly = !ghCanUpdate();
    // Read-only users never leave the viewer — pin to read mode regardless of
    // any stale attribute and don't render the edit toggle below.
    if (readOnly) {
      document.body.setAttribute(cfg.modeAttr, 'read');
    } else if (!document.body.getAttribute(cfg.modeAttr)) {
      document.body.setAttribute(cfg.modeAttr, 'read');
    }

    var slug = '';
    try {
      var id = window.panel && window.panel.view && window.panel.view.props
            && window.panel.view.props.id;
      if (typeof id === 'string') slug = id;
    } catch (_) {}
    if (!slug) return;

    // Dedup: leave a healthy (1 head + 1 preview) pair alone so the iframe
    // isn't reloaded on every scan tick.
    var heads = document.querySelectorAll('#' + cfg.headId);
    var prevs = document.querySelectorAll('#' + cfg.previewId);
    if (heads.length === 1 && prevs.length === 1) return;
    heads.forEach(function (e) { e.remove(); });
    prevs.forEach(function (e) { e.remove(); });

    var firstSection = document.querySelector('.k-page-view .k-section');
    var host = firstSection && firstSection.parentNode;
    if (!host) return;

    // Compact header that reads like a content title row (title left, pen/X
    // toggle right) instead of a full-width strip under the tab bar.
    var head = document.createElement('div');
    head.id = cfg.headId;
    head.className = 'gh-viewer-head';
    head.innerHTML =
      '<span class="gh-viewer-head__title">' + cfg.title + '</span>' +
      (readOnly ? '' :
        '<button type="button" class="gh-btn gh-btn--sm" data-gh-toggle="' + cfg.modeAttr + '"></button>');
    host.insertBefore(head, firstSection);

    var wrap = document.createElement('div');
    wrap.id = cfg.previewId;
    wrap.className = 'gh-viewer-preview';
    // autoplay=1 skips the public page's "press play" splash so the viewer
    // loads straight away — in the panel it's the dataset's centrepiece.
    wrap.innerHTML =
      '<iframe class="gh-viewer-preview__frame" src="/' + slug + cfg.query + '" ' +
        'allow="xr-spatial-tracking; fullscreen" loading="lazy"></iframe>';
    host.insertBefore(wrap, firstSection);

    // Clicks are handled by the delegated [data-gh-toggle] listener; here we
    // only paint the initial icon/label.
    paintToggleBtn(head.querySelector('[data-gh-toggle]'), cfg.modeAttr);
  }

  function ensureModelPreview() {
    ensureViewerTab({
      tab: 'model', bodyClass: 'gh-on-model-tab', modeAttr: 'data-gh-model-mode',
      previewId: 'gh-model-preview', headId: 'gh-model-head',
      title: 'Modèle 3D',
      query: '?embed=1&viewer=only&autoplay=1'
    });
  }

  function ensurePointcloudPreview() {
    ensureViewerTab({
      tab: 'pointcloud', bodyClass: 'gh-on-pointcloud-tab', modeAttr: 'data-gh-pointcloud-mode',
      previewId: 'gh-pointcloud-preview', headId: 'gh-pointcloud-head',
      title: 'Nuage de points',
      query: '?embed=1&viewer=only&autoplay=1&pointcloud=1'
    });
  }

  // ── Details tab read/edit mode toggle (same pattern as model tab) ──
  function _escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildDetailsShowcase(showcase) {
    var content = {};
    try { content = window.panel.view.props.versions.latest || {}; } catch (_) {}
    var desc = (content.description || '').trim();

    // Skeleton: description renders immediately (we already have it from the
    // view props); cover + rendered blocks + gallery are fetched from the
    // details-preview API route and filled in below.
    showcase.innerHTML =
      '<div class="gh-details-showcase__inner">' +
        '<div class="gh-details-showcase__cover-slot"></div>' +
        (desc ? '<p class="gh-details-showcase__desc">' + _escHtml(desc) + '</p>' : '') +
        '<div class="gh-details-showcase__content"><p class="gh-details-showcase__loading">Chargement de l’aperçu…</p></div>' +
        '<div class="gh-details-showcase__gallery-slot"></div>' +
      '</div>';

    var id = '';
    try { id = window.panel.view.props.id || ''; } catch (_) {}
    if (!id) return;
    var enc  = id.replace(/\//g, '+');
    var path = 'gh/pages/' + enc + '/details-preview';

    // Prefer the panel API helper; fall back to a raw fetch so the preview
    // never hangs on the loading state if the helper isn't present.
    var request;
    if (window.panel && window.panel.api && window.panel.api.get) {
      request = window.panel.api.get(path);
    } else {
      request = fetch('/api/' + path, {
        headers: { 'X-CSRF': (window.panel && window.panel.csrf) || '' },
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); });
    }

    request.then(function (res) {
      if (!res || res.status !== 'ok') { throw new Error('bad response'); }

      // Cover
      var coverSlot = showcase.querySelector('.gh-details-showcase__cover-slot');
      if (coverSlot) {
        if (res.coverUrl) {
          var img = document.createElement('img');
          img.className = 'gh-details-showcase__cover';
          img.src = res.coverUrl;
          img.alt = '';
          coverSlot.appendChild(img);
        } else {
          coverSlot.remove();
        }
      }

      // Rendered editorial blocks (server-rendered HTML, same as public page)
      var contentEl = showcase.querySelector('.gh-details-showcase__content');
      if (contentEl) {
        if (res.blocksHtml && res.blocksHtml.trim()) {
          contentEl.innerHTML = res.blocksHtml;
        } else {
          contentEl.innerHTML =
            '<p class="gh-details-showcase__desc gh-details-showcase__desc--empty">' +
            'Aucun contenu éditorial. Basculez en mode édition pour en ajouter.</p>';
        }
      }

      // Gallery thumbnails
      var gallerySlot = showcase.querySelector('.gh-details-showcase__gallery-slot');
      if (gallerySlot) {
        if (Array.isArray(res.gallery) && res.gallery.length) {
          var grid = document.createElement('div');
          grid.className = 'gh-details-showcase__gallery';
          res.gallery.forEach(function (u) {
            var t = document.createElement('img');
            t.className = 'gh-details-showcase__thumb';
            t.src = u;
            t.alt = '';
            t.loading = 'lazy';
            grid.appendChild(t);
          });
          gallerySlot.appendChild(grid);
        } else {
          gallerySlot.remove();
        }
      }
    }).catch(function () {
      var contentEl = showcase.querySelector('.gh-details-showcase__content');
      if (contentEl) {
        contentEl.innerHTML =
          '<p class="gh-details-showcase__desc gh-details-showcase__desc--empty">Aperçu indisponible.</p>';
      }
    });
  }

  // ── Tab-bar download button ────────────────────────────────────────
  // Injects a compact icon button just before the "Fichiers" tab so the
  // user can trigger the structured ZIP download from anywhere in the
  // project without switching to Vue d'ensemble first. The button gets
  // margin-left:auto (via CSS) which pushes the [download][files] pair
  // to the far right of the flex tab bar — the files tab loses its own
  // auto-margin and sits directly after it.
  // Small download-arrow SVG (15×15, stroke-based, fits comfortably inside
  // the tab button alongside the text label).
  var GH_DOWNLOAD_SVG =
    '<svg width="13" height="13" viewBox="0 0 15 15" fill="none" stroke="currentColor"' +
    ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M7.5 1v8.5"/>' +
    '<path d="M4 7l3.5 3.5L11 7"/>' +
    '<path d="M2 13h11"/>' +
    '</svg>';

  // Kick off a structured-ZIP download with live feedback. The archive is
  // built server-side (can take a while for point-cloud-heavy projects), so
  // the button shows a "Compression…" spinner while we wait. We stream the
  // attachment through a hidden iframe — native browser download, no in-memory
  // buffering (the zips can be hundreds of MB) and the panel never navigates.
  // The server sets a JS-readable `gh_dl_done=<token>` cookie the instant
  // compression finishes and bytes start flowing; we poll for it to drop the
  // spinner. Exposed on window so the Vue overview tile can reuse it.
  function ghStartZipDownload(encoded, btn) {
    if (!btn || btn.dataset.ghDl === '1') return;
    btn.dataset.ghDl = '1';
    btn.classList.add('gh-dl-loading');
    btn.setAttribute('aria-busy', 'true');
    if ('disabled' in btn) btn.disabled = true;

    // Swap the visible label to a progress word (restored afterwards).
    var labelEl   = btn.querySelector('span, strong');
    var prevLabel = labelEl ? labelEl.textContent : null;
    if (labelEl) labelEl.textContent = 'Compression…';

    var token = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

    var iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = '/gh/download/' + encoded + '?dl=' + token;
    document.body.appendChild(iframe);

    var settled = false;
    function restore(ok) {
      if (settled) return;
      settled = true;
      clearInterval(poll);
      clearTimeout(failTimer);
      if (labelEl) labelEl.textContent = ok ? 'Téléchargement…' : 'Réessayer';
      setTimeout(function () {
        btn.classList.remove('gh-dl-loading');
        btn.removeAttribute('aria-busy');
        if ('disabled' in btn) btn.disabled = false;
        if (labelEl && prevLabel != null) labelEl.textContent = prevLabel;
        delete btn.dataset.ghDl;
        if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
      }, ok ? 900 : 1800);
    }

    var poll = setInterval(function () {
      if (document.cookie.indexOf('gh_dl_done=' + token) !== -1) {
        document.cookie = 'gh_dl_done=; Max-Age=0; path=/';  // one-shot
        restore(true);
      }
    }, 200);
    // Safety net matching the server's set_time_limit(300) — never leave the
    // spinner stuck if the cookie never arrives (e.g. an auth error in the
    // hidden iframe we can't observe).
    var failTimer = setTimeout(function () { restore(false); }, 300000);
  }
  window.ghStartZipDownload = ghStartZipDownload;

  function ensureDownloadBtn() {
    if (document.getElementById('gh-download-btn')) return;

    var tabs = document.querySelector('.k-tabs');
    if (!tabs) return;

    // Page ID from panel state (same source as the overview component).
    var pageId;
    try { pageId = window.panel?.view?.props?.model?.id; } catch (_) {}
    if (!pageId) return;

    var encoded = pageId.replace(/\//g, '+');

    var btn = document.createElement('button');
    btn.id        = 'gh-download-btn';
    btn.type      = 'button';
    btn.title     = 'Télécharger le dossier en ZIP structuré';
    btn.setAttribute('aria-label', 'Télécharger le dossier');
    btn.innerHTML = GH_DOWNLOAD_SVG + '<span>Télécharger</span>';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      ghStartZipDownload(encoded, btn);
    });

    // Insert AFTER the "Fichiers" tab → [Fichiers][Télécharger] right cluster.
    var filesBtn = tabs.querySelector('.k-tabs-button[href*="tab=files"], .k-tabs-button[data-tab="files"]');
    if (filesBtn && filesBtn.nextSibling) {
      tabs.insertBefore(btn, filesBtn.nextSibling);
    } else {
      tabs.appendChild(btn);
    }
  }

  function ensureDetailsToggle() {
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab');

    // Off the details tab → clean up and bail.
    if (tab !== 'details') {
      var staleBar  = document.getElementById('gh-details-bar');
      if (staleBar) staleBar.remove();
      var staleShow = document.getElementById('gh-details-showcase');
      if (staleShow) staleShow.remove();
      document.body.classList.remove('gh-on-details-tab');
      document.body.removeAttribute('data-gh-details-mode');
      return;
    }

    document.body.classList.add('gh-on-details-tab');
    var readOnly = !ghCanUpdate();
    // Read-only users are pinned to the read showcase, no edit toggle.
    if (readOnly) {
      document.body.setAttribute('data-gh-details-mode', 'read');
    } else if (!document.body.getAttribute('data-gh-details-mode')) {
      document.body.setAttribute('data-gh-details-mode', 'read');
    }

    // Dedup: if exactly one bar + one showcase already exist, nothing to do.
    var existingBars  = document.querySelectorAll('#gh-details-bar');
    var existingShows = document.querySelectorAll('#gh-details-showcase');
    if (existingShows.length === 1 && (readOnly || existingBars.length === 1)) return;
    existingBars.forEach(function(e) { e.remove(); });
    existingShows.forEach(function(e) { e.remove(); });

    var firstSection = document.querySelector('.k-page-view .k-section');
    var host = firstSection && firstSection.parentNode;
    if (!host) return;

    // Persistent toolbar at the top of the tab — edit affordance only, so it's
    // omitted entirely for read-only users (just the showcase remains).
    if (!readOnly) {
      var bar = document.createElement('div');
      bar.id = 'gh-details-bar';
      bar.className = 'gh-details-bar';
      bar.innerHTML =
        '<span class="gh-details-bar__banner">' +
          '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>' +
          ' Mode édition — modifiez le contenu et la galerie.' +
        '</span>' +
        '<button type="button" class="gh-btn" data-gh-toggle="data-gh-details-mode"></button>';
      host.insertBefore(bar, firstSection);
      paintToggleBtn(bar.querySelector('[data-gh-toggle]'), 'data-gh-details-mode');
    }

    // Showcase: pretty read-only card shown when not editing.
    var showcase = document.createElement('div');
    showcase.id = 'gh-details-showcase';
    showcase.className = 'gh-details-showcase';
    buildDetailsShowcase(showcase);
    host.insertBefore(showcase, firstSection);
  }

  function scan() {
    if (!isProjectPage()) {
      document.body.classList.remove(BODY_FLAG);
      document.body.classList.remove('gh-readonly');
      document.body.style.removeProperty('--gh-header-height');
      // Tear down every bit of tab chrome we may have injected + its flags.
      ['gh-model-head', 'gh-model-preview', 'gh-pointcloud-head', 'gh-pointcloud-preview',
       'gh-details-bar', 'gh-details-showcase', 'gh-download-btn'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.remove();
      });
      ['gh-on-model-tab', 'gh-on-pointcloud-tab', 'gh-on-details-tab'].forEach(function (c) {
        document.body.classList.remove(c);
      });
      ['data-gh-model-mode', 'data-gh-pointcloud-mode', 'data-gh-details-mode'].forEach(function (a) {
        document.body.removeAttribute(a);
      });
      return;
    }
    document.body.classList.add(BODY_FLAG);
    // Read-only flag drives the CSS that hides any residual edit affordances
    // (per-section "Modifier" docks, the visibility pill's edit actions, etc.).
    document.body.classList.toggle('gh-readonly', !ghCanUpdate());
    measureHeader();
    ensureDownloadBtn();
    ensureModelPreview();
    ensurePointcloudPreview();
    ensureDetailsToggle();

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

      // Skip the visibility_meta section — it holds only hidden fields and
      // would render as an empty styled card, which is worse than invisible.
      // We no longer query child .k-field elements here: Vue hydrates section
      // content asynchronously after the wrapper enters the DOM, so checking
      // children at scan() time is a race condition that causes the sections
      // on the Visite Virtuelle tab to be silently skipped on first scan.
      if (s.classList.contains('k-section-name-visibility_meta')) return;
      tag(s);
      // Every tagged section gets a title (even non-editable ones like
      // the file-upload sections).
      ensureHeadline(s);
      // Collapsible sections: model file groups (bulky uploads) + all
      // three content sections on the Visite Virtuelle tab (content,
      // gallery, sharing) so the long tab can be browsed without
      // scrolling through everything at once.
      if (s.classList.contains('k-section-name-exterior_files')
       || s.classList.contains('k-section-name-interior_files')
       || s.classList.contains('k-section-name-content_section')
       || s.classList.contains('k-section-name-gallery_section')
       || s.classList.contains('k-section-name-sharing_section')) {
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

  var _ghSettingsRaf = 0;
  var _ghSettingsTries = 0;
  function scheduleSettingsRework() {
    if (_ghSettingsRaf) return;
    _ghSettingsTries = 0;
    var tick = function () {
      _ghSettingsTries++;
      if (document.querySelector('.k-dropdown-content')) {
        _ghSettingsRaf = 0;
        reworkSettingsDropdown();
        return;
      }
      if (_ghSettingsTries > 20) {
        _ghSettingsRaf = 0;
        return;
      }
      _ghSettingsRaf = requestAnimationFrame(tick);
    };
    _ghSettingsRaf = requestAnimationFrame(tick);
  }

  document.addEventListener('click', function(e) {
    if (e.target.closest('.k-settings-view-button')) {
      scheduleSettingsRework();
    }
  }, { passive: true });

  /*  Make a section card collapsible by clicking its title. A chevron is
   *  appended to the title; clicking toggles .gh-collapsed on the
   *  section (CSS hides everything but the title). Used for the
   *  exterior/interior model file groups so they fold like before. */
  function makeCollapsible(section) {
    var title = section.querySelector(':scope > .gh-section__title');
    if (!title) return;
    // Guard on the TITLE element, not the section. Vue's vdom diffing removes
    // our injected child nodes on every re-render (they aren't in the vnode
    // tree), so section.dataset.ghCollapsible survives but the new title has
    // no listener/class. By guarding on the title we re-wire each new title
    // instance while still skipping titles that are already set up.
    if (title.dataset.ghCollapsible === '1') return;
    title.dataset.ghCollapsible = '1';
    section.dataset.ghCollapsible = '1';
    title.classList.add('gh-section__title--toggle');

    var chevron = document.createElement('span');
    chevron.className = 'gh-section__chevron';
    chevron.innerHTML =
      '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    title.appendChild(chevron);

    title.addEventListener('click', function () {
      section.classList.toggle('gh-collapsed');
    });
  }

  // Re-scan whenever the panel re-renders (route change, view reload).
  var rescan = function () {
    setTimeout(scan, 50);
  };

  // ── Global panel chrome (runs on EVERY view, not just project pages) ────
  //  1. Theme — mirror Kirby's *resolved* theme onto html.gh-theme-light so
  //     our custom UI follows the panel (not the OS) into light/dark.
  //  2. Sidebar — drop the Changes + Logout bottom buttons and move logout
  //     into a small popover off the Account button.

  function ghParseColor(str) {
    if (!str) return null;
    str = String(str).trim();
    var m = str.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (m) {
      var h = m[1];
      if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
      return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    }
    m = str.match(/rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)/i);
    if (m) return [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3])];
    return null;
  }

  function ghDetectTheme() {
    try {
      // Use the element's RESOLVED text colour (always an rgb), not the
      // --color-text custom prop, which Kirby sets via light-dark() and can
      // come back unresolved from getComputedStyle.
      var probe = document.querySelector('.k-panel') || document.body;
      if (!probe) return;
      var rgb = ghParseColor(getComputedStyle(probe).color);
      if (!rgb) return;
      var lum = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
      // Light text ⇒ dark panel; dark text ⇒ light panel.
      document.documentElement.classList.toggle('gh-theme-light', lum < 0.5);
    } catch (_) {}
  }

  function ghAccountKey(e) { if (e.key === 'Escape') ghCloseAccountPop(); }
  function ghAccountOutside(e) {
    var p = document.getElementById('gh-account-pop');
    if (p && !p.contains(e.target) && !(e.target.closest && e.target.closest('[data-gh-account]'))) {
      ghCloseAccountPop();
    }
  }
  function ghCloseAccountPop() {
    var p = document.getElementById('gh-account-pop');
    if (p) p.remove();
    document.removeEventListener('click', ghAccountOutside, true);
    document.removeEventListener('keydown', ghAccountKey, true);
  }
  function ghOpenAccountPop(anchor, accountHref, logoutBtn) {
    if (document.getElementById('gh-account-pop')) { ghCloseAccountPop(); return; }
    var userSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    var outSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
    var pop = document.createElement('div');
    pop.id = 'gh-account-pop';
    pop.className = 'gh-account-pop';
    pop.innerHTML =
      '<a class="gh-account-pop__item" href="' + accountHref + '">' + userSvg + '<span>Mon compte</span></a>' +
      '<button type="button" class="gh-account-pop__item gh-account-pop__item--danger" data-gh-logout>' + outSvg + '<span>Déconnexion</span></button>';
    document.body.appendChild(pop);

    var rect = anchor.getBoundingClientRect();
    var top = rect.top - pop.offsetHeight - 6;
    if (top < 8) top = rect.bottom + 6;            // flip below if no room above
    pop.style.top = top + 'px';
    pop.style.left = Math.max(8, rect.left) + 'px';

    pop.querySelector('[data-gh-logout]').addEventListener('click', function () {
      ghCloseAccountPop();
      if (logoutBtn) logoutBtn.click();            // reuse Kirby's own logout action
      else window.location.href = '/panel/logout';
    });
    setTimeout(function () {
      document.addEventListener('click', ghAccountOutside, true);
      document.addEventListener('keydown', ghAccountKey, true);
    }, 0);
  }

  function ghSetupSidebar() {
    var menu = document.querySelector('.k-panel-menu');
    if (!menu) return;
    var accountBtn = null, logoutBtn = null;
    menu.querySelectorAll('.k-panel-menu-button').forEach(function (b) {
      var href = (b.getAttribute('href') || '').replace(/\/+$/, '');
      var txt = (b.textContent || '').trim().toLowerCase();
      if (/\/logout$/.test(href) || txt === 'logout' || txt === 'déconnexion' || txt === 'se déconnecter') {
        logoutBtn = b;
      } else if (/\/account$/.test(href) || txt === 'account' || txt === 'compte' || txt === 'mon compte') {
        accountBtn = b;
      } else if (txt.indexOf('modif') === 0 || txt === 'changes' || txt.indexOf('changement') === 0) {
        b.setAttribute('data-gh-hidden', '');        // hide "Changes" / "Modifications"
      }
    });

    // Only move logout into the Account popover when we actually found the
    // Account button — otherwise we'd hide the sole logout path. Safety first.
    if (accountBtn) {
      if (!accountBtn.dataset.ghAccount) {
        accountBtn.dataset.ghAccount = '1';
        var accountHref = accountBtn.getAttribute('href') || '/panel/account';
        accountBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          ghOpenAccountPop(accountBtn, accountHref, logoutBtn);
        }, true);
      }
      if (logoutBtn) logoutBtn.setAttribute('data-gh-hidden', '');
    }
  }

  // ── Panel footer ──────────────────────────────────────────────────────
  // Mirrors the public site footer (dark bg, same fonts, same column grid).
  // Data comes from gh/footer-data (tagline, email, nav pages, social).
  // IMPORTANT: we only cache on success. If the panel isn't authenticated
  // yet when the first fetch fires, we leave _ghFooterData = null so the
  // next ghGlobalChrome tick (≤1s) retries instead of locking in empty data.
  var _ghFooterData  = null;
  var _ghFooterBusy  = false; // prevents concurrent fetches
  var _ghFooterAt    = 0;     // last successful fetch (ms) — drives the TTL refresh
  var FOOTER_TTL     = 30000; // re-pull footer data at most this often so panel
                              // edits (e.g. adding a social URL) appear without
                              // a full browser reload

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildFooterHtml(d) {
    var tagline = (d.tagline || '').replace(/\n/g, '<br>');
    var email   = d.email   || '';
    var nav     = Array.isArray(d.nav)    ? d.nav    : [];
    var social  = Array.isArray(d.social) ? d.social : [];

    function col(title, items, blank) {
      if (!items.length) return '';
      return '<nav class="gh-pf__col">' +
        '<h2 class="gh-pf__col-title">' + title + '</h2>' +
        '<ul class="gh-pf__links">' +
        items.map(function (it) {
          // Always open absolute (external) URLs in a new tab so they don't
          // navigate away from the panel SPA.
          var external = /^https?:\/\//i.test(it.url || '');
          var attrs = (blank || external) ? ' target="_blank" rel="noopener"' : '';
          return '<li><a href="' + esc(it.url) + '"' + attrs + ' class="gh-pf__link">' + esc(it.label) + '</a></li>';
        }).join('') +
        '</ul></nav>';
    }

    var navItems    = nav.map(function (p) { return { url: p.url, label: p.title }; });
    var socialItems = social.map(function (s) { return { url: s.url, label: s.platform }; });

    return (
      '<div class="gh-pf__top">' +
        '<div class="gh-pf__brand">' +
          '<img src="/assets/logos/govr.svg" alt="GOVR" class="gh-pf__logo">' +
          (tagline ? '<p class="gh-pf__tagline">' + tagline + '</p>' : '') +
          (email   ? '<a href="mailto:' + esc(email) + '" class="gh-pf__email">' + esc(email) + '</a>' : '') +
        '</div>' +
        '<div class="gh-pf__cols">' +
          col('Naviguer', navItems, false) +
          col('Nous suivre', socialItems, true) +
        '</div>' +
      '</div>' +
      '<div class="gh-pf__legal">' +
        '<span class="gh-pf__copy">© ' + new Date().getFullYear() + ' GoHéritage</span>' +
        '<a href="/contact" class="gh-pf__legal-link">Mentions légales</a>' +
        '<a href="/contact" class="gh-pf__legal-link">Confidentialité</a>' +
      '</div>'
    );
  }

  function injectPanelFooter(d) {
    var main = document.querySelector('.k-panel-main');
    if (!main) return;
    var footer = document.getElementById('gh-panel-footer');
    if (!footer) {
      footer = document.createElement('footer');
      footer.id        = 'gh-panel-footer';
      footer.className = 'gh-panel-footer';
      main.appendChild(footer);
    } else if (footer.parentNode !== main) {
      // Vue re-rendered the view and detached us — re-attach to the live main.
      main.appendChild(footer);
    }
    var html = buildFooterHtml(d);
    if (footer.innerHTML !== html) footer.innerHTML = html;
    // Always keep it the LAST child so it sits below the view content.
    if (main.lastElementChild !== footer) main.appendChild(footer);
    positionPanelFooter(footer, main);
  }

  // Full-bleed the footer using .k-panel-main's COMPUTED padding (the CSS var
  // can't be matched from inside the footer because of container-query units).
  // Cancel the inline + bottom padding with negative margins, then re-apply
  // the inline padding inside so the footer content lines up with the page.
  function positionPanelFooter(footer, main) {
    if (!footer || !main) return;
    var cs = window.getComputedStyle(main);
    footer.style.marginLeft   = '-' + cs.paddingLeft;
    footer.style.marginRight  = '-' + cs.paddingRight;
    footer.style.marginBottom = '-' + cs.paddingBottom;
    footer.style.paddingLeft  = cs.paddingLeft;
    footer.style.paddingRight = cs.paddingRight;
  }

  // The padding is container-query based, so it changes with panel width —
  // re-measure on resize. Registered once.
  if (!window.__ghFooterResizeHooked) {
    window.__ghFooterResizeHooked = true;
    window.addEventListener('resize', function () {
      positionPanelFooter(
        document.getElementById('gh-panel-footer'),
        document.querySelector('.k-panel-main')
      );
    }, { passive: true });
  }

  // Resolve footer data, trying the panel API first and falling back to a raw
  // authenticated fetch. Defensively unwraps a {data:{…}} envelope. Resolves
  // to a usable object or null (never throws).
  function fetchFooterData() {
    function rawFetch() {
      return fetch('/api/gh/footer-data', {
        headers: { 'X-CSRF': (window.panel && window.panel.csrf) || '' },
        credentials: 'same-origin'
      }).then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; });
    }
    var p;
    if (window.panel && window.panel.api && window.panel.api.get) {
      p = window.panel.api.get('gh/footer-data').catch(rawFetch);
    } else {
      p = rawFetch();
    }
    return p.then(function (d) {
      if (!d) return null;
      if (d.data && !d.nav && !d.tagline) d = d.data; // unwrap envelope
      return (d && (d.status === 'ok' || d.nav || d.tagline)) ? d : null;
    });
  }

  function ensurePanelFooter() {
    // Keep any existing footer pinned as the last child (Vue re-renders detach
    // it). Then refresh in the background once the cache is older than the TTL
    // so panel edits (social links, tagline…) appear without a full reload —
    // the stale footer stays visible meanwhile, no flicker.
    if (_ghFooterData) {
      var existing = document.getElementById('gh-panel-footer');
      var main = document.querySelector('.k-panel-main');
      if (!existing || (main && main.lastElementChild !== existing)) {
        injectPanelFooter(_ghFooterData);
      }
      if (Date.now() - _ghFooterAt < FOOTER_TTL) return; // still fresh
    }
    if (_ghFooterBusy) return;
    if (!window.panel || !window.panel.user) return; // wait for auth

    _ghFooterBusy = true;
    fetchFooterData().then(function (d) {
      _ghFooterBusy = false;
      if (d) { _ghFooterData = d; _ghFooterAt = Date.now(); injectPanelFooter(d); }
      // else: stays null, retried on the next ghGlobalChrome tick
    });
  }

  function ghGlobalChrome() {
    ghDetectTheme();
    ghSetupSidebar();
    ensurePanelFooter();
  }

  // Run once on load, then watch for SPA-style navigation.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { rescan(); ghGlobalChrome(); });
  } else {
    rescan();
    ghGlobalChrome();
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
      ghGlobalChrome();
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
  setInterval(function () { hookPanelEvents(); rescan(); ghGlobalChrome(); }, 1000);

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
