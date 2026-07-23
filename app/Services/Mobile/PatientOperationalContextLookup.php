<?php

namespace App\Services\Mobile;

use App\Models\BedRequest;
use App\Models\EdVisit;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative, source-only lookups used by staff patient-context policy.
 * This service must not issue an opaque reference, authorize a user, or
 * serialize patient data; those responsibilities remain separately bounded.
 */
final class PatientOperationalContextLookup
{
    public function exists(string $patientRef): bool
    {
        return BedRequest::query()->pending()->where('patient_ref', $patientRef)->exists()
            || TransportRequest::query()->active()->where('patient_ref', $patientRef)->exists()
            || EvsRequest::query()->active()->where('patient_ref', $patientRef)->exists()
            || EdVisit::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->whereNull('departed_at')
                ->exists()
            || Encounter::query()->active()->where('patient_ref', $patientRef)->exists()
            || DB::table('prod.or_cases as cases')
                ->join('prod.case_statuses as statuses', 'statuses.status_id', '=', 'cases.status_id')
                ->where('cases.patient_id', $patientRef)
                ->where('cases.is_deleted', false)
                ->whereIn('statuses.code', ['SCHED', 'INPROG', 'DELAY'])
                ->exists();
    }

    /** @return list<int> */
    public function activeUnitIds(string $patientRef): array
    {
        return collect()
            ->merge(Encounter::query()
                ->active()
                ->where('patient_ref', $patientRef)
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->merge(EdVisit::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->whereNull('departed_at')
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->merge(EvsRequest::query()
                ->where('patient_ref', $patientRef)
                ->active()
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->filter()
            ->map(static fn ($unitId): int => (int) $unitId)
            ->unique()
            ->values()
            ->all();
    }
}
