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
          <div class="k-upload-overwrite-wrap">

            <!-- Native Kirby dropzone -->
            <k-dropzone class="k-upload-overwrite-dropzone" :disabled="uploading" @drop="onDrop">
              <p class="k-upload-overwrite-dropzone-label">
                {{ uploading ? 'Téléversement…' : 'Déposer un fichier ici' }}
              </p>
              <k-button
                size="sm"
                icon="upload"
                text="Sélectionner"
                :disabled="uploading"
                @click.stop="$refs.input.click()"
              />
              <input
                ref="input"
                type="file"
                :accept="accept || undefined"
                style="display:none"
                @change="upload"
                :disabled="uploading"
              />
            </k-dropzone>

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
                <button
                  v-if="f.filename.endsWith('.png')"
                  type="button"
                  class="k-upload-overwrite-list__delete"
                  title="Compresser en JPEG"
                  :disabled="f.compressing"
                  @click="compress(f)"
                  style="margin-right:0.25rem;"
                >
                  <k-icon :type="f.compressing ? 'loader' : 'image'" />
                </button>
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

            <!-- Status notice -->
            <p v-if="message" :class="['k-upload-overwrite-notice', '--' + messageType]">
              {{ message }}
            </p>

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
          message: '',
          messageType: 'success',
          localFiles: [],
        };
      },

      watch: {
        files: {
          immediate: true,
          handler(val) { this.localFiles = Array.isArray(val) ? [...val] : []; },
        },
      },

      computed: {
        matchingFiles() {
          return this.localFiles;
        },

        // Kirby API encodes nested page IDs with "+" instead of "/"
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
              this.showMessage(`Extension non autorisée : .${ext}`, 'error');
              this.$refs.input.value = '';
              return;
            }
          }

          this.uploading = true;
          this.message = '';

          const form = new FormData();
          form.append('file', file);
          form.append('pageId', this.pageId || '');
          form.append('template', this.template || 'default');
          form.append('fieldName', this.fieldName || '');

          try {
            const resp = await fetch('/api/goheritage/upload-overwrite', {
              method: 'POST',
              headers: { 'X-CSRF': panel.csrf },
              body: form,
            });

            const json = await resp.json();

            if (!resp.ok) {
              this.showMessage('Erreur : ' + (json.error || resp.statusText), 'error');
            } else {
              const verb = json.status === 'replaced' ? 'Remplacé' : 'Ajouté';
              this.showMessage(`${verb} : ${json.filename}`, 'success');
              // Update local list immediately so the file shows without waiting for reload
              const entry = { filename: json.filename, url: json.url, id: json.id };
              const idx = this.localFiles.findIndex(f => f.filename === json.filename);
              if (idx >= 0) this.localFiles.splice(idx, 1, entry);
              else this.localFiles.push(entry);
              this.$panel.view.reload();
            }
          } catch (err) {
            this.showMessage('Erreur : ' + err.message, 'error');
          } finally {
            this.uploading = false;
            this.$refs.input.value = '';
          }
        },

        confirmDelete(file) {
          this.$panel.dialog.open({
            component: 'k-remove-dialog',
            props: {
              text: `Supprimer "${file.filename}" ?`,
            },
            on: {
              submit: () => {
                this.$panel.dialog.close();
                this.deleteFile(file);
              },
            },
          });
        },

        async deleteFile(file) {
          try {
            const params = new URLSearchParams({
              pageId: this.pageId || '',
              filename: file.filename,
            });
            const resp = await fetch(
              `/api/goheritage/delete-file?${params}`,
              {
                method: 'DELETE',
                headers: { 'X-CSRF': panel.csrf },
              }
            );

            const json = await resp.json().catch(() => ({}));
            if (!resp.ok) {
              this.showMessage('Erreur : ' + (json.error || resp.statusText), 'error');
            } else {
              this.showMessage(`Supprimé : ${file.filename}`, 'success');
              this.$panel.view.reload();
            }
          } catch (err) {
            this.showMessage('Erreur : ' + err.message, 'error');
          }
        },

        async compress(file) {
          const idx = this.localFiles.findIndex(f => f.filename === file.filename);
          if (idx < 0) return;
          this.$set(this.localFiles, idx, { ...file, compressing: true });
          try {
            const params = new URLSearchParams({ pageId: this.pageId || '', filename: file.filename });
            const resp = await fetch(`/api/goheritage/compress-file?${params}`, {
              method: 'POST',
              headers: { 'X-CSRF': panel.csrf },
            });
            const json = await resp.json().catch(() => ({}));
            if (resp.ok) {
              this.$panel.view.reload();
            } else {
              this.showMessage('Erreur : ' + (json.error || resp.statusText), 'error');
              this.$set(this.localFiles, idx, file);
            }
          } catch (err) {
            this.showMessage('Erreur : ' + err.message, 'error');
            this.$set(this.localFiles, idx, file);
          }
        },

        showMessage(text, type) {
          this.message = text;
          this.messageType = type;
          setTimeout(() => { this.message = ''; }, 3500);
        },
      },
    },

    'page-files-list': {
      props: {
        pageId: { type: String, default: '' },
        rows:   { type: Array,  default: () => [] },
      },
      data() {
        return { localFiles: [], busyAll: false };
      },
      watch: {
        rows: { immediate: true, handler(v) { this.localFiles = Array.isArray(v) ? [...v] : []; } },
      },
      methods: {
        async _delete(filename) {
          const params = new URLSearchParams({ pageId: this.pageId, filename });
          const resp = await fetch(`/api/goheritage/delete-file?${params}`, {
            method: 'DELETE',
            headers: { 'X-CSRF': panel.csrf },
          });
          if (!resp.ok) {
            const json = await resp.json().catch(() => ({}));
            throw new Error(json.error || `HTTP ${resp.status}`);
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
      },
      template: `
        <div class="k-field">
          <k-field-header label="Fichiers">
            <template #options>
              <k-button-group v-if="localFiles.length">
                <k-button icon="files" size="sm" :disabled="busyAll" @click="confirmDeleteAll">
                  {{ busyAll ? 'Suppression…' : 'Tout supprimer' }}
                </k-button>
              </k-button-group>
            </template>
          </k-field-header>
          <ul v-if="localFiles.length" class="k-upload-overwrite-list">
            <li v-for="f in localFiles" :key="f.filename" class="k-upload-overwrite-list__item">
              <k-icon type="file" class="k-upload-overwrite-list__icon" />
              <a :href="f.url" target="_blank" rel="noopener" class="k-upload-overwrite-list__name">{{ f.filename }}</a>
              <span style="font-size:var(--text-sm); color:var(--color-text-dimmed); flex-shrink:0;">{{ f.size }}</span>
              <button type="button" class="k-upload-overwrite-list__delete" @click="confirmDelete(f)">
                <k-icon type="trash" />
              </button>
            </li>
          </ul>
          <k-empty v-else icon="file">Aucun fichier sur cette page.</k-empty>
        </div>
      `,
    },

    'accordion-trigger': {
      props: {
        label: String,
        target: String,
        defaultOpen: Boolean,
      },
      data() {
        return {
          isOpen: this.defaultOpen
        };
      },
      mounted() {
        // slight delay to ensure siblings are mounted by Vue before manipulating DOM
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
            // STOP if the next column contains another accordion trigger wrapper
            if (next.querySelector('.k-accordion-trigger-field')) break;
            next.style.display = this.isOpen ? '' : 'none';
            next = next.nextElementSibling;
          }
        }
      },
      template: `
        <div class="k-accordion-trigger-field" @click="toggle" style="cursor:pointer; user-select:none; display:flex; align-items:center; gap:0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border); margin-bottom: 0.5rem; margin-top: 1rem;" title="Déplier/Replier la section">
          <k-icon :type="isOpen ? 'angle-down' : 'angle-right'" style="color: var(--color-text-dimmed); margin-top: 2px;" />
          <h2 style="font-size: var(--text-base, 1rem); font-weight: 600;">{{ label }}</h2>
        </div>
      `
    },
  },

});
