<?php

namespace App\Services\Rounds;

use App\Events\Rounds\RoundPatientUpdated;
use App\Events\Rounds\RoundQueueUpdated;
use App\Events\Rounds\RoundRunUpdated;
use App\Exceptions\Rounds\RoundConflictException;
use App\Exceptions\Rounds\RoundPolicyException;
use App\Exceptions\Rounds\RoundTransitionException;
use App\Models\Encounter;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use App\Models\Rounds\RoundTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional command layer for the rounds domain (plan §5.2, §6.3).
 *
 * Every mutation runs in one transaction with a row lock on the run, an
 * optional expected version (stale -> RoundConflictException -> HTTP 409),
 * an optional idempotency key (replays return the current state without
 * re-executing), and an audit event written in the same transaction.
 * Reload-ping broadcasts fire only after commit. Controllers validate and
 * delegate; transitions live here, never in controllers.
 */
class RoundCommandService
{
    /** @var list<object> */
    private array $pendingBroadcasts = [];

    public function __construct(
        private readonly RoundCohortBuilder $cohortBuilder,
        private readonly RoundAuthorizationService $authorization,
        private readonly RoundCompletionService $completion,
        private readonly RoundQueueService $queue,
        private readonly RoundEtaService $eta,
    ) {}

    /**
     * Create a run (draft) and build its cohort at one source cutoff.
     *
     * @param array{
     *     template_uuid: string, scope_type: string, scope_key: string,
     *     mode?: string|null, planned_start_at?: string|null,
     *     window_end_at?: string|null, idempotency_key?: string|null,
     * } $input
     */
    public function createRun(User $actor, array $input): RoundRun
    {
        $template = RoundTemplate::query()
            ->where('template_uuid', $input['template_uuid'])
            ->where('active', true)
            ->first();

        if ($template === null) {
            throw new RoundPolicyException('Unknown or inactive round template.');
        }

        if ($input['scope_type'] !== 'unit') {
            throw new RoundPolicyException('Only unit scope is supported in this phase.');
        }

        if (! in_array('unit', $template->scopeTypes(), true)) {
            throw new RoundPolicyException('This template does not support unit scope.');
        }

        $unit = $this->authorization->resolveUnit($input['scope_key']);

        if ($unit === null) {
            throw new RoundPolicyException('Unknown unit scope.');
        }

        if (! $this->authorization->canStartRun($actor, $unit)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You are not authorized to start a round for this unit.');
        }

        $run = $this->execute(function () use ($actor, $input, $template, $unit): RoundRun {
            if ($this->isReplay($input['idempotency_key'] ?? null, 'run')) {
                $existing = $this->replayedRun($input['idempotency_key']);
                if ($existing !== null) {
                    return $existing;
                }
            }

            $run = RoundRun::create([
                'run_uuid' => (string) Str::uuid(),
                'template_id' => $template->template_id,
                'template_version' => $template->version,
                'facility_key' => config('hospital.default_facility'),
                'scope_type' => 'unit',
                'scope_key' => (string) $unit->unit_id,
                'scope_label' => $unit->name,
                'mode' => $input['mode'] ?? $template->mode,
                'status' => 'draft',
                'planned_start_at' => $input['planned_start_at'] ?? now(),
                'window_end_at' => $input['window_end_at'] ?? null,
                'queue_version' => 1,
                'created_by' => $actor->id,
                'metadata' => [],
            ]);

            $this->cohortBuilder->build($run, $template, $unit, $actor);

            RoundEvent::record(
                'run', $run->run_id, $run->run_uuid, $run->queue_version,
                $actor->id, 'run.created',
                ['scope' => 'unit:'.$unit->unit_id, 'template' => $template->name],
                $input['idempotency_key'] ?? null,
            );

            $this->pendingBroadcasts[] = new RoundRunUpdated($run->run_uuid, $run->status, $run->queue_version);

            return $run->refresh();
        });

        return $run;
    }

