/**
 * upload-overwrite field
 *
 * Kirby panel field — overwrite-safe file upload.
 * Shows Kirby's native k-dropzone, then a file list with delete buttons below.
 */

panel.plugin('goheritage/model-converter', {
  fields: {
    'upload-overwrite': {
      template: `
        <k-field v-bind="$props" class="k-upload-overwrite-field">
          <k-button
            v-if="localFiles && localFiles.length"
            slot="options"
            icon="trash"
            text="Tout supprimer"
            variant="filled"
            size="sm"
            :disabled="uploading"
            @click="confirmDeleteAll"
          />
          <div class="k-upload-overwrite-wrap">

            <!-- Native Kirby dropzone -->
            <k-dropzone class="k-upload-overwrite-dropzone" :disabled="uploading" @drop="onDrop">
              <p class="k-upload-overwrite-dropzone-label">
                {{ resizing ? 'Redimensionnement…' : (uploading ? 'Envoi…' : (convertingFile ? 'Conversion GLB…' : (anyCompressing ? 'Compression…' : 'Déposer un fichier ici'))) }}
              </p>
              <k-button
                size="sm"
                icon="upload"
                text="Sélectionner"
                :disabled="uploading"
                @click.stop="$refs.fileInput.click()"
              />
            </k-dropzone>
            <input
              ref="fileInput"
              type="file"
              :accept="accept || undefined"
              style="display:none"
              @change="upload"
              :disabled="uploading"
            />

            <!-- Progress bar: upload = real XHR fill, compress = time-weighted fill -->
            <div v-if="uploading || anyCompressing || resizing" class="k-upload-overwrite-progress">
              <div
                class="k-upload-overwrite-progress__bar"
                :class="{ 'is-resizing': resizing }"
                :style="{ width: resizing ? '100%' : (uploading ? uploadProgress : compressProgress) + '%' }"
              ></div>
            </div>

            <!-- File list -->
            <ul v-if="matchingFiles.length" class="k-upload-overwrite-list">
              <li v-for="f in matchingFiles" :key="f.filename" class="k-upload-overwrite-list__item">
                <k-icon :type="f.filename.endsWith('.glb') ? 'box' : 'file'" class="k-upload-overwrite-list__icon" />
                <a :href="f.url" target="_blank" rel="noopener" class="k-upload-overwrite-list__name">
                  {{ f.filename }}
                </a>
                <span v-if="f.size" class="k-upload-overwrite-list__size" style="font-size: 0.8rem; color: var(--color-text-dimmed); margin-right: 0.5rem;">
                  {{ f.size }}
                </span>
                <div v-if="/\.obj$/i.test(f.filename)" style="display:flex; flex-direction:column; align-items:flex-start; margin-right:0.25rem; flex-shrink:0; gap:3px;">
                  <span style="font-family:var(--font-mono, monospace); text-transform:uppercase; font-size:0.55rem; color:var(--color-text-dimmed); letter-spacing:0.05em; line-height:1; font-weight:600; padding-left:2px;">Conversion</span>
                  <button
                    type="button"
                    title="Convertir en GLB (Draco)"
                    :disabled="!!convertingFile || globallyBusy"
                    @click="convertObj(f)"
                    style="display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border:1px solid var(--color-border); border-radius:4px; background:transparent; cursor:pointer; font-size:0.65rem; font-family:var(--font-mono, monospace); color:var(--color-text-dimmed); text-transform:uppercase; letter-spacing:0.04em;"
                    :style="{ opacity: (!!convertingFile || globallyBusy) ? 0.4 : 1 }"
                  >
                    <k-icon :type="convertingFile === f.filename ? 'loader' : 'angle-right'" />
                    GLB
                  </button>
                </div>
                <div v-if="/\.png$/i.test(f.filename)" style="display:flex; flex-direction:column; align-items:flex-start; margin-right:0.25rem; flex-shrink:0; gap:3px;">
                  <span style="font-family:var(--font-mono, monospace); text-transform:uppercase; font-size:0.55rem; color:var(--color-text-dimmed); letter-spacing:0.05em; line-height:1; font-weight:600; padding-left:2px;">Compression</span>
                  <div style="display:inline-flex; align-items:stretch; border:1px solid var(--color-border); border-radius:4px; overflow:hidden;">
                    <div style="position:relative; display:flex; align-items:center;">
                    <select
                      :value="selectedPresets[f.filename] !== undefined ? selectedPresets[f.filename] : 2"
                      @change="$set(selectedPresets, f.filename, parseInt($event.target.value))"
                      style="font-size:0.72rem; padding:2px 22px 2px 6px; border:none; background:transparent; color:var(--color-text); appearance:none; -webkit-appearance:none; cursor:pointer; outline:none; min-width:0;"
                    >
                      <option v-for="(p, i) in compressPresets(f)" :key="i" :value="p.idx">{{ p.label }} {{ p.size }}px</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; right:5px; pointer-events:none; color:var(--color-text-dimmed);"><path d="m6 9 6 6 6-6"/></svg>
                  </div>
                  <div style="width:1px; background:var(--color-border); flex-shrink:0;"></div>
                  <button
                    type="button"
                    title="Compresser en JPEG"
                    :disabled="f.compressing || globallyBusy"
                    @click="compress(f)"
                    style="padding:2px 7px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; color:var(--color-text-dimmed);"
                    :style="{ opacity: (f.compressing || globallyBusy) ? 0.4 : 1 }"
                  >
                    <svg v-if="f.compressing" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:gh-spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    <svg v-else-if="globallyBusy" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                  </button>
                  </div>
                </div>
                <button
                  type="button"
                  class="k-upload-overwrite-list__delete"
                  title="Supprimer"
                  @click="confirmDelete(f)"
                >
                  <k-icon type="trash" />
                </button>
              </li>
            </ul>

          </div>
        </k-field>
      `,

      props: {
        label: String,
        help: String,
        accept: String,
        prefix: String,
        template: { type: String, default: 'default' },
        pageId: String,
        fieldName: String,
        files: { type: Array, default: () => [] },
      },

      data() {
        return {
          uploading: false,
          resizing: false,
          uploadProgress: 0,     // 0-100, driven by XHR upload.onprogress
          compressProgress: 0,   // 0-100, simulated over expected duration
          _compressTimer: null,
          convertingFile: '',    // filename currently being converted to GLB, or ''
          globallyBusy: false, // true while ANY compress or convert job is running (cross-instance)
          localFiles: [],
          selectedPresets: {},
          presets: [
            // bpp calibrated from real outputs on 8192² architectural texture:
            //   q=88 → 24 MB, q=72 → 16 MB (1.9 bpp), q=82@4096² → 5.5 MB (2.75 bpp)
            // Pure-Sharp pipeline: ~25 s / ~15 s / ~8 s
            { label: 'Haute',    size: 8192, quality: 72, bpp: 1.9  }, // ~16 Mo
            { label: 'Standard', size: 4096, quality: 82, bpp: 2.75 }, // ~5.5 Mo
            { label: 'Légère',   size: 1024, quality: 75, bpp: 2.0  }, // ~300 Ko
          ],
        };
      },

      watch: {
        files: {
          immediate: true,
          handler(val) {
            this.localFiles = Array.isArray(val) ? [...val] : [];
          },
        },
      },

      mounted() {
        // Keep globallyBusy in sync across all upload-overwrite instances
        // on the same page (exterior + interior fields, compress + convert).
        this._onGlobalBusyStart = () => { this.globallyBusy = true; };
        this._onGlobalBusyEnd   = () => { this.globallyBusy = false; };
        window.addEventListener('goheritage:busy-start', this._onGlobalBusyStart);
        window.addEventListener('goheritage:busy-end',   this._onGlobalBusyEnd);
        // Inject Lucide spinner keyframe once
        if (!document.getElementById('gh-spin-style')) {
          const s = document.createElement('style');
          s.id = 'gh-spin-style';
          s.textContent = '@keyframes gh-spin { to { transform: rotate(360deg); } }';
          document.head.appendChild(s);
        }
      },

      beforeDestroy() {
        window.removeEventListener('goheritage:busy-start', this._onGlobalBusyStart);
        window.removeEventListener('goheritage:busy-end',   this._onGlobalBusyEnd);
      },

      computed: {
        matchingFiles() {
          return this.localFiles;
        },

        anyCompressing() {
          return !!this.convertingFile || this.localFiles.some(f => f.compressing);
        },

        apiPageId() {
          return (this.pageId || '').replace(/\//g, '+');
        },
      },

      methods: {
        onDrop(files) {
          const file = Array.isArray(files) ? files[0] : (files && files[0]);
          if (file) this.handleFile(file);
        },

        upload(event) {
          const file = event.target.files[0];
          if (file) this.handleFile(file);
        },

        async handleFile(file) {
          if (this.uploading) return;

          if (this.accept) {
            const exts = this.accept.split(',').map(e => e.trim().replace(/^\./, '').toLowerCase());
            const ext = file.name.split('.').pop().toLowerCase();
            if (!exts.includes(ext)) {
              this.$panel.notification.error('Extension non autorisée : .' + ext);
              if (this.$refs.fileInput) this.$refs.fileInput.value = '';
              return;
            }
          }

          this.uploading = true;
          this.uploadProgress = 0;

          let uploadFile = file;
          const ext = file.name.split('.').pop().toLowerCase();
          if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) {
            try {
              this.resizing = true;
              // Give the UI a frame to update the 'resizing' state before blocking the main thread
              await new Promise(r => setTimeout(r, 50)); 
              uploadFile = await this.resizeImageIfNeeded(file, 8192);
            } catch (err) {
              console.warn('Client-side resize failed, falling back to original:', err);
            } finally {
              this.resizing = false;
            }
          }

          const form = new FormData();
          form.append('file', uploadFile);
          form.append('pageId', this.pageId || '');
          form.append('template', this.template || 'default');
          form.append('fieldName', this.fieldName || '');

          try {
            const xhr = await new Promise((resolve, reject) => {
              const x = new XMLHttpRequest();
              x.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                  this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                }
              };
              x.onload  = () => resolve(x);
              x.onerror = () => reject(new Error('Erreur réseau'));
              x.open('POST', '/api/goheritage/upload-overwrite');
              x.setRequestHeader('X-CSRF', panel.csrf);
              x.send(form);
            });

            if (xhr.status < 200 || xhr.status >= 300) {
              let errorMsg = xhr.statusText;
              try { const errJson = JSON.parse(xhr.responseText); if (errJson.error) errorMsg = errJson.error; } catch(e) {}
              this.$panel.notification.error(errorMsg);
            } else {
              const json = JSON.parse(xhr.responseText);
              const verb = json.status === 'replaced' ? 'Remplacé' : 'Ajouté';
              this.$panel.notification.success(verb + ' : ' + json.filename);
              const entry = { filename: json.filename, url: json.url, id: json.id };
              const idx = this.localFiles.findIndex(f => f.filename === json.filename);
              if (idx >= 0) this.localFiles.splice(idx, 1, entry);
              else this.localFiles.push(entry);
              this.$panel.view.reload();
            }
          } catch (err) {
            this.$panel.notification.error(err.message);
          } finally {
            this.uploading = false;
            this.resizing = false;
            this.uploadProgress = 0;
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
          }
        },

        resizeImageIfNeeded(file, maxSize) {
          return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
              URL.revokeObjectURL(url);
              let w = img.width, h = img.height;
              if (w <= maxSize && h <= maxSize) return resolve(file);

              const scale = Math.min(maxSize / w, maxSize / h);
              w = Math.round(w * scale);
              h = Math.round(h * scale);

              const canvas = document.createElement('canvas');
              canvas.width = w;
              canvas.height = h;
              const ctx = canvas.getContext('2d');
              try {
                // Ensure alpha preservation for PNGs
                ctx.clearRect(0, 0, w, h);
                ctx.drawImage(img, 0, 0, w, h);
                // We use file.type to preserve PNG (alpha needed for UV dilation)
                canvas.toBlob((blob) => {
                  if (blob) {
                    resolve(new File([blob], file.name, { type: file.type }));
                  } else {
                    resolve(file);
                  }
                }, file.type);
              } catch (err) {
                resolve(file);
              }
            };
            img.onerror = () => {
              URL.revokeObjectURL(url);
              resolve(file);
            };
            img.src = url;
          });
        },

        confirmDelete(file) {
          this.$panel.dialog.open({
            component: 'k-remove-dialog',
            props: { text: `Supprimer "${file.filename}" ?` },
            on: {
              submit: () => {
                this.$panel.dialog.close();
                this.deleteFile(file);
              },
            },
          });
        },

        confirmDeleteAll() {
          this.$panel.dialog.open({
            component: 'k-remove-dialog',
            props: { text: `Supprimer tous les fichiers de ce champ ?` },
            on: {
              submit: () => {
                this.$panel.dialog.close();
                this.deleteAll();
              },
            },
          });
        },

        async deleteAll() {
          this.uploading = true;
          try {
            const toDelete = [...this.localFiles];
            for (const f of toDelete) {
              await this._rawDelete(f.filename);
              this.localFiles = this.localFiles.filter(x => x.filename !== f.filename);
            }
            this.$panel.notification.success('Tous les fichiers supprimés');
            this.$panel.view.reload();
          } catch (err) {
            this.$panel.notification.error(err.message);
          } finally {
            this.uploading = false;
          }
        },

        async _rawDelete(filename) {
          const params = new URLSearchParams({ pageId: this.pageId || '', filename });
          const resp = await fetch(`/api/goheritage/delete-file?${params}`, {
            method: 'DELETE',
            headers: { 'X-CSRF': panel.csrf },
          });
          if (!resp.ok) {
            const body = await resp.text().catch(() => '');
            let errorMsg = `HTTP ${resp.status}`;
            try { const errJson = JSON.parse(body); if (errJson.error) errorMsg = errJson.error; } catch(e) {}
            throw new Error(errorMsg);
          }
        },

        async deleteFile(file) {
          try {
            await this._rawDelete(file.filename);
            this.$panel.notification.success('Fichier supprimé');
            this.$panel.view.reload();
          } catch (err) {
            this.$panel.notification.error(err.message);
          }
        },

        _startCompressProgress(expectedMs) {
          this.compressProgress = 0;
          if (this._compressTimer) clearInterval(this._compressTimer);
          const start = Date.now();
          this._compressTimer = setInterval(() => {
            this.compressProgress = Math.min(90, Math.round(((Date.now() - start) / expectedMs) * 90));
          }, 400);
        },

        _stopCompressProgress(success) {
          if (this._compressTimer) { clearInterval(this._compressTimer); this._compressTimer = null; }
          this.compressProgress = success ? 100 : 0;
          if (success) setTimeout(() => { this.compressProgress = 0; }, 400);
        },

        async compress(file) {
          const idx = this.localFiles.findIndex(f => f.filename === file.filename);
          if (idx < 0) return;

          const presetIdx = this.presetFor(file);
          const preset    = this.presets[presetIdx];
          if (!preset) return;

          // Block concurrent jobs — the server only has 512 MB RAM.
          if (this.globallyBusy) {
            this.$panel.notification.error('Un traitement est déjà en cours, veuillez patienter.');
            return;
          }

          // Notify all field instances on this page that a compress job is starting.
          window.dispatchEvent(new CustomEvent('goheritage:busy-start'));
          // Pure-Sharp pipeline measured times: 8192 ~31 s · 4096 ~11 s · 2048 ~7 s
          this._startCompressProgress(preset.size >= 8192 ? 35000 : preset.size >= 4096 ? 13000 : 9000);
          this.$set(this.localFiles, idx, { ...file, compressing: true });

          try {
            const params = new URLSearchParams({
              pageId: this.pageId || '',
              filename: file.filename,
              size: preset.size,
              quality: preset.quality,
            });
            const resp = await fetch(`/api/goheritage/compress-file?${params}`, {
              method: 'POST',
              headers: { 'X-CSRF': panel.csrf },
            });
            if (!resp.ok) {
              const body = await resp.text().catch(() => '');
              let errorMsg = resp.statusText;
              try { const errJson = JSON.parse(body); if (errJson.error) errorMsg = errJson.error; } catch(e) {}
              this._stopCompressProgress(false);
              this.$panel.notification.error(errorMsg);
              this.$set(this.localFiles, idx, { ...file, compressing: false });
            } else {
              let json = await resp.json().catch(() => null);
              this._stopCompressProgress(true);
              this.$panel.notification.success('Texture compressée');
              if (json && json.filename) {
                this.$set(this.localFiles, idx, {
                  ...file,
                  filename: json.filename,
                  size: json.size,
                  url: json.url,
                  compressing: false
                });
              } else {
                this.$set(this.localFiles, idx, { ...file, compressing: false });
              }
              this.$panel.view.reload();
            }
          } catch (err) {
            this._stopCompressProgress(false);
            this.$panel.notification.error(err.message);
            this.$set(this.localFiles, idx, { ...file, compressing: false });
          } finally {
            window.dispatchEvent(new CustomEvent('goheritage:busy-end'));
          }
        },

        presetFor(f) {
          const v = this.selectedPresets[f.filename];
          return v !== undefined ? parseInt(v) : 1; // default: Standard
        },

        compressPresets(f) {
          return this.presets.map((p, i) => ({ ...p, idx: i }));
        },

        estimateSize(f, preset) {
          let w = preset.size, h = preset.size;
          if (f.width && f.height) {
            const scale = Math.min(preset.size / f.width, preset.size / f.height, 1);
            w = Math.round(f.width * scale);
            h = Math.round(f.height * scale);
          }
          const bytes = w * h * preset.bpp / 8;
          return bytes >= 1048576
            ? (bytes / 1048576).toFixed(1) + ' Mo'
            : Math.round(bytes / 1024) + ' Ko';
        },

        async convertObj(file) {
          if (this.convertingFile || this.globallyBusy) return;
          window.dispatchEvent(new CustomEvent('goheritage:busy-start'));
          this.convertingFile = file.filename;
          // obj2gltf + draco typically takes 30–90 s depending on model size
          this._startCompressProgress(70000);
          try {
            const params = new URLSearchParams({
              pageId:   this.pageId || '',
              filename: file.filename,
            });
            const resp = await fetch(`/api/goheritage/convert-obj?${params}`, {
              method:  'POST',
              headers: { 'X-CSRF': panel.csrf },
            });
            if (!resp.ok) {
              const body = await resp.text().catch(() => '');
              let errorMsg = resp.statusText;
              try { const errJson = JSON.parse(body); if (errJson.error) errorMsg = errJson.error; } catch(e) {}
              this._stopCompressProgress(false);
              this.$panel.notification.error(errorMsg);
            } else {
              this._stopCompressProgress(true);
              this.$panel.notification.success('Converti en GLB');
              this.$panel.view.reload();
            }
          } catch (err) {
            this._stopCompressProgress(false);
            this.$panel.notification.error(err.message);
          } finally {
            this.convertingFile = '';
            window.dispatchEvent(new CustomEvent('goheritage:busy-end'));
          }
        },

        _onGlobalBusyStart() { this.globallyBusy = true; },
        _onGlobalBusyEnd()   { this.globallyBusy = false; },
      },
    },

    'page-files-list': {
      // Don't auto-apply inherited attrs (incl. the blueprint's
      // `label: false`) to the root <k-field> — otherwise k-field
      // renders the literal string "false" as its label.
      inheritAttrs: false,
      props: {
        pageId: { type: String, default: '' },
        rows: { type: Array, default: () => [] },
      },
      data() {
        return {
          localFiles: [],
          busyAll: false,
          sortBy: 'modified',  // default: newest first
          sortDir: 'desc',
          collapsed: {},
        };
      },
      watch: {
        rows: { immediate: true, handler(v) { this.localFiles = Array.isArray(v) ? [...v] : []; } },
      },
      computed: {
        groups() {
          var order = [
            ['model-source',   'Modèle 3D — source'],
            ['model-web',      'Modèle 3D — web'],
            ['texture-source', 'Textures — source'],
            ['texture-web',    'Textures — web'],
            ['hotspot',        "Points d'intérêt"],
            ['cloud',          'Nuage de points'],
            ['photo',          'Photos'],
            ['doc',            'Documents'],
            ['data',           'Données'],
            ['video',          'Vidéos'],
            ['archive',        'Archives'],
            ['other',          'Autres'],
          ];
          var byCat = {};
          this.localFiles.forEach(function (f) {
            var c = f.category || 'other';
            (byCat[c] || (byCat[c] = [])).push(f);
          });
          var sortBy  = this.sortBy;
          var sortDir = this.sortDir;
          function cmp(a, b) {
            var va, vb;
            if (sortBy === 'filename') {
              va = (a.filename || '').toLowerCase();
              vb = (b.filename || '').toLowerCase();
            } else if (sortBy === 'extension') {
              va = (a.extension || '').toLowerCase();
              vb = (b.extension || '').toLowerCase();
            } else if (sortBy === 'size') {
              va = a.sizeRaw || 0;
              vb = b.sizeRaw || 0;
            } else {
              // modified — sort by ISO string
              va = a.modifiedIso || '';
              vb = b.modifiedIso || '';
            }
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ?  1 : -1;
            return 0;
          }
          var self = this;
          return order
            .filter(function (o) { return byCat[o[0]] && byCat[o[0]].length; })
            .map(function (o) {
              var kind = self.fileKind(o[0]);
              return {
                key:   o[0],
                label: o[1],
                icon:  kind.icon,
                cat:   kind.cat,
                files: byCat[o[0]].slice().sort(cmp),
              };
            });
        },
      },
      methods: {
        async _delete(filename) {
          const params = new URLSearchParams({ pageId: this.pageId, filename });
          const resp = await fetch(`/api/goheritage/delete-file?${params}`, {
            method: 'DELETE',
            headers: { 'X-CSRF': panel.csrf },
          });
          if (!resp.ok) {
            const body = await resp.text().catch(() => '');
            let errorMsg = `HTTP ${resp.status}`;
            try { const errJson = JSON.parse(body); if (errJson.error) errorMsg = errJson.error; } catch(e) {}
            throw new Error(errorMsg);
          }
        },
        confirmDelete(file) {
          this.$panel.dialog.open({
            component: 'k-remove-dialog',
            props: { text: `Supprimer "${file.filename}" ?` },
            on: {
              submit: () => {
                this.$panel.dialog.close();
                this._delete(file.filename)
                  .then(() => {
                    this.localFiles = this.localFiles.filter(f => f.filename !== file.filename);
                    this.$panel.view.reload();
                  })
                  .catch(err => this.$panel.notification.error(err.message));
              },
            },
          });
        },
        confirmDeleteAll() {
          this.$panel.dialog.open({
            component: 'k-remove-dialog',
            props: { text: 'Supprimer tous les fichiers de cette page ?' },
            on: { submit: () => { this.$panel.dialog.close(); this.deleteAll(); } },
          });
        },
        async deleteAll() {
          this.busyAll = true;
          try {
            for (const f of [...this.localFiles]) {
              await this._delete(f.filename);
              this.localFiles = this.localFiles.filter(x => x.filename !== f.filename);
            }
            this.$panel.view.reload();
          } catch (err) {
            this.$panel.notification.error(err.message);
          } finally {
            this.busyAll = false;
          }
        },
        toggleGroup(key) {
          this.collapsed[key] = !this.collapsed[key];
        },
        setSort(key) {
          if (this.sortBy === key) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            this.sortBy  = key;
            this.sortDir = key === 'modified' ? 'desc' : 'asc';
          }
        },
        // aria-sort value for a column header so screen readers announce the
        // current sort state ("ascending"/"descending"/"none").
        ariaSort(key) {
          if (this.sortBy !== key) return 'none';
          return this.sortDir === 'asc' ? 'ascending' : 'descending';
        },
        // Icon + colour bucket for a row, derived from the server-supplied
        // category (the shared fileCategory() method) — NOT re-parsed from the
        // extension here. That keeps one classification source, and means the
        // icon honours the texture-vs-photo distinction (a plain extension
        // check can't, since both are images).
        fileKind(cat) {
          switch (cat) {
            case 'model-source':
            case 'model-web':      return { icon: 'box',           cat: 'model'   };
            case 'texture-source':
            case 'texture-web':    return { icon: 'image',         cat: 'texture' };
            case 'cloud':          return { icon: 'gh-pointcloud', cat: 'cloud'   };
            case 'photo':          return { icon: 'image',         cat: 'image'   };
            case 'doc':            return { icon: 'file-document', cat: 'doc'     };
            case 'hotspot':
            case 'data':           return { icon: 'code',          cat: 'data'    };
            case 'archive':        return { icon: 'archive',       cat: 'archive' };
            case 'video':          return { icon: 'video',         cat: 'video'   };
            default:               return { icon: 'file',          cat: 'other'   };
          }
        },
      },
      template: `
        <k-field class="k-page-files-list-field">
          <k-button
            v-if="localFiles && localFiles.length"
            slot="options"
            icon="trash"
            text="Tout supprimer"
            variant="filled"
            size="sm"
            :disabled="busyAll"
            @click="confirmDeleteAll"
          />
          <div v-if="localFiles.length" class="k-page-files-list-wrap">
            <table class="k-page-files-list">
              <thead>
                <tr>
                  <th class="k-page-files-list__th--name k-page-files-list__th--sort" role="button" tabindex="0" :aria-sort="ariaSort('filename')" @click="setSort('filename')" @keydown.enter.prevent="setSort('filename')" @keydown.space.prevent="setSort('filename')" :data-active="sortBy === 'filename'" :data-dir="sortDir">
                    Nom <span class="k-page-files-list__sort-icon" v-if="sortBy === 'filename'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                  </th>
                  <th class="k-page-files-list__th--ext k-page-files-list__th--sort" role="button" tabindex="0" :aria-sort="ariaSort('extension')" @click="setSort('extension')" @keydown.enter.prevent="setSort('extension')" @keydown.space.prevent="setSort('extension')" :data-active="sortBy === 'extension'" :data-dir="sortDir">
                    Type <span class="k-page-files-list__sort-icon" v-if="sortBy === 'extension'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                  </th>
                  <th class="k-page-files-list__th--size k-page-files-list__th--sort" role="button" tabindex="0" :aria-sort="ariaSort('size')" @click="setSort('size')" @keydown.enter.prevent="setSort('size')" @keydown.space.prevent="setSort('size')" :data-active="sortBy === 'size'" :data-dir="sortDir">
                    Taille <span class="k-page-files-list__sort-icon" v-if="sortBy === 'size'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                  </th>
                  <th class="k-page-files-list__th--date k-page-files-list__th--sort" role="button" tabindex="0" :aria-sort="ariaSort('modified')" @click="setSort('modified')" @keydown.enter.prevent="setSort('modified')" @keydown.space.prevent="setSort('modified')" :data-active="sortBy === 'modified'" :data-dir="sortDir">
                    Modifié <span class="k-page-files-list__sort-icon" v-if="sortBy === 'modified'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                  </th>
                  <th class="k-page-files-list__th--actions"></th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in groups" :key="g.key">
                  <tr class="k-files-group__head-row" :class="{ 'is-collapsed': collapsed[g.key] }" :data-cat="g.cat" @click="toggleGroup(g.key)">
                    <td class="k-files-group__head-cell" colspan="5">
                      <div class="k-files-group__head-inner">
                        <k-icon :type="g.icon" class="k-files-group__icon" />
                        <span class="k-files-group__label">{{ g.label }}</span>
                        <span class="k-files-group__count">{{ g.files.length }}</span>
                        <k-icon type="angle-down" class="k-files-group__chevron" />
                      </div>
                    </td>
                  </tr>
                  <tr v-for="f in g.files" v-show="!collapsed[g.key]" :key="f.filename">
                    <td class="k-page-files-list__td--name">
                      <span class="k-page-files-list__name-inner" :data-cat="fileKind(f.category).cat">
                        <k-icon :type="fileKind(f.category).icon" />
                        <a :href="f.url" target="_blank" rel="noopener">{{ f.filename }}</a>
                      </span>
                    </td>
                    <td class="k-page-files-list__td--ext">
                      <span class="k-page-files-list__ext-badge" :data-cat="fileKind(f.category).cat">{{ f.extension }}</span>
                    </td>
                    <td class="k-page-files-list__td--size">{{ f.size }}</td>
                    <td class="k-page-files-list__td--date">{{ f.modified }}</td>
                    <td class="k-page-files-list__td--actions">
                      <a :href="f.url" :download="f.filename" class="k-page-files-list__action" :title="'Télécharger ' + f.filename" :aria-label="'Télécharger ' + f.filename">
                        <k-icon type="download" />
                      </a>
                      <button type="button" class="k-page-files-list__action k-page-files-list__delete" @click="confirmDelete(f)" :title="'Supprimer ' + f.filename" :aria-label="'Supprimer ' + f.filename">
                        <k-icon type="trash" />
                      </button>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <k-empty v-else icon="file">Aucun fichier sur cette page.</k-empty>
        </k-field>
      `,
    },

    'location-search': {
      props: {
        label: String,
        pageId: String,
      },
      data() {
        return {
          query: '',
          results: [],
          searching: false,
          saving: false,
          debounceTimer: null,
        };
      },
      methods: {
        onInput() {
          clearTimeout(this.debounceTimer);
          this.results = [];
          if (!this.query.trim()) return;
          this.debounceTimer = setTimeout(() => this.search(), 400);
        },
        async search() {
          this.searching = true;
          try {
            const resp = await fetch('/api/goheritage/geocode?q=' + encodeURIComponent(this.query), {
              headers: { 'X-CSRF': panel.csrf },
            });
            if (!resp.ok) { this.results = []; return; }
            const json = await resp.json();
            this.results = (json.features || []).map(f => ({
              label: f.place_name || f.text || '',
              lat:   f.geometry.coordinates[1],
              lng:   f.geometry.coordinates[0],
            }));
          } catch (e) {
            this.results = [];
          } finally {
            this.searching = false;
          }
        },
        async pick(result) {
          this.query   = result.label;
          this.results = [];
          this.saving  = true;
          const id = (this.pageId || '').replace(/\//g, '+');
          try {
            await this.$panel.api.patch('pages/' + id, {
              lat: parseFloat(result.lat.toFixed(6)),
              lng: parseFloat(result.lng.toFixed(6)),
            });
            this.$panel.notification.success('Coordonnées enregistrées');
            this.$panel.view.reload();
          } catch (e) {
            this.$panel.notification.error(e.message || 'Erreur lors de l\'enregistrement');
          } finally {
            this.saving = false;
          }
        },
      },
      template: `
        <k-field v-bind="$props" style="position:relative;">
          <div style="display:flex; gap:0.4rem; align-items:center;">
            <input
              type="text"
              v-model="query"
              @input="onInput"
              :disabled="saving"
              placeholder="Rechercher un lieu…"
              style="flex:1; padding:0.35rem 0.6rem; border:1px solid var(--color-border); border-radius:var(--rounded); background:var(--color-background); color:var(--color-text); font-size:var(--text-sm); outline:none;"
            />
            <k-icon v-if="searching || saving" type="loader" style="opacity:0.5;" />
          </div>
          <ul v-if="results.length" style="position:absolute; z-index:100; left:0; right:0; margin:2px 0 0; padding:0; list-style:none; background:var(--color-background); border:1px solid var(--color-border); border-radius:var(--rounded); box-shadow:0 4px 12px rgba(0,0,0,0.15); overflow:hidden;">
            <li
              v-for="(r, i) in results"
              :key="i"
              @click="pick(r)"
              style="padding:0.4rem 0.65rem; font-size:var(--text-sm); cursor:pointer; border-bottom:1px solid var(--color-border);"
              @mouseenter="$event.target.style.background='var(--color-border)'"
              @mouseleave="$event.target.style.background=''"
            >
              {{ r.label }}
              <span style="float:right; font-size:0.65rem; color:var(--color-text-dimmed); font-family:var(--font-mono);">{{ r.lat.toFixed(6) }}, {{ r.lng.toFixed(6) }}</span>
            </li>
          </ul>
        </k-field>
      `,
    },

    'accordion-trigger': {
      props: {
        label: String,
        target: String,
        defaultOpen: Boolean,
        // Optional: name of a toggle field whose value drives the initial state.
        linkedToggle: String,
        // true  → this section opens when the toggle is ON (interior)
        // false → this section opens when the toggle is OFF (exterior)
        openOnTrue: { type: Boolean, default: true },
      },
      data() {
        return { isOpen: this.defaultOpen };
      },
      mounted() {
        if (this.linkedToggle) {
          try {
            // Kirby 3 Panel Vuex store — current content keyed by field name
            const vals = this.$store.state.content.current;
            if (vals && this.linkedToggle in vals) {
              const toggleOn = vals[this.linkedToggle] === true || vals[this.linkedToggle] === 'true';
              this.isOpen = this.openOnTrue ? toggleOn : !toggleOn;
            }
          } catch (_) {}
        }
        setTimeout(() => this.updateVisibility(), 50);
      },
      methods: {
        toggle() {
          this.isOpen = !this.isOpen;
          this.updateVisibility();
        },
        updateVisibility() {
          const col = this.$el.closest('.k-column');
          if (!col) return;
          let next = col.nextElementSibling;
          while (next) {
            if (next.querySelector('.k-accordion-trigger-field')) break;
            next.style.display = this.isOpen ? '' : 'none';
            next = next.nextElementSibling;
          }
        },
      },
      template: `
        <div class="k-accordion-trigger-field" @click="toggle" style="cursor:pointer; user-select:none; display:flex; align-items:center; gap:0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border); margin-bottom: 0.5rem; margin-top: 1rem;" title="Déplier/Replier la section">
          <svg v-if="isOpen" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--color-text-dimmed);flex-shrink:0;"><path d="m6 9 6 6 6-6"/></svg><svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--color-text-dimmed);flex-shrink:0;"><path d="m9 18 6-6-6-6"/></svg>
          <h2 style="font-size: var(--text-base, 1rem); font-weight: 600;">{{ label }}</h2>
        </div>
      `,
    },
  },

});
