/**
 * upload-overwrite field
 *
 * A panel field that lets editors upload a file and silently replace it
 * if a file with the same name already exists on the page.
 * Uses the custom /api/goheritage/upload-overwrite route.
 */

panel.plugin('goheritage/model-converter', {
  fields: {
    'upload-overwrite': {
      template: `
        <div class="upload-overwrite-field">
          <k-field v-bind="$props" :input="false">
            <template #default>

              <!-- Current file list -->
              <ul v-if="currentFiles.length" class="upload-overwrite-field__files">
                <li
                  v-for="f in currentFiles"
                  :key="f.filename"
                  class="upload-overwrite-field__file"
                >
                  <k-icon type="file" />
                  <a :href="f.url" target="_blank" rel="noopener">{{ f.filename }}</a>
                </li>
              </ul>

              <!-- Upload input -->
              <label class="upload-overwrite-field__label">
                <k-button icon="upload" theme="positive" size="sm" @click.prevent="$refs.input.click()">
                  {{ uploading ? 'Téléversement…' : (accept ? 'Déposer un fichier ' + accept : 'Déposer un fichier') }}
                </k-button>
                <input
                  ref="input"
                  type="file"
                  :accept="accept || undefined"
                  style="display:none"
                  @change="upload"
                />
              </label>

              <!-- Status message -->
              <p v-if="message" :class="['upload-overwrite-field__msg', messageType]">
                {{ message }}
              </p>

            </template>
          </k-field>
        </div>
      `,

      props: {
        label:    String,
        help:     String,
        accept:   String,   // e.g. ".glb" or ".jpg,.jpeg,.png"
        template: { type: String, default: 'default' },
        pageId:   String,   // computed server-side
        files:    { type: Array, default: () => [] },
      },

      data() {
        return {
          uploading:    false,
          message:      '',
          messageType:  'success',
          currentFiles: this.files || [],
        };
      },

      watch: {
        files(val) {
          this.currentFiles = val;
        },
      },

      methods: {
        async upload(event) {
          const file = event.target.files[0];
          if (!file) return;

          // Optionally validate extension client-side
          if (this.accept) {
            const exts = this.accept.split(',').map(e => e.trim().replace(/^\./, '').toLowerCase());
            const ext  = file.name.split('.').pop().toLowerCase();
            if (!exts.includes(ext)) {
              this.showMessage(`Extension non autorisée : .${ext}`, 'error');
              event.target.value = '';
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
              const verb = json.status === 'replaced' ? 'remplacé' : 'ajouté';
              this.showMessage(`Fichier ${verb} : ${json.filename}`, 'success');

              // Update local list
              const idx = this.currentFiles.findIndex(f => f.filename === json.filename);
              const entry = { filename: json.filename, url: json.url };
              if (idx >= 0) {
                this.currentFiles.splice(idx, 1, entry);
              } else {
                this.currentFiles.push(entry);
              }

              // Notify Kirby panel to refresh the current page section
              this.$panel.view.reload();
            }
          } catch (err) {
            this.showMessage('Erreur réseau : ' + err.message, 'error');
          } finally {
            this.uploading = false;
            event.target.value = '';
          }
        },

        showMessage(text, type) {
          this.message     = text;
          this.messageType = type;
          setTimeout(() => { this.message = ''; }, 4000);
        },
      },
    },
  },
});
