<?php

namespace Tests\Feature\Deployment;

use App\Models\Reference\ServiceLine;
use App\Services\Deployment\ServiceLineNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 0 acceptance: config-authored service-line registry projects into hosp_ref.*,
 * is idempotent, resolves legacy Summit aliases, and round-trips text[] columns.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 0)
 */
class ServiceLineRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The normalizer caches its alias index statically across the process.
        ServiceLineNormalizer::flush();
    }

    private function seedRegistry(): void
    {
        $this->assertSame(0, Artisan::call('deployment:seed-registry'));
    }

    public function test_seed_registry_populates_all_registry_tables(): void
    {
        $this->seedRegistry();

        $this->assertSame(29, DB::table('hosp_ref.service_lines')->count());
        $this->assertSame(27, DB::table('hosp_ref.programs')->count());
        $this->assertSame(26, DB::table('hosp_ref.capability_tags')->count());
        $this->assertSame(7, DB::table('hosp_ref.capability_levels')->count());
        $this->assertSame(14, DB::table('hosp_ref.idn_roles')->count());
        $this->assertSame(16, DB::table('hosp_ref.location_roles')->count());
        $this->assertSame(9, DB::table('hosp_ref.evidence_classes')->count());
    }

    public function test_seed_registry_is_idempotent(): void
    {
        $this->seedRegistry();

        $before = [
            'service_lines' => DB::table('hosp_ref.service_lines')->count(),
            'programs' => DB::table('hosp_ref.programs')->count(),
            'capability_tags' => DB::table('hosp_ref.capability_tags')->count(),
            'capability_levels' => DB::table('hosp_ref.capability_levels')->count(),
            'idn_roles' => DB::table('hosp_ref.idn_roles')->count(),
            'location_roles' => DB::table('hosp_ref.location_roles')->count(),
            'evidence_classes' => DB::table('hosp_ref.evidence_classes')->count(),
        ];

        // Second run must be a no-op on row counts (upsert, not insert).
        $this->assertSame(0, Artisan::call('deployment:seed-registry'));

        $after = [
            'service_lines' => DB::table('hosp_ref.service_lines')->count(),
            'programs' => DB::table('hosp_ref.programs')->count(),
            'capability_tags' => DB::table('hosp_ref.capability_tags')->count(),
            'capability_levels' => DB::table('hosp_ref.capability_levels')->count(),
            'idn_roles' => DB::table('hosp_ref.idn_roles')->count(),
            'location_roles' => DB::table('hosp_ref.location_roles')->count(),
            'evidence_classes' => DB::table('hosp_ref.evidence_classes')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_normalizer_resolves_legacy_summit_aliases_from_db(): void
    {
        $this->seedRegistry();
        ServiceLineNormalizer::flush();

        $normalizer = app(ServiceLineNormalizer::class);

        $this->assertSame('trauma_acute_care_surgery', $normalizer->canonical('trauma_surgery'));
        $this->assertSame('hospital_medicine', $normalizer->canonical('medicine'));
        $this->assertSame('cardiovascular', $normalizer->canonical('cardiology'));

        // Canonical codes resolve to themselves; unknown codes pass through unchanged.
        $this->assertSame('emergency', $normalizer->canonical('emergency'));
        $this->assertSame('not_a_line', $normalizer->canonical('not_a_line'));

        $this->assertTrue($normalizer->isKnown('trauma_surgery'));
        $this->assertFalse($normalizer->isKnown('not_a_line'));
        $this->assertContains('trauma_acute_care_surgery', $normalizer->all());
    }

    public function test_service_line_text_array_and_boolean_columns_round_trip(): void
    {
        $this->seedRegistry();

        /** @var ServiceLine $trauma */
        $trauma = ServiceLine::query()->findOrFail('trauma_acute_care_surgery');

        // text[] columns come back as PHP lists via the PgTextArray cast.
        $this->assertSame(['trauma_surgery'], $trauma->aliases);
        $this->assertSame(['ACS'], $trauma->certification_or_designation);
        $this->assertIsArray($trauma->default_location_roles);
        $this->assertContains('critical_care', $trauma->default_location_roles);

        // requires[] tokens expanded to boolean columns; booleans cast to real bools.
        $this->assertTrue($trauma->requires_24_7);
        $this->assertTrue($trauma->requires_transfer_agreements);
        $this->assertTrue($trauma->requires_pharmacy);
        $this->assertSame(20, $trauma->sort_order);
        $this->assertSame('ed', $trauma->default_workflow);

        // A line that omits a token gets the column defaulted to false; empty aliases stay empty.
        /** @var ServiceLine $emergency */
        $emergency = ServiceLine::query()->findOrFail('emergency');
        $this->assertFalse($emergency->requires_inpatient_beds);
        $this->assertSame([], $emergency->aliases);
    }

    public function test_every_program_references_a_seeded_service_line(): void
    {
        $this->seedRegistry();

        // The FK would have failed the seed if not; assert explicitly for intent.
        $orphans = DB::table('hosp_ref.programs as p')
            ->leftJoin('hosp_ref.service_lines as s', 'p.service_line_code', '=', 's.service_line_code')
            ->whereNull('s.service_line_code')
            ->count();

        $this->assertSame(0, $orphans);
    }

    public function test_regulated_evidence_classes_are_flagged(): void
    {
        $this->seedRegistry();

        $regulated = DB::table('hosp_ref.evidence_classes')
            ->where('is_regulated', true)
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['accreditation_body', 'state_designation'], $regulated);
    }
}
