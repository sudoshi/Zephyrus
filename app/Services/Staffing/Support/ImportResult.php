<?php

namespace App\Services\Staffing\Support;

use App\Models\Org\StaffImportRun;

/**
 * Phase 7: the outcome of staging + resolving an import run — the run model plus
 * every staged person, each in one of the five review buckets (auto_approved /
 * needs_review / conflicts / unmatched / departed). The wizard's Review Queue and
 * the sync command both read this.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§5.1)
 */
final class ImportResult
{
    public const BUCKETS = ['auto_approved', 'needs_review', 'conflicts', 'unmatched', 'departed'];

    /**
     * @param  list<StagedStaffMember>  $items
     */
    public function __construct(
        public readonly StaffImportRun $run,
        public readonly array $items = [],
    ) {}

    /**
     * @return list<StagedStaffMember>
     */
    public function bucket(string $name): array
    {
        return array_values(array_filter($this->items, fn (StagedStaffMember $i): bool => $i->bucket === $name));
    }

    /**
     * Per-bucket counts (+ total), the shape stored on staff_import_runs.counts.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = ['total' => count($this->items)];
        foreach (self::BUCKETS as $bucket) {
            $counts[$bucket] = count($this->bucket($bucket));
        }

        return $counts;
    }
}
