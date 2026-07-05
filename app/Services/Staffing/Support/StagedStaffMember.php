<?php

namespace App\Services\Staffing\Support;

use App\Models\Org\StaffMember;

/**
 * Phase 7: one staged person inside an import run — the canonical staff_member, the
 * source record that produced it, the resolver's proposed memberships, the assigned
 * review bucket, and any conflicting existing-assignment ids.
 */
final class StagedStaffMember
{
    /**
     * @param  list<ResolvedAssignment>  $proposed
     * @param  list<int>  $conflicts
     */
    public function __construct(
        public readonly StaffMember $member,
        public readonly ?RawStaffRecord $record,
        public array $proposed = [],
        public string $bucket = 'unmatched',
        public array $conflicts = [],
    ) {}
}
