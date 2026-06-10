<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RFC 6238 TOTP (and RFC 4226 HOTP underneath) — dependency-free.
 * SHA-1, 6 digits, 30-second period: compatible with Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password, etc.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New random secret, base32-encoded (160 bits, per RFC 4226 recommendation). */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** otpauth:// provisioning URI for authenticator apps / QR codes. */
    public function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Verify a code with ±$window time-step drift tolerance.
     * $lastUsedCounter enables replay protection: a matching counter must be
     * strictly greater than the last accepted one. On success $usedCounter
     * receives the matched time step so the caller can persist it.
     */
    public function verify(
        string $secret,
        string $code,
        int $window = 1,
        ?int $lastUsedCounter = null,
        ?int &$usedCounter = null
    ): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $key = $this->base32Decode($secret);
        $current = intdiv(time(), self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $counter = $current + $i;
            if ($lastUsedCounter !== null && $counter <= $lastUsedCounter) {
                continue; // replay of an already-consumed time step
            }
            if (hash_equals($this->hotp($key, $counter), $code)) {
                $usedCounter = $counter;

                return true;
            }
        }

        return false;
    }

    /** @return array{plain: string[], hashes: string[]} one-time recovery codes */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars
            $code = substr($code, 0, 5) . '-' . substr($code, 5);
            $plain[] = $code;
            $hashes[] = password_hash($code, PASSWORD_BCRYPT);
        }

        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Check a recovery code against stored hashes; returns the index of the
     * consumed code (caller must remove it) or null.
     */
    public function matchRecoveryCode(string $input, array $hashes): ?int
    {
        $input = strtoupper(trim($input));
        foreach ($hashes as $i => $hash) {
            if (password_verify($input, (string) $hash)) {
                return $i;
            }
        }

        return null;
    }

    private function hotp(string $key, int $counter): string
    {
        $binary = pack('J', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
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
