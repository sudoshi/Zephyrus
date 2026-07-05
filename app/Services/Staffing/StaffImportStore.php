<?php

namespace App\Services\Staffing;

use App\Models\Org\StaffImportRun;
use App\Models\Org\StaffMember;
use App\Models\Reference\StaffRole;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Staffing\Support\ImportResult;
use App\Services\Staffing\Support\RawStaffRecord;
use App\Services\Staffing\Support\ResolvedAssignment;
use App\Services\Staffing\Support\StagedStaffMember;

/**
 * Phase F4: the persistence bridge that makes the wizard's multi-request review loop
 * stateless. The orchestrator produces an in-memory ImportResult; this serializes each
 * staged person (identity + resolver proposals + bucket + the record fields commit
 * needs) into the run's `staged` jsonb, reads it back for GET/PATCH, and reconstructs
 * an ImportResult — with per-member review decisions applied — for orchestrator->commit().
 *
 * It never writes staff_assignments (that stays the orchestrator's sole responsibility)
 * and never touches prod.users. Edited decisions are validated against the registry +
 * role taxonomy so a hand-edited assignment can never carry an FK-invalid code.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§7, §8)
 */
class StaffImportStore
{
    /** Reviewer actions the wizard may record (§7 step 5). */
    public const ACTIONS = ['accept', 'edit', 'split', 'reject', 'defer', 'deactivate'];

    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * Serialize a fresh ImportResult into the run's staged snapshot.
     *
     * @return array{facility_key:string, items:list<array<string,mixed>>}
     */
    public function persist(ImportResult $result, string $facilityKey): array
    {
        $payload = [
            'facility_key' => $facilityKey,
            'items' => array_map(fn (StagedStaffMember $i): array => $this->serializeItem($i), $result->items),
        ];

        $result->run->update(['staged' => $payload]);

        return $payload;
    }

    /**
     * Re-persist after a re-resolve, preserving any decisions already recorded (keyed by
     * staff_member_id) so promoting a rule + re-resolving never discards reviewer work.
     *
     * @return array{facility_key:string, items:list<array<string,mixed>>}
     */
    public function refresh(StaffImportRun $run, ImportResult $result, string $facilityKey): array
    {
        $priorDecisions = [];
        foreach ($this->payload($run)['items'] as $item) {
            if (($item['decision'] ?? null) !== null) {
                $priorDecisions[(int) $item['staff_member_id']] = $item['decision'];
            }
        }

        $items = array_map(function (StagedStaffMember $i) use ($priorDecisions): array {
            $item = $this->serializeItem($i);
            $id = (int) $item['staff_member_id'];
            if (isset($priorDecisions[$id])) {
                $item['decision'] = $priorDecisions[$id];
            }

            return $item;
        }, $result->items);

        $payload = ['facility_key' => $facilityKey, 'items' => $items];
        $run->update(['staged' => $payload]);

        return $payload;
    }

    /**
     * The normalized staged payload for a run.
     *
     * @return array{facility_key:?string, items:list<array<string,mixed>>}
     */
    public function payload(StaffImportRun $run): array
    {
        $staged = is_array($run->staged) ? $run->staged : [];

        return [
            'facility_key' => $staged['facility_key'] ?? null,
            'items' => array_values($staged['items'] ?? []),
        ];
    }

    public function facilityKey(StaffImportRun $run): ?string
    {
        return $this->payload($run)['facility_key'];
    }

    /**
     * Record (or clear) a reviewer decision on one staged member. Returns the updated
     * item, or null when the member is not part of this run's staging.
     *
     * @param  array<string,mixed>|null  $decision
     * @return array<string,mixed>|null
     */
    public function setDecision(StaffImportRun $run, int $staffMemberId, ?array $decision): ?array
    {
        $payload = $this->payload($run);
        $updated = null;

        foreach ($payload['items'] as &$item) {
            if ((int) $item['staff_member_id'] === $staffMemberId) {
                $item['decision'] = $decision;
                $updated = $item;
                break;
            }
        }
        unset($item);

        if ($updated === null) {
            return null;
        }

        $run->update(['staged' => $payload]);

        return $updated;
    }

    /**
     * Rebuild the source records (for a re-resolve) — departed members carry no record.
     *
     * @return list<RawStaffRecord>
     */
    public function records(StaffImportRun $run): array
    {
        $records = [];
        foreach ($this->payload($run)['items'] as $item) {
            if (! is_array($item['record'] ?? null)) {
                continue;
            }
            $records[] = RawStaffRecord::fromArray((string) $item['source_system'], $item['record']);
        }

        return $records;
    }

