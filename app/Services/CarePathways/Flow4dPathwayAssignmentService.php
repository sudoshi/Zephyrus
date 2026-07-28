<?php

declare(strict_types=1);

namespace App\Services\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Services\Patient\Pathway\PatientPathwayInstanceService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Assigns a governed pathway to an encounter that already carries an effective
 * `pathway:read` grant (the Nightingale investor-demo cohort, PR #118), then
 * records length-of-stay-driven milestone statuses — the missing "assignment"
 * layer that lets the 4D Navigator serve real (non-overlay) pathway progress
 * for the demo patients.
 *
 * This is a demonstration provisioner over SYNTHETIC cohort patients. It writes
 * the append-only assignment + status history and therefore requires the
 * catalog to be ALREADY activated (PatientPathwayInstanceService::instantiate
 * enforces version approved+active and release active+signed-off — the gated
 * governance step). Serving remains separately gated by
 * care-pathways.assignment_enabled + the flow4d flag.
 */
final class Flow4dPathwayAssignmentService
{
    public function __construct(private readonly PatientPathwayInstanceService $instances) {}

    /**
     * Assign the active+approved version of `$pathwayKey` to the encounter's
     * effective grant and record LOS-driven milestone statuses.
     *
     * @return array<string, mixed>|null null when the encounter has no effective
     *                                   pathway:read grant (nothing to assign)
     */
    public function assignByPathwayKey(
        int $sourceEncounterId,
        string $pathwayKey,
        CarbonImmutable $admittedAt,
        CarbonImmutable $now,
    ): ?array {
        $grant = PatientEncounterAccessGrant::query()
            ->effective()
            ->where('source_encounter_id', $sourceEncounterId)
            ->orderByDesc('access_grant_id')
            ->first();

        if (! $grant instanceof PatientEncounterAccessGrant || ! $grant->permits('pathway:read')) {
            return null;
        }

        $version = PathwayVersion::query()
            ->whereHas('definition', fn ($query) => $query->where('pathway_key', $pathwayKey))
            ->where('activation_status', 'active')
            ->where('institutional_approval_status', 'approved')
            ->first();

        if (! $version instanceof PathwayVersion) {
            throw new RuntimeException("no active, approved version for pathway '{$pathwayKey}' — activate the catalog first");
        }

        $reference = 'flow4d-demo/'.$sourceEncounterId.'/'.$pathwayKey;
        $instance = $this->instances->instantiate($grant, $version, 'flow4d-demo-assignment', $reference, $now);

        // LOS days; cast the Carbon 3 float BEFORE intdiv (no lossy implicit cast).
        $losDays = intdiv((int) max(0.0, $admittedAt->diffInMinutes($now, false)), 1440);

        $milestones = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->where('review_state', 'approved')
            ->orderBy('sequence')
            ->orderBy('stable_key')
            ->get();

        $counts = ['completed' => 0, 'current' => 0, 'planned' => 0];
        foreach ($milestones as $milestone) {
            $status = $this->statusForMilestone($milestone, $losDays);
            $this->instances->recordMilestoneStatus(
                $instance,
                $milestone,
                $status,
                $reference.'/'.$milestone->stable_key,
                $now,
            );
            $counts[$status]++;
        }

        return [
            'encounter_id' => $sourceEncounterId,
            'pathway_key' => $pathwayKey,
            'version_id' => (int) $version->getKey(),
            'instance_id' => (int) $instance->getKey(),
            'los_days' => $losDays,
            'milestones' => $milestones->count(),
        ] + $counts;
    }

    private function statusForMilestone(MilestoneDefinition $milestone, int $losDays): string
    {
        $range = is_array($milestone->expected_range) ? $milestone->expected_range : [];
        $day = null;
        if (isset($range['day_offset_max']) && is_numeric($range['day_offset_max'])) {
            $day = (int) $range['day_offset_max'];
        } elseif (isset($range['day_offset_min']) && is_numeric($range['day_offset_min'])) {
            $day = (int) $range['day_offset_min'];
        }

        return match (true) {
            $day === null => 'planned',
            $day < $losDays => 'completed',
            $day === $losDays => 'current',
            default => 'planned',
        };
    }
}
