<?php
/**
 * TOTP (RFC 6238) - authenticator-app two-factor auth. Pure PHP, no external deps.
 * Works with Google Authenticator, Authy, Microsoft Authenticator, 1Password, etc.
 *   SHA1, 6 digits, 30-second period (the universal default).
 */

/** Base32 (RFC 4648) encode - used to present the shared secret to the app. */
function totp_base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $val = 0;
    $bits = 0;
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $val = ($val << 8) | ord($bin[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($val >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($val << (5 - $bits)) & 31];
    }
    return $out;
}

/** Base32 decode (ignores spaces, padding and case). */
function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $out = '';
    $val = 0;
    $bits = 0;
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $idx = strpos($alphabet, $b32[$i]);
        if ($idx === false) {
            continue;
        }
        $val = ($val << 5) | $idx;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($val >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** A fresh random secret as base32 (default 20 bytes = 160 bits, the RFC-recommended size). */
function totp_new_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/** The N-digit code for a secret at a given time step. */
function totp_code(string $secretB32, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $key = totp_base32_decode($secretB32);
    $counter = intdiv($timestamp ?? time(), $period);
    // 8-byte big-endian counter (high word stays 0 until year ~2106)
    $bin = pack('N', 0) . pack('N', $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** $digits);
    return str_pad((string)$part, $digits, '0', STR_PAD_LEFT);
}

/** Verify a user-entered code, allowing +/-$window steps for clock drift (default +/-30s). */
function totp_verify(string $secretB32, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
{
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== $digits || $secretB32 === '') {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretB32, $now + $i * $period, $period, $digits), $code)) {
            return true;
        }
    }
    return false;
}

/** otpauth:// URI the authenticator app reads (via QR or manual paste). */
function totp_uri(string $secretB32, string $account, string $issuer = 'TrakrHub'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret'    => $secretB32,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
}

/** Group a base32 secret into 4-char blocks for easier manual typing. */
function totp_pretty_secret(string $secretB32): string
{
    return trim(chunk_split($secretB32, 4, ' '));
}
