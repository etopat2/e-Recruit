<?php

namespace App\Support;

use BackedEnum;
use JsonSerializable;

class CanonicalJson
{
    public function encode(mixed $value): string
    {
        return json_encode(
            $this->normalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    public function normalise(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalise($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->normalise($value->toArray());
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalise($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->normalise($item), $value);
    }
}
