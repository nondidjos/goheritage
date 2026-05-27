<?php

/**
 * invite-system plugin
 *
 * One-time invite links so admins can onboard collaborators without sending
 * passwords by email. Each invite is a JSON file on disk holding a 128-bit
 * random token, target role, optional project redirect, expiry, and a "used
 * at / used by" record so the same link can't register two accounts.
 *
 * Flow:
 *   1. Admin opens the Invitations panel view (or hits the API directly)
 *      and creates an invite for a role (+ optional project page redirect
 *      and human-readable note).
 *   2. Plugin generates a token + URL `/invite/<token>`, returns it for the
 *      admin to copy and forward through whichever channel they prefer.
 *   3. Recipient opens the URL → landing snippet shows project preview
 *      (when scoped) + signup form.
 *   4. Form POSTs to `/register`. Server validates inputs, atomically
 *      consumes the token (rename-based), creates the Kirby user with the
 *      role baked into the invite (no role escalation possible), logs them
 *      in, and redirects to the target project — or the panel.
 *
 * Edge cases explicitly handled:
 *   • Invalid / unknown token             → 404-like landing
 *   • Expired (past expires_at)           → message + ask admin for new link
 *   • Already used                        → message + login link
 *   • Email already registered            → form error + login link
 *   • Two recipients submit concurrently  → atomic file-rename means only
 *                                            the first wins; second sees
 *                                            "already used"
 *   • User abandons mid-flow              → token remains valid until expiry;
 *                                            admin can revoke from panel
 *   • Admin deletes project mid-flow      → invite still creates account;
 *                                            redirect falls back to panel
 *   • Role tampering on POST              → role is read from the stored
 *                                            invite, never from form fields
 */

use Kirby\Cms\App as Kirby;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Http\Response;
use Kirby\Toolkit\F;

// ── Storage helper ─────────────────────────────────────────────────────
class GoheritageInviteStore
{
    private string $dir;

    public function __construct()
    {
        // Hidden inside the accounts folder so it inherits the same backup
        // and permission story as user files. Leading dot keeps it out of
        // Kirby's user collection.
        //
        // Mode 02770 (setgid + group rwx) so files created by the web user
        // (daemon) AND CLI tools (e.g. cron) under the daemon group both
        // have write access. The setgid bit ensures new files inherit the
        // daemon group regardless of which umask is active.
        // Moved out of site/accounts/ — Kirby 5.4's user loader iterates
        // EVERY directory inside accounts/ (including dotfile-prefixed
        // ones) and yields a null user for those it can't parse, which
        // breaks UUID resolution sitewide. Living under site/.private/
        // keeps the files persistent + permission-shared with accounts
        // but invisible to the user iterator.
        $this->dir = kirby()->root('site') . '/.private/invites';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 02770, true);
            @chmod($this->dir, 02770);
        }
    }

    /** Hex-32 sanity check so the path can never be steered out of the dir. */
    private function validToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }

    private function path(string $token): string
    {
        return $this->dir . '/' . $token . '.json';
    }

    public function create(array $data): array
    {
        $token = bin2hex(random_bytes(16));
        $invite = [
            'token'        => $token,
            'role'         => $data['role']         ?? 'author',
            'project_id'   => $data['project_id']   ?? null,
            'hint_email'   => $data['hint_email']   ?? null,
            'hint_message' => $data['hint_message'] ?? null,
            'created_at'   => time(),
            'created_by'   => kirby()->user()?->email() ?? 'system',
            'expires_at'   => $data['expires_at']   ?? (time() + 7 * 24 * 3600),
            'used_at'      => null,
            'used_by'      => null,
        ];
        file_put_contents($this->path($token), json_encode($invite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($this->path($token), 0640);
        return $invite;
    }

    public function load(string $token): ?array
    {
        if (!$this->validToken($token)) return null;
        if (!is_file($this->path($token))) return null;
        $raw = file_get_contents($this->path($token));
        return json_decode($raw, true) ?: null;
    }

    /**
     * Atomic consume — flock-guarded read-modify-write so two concurrent
     * submissions can't both succeed.
     *
     *   • LOCK_EX held for the whole read+write window.
     *   • After acquiring the lock we re-read inside the critical section
     *     (the value we read before the lock may be stale).
     *   • If used_at is already set inside the lock, we lose and return false.
     *   • Otherwise we truncate + rewrite + flush, then release the lock.
     *
     * Returns true iff THIS caller is the one who claimed the token.
     */
    public function consume(string $token, string $userEmail): bool
    {
        if (!$this->validToken($token)) return false;
        $path = $this->path($token);
        if (!is_file($path)) return false;

        $fp = @fopen($path, 'r+');
        if (!$fp) return false;

        $claimed = false;
        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }
            // Re-read with the lock held — the snapshot we used to decide
            // there was no `used_at` may be 100ms out of date.
            rewind($fp);
            $raw = stream_get_contents($fp);
            $invite = json_decode((string) $raw, true);
            if (!is_array($invite) || !empty($invite['used_at'])) {
                return false;
            }
            $invite['used_at'] = time();
            $invite['used_by'] = $userEmail;

            rewind($fp);
            ftruncate($fp, 0);
            $written = fwrite($fp, json_encode($invite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($written === false) {
                return false;
            }
            fflush($fp);
            $claimed = true;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
        return $claimed;
    }

    public function revoke(string $token): bool
    {
        if (!$this->validToken($token)) return false;
        return @unlink($this->path($token));
    }

    /** All invites, newest first, with computed status. */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $data = json_decode((string) @file_get_contents($f), true);
            if ($data) {
                $data['status'] = $this->status($data);
                $data['url']    = url('invite/' . $data['token']);
                if (!empty($data['project_id'])) {
                    $p = page($data['project_id']);
                    $data['project_title'] = $p ? $p->title()->value() : '(supprimé)';
                }
                $out[] = $data;
            }
        }
        usort($out, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
        return $out;
    }

    public function status(array $invite): string
    {
        if (!empty($invite['used_at']))                  return 'used';
        if (time() > ($invite['expires_at'] ?? 0))       return 'expired';
        return 'active';
    }

    /** Prune anything older than 30 days past expiry. Cheap to call. */
    public function prune(): int
    {
        $threshold = time() - 30 * 24 * 3600;
        $deleted = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $data = json_decode((string) @file_get_contents($f), true);
            if (!$data) continue;
            $exp = $data['expires_at'] ?? 0;
            $used = $data['used_at'];
            // Delete used invites or expired-for-30-days invites
            if ($used !== null || ($exp > 0 && $exp < $threshold)) {
                @unlink($f);
                $deleted++;
            }
        }
        return $deleted;
    }
}


