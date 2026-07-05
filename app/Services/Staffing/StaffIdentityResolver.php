<?php

namespace App\Services\Staffing;

use App\Models\Org\StaffMember;
use App\Services\Staffing\Support\RawStaffRecord;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: collapses a RawStaffRecord onto a canonical hosp_org.staff_members row
 * (deduped on source_system + external_id / staff_key), then suggests an app-account
 * link to prod.users. prod.users has no NPI/employee_id column, so the only account
 * match is exact email; NPI is used to surface cross-source duplicates (conflicts),
 * never to auto-merge.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6.1)
 */
class StaffIdentityResolver
{
    /**
     * Find-or-create the staff_member for this record, refresh identity fields, and
     * suggest an app-account link. Safe to run in dry-run (staff_members is staging;
     * only staff_assignments are gated behind commit).
     */
    public function upsert(RawStaffRecord $record): StaffMember
    {
        $now = now();

        $member = StaffMember::query()
            ->where('source_system', $record->sourceSystem)
            ->where('external_id', $record->externalId)
            ->first();

        $attributes = [
            'staff_key' => $record->staffKey(),
            'source_system' => $record->sourceSystem,
            'external_id' => $record->externalId,
            'npi' => $record->npi,
            'license_no' => $record->licenseNo,
            'display_name' => $record->displayName,
            'email' => $record->email,
            'employee_type' => $record->employeeType,
            'employment_status' => $record->employmentStatus,
            'is_active' => ! $record->isTerminated(),
            'last_seen_at' => $now,
        ];

        if ($member === null) {
            $member = new StaffMember($attributes);
            $member->first_seen_at = $now;
        } else {
            $member->fill($attributes);
        }

        $this->linkAccount($member, $record);

        $member->save();

        return $member;
    }

    /**
     * Suggest a prod.users link by exact (case-insensitive) email. Only sets a link
     * when none exists; never overwrites a confirmed link. Records the method +
     * confidence in metadata for the reviewer.
     */
    public function linkAccount(StaffMember $member, RawStaffRecord $record): void
    {
        if ($member->user_id !== null || $record->email === null) {
            return;
        }

        $userId = DB::table('prod.users')
            ->whereRaw('lower(email) = ?', [strtolower($record->email)])
            ->value('id');

        if ($userId === null) {
            return;
        }

        $member->user_id = (int) $userId;
        $member->metadata = array_merge($member->metadata ?? [], [
            'account_link' => ['method' => 'email', 'confidence' => 0.95],
        ]);
    }

    /**
     * Other staff_member ids that share this member's NPI across sources (potential
     * duplicate identities — surfaced as conflicts, not auto-merged).
     *
     * @return list<int>
     */
    public function npiDuplicates(StaffMember $member): array
    {
        if ($member->npi === null || $member->npi === '') {
            return [];
        }

        return StaffMember::query()
            ->where('npi', $member->npi)
            ->where('staff_member_id', '!=', $member->staff_member_id)
            ->pluck('staff_member_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
