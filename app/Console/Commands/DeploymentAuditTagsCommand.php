<?php

namespace App\Console\Commands;

use App\Casts\PgTextArray;
use App\Services\Deployment\CapabilityTagBackfiller;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrity audit for capability tags on prod.beds / prod.rooms:
 *  - orphan tags: values not present in hosp_ref.capability_tags (a real defect -> exit 1);
 *  - beds missing their unit's expected acuity tags (advisory -> warning only).
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 3)
 */
class DeploymentAuditTagsCommand extends Command
{
    protected $signature = 'deployment:audit-tags';

    protected $description = 'Audit prod.beds/prod.rooms capability tags for orphans (not in registry) and beds missing expected acuity tags.';

    public function handle(CapabilityTagBackfiller $backfiller): int
    {
        if (! Schema::hasTable('hosp_ref.capability_tags')) {
            $this->error('hosp_ref.capability_tags is not migrated; run deployment:seed-registry first.');

            return self::FAILURE;
        }

        $registered = DB::table('hosp_ref.capability_tags')
            ->pluck('tag_code')
            ->mapWithKeys(fn ($code): array => [(string) $code => true])
            ->all();

        $bedOrphans = $this->orphanTags('prod.beds', $registered);
        $roomOrphans = $this->orphanTags('prod.rooms', $registered);
        $missing = $this->bedsMissingExpectedTags($backfiller);

        $this->info('Capability-tag audit');
        $this->line('Registered tags:      '.count($registered));
        $this->line('Bed orphan tags:      '.(empty($bedOrphans) ? '0' : implode(', ', $bedOrphans)));
        $this->line('Room orphan tags:     '.(empty($roomOrphans) ? '0' : implode(', ', $roomOrphans)));

        if ($missing !== []) {
            $this->warn('Beds missing expected acuity tags:');
            foreach ($missing as $row) {
                $this->line(sprintf('  %-8s %d bed(s) missing [%s]', $row['unit'], $row['missing_beds'], implode(', ', $row['expected'])));
            }
        } else {
            $this->line('Beds missing expected: 0');
        }

        $orphanCount = count($bedOrphans) + count($roomOrphans);
        if ($orphanCount > 0) {
            $this->error("Found {$orphanCount} orphan tag(s) not present in hosp_ref.capability_tags.");

            return self::FAILURE;
        }

        $this->info('No orphan tags.');

        return self::SUCCESS;
    }

    /**
     * Distinct tags on the table that are not registered in hosp_ref.capability_tags.
     *
     * @param  array<string, bool>  $registered
     * @return list<string>
     */
    private function orphanTags(string $table, array $registered): array
    {
        if (! Schema::hasColumn($table, 'capability_tags')) {
            return [];
        }

        $tags = DB::table($table)
            ->whereRaw('cardinality(capability_tags) > 0')
            ->distinct()
            ->selectRaw('unnest(capability_tags) as tag')
            ->pluck('tag')
            ->map(fn ($tag): string => (string) $tag)
            ->all();

        return array_values(array_filter($tags, fn (string $tag): bool => ! isset($registered[$tag])));
    }

    /**
     * @return list<array{unit:string, expected:list<string>, missing_beds:int}>
     */
    private function bedsMissingExpectedTags(CapabilityTagBackfiller $backfiller): array
    {
        if (! Schema::hasColumn('prod.beds', 'capability_tags')) {
            return [];
        }

        $rows = [];

        foreach (app(HospitalManifest::class)->units() as $unit) {
            $abbr = $unit['abbr'] ?? null;
            $expected = $backfiller->defaultBedTags($unit['acuity'] ?? null, $unit['service_line'] ?? null, $unit['type'] ?? null);
            if ($abbr === null || $expected === []) {
                continue;
            }

            $unitId = DB::table('prod.units')
                ->where('abbreviation', $abbr)
                ->where('is_deleted', false)
                ->value('unit_id');
            if ($unitId === null) {
                continue;
            }

            $missing = DB::table('prod.beds')
                ->where('unit_id', $unitId)
                ->where('is_deleted', false)
                ->whereRaw('NOT (capability_tags @> ?::text[])', [PgTextArray::literal($expected)])
                ->count();

            if ($missing > 0) {
                $rows[] = ['unit' => (string) $abbr, 'expected' => $expected, 'missing_beds' => $missing];
            }
        }

        return $rows;
    }
}