    public function start(User $actor, RoundRun $run, array $opts = []): RoundRun
    {
        return $this->transitionRun($actor, $run, 'active', 'run.started', $opts, function (RoundRun $locked): void {
            $locked->started_at = now();
        });
    }

    public function pause(User $actor, RoundRun $run, array $opts = []): RoundRun
    {
        return $this->transitionRun($actor, $run, 'paused', 'run.paused', $opts);
    }

    public function resume(User $actor, RoundRun $run, array $opts = []): RoundRun
    {
        if ($run->status !== 'paused') {
            throw new RoundTransitionException("Cannot resume a run in status '{$run->status}'.");
        }

        return $this->transitionRun($actor, $run, 'active', 'run.resumed', $opts);
    }

    public function cancel(User $actor, RoundRun $run, array $opts = []): RoundRun
    {
        return $this->transitionRun($actor, $run, 'cancelled', 'run.cancelled', $opts, function (RoundRun $locked) use ($opts): void {
            $locked->cancelled_at = now();
            $locked->metadata = array_merge((array) $locked->metadata, [
                'cancel_reason' => $opts['reason'] ?? null,
            ]);
        });
    }

    /**
     * Complete a run. If patients remain in disallowed states, an authorized
     * exception with a reason is required (plan §6.2) and is recorded.
     */
    public function complete(User $actor, RoundRun $run, array $opts = []): RoundRun
    {
        $this->authorization->assertCanLead($actor, $run);

        return $this->execute(function () use ($actor, $run, $opts): RoundRun {
            $locked = $this->lockRun($run);

            if ($this->isReplay($opts['idempotency_key'] ?? null, 'run', $locked->run_id)) {
                return $locked;
            }

            if (! $locked->canTransitionTo('completed')) {
                throw new RoundTransitionException("Cannot complete a run in status '{$locked->status}'.");
            }

            $evaluation = $this->completion->evaluateRun($locked);

            if (! $evaluation['can_complete']) {
                $reason = trim((string) ($opts['exception_reason'] ?? ''));

                if ($reason === '') {
                    throw new RoundPolicyException(
                        'Run has patients in disallowed states; provide an exception reason to complete anyway. Blocking: '
                        .implode(', ', $evaluation['blocking'])
                    );
                }

                $locked->completion_exception = [
                    'reason' => $reason,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now()->toIso8601String(),
                    'blocking' => $evaluation['blocking'],
                ];
            }

            $locked->status = 'completed';
            $locked->completed_at = now();
            $locked->save();

            RoundEvent::record(
                'run', $locked->run_id, $locked->run_uuid, $locked->queue_version,
                $actor->id, 'run.completed',
                ['counts' => $evaluation['counts'], 'exception' => $locked->completion_exception !== null],
                $opts['idempotency_key'] ?? null,
            );

            $this->pendingBroadcasts[] = new RoundRunUpdated($locked->run_uuid, $locked->status, $locked->queue_version);

            return $locked;
        });
    }

