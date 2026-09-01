<?php

namespace App\Support;

use InvalidArgumentException;

class Nin
{
    public static function normalise(string $nin): string
    {
        return mb_strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($nin)) ?? '');
    }

    public static function validate(string $nin): string
    {
        $normalised = self::normalise($nin);

        if (! preg_match('/^[A-Z]{2}[A-Z0-9]{10,18}$/', $normalised)) {
            throw new InvalidArgumentException('The NIN format is invalid.');
        }

        return $normalised;
    }

    public static function fingerprint(string $nin): string
    {
        return hash_hmac('sha256', self::validate($nin), (string) config('app.key'));
    }

    public static function mask(string $nin): string
    {
        $normalised = self::normalise($nin);
        if (mb_strlen($normalised) < 6) {
            return str_repeat('*', mb_strlen($normalised));
        }

        return mb_substr($normalised, 0, 2).str_repeat('*', mb_strlen($normalised) - 5).mb_substr($normalised, -3);
    }
}
