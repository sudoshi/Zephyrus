<?php

declare(strict_types=1);

namespace App\Services\CarePathways;

use Illuminate\Support\Facades\DB;

/**
 * Compiles a governed care-pathway version's executable milestone layer into the
 * serializable portion of the Arena sidecar reference-model spec — the shape
 * `arena/app/pathways.py::PATHWAYS` uses — plus a content-address digest so a
 * version's compiled model is stable and comparable across releases.
 *
 * FLOW-4D plan Phase D1. This is the governed-catalog → conformance bridge, and
 * it is deliberately a DECLARATIVE compiler:
 *
 *   - It emits label / version / owner / case_type / trigger / ordered
 *     activities / timing targets / deviation labels — everything a clinical
 *     reviewer needs to read the reference model.
 *   - It does NOT emit the sidecar's `evaluate` callable. That is imperative
 *     timing logic (SEP-3's 3-hour window, the AHCAH visit floor); deriving it
 *     from catalog data is the open question D2's OCEL-coverage report and D3's
 *     CPG-on-FHIR/CQL memo own. Nothing here is executed against live cases —
 *     Phase D ships dark behind the deferred serving flags (plan G-9).
 *
 * `compile()` is pure (no I/O, deterministic). `compileVersion()` is the thin
 * DB adapter; it returns null for a version with no persisted executable layer,
 * which — for the current inactive catalog release — is every version. That is
 * the honest state, not an error.
 */
final class ReferenceModelCompiler
{
    /** DRG care pathways are encounter-bound; the OCEL case object is the Encounter. */
    private const CASE_TYPE = 'Encounter';

    /**
     * Compile a version's declarative reference model. Pure.
     *
     * @param  array<string, mixed>  $meta  pathway_key, label, semantic_version, service_line_code, care_type, source_service_line, and (adapter-only) *_uuid / grouper_version
     * @param  iterable<array<string, mixed>>  $milestones  each: stable_key, title, phase, sequence, expected_range
     * @return array<string, mixed>|null null when the version has no milestones (no executable layer to compile)
     */
    public function compile(array $meta, iterable $milestones): ?array
    {
        $ordered = $this->orderMilestones($milestones);
        if ($ordered === []) {
            return null;
        }

        $pathwayKey = (string) ($meta['pathway_key'] ?? '');
        $label = (string) ($meta['label'] ?? $pathwayKey);
        $semanticVersion = isset($meta['semantic_version']) ? (string) $meta['semantic_version'] : null;

        $activities = array_map(static fn (array $m): string => $m['stable_key'], $ordered);
        $timingTargets = [];
        $deviationLabels = [];
        $milestonesOut = [];

        foreach ($ordered as $m) {
            $key = $m['stable_key'];
            $title = $m['title'] !== '' ? $m['title'] : $this->humanize($key);
            [$min, $max, $display] = $m['timing'];

            if ($min !== null || $max !== null || $display !== null) {
                $timingTargets[] = [
                    'activity' => $key,
                    'day_offset_min' => $min,
                    'day_offset_max' => $max,
                    'display' => $display,
                ];
            }

            // Data-driven deviation vocabulary: a "not yet observed" code for
            // every milestone, and a "late" code wherever a numeric day ceiling
            // exists. Codes are stable (keyed on the milestone stable_key).
            $deviationLabels[$key.'_missing'] = $title.' not yet observed';
            if ($max !== null) {
                $deviationLabels[$key.'_late'] = $title.' beyond day '.$max;
            }

            $milestonesOut[] = [
                'stable_key' => $key,
                'title' => $title,
                'phase' => $m['phase'],
                'sequence' => $m['sequence'],
                'day_offset_min' => $min,
                'day_offset_max' => $max,
                'display' => $display,
            ];
        }

        $body = [
            'pathway_key' => $pathwayKey,
            'label' => $label,
            'version' => $this->majorVersion($semanticVersion),
            'semantic_version' => $semanticVersion,
            'owner' => $this->owner($meta),
            'case_type' => self::CASE_TYPE,
            'trigger' => $activities[0],
            'activities' => $activities,
            'timing_targets' => $timingTargets,
            'deviation_labels' => $deviationLabels,
        ];

        return $body + [
            'milestones' => $milestonesOut,
            'source' => [
                'pathway_version_uuid' => isset($meta['pathway_version_uuid']) ? (string) $meta['pathway_version_uuid'] : null,
                'semantic_version' => $semanticVersion,
                'catalog_release_uuid' => isset($meta['catalog_release_uuid']) ? (string) $meta['catalog_release_uuid'] : null,
                'grouper_version' => isset($meta['grouper_version']) ? (string) $meta['grouper_version'] : null,
            ],
            'digest' => $this->digest($body),
        ];
    }

