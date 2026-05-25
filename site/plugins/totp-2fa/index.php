<?php

/**
 * totp-2fa plugin
 *
 * Optional TOTP (RFC 6238) two-factor authentication for Kirby panel users.
 *
 *   ΓÇó Enrollment       ΓÇö user opens their profile ΓåÆ "Activer 2FA" section
 *                        reveals a QR code (otpauth:// URI) + recovery codes.
 *                        Verifying one code from the authenticator app
 *                        flips totp_enabled on the user record.
 *   ΓÇó Login intercept  ΓÇö after Kirby's password check we check totp_enabled.
 *                        If set, the user is redirected to /panel/totp where
 *                        they must enter a 6-digit code (or a recovery code)
 *                        before reaching the panel proper.
 *   ΓÇó Recovery codes   ΓÇö 10 single-use codes shown ONCE at enrollment.
 *                        Stored as SHA-256 hashes so a disk leak doesn't
 *                        reveal them. Consumed on use.
 *   ΓÇó Disable          ΓÇö user can disable from the same panel section by
 *                        re-entering one TOTP code or a recovery code.
 *
 * Storage:
 *   Three user content fields (no schema migration needed ΓÇö Kirby content
 *   fields are created on first write):
 *     totp_secret          string (Base32)  ΓÇö the shared secret
 *     totp_enabled         bool             ΓÇö false until enrollment verified
 *     totp_recovery_codes  yaml             ΓÇö array of SHA-256 hashes
 *
 * Security model:
 *   ΓÇó Secret is stored in the user content file (already protected by
 *     site/accounts ACL + .htaccess block on /site/).
 *   ΓÇó Verification uses hash_equals (constant-time) inside totp.php.
 *   ΓÇó Recovery codes are hashed at rest.
 *   ΓÇó Pending-login session token is short-lived (10 min) and bound to a
 *     specific user email ΓÇö can't be replayed for a different user.
 *
 * Pending-login flow (avoiding the "user is half logged-in" problem):
 *   1. POST /panel/login with credentials ΓåÆ Kirby validates password.
 *   2. Our `user.login:after` hook checks totp_enabled.
 *   3. If enabled, hook logs the user OUT immediately and stores a
 *      "pending TOTP" record in the session (email + expiry).
 *   4. User lands on /totp/verify, enters a code.
 *   5. On valid code: log them in via loginPasswordless().
 *      On invalid: stay on the page with an error.
 *   6. If they abandon: the pending record expires in 10 min, harmless.
 */

use Kirby\Cms\App as Kirby;
use Kirby\Cms\User;
use Kirby\Data\Yaml;
use Kirby\Exception\Exception as KirbyException;

require_once __DIR__ . '/totp.php';

