<?php

namespace App\Services\Rounds;

use App\Models\Rounds\RoundPatient;
use Illuminate\Support\Collection;

/**
 * Deterministic, explainable queue priority (plan §7.2).
 *
 * Every score component emits a reason {code, band, weight, value, source,
 * observed_at, explanation}. Ordering bands (1 rounds earliest):
 *   1 pinned · 2 time-critical · 3 discharge-ready with open work
 *   4 coordination window · 5 missing/overdue input · 6 routine
 *
 * Guardrails: discharge readiness is never inferred from bed pressure or LOS;
 * ranking is never described as a clinical recommendation; stable room/bed
 * ordering is the fallback when signals are absent or stale.
 */
class RoundQueueService
{
    /**
     * @param array{
     *     expected_discharge_today?: bool,
     *     acuity_tier?: int|null,
     *     missing_required_input?: bool,
     *     coordination_window?: bool,
     *     open_task_count?: int,
     * } $signals
     * @return array{band: int, score: float, reasons: list<array<string, mixed>>}
     */
    public function scorePatient(RoundPatient $patient, array $signals, array $priorityPolicy = []): array
    {
        $weights = array_merge(
            (array) config('rounds.priority.weights'),
            (array) ($priorityPolicy['weights'] ?? []),
        );

        $reasons = [];
        $observedAt = now()->toIso8601String();

        if ($patient->pinned_at !== null) {
            $reasons[] = $this->reason(
                'pinned', 1, (float) $weights['pinned'], true, 'manual',
                'Manually pinned: '.($patient->pin_reason ?: 'no reason recorded'), $observedAt,
            );
        }

        $acuity = $signals['acuity_tier'] ?? null;
        if ($acuity !== null && (int) $acuity <= 1) {
            $reasons[] = $this->reason(
                'time_critical_acuity', 2, (float) $weights['time_critical_acuity'], (int) $acuity,
                'census', 'Highest acuity tier on the unit census', $observedAt,
            );
        }

        if (! empty($signals['expected_discharge_today'])) {
            $openWork = (int) ($signals['open_task_count'] ?? 0);
            $reasons[] = $this->reason(
                'discharge_ready', 3, (float) $weights['discharge_ready'], true, 'census',
                $openWork > 0
                    ? "Expected discharge today with {$openWork} open task(s)"
                    : 'Expected discharge today', $observedAt,
            );
        }

        if (! empty($signals['coordination_window'])) {
            $reasons[] = $this->reason(
                'coordination_window', 4, (float) $weights['coordination_window'], true,
                'coordination', 'Family/interpreter/consultant availability window applies', $observedAt,
            );
        }

        if (! empty($signals['missing_required_input'])) {
            $reasons[] = $this->reason(
                'missing_required_input', 5, (float) $weights['missing_required_input'], true,
                'completion_policy', 'Required contribution is missing or overdue', $observedAt,
            );
        }

        $band = $reasons === [] ? 6 : min(array_column($reasons, 'band'));
        $score = array_sum(array_column($reasons, 'weight'));

        if ($reasons === []) {
            $reasons[] = $this->reason(
                'routine', 6, 0.0, null, 'default',
                'No priority signal; ordered by location for an efficient path', $observedAt,
            );
        }

        return ['band' => $band, 'score' => $score, 'reasons' => $reasons];
    }

    /**
     * Order a run's patients deterministically and assign queue positions in
     * memory: active patients by (band asc, score desc, bed label asc, id asc);
     * settled patients (rounded/skipped/deferred) sink below, keeping their
     * relative order stable.
     *
     * @param  Collection<int, RoundPatient>  $patients
     * @return Collection<int, RoundPatient> ordered
     */
    public function orderQueue(Collection $patients): Collection
    {
        [$settled, $active] = $patients->partition(
            fn (RoundPatient $p): bool => in_array($p->status, ['rounded', 'skipped', 'deferred'], true)
        );

        $comparator = function (RoundPatient $a, RoundPatient $b): int {
            return [$a->priority_band, -$a->priority_score, (string) $a->snapshot_bed, $a->round_patient_id]
                <=> [$b->priority_band, -$b->priority_score, (string) $b->snapshot_bed, $b->round_patient_id];
        };

        $ordered = $active->sort($comparator)->values()
            ->concat($settled->sortBy('queue_position')->values());

        $position = 1;
        foreach ($ordered as $patient) {
            $patient->queue_position = $position++;
        }

        return $ordered->values();
    }

    /** @return array<string, mixed> */
    private function reason(
        string $code,
        int $band,
        float $weight,
        mixed $value,
        string $source,
        string $explanation,
        string $observedAt,
    ): array {
        return [
            'code' => $code,
            'band' => $band,
            'weight' => $weight,
            'value' => $value,
            'source' => $source,
            'explanation' => $explanation,
            'observed_at' => $observedAt,
        ];
    }
}
