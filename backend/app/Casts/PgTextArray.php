<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** A PostgreSQL text[] column as a PHP list of strings. */
class PgTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}' || $value === '') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/u', $value, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => stripcslashes(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? '')), $m);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_map(fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"', array_values((array) $value));

        return '{'.implode(',', $items).'}';
    }
}
