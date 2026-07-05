<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Phase 2 one-time (idempotent) data hygiene that must run before the
 * facility_spaces service-line FK is validated:
 *
 *  1. normalize() folds legacy service-line codes to canonical across the three
 *     free-text stores (facility_spaces.service_line_code, flow_events.service_line,
 *     occupancy_snapshots.service_line_counts jsonb rekey).
 *  2. backfill() derives facility_spaces.facility_key from the space_code prefix via
 *     hosp_org.facilities.cad_facility_code, and seeds one primary
 *     facility_space_service_lines bridge row per space that has a service line.
 *
 * Every step is guarded by Schema::hasTable/hasColumn so it degrades gracefully on a
 * partially-migrated database, and re-running is a no-op (only real changes count).
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5, Phase 2)
 */
class ServiceLineBackfiller
{
    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * Fold legacy service-line codes to canonical across the three free-text stores.
     *
     * @return array{facility_spaces:int, flow_events:int, occupancy_snapshots:int}
     */
    public function normalize(): array
    {
        ServiceLineNormalizer::flush();

        return [
            'facility_spaces' => $this->normalizeColumn('hosp_space.facility_spaces', 'service_line_code'),
            'flow_events' => $this->normalizeColumn('flow_core.flow_events', 'service_line'),
            'occupancy_snapshots' => $this->normalizeOccupancyCounts(),
        ];
    }

    /**
     * Backfill facility_keys + one primary bridge row per space.
     *
     * @return array{facility_keys:int, bridge_rows:int}
     */
    public function backfill(): array
    {
        return DB::transaction(fn (): array => [
            'facility_keys' => $this->backfillFacilityKeys(),
            'bridge_rows' => $this->backfillBridge(),
        ]);
    }

    private function normalizeColumn(string $table, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $updated = 0;
        $codes = DB::table($table)->whereNotNull($column)->distinct()->pluck($column);

        foreach ($codes as $code) {
            $canonical = $this->normalizer->canonical((string) $code);
            if ($canonical !== (string) $code) {
                $updated += DB::table($table)->where($column, $code)->update([$column => $canonical]);
            }
        }

        return $updated;
    }

    /**
     * @throws JsonException
     */
    private function normalizeOccupancyCounts(): int
    {
        $table = 'flow_core.occupancy_snapshots';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'service_line_counts')) {
            return 0;
        }

        $updated = 0;

        DB::table($table)
            ->select('occupancy_snapshot_id', 'service_line_counts')
            ->orderBy('occupancy_snapshot_id')
            ->chunk(500, function ($rows) use (&$updated, $table): void {
                foreach ($rows as $row) {
                    $counts = $this->decode($row->service_line_counts);
                    if ($counts === []) {
                        continue;
                    }

                    [$rekeyed, $changed] = $this->rekey($counts);
                    if (! $changed) {
                        continue;
                    }

                    DB::update(
                        "UPDATE {$table} SET service_line_counts = ?::jsonb, updated_at = now() WHERE occupancy_snapshot_id = ?",
                        [json_encode($rekeyed, JSON_THROW_ON_ERROR), $row->occupancy_snapshot_id]
                    );
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, int|float>  $counts
     * @return array{0: array<string, int|float>, 1: bool}
     */
    private function rekey(array $counts): array
    {
        $rekeyed = [];
        $changed = false;

        foreach ($counts as $code => $n) {
            $canonical = $this->normalizer->canonical((string) $code);
            if ($canonical !== (string) $code) {
                $changed = true;
            }
            $rekeyed[$canonical] = ($rekeyed[$canonical] ?? 0) + $n;
        }

        return [$rekeyed, $changed];
    }

    private function backfillFacilityKeys(): int
    {
        if (! Schema::hasTable('hosp_space.facility_spaces') || ! Schema::hasColumn('hosp_space.facility_spaces', 'facility_key')) {
            return 0;
        }

        // cad_facility_code -> facility_key, only available once hosp_org is migrated + seeded.
        $byCad = Schema::hasTable('hosp_org.facilities')
            ? DB::table('hosp_org.facilities')
                ->whereNotNull('cad_facility_code')
                ->pluck('facility_key', 'cad_facility_code')
                ->all()
            : [];

        if ($byCad === []) {
            return 0;
        }

        $updated = 0;
        $spaces = DB::table('hosp_space.facility_spaces')
            ->select('facility_space_id', 'space_code', 'facility_key')
            ->get();

        foreach ($spaces as $space) {
            $prefix = $this->facilityPrefix((string) $space->space_code);
            $facilityKey = $prefix !== null ? ($byCad[$prefix] ?? null) : null;

            if ($facilityKey !== null && $facilityKey !== $space->facility_key) {
                DB::table('hosp_space.facility_spaces')
                    ->where('facility_space_id', $space->facility_space_id)
                    ->update(['facility_key' => $facilityKey, 'updated_at' => now()]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @throws JsonException
     */
    private function backfillBridge(): int
    {
        if (! Schema::hasTable('hosp_space.facility_space_service_lines')) {
            return 0;
        }

        $inserted = 0;
        $spaces = DB::table('hosp_space.facility_spaces')
            ->whereNotNull('service_line_code')
            ->select('facility_space_id', 'service_line_code', 'location_role')
            ->get();

        foreach ($spaces as $space) {
            $code = $this->normalizer->canonical((string) $space->service_line_code);

            // Never create a bridge row that would violate the service_line_code FK.
            if ($code === '' || ! $this->normalizer->isKnown($code)) {
                continue;
            }

            $exists = DB::table('hosp_space.facility_space_service_lines')
                ->where('facility_space_id', $space->facility_space_id)
                ->where('service_line_code', $code)
                ->whereNull('program_code')
                ->exists();

            if ($exists) {
                continue;
            }

            // Claim primary only if the space does not already have one (respects uq_fssl_one_primary).
            $hasPrimary = DB::table('hosp_space.facility_space_service_lines')
                ->where('facility_space_id', $space->facility_space_id)
                ->where('primary_flag', true)
                ->exists();

            DB::insert(
                'INSERT INTO hosp_space.facility_space_service_lines
                    (facility_space_id, service_line_code, location_role, primary_flag, capability_tags, evidence, updated_at)
                 VALUES (?, ?, ?, ?, ?::text[], ?::jsonb, now())',
                [
                    $space->facility_space_id,
                    $code,
                    $space->location_role,
                    ! $hasPrimary,
                    '{}',
                    json_encode(['source' => 'backfill:dominant_service_line'], JSON_THROW_ON_ERROR),
                ]
            );
            $inserted++;
        }

        return $inserted;
    }

    private function facilityPrefix(string $spaceCode): ?string
    {
        if (! str_contains($spaceCode, ':')) {
            return null;
        }

        return substr($spaceCode, 0, (int) strpos($spaceCode, ':'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
