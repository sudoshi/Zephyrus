<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Nightingale\Demo\NightingaleDemoCohort;
use App\Services\CarePathways\Flow4dPathwayAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class Flow4dAssignDemoPathwaysCommand extends Command
{
    protected $signature = 'flow4d:assign-demo-pathways
        {--confirm= : Must equal assign-demo-cohort-pathways to write}
        {--dry-run : List the planned assignments without writing}';

    protected $description = 'Assign each Nightingale demo-cohort patient (PR #118) their governed pathway + LOS-based milestone statuses, so the 4D Navigator serves REAL progress. Requires the catalog already activated for those pathways.';

    private const CONFIRM = 'assign-demo-cohort-pathways';

    public function handle(Flow4dPathwayAssignmentService $assigner): int
    {
        $grants = PatientEncounterAccessGrant::query()
            ->where('source_system_key', NightingaleDemoCohort::SOURCE_SYSTEM_KEY)
            ->get();

        if ($grants->isEmpty()) {
            $this->warn('No Nightingale demo-cohort grants found. Provision the cohort first (NIGHTINGALE_DEMO_PROVISIONING_ENABLED + its command).');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $plan = [];
        foreach ($grants as $grant) {
            $metadata = is_array($grant->metadata) ? $grant->metadata : [];
            $alias = is_string($metadata['demo_username'] ?? null) ? $metadata['demo_username'] : null;
            $member = $alias !== null ? (NightingaleDemoCohort::MEMBERS[$alias] ?? null) : null;
            if ($member === null || $grant->source_encounter_id === null) {
                continue;
            }
            $plan[] = [
                'alias' => $alias,
                'encounter_id' => (int) $grant->source_encounter_id,
                'pathway_key' => (string) $member['pathway_key'],
            ];
        }

        if ($plan === []) {
            $this->warn('Cohort grants found, but none carried a resolvable demo_username + encounter.');

            return self::SUCCESS;
        }

        $this->table(
            ['Patient', 'Encounter', 'Pathway'],
            array_map(static fn (array $p): array => [$p['alias'], (string) $p['encounter_id'], $p['pathway_key']], $plan),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if ($this->option('confirm') !== self::CONFIRM) {
            $this->error('Refusing to write. Re-run with --confirm='.self::CONFIRM.' once the catalog is activated for these pathways.');

            return self::INVALID;
        }

        $assigned = 0;
        foreach ($plan as $entry) {
            $encounter = Encounter::query()->find($entry['encounter_id']);
            $admittedAt = $encounter?->admitted_at
                ? CarbonImmutable::parse((string) $encounter->admitted_at)
                : $now->subDays(2);

            try {
                $summary = $assigner->assignByPathwayKey($entry['encounter_id'], $entry['pathway_key'], $admittedAt, $now);
                if ($summary === null) {
                    $this->warn(sprintf('  %s: no effective pathway:read grant — skipped', $entry['alias']));

                    continue;
                }
                $this->line(sprintf(
                    '  %s → %s: %d milestones (%d complete, %d current)',
                    $entry['alias'], $entry['pathway_key'], $summary['milestones'], $summary['completed'], $summary['current'],
                ));
                $assigned++;
            } catch (RuntimeException $exception) {
                $this->error(sprintf('  %s: %s', $entry['alias'], $exception->getMessage()));
            }
        }

        $this->info(sprintf('Assigned %d cohort pathway(s).', $assigned));

        return self::SUCCESS;
    }
}
