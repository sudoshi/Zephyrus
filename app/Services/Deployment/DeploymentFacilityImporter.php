<?php

namespace App\Services\Deployment;

use App\Services\Deployment\Concerns\UpsertsPgRows;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports an IDN facility roster (organization + markets + facilities) into
 * hosp_org (Layer 1). Idempotent: organizations upsert on organization_key,
 * markets on (organization_id, market_key), facilities on facility_key.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class DeploymentFacilityImporter
{
    use UpsertsPgRows;

    /**
     * @return array{organizations:int, markets:int, facilities:int}
     */
    public function importFile(string $path): array
    {
        return $this->importData($this->readJsonFile($path));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{organizations:int, markets:int, facilities:int}
     */
    public function importData(array $data): array
    {
        $orgSpec = $data['organization'] ?? null;
        if (! is_array($orgSpec) || empty($orgSpec['key'])) {
            throw new RuntimeException('Facility import requires an "organization" object with a "key".');
        }

        return DB::transaction(function () use ($data, $orgSpec): array {
            $this->upsertRow('hosp_org.organizations', [
                'organization_key' => $orgSpec['key'],
                'name' => $orgSpec['name'] ?? $orgSpec['key'],
                'short_name' => $orgSpec['short_name'] ?? null,
                'kind' => $orgSpec['kind'] ?? 'idn',
                'headquarters_state' => $orgSpec['headquarters_state'] ?? null,
                'metadata' => $orgSpec['metadata'] ?? [],
            ], ['organization_key'], [], ['metadata']);

            $organizationId = (int) DB::table('hosp_org.organizations')
                ->where('organization_key', $orgSpec['key'])
                ->value('organization_id');

            $marketCount = 0;
            foreach ($data['markets'] ?? [] as $market) {
                if (empty($market['key'])) {
                    throw new RuntimeException('Each market requires a "key".');
                }
                $this->upsertRow('hosp_org.markets', [
                    'organization_id' => $organizationId,
                    'market_key' => $market['key'],
                    'name' => $market['name'] ?? $market['key'],
                    'region' => $market['region'] ?? null,
                    'state' => $market['state'] ?? null,
                    'metadata' => $market['metadata'] ?? [],
                ], ['organization_id', 'market_key'], [], ['metadata']);
                $marketCount++;
            }

            $marketIdByKey = DB::table('hosp_org.markets')
                ->where('organization_id', $organizationId)
                ->pluck('market_id', 'market_key')
                ->all();

            $facilityCount = 0;
            foreach ($data['facilities'] ?? [] as $facility) {
                if (empty($facility['facility_key']) || empty($facility['idn_role'])) {
                    throw new RuntimeException('Each facility requires "facility_key" and "idn_role".');
                }

                $marketId = isset($facility['market_key'])
                    ? ($marketIdByKey[$facility['market_key']] ?? null)
                    : null;

                $this->upsertRow('hosp_org.facilities', [
                    'organization_id' => $organizationId,
                    'market_id' => $marketId,
                    'facility_key' => $facility['facility_key'],
                    'facility_name' => $facility['facility_name'] ?? $facility['facility_key'],
                    'short_name' => $facility['short_name'] ?? null,
                    'parent_system' => $facility['parent_system'] ?? ($orgSpec['name'] ?? null),
                    'market' => $facility['market'] ?? null,
                    'region' => $facility['region'] ?? null,
                    'state' => $facility['state'] ?? null,
                    'county' => $facility['county'] ?? null,
                    'lat' => $facility['lat'] ?? null,
                    'lng' => $facility['lng'] ?? null,
                    'idn_role' => $facility['idn_role'],
                    'campus_type' => $facility['campus_type'] ?? null,
                    'license_type' => $facility['license_type'] ?? null,
                    'teaching_status' => $facility['teaching_status'] ?? null,
                    'licensed_beds' => $facility['licensed_beds'] ?? null,
                    'trauma_level_adult' => $facility['trauma_level_adult'] ?? null,
                    'trauma_level_pediatric' => $facility['trauma_level_pediatric'] ?? null,
                    'stroke_level' => $facility['stroke_level'] ?? null,
                    'maternal_level' => $facility['maternal_level'] ?? null,
                    'neonatal_level' => $facility['neonatal_level'] ?? null,
                    'burn_center_status' => $facility['burn_center_status'] ?? null,
                    'transplant_center_status' => $facility['transplant_center_status'] ?? null,
                    'transplant_programs' => $facility['transplant_programs'] ?? [],
                    'pediatric_capability' => $facility['pediatric_capability'] ?? null,
                    'behavioral_health_capability' => $facility['behavioral_health_capability'] ?? null,
                    'ambulatory_surgery_capability' => $facility['ambulatory_surgery_capability'] ?? null,
                    'home_hospital_capability' => $facility['home_hospital_capability'] ?? null,
                    'cad_facility_code' => $facility['cad_facility_code'] ?? null,
                    'review_status' => $facility['review_status'] ?? 'assumed',
                    'source_evidence' => $facility['source_evidence'] ?? [],
                    'is_active' => $facility['is_active'] ?? true,
                    'metadata' => $facility['metadata'] ?? [],
                ], ['facility_key'], ['transplant_programs'], ['source_evidence', 'metadata']);
                $facilityCount++;
            }

            return [
                'organizations' => 1,
                'markets' => $marketCount,
                'facilities' => $facilityCount,
            ];
        });
    }
}
