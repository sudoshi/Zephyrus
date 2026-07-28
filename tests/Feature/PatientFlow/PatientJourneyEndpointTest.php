<?php

namespace Tests\Feature\PatientFlow;

use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\PatientFlow\FlowEvent;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/patient-flow/journey — the Patient Journey Drawer spine
 * (FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN §8 Phase A1, finding PJ-1).
 *
 * Proves the layered gates (persona lens → patient scope A2P authorization),
 * the ordered event/segment derivation, and the identity discipline: the raw
 * patient_ref must never appear anywhere in the response body. PHPUnit class
 * syntax — Pest is excluded on this environment.
 */
class PatientJourneyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Journey Test';
        $user->email = 'journeytest@example.com';
        $user->username = 'journeytest';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    /** Admit → transfer → discharge for one synthetic patient, via the real normalizer. */
    private function seedJourneyEvents(): string
    {
        $messages = [
            ['A01', '2026-07-25 08:00:00', 'TICU^TICU-R001^TICU-B001^ZEPHYRUS'],
            ['A02', '2026-07-25 11:30:00', 'MS5B^MS5B-R001^MS5B-B001^ZEPHYRUS'],
            ['A03', '2026-07-25 20:15:00', 'MS5B^MS5B-R001^MS5B-B001^ZEPHYRUS'],
        ];

        foreach ($messages as $index => [$trigger, $time, $location]) {
            $hl7Time = CarbonImmutable::parse($time)->format('YmdHis');
            $raw = implode("\r", [
                "MSH|^~\\&|EHR|AMC|FLOW|AMC|{$hl7Time}||ADT^{$trigger}|JRNMSG{$index}|P|2.5.1",
                "EVN|{$trigger}|{$hl7Time}",
                'PID|||JRN0001^^^AMC^MR||FLOW^JRN0001',
                "PV1||I|{$location}||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VISJRN0001^^^AMC^VN",
                '',
            ]);

            $event = app(FlowEventNormalizer::class)->normalize($raw);
            app(FlowEventRepository::class)->upsertNormalizedEvent($event);
        }

        $patientRef = (string) FlowEvent::query()->orderBy('occurred_at')->firstOrFail()->patient_ref;

        BedRequest::create([
            'patient_ref' => $patientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        return $patientRef;
    }

    public function test_journey_returns_the_ordered_story_for_a_patient_scope(): void
    {
        $patientRef = $this->seedJourneyEvents();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/patient-flow/journey?persona=house_supervisor&scope=patient:{$contextRef}");

        $response->assertOk()
            ->assertJsonPath('patient.patient_context_ref', $contextRef)
            ->assertJsonPath('patient.detail_authorized', true)
            ->assertJsonPath('lens.role_id', 'house_supervisor');

        // Events are chronological and complete.
        $events = $response->json('events');
        $this->assertCount(3, $events);
        $occurred = array_column($events, 'occurred_at');
        $sorted = $occurred;
        sort($sorted);
        $this->assertSame($sorted, $occurred, 'journey events must be chronological');

        // Movement folds into dwell segments: TICU (closed by the transfer)
        // then MS5B (closed by the discharge) — both with real dwell.
        $segments = $response->json('segments');
        $this->assertCount(2, $segments);
        $this->assertFalse($segments[0]['open']);
        $this->assertFalse($segments[1]['open']);
        $this->assertSame(210, $segments[0]['dwell_minutes']); // 08:00 → 11:30
        $this->assertSame(525, $segments[1]['dwell_minutes']); // 11:30 → 20:15

        // Logistics carry the pending bed request, hand-built and ref-free.
        $logistics = $response->json('logistics');
        $this->assertNotEmpty($logistics);
        $this->assertSame('bed_request', $logistics[0]['domain']);
        $this->assertSame('pending', $logistics[0]['status']);

        // Envelope basics: window, epoch key (null without a refresh ledger row).
        $this->assertIsArray($response->json('window'));
        $this->assertArrayHasKey('epoch', $response->json());

        // Identity sentinel: the raw patient_ref never appears anywhere in the
        // body — every surviving reference is the opaque ptok context ref.
        $this->assertStringNotContainsString($patientRef, $response->getContent());
        $this->assertStringContainsString($contextRef, $response->getContent());
    }

    public function test_pathway_progress_is_absent_while_the_care_pathway_flags_are_dark(): void
    {
        // FLOW-4D Phase D ships dark: with the serving gate and the demo flag
        // both off, the journey response must omit the pathway_progress key
        // entirely (byte-identical inertness).
        config(['care-pathways.assignment_enabled' => false, 'care-pathways.demo.enabled' => false]);

        $patientRef = $this->seedJourneyEvents();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/patient-flow/journey?persona=house_supervisor&scope=patient:{$contextRef}")
            ->assertOk();

        $this->assertArrayNotHasKey('pathway_progress', $response->json());
    }

    public function test_demo_overlay_attaches_a_synthetic_pathway_for_the_designated_patient(): void
    {
        // FLOW-4D Phase D5: the demo flag (independent of every serving gate)
        // shows one designated patient a synthetic, clearly-labeled pathway.
        config(['care-pathways.demo.enabled' => true, 'care-pathways.assignment_enabled' => false]);

        $patientRef = $this->seedJourneyEvents();
        Encounter::create([
            'patient_ref' => $patientRef,
            'admitted_at' => CarbonImmutable::now()->subDays(2),
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/patient-flow/journey?persona=house_supervisor&scope=patient:{$contextRef}")
            ->assertOk()
            ->assertJsonPath('pathway_progress.demo', true)
            ->assertJsonPath('pathway_progress.clinical_use', false)
            ->assertJsonPath('pathway_progress.notice', 'Demo — not clinical guidance')
            ->assertJsonPath('pathway_progress.source', 'synthetic_demo');

        $this->assertNotEmpty($response->json('pathway_progress.milestones'));
        // The synthetic pathway carries no real patient identity.
        $this->assertStringNotContainsString($patientRef, $response->getContent());
    }

    public function test_demo_los_calc_handles_fractional_timestamps_without_a_precision_deprecation(): void
    {
        // Regression: Carbon 3 diffInMinutes returns a float; a raw intdiv(float)
        // both loses precision (E_DEPRECATED) and breaks under PHP 9. Admit at a
        // NON-whole-minute offset so the minute delta is fractional.
        config(['care-pathways.demo.enabled' => true, 'care-pathways.assignment_enabled' => false]);
        Cache::flush(); // designated-encounter lookup is cached; keep this test deterministic

        $patientRef = $this->seedJourneyEvents();
        Encounter::create([
            'patient_ref' => $patientRef,
            'admitted_at' => CarbonImmutable::now()->subDays(2)->subHours(5)->subSeconds(19),
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);

        $deprecations = [];
        set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED && str_contains($message, 'loses precision')) {
                $deprecations[] = $message;
            }

            return false;
        });
        try {
            $response = $this->actingAs($this->admin())
                ->getJson("/api/patient-flow/journey?persona=house_supervisor&scope=patient:{$contextRef}")
                ->assertOk();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations, 'LOS calc must not trigger an implicit float→int deprecation');
        // LOS ≈ 2.2 days → intdiv to day 2: milestones through day 1 complete,
        // the day-2 milestone current.
        $summary = $response->json('pathway_progress.summary');
        $this->assertSame(3, $summary['completed']);
        $this->assertSame('hf_gdmt', $summary['current_stable_key']);
    }

    public function test_journey_requires_a_patient_scope(): void
    {
        $this->seedJourneyEvents();

        $this->actingAs($this->admin())
            ->getJson('/api/patient-flow/journey?persona=house_supervisor&scope=house')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'journey_requires_patient_scope');
    }

    public function test_an_aggregate_persona_is_forbidden_by_the_lens(): void
    {
        $patientRef = $this->seedJourneyEvents();
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);

        // Executive is patient_dots=none — the middleware denies before scope
        // resolution ever reaches the A2P matrix.
        $this->actingAs($this->admin())
            ->getJson("/api/patient-flow/journey?persona=executive&scope=patient:{$contextRef}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'flow_lens_forbidden');
    }

    public function test_an_unresolvable_ptok_is_forbidden(): void
    {
        $this->seedJourneyEvents();

        $this->actingAs($this->admin())
            ->getJson('/api/patient-flow/journey?persona=house_supervisor&scope=patient:ptok_deadbeefdeadbeefdeadbeef')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'flow_lens_forbidden');
    }
}
