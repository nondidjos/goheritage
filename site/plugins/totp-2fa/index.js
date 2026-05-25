/**
 * totp-2fa panel plugin
 *
 * Provides:
 *   ΓÇó `totp-section`  ΓÇö drop-in user-blueprint section showing 2FA status,
 *     enrollment dialog (QR code + verify), recovery codes, and disable.
 *   ΓÇó Login interceptor ΓÇö after a successful POST /api/auth/login response
 *     we check whether the session is actually still logged in. If not, we
 *     redirect to /totp/verify (the user.login:after hook has stashed a
 *     pending challenge in the session).
 *
 * QR code rendering uses qrcode-svg (single-file vanilla JS, ~10 KB,
 * MIT licensed). Loaded lazily from CDN ΓÇö only paid for when a user opens
 * the 2FA enrollment dialog.
 */

panel.plugin('goheritage/totp-2fa', {

  sections: {
    'totp-section': {
      template: /* html */`
        <k-section :label="label" :icon="'lock'">
          <div class="goheritage-totp-section">

            <div v-if="loading" class="goheritage-totp-section__loading">
              <k-icon type="loader" /> ChargementΓÇª
            </div>

            <template v-else>

              <!-- Enabled state -->
              <div v-if="status.enabled" class="goheritage-totp-section__row">
                <div class="goheritage-totp-section__status goheritage-totp-section__status--on">
                  <k-icon type="check" />
                  <span><strong>2FA activ├⌐e</strong> ΓÇö {{ status.codes_remaining }} code(s) de secours restants.</span>
                </div>
                <k-button icon="lock-open" theme="negative" @click="openDisable">D├⌐sactiver</k-button>
              </div>

              <!-- Not enabled -->
              <div v-else class="goheritage-totp-section__row">
                <div class="goheritage-totp-section__status">
                  <k-icon type="alert" />
                  <span>2FA non activ├⌐e. Renforcez la s├⌐curit├⌐ de votre compte avec une application d'authentification (Google Authenticator, Authy, 1PasswordΓÇª).</span>
                </div>
                <k-button icon="lock" variant="filled" theme="positive" @click="openEnroll">Activer 2FA</k-button>
              </div>

            </template>

            <!-- ΓöÇΓöÇ Enrollment dialog ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ -->
            <k-dialog
              v-if="enrollOpen"
              :submit-button="false"
              :cancel-button="{ text: 'Annuler' }"
              @cancel="closeEnroll"
              size="medium"
            >
              <k-text><strong>1. Scannez ce QR code</strong> dans votre application d'authentification.</k-text>

              <div class="goheritage-totp-qr" ref="qrContainer"></div>

              <k-text class="goheritage-totp-section__manual">
                Ou entrez manuellement&nbsp;: <code>{{ enroll.secret }}</code>
              </k-text>

              <k-text><strong>2. Entrez le code ├á 6 chiffres</strong> affich├⌐ par l'application pour v├⌐rifier.</k-text>

              <k-input
                v-model="enroll.code"
                type="text"
                placeholder="000000"
                inputmode="numeric"
                maxlength="6"
                :autofocus="true"
                class="goheritage-totp-code-input"
                @keydown.enter="submitEnroll"
              />

              <k-text v-if="enroll.error" theme="negative">{{ enroll.error }}</k-text>

              <k-button-group>
                <k-button :disabled="enroll.code.length !== 6 || enroll.submitting" icon="check" variant="filled" theme="positive" @click="submitEnroll">
                  {{ enroll.submitting ? 'V├⌐rificationΓÇª' : 'V├⌐rifier et activer' }}
                </k-button>
              </k-button-group>
            </k-dialog>

            <!-- ΓöÇΓöÇ Recovery codes dialog (after enrollment) ΓöÇΓöÇΓöÇΓöÇ -->
            <k-dialog
              v-if="recoveryCodes"
              :submit-button="{ text: 'J\\'ai sauvegard├⌐ mes codes', icon: 'check', theme: 'positive' }"
              :cancel-button="false"
              @submit="closeRecovery"
              @close="closeRecovery"
              size="medium"
            >
              <k-text><strong>Sauvegardez ces codes</strong> dans un endroit s├╗r ΓÇö ils servent si vous perdez l'acc├¿s ├á votre application d'authentification. Chaque code n'est utilisable qu'une seule fois.</k-text>
              <ul class="goheritage-totp-codes">
                <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
              </ul>
              <k-button-group>
                <k-button icon="copy" @click="copyCodes">Copier la liste</k-button>
                <k-button icon="download" @click="downloadCodes">T├⌐l├⌐charger</k-button>
              </k-button-group>
            </k-dialog>

            <!-- ΓöÇΓöÇ Disable dialog ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ -->
            <k-dialog
              v-if="disableOpen"
              :submit-button="false"
              :cancel-button="{ text: 'Annuler' }"
              @cancel="closeDisable"
              size="medium"
            >
              <k-text><strong>D├⌐sactiver 2FA</strong> ΓÇö entrez un code TOTP ou un code de secours pour confirmer.</k-text>
              <k-input
                v-model="disableForm.code"
                type="text"
                placeholder="000000 ou code-secours"
                :autofocus="true"
                class="goheritage-totp-code-input"
                @keydown.enter="submitDisable"
              />
              <k-text v-if="disableForm.error" theme="negative">{{ disableForm.error }}</k-text>
              <k-button-group>
                <k-button :disabled="!disableForm.code || disableForm.submitting" icon="lock-open" variant="filled" theme="negative" @click="submitDisable">
                  {{ disableForm.submitting ? 'V├⌐rificationΓÇª' : 'D├⌐sactiver' }}
                </k-button>
              </k-button-group>
            </k-dialog>

          </div>
        </k-section>
      `,

      computed: {
        label() { return 'Authentification ├á deux facteurs'; },
      },

      data() {
        return {
          loading:      true,
          status:       { enabled: false, codes_remaining: 0 },
          enrollOpen:   false,
          enroll:       { secret: '', uri: '', code: '', error: null, submitting: false },
          recoveryCodes: null,
          disableOpen:  false,
          disableForm:  { code: '', error: null, submitting: false },
        };
      },

      async created() {
        await this.loadStatus();
      },

      methods: {
        async loadStatus() {
          this.loading = true;
          try {
            this.status = await this.$api.get('goheritage/totp/status');
          } catch (e) {
            // Likely unauthorised ΓÇö leave defaults
          } finally {
            this.loading = false;
          }
        },

        async openEnroll() {
          this.enroll = { secret: '', uri: '', code: '', error: null, submitting: false };
          try {
            const r = await this.$api.get('goheritage/totp/setup');
            this.enroll.secret = r.secret;
            this.enroll.uri    = r.uri;
            this.enrollOpen = true;
            this.$nextTick(() => this.renderQR(r.uri));
          } catch (e) {
            this.$panel.notification.error('Impossible de g├⌐n├⌐rer un secret : ' + e.message);
          }
        },

        closeEnroll() { this.enrollOpen = false; },

        renderQR(uri) {
          // Lazy-load qrcode-svg from CDN. ~10 KB, MIT-licensed.
          const QR_URL = 'https://cdn.jsdelivr.net/npm/qrcode-svg@1.1.0/dist/qrcode.min.js';
          const renderInto = () => {
            if (!this.$refs.qrContainer || !window.QRCode) return;
            const svg = new window.QRCode({
              content:  uri,
              padding:  2,
              width:    220,
              height:   220,
              color:    '#000',
              background: '#fff',
              ecl:      'M',
              container: 'svg-viewbox',
              join:     true,
            }).svg();
            this.$refs.qrContainer.innerHTML = svg;
          };
          if (window.QRCode) {
            renderInto();
          } else {
            const s = document.createElement('script');
            s.src = QR_URL;
            s.onload  = renderInto;
            s.onerror = () => {
              if (this.$refs.qrContainer) {
                this.$refs.qrContainer.innerHTML = '<p style="color:#a00">QR indisponible ΓÇö saisissez le secret manuellement.</p>';
              }
            };
            document.head.appendChild(s);
          }
        },

        async submitEnroll() {
          this.enroll.submitting = true;
          this.enroll.error = null;
          try {
            const r = await this.$api.post('goheritage/totp/confirm', {
              secret: this.enroll.secret,
              code:   this.enroll.code.trim(),
            });
            this.enrollOpen   = false;
            this.recoveryCodes = r.recovery_codes;
            await this.loadStatus();
            this.$panel.notification.success('2FA activ├⌐e');
          } catch (e) {
            this.enroll.error = e.message || 'Erreur';
          } finally {
            this.enroll.submitting = false;
          }
        },

        closeRecovery() {
          this.recoveryCodes = null;
        },

        copyCodes() {
          const text = (this.recoveryCodes || []).join('\n');
          if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(
              () => this.$panel.notification.success('Codes copi├⌐s'),
              () => this.$panel.notification.error('Impossible de copier ΓÇö s├⌐lectionnez manuellement.')
            );
          }
        },

        downloadCodes() {
          const text = '# Codes de secours GoH├⌐ritage ΓÇö ' + new Date().toISOString() + '\n'
                     + '# Chaque code est utilisable une seule fois.\n\n'
                     + (this.recoveryCodes || []).join('\n');
          const blob = new Blob([text], { type: 'text/plain' });
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'goheritage-recovery-codes.txt';
          a.click();
          URL.revokeObjectURL(a.href);
        },

        openDisable() {
          this.disableForm = { code: '', error: null, submitting: false };
          this.disableOpen = true;
        },

        closeDisable() { this.disableOpen = false; },

        async submitDisable() {
          this.disableForm.submitting = true;
          this.disableForm.error = null;
          try {
            await this.$api.post('goheritage/totp/disable', { code: this.disableForm.code.trim() });
            this.disableOpen = false;
            await this.loadStatus();
            this.$panel.notification.success('2FA d├⌐sactiv├⌐e');
          } catch (e) {
            this.disableForm.error = e.message || 'Erreur';
          } finally {
            this.disableForm.submitting = false;
          }
        },
      },
    },
  },
});

