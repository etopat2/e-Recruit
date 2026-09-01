<?php

namespace App\Services;

use Illuminate\Support\Str;

class TotpService
{
    private const Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'UPS e-Recruit'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            rawurlencode($secret),
            rawurlencode($issuer),
        );
    }

    /** @return array{plain: list<string>, hashed: list<string>} */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];
        for ($index = 0; $index < $count; $index++) {
            $code = mb_strtoupper(Str::random(5).'-'.Str::random(5));
            $plain[] = $code;
            $hashed[] = hash('sha256', $code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /** @param list<string> $hashedCodes
     * @return array{valid: bool, remaining: list<string>}
     */
    public function consumeRecoveryCode(array $hashedCodes, string $providedCode): array
    {
        $needle = hash('sha256', mb_strtoupper(trim($providedCode)));
        foreach ($hashedCodes as $index => $hash) {
            if (hash_equals($hash, $needle)) {
                unset($hashedCodes[$index]);

                return ['valid' => true, 'remaining' => array_values($hashedCodes)];
            }
        }

        return ['valid' => false, 'remaining' => $hashedCodes];
    }

    private function code(string $secret, int $counter): string
    {
        $binaryCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $input): string
    {
        $bits = '';
        foreach (str_split($input) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::Alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $input): string
    {
        $bits = '';
        foreach (str_split(mb_strtoupper(rtrim($input, '='))) as $character) {
            $position = strpos(self::Alphabet, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
