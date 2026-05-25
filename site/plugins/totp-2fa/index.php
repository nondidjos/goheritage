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
                    ];
                },
            ],
        ],
    ],
]);
