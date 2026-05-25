<?php

/**
 * Minimal RFC 6238 TOTP implementation in pure PHP.
 *
 *   ΓÇó Algorithm:  SHA-1 (RFC 6238 default; supported by every authenticator)
 *   ΓÇó Digits:     6
 *   ΓÇó Period:     30 seconds
 *   ΓÇó Window:     ┬▒1 step (so a 30-second clock drift either way is tolerated)
 *
 * No external dependencies. Audited against the RFC 6238 reference test
 * vectors (the comment block at the bottom shows the expected outputs for
 * the canonical secret "12345678901234567890" at known timestamps).
 *
 * Secrets are stored as Base32 strings (the format authenticator apps expect)
 * and converted to raw bytes only inside compute().
 */

class GoheritageTotp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1; // accept current ┬▒1 steps (90 sec total)
    private const ALGO   = 'sha1';

    /** Generate a fresh 160-bit (20-byte) shared secret as Base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Compute the 6-digit code for a given secret + Unix timestamp. */
    public static function compute(string $secretBase32, int $timestamp): string
    {
        $key = self::base32Decode($secretBase32);
        $counter = intdiv($timestamp, self::PERIOD);

        // 8-byte big-endian counter
        $binCounter = pack('N*', 0) . pack('N*', $counter);

        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);

        // Dynamic Truncation per RFC 4226
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code    = (ord($hash[$offset])     & 0x7F) << 24
                 | (ord($hash[$offset + 1]) & 0xFF) << 16
                 | (ord($hash[$offset + 2]) & 0xFF) << 8
                 | (ord($hash[$offset + 3]) & 0xFF);
        $code   %= 10 ** self::DIGITS;

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied code against the secret. Accepts WINDOW steps
     * around now to absorb clock drift. Constant-time comparison to avoid
     * timing side channels.
     */
    public static function verify(string $secretBase32, string $userCode, ?int $now = null): bool
    {
        $now = $now ?? time();
        $clean = preg_replace('/\s+/', '', $userCode);
        if (!preg_match('/^\d{6}$/', $clean)) {
            return false;
        }
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $expected = self::compute($secretBase32, $now + $i * self::PERIOD);
            if (hash_equals($expected, $clean)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the otpauth:// URI an authenticator app reads from a QR code.
     * Issuer is URL-encoded twice intentionally (once in the label, once in
     * the issuer param) so apps like Authy show "GoH├⌐ritage: alice@x" cleanly.
     */
    public static function provisioningUri(string $secretBase32, string $accountName, string $issuer = 'GoH├⌐ritage'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /** Generate N single-use recovery codes (10 chars each, easy to type). */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // 5 random bytes ΓåÆ 10 hex chars, grouped 5-5 for readability
            $hex = bin2hex(random_bytes(5));
            $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
        }
        return $codes;
    }

    /** Hash a recovery code for storage (so disk leak doesn't reveal them). */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(trim($code)));
    }

    // ΓöÇΓöÇ Base32 helpers (RFC 4648, no padding output) ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    private const B32_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $data): string
    {
        if ($data === '') return '';
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::B32_ALPHA[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($b32 === '') return '';
        $bits = '';
        foreach (str_split($b32) as $c) {
            $idx = strpos(self::B32_ALPHA, $c);
            if ($idx === false) continue;
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}

/**
 * RFC 6238 Appendix B reference test vectors (SHA-1):
 *   Secret base32 = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
 *   (i.e. raw "12345678901234567890")
 *
 *   Time (s)      Expected
 *   59            287082
 *   1111111109    081804
 *   1111111111    050471
 *   1234567890    005924
 *
 * Spot-checked with this implementation ΓÇö passes all four.
 */
