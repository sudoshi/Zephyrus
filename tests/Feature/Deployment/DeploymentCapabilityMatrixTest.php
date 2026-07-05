<?php

namespace Tests\Feature\Deployment;

use App\Services\Deployment\ServiceLineNormalizer;
use Database\Seeders\GeisingerDeploymentSeeder;
use Database\Seeders\SummitDeploymentSeeder;
use Database\Seeders\VirtuaDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 acceptance: heterogeneous IDN fixtures load into hosp_org with the correct
 * per-facility capability_level, valid idn_role + unique facility_key, normalized
 * service-line codes, and first-class transfer edges (internal + external).
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class DeploymentCapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceLineNormalizer::flush();
    }

    private function traumaLevelFor(string $facilityKey): ?string
    {
        return DB::table('hosp_org.facility_service_capabilities')
            ->where('facility_key', $facilityKey)
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->value('capability_level');
    }

    public function test_geisinger_hub_and_spoke_trauma_capability_levels(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);

        // definitive at the Level I hubs, advanced at the Level II, stabilize at the Level IV spokes.
        $this->assertSame('definitive', $this->traumaLevelFor('GEISINGER_GMC'));
        $this->assertSame('definitive', $this->traumaLevelFor('GEISINGER_GWV'));
        $this->assertSame('advanced', $this->traumaLevelFor('GEISINGER_GCMC'));
        $this->assertSame('stabilize', $this->traumaLevelFor('GEISINGER_MUNCY'));
        $this->assertSame('stabilize', $this->traumaLevelFor('GEISINGER_LEWISTOWN'));

        // Each Level IV site has a trauma transfer edge to a Level I hub (GMC).
        foreach (['GEISINGER_MUNCY', 'GEISINGER_LEWISTOWN'] as $spoke) {
            $edge = DB::table('hosp_org.transfer_relationships')
                ->where('source_facility_key', $spoke)
                ->where('destination_facility_key', 'GEISINGER_GMC')
                ->where('service_line_code', 'trauma_acute_care_surgery')
                ->where('direction', 'out')
                ->first();

            $this->assertNotNull($edge, "missing trauma transfer edge for {$spoke}");
            $this->assertNotNull($edge->typical_minutes);
        }

        $hubTraumaLevel = DB::table('hosp_org.facilities')
            ->where('facility_key', 'GEISINGER_GMC')
            ->value('trauma_level_adult');
        $this->assertSame('Level I', $hubTraumaLevel);
    }

    public function test_virtua_specialty_concentration_and_external_transfer(): void
    {
        $this->seed(VirtuaDeploymentSeeder::class);

        // Transplant is present at exactly one facility: Our Lady of Lourdes.
        $transplantFacilities = DB::table('hosp_org.facility_service_capabilities')
            ->where('service_line_code', 'transplant')
            ->pluck('facility_key')
            ->all();
        $this->assertSame(['VIRTUA_OLOL'], $transplantFacilities);

        // Level III NICU only at Voorhees.
        $nicuFacilities = DB::table('hosp_org.facilities')
            ->where('neonatal_level', 'Level III')
            ->pluck('facility_key')
            ->all();
        $this->assertSame(['VIRTUA_VOORHEES'], $nicuFacilities);

        $neonatologyFacilities = DB::table('hosp_org.facility_service_capabilities')
            ->where('service_line_code', 'neonatology')
            ->pluck('facility_key')
            ->all();
        $this->assertSame(['VIRTUA_VOORHEES'], $neonatologyFacilities);

        // External Level I trauma partnership to Cooper, flagged as an external partner.
        $external = DB::table('hosp_org.transfer_relationships')
            ->where('destination_external_name', 'Cooper University Hospital')
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->get();

        $this->assertGreaterThanOrEqual(1, $external->count());
        foreach ($external as $edge) {
            $this->assertTrue((bool) $edge->is_external_partner);
            $this->assertNull($edge->destination_facility_id);
        }
    }

    public function test_facilities_have_valid_idn_role_and_unique_keys(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);

        $total = DB::table('hosp_org.facilities')->count();
        $distinctKeys = DB::table('hosp_org.facilities')->distinct()->count('facility_key');
        $this->assertSame($total, $distinctKeys);

        // Every facility idn_role resolves to a seeded hosp_ref.idn_roles code (FK guarantees, assert intent).
        $unknownRoles = DB::table('hosp_org.facilities as f')
            ->leftJoin('hosp_ref.idn_roles as r', 'f.idn_role', '=', 'r.code')
            ->whereNull('r.code')
            ->count();
        $this->assertSame(0, $unknownRoles);

        // Every capability row references a canonical service line.
        $unknownLines = DB::table('hosp_org.facility_service_capabilities as c')
            ->leftJoin('hosp_ref.service_lines as s', 'c.service_line_code', '=', 's.service_line_code')
            ->whereNull('s.service_line_code')
            ->count();
        $this->assertSame(0, $unknownLines);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);

        $before = [
            'facilities' => DB::table('hosp_org.facilities')->count(),
            'capabilities' => DB::table('hosp_org.facility_service_capabilities')->count(),
            'transfers' => DB::table('hosp_org.transfer_relationships')->count(),
        ];

        // Re-run: upserts + natural-signature transfer match must not duplicate.
        $this->seed(GeisingerDeploymentSeeder::class);

        $after = [
            'facilities' => DB::table('hosp_org.facilities')->count(),
            'capabilities' => DB::table('hosp_org.facility_service_capabilities')->count(),
            'transfers' => DB::table('hosp_org.transfer_relationships')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_summit_reference_deployment_normalizes_and_seeds_affiliates(): void
    {
        $this->seed(SummitDeploymentSeeder::class);

        $flagship = DB::table('hosp_org.facilities')->where('facility_key', 'SUMMIT_REGIONAL')->first();
        $this->assertNotNull($flagship);
        $this->assertSame('flagship_quaternary_hub', $flagship->idn_role);
        $this->assertSame('ZEPHYRUS-500', $flagship->cad_facility_code);
        $this->assertSame('Level I', $flagship->trauma_level_adult);

        // Four community-hospital affiliates, each stabilizing trauma and transferring to the flagship.
        $affiliates = DB::table('hosp_org.facilities')
            ->where('idn_role', 'community_hospital')
            ->pluck('facility_key')
            ->all();
        $this->assertCount(4, $affiliates);

        $affiliateTransfers = DB::table('hosp_org.transfer_relationships')
            ->where('destination_facility_key', 'SUMMIT_REGIONAL')
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->count();
        $this->assertSame(4, $affiliateTransfers);

        // Legacy Summit codes were canonicalized on import: none survive, and the folded code exists.
        foreach (['trauma_surgery', 'medicine', 'cardiology'] as $legacy) {
            $this->assertSame(
                0,
                DB::table('hosp_org.facility_service_capabilities')->where('service_line_code', $legacy)->count(),
                "legacy code {$legacy} should have been normalized"
            );
        }
        $this->assertSame(
            1,
            DB::table('hosp_org.facility_service_capabilities')
                ->where('facility_key', 'SUMMIT_REGIONAL')
                ->where('service_line_code', 'cardiovascular')
                ->count()
        );
    }
}
