<?php

namespace App\Services\PatientFlow;

use App\Domain\Arena\ArenaService;
use App\Services\Flow\FlowLensService;
use App\Services\Mobile\MobilePatientContextService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk per-patient pathway-deviation flags for the 4D scene (FLOW-4D plan
 * §7.2 / §8 C3). Answers one question for the deviation glyph layer and the
 * "Pathway deviations" census scope: WHICH currently-visible patients carry a
 * cached non-conformant case verdict, keyed by the same opaque ptok the
 * scene's event rows already use — the client joins by ref string, never
 * identity.
 *
 * Scoping parity is the load-bearing property: patients are enumerated from
 * the SAME event window/filters the scene renders and pass the SAME
 * FlowLensService::canViewPatientRow gate, so a unit-scoped persona learns
 * deviation state for exactly the tokens their scene shows — nothing wider.
 * Reads the arena.case_conformance cache only (RefreshArenaConformance owns
 * writes); case oids and raw refs stay server-side.
 */
class PathwayDeviationSceneService
{
    /**
     * The batch cadence stated to the operator (plan C4 freshness honesty).
     * Mirrors the RefreshArenaConformance schedule in bootstrap/app.php
     * (everyThirtyMinutes) — update BOTH if the cadence ever changes.
     */
    public const BATCH_CADENCE_MINUTES = 30;

    public function __construct(
        private readonly FlowEventRepository $events,
        private readonly FlowLensService $lens,
        private readonly MobilePatientContextService $patientContext,
        private readonly ArenaService $arena,
    ) {}

    /**
     * @param  array{lens: array<string, mixed>, scope: array<string, mixed>, depth: string, task_refs: list<string>, visible_unit_ids: list<int>}  $context
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $context, array $filters): array
    {
        if (! Schema::hasTable('arena.case_conformance')) {
            return [
                'available' => false,
                'reason' => 'cache_missing',
                'patients' => [],
                'as_of' => null,
                'cadence_minutes' => self::BATCH_CADENCE_MINUTES,
            ];
        }

        // Raw refs are held only long enough to authorize + hash — the same
        // discipline PatientFlowEventAccessService documents.
        $patientRefs = [];
        foreach ($this->events->serializeEvents($this->events->filteredEvents($filters)) as $row) {
            $ref = isset($row['patient_id']) && is_scalar($row['patient_id']) ? (string) $row['patient_id'] : null;
            if ($ref === null || $ref === '' || isset($patientRefs[$ref])) {
                continue;
            }

            if ($this->lens->canViewPatientRow(
                $row,
                $context['depth'],
                $context['scope'],
                $context['visible_unit_ids'],
                $context['task_refs'],
            )) {
                $patientRefs[$ref] = true;
            }
        }

        $oidsByPatient = $this->arena->caseOidsForPatients(array_keys($patientRefs));

        $refByOid = [];
        foreach ($oidsByPatient as $ref => $oids) {
            foreach ($oids as $oid) {
                $refByOid[$oid] = $ref;
            }
        }

        if ($refByOid === []) {
            return $this->payload([], null);
        }

        $rows = DB::table('arena.case_conformance')
            ->whereIn('case_oid', array_keys($refByOid))
            ->where('conformant', false)
            ->orderBy('pathway')
            ->get();

        $asOf = null;
        $byPatient = [];
        foreach ($rows as $row) {
            $ref = $refByOid[(string) $row->case_oid] ?? null;
            if ($ref === null) {
                continue;
            }

            $computedAt = Carbon::parse((string) $row->computed_at);
            if ($asOf === null || $computedAt->gt($asOf)) {
                $asOf = $computedAt;
            }

            $pathway = (string) $row->pathway;
            $entry = $byPatient[$ref][$pathway] ?? [
                'pathway' => $pathway,
                'pathway_version' => (int) $row->pathway_version,
                'deviations' => [],
            ];
            $codes = json_decode((string) $row->deviations, true) ?: [];
            $entry['deviations'] = array_values(array_unique(array_merge(
                $entry['deviations'],
                array_values(array_filter($codes, 'is_string')),
            )));
            $byPatient[$ref][$pathway] = $entry;
        }

        $patients = [];
        foreach ($byPatient as $ref => $pathways) {
            $contextRef = $this->patientContext->contextRefFor((string) $ref);
            if ($contextRef === null) {
                continue;
            }

            $patients[] = [
                'ref' => $contextRef,
                'pathways' => array_values($pathways),
            ];
        }

        usort($patients, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));

        return $this->payload($patients, $asOf);
    }

    /**
     * @param  list<array<string, mixed>>  $patients
     * @return array<string, mixed>
     */
    private function payload(array $patients, ?Carbon $asOf): array
    {
        return [
            'available' => true,
            'patients' => $patients,
            'as_of' => $asOf?->toIso8601String(),
            'cadence_minutes' => self::BATCH_CADENCE_MINUTES,
        ];
    }
}
