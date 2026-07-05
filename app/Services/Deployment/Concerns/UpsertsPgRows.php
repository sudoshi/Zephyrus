<?php

namespace App\Services\Deployment\Concerns;

use App\Casts\PgTextArray;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Raw, parameterized, idempotent upsert helpers for the hosp_org / hosp_ref
 * deployment importers. Array columns bind with `?::text[]` and jsonb columns
 * with `?::jsonb` to avoid driver-dependent text->text[]/jsonb coercion — the
 * same pattern proven in ServiceLineRegistrar.
 */
trait UpsertsPgRows
{
    /**
     * Upsert one row keyed on a unique constraint.
     *
     * @param  array<string, mixed>  $row  column => value (never include created_at/updated_at)
     * @param  list<string>  $conflictKeys  columns of the target unique constraint
     * @param  list<string>  $arrayColumns  text[] columns in $row
     * @param  list<string>  $jsonColumns  jsonb columns in $row
     */
    protected function upsertRow(string $table, array $row, array $conflictKeys, array $arrayColumns = [], array $jsonColumns = []): void
    {
        $columns = array_keys($row);

        $placeholders = array_map(function (string $col) use ($arrayColumns, $jsonColumns): string {
            if (in_array($col, $arrayColumns, true)) {
                return '?::text[]';
            }
            if (in_array($col, $jsonColumns, true)) {
                return '?::jsonb';
            }

            return '?';
        }, $columns);

        $setClauses = array_map(
            static fn (string $col): string => "{$col} = EXCLUDED.{$col}",
            array_values(array_diff($columns, $conflictKeys))
        );
        $setClauses[] = 'updated_at = now()';

        $sql = sprintf(
            'INSERT INTO %s (%s, updated_at) VALUES (%s, now()) ON CONFLICT (%s) DO UPDATE SET %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $conflictKeys),
            implode(', ', $setClauses)
        );

        DB::statement($sql, $this->bindingsFor($row, $columns, $arrayColumns, $jsonColumns));
    }

    /**
     * Build ordered bindings, formatting text[] and jsonb values.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @param  list<string>  $arrayColumns
     * @param  list<string>  $jsonColumns
     * @return list<mixed>
     */
    protected function bindingsFor(array $row, array $columns, array $arrayColumns, array $jsonColumns): array
    {
        return array_map(function (string $col) use ($row, $arrayColumns, $jsonColumns) {
            $value = $row[$col];

            if (in_array($col, $arrayColumns, true)) {
                $list = is_array($value) ? $value : ($value === null ? [] : [$value]);

                return PgTextArray::literal($list);
            }

            if (in_array($col, $jsonColumns, true)) {
                try {
                    return json_encode($value ?? (object) [], JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Unable to encode jsonb column {$col}: ".$e->getMessage());
                }
            }

            return $value;
        }, $columns);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    protected function readJsonFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Import file is not readable: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read import file: {$path}");
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("Import file must decode to an object: {$path}");
        }

        return $data;
    }
}
