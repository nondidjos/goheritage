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
                <k-icon type="file" class="k-upload-overwrite-list__icon" />
                <a :href="f.url" target="_blank" rel="noopener" class="k-upload-overwrite-list__name">
                  {{ f.filename }}
                </a>
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
        label:    String,
        help:     String,
        accept:   String,
        prefix:   String,
        template: { type: String, default: 'default' },
        pageId:   String,
        files:    { type: Array, default: () => [] },
      },

      data() {
        return {
          uploading:   false,
          message:     '',
          messageType: 'success',
        };
      },

      computed: {
        matchingFiles() {
          if (!this.files || !this.accept) return [];
          const exts = this.accept
            .split(',')
            .map(e => e.trim().replace(/^\./, '').toLowerCase());
          return this.files.filter(f => {
            const ext = f.filename.split('.').pop().toLowerCase();
            const extMatch = exts.includes(ext);
            if (!extMatch) return false;
            if (this.prefix) {
              return f.filename.toLowerCase().startsWith(this.prefix.toLowerCase());
            }
            return true;
          });
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
            const ext  = file.name.split('.').pop().toLowerCase();
            if (!exts.includes(ext)) {
              this.showMessage(`Extension non autorisée : .${ext}`, 'error');
              this.$refs.input.value = '';
              return;
            }
          }

          this.uploading = true;
          this.message   = '';

          const form = new FormData();
          form.append('file',     file);
          form.append('pageId',   this.pageId || '');
          form.append('template', this.template || 'default');

          try {
            const resp = await fetch('/api/goheritage/upload-overwrite', {
              method:  'POST',
              headers: { 'X-CSRF': panel.csrf },
              body:    form,
            });

            const json = await resp.json();

            if (!resp.ok) {
              this.showMessage('Erreur : ' + (json.error || resp.statusText), 'error');
            } else {
              const verb = json.status === 'replaced' ? 'Remplacé' : 'Ajouté';
              this.showMessage(`${verb} : ${json.filename}`, 'success');
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
            const resp = await fetch(
              `/api/pages/${this.apiPageId}/files/${encodeURIComponent(file.filename)}`,
              {
                method:  'DELETE',
                headers: { 'X-CSRF': panel.csrf },
              }
            );

            if (!resp.ok) {
              const json = await resp.json().catch(() => ({}));
              this.showMessage('Erreur : ' + (json.message || resp.statusText), 'error');
            } else {
              this.showMessage(`Supprimé : ${file.filename}`, 'success');
              this.$panel.view.reload();
            }
          } catch (err) {
            this.showMessage('Erreur : ' + err.message, 'error');
          }
        },

        showMessage(text, type) {
          this.message     = text;
          this.messageType = type;
          setTimeout(() => { this.message = ''; }, 3500);
        },
      },
    },
  },
});
