/**
 * invite-system panel view
 *
 * Registers a custom panel view at /panel/plugins/goheritage-invite-system/invites
 * (referenced from config.php's panel.menu config so it appears in the sidebar).
 *
 * UI:
 *   • Table of existing invites with status pills (active / used / expired)
 *   • "Créer un lien" button → opens a dialog with role / project / expiry
 *     fields. Returns the generated URL, ready to copy.
 *   • Each row: copy URL · revoke · view scoped project.
 *
 * All API calls go through Kirby's built-in $api wrapper so they inherit
 * the panel CSRF token and the user's auth session automatically.
 */

panel.plugin('goheritage/invite-system', {

  // `views` is silently ignored in Kirby 5.x — panel.plugin() only handles
  // components, fields, sections, etc.  The PHP-side route is registered via
  // `areas` in index.php; the action returns `component: 'k-goheritage-invites-view'`
  // which Kirby looks up in the global component registry built from this block.
  components: {

    // ── Status pill ───────────────────────────────────────────────────
    'goheritage-invite-status': {
      props: ['status'],
      template: `
        <span class="goheritage-invite-status" :class="'goheritage-invite-status--' + status">
          <k-icon :type="icon" />
          {{ label }}
        </span>
      `,
      computed: {
        icon()  { return { active: 'check', used: 'archive', expired: 'clock' }[this.status] || 'help'; },
        label() { return { active: 'Actif', used: 'Utilisé', expired: 'Expiré' }[this.status] || this.status; },
      },
    },

    // ── Full-page invitations view ────────────────────────────────────
    'k-goheritage-invites-view': {

      template: /* html */`
        <k-panel-inside class="k-invites-view">
            <k-header>
              Invitations
              <k-button-group slot="buttons">
                <k-button
                  icon="add"
                  variant="filled"
                  theme="positive"
                  @click="openCreate"
                >Créer un lien</k-button>
              </k-button-group>
            </k-header>

            <k-section>
              <p slot="info" class="goheritage-invites-help">
                Générez un lien à usage unique. Le destinataire crée son compte
                en cliquant le lien&nbsp;; le rôle (et le projet de destination,
                si renseigné) sont définis ici, pas dans le formulaire d'inscription.
              </p>

              <div v-if="loading" class="goheritage-invites-loading">
                <k-icon type="loader" />
                Chargement…
              </div>

              <!-- Empty state: use Kirby's k-empty so the icon renders
                   contained (was floating before), and put the CTA in
                   its own row so the + actually sits centered inside
                   the button's flexbox. -->
              <template v-else-if="invites.length === 0">
                <k-empty icon="email" class="goheritage-invites-empty">
                  Aucune invitation pour l'instant.
                </k-empty>
                <div class="goheritage-invites-empty-cta">
                  <k-button
                    icon="add"
                    text="Créer la première"
                    variant="filled"
                    theme="positive"
                    size="sm"
                    @click="openCreate"
                  />
                </div>
              </template>

              <table v-else class="goheritage-invites-table">
                <thead>
                  <tr>
                    <th>Statut</th>
                    <th>Rôle</th>
                    <th>Projet</th>
                    <th>Destinataire</th>
                    <th>Créé</th>
                    <th>Expire</th>
                    <th class="goheritage-invites-table__actions">&nbsp;</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inv in invites" :key="inv.token">
                    <td><goheritage-invite-status :status="inv.status" /></td>
                    <td><code>{{ inv.role }}</code></td>
                    <td class="goheritage-invites-table__project">
                      <span v-if="inv.project_title">{{ inv.project_title }}</span>
                      <span v-else class="goheritage-invites-table__dim">—</span>
                    </td>
                    <td>{{ inv.hint_email || '—' }}</td>
                    <td>{{ formatDate(inv.created_at) }}</td>
                    <td :class="{ 'goheritage-invites-table__expiring': isExpiringSoon(inv) }">
                      {{ formatDate(inv.expires_at) }}
                    </td>
                    <td class="goheritage-invites-table__actions">
                      <k-button
                        v-if="inv.status === 'active'"
                        icon="copy"
                        size="xs"
                        @click="copyUrl(inv.url)"
                        :tooltip="'Copier ' + inv.url"
                      >Copier</k-button>
                      <k-button
                        v-if="inv.status === 'active' && inv.hint_email"
                        icon="email"
                        size="xs"
                        @click="sendEmail(inv)"
                        :tooltip="'Envoyer à ' + inv.hint_email"
                      >Envoyer</k-button>
                      <k-button
                        icon="trash"
                        size="xs"
                        theme="negative"
                        @click="confirmRevoke(inv)"
                      >Révoquer</k-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </k-section>

            <!-- ── Create dialog ──────────────────────────────────── -->
            <k-dialog
              v-if="dialogOpen"
              ref="createDialog"
              :submit-button="{ text: 'Générer le lien', icon: 'add', theme: 'positive' }"
              :cancel-button="{ text: 'Annuler' }"
              @submit="submitCreate"
              @cancel="closeCreate"
            >
              <k-text>Créer une invitation</k-text>

              <k-fieldset
                :fields="createFields"
                v-model="createForm"
              />
            </k-dialog>

            <!-- ── Result dialog (after create) ───────────────────── -->
            <k-dialog
              v-if="resultInvite"
              :submit-button="{ text: 'Fermer', icon: 'check' }"
              :cancel-button="false"
              @submit="resultInvite = null"
              @close="resultInvite = null"
            >
              <k-text>
                <strong>Lien généré</strong> — copiez-le et envoyez-le au destinataire,
                ou utilisez le bouton « Envoyer » si l'email SMTP est configuré.
              </k-text>
              <div class="goheritage-invite-result">
                <input ref="resultInput" type="text" :value="resultInvite.url" readonly @click="$event.target.select()" />
                <k-button icon="copy" variant="filled" @click="copyUrl(resultInvite.url)">Copier</k-button>
                <k-button
                  v-if="resultInvite.hint_email"
                  icon="email"
                  variant="filled"
                  theme="info"
                  @click="sendEmail(resultInvite)"
                >Envoyer</k-button>
              </div>
              <k-text class="goheritage-invite-result__note">
                Ce lien n'apparaîtra plus en clair après fermeture — il reste cependant copiable depuis la table ci-dessous.
              </k-text>
            </k-dialog>

          </k-panel-inside>
        `,

        data() {
          return {
            loading:      true,
            invites:      [],
            projects:     [],
            dialogOpen:   false,
            resultInvite: null,
            createForm: {
              role:            'author',
              project_id:      '',
              hint_email:      '',
              hint_message:    '',
              expires_in_days: 7,
            },
          };
        },

        computed: {
          createFields() {
            return {
              role: {
                label:   'Rôle attribué au compte',
                type:    'select',
                width:   '1/2',
                options: [
                  { value: 'author',  text: 'Author — peut éditer mais pas publier' },
                  { value: 'default', text: 'Default — lecture seule' },
                  { value: 'admin',   text: 'Admin — accès complet (avec prudence)' },
                ],
                required: true,
              },
              expires_in_days: {
                label: 'Expire dans',
                type:  'select',
                width: '1/2',
                options: [
                  { value: 1,  text: '1 jour' },
                  { value: 7,  text: '7 jours (défaut)' },
                  { value: 14, text: '14 jours' },
                  { value: 30, text: '30 jours' },
                  { value: 90, text: '90 jours' },
                ],
                required: true,
              },
              project_id: {
                label:    'Projet de destination (optionnel)',
                type:     'select',
                options:  this.projects,
                empty:    'Aucun — invite vers le panneau',
                help:     'Le destinataire est redirigé vers ce projet après inscription.',
              },
              hint_email: {
                label:       'Email suggéré (optionnel)',
                type:        'email',
                width:       '1/2',
                placeholder: 'destinataire@exemple.com',
                help:        'Pré-remplit le champ email du formulaire.',
              },
              hint_message: {
                label:       'Message personnel (optionnel)',
                type:        'textarea',
                buttons:     false,
                size:        'small',
                placeholder: 'Bonjour ! Voici ton accès au projet…',
              },
            };
          },
        },

        async created() {
          await this.loadInvites();
          await this.loadProjects();
        },

        methods: {
          async loadInvites() {
            this.loading = true;
            try {
              const r = await this.$api.get('goheritage/invites');
              this.invites = r.invites || [];
            } catch (e) {
              this.$panel.notification.error('Impossible de charger les invitations : ' + e.message);
            } finally {
              this.loading = false;
            }
          },

          async loadProjects() {
            // Pull project pages so the dialog can offer them as redirect targets
            try {
              const r = await this.$api.get('pages/map/children', { select: 'id,title' });
              this.projects = (r.data || []).map(p => ({ value: p.id, text: p.title }));
            } catch (e) {
              // Non-fatal — dialog just won't list projects
              this.projects = [];
            }
          },

          openCreate() {
            this.createForm = {
              role:            'author',
              project_id:      '',
              hint_email:      '',
              hint_message:    '',
              expires_in_days: 7,
            };
            this.dialogOpen = true;
          },

          closeCreate() {
            this.dialogOpen = false;
          },

          async submitCreate() {
            try {
              const r = await this.$api.post('goheritage/invites', this.createForm);
              this.dialogOpen = false;
              this.resultInvite = r.invite;
              await this.loadInvites();
              this.$panel.notification.success('Invitation créée');
            } catch (e) {
              this.$panel.notification.error('Erreur : ' + (e.message || 'inconnue'));
            }
          },

          async sendEmail(inv) {
            const to = inv.hint_email;
            if (!to) {
              this.$panel.notification.error('Aucune adresse email renseignée pour cette invitation.');
              return;
            }
            try {
              await this.$api.post('goheritage/invites/' + inv.token + '/email', { to });
              this.$panel.notification.success('Email envoyé à ' + to);
            } catch (e) {
              // 503 = SMTP not configured — distinct message so admin knows
              // why it failed and what to do.
              const msg = e.message || 'Erreur inconnue';
              if (e.code === 503 || /smtp/i.test(msg)) {
                this.$panel.notification.error(msg);
              } else {
                this.$panel.notification.error('Échec : ' + msg);
              }
            }
          },

          confirmRevoke(inv) {
            this.$panel.dialog.open({
              component: 'k-remove-dialog',
              props: {
                text: 'Révoquer ce lien&nbsp;? Il deviendra invalide immédiatement et ne pourra plus servir à créer un compte.',
              },
              on: {
                submit: async () => {
                  try {
                    await this.$api.delete('goheritage/invites/' + inv.token);
                    this.$panel.dialog.close();
                    this.$panel.notification.success('Invitation révoquée');
                    await this.loadInvites();
                  } catch (e) {
                    this.$panel.notification.error('Erreur : ' + e.message);
                  }
                },
              },
            });
          },

          copyUrl(url) {
            if (!url) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(url).then(
                () => this.$panel.notification.success('Lien copié'),
                () => this.fallbackCopy(url)
              );
            } else {
              this.fallbackCopy(url);
            }
          },

          fallbackCopy(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); this.$panel.notification.success('Lien copié'); }
            catch (_) { this.$panel.notification.error('Copie impossible — sélectionnez manuellement.'); }
            document.body.removeChild(ta);
          },

          formatDate(ts) {
            if (!ts) return '—';
            const d = new Date(ts * 1000);
            return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
          },

          isExpiringSoon(inv) {
            if (inv.status !== 'active') return false;
            const daysLeft = (inv.expires_at - Math.floor(Date.now() / 1000)) / 86400;
            return daysLeft < 2;
          },
        },
    },

  },
});


