<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;

/** Keyset cursor for lists ordered newest first: the (created_at, id) of the last row of the previous page. */
final class Cursor
{
    public static function encode(\DateTimeInterface $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$createdAt->format('Y-m-d H:i:s.uP'), $id])), '+/', '-_'), '=');
    }

    /** @return array{string, string} */
    public static function decode(string $cursor, string $errorCode): array
    {
        $parts = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($parts) || count($parts) !== 2 || ! Str::isUuid((string) $parts[1]) || strtotime((string) $parts[0]) === false) {
            throw new ApiException($errorCode, 422);
        }

        return [$parts[0], $parts[1]];
    }
}