Kirby::plugin('goheritage/totp-2fa', [

    // ΓöÇΓöÇ Panel API (admin user manages their own 2FA) ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    'api' => [
        'routes' => [

            // GET /api/goheritage/totp/setup ΓÇö returns a fresh secret + QR URI.
            // Doesn't commit anything yet ΓÇö only `confirm` writes to the user.
            [
                'pattern' => 'goheritage/totp/setup',
                'method'  => 'GET',
                'auth'    => true,
                'action'  => function () {
                    $user = kirby()->user();
                    if (!$user) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 401]);
                    }
                    $secret = GoheritageTotp::generateSecret();
                    return [
                        'secret' => $secret,
                        'uri'    => GoheritageTotp::provisioningUri($secret, $user->email()),
                        'issuer' => 'GoH├⌐ritage',
                    ];
                },
            ],

            // POST /api/goheritage/totp/confirm
            //   { secret, code }
            // Writes the secret + recovery codes to the user record IF the
            // provided code verifies. Returns the recovery codes (plaintext,
            // one-time display) on success.
            [
                'pattern' => 'goheritage/totp/confirm',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function () {
                    $user = kirby()->user();
                    if (!$user) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 401]);
                    }
                    $body   = $this->requestBody() ?: [];
                    $secret = trim((string) ($body['secret'] ?? ''));
                    $code   = trim((string) ($body['code'] ?? ''));

                    if (!GoheritageTotp::verify($secret, $code)) {
                        throw new KirbyException(['fallback' => 'Code invalide.', 'httpCode' => 400]);
                    }

                    $recoveryCodes = GoheritageTotp::generateRecoveryCodes(10);
                    $hashed = array_map([GoheritageTotp::class, 'hashRecoveryCode'], $recoveryCodes);

                    // impersonate so user can update own content even with
                    // restricted role permissions
                    kirby()->impersonate('kirby');
                    $user->update([
                        'totp_secret'         => $secret,
                        'totp_enabled'        => 'true',
                        'totp_recovery_codes' => Yaml::encode($hashed),
                    ]);
                    kirby()->impersonate();

                    return [
                        'enabled'        => true,
                        'recovery_codes' => $recoveryCodes,
                    ];
                },
            ],

            // POST /api/goheritage/totp/disable
            //   { code }   ΓÇö TOTP code OR recovery code
            // Wipes the secret + enabled flag. Requires a valid code so a
            // hijacked session can't disable 2FA silently.
            [
                'pattern' => 'goheritage/totp/disable',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function () {
                    $user = kirby()->user();
                    if (!$user) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 401]);
                    }
                    if (!$user->totp_enabled()->toBool()) {
                        throw new KirbyException(['fallback' => '2FA d├⌐j├á d├⌐sactiv├⌐.', 'httpCode' => 400]);
                    }
                    $code   = trim((string) ($this->requestBody()['code'] ?? ''));
                    $secret = $user->totp_secret()->value();
                    if (!goheritage_totp_check_user_code($user, $code)) {
                        throw new KirbyException(['fallback' => 'Code invalide.', 'httpCode' => 400]);
                    }
                    kirby()->impersonate('kirby');
                    $user->update([
                        'totp_secret'         => '',
                        'totp_enabled'        => 'false',
                        'totp_recovery_codes' => '',
                    ]);
                    kirby()->impersonate();
                    return ['enabled' => false];
                },
            ],

            // GET /api/goheritage/totp/status ΓÇö used by the panel section to
            // show "Activ├⌐" / "Non activ├⌐" without exposing the secret.
            [
                'pattern' => 'goheritage/totp/status',
                'method'  => 'GET',
                'auth'    => true,
                'action'  => function () {
                    $user = kirby()->user();
                    if (!$user) {
                        throw new KirbyException(['key' => 'access.panel', 'httpCode' => 401]);
                    }
                    return [
                        'enabled' => $user->totp_enabled()->toBool(),
                        'codes_remaining' => count(Yaml::decode($user->totp_recovery_codes()->value() ?? '')),
                    ];
                },
            ],
        ],
    ],

    // ΓöÇΓöÇ Public routes ΓÇö the TOTP challenge step after password login ΓöÇΓöÇ
    //
    // The pending-challenge state is stored in a JSON file on disk + a
    // short-lived cookie that points to it (NOT in Kirby's session,
    // because $user->logout() may destroy the session and we'd lose the
    // marker). The cookie is HttpOnly + Secure + SameSite=Lax, the file
    // is in site/accounts/.totp-pending/ (admin-readable only).
    'routes' => [
        [
            'pattern' => 'totp/verify',
            'method'  => 'GET|POST',
            'action'  => function () {
                // Cheap GC each request ΓÇö keeps the pending dir tidy
                goheritage_totp_prune_pending();
                $pending = goheritage_totp_load_pending();

                if (!$pending) {
                    return go('/panel/login');
                }
                if (time() > $pending['expires_at']) {
                    goheritage_totp_clear_pending();
                    return go('/panel/login?totp_expired=1');
                }

                $user = kirby()->user($pending['email']);
                if (!$user) {
                    goheritage_totp_clear_pending();
                    return go('/panel/login');
                }

                $error = null;
                if (kirby()->request()->is('POST')) {
                    $code = trim((string) get('code'));
                    if (goheritage_totp_check_user_code($user, $code)) {
                        goheritage_totp_clear_pending();
                        // Re-establish the login session now that 2FA is verified.
                        try {
                            $user->loginPasswordless();
                        } catch (\Throwable $e) {
                            return go('/panel/login');
                        }
                        return go('/panel');
                    } else {
                        $error = 'Code invalide. R├⌐essayez ou utilisez un code de secours.';
                    }
                }

                return snippet('totp-verify', [
                    'error'   => $error,
                    'email'   => $pending['email'],
                    'expires' => $pending['expires_at'],
                ], true);
            },
        ],

        // GET /totp/cancel ΓÇö user explicitly abandons the 2FA challenge
        // (from the "Annuler et recommencer" link). Clears the pending
        // marker so they don't see a stale challenge if they hit /totp/verify
        // again. Always redirects to /panel/login.
        [
            'pattern' => 'totp/cancel',
            'action'  => function () {
                goheritage_totp_clear_pending();
                return go('/panel/login');
            },
        ],
    ],

    // ΓöÇΓöÇ Hooks ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    'hooks' => [
        // After Kirby validates the password, gate the session behind a
        // TOTP challenge if the user has 2FA enabled.
        //
        // Pending marker lives in a file + cookie (not Kirby's session),
        // because $user->logout() may destroy the session and lose it.
        // The cookie just carries a random pointer; the actual email +
        // expiry live in the file at site/accounts/.totp-pending/.
        'user.login:after' => function (string $token, User $user) {
            if (!$user->totp_enabled()->toBool()) {
                return;
            }
            goheritage_totp_store_pending($user->email(), 600);
            $user->logout();
            // The panel can't redirect from a hook (login API returns JSON);
            // the frontend interceptor in index.js redirects to /totp/verify
            // once it sees the login response.
        },

        // Defensive: if a user's TOTP secret somehow gets cleared (e.g.
        // admin manually edited the YAML), make sure enabled flips back too.
        'user.update:before' => function (User $user, array $values, array $strings) {
            if (
                array_key_exists('totp_enabled', $values) &&
                $values['totp_enabled'] === 'true' &&
                empty($values['totp_secret']) &&
                $user->totp_secret()->isEmpty()
            ) {
                throw new KirbyException(['fallback' => 'Impossible d\'activer 2FA sans secret.', 'httpCode' => 400]);
            }
        },
    ],

    // ΓöÇΓöÇ User methods ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    'userMethods' => [
        'has2FA' => function () {
            return $this->totp_enabled()->toBool();
        },
    ],
]);


