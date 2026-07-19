<?php

namespace App\Services\Rounds;

use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;

/**
 * Lens-clamped read projections for the board, the patient workspace, and
 * the 4D scene overlay (plan §5.2, §8.1). Every response carries the
 * {version, generated_at, source_cutoff_at, scope, lens} envelope so clients
 * can detect staleness and version conflicts.
 *
 * Redaction happens HERE, server-side: aggregate-only viewers receive counts
 * and states, never patient identifiers or clinical text. The scene
 * projection carries opaque round-patient tokens only, for every viewer.
 */
class RoundProjectionService
{
    public function __construct(
        private readonly RoundAuthorizationService $authorization,
        private readonly RoundCompletionService $completion,
        private readonly MobilePatientContextService $patientContext,
    ) {}

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    public function board(RoundRun $run, User $viewer): array
    {
        $detail = $this->authorization->canViewPatientDetail($viewer, $run);
        $policy = $this->completion->policyFor($run);

        $patients = $run->patients()
            ->with([
                'contributions' => fn ($q) => $q->whereIn('status', ['submitted', 'draft'])->orderByDesc('submitted_at'),
                'questions' => fn ($q) => $q->where('status', 'open'),
                'tasks',
            ])
            ->orderBy('queue_position')
            ->get();

        // Load the run's participants once and share the single run instance
        // across every row, so evaluatePatient() (waiver + requirement checks)
        // reads in-memory instead of re-querying run/participants per patient.
        // This collapses a ~5-queries-per-patient N+1 into a flat handful.
        $run->loadMissing('participants');
        $patients->each(fn (RoundPatient $patient) => $patient->setRelation('run', $run));

        $rows = $patients->map(function (RoundPatient $patient) use ($detail, $policy) {
            $evaluation = $this->completion->evaluatePatient($patient, $policy);

            $row = [
                'round_patient_uuid' => $patient->round_patient_uuid,
                'status' => $patient->status,
                'status_reason' => $patient->status_reason,
                'queue_position' => $patient->queue_position,
                'priority_band' => $patient->priority_band,
                'priority_score' => (float) $patient->priority_score,
                'priority_reasons' => $patient->priority_reasons,
                'pinned' => $patient->pinned_at !== null,
                'pin_reason' => $patient->pin_reason,
                'eta_window_start' => $patient->eta_window_start?->toIso8601String(),
                'eta_window_end' => $patient->eta_window_end?->toIso8601String(),
                'estimated_duration_minutes' => $patient->estimated_duration_minutes,
                'bed' => $patient->snapshot_bed,
                'unit_id' => $patient->snapshot_unit_id,
                'service_line_code' => $patient->snapshot_service_line_code,
                'version' => $patient->version,
                'requirements' => [
                    'satisfied' => $evaluation['satisfied'],
                    'missing' => $evaluation['missing'],
                    'stale' => $evaluation['stale'],
                    'waived' => $evaluation['waived'],
                ],
                'open_task_count' => $evaluation['open_task_count'],
                'open_question_count' => $patient->relationLoaded('questions')
                    ? $patient->questions->count()
                    : $patient->questions()->where('status', 'open')->count(),
                'rounded_at' => $patient->rounded_at?->toIso8601String(),
            ];

            if ($detail) {
                $row['patient_label'] = $patient->patient_ref;
                $row['patient_context_ref'] = $this->patientContext->contextRefFor($patient->patient_ref);
                $row['contributions'] = $patient->contributions->map(fn ($c) => [
                    'contribution_uuid' => $c->contribution_uuid,
                    'section_code' => $c->section_code,
                    'author_role' => $c->author_role,
                    'status' => $c->status,
                    'summary' => $c->summary,
                    'submitted_at' => $c->submitted_at?->toIso8601String(),
                    'version' => $c->version,
                ])->values()->all();
            } else {
                $row['patient_label'] = null;
                $row['patient_context_ref'] = null;
                $row['contributions'] = [];
                $row['contribution_count'] = $patient->contributions->where('status', 'submitted')->count();
            }

            return $row;
        })->values()->all();

        $statusCounts = $patients->countBy('status')->all();

        return $this->envelope($run, $viewer, [
            'run' => $this->runSummary($run),
            'progress' => [
                'total' => $patients->count(),
                'by_status' => $statusCounts,
                'rounded' => $statusCounts['rounded'] ?? 0,
            ],
            'patients' => $rows,
            'participants' => $run->participants()
                ->whereNull('round_patient_id')
                ->get()
                ->map(fn ($p) => [
                    'participant_uuid' => $p->participant_uuid,
                    'role_code' => $p->role_code,
                    'required' => $p->required,
                    'status' => $p->status,
                    'user_id' => $detail ? $p->user_id : null,
                    'waiver_reason' => $p->waiver_reason,
                ])->values()->all(),
        ]);
    }