    /**
     * Patient FSM transition. mark-ready / complete / reopen / defer / skip
     * all route through here with an expected version check.
     *
     * @param  array{expected_version?: int|null, reason?: string|null, idempotency_key?: string|null, exception_reason?: string|null}  $opts
     */
    public function transitionPatient(User $actor, RoundPatient $patient, string $to, array $opts = []): RoundPatient
    {
        $run = $patient->run;

        // Leading is required to mark rounded or reopen; contributing suffices otherwise.
        if (in_array($to, ['rounded'], true) || $patient->status === 'rounded') {
            $this->authorization->assertCanLead($actor, $run);
        } else {
            $this->authorization->assertCanContribute($actor, $run);
        }

        return $this->execute(function () use ($actor, $patient, $run, $to, $opts): RoundPatient {
            $this->lockRun($run);
            $locked = RoundPatient::query()->lockForUpdate()->findOrFail($patient->round_patient_id);

            if ($this->isReplay($opts['idempotency_key'] ?? null, 'round_patient', $locked->round_patient_id)) {
                return $locked;
            }

            $expected = $opts['expected_version'] ?? null;
            if ($expected !== null && (int) $expected !== (int) $locked->version) {
                throw new RoundConflictException(
                    "Stale patient version: expected {$expected}, current {$locked->version}."
                );
            }

            if (! $locked->canTransitionTo($to)) {
                throw new RoundTransitionException(
                    "Cannot move patient from '{$locked->status}' to '{$to}'."
                );
            }

            if (in_array($to, ['deferred', 'skipped'], true) && trim((string) ($opts['reason'] ?? '')) === '') {
                throw new RoundPolicyException("A reason is required to mark a patient {$to}.");
            }

            $from = $locked->status;

            if ($to === 'rounded') {
                $evaluation = $this->completion->evaluatePatient($locked);

                if (! $evaluation['satisfied']) {
                    $reason = trim((string) ($opts['exception_reason'] ?? ''));

                    if ($reason === '') {
                        throw new RoundPolicyException(
                            'Completion requirements are not met; provide an exception reason to round anyway.'
                        );
                    }

                    $locked->metadata = array_merge((array) $locked->metadata, [
                        'completion_exception' => [
                            'reason' => $reason,
                            'recorded_by' => $actor->id,
                            'recorded_at' => now()->toIso8601String(),
                            'missing' => $evaluation['missing'],
                        ],
                    ]);
                }

                $locked->rounded_by = $actor->id;
                $locked->rounded_at = now();
            }

            $eventType = $from === 'rounded' && $to === 'in_progress'
                ? 'patient.reopened'
                : 'patient.transitioned';

            $locked->status = $to;
            $locked->status_reason = $opts['reason'] ?? null;
            $locked->version = $locked->version + 1;
            $locked->save();

            RoundEvent::record(
                'round_patient', $locked->round_patient_id, $locked->round_patient_uuid, $locked->version,
                $actor->id, $eventType,
                ['from' => $from, 'to' => $to, 'reason' => $opts['reason'] ?? null],
                $opts['idempotency_key'] ?? null,
            );

            $this->recomputeQueue($run, $actor, bumpVersion: true, recordEvent: false);

            $this->pendingBroadcasts[] = new RoundPatientUpdated(
                $run->run_uuid, $locked->round_patient_uuid, $locked->status, $locked->version,
            );

            return $locked;
        });
    }

    /**
     * Reorder the queue to an explicit UUID order. Requires the caller's
     * expected_queue_version; conflicts return the current projection.
     *
     * @param  list<string>  $orderedUuids
     */
    public function reorderQueue(User $actor, RoundRun $run, array $orderedUuids, int $expectedQueueVersion, array $opts = []): RoundRun
    {
        $this->authorization->assertCanLead($actor, $run);

        return $this->execute(function () use ($actor, $run, $orderedUuids, $expectedQueueVersion, $opts): RoundRun {
            $locked = $this->lockRun($run);

            if ($this->isReplay($opts['idempotency_key'] ?? null, 'run', $locked->run_id)) {
                return $locked;
            }

            $this->assertQueueVersion($locked, $expectedQueueVersion);

            $patients = $locked->patients()->get()->keyBy('round_patient_uuid');

            $unknown = array_diff($orderedUuids, $patients->keys()->all());
            if ($unknown !== []) {
                throw new RoundPolicyException('Unknown round patient in requested order.');
            }

            $position = 1;
            $ordered = collect();
            foreach ($orderedUuids as $uuid) {
                $patient = $patients->get($uuid);
                $patient->queue_position = $position++;
                $ordered->push($patient);
            }
            // Anyone omitted keeps relative order after the explicit ones.
            foreach ($patients->sortBy('queue_position') as $patient) {
                if (! in_array($patient->round_patient_uuid, $orderedUuids, true)) {
                    $patient->queue_position = $position++;
                    $ordered->push($patient);
                }
            }

            $this->eta->assignWindows($locked, $ordered, (array) $locked->template?->eta_policy);

            foreach ($ordered as $patient) {
                $patient->save();
            }

            $locked->queue_version = $locked->queue_version + 1;
            $locked->save();

            RoundEvent::record(
                'run', $locked->run_id, $locked->run_uuid, $locked->queue_version,
                $actor->id, 'queue.reordered',
                ['order' => $orderedUuids],
                $opts['idempotency_key'] ?? null,
            );

            $this->pendingBroadcasts[] = new RoundQueueUpdated($locked->run_uuid, $locked->queue_version);

            return $locked;
        });
    }

