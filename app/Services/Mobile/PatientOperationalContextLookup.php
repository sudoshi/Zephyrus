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
        return BedRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || TransportRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || EvsRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || EdVisit::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || Encounter::query()->active()->where('patient_ref', $patientRef)->exists()
            || DB::table('prod.or_cases')->where('patient_id', $patientRef)->where('is_deleted', false)->exists();
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
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->merge(EvsRequest::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->filter()
            ->map(static fn ($unitId): int => (int) $unitId)
            ->unique()
            ->values()
            ->all();
    }
}