    /**
     * 4D overlay projection: opaque tokens + location + round state only.
     * No patient identifier ever appears here, for any lens (plan §8.1).
     *
     * F-2 ruling (2026-07-19): under an aggregate flow lens (patient_dots =
     * none) bed-level context is also stripped — bed + queue + status is
     * re-identifiable at ward level on a shared wall. Aggregate stops anchor
     * at unit centroids (the client falls back automatically when bed is null).
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function scene(RoundRun $run, User $viewer, bool $aggregate = false): array
    {
        $patients = $run->patients()->orderBy('queue_position')->get();

        $stops = $patients->map(fn (RoundPatient $patient) => [
            'round_patient_uuid' => $patient->round_patient_uuid,
            'status' => $patient->status,
            'priority_band' => $patient->priority_band,
            'pinned' => $patient->pinned_at !== null,
            'discharge_ready' => collect($patient->priority_reasons)->contains(fn ($r) => ($r['code'] ?? null) === 'discharge_ready'),
            'missing_input' => collect($patient->priority_reasons)->contains(fn ($r) => ($r['code'] ?? null) === 'missing_required_input'),
            'queue_position' => $patient->queue_position,
            'unit_id' => $patient->snapshot_unit_id,
            'facility_space_id' => $aggregate ? null : $patient->snapshot_facility_space_id,
            'bed' => $aggregate ? null : $patient->snapshot_bed,
        ])->values()->all();

        return $this->envelope($run, $viewer, [
            'run' => $this->runSummary($run),
            'stops' => $stops,
        ]);
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    public function patientDetail(RoundPatient $patient, User $viewer): array
    {
        $run = $patient->run;
        $detail = $this->authorization->canViewPatientDetail($viewer, $run);
        $evaluation = $this->completion->evaluatePatient($patient);

        $data = [
            'round_patient_uuid' => $patient->round_patient_uuid,
            'status' => $patient->status,
            'status_reason' => $patient->status_reason,
            'version' => $patient->version,
            'priority_band' => $patient->priority_band,
            'priority_reasons' => $patient->priority_reasons,
            'pinned' => $patient->pinned_at !== null,
            'pin_reason' => $patient->pin_reason,
            'eta_window_start' => $patient->eta_window_start?->toIso8601String(),
            'eta_window_end' => $patient->eta_window_end?->toIso8601String(),
            'bed' => $patient->snapshot_bed,
            'unit_id' => $patient->snapshot_unit_id,
            'requirements' => $evaluation,
            'rounded_at' => $patient->rounded_at?->toIso8601String(),
            'inclusion' => $patient->inclusion,
        ];

        if ($detail) {
            $data['patient_label'] = $patient->patient_ref;
            $data['patient_context_ref'] = $this->patientContext->contextRefFor($patient->patient_ref);
            $data['contributions'] = $patient->contributions()
                ->orderByDesc('authored_at')
                ->get()
                ->map(fn ($c) => [
                    'contribution_uuid' => $c->contribution_uuid,
                    'section_code' => $c->section_code,
                    'author_role' => $c->author_role,
                    'author_user_id' => $c->author_user_id,
                    'status' => $c->status,
                    'structured_data' => $c->structured_data,
                    'summary' => $c->summary,
                    'source_refs' => $c->source_refs,
                    'authored_at' => $c->authored_at?->toIso8601String(),
                    'submitted_at' => $c->submitted_at?->toIso8601String(),
                    'supersedes_uuid' => $c->supersedes?->contribution_uuid,
                    'version' => $c->version,
                ])->values()->all();
            $data['questions'] = $patient->questions()->orderByDesc('created_at')->get()->map(fn ($q) => [
                'question_uuid' => $q->question_uuid,
                'question_text' => $q->question_text,
                'target_role' => $q->target_role,
                'status' => $q->status,
                'due_at' => $q->due_at?->toIso8601String(),
            ])->values()->all();
            $data['tasks'] = $patient->tasks()->orderByDesc('created_at')->get()->map(fn ($t) => [
                'task_uuid' => $t->task_uuid,
                'title' => $t->title,
                'category' => $t->category,
                'owner_role' => $t->owner_role,
                'status' => $t->status,
                'due_at' => $t->due_at?->toIso8601String(),
                'ops_action_uuid' => $t->ops_action_uuid,
            ])->values()->all();
        } else {
            $data['patient_label'] = null;
            $data['patient_context_ref'] = null;
            $data['contributions'] = [];
            $data['questions'] = [];
            $data['tasks'] = [];
        }

        return $this->envelope($run, $viewer, $data);
    }

    /** @return array<string, mixed> */
    public function runSummary(RoundRun $run): array
    {
        return [
            'run_uuid' => $run->run_uuid,
            'template' => [
                'template_uuid' => $run->template?->template_uuid,
                'name' => $run->template?->name,
                'version' => $run->template_version,
            ],
            'scope_type' => $run->scope_type,
            'scope_key' => $run->scope_key,
            'scope_label' => $run->scope_label,
            'mode' => $run->mode,
            'status' => $run->status,
            'planned_start_at' => $run->planned_start_at?->toIso8601String(),
            'window_end_at' => $run->window_end_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'queue_version' => $run->queue_version,
            'source_cutoff_at' => $run->source_cutoff_at?->toIso8601String(),
            'completion_exception' => $run->completion_exception,
            'created_by' => $run->created_by,
        ];
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    public function envelope(RoundRun $run, User $viewer, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'version' => $run->queue_version,
                'generated_at' => now()->toIso8601String(),
                'source_cutoff_at' => $run->source_cutoff_at?->toIso8601String(),
                'scope' => $run->scope_type.':'.$run->scope_key,
                'lens' => $this->authorization->canViewPatientDetail($viewer, $run) ? 'detail' : 'aggregate',
            ],
        ];
    }
}
