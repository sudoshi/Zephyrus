<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaService;
use App\Domain\Ocel\EmissionMap;
use App\Jobs\RefreshArenaConformance;
use App\Models\BedRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The per-patient conformance seam (FLOW-4D plan §8 Phase A2, finding CF-2):
 * RefreshArenaConformance lands per-case verdicts in arena.case_conformance;
 * GET /api/arena/conformance/case joins a live patient to their de-identified
 * case oids via EmissionMap::hashRef and reads the cache only. PHPUnit class
 * syntax — Pest is excluded on this environment.
 */
class CaseConformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase C (plan §8 C5) layered FLOW4D_CONFORMANCE_ENABLED onto this
        // route; the full gate matrix lives in Flow4dConformanceSurfaceTest.
        config(['services.arena.enabled' => true, 'services.flow4d.conformance' => true]);
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Case Conformance Test';
        $user->email = 'caseconf@example.com';
        $user->username = 'caseconf';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    /** @return array{patient_ref: string, encounter_ref: string} */
    private function seedFlowEvent(): array
    {
        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260725080000||ADT^A01|CASECONF1|P|2.5.1',
            'EVN|A01|20260725080000',
            'PID|||CASE0001^^^AMC^MR||FLOW^CASE0001',
            'PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VISCASE0001^^^AMC^VN',
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event);

        $row = DB::table('flow_core.flow_events')->orderBy('occurred_at')->first(['patient_ref', 'encounter_ref']);

        // The A2P authorization inside patientScope() requires operational
        // context for the patient (same fixture rule as PatientLensApiTest).
        BedRequest::create([
            'patient_ref' => (string) $row->patient_ref,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        return [
            'patient_ref' => (string) $row->patient_ref,
            'encounter_ref' => (string) $row->encounter_ref,
        ];
    }

    public function test_case_oids_cover_patient_and_encounter_hashes(): void
    {
        $refs = $this->seedFlowEvent();

        $oids = app(ArenaService::class)->caseOidsForPatient($refs['patient_ref']);

        $this->assertContains('patient-'.EmissionMap::hashRef($refs['patient_ref']), $oids);
        $this->assertContains('enc-'.EmissionMap::hashRef($refs['encounter_ref']), $oids);
    }

    public function test_the_endpoint_returns_cached_verdicts_for_the_patient_scope(): void
    {
        $refs = $this->seedFlowEvent();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);
        $caseOid = 'enc-'.EmissionMap::hashRef($refs['encounter_ref']);

        DB::table('arena.case_conformance')->insert([
            'case_oid' => $caseOid,
            'pathway' => 'sepsis',
            'pathway_version' => 1,
            'conformant' => false,
            'deviations' => json_encode(['antibiotic_late']),
            'activity_timeline' => json_encode(['sepsis_recognition' => '2026-07-25T08:10:00+00:00']),
            'computed_at' => now()->subMinutes(9),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/arena/conformance/case?persona=house_supervisor&scope=patient:{$contextRef}");

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('patient_context_ref', $contextRef)
            ->assertJsonPath('verdicts.0.pathway', 'sepsis')
            ->assertJsonPath('verdicts.0.conformant', false)
            ->assertJsonPath('verdicts.0.deviations.0', 'antibiotic_late');

        // The de-identified case oid stays server-side, and the raw patient
        // ref never appears — the join is invisible to the client.
        $this->assertStringNotContainsString($caseOid, $response->getContent());
        $this->assertStringNotContainsString($refs['patient_ref'], $response->getContent());
    }

    public function test_the_endpoint_requires_a_patient_scope(): void
    {
        $this->seedFlowEvent();

        $this->actingAs($this->admin())
            ->getJson('/api/arena/conformance/case?persona=house_supervisor&scope=house')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'case_conformance_requires_patient_scope');
    }

    public function test_an_aggregate_persona_is_forbidden(): void
    {
        $refs = $this->seedFlowEvent();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->getJson("/api/arena/conformance/case?persona=executive&scope=patient:{$contextRef}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'flow_lens_forbidden');
    }

    public function test_the_route_is_absent_when_the_arena_is_off(): void
    {
        config(['services.arena.enabled' => false]);
        $refs = $this->seedFlowEvent();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->getJson("/api/arena/conformance/case?persona=house_supervisor&scope=patient:{$contextRef}")
            ->assertNotFound();
    }

    public function test_the_refresh_job_lands_and_prunes_per_case_verdicts(): void
    {
        // First batch: two sepsis cases.
        $this->bindArenaServiceReturning([
            $this->pathwayPayload('sepsis', [
                ['case_id' => 'enc-aaaaaaaaaaaa', 'conformant' => true, 'deviations' => [], 'activity_timeline' => []],
                ['case_id' => 'enc-bbbbbbbbbbbb', 'conformant' => false, 'deviations' => ['no_lactate'], 'activity_timeline' => []],
            ]),
        ]);
        (new RefreshArenaConformance)->handle(app(ArenaService::class));

        $this->assertSame(2, DB::table('arena.case_conformance')->where('pathway', 'sepsis')->count());

        // Second batch: one case left the log window — its row must be pruned.
        $this->bindArenaServiceReturning([
            $this->pathwayPayload('sepsis', [
                ['case_id' => 'enc-bbbbbbbbbbbb', 'conformant' => true, 'deviations' => [], 'activity_timeline' => []],
            ]),
        ]);
        (new RefreshArenaConformance)->handle(app(ArenaService::class));

        $rows = DB::table('arena.case_conformance')->where('pathway', 'sepsis')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('enc-bbbbbbbbbbbb', $rows[0]->case_oid);
        $this->assertTrue((bool) $rows[0]->conformant);

        // The aggregate cockpit signal landed alongside the case verdicts.
        $this->assertSame(1, DB::table('arena.conformance_signals')
            ->where('metric_key', 'quality.sepsis_conformance')->count());
    }

    /** @param list<array<string, mixed>> $caseResults */
    private function pathwayPayload(string $key, array $caseResults): array
    {
        $deviant = count(array_filter($caseResults, fn (array $case): bool => ! $case['conformant']));

        return [
            'pathway' => $key,
            'label' => 'Sepsis bundle (SEP-3)',
            'version' => 1,
            'owner' => 'quality',
            'case_type' => 'Encounter',
            'cases' => count($caseResults),
            'conformant' => count($caseResults) - $deviant,
            'deviant' => $deviant,
            'conformance_rate' => count($caseResults) > 0
                ? round((count($caseResults) - $deviant) / count($caseResults), 4)
                : null,
            'deviations' => [],
            'sample_deviant_cases' => [],
            'case_results' => $caseResults,
        ];
    }

    /** @param list<array<string, mixed>> $pathways */
    private function bindArenaServiceReturning(array $pathways): void
    {
        $service = $this->createPartialMock(ArenaService::class, ['conformance']);
        $service->method('conformance')
            ->willReturn(['available' => true, 'pathways' => $pathways]);

        $this->app->instance(ArenaService::class, $service);
    }
}
