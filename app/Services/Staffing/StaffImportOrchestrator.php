<?php

namespace App\Services\Staffing;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffImportRun;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMappingReview;
use App\Models\Org\StaffMember;
use App\Models\Reference\StaffRole;
use App\Services\Staffing\Contracts\StaffingConnector;
use App\Services\Staffing\Support\ImportResult;
use App\Services\Staffing\Support\PullWindow;
use App\Services\Staffing\Support\RawStaffRecord;
use App\Services\Staffing\Support\ResolvedAssignment;
use App\Services\Staffing\Support\StagedStaffMember;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: owns the staff_import_run lifecycle (staged -> resolved -> committed),
 * sorts every resolved person into the five review buckets, and is the ONLY writer
 * of staff_assignments (on commit). Dry-run by default; commit is a separate,
 * explicit call. Account provisioning routes exclusively through
 * StaffProvisioningService (additive, auth-safe).
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§5.1, §6.1)
 */
class StaffImportOrchestrator
{
    private const AUTO_APPROVE_THRESHOLD = 0.90;

    public function __construct(
        private readonly StaffIdentityResolver $identity,
        private readonly ServiceLineRoleResolver $resolver,
        private readonly StaffProvisioningService $provisioning,
    ) {}

    /**
     * Stage + resolve + bucket an import (no assignments written). Persists
     * staff_members (staging) and the run's counts.
     *
     * @param  array{initiated_by?:int, dry_run?:bool}  $opts
     */
    public function run(StaffingSource $source, StaffingConnector $connector, string $facilityKey, PullWindow $window, array $opts = []): ImportResult
    {
        $run = StaffImportRun::create([
            'staffing_source_id' => $source->staffing_source_id,
            'status' => 'staged',
            'mapping_snapshot' => $source->mapping_template ?? [],
            'dry_run' => $opts['dry_run'] ?? true,
            'initiated_by' => $opts['initiated_by'] ?? null,
        ]);

        return $this->stageAndResolve($run, $source, $connector->pullStaff($window), $facilityKey);
    }

    /**
     * Re-run resolution over already-staged records (rebuilt from the run's staged
     * snapshot) against the CURRENT rules — the "promote a rule, shrink the queue"
     * loop (§14) without re-pulling from the connector. Reuses the same run row.
     *
     * @param  iterable<RawStaffRecord>  $records
     */
    public function reresolve(StaffImportRun $run, StaffingSource $source, iterable $records, string $facilityKey): ImportResult
    {
        return $this->stageAndResolve($run, $source, $records, $facilityKey);
    }

    /**
     * Shared stage + resolve + bucket loop: upsert each record's staff_member (safe in
     * dry-run — staff_members is staging), resolve to proposed memberships, bucket, and
     * append source-absent members as 'departed'. Writes the run's counts and flips it
     * to 'resolved'. The ONLY caller-visible side effect on staff_assignments is none —
     * commit() is a separate, explicit step.
     *
     * @param  iterable<RawStaffRecord>  $records
     */
    private function stageAndResolve(StaffImportRun $run, StaffingSource $source, iterable $records, string $facilityKey): ImportResult
    {
        $rules = $this->loadRules($source);
        $regulatedRoles = $this->regulatedRoles();

        $items = [];
        $seenKeys = [];
        $new = 0;

        foreach ($records as $record) {
            $member = $this->identity->upsert($record);
            $seenKeys[] = $member->staff_key;
            if ($member->wasRecentlyCreated) {
                $new++;
            }

            $proposed = $this->resolver->resolve(
                $record,
                $rules,
                $this->overridesFor($member),
                $regulatedRoles,
            );

            $facility = $this->facilityFor($record, $facilityKey);
            $conflicts = $this->conflictsFor($member, $facility, $proposed);
            $bucket = $this->bucketFor($proposed, $conflicts);

            $items[] = new StagedStaffMember($member, $record, $proposed, $bucket, $conflicts);
        }

        foreach ($this->departedMembers($source, $seenKeys) as $member) {
            $items[] = new StagedStaffMember($member, null, [], 'departed');
        }

        $result = new ImportResult($run, $items);

        $run->update([
            'status' => 'resolved',
            'counts' => array_merge($result->counts(), [
                'new' => $new,
                'updated' => count($seenKeys) - $new,
            ]),
        ]);

        return $result;
    }

