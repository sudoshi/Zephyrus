<?php

namespace Tests\Feature\Deployment;

use App\Models\Unit;
use App\Models\User;
use App\Services\Deployment\DeploymentReadinessService;
use App\Services\Deployment\ServiceLineNormalizer;
use Database\Seeders\SummitDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 acceptance: the DeploymentReadinessService / deployment:readiness command /
 * GET /api/deployment/readiness scorecard mechanically checks the plan §16 Acceptance
 * Criteria. Summit (client-verified flagship, mapped beds) passes the named hard checks
 * and is deployment_ready; assumed affiliates are listed; staffing checks (Phase 7) are
 * not_applicable.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §16)
 */
class DeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,array<string,mixed>> */
    private function checksByKey(array $report): array
    {
        $out = [];
        foreach ($report['checks'] as $check) {
            $out[$check['key']] = $check;
        }

        return $out;
    }

    /**
     * Seed a deployment-ready Summit: hosp_org (SummitDeploymentSeeder) + a few units as
     * facility spaces with mapped prod.units and mapped prod.beds.
     *
     * @param  bool  $mapBeds  when false, ICU beds are left unmapped (to exercise criterion 4 failing)
     */
    private function seedReadySummit(bool $mapBeds = true): void
    {
        $this->seed(SummitDeploymentSeeder::class);

        $normalizer = app(ServiceLineNormalizer::class);
        $now = Carbon::parse('2026-07-05 00:00:00');

        $specs = [
            ['abbr' => 'MICU', 'cad' => 'MICU3', 'name' => 'Medical ICU', 'sl' => 'critical_care', 'acuity' => 'icu', 'type' => 'icu', 'floor' => 3, 'beds' => 3],
            ['abbr' => '4W', 'cad' => 'MS4A', 'name' => 'Med/Surg A', 'sl' => 'adult_med_surg', 'acuity' => 'med_surg', 'type' => 'med_surg', 'floor' => 4, 'beds' => 2],
            ['abbr' => 'ED', 'cad' => 'ED', 'name' => 'Emergency Department', 'sl' => 'emergency', 'acuity' => 'emergency', 'type' => 'ed', 'floor' => 1, 'beds' => 0],
        ];

        foreach ($specs as $spec) {
            $canonical = $normalizer->canonical($spec['sl']);

            $spaceId = DB::table('hosp_space.facility_spaces')->insertGetId([
                'space_code' => $spec['cad'],
                'space_name' => $spec['name'],
                'space_category' => 'unit',
                'floor_number' => $spec['floor'],
                'service_line_code' => $canonical,
                'acuity_level' => $spec['acuity'],
                'facility_key' => 'SUMMIT_REGIONAL',
                'status' => 'active',
                'attributes' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'facility_space_id');

            $unit = Unit::create([
                'name' => $spec['name'],
                'abbreviation' => $spec['abbr'],
                'type' => $spec['type'],
                'staffed_bed_count' => $spec['beds'],
                'ratio_floor' => 2,
                'facility_space_id' => $spaceId,
                'is_deleted' => false,
            ]);

            for ($i = 1; $i <= $spec['beds']; $i++) {
                DB::table('prod.beds')->insert([
                    'unit_id' => $unit->unit_id,
                    'label' => sprintf('%s-%02d', $spec['abbr'], $i),
                    'status' => 'available',
                    'bed_type' => 'standard',
                    'isolation_capable' => false,
                    'facility_space_id' => $mapBeds ? $spaceId : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('hosp_space.facility_space_service_lines')->insert([
                'facility_space_id' => $spaceId,
                'service_line_code' => $canonical,
                'primary_flag' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function test_summit_passes_the_named_hard_checks_and_is_deployment_ready(): void
    {
        $this->seedReadySummit();

        $report = app(DeploymentReadinessService::class)->evaluate('SUMMIT_REGIONAL');
        $checks = $this->checksByKey($report);

        $this->assertSame('pass', $checks['facility_idn_role']['status']);
        $this->assertSame('pass', $checks['capability_coverage']['status']);
        $this->assertSame('pass', $checks['bed_mapping']['status']);
        $this->assertSame('pass', $checks['regulated_evidence']['status']);
        $this->assertTrue($report['deployment_ready']);
        $this->assertSame(0, $report['summary']['fail']);
    }

    public function test_assumed_affiliate_rows_are_listed_for_signoff(): void
    {
        $this->seedReadySummit();

        $report = app(DeploymentReadinessService::class)->evaluate('SUMMIT_REGIONAL');
        $lowConfidence = $this->checksByKey($report)['low_confidence'];

        $this->assertSame('warn', $lowConfidence['status']);
        $this->assertGreaterThan(0, $lowConfidence['count']);

        $facilityKeys = array_column($lowConfidence['failures'], 'facility_key');
        $this->assertContains('SUMMIT_HAWTHORNE', $facilityKeys);
    }

    public function test_staffing_checks_are_active_after_phase_7(): void
    {
        // With the Phase 7 hosp_org.staff_assignments table present, criteria 12–13 are
        // live: seeded units carry no assignments yet -> unit_staffing warns (non-blocking),
        // and an empty assignment set is trivially FK-valid -> staff_assignment_integrity passes.
        $this->seedReadySummit();

        $report = app(DeploymentReadinessService::class)->evaluate('SUMMIT_REGIONAL');
        $checks = $this->checksByKey($report);

        $this->assertSame('warn', $checks['unit_staffing']['status']);
        $this->assertGreaterThan(0, $checks['unit_staffing']['count']);
        $this->assertSame('pass', $checks['staff_assignment_integrity']['status']);
        // Neither is a hard failure — Summit stays deployment-ready.
        $this->assertTrue($report['deployment_ready']);
    }

    public function test_unmapped_inpatient_bed_fails_bed_mapping_and_blocks_readiness(): void
    {
        $this->seedReadySummit(mapBeds: false);

        $report = app(DeploymentReadinessService::class)->evaluate('SUMMIT_REGIONAL');
        $bedMapping = $this->checksByKey($report)['bed_mapping'];

        $this->assertSame('fail', $bedMapping['status']);
        $this->assertGreaterThan(0, $bedMapping['count']);
        $this->assertFalse($report['deployment_ready']);
    }

    public function test_source_verified_regulated_line_without_regulated_evidence_fails(): void
    {
        $this->seedReadySummit();

        // Claim a Level I trauma designation as source_verified but back it only with a marketing page.
        DB::table('hosp_org.facility_service_capabilities')
            ->where('facility_key', 'SUMMIT_REGIONAL')
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->update(['review_status' => 'source_verified', 'source_evidence_type' => 'public_location_page']);

        $report = app(DeploymentReadinessService::class)->evaluate('SUMMIT_REGIONAL');
        $regulated = $this->checksByKey($report)['regulated_evidence'];

        $this->assertSame('fail', $regulated['status']);
        $this->assertSame('trauma_acute_care_surgery', $regulated['failures'][0]['service_line_code']);
        $this->assertFalse($report['deployment_ready']);
    }

    public function test_command_exit_code_reflects_readiness(): void
    {
        $this->seedReadySummit();
        $this->assertSame(0, Artisan::call('deployment:readiness', ['facilityKey' => 'SUMMIT_REGIONAL']));

        // Break bed mapping → a hard failure → non-zero exit.
        DB::table('prod.beds')->update(['facility_space_id' => null]);
        $this->assertSame(1, Artisan::call('deployment:readiness', ['facilityKey' => 'SUMMIT_REGIONAL']));

        $this->assertSame(1, Artisan::call('deployment:readiness', ['facilityKey' => 'NOT_A_FACILITY']));
    }

    public function test_api_returns_the_same_scorecard_json(): void
    {
        $this->seedReadySummit();
        $user = User::factory()->create(['role' => 'superuser']);

        $data = $this->actingAs($user)
            ->getJson('/api/deployment/readiness/SUMMIT_REGIONAL')
            ->assertOk()
            ->json('data');

        $this->assertSame('SUMMIT_REGIONAL', $data['facility_key']);
        $this->assertTrue($data['deployment_ready']);
        $this->assertCount(13, $data['checks']);

        $this->actingAs($user)
            ->getJson('/api/deployment/readiness/NOT_A_FACILITY')
            ->assertNotFound();
    }
}
