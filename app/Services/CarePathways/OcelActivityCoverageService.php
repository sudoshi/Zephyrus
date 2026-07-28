<?php

declare(strict_types=1);

namespace App\Services\CarePathways;

use Illuminate\Support\Facades\DB;

/**
 * Reports how much of the governed catalog's compiled milestone vocabulary maps
 * to Arena OCEL activities (FLOW-4D plan Phase D2) — the honest gap list a
 * clinical mapping review needs before any per-patient conformance could ever be
 * served from the DRG catalog.
 *
 * Read-only. Operates over the persisted executable layer (milestone_definitions);
 * for the current inactive catalog release that layer is empty, so the report is
 * honestly empty until milestones are authored (care-pathways:draft-executable-layer).
 */
final class OcelActivityCoverageService
{
    public function __construct(private readonly ReferenceModelCompiler $compiler) {}

    /**
     * @return array{
     *   pathways: list<array<string, mixed>>,
     *   totals: array{executable_versions:int, activities:int, mapped:int, unmapped:int, unknown_targets:int, coverage:?float}
     * }
     */
    public function report(): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('care-pathways-ocel-map.activities', []);
        $vocabulary = array_flip((array) config('care-pathways-ocel-map.ocel_vocabulary', []));

        $versionIds = DB::table('care_pathways.milestone_definitions')
            ->distinct()
            ->orderBy('pathway_version_id')
            ->pluck('pathway_version_id');

        $pathways = [];
        $totalActivities = 0;
        $totalMapped = 0;
        $totalUnknown = 0;

        foreach ($versionIds as $versionId) {
            $model = $this->compiler->compileVersion((int) $versionId);
            if ($model === null) {
                continue;
            }

            $unmapped = [];
            $unknownTargets = [];
            $mapped = 0;

            foreach ($model['activities'] as $activity) {
                $target = $map[$activity] ?? null;
                if ($target === null || $target === '') {
                    $unmapped[] = $activity;

                    continue;
                }
                $mapped++;
                if (! isset($vocabulary[$target])) {
                    $unknownTargets[] = $activity.' → '.$target;
                }
            }

            $count = count($model['activities']);
            $totalActivities += $count;
            $totalMapped += $mapped;
            $totalUnknown += count($unknownTargets);

            $pathways[] = [
                'pathway_key' => $model['pathway_key'],
                'label' => $model['label'],
                'version_uuid' => $model['source']['pathway_version_uuid'],
                'activities' => $count,
                'mapped' => $mapped,
                'unmapped' => $unmapped,
                'unknown_targets' => $unknownTargets,
                'coverage' => $count > 0 ? round($mapped / $count, 4) : null,
            ];
        }

        return [
            'pathways' => $pathways,
            'totals' => [
                'executable_versions' => count($pathways),
                'activities' => $totalActivities,
                'mapped' => $totalMapped,
                'unmapped' => $totalActivities - $totalMapped,
                'unknown_targets' => $totalUnknown,
                'coverage' => $totalActivities > 0 ? round($totalMapped / $totalActivities, 4) : null,
            ],
        ];
    }
}
