<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\UpdatePreferencesRequest;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\PatientAccessAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientAccessAuditRecorder $audit) {}

    public function show(Request $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        $this->audit->record(
            $request,
            'patient.profile.viewed',
            'access',
            'view_profile',
            'allowed',
            $principal,
            resourceType: 'patient_principal',
            resourceUuid: (string) $principal->principal_uuid,
        );

        return $this->patientEnvelope($this->profile($principal));
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        $validated = $request->validated();

        $preferenceKeys = [
            'text_size',
            'reduced_motion',
            'high_contrast',
            'notification_preview',
            'preferred_channel',
        ];
        DB::transaction(function () use ($principal, $validated, $preferenceKeys, $request): void {
            if (array_key_exists('locale', $validated)) {
                $principal->locale = $validated['locale'];
            }

            if (array_key_exists('timezone', $validated)) {
                $principal->timezone = $validated['timezone'];
            }

            $principal->preferences = array_merge(
                (array) $principal->preferences,
                Arr::only($validated, $preferenceKeys),
            );
            $principal->save();

            $this->audit->record(
                $request,
                'patient.profile.preferences_updated',
                'activity',
                'update_preferences',
                'succeeded',
                $principal,
                resourceType: 'patient_principal',
                resourceUuid: (string) $principal->principal_uuid,
                metadata: ['changed_field_count' => count($validated)],
            );
        });

        return $this->patientEnvelope($this->profile($principal->refresh()));
    }

    /** @return array<string, mixed> */
    private function profile(PatientPrincipal $principal): array
    {
        return [
            'principal_uuid' => (string) $principal->principal_uuid,
            'principal_type' => (string) $principal->principal_type,
            'display_name' => (string) $principal->display_name,
            'email' => $principal->email,
            'phone_e164' => $principal->phone_e164,
            'email_verified' => $principal->email_verified_at !== null,
            'phone_verified' => $principal->phone_verified_at !== null,
            'locale' => (string) $principal->locale,
            'timezone' => (string) $principal->timezone,
            'preferences' => (object) ((array) $principal->preferences),
        ];
    }
}
