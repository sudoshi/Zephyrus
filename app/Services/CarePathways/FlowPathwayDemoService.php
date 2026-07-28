<?php

declare(strict_types=1);

namespace App\Services\CarePathways;

use Carbon\CarbonImmutable;

/**
 * Builds the synthetic, clearly-labeled "assigned pathway" the 4D Navigator
 * journey drawer shows for one designated demo patient (FLOW-4D plan Phase D5) —
 * the investor-visible artifact of the governed-catalog → conformance bridge.
 *
 * It is honest scaffolding, not clinical guidance:
 *   - Curated milestones are compiled through the SAME pure ReferenceModelCompiler
 *     the governed catalog uses, so the demo exercises the real transformation.
 *   - Progress is derived purely from the patient's length of stay — no DB
 *     writes, no pathway_instances row, no activation.
 *   - The emitted DTO is `clinical_use=false` and carries an explicit
 *     "Demo — not clinical guidance" notice.
 *
 * Gated only by `care-pathways.demo.enabled` (on in dev/investor prod). It never
 * touches — and is independent of — the serving flags (plan G-9).
 */
final class FlowPathwayDemoService
{
    /**
     * Curated Heart-Failure admission→transition milestones, echoing the
     * existing heart-failure-awareness demo scenario. Day offsets drive the
     * length-of-stay progression.
     *
     * @var list<array{stable_key:string, title:string, phase:string, sequence:int, expected_range:array{day_offset_min:int, day_offset_max:int}}>
     */
    private const MILESTONES = [
        ['stable_key' => 'hf_arrival', 'title' => 'Admitted and assessed', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 0]],
        ['stable_key' => 'hf_diuresis', 'title' => 'Diuresis started, daily weights', 'phase' => 'day_1', 'sequence' => 1, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 1]],
        ['stable_key' => 'hf_echo', 'title' => 'Echocardiogram reviewed', 'phase' => 'day_1', 'sequence' => 2, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 1]],
        ['stable_key' => 'hf_gdmt', 'title' => 'Guideline-directed medical therapy optimized', 'phase' => 'day_2', 'sequence' => 3, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 2]],
        ['stable_key' => 'hf_education', 'title' => 'Self-care education and cardiac rehab referral', 'phase' => 'day_3', 'sequence' => 4, 'expected_range' => ['day_offset_min' => 2, 'day_offset_max' => 3]],
        ['stable_key' => 'hf_discharge', 'title' => 'Discharge readiness and follow-up scheduled', 'phase' => 'discharge', 'sequence' => 5, 'expected_range' => ['day_offset_min' => 3, 'day_offset_max' => 4]],
    ];

    public function __construct(
        private readonly ReferenceModelCompiler $compiler,
        private readonly PathwayInstanceReadService $reader,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('care-pathways.demo.enabled');
    }

    /**
     * Project a synthetic, length-of-stay-driven pathway progress DTO. Null when
     * the demo flag is off. Pure aside from the config read + wall clock.
     *
     * @return array<string, mixed>|null
     */
    public function syntheticProgress(int $losDays, ?CarbonImmutable $now = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $model = $this->compiler->compile([
            'pathway_key' => 'demo-heart-failure',
            'label' => 'Heart Failure — Admission to Supported Transition',
            'semantic_version' => 'demo.1',
            'service_line_code' => 'Cardiology',
        ], self::MILESTONES);

        if ($model === null) {
            return null;
        }

        $currentDay = max(0, $losDays);
        $milestones = array_map(
            fn (array $m): array => [
                'stable_key' => $m['stable_key'],
                'title' => $m['title'],
                'phase' => $m['phase'],
                'sequence' => $m['sequence'],
                'status' => $this->statusForDay($m['day_offset_max'] ?? $m['day_offset_min'], $currentDay),
                'observed_at' => null,
                'expected' => [
                    'day_offset_min' => $m['day_offset_min'],
                    'day_offset_max' => $m['day_offset_max'],
                    'display' => $m['display'],
                ],
            ],
            $model['milestones'],
        );

        return $this->reader->assemble(
            demo: true,
            notice: 'Demo — not clinical guidance',
            pathway: [
                'key' => $model['pathway_key'],
                'label' => $model['label'],
                'version_uuid' => null,
                'semantic_version' => $model['semantic_version'],
                'digest' => $model['digest'],
            ],
            milestones: $milestones,
            source: 'synthetic_demo',
            asOf: ($now ?? CarbonImmutable::now())->toJSON(),
        );
    }

    private function statusForDay(?int $day, int $currentDay): string
    {
        return match (true) {
            $day === null => 'planned',
            $day < $currentDay => 'completed',
            $day === $currentDay => 'current',
            default => 'planned',
        };
    }
}