/**
 * Builds the plain-text email body sent to the invitee.
 * Kept simple — most enterprise mail filters mangle HTML emails from
 * unknown senders, and the link is the only thing that really needs to
 * land cleanly in the recipient's inbox.
 */
function goheritage_invite_email_body(array $invite, $project, string $url): string {
    $lines = [];
    $lines[] = "Bonjour,";
    $lines[] = "";
    if ($project) {
        $lines[] = "Vous avez été invité·e à rejoindre le projet « " . $project->title()->value() . " » sur GoHéritage.";
    } else {
        $lines[] = "Vous avez été invité·e à rejoindre GoHéritage.";
    }
    $lines[] = "";
    if (!empty($invite['hint_message'])) {
        $lines[] = "Message de l'administrateur :";
        $lines[] = $invite['hint_message'];
        $lines[] = "";
    }
    $lines[] = "Cliquez sur le lien suivant pour créer votre compte :";
    $lines[] = $url;
    $lines[] = "";
    $lines[] = "Ce lien expire le " . date('d/m/Y \à H\hi', $invite['expires_at']) . ".";
    $lines[] = "Il ne peut servir qu'une seule fois.";
    $lines[] = "";
    $lines[] = "— L'équipe GoHéritage";
    return implode("\n", $lines);
}

