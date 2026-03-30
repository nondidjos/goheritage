/**
 * GoHéritage — Hotspot Detector panel field
 * Kirby 5 panel plugin (no build step — loaded as-is by Kirby)
 *
 * Registers the `hotspot-detect` field type used in project.yml.
 * The PHP backend (index.php) passes `pageId` and `existingCount` as
 * computed props so this component always knows which page to update.
 */

panel.plugin('goheritage/hotspot-detector', {
  fields: {
    'hotspot-detect': {
      props: {
        pageId:        { type: String,  default: '' },
        existingCount: { type: Number,  default: 0  },
        label:         { type: String,  default: 'Détecter les hotspots' },
        help:          { type: String,  default: '' },
      },

      data() {
        return {
          loading: false,
          result:  null,   // { count, added, skipped, message } on success
          error:   null,   // string on failure
        };
      },

      methods: {
        async detect() {
          this.loading = true;
          this.result  = null;
          this.error   = null;

          try {
            // Kirby encodes page IDs with + for nested paths (map/chateau → map+chateau)
            const encodedId = this.pageId.replace(/\//g, '+');
            const r = await this.$api.post('goheritage/detect-hotspots/' + encodedId, {});

            if (r.status === 'error') {
              this.error = r.message || 'Erreur inconnue';
            } else {
              this.result = r;
              // Reload the panel view after a short pause so the user
              // can read the success message before the page refreshes
              setTimeout(() => {
                this.$go(window.location.pathname);
              }, 2000);
            }
          } catch (e) {
            this.error = (e && e.message) ? e.message : 'Erreur lors de la requête';
          } finally {
            this.loading = false;
          }
        },
      },

      template: `
        <k-field v-bind="$props" class="k-hotspot-detect-field">
          <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
            <k-button
              icon="search"
              variant="filled"
              :theme="loading ? 'passive' : 'positive'"
              :disabled="loading"
              @click="detect"
            >
              {{ loading ? 'Détection en cours…' : 'Détecter les hotspots du GLB' }}
            </k-button>

            <span v-if="!result && !error && existingCount > 0"
                  style="font-size:0.8rem; color:var(--color-text-dimmed)">
              {{ existingCount }} annotation(s) en place
            </span>
          </div>

          <!-- success -->
          <k-box
            v-if="result"
            theme="positive"
            style="margin-top:0.625rem"
          >
            <template v-if="result.count > 0">
              <strong>{{ result.count }}</strong> hotspot(s) trouvé(s) —
              <strong>{{ result.added }}</strong> nouveau(x),
              <strong>{{ result.skipped }}</strong> conservé(s).
              Rechargement…
            </template>
            <template v-else>
              Aucun hotspot trouvé dans ce GLB. Vérifiez que les Empties
              sont nommés <code>hotspot_*</code> et que «&nbsp;Custom Properties&nbsp;»
              était coché lors de l'export.
            </template>
          </k-box>

          <!-- error -->
          <k-box
            v-if="error"
            theme="negative"
            style="margin-top:0.625rem"
          >
            {{ error }}
          </k-box>
        </k-field>
      `,
    },
  },
});
