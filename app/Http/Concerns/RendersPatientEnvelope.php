<?php

namespace App\Http\Concerns;

use App\Services\Patient\PatientResponseMetadata;
use Illuminate\Http\JsonResponse;

/**
 * Uniform response boundary for the patient application.
 *
 * Patient responses deliberately do not inherit staff web deep links or staff
 * operational metadata. Callers can always determine freshness and version.
 */
trait RendersPatientEnvelope
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    protected function patientEnvelope(
        mixed $data,
        array $meta = [],
        array $links = [],
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'meta' => app(PatientResponseMetadata::class)->forRequest(request(), $meta),
            'links' => (object) $links,
        ], $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
