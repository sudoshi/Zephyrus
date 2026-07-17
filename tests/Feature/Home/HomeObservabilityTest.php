<?php

namespace Tests\Feature\Home;

use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Rpm\SyntheticRpmConnector;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeEscalation;
use App\Models\Home\RpmAlert;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Cockpit\DrillBuilder;
use App\Services\Eddy\EddyActionService;
use App\Services\Home\HewsService;
use App\Services\Home\HomeEscalationService;
use Database\Seeders\HomeHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'HOME_OBS_IDN', 'name' => 'Home Obs IDN', 'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'HOME_OBS_FACILITY',
            'facility_name' => 'Home Obs Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        config()->set('integrations.synthetic.facility_key', $facility->facility_key);
        config()->set('home_hospital.enabled', true);
        $this->seed(HomeHospitalDemoSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_demo_seed_opens_exactly_one_critical_alert(): void
    {
        $critical = RpmAlert::open()->where('severity', 'critical')->get();

        $this->assertCount(1, $critical);
        $this->assertSame('HOME-DEMO-001', $critical->first()->patient_ref);
        $this->assertSame('59408-5.low', $critical->first()->rule_key);
    }

    public function test_hews_is_deterministic_and_transparent(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-001')->firstOrFail();

        $first = app(HewsService::class)->computeForEpisode($episode);
        $second = app(HewsService::class)->computeForEpisode($episode);

        $this->assertNotNull($first);
        $this->assertSame($first['score'], $second['score']);
        $this->assertSame(['news2', 'baseline_deviation', 'trend', 'adherence'], array_keys($first['components']));
        $this->assertSame($first['score'], (int) array_sum($first['components']));
        // The deliberately-declining patient never reads low-risk.
        $this->assertContains($first['band'], ['medium', 'high']);
        $this->assertGreaterThan(0, $first['components']['trend']);
    }

    public function test_repeat_breach_dedupes_onto_one_open_alert(): void
    {
        $connector = app(SyntheticRpmConnector::class);

        foreach (['TX-DDP-1', 'TX-DDP-2'] as $tx) {
            $connector->handleWebhook(new WebhookEnvelope(payload: ['messages' => [[
                'event_type' => 'ObservationRecorded',
                'patient_ref' => 'HOME-DEMO-002',
                'loinc_code' => '59408-5',
                'display' => 'Oxygen saturation',
                'value' => 86,
                'unit' => '%',
                'transmission_id' => $tx,
                'observed_at' => now()->toIso8601String(),
            ]]]));
        }

        $alerts = RpmAlert::query()
            ->where('patient_ref', 'HOME-DEMO-002')
            ->where('rule_key', '59408-5.low')
            ->where('status', 'open')
            ->get();

        $this->assertCount(1, $alerts);
        $this->assertSame(2, (int) $alerts->first()->metadata['breach_count']);
        $this->assertSame('critical', $alerts->first()->severity);
    }

    public function test_acknowledge_and_resolve_record_the_human(): void
    {
        $alert = RpmAlert::open()->where('severity', 'critical')->firstOrFail();

        $this->actingAs($this->user)
            ->postJson("/api/home/alerts/{$alert->alert_uuid}/acknowledge")
            ->assertOk()
            ->assertJsonPath('alert.status', 'acknowledged');

        $this->actingAs($this->user)
            ->postJson("/api/home/alerts/{$alert->alert_uuid}/resolve")
            ->assertOk()
            ->assertJsonPath('alert.status', 'resolved');

        $fresh = $alert->fresh();
        $this->assertSame($this->user->email, $fresh->acknowledged_by);
        $this->assertSame($this->user->email, $fresh->resolved_by);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_escalation_timing_chain_and_response_minutes(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-003')->firstOrFail();
        $service = app(HomeEscalationService::class);

        $escalation = $service->open($episode, 'critical_vital');
        $this->assertSame('open', $escalation->status);

        // A second trigger joins the open chain instead of forking.
        $again = $service->open($episode, 'patient_request');
        $this->assertSame($escalation->home_escalation_id, $again->home_escalation_id);

        $escalation = $service->markDispatched($escalation, 'field_dispatch');
        $escalation = $service->markArrived($escalation);
        $this->assertSame('responding', $escalation->status);
        $this->assertNotNull($escalation->response_minutes);

        $escalation = $service->resolve($escalation, 'managed_at_home');
        $this->assertSame('resolved', $escalation->status);
        $this->assertSame('managed_at_home', $escalation->outcome);
    }

    public function test_adt_registration_closes_the_open_escalation_with_ed_return(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-004')->firstOrFail();
        $service = app(HomeEscalationService::class);
        $service->open($episode, 'clinical_deterioration');

        $closed = $service->closeForEdReturn('HOME-DEMO-004');

        $this->assertNotNull($closed);
        $this->assertSame('resolved', $closed->status);
        $this->assertSame('ed_return', $closed->outcome);
        $this->assertSame('adt_ed_registration', $closed->metadata['closed_by']);

        // No open escalation → a stranger's ADT is a no-op.
        $this->assertNull($service->closeForEdReturn('NOBODY-HOME'));
        $this->assertSame(0, HomeEscalation::open()->count());
    }

    public function test_escalation_api_drives_the_chain(): void
    {
        $episode = HomeEpisode::where('patient_ref', 'HOME-DEMO-005')->firstOrFail();

        $created = $this->actingAs($this->user)
            ->postJson('/api/home/escalations', [
                'episode_uuid' => $episode->episode_uuid,
                'trigger_type' => 'critical_vital',
            ])
            ->assertCreated()
            ->json('escalation');

        $uuid = $created['escalationUuid'];

        $this->actingAs($this->user)
            ->postJson("/api/home/escalations/{$uuid}/dispatch", ['response_mode' => 'field_dispatch'])
            ->assertOk()
            ->assertJsonPath('escalation.status', 'responding');

        $this->actingAs($this->user)
            ->postJson("/api/home/escalations/{$uuid}/arrive")
            ->assertOk();

        $resolved = $this->actingAs($this->user)
            ->postJson("/api/home/escalations/{$uuid}/resolve", ['outcome' => 'managed_at_home'])
            ->assertOk()
            ->json('escalation');

        $this->assertSame('resolved', $resolved['status']);
        $this->assertIsInt($resolved['responseMinutes']);
    }

    public function test_cockpit_snapshot_carries_the_home_domain_and_crit_alert(): void
    {
        $this->seed(\Database\Seeders\CockpitKpiDefinitionSeeder::class);
        $snapshot = app(\App\Services\Cockpit\SnapshotBuilder::class)->build();

        $this->assertArrayHasKey('home', $snapshot['domains']);
        $tiles = collect($snapshot['domains']['home']['tiles']);
        $unacked = $tiles->firstWhere('key', 'home.unacked_critical_vitals');

        $this->assertNotNull($unacked);
        $this->assertSame('crit', $unacked['status']);
        $this->assertSame(1.0, (float) $unacked['value']);

        // The Earned-Red ration: the crit home alert routes to the draft-only
        // escalation-response proposal — the agent proposes, a human disposes.
        $this->assertSame(
            'propose_escalation_response',
            EddyActionService::actionForAlert('home.unacked_critical_vitals', 'crit'),
        );
    }

    public function test_home_domain_absent_when_flag_off(): void
    {
        config()->set('home_hospital.enabled', false);

        $snapshot = app(\App\Services\Cockpit\SnapshotBuilder::class)->build();

        $this->assertArrayNotHasKey('home', $snapshot['domains']);
    }

    public function test_home_drill_builds_ward_board_and_funnel(): void
    {
        $this->seed(\Database\Seeders\CockpitKpiDefinitionSeeder::class);
        app(\App\Services\Cockpit\SnapshotBuilder::class)->build();

        $drill = app(DrillBuilder::class)->build('home');

        $this->assertNotNull($drill);
        $this->assertSame('Home Hospital — Virtual Ward', $drill['title']);
        $captions = array_column($drill['tables'], 'caption');
        $this->assertContains('Virtual ward board', $captions);
        $this->assertContains('Referral funnel', $captions);
    }

    public function test_command_page_renders_breach_first(): void
    {
        $this->actingAs($this->user)
            ->get('/home/command')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/Command')
                ->has('episodes', 8)
                ->where('episodes.0.patientRef', 'HOME-DEMO-001')
                ->where('episodes.0.breach', true)
                ->where('summary.breaches', 1)
            );
    }

    public function test_ingested_observation_persists_a_fhir_resource_version(): void
    {
        app(SyntheticRpmConnector::class)->handleWebhook(new WebhookEnvelope(payload: ['messages' => [[
            'event_type' => 'ObservationRecorded',
            'patient_ref' => 'HOME-DEMO-003',
            'loinc_code' => '8867-4',
            'display' => 'Heart rate',
            'value' => 88,
            'unit' => 'bpm',
            'transmission_id' => 'TX-FHIR-1',
            'observed_at' => now()->toIso8601String(),
        ]]]));

        $observation = \App\Models\Home\RpmObservation::query()
            ->where('transmission_id', 'TX-FHIR-1')
            ->firstOrFail();

        $version = DB::table('fhir.resource_versions')
            ->where('resource_type', 'Observation')
            ->where('fhir_id', $observation->observation_uuid)
            ->first();
        $this->assertNotNull($version);

        $link = DB::table('fhir.resource_links')
            ->where('resource_type', 'Observation')
            ->where('fhir_id', $observation->observation_uuid)
            ->where('internal_table', 'rpm_observations')
            ->where('internal_pk', (string) $observation->rpm_observation_id)
            ->first();
        $this->assertNotNull($link);
    }
}
