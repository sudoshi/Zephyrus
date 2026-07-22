<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\Projection\PatientProjectionDisclosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncounterProjectionController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientProjectionDisclosureService $disclosures) {}

    public function today(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'today');
    }

    public function pathway(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'pathway');
    }

    public function pathwayEvents(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'pathway_events');
    }

    public function dischargeReadiness(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'discharge_readiness');
    }

    public function roundsSummary(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'rounds_summary');
    }

    public function careTeam(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->show($request, $encounterUuid, 'care_team');
    }

    private function show(Request $request, string $encounterUuid, string $kind): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        $disclosure = $this->disclosures->disclose($request, $principal, $encounterUuid, $kind);

        if ($disclosure === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        }

        return $this->patientEnvelope($disclosure['data'], $disclosure['meta']);
    }
}
