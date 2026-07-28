<?php

declare(strict_types=1);

namespace App\Services\CarePathways;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only projection of a patient's assigned governed-pathway progress for the
 * 4D Navigator journey drawer (FLOW-4D plan Phase D4).
 *
 * Dark by design. Two independent gates must both pass before this service
 * returns anything:
 *   1. `care-pathways.assignment_enabled` — the DRG-catalog serving gate. Off
 *      until [SU]/clinical sign-off (plan G-9).
 *   2. The `patient_experience.pathway_instances` tables must exist. On the
 *      current dev/prod database they are absent; the service is inert.
 *
 * Reads only. Latest status comes from the append-only status EVENTS via the
 * `current_pathway_*_statuses` views (DISTINCT ON latest observation); the base
 * status facts are never mutated. The emitted DTO shape is shared with the
 * synthetic demo overlay (D5) so the drawer renders both identically.
 */
final class PathwayInstanceReadService
{
    /** Serving gate + schema guard. Both must hold for any real progress to surface. */
    public function available(): bool
    {
        return (bool) config('care-pathways.assignment_enabled')
            && Schema::hasTable('patient_experience.pathway_instances');
    }

    /**
     * Resolve the active encounter access grant for a flow encounter id and
     * project its latest assigned-pathway progress. Null when the service is
     * gated off, the tables are absent, no active grant exists, or the encounter
     * carries no pathway instance. This is the seam the journey spine calls.
     *
     * @return array<string, mixed>|null
     */
    public function progressForEncounterId(?int $sourceEncounterId): ?array
    {
        if ($sourceEncounterId === null || ! $this->available()) {
            return null;
        }

        $accessGrantId = DB::table('patient_experience.encounter_access_grants')
            ->where('source_encounter_id', $sourceEncounterId)
            ->where('status', 'active')
            ->orderByDesc('access_grant_id')
            ->value('access_grant_id');

        return $accessGrantId === null ? null : $this->progressForAccessGrant((int) $accessGrantId);
    }

    /**
     * Project the latest assigned-pathway progress for one encounter access
     * grant. Read-only; append-only-respecting.
     *
     * @return array<string, mixed>|null
     */
    public function progressForAccessGrant(int $accessGrantId): ?array
    {
        if (! $this->available()) {
            return null;
        }

        // Explicit column list: this table also carries an encrypted source
        // identity column we never touch — never pull it into memory.
        $instance = DB::table('patient_experience.pathway_instances')
            ->where('access_grant_id', $accessGrantId)
            ->orderByDesc('instantiated_at')
            ->orderByDesc('pathway_instance_id')
            ->first(['pathway_instance_id', 'pathway_version_id', 'recorded_at']);

        if ($instance === null) {
            return null;
        }

        $version = DB::table('care_pathways.versions as versions')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->where('versions.pathway_version_id', $instance->pathway_version_id)
            ->first([
                'versions.pathway_version_uuid',
                'versions.semantic_version',
                'definitions.pathway_key',
                'definitions.canonical_name',
            ]);

        if ($version === null) {
            return null;
        }

        $milestones = DB::table('patient_experience.pathway_milestone_instances as mi')
            ->join('care_pathways.milestone_definitions as md', 'md.milestone_definition_id', '=', 'mi.milestone_definition_id')
            ->leftJoin('patient_experience.current_pathway_milestone_statuses as cs', 'cs.pathway_milestone_instance_id', '=', 'mi.pathway_milestone_instance_id')
            ->where('mi.pathway_instance_id', $instance->pathway_instance_id)
            ->orderBy('md.sequence')
            ->orderBy('md.stable_key')
            ->get([
                'md.stable_key',
                'md.title',
                'md.phase',
                'md.sequence',
                'md.expected_range',
                'cs.status',
                'cs.source_observed_at',
            ])
            ->map(fn (object $row): array => [
                'stable_key' => (string) $row->stable_key,
                'title' => (string) ($row->title ?? ''),
                'phase' => $row->phase !== null ? (string) $row->phase : null,
                'sequence' => $row->sequence !== null ? (int) $row->sequence : null,
                'status' => $row->status !== null ? (string) $row->status : 'planned',
                'observed_at' => $this->iso($row->source_observed_at),
                'expected' => $this->expected($row->expected_range),
            ])
            ->all();

        return $this->assemble(
            demo: false,
            notice: null,
            pathway: [
                'key' => (string) $version->pathway_key,
                'label' => (string) $version->canonical_name,
                'version_uuid' => (string) $version->pathway_version_uuid,
                'semantic_version' => (string) $version->semantic_version,
            ],
            milestones: $milestones,
            source: 'assigned_instance',
            asOf: $this->iso($instance->recorded_at) ?? CarbonImmutable::now()->toJSON(),
        );
    }

    /**
     * Assemble the shared progress DTO from an ordered milestone list. Reused by
     * the demo overlay (D5) so the drawer renders real and synthetic identically.
     *
     * @param  array<string, mixed>  $pathway
     * @param  list<array<string, mixed>>  $milestones
     * @return array<string, mixed>
     */
    public function assemble(bool $demo, ?string $notice, array $pathway, array $milestones, string $source, string $asOf): array
    {
        $counts = ['completed' => 0, 'current' => 0, 'planned' => 0, 'delayed' => 0, 'canceled' => 0];
        $currentStableKey = null;

        foreach ($milestones as $m) {
            $status = (string) ($m['status'] ?? 'planned');
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
            if ($currentStableKey === null && $status === 'current') {
                $currentStableKey = (string) $m['stable_key'];
            }
        }

        $total = count($milestones);

        return [
            'state' => 'ok',
            'demo' => $demo,
            // Phase D serves nothing clinical: real progress is dark behind the
            // serving gate, and the demo is explicitly synthetic. This is always
            // false — the drawer must never present pathway state as guidance.
            'clinical_use' => false,
            'notice' => $notice,
            'pathway' => $pathway,
            'milestones' => $milestones,
            'summary' => $counts + [
                'total' => $total,
                'current_stable_key' => $currentStableKey,
                // ERAS "elements met" framing, matching the adherence panel.
                'elements_met_label' => $counts['completed'].' of '.$total.' milestones complete',
            ],
            'source' => $source,
            'as_of' => $asOf,
        ];
    }

    /**
     * Decode a milestone's flexible expected_range into a stable timing shape.
     *
     * @return array{day_offset_min:?int, day_offset_max:?int, display:?string}
     */
    private function expected(mixed $expectedRange): array
    {
        $range = [];
        if (is_array($expectedRange)) {
            $range = $expectedRange;
        } elseif (is_string($expectedRange) && $expectedRange !== '') {
            try {
                $decoded = json_decode($expectedRange, true, 512, JSON_THROW_ON_ERROR);
                $range = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $range = [];
            }
        }

        return [
            'day_offset_min' => isset($range['day_offset_min']) && is_numeric($range['day_offset_min']) ? (int) $range['day_offset_min'] : null,
            'day_offset_max' => isset($range['day_offset_max']) && is_numeric($range['day_offset_max']) ? (int) $range['day_offset_max'] : null,
            'display' => isset($range['display']) && $range['display'] !== '' ? (string) $range['display'] : null,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->toJSON();
        } catch (Throwable) {
            return null;
        }
    }
}
