<?php

namespace App\Services\Patient;

use App\Http\Middleware\AssignRequestIdentity;
use Illuminate\Http\Request;

class PatientResponseMetadata
{
    /** @param  array<string, mixed>  $overrides */
    public function forRequest(Request $request, array $overrides = []): array
    {
        $generatedAt = now()->toISOString();

        return array_merge([
            'request_id' => $request->attributes->get(AssignRequestIdentity::ATTRIBUTE),
            'generated_at' => $generatedAt,
            'source_freshness' => [
                'status' => 'not_applicable',
                'observed_at' => null,
            ],
            'policy_version' => (string) config(
                'hummingbird-patient.policy_version',
                'patient-disclosure-v1-draft',
            ),
            // Stable state codes are rendered with native bundled patient
            // language. A client can use this additive signal to withhold a
            // projection whose vocabulary it no longer understands instead of
            // inferring a label from an internal code.
            'state_vocabulary_version' => (string) config(
                'hummingbird-patient-content.state_vocabulary.version',
                'patient-state-vocabulary.v1-draft',
            ),
            // Compatibility aliases retained while native clients move to the
            // patient response contract above.
            'as_of' => $generatedAt,
            'stale' => false,
            'version' => null,
        ], $overrides);
    }
}
