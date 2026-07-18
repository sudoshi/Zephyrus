<?php

namespace App\Services\Home;

use App\Models\Home\RpmObservation;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists each projected RPM observation as a FHIR R4 Observation
 * (US Core vital-signs shape) in fhir.resource_versions with a
 * fhir.resource_links row back to prod.rpm_observations
 * (ACUM-PRD-HAH-001 §5.2 / build brief §6.6).
 *
 * Append-only and idempotent: fhir_id = observation_uuid, version_id = '1'
 * (device readings never amend; a corrected reading is a NEW transmission).
 * Resource JSON is encrypted out-of-row via the ClinicalPayloadStore, exactly
 * like the SMART poll path; when the store is disabled (dev without payload
 * encryption) recording is skipped with a log line — never a hard failure of
 * the projection.
 */
class RpmFhirObservationRecorder
{
    public function __construct(private readonly ClinicalPayloadStore $payloads) {}

    /** Resolve the integration source once per request; the feed key is stable. */
    public function recordForSourceKey(RpmObservation $observation, string $sourceKey): void
    {
        $sourceId = DB::table('integration.sources')->where('source_key', $sourceKey)->value('source_id');

        if ($sourceId === null) {
            return;
        }

        $this->record($observation, (int) $sourceId);
    }

    public function record(RpmObservation $observation, int $sourceId): void
    {
        $resource = $this->observationResource($observation);
        $resourceJson = json_encode($resource, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $resourceJson);

        try {
            $stored = $this->payloads->storeJson($sourceId, 'fhir_resource', $resource);
        } catch (\Throwable $exception) {
            Log::info('home_hospital.fhir_observation_skipped', [
                'reason' => $exception->getMessage(),
                'observation_uuid' => $observation->observation_uuid,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($observation, $sourceId, $hash, $stored): void {
                $exists = DB::table('fhir.resource_versions')
                    ->where('source_id', $sourceId)
                    ->where('resource_type', 'Observation')
                    ->where('fhir_id', $observation->observation_uuid)
                    ->where('version_id', '1')
                    ->exists();

                if ($exists) {
                    return;
                }

                $resourceVersionId = DB::table('fhir.resource_versions')->insertGetId([
                    'source_id' => $sourceId,
                    'resource_type' => 'Observation',
                    'fhir_id' => $observation->observation_uuid,
                    'version_id' => '1',
                    'last_updated' => $observation->observed_at,
                    'resource_hash' => $hash,
                    'resource_data' => json_encode((object) [], JSON_THROW_ON_ERROR),
                    'payload_object_id' => $stored->payloadObjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'resource_version_id');

                DB::table('fhir.resource_links')->insertOrIgnore([
                    'source_id' => $sourceId,
                    'resource_type' => 'Observation',
                    'fhir_id' => $observation->observation_uuid,
                    'internal_schema' => 'prod',
                    'internal_table' => 'rpm_observations',
                    'internal_pk' => (string) $observation->rpm_observation_id,
                    'metadata' => json_encode(['resource_version_id' => $resourceVersionId], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $this->payloads->discard(
                $stored->payloadObjectId,
                $sourceId,
                'fhir_observation_link_failed',
                'Encrypted FHIR Observation payload discarded because the version row did not become authoritative.',
            );

            throw $exception;
        }
    }

    /**
     * Minimal US Core vital-signs Observation. subject carries the
     * pseudonymous patient_ref (never an MRN); device identity stays a
     * serial-keyed reference resolved via prod.rpm_devices.
     *
     * @return array<string, mixed>
     */
    private function observationResource(RpmObservation $observation): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => $observation->observation_uuid,
            'meta' => [
                'profile' => ['http://hl7.org/fhir/us/core/StructureDefinition/us-core-vital-signs'],
            ],
            'status' => 'final',
            'category' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code' => 'vital-signs',
                    'display' => 'Vital Signs',
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system' => 'http://loinc.org',
                    'code' => $observation->loinc_code,
                    'display' => $observation->display,
                ]],
            ],
            'subject' => ['reference' => 'Patient/'.$observation->patient_ref],
            'effectiveDateTime' => $observation->observed_at?->toIso8601String(),
            'issued' => $observation->received_at?->toIso8601String(),
            'valueQuantity' => [
                'value' => (float) $observation->value,
                'unit' => $observation->unit,
                'system' => 'http://unitsofmeasure.org',
                'code' => $observation->unit,
            ],
        ];
    }
}
