<?php

namespace App\Services\Deployment;

use App\Casts\PgTextArray;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: seeds default capability tags onto prod.beds from a unit's acuity /
 * service line (the client roster later overrides). Pure heuristic in
 * defaultBedTags(); writes are raw ?::text[] and non-destructive — a bed that
 * already carries tags is never overwritten, so this is idempotent and safe to
 * re-run and safe against client customizations.
 *
 * Every tag returned by the heuristic is a registered, bed-applicable
 * hosp_ref.capability_tags code; deployment:audit-tags catches any drift.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 3)
 */
class CapabilityTagBackfiller
{
    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * Default bed capability tags for a unit's acuity + service line (+ optional bed_type).
     *
     * @return list<string> registered, bed-applicable tag codes (may be empty)
     */
    public function defaultBedTags(?string $acuity, ?string $serviceLine = null, ?string $bedType = null): array
    {
        $acuity = strtolower(trim((string) $acuity));
        $serviceLine = $serviceLine !== null && $serviceLine !== ''
            ? $this->normalizer->canonical(strtolower(trim($serviceLine)))
            : '';
        $bedType = strtolower(trim((string) $bedType));

        // Neonatal takes precedence: a NICU carries acuity 'pediatrics' but is not a peds floor.
        if ($serviceLine === 'neonatology') {
            return $this->finalize(['neonatal', 'medical_gas']);
        }

        $tags = match ($acuity) {
            'icu' => ['ventilator', 'telemetry', 'medical_gas'],
            'burn_icu' => ['ventilator', 'telemetry', 'medical_gas', 'burn'],
            'telemetry', 'stepdown', 'step_down' => ['telemetry', 'medical_gas'],
            'behavioral' => ['behavioral_safe'],
            'women' => ['medical_gas'], // 'ob' is a room-level tag, not a bed tag
            'pediatrics' => ['pediatric', 'medical_gas'],
            'oncology', 'med_surg', 'emergency' => ['medical_gas'],
            default => [],
        };

        // bed_type refinements (the catalog importer sets bed_type; RtdcSeeder does not).
        $tags = match ($bedType) {
            'icu' => array_merge($tags, ['ventilator', 'telemetry', 'medical_gas']),
            'step_down' => array_merge($tags, ['telemetry', 'medical_gas']),
            'behavioral_health' => array_merge($tags, ['behavioral_safe']),
            default => $tags,
        };

        if ($serviceLine === 'burn') {
            $tags[] = 'burn';
        }

        return $this->finalize($tags);
    }

    /**
     * Seed default tags onto every bed of the whole Summit roster from the manifest.
     *
     * @return array{units:int, beds:int}
     */
    public function backfillFromManifest(): array
    {
        if (! $this->bedTagsColumnExists()) {
            return ['units' => 0, 'beds' => 0];
        }

        $unitsTouched = 0;
        $bedsTagged = 0;

        foreach (app(HospitalManifest::class)->units() as $unit) {
            $abbr = $unit['abbr'] ?? null;
            if ($abbr === null) {
                continue;
            }

            $unitId = DB::table('prod.units')
                ->where('abbreviation', $abbr)
                ->where('is_deleted', false)
                ->value('unit_id');

            if ($unitId === null) {
                continue;
            }

            $tags = $this->defaultBedTags($unit['acuity'] ?? null, $unit['service_line'] ?? null, $unit['type'] ?? null);
            if ($tags === []) {
                continue;
            }

            $updated = $this->applyToUnitBeds((int) $unitId, $tags);
            if ($updated > 0) {
                $unitsTouched++;
                $bedsTagged += $updated;
            }
        }

        return ['units' => $unitsTouched, 'beds' => $bedsTagged];
    }

    /**
     * Set default tags on a unit's beds that do not already carry tags (non-destructive).
     */
    public function applyToUnitBeds(int $unitId, array $tags): int
    {
        if ($tags === [] || ! $this->bedTagsColumnExists()) {
            return 0;
        }

        return DB::update(
            'UPDATE prod.beds SET capability_tags = ?::text[]
             WHERE unit_id = ? AND is_deleted = false AND cardinality(capability_tags) = 0',
            [PgTextArray::literal($tags), $unitId]
        );
    }

    /**
     * Set default tags on a single bed if it does not already carry tags.
     */
    public function applyToBed(int $bedId, array $tags): int
    {
        if ($tags === [] || ! $this->bedTagsColumnExists()) {
            return 0;
        }

        return DB::update(
            'UPDATE prod.beds SET capability_tags = ?::text[]
             WHERE bed_id = ? AND cardinality(capability_tags) = 0',
            [PgTextArray::literal($tags), $bedId]
        );
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function finalize(array $tags): array
    {
        return array_values(array_unique($tags));
    }

    private function bedTagsColumnExists(): bool
    {
        return Schema::hasColumn('prod.beds', 'capability_tags');
    }
}