    /**
     * Commit the requested buckets: write staff_assignments, provision linked
     * accounts (additively), and log a staff_mapping_review per member. Idempotent —
     * re-committing upserts on the natural key. Defaults to auto_approved only.
     *
     * @param  array{buckets?:list<string>, reviewer_id?:int, facility_key?:string}  $opts
     * @return array<string, int>
     */
    public function commit(ImportResult $result, string $facilityKey, array $opts = []): array
    {
        $buckets = $opts['buckets'] ?? ['auto_approved'];
        $reviewerId = $opts['reviewer_id'] ?? null;

        $summary = ['members' => 0, 'assignments' => 0, 'provisioned' => 0, 'reviews' => 0];

        foreach ($buckets as $bucketName) {
            foreach ($result->bucket($bucketName) as $item) {
                if ($item->proposed === []) {
                    continue;
                }

                $facility = $item->record !== null ? $this->facilityFor($item->record, $facilityKey) : $facilityKey;

                DB::transaction(function () use ($item, $facility, $reviewerId, $result, &$summary): void {
                    $written = $this->writeAssignments($item, $facility, $reviewerId);
                    $summary['assignments'] += $written;
                    $summary['members']++;

                    $summary['provisioned'] += $this->provisionMember($item);

                    StaffMappingReview::create([
                        'staff_import_run_id' => $result->run->staff_import_run_id,
                        'staff_member_id' => $item->member->staff_member_id,
                        'proposed' => array_map(fn (ResolvedAssignment $a): array => $a->toArray(), $item->proposed),
                        'final' => array_map(fn (ResolvedAssignment $a): array => $a->toArray(), $item->proposed),
                        'action' => 'accept',
                        'reviewer_id' => $reviewerId,
                    ]);
                    $summary['reviews']++;
                });
            }
        }

        $result->run->update(['status' => 'committed', 'completed_at' => now()]);

        return $summary;
    }

    /**
     * Write (upsert) a member's proposed assignments. Clears the member's primary
     * flags first so exactly one primary survives (uq_staff_one_primary).
     */
    private function writeAssignments(StagedStaffMember $item, string $facilityKey, ?int $reviewerId): int
    {
        StaffAssignment::query()
            ->where('staff_member_id', $item->member->staff_member_id)
            ->update(['primary_flag' => false]);

        $now = now();
        $written = 0;

        foreach ($item->proposed as $proposal) {
            $unitId = $this->resolveUnitId($facilityKey, $proposal->unitHint);

            StaffAssignment::updateOrCreate(
                [
                    'staff_member_id' => $item->member->staff_member_id,
                    'facility_key' => $facilityKey,
                    'service_line_code' => $proposal->serviceLineCode,
                    'role_code' => $proposal->roleCode,
                    'unit_id' => $unitId,
                ],
                [
                    'program_code' => $proposal->programCode,
                    'primary_flag' => $proposal->primary,
                    'coverage_model' => $this->coverageModel($item->record),
                    'fte' => $item->record?->fte,
                    'confidence' => $proposal->confidence,
                    'resolution_source' => $proposal->resolutionSource,
                    'review_status' => $this->reviewStatusFor($proposal),
                    'evidence' => $proposal->evidence,
                    'effective_start' => $now->toDateString(),
                    'is_active' => true,
                    'decided_by' => $reviewerId,
                    'decided_at' => $now,
                ],
            );
            $written++;
        }

        return $written;
    }

    private function provisionMember(StagedStaffMember $item): int
    {
        if ($item->member->user_id === null || $item->proposed === []) {
            return 0;
        }

        $primary = null;
        foreach ($item->proposed as $proposal) {
            if ($proposal->primary) {
                $primary = $proposal;
                break;
            }
        }
        $primary ??= $item->proposed[0];

        $role = StaffRole::find($primary->roleCode);
        if ($role === null) {
            return 0;
        }

        $delta = $this->provisioning->provisionFromAssignment($item->member, $role);

        return ($delta['provisioned'] ?? false) ? 1 : 0;
    }

    /**
     * @param  list<ResolvedAssignment>  $proposed
     * @param  list<int>  $conflicts
     */
    private function bucketFor(array $proposed, array $conflicts): string
    {
        if ($proposed === []) {
            return 'unmatched';
        }

        // Regulated roles are NEVER auto-approved (require explicit reviewer + evidence).
        foreach ($proposed as $proposal) {
            if ($proposal->regulated) {
                return 'needs_review';
            }
        }

        if ($conflicts !== []) {
            return 'conflicts';
        }

        foreach ($proposed as $proposal) {
            $deterministic = in_array($proposal->resolutionSource, ['override', 'rule'], true);
            if (! $deterministic || $proposal->confidence < self::AUTO_APPROVE_THRESHOLD) {
                return 'needs_review';
            }
        }

        return 'auto_approved';
    }

