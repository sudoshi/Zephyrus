<?php

namespace Database\Seeders;

use App\Services\Deployment\DeploymentCapabilityImporter;
use App\Services\Deployment\DeploymentFacilityImporter;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Database\Seeder;

/**
 * Seeds Summit Regional as the reference IDN deployment: organization SUMMIT_HEALTH,
 * market MID_ATLANTIC, flagship SUMMIT_REGIONAL (flagship_quaternary_hub, cad
 * ZEPHYRUS-500, Level I trauma) with one capability row per manifest service line at
 * `definitive`, plus four community-hospital affiliates that stabilize trauma and
 * transfer to the flagship. Legacy Summit service-line codes are canonicalized on the
 * way in. Summit is a *reference*, never the universal schema.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class SummitDeploymentSeeder extends Seeder
{
    private const ORG_KEY = 'SUMMIT_HEALTH';

    private const MARKET_KEY = 'MID_ATLANTIC';

    private const FLAGSHIP_KEY = 'SUMMIT_REGIONAL';

    /** @var list<array{key:string,code:string,name:string,beds:int,minutes:int}> */
    private const AFFILIATES = [
        ['key' => 'SUMMIT_HAWTHORNE', 'code' => 'HAWTH', 'name' => 'Summit Health — Hawthorne Campus', 'beds' => 90, 'minutes' => 35],
        ['key' => 'SUMMIT_RIVERTON', 'code' => 'RIVCH', 'name' => 'Summit Health — Riverton Community Hospital', 'beds' => 140, 'minutes' => 50],
        ['key' => 'SUMMIT_GLENMOORE', 'code' => 'GLNMC', 'name' => 'Summit Health — Glenmoore Medical Center', 'beds' => 110, 'minutes' => 28],
        ['key' => 'SUMMIT_CASTLETON', 'code' => 'CASTG', 'name' => 'Summit Health — Castleton General Hospital', 'beds' => 80, 'minutes' => 42],
    ];

    public function run(): void
    {
        app(ServiceLineRegistrar::class)->seed();
        ServiceLineNormalizer::flush();

        $manifest = app(HospitalManifest::class);
        $normalizer = app(ServiceLineNormalizer::class);

        app(DeploymentFacilityImporter::class)->importData($this->buildFacilities($manifest));
        app(DeploymentCapabilityImporter::class)->importData($this->buildCapabilities($manifest, $normalizer));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFacilities(HospitalManifest $manifest): array
    {
        $facility = $manifest->facility();

        $facilities = [[
            'facility_key' => self::FLAGSHIP_KEY,
            'facility_name' => $facility['name'],
            'short_name' => $facility['short_name'] ?? null,
            'market_key' => self::MARKET_KEY,
            'idn_role' => 'flagship_quaternary_hub',
            'state' => $facility['state'] ?? 'PA',
            'region' => $facility['region'] ?? 'Mid-Atlantic',
            'licensed_beds' => $facility['licensed_beds'] ?? null,
            'trauma_level_adult' => 'Level I',
            'stroke_level' => 'Comprehensive',
            'teaching_status' => 'academic',
            'cad_facility_code' => $facility['cad_facility_code'] ?? 'ZEPHYRUS-500',
            'review_status' => 'client_verified',
            // Display-only manifest fields with no first-class column; the manifest
            // generator (Phase 5) reproduces facility.code/city/type/tagline +
            // physical-capacity denominators (discharge_lounge_chairs) from here.
            'metadata' => [
                'code' => $facility['code'] ?? 'HOSP1',
                'city' => $facility['city'] ?? null,
                'type' => $facility['type'] ?? null,
                'tagline' => $facility['tagline'] ?? null,
                'discharge_lounge_chairs' => $facility['discharge_lounge_chairs'] ?? null,
            ],
        ]];

        foreach (self::AFFILIATES as $affiliate) {
            $facilities[] = [
                'facility_key' => $affiliate['key'],
                'facility_name' => $affiliate['name'],
                'market_key' => self::MARKET_KEY,
                'idn_role' => 'community_hospital',
                'state' => 'PA',
                'region' => 'Mid-Atlantic',
                'licensed_beds' => $affiliate['beds'],
                'trauma_level_adult' => 'Level IV',
                'review_status' => 'assumed',
                'metadata' => ['code' => $affiliate['code']],
            ];
        }

        return [
            'organization' => [
                'key' => self::ORG_KEY,
                'name' => 'Summit Health',
                'short_name' => 'Summit Health',
                'kind' => 'idn',
                'headquarters_state' => 'PA',
            ],
            'markets' => [
                ['key' => self::MARKET_KEY, 'name' => 'Mid-Atlantic', 'region' => 'Mid-Atlantic', 'state' => 'PA'],
            ],
            'facilities' => $facilities,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCapabilities(HospitalManifest $manifest, ServiceLineNormalizer $normalizer): array
    {
        $capabilities = [];
        $seen = [];

        foreach ($manifest->serviceLines() as $line) {
            $canonical = $normalizer->canonical((string) ($line['code'] ?? ''));

            if ($canonical === '' || isset($seen[$canonical])) {
                continue; // cardiology + cardiovascular fold to a single canonical row
            }
            $seen[$canonical] = true;

            $capabilities[] = [
                'facility_key' => self::FLAGSHIP_KEY,
                'service_line' => $canonical,
                'capability_level' => 'definitive',
                'coverage_model' => 'in_house',
                'hours' => $canonical === 'emergency' ? '24/7' : null,
                'source_evidence_type' => 'client_roster',
                'review_status' => 'client_verified',
            ];
        }

        $transfers = [];

        foreach (self::AFFILIATES as $affiliate) {
            $capabilities[] = [
                'facility_key' => $affiliate['key'],
                'service_line' => 'trauma_acute_care_surgery',
                'capability_level' => 'stabilize',
                'coverage_model' => 'on_call',
                'transfer_out_targets' => [self::FLAGSHIP_KEY],
                'source_evidence_type' => 'assumption',
                'review_status' => 'assumed',
            ];
            $capabilities[] = [
                'facility_key' => $affiliate['key'],
                'service_line' => 'emergency',
                'capability_level' => 'routine',
                'coverage_model' => 'in_house',
                'hours' => '24/7',
                'source_evidence_type' => 'assumption',
                'review_status' => 'assumed',
            ];
            $transfers[] = [
                'source_facility_key' => $affiliate['key'],
                'destination_facility_key' => self::FLAGSHIP_KEY,
                'service_line' => 'trauma_acute_care_surgery',
                'transport_mode' => 'critical_care_transport',
                'typical_minutes' => $affiliate['minutes'],
                'direction' => 'out',
                'review_status' => 'assumed',
            ];
        }

        return ['capabilities' => $capabilities, 'transfers' => $transfers];
    }
}