    /**
     * Pin or unpin a patient. Pinning requires a reason; the prior order is
     * preserved in the audit event (plan §7.2 guardrails).
     */
    public function setPin(User $actor, RoundPatient $patient, bool $pinned, ?string $reason, int $expectedQueueVersion, array $opts = []): RoundRun
    {
        $run = $patient->run;
        $this->authorization->assertCanLead($actor, $run);

        if ($pinned && trim((string) $reason) === '') {
            throw new RoundPolicyException('A reason is required to pin a patient.');
        }
        if (! $pinned && trim((string) $reason) === '') {
            throw new RoundPolicyException('A reason is required to unpin a patient.');
        }

        return $this->execute(function () use ($actor, $patient, $run, $pinned, $reason, $expectedQueueVersion, $opts): RoundRun {
            $locked = $this->lockRun($run);

            // Pin records its idempotency event against the patient (see below),
            // so the replay check must scope to the same aggregate.
            if ($this->isReplay($opts['idempotency_key'] ?? null, 'round_patient', $patient->round_patient_id)) {
                return $locked;
            }

            $this->assertQueueVersion($locked, $expectedQueueVersion);

            $lockedPatient = RoundPatient::query()->lockForUpdate()->findOrFail($patient->round_patient_id);
            $priorOrder = $locked->patients()->orderBy('queue_position')->pluck('round_patient_uuid')->all();

            $lockedPatient->pinned_by = $pinned ? $actor->id : null;
            $lockedPatient->pinned_at = $pinned ? now() : null;
            $lockedPatient->pin_reason = $pinned ? $reason : null;
            $lockedPatient->version = $lockedPatient->version + 1;
            $lockedPatient->save();

            RoundEvent::record(
                'round_patient', $lockedPatient->round_patient_id, $lockedPatient->round_patient_uuid, $lockedPatient->version,
                $actor->id, $pinned ? 'patient.pinned' : 'patient.unpinned',
                ['reason' => $reason, 'prior_order' => $priorOrder],
                $opts['idempotency_key'] ?? null,
            );

            $this->recomputeQueue($locked, $actor, bumpVersion: true, recordEvent: false);

            $this->pendingBroadcasts[] = new RoundQueueUpdated($locked->run_uuid, $locked->fresh()->queue_version);

            return $locked->refresh();
        });
    }