    /**
     * Load a governed version's definition metadata + persisted milestone layer
     * and compile it. Read-only; never writes. Returns null when the version is
     * unknown or has no milestone_definitions rows.
     *
     * @return array<string, mixed>|null
     */
    public function compileVersion(int $pathwayVersionId): ?array
    {
        $version = DB::table('care_pathways.versions as versions')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->join('care_pathways.catalog_releases as releases', 'releases.catalog_release_id', '=', 'versions.catalog_release_id')
            ->where('versions.pathway_version_id', $pathwayVersionId)
            ->select([
                'versions.pathway_version_uuid',
                'versions.semantic_version',
                'definitions.pathway_key',
                'definitions.canonical_name',
                'definitions.care_type',
                'definitions.source_service_line',
                'definitions.service_line_code',
                'releases.catalog_release_uuid',
                'releases.grouper_version',
            ])
            ->first();

        if ($version === null) {
            return null;
        }

        $milestones = DB::table('care_pathways.milestone_definitions')
            ->where('pathway_version_id', $pathwayVersionId)
            ->orderBy('sequence')
            ->orderBy('stable_key')
            ->get(['stable_key', 'title', 'phase', 'sequence', 'expected_range'])
            ->map(static fn (object $row): array => [
                'stable_key' => (string) $row->stable_key,
                'title' => (string) ($row->title ?? ''),
                'phase' => $row->phase !== null ? (string) $row->phase : null,
                'sequence' => $row->sequence !== null ? (int) $row->sequence : null,
                'expected_range' => self::decodeJsonObject($row->expected_range),
            ])
            ->all();

        return $this->compile([
            'pathway_key' => (string) $version->pathway_key,
            'label' => (string) $version->canonical_name,
            'semantic_version' => (string) $version->semantic_version,
            'care_type' => $version->care_type,
            'source_service_line' => $version->source_service_line,
            'service_line_code' => $version->service_line_code,
            'pathway_version_uuid' => (string) $version->pathway_version_uuid,
            'catalog_release_uuid' => (string) $version->catalog_release_uuid,
            'grouper_version' => (string) $version->grouper_version,
        ], $milestones);
    }

    /**
     * Normalize + deterministically order the milestone rows: sequence ascending
     * with nulls last, then stable_key ascending as a stable tiebreak.
     *
     * @param  iterable<array<string, mixed>>  $milestones
     * @return list<array{stable_key:string, title:string, phase:?string, sequence:?int, timing:array{0:?int,1:?int,2:?string}}>
     */
    private function orderMilestones(iterable $milestones): array
    {
        $rows = [];
        foreach ($milestones as $m) {
            $key = trim((string) ($m['stable_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'stable_key' => $key,
                'title' => trim((string) ($m['title'] ?? '')),
                'phase' => isset($m['phase']) && $m['phase'] !== '' ? (string) $m['phase'] : null,
                'sequence' => isset($m['sequence']) && $m['sequence'] !== null ? (int) $m['sequence'] : null,
                'timing' => $this->timing($m['expected_range'] ?? []),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $sa = $a['sequence'] ?? PHP_INT_MAX;
            $sb = $b['sequence'] ?? PHP_INT_MAX;

            return $sa <=> $sb ?: strcmp($a['stable_key'], $b['stable_key']);
        });

        return $rows;
    }

    /**
     * Read a milestone's timing target from its flexible expected_range object:
     * numeric day_offset_min / day_offset_max when present, and a human display
     * string when the range is expressed that way ({display:'Today'}).
     *
     * @return array{0:?int, 1:?int, 2:?string}
     */
    private function timing(mixed $expectedRange): array
    {
        $range = is_array($expectedRange) ? $expectedRange : [];

        $min = array_key_exists('day_offset_min', $range) && is_numeric($range['day_offset_min'])
            ? (int) $range['day_offset_min'] : null;
        $max = array_key_exists('day_offset_max', $range) && is_numeric($range['day_offset_max'])
            ? (int) $range['day_offset_max'] : null;
        $display = isset($range['display']) && $range['display'] !== '' ? (string) $range['display'] : null;

        return [$min, $max, $display];
    }

    /** @param array<string, mixed> $meta */
    private function owner(array $meta): string
    {
        $line = $meta['service_line_code'] ?? $meta['source_service_line'] ?? $meta['care_type'] ?? null;
        $slug = $this->slug((string) ($line ?? ''));

        return 'clinical:'.($slug !== '' ? $slug : 'unassigned');
    }

    private function majorVersion(?string $semanticVersion): int
    {
        if ($semanticVersion === null) {
            return 1;
        }
        if (preg_match('/\d+/', $semanticVersion, $m) === 1) {
            return (int) $m[0];
        }

        return 1;
    }

    /** @param array<string, mixed> $body */
    private function digest(array $body): string
    {
        return hash('sha256', (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function humanize(string $key): string
    {
        return ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Decode a jsonb column that Postgres returns as a string (or already-cast
     * array) into an associative array, tolerantly.
     *
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
