<?php

namespace App\Services\Rounds;

use App\Events\Rounds\RoundPatientUpdated;
use App\Exceptions\Rounds\RoundPolicyException;
use App\Exceptions\Rounds\RoundTransitionException;
use App\Models\Rounds\RoundContribution;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundPatient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contribution lifecycle (plan §6.2, §6.3): draft -> submitted -> superseded,
 * or withdrawn. Submitted rows are immutable — corrections insert a
 * superseding row inside one transaction so the partial unique index
 * (one active submitted row per patient/author/role/section) always holds.
 *
 * structured_data is validated against the config('rounds.sections')
 * allowlist; arbitrary payloads never become a shadow medical record.
 */
class RoundContributionService
{
    public function __construct(
        private readonly RoundAuthorizationService $authorization,
    ) {}

    /**
     * Create a draft or submit directly (submit=true). If the author already
     * has a draft for this section the draft is updated in place — drafts are
     * mutable; only submission freezes content.
     *
     * @param array{
     *     section_code: string, author_role?: string|null, structured_data?: array<string, mixed>,
     *     summary?: string|null, source_refs?: list<mixed>, submit?: bool, idempotency_key?: string|null,
     * } $input
     */
    public function compose(User $actor, RoundPatient $patient, array $input): RoundContribution
    {
        $run = $patient->run;
        $this->authorization->assertCanContribute($actor, $run);

        $role = $input['author_role'] ?? $this->authorization->contributorRoleFor($actor, $run);

        if ($role === null || ! array_key_exists($role, (array) config('rounds.roles'))) {
            throw new RoundPolicyException('A valid contributor role is required.');
        }

        $section = $input['section_code'];
        $this->validateSection($section, $role, (array) ($input['structured_data'] ?? []));

        return DB::transaction(function () use ($actor, $patient, $input, $role, $section): RoundContribution {
            RoundPatient::query()->lockForUpdate()->findOrFail($patient->round_patient_id);

            if (! empty($input['idempotency_key'])) {
                $existing = RoundEvent::query()
                    ->where('idempotency_key', $input['idempotency_key'])
                    ->where('aggregate_type', 'contribution')
                    ->first();
                if ($existing !== null) {
                    return RoundContribution::query()->findOrFail($existing->aggregate_id);
                }
            }

            $draft = RoundContribution::query()
                ->where('round_patient_id', $patient->round_patient_id)
                ->where('author_user_id', $actor->id)
                ->where('author_role', $role)
                ->where('section_code', $section)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($draft !== null) {
                $draft->fill([
                    'structured_data' => (array) ($input['structured_data'] ?? []),
                    'summary' => $input['summary'] ?? null,
                    'source_refs' => (array) ($input['source_refs'] ?? []),
                    'authored_at' => now(),
                ])->save();
                $contribution = $draft;
            } else {
                $contribution = RoundContribution::create([
                    'contribution_uuid' => (string) Str::uuid(),
                    'round_patient_id' => $patient->round_patient_id,
                    'author_user_id' => $actor->id,
                    'author_role' => $role,
                    'section_code' => $section,
                    'status' => 'draft',
                    'structured_data' => (array) ($input['structured_data'] ?? []),
                    'summary' => $input['summary'] ?? null,
                    'source_refs' => (array) ($input['source_refs'] ?? []),
                    'authored_at' => now(),
                    'version' => 1,
                ]);

                RoundEvent::record(
                    'contribution', $contribution->contribution_id, $contribution->contribution_uuid, 1,
                    $actor->id, 'contribution.drafted',
                    ['section' => $section, 'role' => $role],
                    $input['idempotency_key'] ?? null,
                );
            }

            if (! empty($input['submit'])) {
                $contribution = $this->submitLocked($actor, $contribution);
            }

            return $contribution;
        });
    }

    public function submit(User $actor, RoundContribution $contribution): RoundContribution
    {
        $patient = $contribution->patient;
        $this->authorization->assertCanContribute($actor, $patient->run);

        if ((int) $contribution->author_user_id !== (int) $actor->id) {
            throw new RoundPolicyException('Only the author can submit a contribution.');
        }

        return DB::transaction(function () use ($actor, $contribution): RoundContribution {
            RoundPatient::query()->lockForUpdate()->findOrFail($contribution->round_patient_id);
            $locked = RoundContribution::query()->lockForUpdate()->findOrFail($contribution->contribution_id);

            return $this->submitLocked($actor, $locked);
        });
    }

    public function withdraw(User $actor, RoundContribution $contribution, ?string $reason = null): RoundContribution
    {
        $patient = $contribution->patient;
        $this->authorization->assertCanContribute($actor, $patient->run);

        if ((int) $contribution->author_user_id !== (int) $actor->id
            && ! $this->authorization->canLead($actor, $patient->run)) {
            throw new RoundPolicyException('Only the author or a round leader can withdraw a contribution.');
        }

        return DB::transaction(function () use ($actor, $contribution, $reason): RoundContribution {
            $locked = RoundContribution::query()->lockForUpdate()->findOrFail($contribution->contribution_id);

            if (! $locked->canTransitionTo('withdrawn')) {
                throw new RoundTransitionException("Cannot withdraw a contribution in status '{$locked->status}'.");
            }

            $locked->status = 'withdrawn';
            $locked->save();

            RoundEvent::record(
                'contribution', $locked->contribution_id, $locked->contribution_uuid, $locked->version,
                $actor->id, 'contribution.withdrawn',
                ['reason' => $reason],
            );

            return $locked;
        });
    }