    /**
     * Rebuild an ImportResult from the staged snapshot with reviewer decisions applied
     * to each member's proposals, ready for orchestrator->commit(). Undecided members in
     * ambiguous buckets (needs_review / conflicts / unmatched) are NOT committed — only
     * an explicit accept/edit, or the auto_approved bucket, carries proposals through.
     *
     * @return array{result:ImportResult, deactivate:list<int>}
     */
    public function reconstructForCommit(StaffImportRun $run): array
    {
        $items = [];
        $deactivate = [];

        foreach ($this->payload($run)['items'] as $raw) {
            $member = StaffMember::find($raw['staff_member_id']);
            if ($member === null) {
                continue;
            }

            $record = is_array($raw['record'] ?? null)
                ? RawStaffRecord::fromArray((string) $raw['source_system'], $raw['record'])
                : null;

            $bucket = (string) ($raw['bucket'] ?? 'unmatched');
            $action = $raw['decision']['action'] ?? null;

            $proposed = match (true) {
                $action === 'deactivate' => $this->markDeactivate($member->staff_member_id, $deactivate),
                in_array($action, ['edit', 'split'], true) => $this->assignmentsFromDecision($raw['decision']),
                in_array($action, ['reject', 'defer'], true) => [],
                $action === 'accept' => $this->rebuildProposed($raw['proposed'] ?? []),
                // Undecided: only the high-confidence auto_approved bucket commits by default.
                $bucket === 'auto_approved' => $this->rebuildProposed($raw['proposed'] ?? []),
                default => [],
            };

            $items[] = new StagedStaffMember(
                $member,
                $record,
                $proposed,
                $bucket,
                array_map('intval', $raw['conflicts'] ?? []),
            );
        }

        return ['result' => new ImportResult($run, $items), 'deactivate' => $deactivate];
    }

    /**
     * Validate a decision payload before it is stored. Returns an error message, or null
     * when valid. Edit/split decisions must carry ≥1 assignment with a registry-known
     * service line and an existing role.
     *
     * @param  array<string,mixed>  $decision
     */
    public function validateDecision(array $decision): ?string
    {
        $action = $decision['action'] ?? null;
        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return 'action must be one of: '.implode(', ', self::ACTIONS).'.';
        }

        if (! in_array($action, ['edit', 'split'], true)) {
            return null;
        }

        $assignments = $decision['assignments'] ?? null;
        if (! is_array($assignments) || $assignments === []) {
            return 'edit/split decisions require at least one assignment.';
        }

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                return 'each assignment must be an object.';
            }
            if ($this->validServiceLine($assignment['service_line_code'] ?? null) === null) {
                return "unknown service line '".($assignment['service_line_code'] ?? '')."'.";
            }
            $role = $assignment['role_code'] ?? null;
            if (! is_string($role) || StaffRole::find($role) === null) {
                return "unknown role '".($role ?? '')."'.";
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return list<ResolvedAssignment>
     */
    private function assignmentsFromDecision(array $decision): array
    {
        $assignments = is_array($decision['assignments'] ?? null) ? $decision['assignments'] : [];
        $out = [];
        $primaryClaimed = false;

        foreach (array_values($assignments) as $i => $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $serviceLine = $this->validServiceLine($assignment['service_line_code'] ?? null);
            $roleCode = $assignment['role_code'] ?? null;
            if ($serviceLine === null || ! is_string($roleCode)) {
                continue;
            }

            $role = StaffRole::find($roleCode);
            if ($role === null) {
                continue;
            }

            $primary = (bool) ($assignment['primary'] ?? false);
            if ($primary) {
                $primaryClaimed = true;
            }

            $out[] = new ResolvedAssignment(
                serviceLineCode: $serviceLine,
                roleCode: $roleCode,
                confidence: 1.00,
                resolutionSource: 'override',
                evidence: ['source' => 'manual_review', 'source_field' => 'reviewer_decision'],
                unitHint: isset($assignment['unit_hint']) && $assignment['unit_hint'] !== '' ? (string) $assignment['unit_hint'] : null,
                programCode: isset($assignment['program_code']) && $assignment['program_code'] !== '' ? (string) $assignment['program_code'] : null,
                primary: $primary,
                regulated: (bool) $role->is_regulated,
            );
        }

        // Guarantee exactly one primary survives (uq_staff_one_primary) — default the first.
        if (! $primaryClaimed && $out !== []) {
            $out[0] = $out[0]->with(['primary' => true]);
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $proposedRaw
     * @return list<ResolvedAssignment>
     */
    private function rebuildProposed(array $proposedRaw): array
    {
        return array_values(array_map(
            fn (array $p): ResolvedAssignment => ResolvedAssignment::fromArray($p),
            array_filter($proposedRaw, 'is_array'),
        ));
    }

    /**
     * @param  list<int>  $deactivate
     * @return array{}
     */
    private function markDeactivate(int $staffMemberId, array &$deactivate): array
    {
        $deactivate[] = $staffMemberId;

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeItem(StagedStaffMember $i): array
    {
        return [
            'staff_member_id' => (int) $i->member->staff_member_id,
            'staff_key' => $i->member->staff_key,
            'display_name' => $i->member->display_name,
            'email' => $i->member->email,
            'employee_type' => $i->member->employee_type,
            'employment_status' => $i->member->employment_status,
            'user_id' => $i->member->user_id !== null ? (int) $i->member->user_id : null,
            'account_link' => $i->member->metadata['account_link'] ?? null,
            'source_system' => $i->member->source_system,
            'bucket' => $i->bucket,
            'conflicts' => array_map('intval', $i->conflicts),
            'proposed' => array_map(fn (ResolvedAssignment $a): array => $a->toArray(), $i->proposed),
            'record' => $i->record?->toArray(),
            'decision' => null,
        ];
    }

    private function validServiceLine(mixed $code): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        $canonical = $this->normalizer->canonical($code);

        return $this->normalizer->isKnown($canonical) ? $canonical : null;
    }
}
