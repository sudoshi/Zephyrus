<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\EmissionMap;
use App\Models\BedRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use App\Services\PatientFlow\PathwayDeviationSceneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase C (FLOW-4D plan §8 C3/C5) — the adherence surface's composed gates and
 * the two new endpoints. The whole surface requires ARENA_ENABLED ∧
 * FLOW4D_CONFORMANCE_ENABLED ∧ a patient-dots flow lens; any leg missing means
 * 404/404/403 respectively, so the navigator ships byte-identical with the
 * flag off. PHPUnit class syntax — Pest is excluded on this environment.
 */
class Flow4dConformanceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.arena.enabled' => true, 'services.flow4d.conformance' => true]);
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Flow4d Conformance Test';
        $user->email = 'flow4dconf@example.com';
        $user->username = 'flow4dconf';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    /** @return array{patient_ref: string, encounter_ref: string} */
    private function seedPatient(string $mrn, string $visit, string $time): array
    {
        $raw = implode("\r", [
            "MSH|^~\\&|EHR|AMC|FLOW|AMC|{$time}||ADT^A01|F4C{$mrn}|P|2.5.1",
            "EVN|A01|{$time}",
            "PID|||{$mrn}^^^AMC^MR||FLOW^{$mrn}",
            "PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||{$visit}^^^AMC^VN",
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event);

        // Seed times increase per call, so the newest row is the one just added.
        $row = DB::table('flow_core.flow_events')
            ->orderByDesc('occurred_at')
            ->first(['patient_ref', 'encounter_ref']);

        // A2P authorization inside patientScope() needs operational context
        // (same fixture rule as PatientLensApiTest / CaseConformanceTest).
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

    private function cacheVerdict(string $caseOid, string $pathway, bool $conformant, array $deviations): void
    {
        DB::table('arena.case_conformance')->insert([
            'case_oid' => $caseOid,
            'pathway' => $pathway,
            'pathway_version' => 1,
            'conformant' => $conformant,
            'deviations' => json_encode($deviations),
            'activity_timeline' => json_encode(['sepsis_recognition' => '2026-07-26T08:10:00+00:00']),
            'computed_at' => now()->subMinutes(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- C5: the composed gate matrix ------------------------------------

    /** @return array<string, array{bool, bool, string, int}> */
    public static function gateMatrix(): array
    {
        // (arena, flag, persona) → expected status on /conformance/scene.
        // Missing arena or flag legs read as "the surface does not exist"
        // (404); a lens without patient dots is an explicit 403.
        return [
            'arena off · flag off · full-dots' => [false, false, 'house_supervisor', 404],
            'arena off · flag off · aggregate' => [false, false, 'executive', 404],
            'arena off · flag on · full-dots' => [false, true, 'house_supervisor', 404],
            'arena off · flag on · aggregate' => [false, true, 'executive', 404],
            'arena on · flag off · full-dots' => [true, false, 'house_supervisor', 404],
            'arena on · flag off · aggregate' => [true, false, 'executive', 404],
            'arena on · flag on · aggregate' => [true, true, 'executive', 403],
            'arena on · flag on · full-dots' => [true, true, 'house_supervisor', 200],
        ];
    }

    #[DataProvider('gateMatrix')]
    public function test_scene_endpoint_gate_matrix(bool $arena, bool $flag, string $persona, int $expected): void
    {
        config(['services.arena.enabled' => $arena, 'services.flow4d.conformance' => $flag]);

        $this->actingAs($this->admin())
            ->getJson("/api/arena/conformance/scene?persona={$persona}&scope=house")
            ->assertStatus($expected);
    }

    public function test_case_endpoint_is_absent_when_the_flag_is_off(): void
    {
        config(['services.flow4d.conformance' => false]);
        $refs = $this->seedPatient('F4CA0001', 'VISF4CA1', '20260726080000');
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->getJson("/api/arena/conformance/case?persona=house_supervisor&scope=patient:{$contextRef}")
            ->assertNotFound();
    }

    public function test_exception_note_is_absent_when_the_flag_is_off(): void
    {
        config(['services.flow4d.conformance' => false]);
        $refs = $this->seedPatient('F4CA0001', 'VISF4CA1', '20260726080000');
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->postJson("/api/arena/conformance/exception-note?persona=house_supervisor&scope=patient:{$contextRef}", [
                'pathway' => 'sepsis',
                'note' => 'Documented exception.',
            ])
            ->assertNotFound();
    }

    // ---- C3: the bulk scene-flags read ------------------------------------

    public function test_scene_flags_ship_deviant_patients_only_keyed_by_ptok(): void
    {
        $deviant = $this->seedPatient('F4CDEV01', 'VISF4CD1', '20260726080000');
        $conformant = $this->seedPatient('F4CCON01', 'VISF4CC1', '20260726081500');

        $this->cacheVerdict('enc-'.EmissionMap::hashRef($deviant['encounter_ref']), 'sepsis', false, ['antibiotic_late', 'no_repeat_lactate']);
        $this->cacheVerdict('enc-'.EmissionMap::hashRef($conformant['encounter_ref']), 'sepsis', true, []);
        // A deviant verdict whose case never joins an in-window patient must
        // not appear (it belongs to a patient outside the scene).
        $this->cacheVerdict('enc-ffffffffffff', 'sepsis', false, ['no_lactate']);

        $deviantPtok = app(MobilePatientContextService::class)->contextRefFor($deviant['patient_ref']);
        $conformantPtok = app(MobilePatientContextService::class)->contextRefFor($conformant['patient_ref']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/arena/conformance/scene?persona=house_supervisor&scope=house');

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('cadence_minutes', 30)
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.ref', $deviantPtok)
            ->assertJsonPath('patients.0.pathways.0.pathway', 'sepsis')
            ->assertJsonPath('patients.0.pathways.0.pathway_version', 1);

        $codes = $response->json('patients.0.pathways.0.deviations');
        $this->assertEqualsCanonicalizing(['antibiotic_late', 'no_repeat_lactate'], $codes);
        $this->assertNotNull($response->json('as_of'));

        // Identity discipline: raw refs and de-identified case oids stay
        // server-side; conformant patients do not ship at all.
        $content = $response->getContent();
        $this->assertStringNotContainsString($deviant['patient_ref'], $content);
        $this->assertStringNotContainsString($deviant['encounter_ref'], $content);
        $this->assertStringNotContainsString('enc-', $content);
        $this->assertStringNotContainsString((string) $conformantPtok, $content);
    }

    public function test_scene_flags_respect_a_restrictive_patient_depth(): void
    {
        // Structural parity proof: the service runs the SAME
        // canViewPatientRow gate the scene's event feed uses, so a depth that
        // grants nothing ships nothing — even with deviant verdicts cached.
        $deviant = $this->seedPatient('F4CDEV02', 'VISF4CD2', '20260726080000');
        $this->cacheVerdict('enc-'.EmissionMap::hashRef($deviant['encounter_ref']), 'sepsis', false, ['no_lactate']);

        $payload = app(PathwayDeviationSceneService::class)->build([
            'lens' => ['patient_dots' => 'scoped'],
            'scope' => ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null, 'patient_context_ref' => null],
            'depth' => 'task',
            'task_refs' => [],
            'visible_unit_ids' => [],
        ], []);

        $this->assertTrue($payload['available']);
        $this->assertSame([], $payload['patients']);
    }

    // ---- C1: the exception-note draft -------------------------------------

    public function test_exception_note_lands_a_pending_eddy_draft(): void
    {
        $refs = $this->seedPatient('F4CNOTE1', 'VISF4CN1', '20260726080000');
        $this->cacheVerdict('enc-'.EmissionMap::hashRef($refs['encounter_ref']), 'sepsis', false, ['antibiotic_late']);
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/arena/conformance/exception-note?persona=house_supervisor&scope=patient:{$contextRef}", [
                'pathway' => 'sepsis',
                'note' => 'Antibiotics were held pending nephrology guidance — documented variance.',
                'deviations' => ['antibiotic_late'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('action_type', 'flag_pathway_deviation')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('approved', false)
            ->assertJsonPath('patient_context_ref', $contextRef);

        // Governance records ride the existing lifecycle: draft
        // recommendation + draft action + PENDING approval, never approved.
        $recommendation = DB::table('ops.recommendations')
            ->where('recommendation_type', 'eddy_pathway_deviation')->first();
        $this->assertNotNull($recommendation);
        $this->assertSame('draft', $recommendation->status);
        $this->assertSame('eddy', $recommendation->created_by_source);

        $action = DB::table('ops.actions')->where('action_type', 'flag_pathway_deviation')->first();
        $this->assertNotNull($action);
        $this->assertSame('draft', $action->status);

        $approval = DB::table('ops.approvals')->where('action_id', $action->action_id)->first();
        $this->assertSame('pending', $approval->status);

        // Identity discipline in the durable record: the params carry the
        // opaque context ref and pathway state — never the raw patient ref.
        $payload = json_decode((string) $action->payload, true);
        $this->assertSame('sepsis', $payload['pathway']);
        $this->assertSame(['antibiotic_late'], $payload['deviations']);
        $this->assertSame($contextRef, $payload['patient_context_ref']);
        $this->assertTrue($payload['exception_note']);
        $this->assertStringNotContainsString($refs['patient_ref'], (string) $action->payload);
    }

    public function test_exception_note_requires_a_cached_deviation(): void
    {
        $refs = $this->seedPatient('F4CNOTE2', 'VISF4CN2', '20260726080000');
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->postJson("/api/arena/conformance/exception-note?persona=house_supervisor&scope=patient:{$contextRef}", [
                'pathway' => 'sepsis',
                'note' => 'There is nothing cached for this pathway.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'no_cached_deviation');

        $this->assertSame(0, DB::table('ops.recommendations')->count());
    }

    public function test_exception_note_requires_a_patient_scope(): void
    {
        $this->seedPatient('F4CNOTE3', 'VISF4CN3', '20260726080000');

        $this->actingAs($this->admin())
            ->postJson('/api/arena/conformance/exception-note?persona=house_supervisor&scope=house', [
                'pathway' => 'sepsis',
                'note' => 'Missing patient scope.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'exception_note_requires_patient_scope');
    }

    public function test_exception_note_is_forbidden_for_an_aggregate_persona(): void
    {
        $refs = $this->seedPatient('F4CNOTE4', 'VISF4CN4', '20260726080000');
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($refs['patient_ref']);

        $this->actingAs($this->admin())
            ->postJson("/api/arena/conformance/exception-note?persona=executive&scope=patient:{$contextRef}", [
                'pathway' => 'sepsis',
                'note' => 'Aggregate personas have no per-patient surface.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'flow_lens_forbidden');
    }
}
