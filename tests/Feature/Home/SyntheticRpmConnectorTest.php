<?php

namespace Tests\Feature\Home;

use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Rpm\SyntheticRpmConnector;
use App\Models\Home\RpmDevice;
use App\Models\Home\RpmObservation;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\Raw\DeadLetter;
use Database\Seeders\HomeHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyntheticRpmConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'RPM_TEST_IDN',
            'name' => 'RPM Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'RPM_TEST_FACILITY',
            'facility_name' => 'RPM Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        config()->set('integrations.synthetic.facility_key', $facility->facility_key);
        config()->set('home_hospital.enabled', true);
        $this->seed(HomeHospitalDemoSeeder::class);
    }

    public function test_webhook_observation_projects_into_the_rpm_ledger(): void
    {
        $run = app(SyntheticRpmConnector::class)->handleWebhook($this->envelope());

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->messages_received);
        $this->assertSame(2, $run->messages_succeeded);

        $observation = RpmObservation::sole();
        $this->assertSame('HOME-DEMO-001', $observation->patient_ref);
        $this->assertSame('59408-5', $observation->loinc_code);
        $this->assertSame(93.0, $observation->value);
        $this->assertSame('ok', $observation->quality_flag);
        $this->assertNotNull($observation->rpm_enrollment_id);

        $device = RpmDevice::where('serial_number', 'SH100-001-PU')->sole();
        $this->assertSame(77, $device->battery_pct);
        $this->assertNotNull($device->last_transmission_at);
    }

    public function test_replaying_the_same_webhook_is_idempotent(): void
    {
        $connector = app(SyntheticRpmConnector::class);

        $connector->handleWebhook($this->envelope());
        $second = $connector->handleWebhook($this->envelope());

        $this->assertSame(2, $second->messages_skipped);
        $this->assertSame(1, RpmObservation::count());
    }

    public function test_observation_for_unknown_patient_dead_letters(): void
    {
        $run = app(SyntheticRpmConnector::class)->handleWebhook(new WebhookEnvelope(
            payload: ['messages' => [[
                'event_type' => 'ObservationRecorded',
                'patient_ref' => 'NOT-ENROLLED-999',
                'loinc_code' => '8867-4',
                'value' => 120,
                'transmission_id' => 'TX-UNKNOWN-0001',
            ]]],
        ));

        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->messages_failed);
        $this->assertSame(0, RpmObservation::count());
        $this->assertSame(1, DeadLetter::where('failure_stage', 'mapping')->count());
    }

    private function envelope(): WebhookEnvelope
    {
        return new WebhookEnvelope(
            payload: ['messages' => [[
                'event_type' => 'ObservationRecorded',
                'patient_ref' => 'HOME-DEMO-001',
                'loinc_code' => '59408-5',
                'display' => 'Oxygen saturation',
                'value' => 93,
                'unit' => '%',
                'transmission_id' => 'TX-TEST-0001',
                'device_serial' => 'SH100-001-PU',
                'observed_at' => now()->toIso8601String(),
            ], [
                'event_type' => 'DeviceStatusChanged',
                'device_serial' => 'SH100-001-PU',
                'status' => 'active',
                'battery_pct' => 77,
                'transmission_id' => 'TX-TEST-0002',
            ]]],
        );
    }
}