// ΓöÇΓöÇ Login-flow interceptor ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
// When the user submits the login form and 2FA is enabled, the
// user.login:after hook stores a pending-TOTP marker (cookie+file) then
// logs them out. The panel JS sees the login API return success and
// tries to navigate to /panel, which bounces back to /panel/login.
//
// We intercept that flow: as soon as we see a successful login API call,
// we check for the goheritage_totp_pending cookie. If present, the user
// has 2FA enabled ΓÇö redirect straight to /totp/verify instead of letting
// the panel do its dance. If absent (no 2FA), we don't interfere.
(function () {
  if (!window.fetch) return;

  const cookieExists = function () {
    return document.cookie.split(';').some(function (c) {
      return c.trim().indexOf('goheritage_totp_pending=') === 0;
    });
  };

  const origFetch = window.fetch;
  window.fetch = function (input, init) {
    return origFetch.call(this, input, init).then(function (resp) {
      try {
        const url = typeof input === 'string' ? input : (input.url || '');
        // Match both /api/auth/login and panel-prefixed variants
        if (!/\/api\/auth\/login/.test(url) || !resp.ok) return resp;

        // Defer briefly so the Set-Cookie header from the login hook lands.
        // Then check for the pending-TOTP cookie. If it's there, we know
        // the user has 2FA ΓÇö go to verify. Otherwise let the panel do its
        // normal thing.
        setTimeout(function () {
          if (cookieExists()) {
            window.location.href = '/totp/verify';
          }
        }, 100);
      } catch (_) {}
      return resp;
    });
  };
})();