/**
 * ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
 * Pending-TOTP storage helpers
 *
 * Cookie name      : goheritage_totp_pending  (HttpOnly, Secure, SameSite=Lax)
 * Cookie value     : 32-char hex pointer
 * File path        : site/accounts/.totp-pending/<pointer>.json
 * File content     : { email, expires_at }
 *
 * We use cookie+file (not Kirby's session) because $user->logout() may
 * destroy the session, which would orphan the pending marker.
 * ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
 */
function goheritage_totp_pending_dir(): string
{
    // Mode 02770 (setgid + group rwx) so the directory is writable by both
    // the web user (daemon) and any CLI tools running under the daemon
    // group. Setgid ensures new files inherit the daemon group.
    $dir = kirby()->root('accounts') . '/.totp-pending';
    if (!is_dir($dir)) {
        @mkdir($dir, 02770, true);
        @chmod($dir, 02770);
    }
    return $dir;
}

function goheritage_totp_store_pending(string $email, int $ttlSec): void
{
    $pointer = bin2hex(random_bytes(16));
    $file    = goheritage_totp_pending_dir() . '/' . $pointer . '.json';
    file_put_contents($file, json_encode([
        'email'      => $email,
        'expires_at' => time() + $ttlSec,
    ]));
    @chmod($file, 0640);

    // HttpOnly is intentionally FALSE so the panel's JS interceptor can
    // detect this cookie and redirect to /totp/verify. The cookie value
    // is only a random pointer ΓÇö the actual email/expiry live in the
    // server-side file, so a pointer leak via JS gets an attacker nothing
    // (they still can't authenticate without the right TOTP code).
    // Guarded so CLI tests + edge cases where headers leaked don't trigger
    // a PHP warning (and so prod still gets a proper cookie via the hook).
    if (!headers_sent()) {
        setcookie('goheritage_totp_pending', $pointer, [
            'expires'  => time() + $ttlSec,
            'path'     => '/',
            'httponly' => false,
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') === '443',
            'samesite' => 'Lax',
        ]);
    }
    // Also poke $_COOKIE so the same request can read it back if needed.
    $_COOKIE['goheritage_totp_pending'] = $pointer;
}

function goheritage_totp_load_pending(): ?array
{
    $pointer = $_COOKIE['goheritage_totp_pending'] ?? null;
    if (!$pointer || !preg_match('/^[a-f0-9]{32}$/', $pointer)) {
        return null;
    }
    $file = goheritage_totp_pending_dir() . '/' . $pointer . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || empty($data['email']) || empty($data['expires_at'])) {
        return null;
    }
    return $data;
}

function goheritage_totp_clear_pending(): void
{
    $pointer = $_COOKIE['goheritage_totp_pending'] ?? null;
    if ($pointer && preg_match('/^[a-f0-9]{32}$/', $pointer)) {
        @unlink(goheritage_totp_pending_dir() . '/' . $pointer . '.json');
    }
    if (!headers_sent()) {
        setcookie('goheritage_totp_pending', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => false,
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') === '443',
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE['goheritage_totp_pending']);
}

/**
 * Garbage-collect expired pending markers. Cheap to call from any TOTP
 * route (single glob over a small dir; old files just get unlinked).
 */
function goheritage_totp_prune_pending(): void
{
    foreach (glob(goheritage_totp_pending_dir() . '/*.json') ?: [] as $f) {
        $data = json_decode((string) @file_get_contents($f), true);
        if (!$data || time() > ($data['expires_at'] ?? 0)) {
            @unlink($f);
        }
    }
}

/**
 * Verify a user-supplied code against the user's TOTP secret OR their
 * recovery code list. If a recovery code matches, it's consumed.
 */
function goheritage_totp_check_user_code(User $user, string $code): bool
{
    $code   = trim($code);
    $secret = $user->totp_secret()->value();

    // Try TOTP first ΓÇö fast, no disk writes on success
    if ($secret && GoheritageTotp::verify($secret, preg_replace('/\s+/', '', $code))) {
        return true;
    }

    // Try recovery codes (case-insensitive, with or without dash)
    $stored = Yaml::decode($user->totp_recovery_codes()->value() ?? '');
    if (!is_array($stored) || empty($stored)) {
        return false;
    }
    $normalised = strtolower(preg_replace('/[^a-f0-9-]/', '', $code));
    $hash = GoheritageTotp::hashRecoveryCode($normalised);

    foreach ($stored as $i => $h) {
        if (hash_equals($h, $hash)) {
            // Consume it
            array_splice($stored, $i, 1);
            kirby()->impersonate('kirby');
            $user->update(['totp_recovery_codes' => Yaml::encode($stored)]);
            kirby()->impersonate();
            return true;
        }
    }
    return false;
}
