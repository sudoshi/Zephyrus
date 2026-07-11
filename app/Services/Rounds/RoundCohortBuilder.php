<?php

namespace App\Services\Rounds;

use App\Models\Encounter;
use App\Models\PatientFlow\FlowEncounter;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundParticipant;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use App\Models\Rounds\RoundTemplate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Resolves eligible active encounters for a run's scope at one source cutoff
 * (plan §7.1): dedupes by encounter, records inclusion reasons and source
 * freshness, adds required role slots from the template plus current unit
 * staff assignments, and produces the initial ordered queue. Runs inside the
 * caller's transaction (RoundCommandService::createRun).
 *
 * The census source is prod.encounters (the operational spine). When the
 * flow_core bridge exists the canonical flow encounter_ref is used; otherwise
 * a deterministic `prodenc:{id}` ref keeps enrollment independent of the flow
 * spine's population. ADT changes after the cutoff never rewrite the cohort —
 * reconciliation produces explicit suggestions instead.
 */
class RoundCohortBuilder
{
    public function __construct(
        private readonly RoundQueueService $queue,
        private readonly RoundEtaService $eta,
    ) {}

    /**
     * @return array{enrolled: int, excluded: list<array{encounter_id: int, reason: string}>}
     */
    public function build(RoundRun $run, RoundTemplate $template, Unit $unit, ?User $actor): array
    {
        $cutoff = now();
        $encounters = Encounter::query()
            ->active()
            ->where('unit_id', $unit->unit_id)
            ->with('bed')
            ->orderBy('encounter_id')
            ->get();

        $flowRefs = $this->bridgedFlowRefs($encounters->pluck('encounter_id')->all());

        $excluded = [];
        $patients = collect();

        foreach ($encounters as $encounter) {
            if (! $encounter->patient_ref) {
                $excluded[] = ['encounter_id' => $encounter->encounter_id, 'reason' => 'missing_patient_ref'];

                continue;
            }

            $flow = $flowRefs[$encounter->encounter_id] ?? null;
            $encounterRef = $flow['encounter_ref'] ?? 'prodenc:'.$encounter->encounter_id;

            // Dedupe by encounter within the run (also enforced by the
            // partial unique index uq_rounds_patients_run_encounter).
            if ($patients->contains(fn (RoundPatient $p) => $p->encounter_ref === $encounterRef)) {
                $excluded[] = ['encounter_id' => $encounter->encounter_id, 'reason' => 'duplicate_encounter'];

                continue;
            }

            $signals = [
                'acuity_tier' => $encounter->acuity_tier,
                'expected_discharge_today' => $encounter->expected_discharge_date !== null
                    && $encounter->expected_discharge_date->lte(now()->endOfDay()),
                'coordination_window' => false,
                'missing_required_input' => $template->required_roles !== [],
                'open_task_count' => 0,
            ];

            $priority = $this->queue->scorePatient(new RoundPatient, $signals, (array) $template->priority_policy);
            $duration = $this->eta->estimateDuration([
                'acuity_tier' => $encounter->acuity_tier,
                'missing_required_input' => $signals['missing_required_input'],
            ], (array) $template->eta_policy);

            $patients->push(new RoundPatient([
                'round_patient_uuid' => (string) Str::uuid(),
                'run_id' => $run->run_id,
                'encounter_ref' => $encounterRef,
                'prod_encounter_id' => $encounter->encounter_id,
                'patient_ref' => $encounter->patient_ref,
                'snapshot_unit_id' => $unit->unit_id,
                'snapshot_facility_space_id' => $unit->facility_space_id,
                'snapshot_service_line_code' => $flow['service_line'] ?? null,
                'snapshot_room' => null,
                'snapshot_bed' => $encounter->bed?->label,
                'status' => 'queued',
                'priority_score' => $priority['score'],
                'priority_band' => $priority['band'],
                'priority_reasons' => $priority['reasons'],
                'estimated_duration_minutes' => $duration['minutes'],
                'inclusion' => [
                    'included_at' => $cutoff->toIso8601String(),
                    'source' => 'prod_census',
                    'reasons' => ['active_census'],
                    'flow_bridged' => $flow !== null,
                ],
                'version' => 1,
                'metadata' => [
                    'signals' => $signals,
                    'duration_components' => $duration['components'],
                ],
            ]));
        }

        $ordered = $this->queue->orderQueue($patients);
        $this->eta->assignWindows($run, $ordered, (array) $template->eta_policy);

        foreach ($ordered as $patient) {
            $patient->save();
        }

        $this->createRoleSlots($run, $template, $unit);

        $run->forceFill([
            'source_cutoff_at' => $cutoff,
            'metadata' => array_merge((array) $run->metadata, [
                'cohort' => [
                    'enrolled' => $ordered->count(),
                    'excluded' => $excluded,
                    'built_at' => $cutoff->toIso8601String(),
                ],
            ]),
        ])->save();

        RoundEvent::record(
            'run', $run->run_id, $run->run_uuid, $run->queue_version,
            $actor?->id, 'cohort.built',
            ['enrolled' => $ordered->count(), 'excluded_count' => count($excluded)],
        );

        return ['enrolled' => $ordered->count(), 'excluded' => $excluded];
    }