    /**
     * Existing active assignment ids for this member+facility that the current
     * proposals change (role change on the same service line, or a moved membership).
     *
     * @param  list<ResolvedAssignment>  $proposed
     * @return list<int>
     */
    private function conflictsFor(StaffMember $member, string $facilityKey, array $proposed): array
    {
        if ($member->staff_member_id === null) {
            return [];
        }

        $existing = StaffAssignment::query()
            ->where('staff_member_id', $member->staff_member_id)
            ->where('facility_key', $facilityKey)
            ->where('is_active', true)
            ->get(['staff_assignment_id', 'service_line_code', 'role_code']);

        if ($existing->isEmpty() || $proposed === []) {
            return [];
        }

        $proposedPairs = [];
        $proposedServiceLines = [];
        foreach ($proposed as $p) {
            $proposedPairs[$p->serviceLineCode.'|'.$p->roleCode] = true;
            $proposedServiceLines[$p->serviceLineCode] = true;
        }

        $conflicts = [];
        foreach ($existing as $row) {
            $pair = $row->service_line_code.'|'.$row->role_code;
            if (isset($proposedPairs[$pair])) {
                continue; // unchanged — no conflict
            }
            // Same service line, different role (role change) is the canonical conflict.
            if (isset($proposedServiceLines[$row->service_line_code])) {
                $conflicts[] = (int) $row->staff_assignment_id;
            }
        }

        return $conflicts;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function loadRules(StaffingSource $source)
    {
        return DB::table('hosp_org.staff_mapping_rules')
            ->where('is_active', true)
            ->where(function ($q) use ($source): void {
                $q->whereNull('staffing_source_id')
                    ->orWhere('staffing_source_id', $source->staffing_source_id);
            })
            ->orderBy('priority')
            ->get();
    }

    /**
     * @return array<string, bool>
     */
    private function regulatedRoles(): array
    {
        return StaffRole::query()
            ->where('is_regulated', true)
            ->pluck('role_code')
            ->mapWithKeys(fn (string $code): array => [$code => true])
            ->all();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function overridesFor(StaffMember $member): array
    {
        $pins = StaffAssignment::query()
            ->where('staff_member_id', $member->staff_member_id)
            ->where('resolution_source', 'override')
            ->where('is_active', true)
            ->get(['service_line_code', 'role_code', 'program_code'])
            ->map(fn ($a): array => [
                'service_line_code' => $a->service_line_code,
                'role_code' => $a->role_code,
                'program_code' => $a->program_code,
            ])
            ->all();

        return $pins === [] ? [] : [$member->staff_key => $pins];
    }

    /**
     * @param  list<string>  $seenKeys
     * @return \Illuminate\Support\Collection<int, StaffMember>
     */
    private function departedMembers(StaffingSource $source, array $seenKeys)
    {
        return StaffMember::query()
            ->where('source_system', $source->source_key)
            ->where('is_active', true)
            ->when($seenKeys !== [], fn ($q) => $q->whereNotIn('staff_key', $seenKeys))
            ->get();
    }

    private function facilityFor(RawStaffRecord $record, string $default): string
    {
        $fromRow = $record->raw['facility_key'] ?? null;

        return is_string($fromRow) && $fromRow !== '' ? $fromRow : $default;
    }

    private function resolveUnitId(string $facilityKey, ?string $unitHint): ?int
    {
        if ($unitHint === null || $unitHint === '') {
            return null;
        }

        $unitId = DB::table('prod.units as u')
            ->join('hosp_space.facility_spaces as fs', 'fs.facility_space_id', '=', 'u.facility_space_id')
            ->where('fs.facility_key', $facilityKey)
            ->where('u.is_deleted', false)
            ->where(function ($q) use ($unitHint): void {
                $q->whereRaw('lower(u.abbreviation) = ?', [strtolower($unitHint)])
                    ->orWhereRaw('lower(u.name) = ?', [strtolower($unitHint)]);
            })
            ->value('u.unit_id');

        return $unitId !== null ? (int) $unitId : null;
    }

    private function reviewStatusFor(ResolvedAssignment $proposal): string
    {
        if ($proposal->regulated) {
            return 'assumed'; // regulated needs explicit reviewer + credentialing evidence
        }

        return match ($proposal->resolutionSource) {
            'override' => 'client_verified',
            'rule' => 'source_verified',
            default => 'assumed',
        };
    }

    private function coverageModel(?RawStaffRecord $record): ?string
    {
        return $record?->raw['coverage_model'] ?? null;
    }
}
