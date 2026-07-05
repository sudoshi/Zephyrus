<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL `text[]` column to/from a PHP list of strings.
 *
 * Laravel's built-in `array` cast round-trips JSON (`["a","b"]`), which is wrong
 * for native Postgres arrays (stored as `{a,b,"c d"}`). This cast reads the
 * Postgres array-literal form into a PHP list and emits it back.
 *
 * Read path (get) is always safe. For writes, the sanctioned bulk path is
 * App\Services\Deployment\ServiceLineRegistrar, which binds array params with an
 * explicit `?::text[]` cast; single-model Eloquent writes of array attributes
 * should go through that service to avoid driver-dependent text→text[] coercion.
 */
class PgTextArray implements CastsAttributes
{
    /**
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return self::parse($value === null ? null : (string) $value);
    }

    /**
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $values = $value === null ? [] : (is_array($value) ? $value : [$value]);

        return [$key => self::literal($values)];
    }

    /**
     * Parse a Postgres array literal (`{a,b,"c d"}`) into a PHP list of strings.
     *
     * @return list<string>
     */
    public static function parse(?string $literal): array
    {
        if ($literal === null) {
            return [];
        }

        $literal = trim($literal);
        if ($literal === '' || $literal === '{}') {
            return [];
        }

        if (str_starts_with($literal, '{') && str_ends_with($literal, '}')) {
            $literal = substr($literal, 1, -1);
        }

        $result = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($literal);

        for ($i = 0; $i < $length; $i++) {
            $ch = $literal[$i];

            if ($inQuotes) {
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $literal[$i + 1];
                    $i++;
                } elseif ($ch === '"') {
                    $inQuotes = false;
                } else {
                    $current .= $ch;
                }

                continue;
            }

            if ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        $result[] = $current;

        return array_values(array_filter(
            array_map('trim', $result),
            static fn (string $v): bool => $v !== '' && strcasecmp($v, 'NULL') !== 0
        ));
    }

    /**
     * Format a PHP list into a Postgres array literal (`{a,b,"c d"}`).
     *
     * @param  array<int, mixed>  $values
     */
    public static function literal(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $parts = array_map(static function ($value): string {
            $string = (string) $value;

            if ($string === '' || preg_match('/[{}",\\\\\s]/', $string) === 1) {
                return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $string).'"';
            }

            return $string;
        }, array_values($values));

        return '{'.implode(',', $parts).'}';
    }
}
