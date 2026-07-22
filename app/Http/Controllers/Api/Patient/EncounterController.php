<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\PatientAccessAuditRecorder;
use App\Services\Patient\PatientEncounterAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EncounterController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(
        private readonly PatientEncounterAccessService $access,
        private readonly PatientAccessAuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        Gate::forUser($principal)->authorize('viewAny', PatientEncounterAccessGrant::class);

        $grants = $this->access->activeGrants($principal);
        $encounters = DB::transaction(function () use ($grants, $principal, $request) {
            return $grants->map(function (PatientEncounterAccessGrant $grant) use ($principal, $request): array {
                $this->audit->record(
                    $request,
                    'patient.encounter.disclosed',
                    'access',
                    'list_encounters',
                    'allowed',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_encounter',
                    resourceUuid: (string) $grant->encounter_uuid,
                );

                return $this->access->patientSafeProjection($grant);
            });
        });

        return $this->patientEnvelope([
            'encounters' => $encounters,
        ], [
            'count' => $encounters->count(),
            'version' => $encounters->max('version'),
            'source_freshness' => [
                'status' => $grants->isEmpty() ? 'no_data' : 'current',
                'observed_at' => $grants->max(
                    fn (PatientEncounterAccessGrant $grant): ?string => $grant->updated_at?->toISOString(),
                ),
            ],
        ]);
    }
}