    /**
     * Submit inside an already-open transaction with the patient row locked.
     * Supersedes any existing submitted row for the same author/role/section.
     */
    private function submitLocked(User $actor, RoundContribution $contribution): RoundContribution
    {
        if (! $contribution->canTransitionTo('submitted')) {
            throw new RoundTransitionException("Cannot submit a contribution in status '{$contribution->status}'.");
        }

        $prior = RoundContribution::query()
            ->where('round_patient_id', $contribution->round_patient_id)
            ->where('author_user_id', $contribution->author_user_id)
            ->where('author_role', $contribution->author_role)
            ->where('section_code', $contribution->section_code)
            ->where('status', 'submitted')
            ->lockForUpdate()
            ->first();

        if ($prior !== null) {
            $prior->status = 'superseded';
            $prior->save();

            $contribution->supersedes_id = $prior->contribution_id;
            $contribution->version = $prior->version + 1;

            RoundEvent::record(
                'contribution', $prior->contribution_id, $prior->contribution_uuid, $prior->version,
                $actor->id, 'contribution.superseded',
                ['superseded_by' => $contribution->contribution_uuid],
            );
        }

        $contribution->status = 'submitted';
        $contribution->submitted_at = now();
        $contribution->save();

        RoundEvent::record(
            'contribution', $contribution->contribution_id, $contribution->contribution_uuid, $contribution->version,
            $actor->id, 'contribution.submitted',
            ['section' => $contribution->section_code, 'role' => $contribution->author_role],
        );

        // Participant slot for this role is now satisfied by this author.
        $contribution->patient->run->participants()
            ->where('role_code', $contribution->author_role)
            ->where(function ($q) use ($actor) {
                $q->whereNull('user_id')->orWhere('user_id', $actor->id);
            })
            ->whereIn('status', ['pending', 'invited', 'accepted'])
            ->limit(1)
            ->update(['status' => 'contributed', 'responded_at' => now()]);

        // First activity nudges the patient out of 'queued'.
        $patient = RoundPatient::query()->lockForUpdate()->find($contribution->round_patient_id);
        if ($patient !== null && $patient->status === 'queued') {
            $patient->status = 'in_progress';
            $patient->version = $patient->version + 1;
            $patient->save();

            RoundEvent::record(
                'round_patient', $patient->round_patient_id, $patient->round_patient_uuid, $patient->version,
                $actor->id, 'patient.transitioned',
                ['from' => 'queued', 'to' => 'in_progress', 'reason' => 'first_contribution'],
            );

            DB::afterCommit(function () use ($patient): void {
                broadcast(new RoundPatientUpdated(
                    $patient->run->run_uuid, $patient->round_patient_uuid, $patient->status, $patient->version,
                ));
            });
        }

        return $contribution;
    }

    /**
     * Enforce the section allowlist: known section, role authored-by
     * permission, only allowlisted fields, enum/type constraints (§6.2).
     */
    private function validateSection(string $sectionCode, string $role, array $structuredData): void
    {
        $section = config('rounds.sections.'.$sectionCode);

        if ($section === null) {
            throw new RoundPolicyException("Unknown contribution section '{$sectionCode}'.");
        }

        if (! in_array($role, (array) $section['roles'], true)) {
            throw new RoundPolicyException("Role '{$role}' may not author section '{$sectionCode}'.");
        }

        $fields = (array) $section['fields'];

        foreach ($structuredData as $key => $value) {
            if (! array_key_exists($key, $fields)) {
                throw new RoundPolicyException("Field '{$key}' is not allowlisted for section '{$sectionCode}'.");
            }

            $type = $fields[$key];

            if (str_starts_with($type, 'enum:')) {
                $allowed = explode(',', substr($type, 5));
                if ($value !== null && ! in_array($value, $allowed, true)) {
                    throw new RoundPolicyException("Field '{$key}' must be one of: ".implode(', ', $allowed).'.');
                }
            } elseif ($type === 'boolean') {
                if ($value !== null && ! is_bool($value)) {
                    throw new RoundPolicyException("Field '{$key}' must be a boolean.");
                }
            } else {
                if ($value !== null && ! is_string($value)) {
                    throw new RoundPolicyException("Field '{$key}' must be a string.");
                }
                $max = $type === 'text' ? 8000 : 500;
                if (is_string($value) && mb_strlen($value) > $max) {
                    throw new RoundPolicyException("Field '{$key}' exceeds {$max} characters.");
                }
            }
        }
    }
}