// ── Plugin registration ────────────────────────────────────────────────
Kirby::plugin('goheritage/invite-system', [

    // ── Panel area (registers the PHP-side route for the invites view) ─
    // Kirby 5's panel.plugin() `views` key is not processed — only
    // `components`, `fields`, `sections`, etc. are handled. To make the
    // panel router serve the HTML shell for our custom view, we must
    // register a PHP-level area with the matching pattern.
    //  Invitations live UNDER the Users area now — they're conceptually
    //  pending users. Registering an area with key `users` MERGES with
    //  Kirby's built-in users area, so our extra view shows up on the
    //  same URL space (/panel/users/invitations) without adding a new
    //  top-level sidebar entry.
    //
    //  Reachable from the users page header via a custom view button
    //  registered in index.js (k-invitations-view-button).
    'areas' => [
        'users' => function () {
            return [
                'views' => [
                    [
                        'pattern' => 'users/invitations',
                        'action'  => function () {
                            // Admin-gate the route — only admins can manage invites.
                            if (!kirby()->user() || !kirby()->user()->isAdmin()) {
                                throw new \Kirby\Exception\PermissionException(
                                    'Réservé aux administrateurs.'
                                );
                            }
                            return [
                                'component' => 'k-goheritage-invites-view',
                                'title'     => 'Invitations',
                                'breadcrumb' => [
                                    ['label' => 'Utilisateurs', 'link' => 'users'],
                                    ['label' => 'Invitations'],
                                ],
                                'props' => [],
                            ];
                        },
                    ],
                ],
            ];
        },
    ],

    // ── API routes (panel, admin-only) ────────────────────────────────
    'api' => [
        'routes' => [
            [
                'pattern' => 'goheritage/invites',
                'method'  => 'GET',
                'auth'    => true,
                'action'  => function () {
                    if (!kirby()->user() || !kirby()->user()->isAdmin()) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 403]);
                    }
                    $store = new GoheritageInviteStore();
                    $store->prune();
                    return ['invites' => $store->all()];
                },
            ],

            [
                'pattern' => 'goheritage/invites',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function () {
                    if (!kirby()->user() || !kirby()->user()->isAdmin()) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 403]);
                    }
                    $body = $this->requestBody() ?: [];

                    // Whitelist roles — admins can grant author or default,
                    // and EXPLICITLY also admin (creating a new admin is
                    // intentional, but logged so it's audit-traceable).
                    $allowedRoles = ['author', 'default', 'admin'];
                    $role = $body['role'] ?? 'author';
                    if (!in_array($role, $allowedRoles, true)) {
                        throw new KirbyException(['key' => 'invite.invalid-role', 'fallback' => 'Rôle invalide', 'httpCode' => 400]);
                    }

                    // Validate project_id resolves to a real project (if set)
                    $projectId = $body['project_id'] ?? null;
                    if ($projectId !== null && $projectId !== '') {
                        if (!page($projectId)) {
                            throw new KirbyException(['key' => 'invite.invalid-project', 'fallback' => 'Projet introuvable', 'httpCode' => 400]);
                        }
                    } else {
                        $projectId = null;
                    }

                    // Days clamped 1..90 — sane upper bound to discourage
                    // forever-tokens that turn into a security debt.
                    $days = max(1, min(90, intval($body['expires_in_days'] ?? 7)));

                    $store = new GoheritageInviteStore();
                    $invite = $store->create([
                        'role'         => $role,
                        'project_id'   => $projectId,
                        'hint_email'   => trim((string)($body['hint_email'] ?? '')) ?: null,
                        'hint_message' => trim((string)($body['hint_message'] ?? '')) ?: null,
                        'expires_at'   => time() + $days * 24 * 3600,
                    ]);
                    return [
                        'invite' => array_merge($invite, [
                            'status' => $store->status($invite),
                            'url'    => url('invite/' . $invite['token']),
                        ]),
                    ];
                },
            ],

            [
                'pattern' => 'goheritage/invites/(:any)',
                'method'  => 'DELETE',
                'auth'    => true,
                'action'  => function ($token) {
                    if (!kirby()->user() || !kirby()->user()->isAdmin()) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 403]);
                    }
                    $store = new GoheritageInviteStore();
                    return ['revoked' => $store->revoke($token)];
                },
            ],

            // POST /api/goheritage/invites/(:any)/email — send the invite by email
            // Returns 200 + ok=true on success, 503 if SMTP isn't configured,
            // 400 if the invite has no email hint to send to.
            [
                'pattern' => 'goheritage/invites/(:any)/email',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function ($token) {
                    if (!kirby()->user() || !kirby()->user()->isAdmin()) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 403]);
                    }
                    $store  = new GoheritageInviteStore();
                    $invite = $store->load($token);
                    if (!$invite) {
                        throw new KirbyException(['key' => 'invite.not-found', 'fallback' => 'Invitation introuvable', 'httpCode' => 404]);
                    }

                    $body  = $this->requestBody() ?: [];
                    $to    = trim((string) ($body['to'] ?? $invite['hint_email'] ?? ''));
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        throw new KirbyException(['key' => 'invite.bad-email', 'fallback' => 'Adresse email invalide', 'httpCode' => 400]);
                    }

                    // Check SMTP is configured — Kirby's email transport falls
                    // back to PHP mail() which is unreliable on Bitnami without
                    // sendmail. Fail loudly if neither is set.
                    $smtp = kirby()->option('email.transport', null);
                    if ($smtp === null) {
                        throw new KirbyException([
                            'key'      => 'invite.smtp-missing',
                            'fallback' => 'SMTP non configuré — copiez le lien et envoyez-le manuellement (la livraison email s\'activera dès que vous aurez configuré email.transport dans config.php).',
                            'httpCode' => 503,
                        ]);
                    }

                    $project = !empty($invite['project_id']) ? page($invite['project_id']) : null;
                    $url     = url('invite/' . $invite['token']);

                    try {
                        kirby()->email([
                            'to'      => $to,
                            'from'    => kirby()->option('email.from', 'noreply@goheritage.eu'),
                            'fromName' => 'GoHéritage',
                            'subject' => $project
                                ? 'Invitation à rejoindre « ' . $project->title()->value() . ' »'
                                : 'Invitation à rejoindre GoHéritage',
                            'template' => null,
                            'body'    => goheritage_invite_email_body($invite, $project, $url),
                        ]);
                    } catch (\Throwable $e) {
                        throw new KirbyException([
                            'fallback' => 'Échec de l\'envoi : ' . $e->getMessage(),
                            'httpCode' => 500,
                        ]);
                    }
                    return ['ok' => true, 'to' => $to];
                },
            ],
        ],
    ],

    // ── Public routes ──────────────────────────────────────────────────
    'routes' => [

        // Landing — recipient clicks the invite link.
        [
            'pattern' => 'invite/(:any)',
            'action'  => function ($token) {
                $store  = new GoheritageInviteStore();
                $invite = $store->load($token);
                $status = $invite ? $store->status($invite) : 'invalid';

                // If already logged in as the inviting team, just go to panel
                if ($status === 'active' && kirby()->user()) {
                    $target = $invite['project_id'] ? page($invite['project_id'])?->url() : null;
                    return go($target ?? '/panel');
                }

                return snippet('invite-landing', [
                    'invite'    => $invite,
                    'status'    => $status,
                    'errors'    => [],
                    'form_data' => ['email' => $invite['hint_email'] ?? '', 'name' => ''],
                    'token'     => $token,
                ], true);
            },
        ],

        // POST handler for the signup form.
        [
            'pattern' => 'register',
            'method'  => 'POST',
            'action'  => function () {
                $token = (string) get('token');
                $store = new GoheritageInviteStore();
                $invite = $store->load($token);
                $status = $invite ? $store->status($invite) : 'invalid';

                if ($status !== 'active') {
                    return snippet('invite-landing', [
                        'invite'    => $invite,
                        'status'    => $status,
                        'errors'    => [],
                        'form_data' => [],
                        'token'     => $token,
                    ], true);
                }

                $email           = trim((string) get('email'));
                $name            = trim((string) get('name'));
                $password        = (string) get('password');
                $passwordConfirm = (string) get('password_confirm');

                $errors = [];
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Adresse email invalide.';
                }
                if ($name === '') {
                    $errors[] = 'Le nom est requis.';
                }
                if (strlen($password) < 8) {
                    $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
                }
                if ($password !== $passwordConfirm) {
                    $errors[] = 'Les mots de passe ne correspondent pas.';
                }
                if (empty($errors) && kirby()->users()->find($email)) {
                    $errors[] = 'Un compte existe déjà avec cette adresse. Connectez-vous au panneau pour accéder à votre projet.';
                }

                if (!empty($errors)) {
                    return snippet('invite-landing', [
                        'invite'    => $invite,
                        'status'    => $status,
                        'errors'    => $errors,
                        'form_data' => ['email' => $email, 'name' => $name],
                        'token'     => $token,
                    ], true);
                }

                // Atomically claim the token BEFORE creating the user so a
                // crash during user-create can't double-spend the invite.
                if (!$store->consume($token, $email)) {
                    return snippet('invite-landing', [
                        'invite'    => $invite,
                        'status'    => 'used',
                        'errors'    => [],
                        'form_data' => [],
                        'token'     => $token,
                    ], true);
                }

                // User creation needs admin privileges. Impersonate Kirby
                // (the system user) so the role baked into the invite is
                // applied without a logged-in admin session.
                try {
                    kirby()->impersonate('kirby');
                    $user = kirby()->users()->create([
                        'name'     => $name,
                        'email'    => $email,
                        'password' => $password,
                        'role'     => $invite['role'],
                        'language' => 'fr',
                    ]);
                    kirby()->impersonate();
                } catch (\Throwable $e) {
                    // User creation failed — roll back the consume so the
                    // invite can be retried.
                    $invite['used_at'] = null;
                    $invite['used_by'] = null;
                    file_put_contents(
                        kirby()->root('site') . '/.private/invites/' . $token . '.json',
                        json_encode($invite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                    return snippet('invite-landing', [
                        'invite'    => $invite,
                        'status'    => 'active',
                        'errors'    => ['Erreur lors de la création du compte : ' . $e->getMessage()],
                        'form_data' => ['email' => $email, 'name' => $name],
                        'token'     => $token,
                    ], true);
                }

                // Auto-login the new user
                try {
                    $user->loginPasswordless();
                } catch (\Throwable $e) {
                    // Non-fatal — they can log in manually
                }

                // Redirect: project if scoped, otherwise panel.
                $target = null;
                if (!empty($invite['project_id'])) {
                    $page = page($invite['project_id']);
                    if ($page) $target = $page->url();
                }
                return go($target ?? '/panel');
            },
        ],
    ],
]);
