<?php

namespace App\Services\Rounds;

use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Transparent, static duration estimates (plan §7.3).
 *
 *   patient duration = template default + complexity + unresolved-input
 *   patient window   = cumulative preceding durations + uncertainty buffer
 *
 * Windows are ranges, never false precision. shouldNotify() implements the
 * damping threshold so small shifts do not generate notification noise.
 * A learned per-unit model replaces the static adjustments in Phase 8 only.
 */
class RoundEtaService
{
    /**
     * @param  array{acuity_tier?: int|null, missing_required_input?: bool, coordination?: bool}  $signals
     * @param  array<string, mixed>  $etaPolicy  template override of config('rounds.eta')
     * @return array{minutes: int, components: list<array{code: string, minutes: int, explanation: string}>}
     */
    public function estimateDuration(array $signals, array $etaPolicy = []): array
    {
        $cfg = array_merge((array) config('rounds.eta'), $etaPolicy);

        $components = [[
            'code' => 'template_default',
            'minutes' => (int) $cfg['default_duration_minutes'],
            'explanation' => 'Template default duration',
        ]];

        $acuity = $signals['acuity_tier'] ?? null;
        if ($acuity !== null && (int) $acuity < 3) {
            $steps = 3 - (int) $acuity; // tier 1 = sickest
            $minutes = $steps * (int) $cfg['complexity_minutes_per_acuity_step'];
            $components[] = [
                'code' => 'complexity',
                'minutes' => $minutes,
                'explanation' => "Acuity tier {$acuity} complexity adjustment",
            ];
        }

        if (! empty($signals['missing_required_input'])) {
            $components[] = [
                'code' => 'unresolved_input',
                'minutes' => (int) $cfg['unresolved_input_minutes'],
                'explanation' => 'Required round input still missing',
            ];
        }

        if (! empty($signals['coordination'])) {
            $components[] = [
                'code' => 'coordination',
                'minutes' => (int) $cfg['coordination_minutes'],
                'explanation' => 'Family/interpreter/consultant coordination expected',
            ];
        }

        return [
            'minutes' => array_sum(array_column($components, 'minutes')),
            'components' => $components,
        ];
    }

    /**
     * Assign cumulative ETA windows to an ordered queue, in memory. Patients
     * already settled (rounded/skipped/deferred) get no window.
     *
     * @param  Collection<int, RoundPatient>  $orderedPatients
     */
    public function assignWindows(RoundRun $run, Collection $orderedPatients, array $etaPolicy = []): void
    {
        $cfg = array_merge((array) config('rounds.eta'), $etaPolicy);
        $buffer = (int) $cfg['uncertainty_buffer_minutes'];

        $cursor = Carbon::instance($run->started_at ?? $run->planned_start_at ?? now());
        $cumulative = 0;

        foreach ($orderedPatients as $patient) {
            if (in_array($patient->status, ['rounded', 'skipped', 'deferred'], true)) {
                $patient->eta_window_start = null;
                $patient->eta_window_end = null;

                continue;
            }

            $duration = (int) ($patient->estimated_duration_minutes
                ?? $cfg['default_duration_minutes']);

            $patient->eta_window_start = $cursor->copy()->addMinutes($cumulative);
            $patient->eta_window_end = $cursor->copy()->addMinutes($cumulative + $duration + $buffer);
            $cumulative += $duration;
        }
    }

    /** Damping: notify only when the window start moved past the threshold. */
    public function shouldNotify(?CarbonInterface $previousStart, ?CarbonInterface $newStart, array $etaPolicy = []): bool
    {
        if ($previousStart === null || $newStart === null) {
            return false;
        }

        $cfg = array_merge((array) config('rounds.eta'), $etaPolicy);

        return abs($previousStart->diffInMinutes($newStart, false)) >= (int) $cfg['notify_threshold_minutes'];
    }
}
