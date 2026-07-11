<?php

namespace App\Services\Rounds;

use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use Illuminate\Support\Carbon;

/**
 * Completion-policy evaluation (plan §6.4).
 *
 * Templates declare required roles/sections with hard vs soft requirements;
 * waived participant slots excuse a role; freshness windows mark stale input.
 * The output always says exactly WHICH requirement is missing — a progress
 * percentage must never obscure that.
 */
class RoundCompletionService
{
    /** Effective policy: template completion_policy over config defaults. */
    public function policyFor(RoundRun $run): array
    {
        $template = $run->template;

        return array_merge(
            (array) config('rounds.completion'),
            ['required_roles' => $template?->required_roles ?? []],
            (array) ($template?->completion_policy ?? []),
        );
    }

    /**
     * @return array{
     *     satisfied: bool,
     *     missing: list<array{role: string, section: string, requirement: string}>,
     *     stale: list<array{role: string, section: string, submitted_at: string}>,
     *     waived: list<array{role: string, waived_by: int|null, reason: string|null}>,
     *     open_task_count: int,
     * }
     */
    public function evaluatePatient(RoundPatient $patient, ?array $policy = null): array
    {
        $policy ??= $this->policyFor($patient->run);
        $freshnessHours = (int) ($policy['freshness_hours'] ?? 24);

        $submitted = $patient->contributions()
            ->where('status', 'submitted')
            ->get(['author_role', 'section_code', 'submitted_at']);

        $waivedRoles = $patient->run->participants()
            ->where('status', 'waived')
            ->where(function ($q) use ($patient) {
                $q->whereNull('round_patient_id')->orWhere('round_patient_id', $patient->round_patient_id);
            })
            ->get(['role_code', 'waived_by', 'waiver_reason']);

        $waived = $waivedRoles->map(fn ($p) => [
            'role' => $p->role_code,
            'waived_by' => $p->waived_by,
            'reason' => $p->waiver_reason,
        ])->all();
        $waivedRoleCodes = $waivedRoles->pluck('role_code')->all();

        $missing = [];
        $stale = [];

        foreach ((array) ($policy['required_roles'] ?? []) as $requirement) {
            $role = $requirement['role_code'] ?? null;
            if ($role === null || in_array($role, $waivedRoleCodes, true)) {
                continue;
            }

            $kind = $requirement['requirement'] ?? 'hard';

            foreach ((array) ($requirement['sections'] ?? []) as $section) {
                $match = $submitted->first(
                    fn ($c) => $c->author_role === $role && $c->section_code === $section
                );

                if ($match === null) {
                    $missing[] = ['role' => $role, 'section' => $section, 'requirement' => $kind];

                    continue;
                }

                $submittedAt = $match->submitted_at ? Carbon::parse($match->submitted_at) : null;
                if ($submittedAt !== null && $submittedAt->lt(now()->subHours($freshnessHours))) {
                    $stale[] = [
                        'role' => $role,
                        'section' => $section,
                        'submitted_at' => $submittedAt->toIso8601String(),
                    ];
                }
            }
        }

        $openTasks = $patient->tasks()->whereIn('status', ['open', 'in_progress'])->count();

        $hardMissing = array_values(array_filter($missing, fn ($m) => $m['requirement'] === 'hard'));
        $blockedByTasks = ! empty($policy['block_on_open_tasks']) && $openTasks > 0;

        return [
            'satisfied' => $hardMissing === [] && ! $blockedByTasks,
            'missing' => $missing,
            'stale' => $stale,
            'waived' => $waived,
            'open_task_count' => $openTasks,
        ];
    }

    /**
     * Whether the run may complete: every patient must be in an allowed
     * terminal-ish state (rounded/skipped/deferred) unless an authorized
     * exception is recorded (plan §6.2).
     *
     * @return array{can_complete: bool, blocking: list<string>, counts: array<string, int>}
     */
    public function evaluateRun(RoundRun $run): array
    {
        $patients = $run->patients()->get(['round_patient_uuid', 'status']);

        $blocking = $patients
            ->reject(fn ($p) => in_array($p->status, ['rounded', 'skipped', 'deferred'], true))
            ->pluck('round_patient_uuid')
            ->all();

        return [
            'can_complete' => $blocking === [],
            'blocking' => $blocking,
            'counts' => $patients->countBy('status')->all(),
        ];
    }
}