    /**
     * Apply selected reconciliation suggestions: enroll specific encounters
     * and/or mark departed patients deferred. Never silent — callers pass the
     * explicit sets and a reason.
     *
     * @param  array{add?: list<int>, remove?: list<string>, reason?: string|null, idempotency_key?: string|null}  $apply
     */
    public function applyReconciliation(User $actor, RoundRun $run, array $apply): RoundRun
    {
        $this->authorization->assertCanLead($actor, $run);

        return $this->execute(function () use ($actor, $run, $apply): RoundRun {
            $locked = $this->lockRun($run);

            if ($this->isReplay($apply['idempotency_key'] ?? null, 'run', $locked->run_id)) {
                return $locked;
            }

            $template = $locked->template;
            $unit = $this->authorization->resolveUnit($locked->scope_key);

            foreach ((array) ($apply['add'] ?? []) as $prodEncounterId) {
                $encounter = Encounter::query()->active()->with('bed')->find($prodEncounterId);
                if ($encounter === null || $unit === null || (int) $encounter->unit_id !== (int) $unit->unit_id) {
                    continue;
                }

                $exists = $locked->patients()->where('prod_encounter_id', $encounter->encounter_id)->exists();
                if ($exists) {
                    continue;
                }

                $duration = $this->eta->estimateDuration([
                    'acuity_tier' => $encounter->acuity_tier,
                    'missing_required_input' => ($template?->required_roles ?? []) !== [],
                ], (array) $template?->eta_policy);

                RoundPatient::create([
                    'round_patient_uuid' => (string) Str::uuid(),
                    'run_id' => $locked->run_id,
                    'encounter_ref' => 'prodenc:'.$encounter->encounter_id,
                    'prod_encounter_id' => $encounter->encounter_id,
                    'patient_ref' => $encounter->patient_ref,
                    'snapshot_unit_id' => $encounter->unit_id,
                    'snapshot_bed' => $encounter->bed?->label,
                    'status' => 'queued',
                    'priority_band' => 6,
                    'priority_reasons' => [],
                    'estimated_duration_minutes' => $duration['minutes'],
                    'inclusion' => [
                        'included_at' => now()->toIso8601String(),
                        'source' => 'reconciliation',
                        'reasons' => ['reconcile_add'],
                        'reason_note' => $apply['reason'] ?? null,
                    ],
                    'version' => 1,
                    'metadata' => ['duration_components' => $duration['components']],
                ]);
            }

            foreach ((array) ($apply['remove'] ?? []) as $uuid) {
                $patient = $locked->patients()->where('round_patient_uuid', $uuid)->lockForUpdate()->first();
                if ($patient === null || in_array($patient->status, ['rounded', 'skipped', 'deferred'], true)) {
                    continue;
                }

                $from = $patient->status;
                $patient->status = 'deferred';
                $patient->status_reason = $apply['reason'] ?? 'reconcile_remove';
                $patient->version = $patient->version + 1;
                $patient->save();

                RoundEvent::record(
                    'round_patient', $patient->round_patient_id, $patient->round_patient_uuid, $patient->version,
                    $actor->id, 'patient.transitioned',
                    ['from' => $from, 'to' => 'deferred', 'reason' => $apply['reason'] ?? 'reconcile_remove'],
                );
            }

            $this->recomputeQueue($locked, $actor, bumpVersion: true, recordEvent: true, eventType: 'cohort.reconciled');

            $this->pendingBroadcasts[] = new RoundQueueUpdated($locked->run_uuid, $locked->fresh()->queue_version);

            return $locked->refresh();
        });
    }

    /**
     * Re-score, re-order, and re-window the queue from each patient's stored
     * signal snapshot — every reason stays reproducible from recorded inputs.
     */
    private function recomputeQueue(RoundRun $run, ?User $actor, bool $bumpVersion, bool $recordEvent, string $eventType = 'queue.recomputed'): void
    {
        $template = $run->template;
        $patients = $run->patients()->get();

        foreach ($patients as $patient) {
            $signals = (array) ($patient->metadata['signals'] ?? []);
            $signals['open_task_count'] = $patient->tasks()->whereIn('status', ['open', 'in_progress'])->count();

            $priority = $this->queue->scorePatient($patient, $signals, (array) $template?->priority_policy);
            $patient->priority_score = $priority['score'];
            $patient->priority_band = $priority['band'];
            $patient->priority_reasons = $priority['reasons'];
        }

        // The queue_version is the optimistic-lock token for pin/reorder. Only
        // bump it when the ORDER actually changes — otherwise a plain status
        // change (e.g. mark-ready, which never reorders) would invalidate a
        // teammate's in-flight pin/reorder with a spurious 409 on a busy board.
        // Score/band updates still propagate via the post-mutation board refetch.
        $before = $patients->sortBy('queue_position')->pluck('round_patient_id')->values()->all();
        $ordered = $this->queue->orderQueue($patients);
        $after = $ordered->pluck('round_patient_id')->values()->all();
        $this->eta->assignWindows($run, $ordered, (array) $template?->eta_policy);

        foreach ($ordered as $patient) {
            $patient->save();
        }

        if ($bumpVersion && $before !== $after) {
            $run->queue_version = $run->queue_version + 1;
            $run->save();
        }

        if ($recordEvent) {
            RoundEvent::record(
                'run', $run->run_id, $run->run_uuid, $run->queue_version,
                $actor?->id, $eventType, [],
            );
        }
    }

