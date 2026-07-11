<?php

namespace Database\Seeders;

use App\Models\Rounds\RoundTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Pilot round templates (plan §6.1, §15 Phase 1). Idempotent by (name,
 * version); template_uuid is minted once and never rotated on reseed.
 * Clinical owners approve the required roles and completion policy before
 * production activation (Phase 0 gate).
 */
class RoundTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('Unit Multidisciplinary Round', 1, [
            'description' => 'Asynchronous daily multidisciplinary round for one inpatient unit. '
                .'Nursing and attending inputs are required; pharmacy and case management are soft requirements.',
            'scope_types' => '{unit}',
            'mode' => 'async',
            'required_roles' => [
                ['role_code' => 'bedside_nurse', 'sections' => ['overnight_events'], 'requirement' => 'hard'],
                ['role_code' => 'attending', 'sections' => ['clinical_plan'], 'requirement' => 'hard'],
                ['role_code' => 'pharmacist', 'sections' => ['medications'], 'requirement' => 'soft'],
                ['role_code' => 'case_manager', 'sections' => ['disposition_planning'], 'requirement' => 'soft'],
            ],
            'completion_policy' => [
                'freshness_hours' => 24,
                'require_leader_attestation' => true,
                'block_on_open_tasks' => false,
            ],
            'priority_policy' => [],
            'eta_policy' => [],
            'active' => true,
        ]);

        $this->upsert('Discharge Focus Round', 1, [
            'description' => 'Focused discharge-barrier review: case management leads, attending confirms disposition.',
            'scope_types' => '{unit}',
            'mode' => 'async',
            'required_roles' => [
                ['role_code' => 'case_manager', 'sections' => ['disposition_planning'], 'requirement' => 'hard'],
                ['role_code' => 'attending', 'sections' => ['clinical_plan'], 'requirement' => 'hard'],
            ],
            'completion_policy' => [
                'freshness_hours' => 12,
                'require_leader_attestation' => true,
                'block_on_open_tasks' => true,
            ],
            'priority_policy' => [],
            'eta_policy' => ['default_duration_minutes' => 5],
            'active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function upsert(string $name, int $version, array $attributes): void
    {
        $template = RoundTemplate::firstOrNew(['name' => $name, 'version' => $version]);

        if (! $template->exists) {
            $template->template_uuid = (string) Str::uuid();
        }

        $template->fill($attributes)->save();
    }
}