    /**
     * Current active census delta against the enrolled cohort — explicit
     * suggestions only, never a silent rewrite (plan §7.1).
     *
     * @return array{
     *     add: list<array{encounter_ref: string, prod_encounter_id: int, bed: string|null}>,
     *     remove: list<array{round_patient_uuid: string, reason: string}>,
     * }
     */
    public function suggestReconciliation(RoundRun $run, Unit $unit): array
    {
        $current = Encounter::query()->active()->where('unit_id', $unit->unit_id)->with('bed')->get();
        $flowRefs = $this->bridgedFlowRefs($current->pluck('encounter_id')->all());

        $enrolled = $run->patients()->get(['round_patient_uuid', 'encounter_ref', 'prod_encounter_id', 'status']);
        $enrolledRefs = $enrolled->pluck('encounter_ref')->all();

        $add = [];
        foreach ($current as $encounter) {
            $ref = ($flowRefs[$encounter->encounter_id]['encounter_ref'] ?? null) ?? 'prodenc:'.$encounter->encounter_id;
            if (! in_array($ref, $enrolledRefs, true)) {
                $add[] = [
                    'encounter_ref' => $ref,
                    'prod_encounter_id' => $encounter->encounter_id,
                    'bed' => $encounter->bed?->label,
                ];
            }
        }

        $currentIds = $current->pluck('encounter_id')->all();
        $remove = [];
        foreach ($enrolled as $patient) {
            $stillActive = $patient->prod_encounter_id !== null
                && in_array((int) $patient->prod_encounter_id, $currentIds, true);
            if (! $stillActive && ! in_array($patient->status, ['rounded', 'skipped'], true)) {
                $remove[] = [
                    'round_patient_uuid' => $patient->round_patient_uuid,
                    'reason' => 'no_longer_on_active_census',
                ];
            }
        }

        return ['add' => $add, 'remove' => $remove];
    }

    /**
     * Run-level role slots from the template, auto-assigned to current unit
     * staff where prod.user_unit pivot roles map onto contributor roles.
     */
    private function createRoleSlots(RoundRun $run, RoundTemplate $template, Unit $unit): void
    {
        $assignedByRole = $this->unitUsersByContributorRole($unit);

        foreach ((array) $template->required_roles as $requirement) {
            $role = $requirement['role_code'] ?? null;
            if ($role === null) {
                continue;
            }

            $users = $assignedByRole[$role] ?? [null];

            foreach ($users as $userId) {
                RoundParticipant::create([
                    'participant_uuid' => (string) Str::uuid(),
                    'run_id' => $run->run_id,
                    'round_patient_id' => null,
                    'user_id' => $userId,
                    'role_code' => $role,
                    'required' => ($requirement['requirement'] ?? 'hard') === 'hard',
                    'status' => 'pending',
                ]);
            }
        }
    }

    /** @return array<string, list<int>> contributor role_code => user ids on this unit */
    private function unitUsersByContributorRole(Unit $unit): array
    {
        if (! Schema::hasTable('prod.user_unit')) {
            return [];
        }

        $map = (array) config('rounds.unit_role_map', []);
        $result = [];

        $rows = \Illuminate\Support\Facades\DB::table('prod.user_unit')
            ->where('unit_id', $unit->unit_id)
            ->whereNotNull('role')
            ->get(['user_id', 'role']);

        foreach ($rows as $row) {
            $contributorRole = $map[$row->role] ?? null;
            if ($contributorRole !== null) {
                $result[$contributorRole][] = (int) $row->user_id;
            }
        }

        return $result;
    }

    /** @return array<int, array{encounter_ref: string, service_line: string|null}> keyed by prod encounter_id */
    private function bridgedFlowRefs(array $prodEncounterIds): array
    {
        if ($prodEncounterIds === [] || ! Schema::hasTable('flow_core.encounters')) {
            return [];
        }

        return FlowEncounter::query()
            ->whereIn('prod_encounter_id', $prodEncounterIds)
            ->get(['encounter_ref', 'prod_encounter_id', 'service_line'])
            ->keyBy('prod_encounter_id')
            ->map(fn (FlowEncounter $f) => [
                'encounter_ref' => $f->encounter_ref,
                'service_line' => $f->service_line,
            ])
            ->all();
    }
}