    private function transitionRun(User $actor, RoundRun $run, string $to, string $eventType, array $opts, ?callable $mutate = null): RoundRun
    {
        $this->authorization->assertCanLead($actor, $run);

        return $this->execute(function () use ($actor, $run, $to, $eventType, $opts, $mutate): RoundRun {
            $locked = $this->lockRun($run);

            if ($this->isReplay($opts['idempotency_key'] ?? null, 'run', $locked->run_id)) {
                return $locked;
            }

            if (! $locked->canTransitionTo($to)) {
                throw new RoundTransitionException(
                    "Cannot move run from '{$locked->status}' to '{$to}'."
                );
            }

            $from = $locked->status;
            $locked->status = $to;

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->save();

            RoundEvent::record(
                'run', $locked->run_id, $locked->run_uuid, $locked->queue_version,
                $actor->id, $eventType,
                ['from' => $from, 'to' => $to],
                $opts['idempotency_key'] ?? null,
            );

            $this->pendingBroadcasts[] = new RoundRunUpdated($locked->run_uuid, $locked->status, $locked->queue_version);

            return $locked;
        });
    }

    /** Run the mutation in a transaction; broadcast reload pings only after commit. */
    private function execute(callable $fn): mixed
    {
        $this->pendingBroadcasts = [];

        try {
            $result = DB::transaction($fn);
        } catch (\Illuminate\Database\QueryException $e) {
            // A concurrent duplicate idempotency key hits the partial unique
            // index; surface it as a conflict, not a 500.
            if (str_contains($e->getMessage(), 'uq_rounds_events_idempotency')) {
                throw new RoundConflictException('Duplicate command (idempotency key already used).');
            }

            throw $e;
        }

        foreach ($this->pendingBroadcasts as $event) {
            broadcast($event);
        }
        $this->pendingBroadcasts = [];

        return $result;
    }

    private function lockRun(RoundRun $run): RoundRun
    {
        $locked = RoundRun::query()->lockForUpdate()->find($run->run_id);

        if ($locked === null) {
            throw new RoundTransitionException('Round run no longer exists.');
        }

        $locked->setRelation('template', $run->template);

        return $locked;
    }

    private function assertQueueVersion(RoundRun $run, int $expected): void
    {
        if ((int) $run->queue_version !== $expected) {
            throw new RoundConflictException(
                "Stale queue version: expected {$expected}, current {$run->queue_version}."
            );
        }
    }

    /**
     * A replay is the SAME key seen on the SAME aggregate this command records
     * against — not merely the key existing anywhere. Scoping by aggregate
     * prevents a key reused across different aggregates (or aggregate types)
     * from short-circuiting an unrelated command with stale state. Pass a null
     * $aggregateId to scope by type only (create, before the id exists).
     */
    private function isReplay(?string $idempotencyKey, string $aggregateType, int|string|null $aggregateId = null): bool
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return false;
        }

        $query = RoundEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('aggregate_type', $aggregateType);

        if ($aggregateId !== null) {
            $query->where('aggregate_id', $aggregateId);
        }

        return $query->exists();
    }

    private function replayedRun(string $idempotencyKey): ?RoundRun
    {
        $event = RoundEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('aggregate_type', 'run')
            ->first();

        if ($event === null) {
            return null;
        }

        return RoundRun::query()->find($event->aggregate_id);
    }
}