// ──────────────────────────────────────────────────────────────────────
//  Users-page header enhancement
//
//  We dropped the standalone "Invitations" sidebar entry. To keep the
//  feature discoverable, this DOM enhancement detects when the user is
//  on /panel/users (the built-in users list) and injects a "Voir les
//  invitations" link button into the page header so admins can reach
//  the invites view in one click.
//
//  Plain DOM, runs outside Vue, idempotent — same pattern as the
//  project-ux section-edit injector.
// ──────────────────────────────────────────────────────────────────────
(function () {
  if (typeof window === 'undefined') return;

  var BTN_ID = 'gh-invites-jump';

  function onUsersList() {
    // /panel/users (with optional trailing slash) but NOT /panel/users/<anything>
    var p = window.location.pathname || '';
    return /\/panel\/users\/?$/.test(p);
  }

  function isAdmin() {
    try { return window.panel?.user?.role?.name === 'admin'; }
    catch (_) { return false; }
  }

  function inject() {
    if (!onUsersList() || !isAdmin()) {
      var stale = document.getElementById(BTN_ID);
      if (stale) stale.remove();
      return;
    }
    if (document.getElementById(BTN_ID)) return;

    // Find the page header's button group. Kirby renders header buttons
    // inside .k-header-buttons; we append after the existing ones.
    var header = document.querySelector('.k-page-view > .k-header')
              || document.querySelector('.k-header');
    if (!header) return;
    var btnGroup = header.querySelector('.k-header-buttons')
                || header.querySelector('.k-button-group');
    if (!btnGroup) return;

    var btn = document.createElement('a');
    btn.id = BTN_ID;
    btn.href = '/panel/users/invitations';
    btn.className = 'k-button';
    btn.setAttribute('data-variant', 'filled');
    btn.setAttribute('data-theme', 'passive');
    btn.setAttribute('data-size', 'sm');
    btn.innerHTML =
      '<span class="k-button-icon">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>' +
          '<polyline points="22,6 12,13 2,6"/>' +
        '</svg>' +
      '</span>' +
      '<span class="k-button-text">Invitations</span>';
    // Let Kirby's SPA router handle the click instead of full-page navigation.
    btn.addEventListener('click', function (e) {
      if (window.panel?.view?.open) {
        e.preventDefault();
        window.panel.view.open('/users/invitations');
      }
    });
    btnGroup.appendChild(btn);
  }

  // Observe DOM for view changes (Kirby is an SPA).
  var mo = new MutationObserver(function () { inject(); });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      inject();
      mo.observe(document.body, { childList: true, subtree: true });
    });
  } else {
    inject();
    mo.observe(document.body, { childList: true, subtree: true });
  }
})();
