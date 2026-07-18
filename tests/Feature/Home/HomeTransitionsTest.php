<?php

namespace Tests\Feature\Home;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeReferral;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\HomeHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('home_hospital.enabled', true);
        $this->seed(HomeHospitalDemoSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_referral_traverses_the_funnel_and_activates_onto_the_virtual_unit(): void
    {
        $created = $this->actingAs($this->user)
            ->postJson('/api/home/referrals', [
                'patient_ref' => 'FUNNEL-TEST-001',
                'source' => 'ed_diversion',
                'screening' => ['condition_code' => 'heart_failure', 'condition_label' => 'Heart Failure', 'acuity_tier' => 3],
                'service_zone' => 'north',
            ])
            ->assertCreated()
            ->json('referral');

        $uuid = $created['referralUuid'];

        foreach (['screened', 'eligible', 'consented'] as $expected) {
            $this->actingAs($this->user)
                ->postJson("/api/home/referrals/{$uuid}/advance")
                ->assertOk()
                ->assertJsonPath('referral.status', $expected);
        }

        $this->actingAs($this->user)
            ->postJson("/api/home/referrals/{$uuid}/advance")
            ->assertOk()
            ->assertJsonPath('referral.status', 'activated');

        $episode = HomeEpisode::query()->where('patient_ref', 'FUNNEL-TEST-001')->sole();
        $this->assertSame('active', $episode->status);
        $this->assertSame('heart_failure', $episode->condition_code);

        $encounter = $episode->encounter;
        $this->assertNotNull($encounter);
        $this->assertSame('virtual_home', $encounter->unit->type);
        $this->assertSame('occupied', $encounter->bed->status);

        // Activation opens the inbound checklist and assigns a kit.
        $this->assertSame(1, $episode->transitions()->where('direction', 'inbound')->count());
        $this->assertSame(1, $episode->enrollments()->where('status', 'active')->count());
    }

    public function test_activation_fails_loudly_when_the_ward_is_full(): void
    {
        $ward = Unit::where('type', 'virtual_home')->sole();
        Bed::where('unit_id', $ward->unit_id)->where('status', 'available')
            ->update(['status' => 'blocked']);

        $referral = HomeReferral::where('patient_ref', 'HOME-REF-103')->sole(); // consented in the demo seed

        $this->actingAs($this->user)
            ->postJson("/api/home/referrals/{$referral->referral_uuid}/advance")
            ->assertUnprocessable();

        $this->assertSame('consented', $referral->fresh()->status);
    }

    public function test_snf_handoff_writes_care_transition_request_and_scored_regional_decision(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-001')->sole();

        $response = $this->actingAs($this->user)
            ->postJson("/api/home/episodes/{$episode->episode_uuid}/handoff", [
                'receiving_entity_type' => 'snf',
            ])
            ->assertCreated()
            ->json();

        $request = DB::table('prod.transport_requests')
            ->where('transport_request_id', $response['transportRequestId'])
            ->first();
        $this->assertSame('care_transition', $request->request_type);
        $this->assertSame('HOME-DEMO-001', $request->patient_ref);

        $decision = DB::table('regional.transfer_decisions')
            ->where('transfer_decision_id', $response['decisionId'])
            ->first();
        $this->assertNotNull($decision);
        $this->assertNotNull($decision->selected_score);
        $opportunityCost = json_decode((string) $decision->opportunity_cost_payload, true);
        $this->assertNotEmpty($opportunityCost);

        // A second open handoff for the same episode is rejected.
        $this->actingAs($this->user)
            ->postJson("/api/home/episodes/{$episode->episode_uuid}/handoff", [
                'receiving_entity_type' => 'snf',
            ])
            ->assertUnprocessable();
    }

    public function test_pcp_handoff_needs_no_regional_decision(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-002')->sole();

        $response = $this->actingAs($this->user)
            ->postJson("/api/home/episodes/{$episode->episode_uuid}/handoff", [
                'receiving_entity_type' => 'pcp',
            ])
            ->assertCreated()
            ->json();

        $this->assertNull($response['decisionId']);
        $this->assertNotNull($response['transportRequestId']);
    }

    public function test_routine_discharge_frees_the_slot_and_enrolls_the_30_day_cohort(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-003')->sole();
        $bedId = $episode->encounter->bed_id;
        $kitId = $episode->enrollments()->sole()->rpm_kit_id;

        $this->actingAs($this->user)
            ->postJson("/api/home/episodes/{$episode->episode_uuid}/discharge", [
                'disposition' => 'routine_discharge',
            ])
            ->assertOk()
            ->assertJsonPath('episode.status', 'completed')
            ->assertJsonPath('episode.disposition', 'routine_discharge');

        $this->assertSame('available', Bed::find($bedId)->status);
        $this->assertSame('available', \App\Models\Home\RpmKit::find($kitId)->status);
        $this->assertSame('discharged', $episode->fresh()->encounter->status);

        $cohort = HomeEpisode::query()
            ->where('patient_ref', 'HOME-DEMO-003')
            ->where('status', 'active')
            ->whereHas('program', fn ($q) => $q->where('program_type', 'post_discharge_rpm'))
            ->sole();

        $this->assertNull($cohort->encounter_id); // no slot — not an avoided bed-day
        $this->assertSame(30.0, (float) $cohort->target_los_days);

        $plan = $cohort->enrollments()->sole()->monitoring_plan;
        $this->assertSame(720, (int) $plan['cadence_minutes']['59408-5']); // step-down cadence
        $this->assertSame(1440, (int) $plan['cadence_minutes']['29463-7']); // daily weight
    }

    public function test_physical_address_is_confined_to_the_logistics_context(): void
    {
        $logistics = json_encode($this->actingAs($this->user)->getJson('/api/home/logistics')->json(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Street', $logistics);

        foreach (['/api/home/census', '/api/home/command', '/api/home/referrals', '/api/home/transitions', '/api/home/alerts', '/api/home/escalations'] as $endpoint) {
            $payload = json_encode($this->actingAs($this->user)->getJson($endpoint)->assertOk()->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Street', $payload, "Address leaked on {$endpoint}");
            $this->assertStringNotContainsString('logistics_address', $payload, "Address key leaked on {$endpoint}");
        }
    }

    public function test_worklists_surface_live_census_candidates(): void
    {
        // ED: one stable boarder (eligible) and one ESI-2 (excluded).
        DB::table('prod.ed_visits')->insert([
            ['patient_ref' => 'ED-CAND-01', 'arrived_at' => now()->subHours(5), 'esi_level' => 3,
                'admit_decision_at' => now()->subHours(2), 'bed_assigned_at' => null,
                'disposition' => 'admitted', 'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false],
            ['patient_ref' => 'ED-SICK-01', 'arrived_at' => now()->subHours(1), 'esi_level' => 2,
                'admit_decision_at' => null, 'bed_assigned_at' => null,
                'disposition' => null, 'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false],
        ]);

        // Step-down: a stable med/surg encounter near expected discharge.
        $unit = Unit::create(['name' => 'Test Med Surg', 'abbreviation' => 'TMS', 'type' => 'med_surg', 'staffed_bed_count' => 4]);
        Encounter::create([
            'patient_ref' => 'SD-CAND-01', 'unit_id' => $unit->unit_id, 'admitted_at' => now()->subDays(3),
            'expected_discharge_date' => now()->addDay()->toDateString(), 'acuity_tier' => 4, 'status' => 'active',
        ]);

        $payload = $this->actingAs($this->user)->getJson('/api/home/referrals')->assertOk()->json();

        $edRefs = array_column($payload['edCandidates'], 'patientRef');
        $this->assertContains('ED-CAND-01', $edRefs);
        $this->assertNotContains('ED-SICK-01', $edRefs);
        $this->assertTrue(collect($payload['edCandidates'])->firstWhere('patientRef', 'ED-CAND-01')['isBoarding']);

        $sdRefs = array_column($payload['stepDownCandidates'], 'patientRef');
        $this->assertContains('SD-CAND-01', $sdRefs);
    }
}
