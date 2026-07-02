<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAltitudeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_you_drill_and_patient_context_use_altitude_contracts(): void
    {
        Sanctum::actingAs($this->user(), ['mobile:read']);

        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-ALTITUDE-1',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        $forYou = $this->getJson('/api/mobile/v1/for-you?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.altitude', 'A2')
            ->assertJsonPath('data.0.patient_context_ref', fn (?string $ref): bool => str_starts_with((string) $ref, 'ptok_'))
            ->getContent();

        $this->assertStringNotContainsString('SECRET-MRN-ALTITUDE-1', $forYou);

        $drill = $this->getJson("/api/mobile/v1/drills/bedreq-{$bedRequest->bed_request_id}?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'rtdc')
            ->json('data');

        $this->assertStringStartsWith('ptok_', $drill['patient_context_ref']);

        $this->getJson("/api/mobile/v1/patients/{$drill['patient_context_ref']}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2P')
            ->assertJsonPath('data.patient.patient_context_ref', $drill['patient_context_ref'])
            ->assertJsonPath('data.patient.phi_minimized', true)
            ->assertJsonPath('data.dependencies.0.dependency_type', 'bed_request');

        $this->getJson('/api/mobile/v1/patients/SECRET-MRN-ALTITUDE-1/operational-context?persona=bed_manager')
            ->assertForbidden();
    }

    public function test_mobile_write_records_activity_and_acknowledgement(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user, ['mobile:read', 'mobile:act']);

        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->postJson("/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $activity = $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'barrier.resolved')
            ->json('data.0');

        $this->postJson("/api/mobile/v1/activity/{$activity['event_uuid']}/ack?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);
    }

    private function user(): User
    {
        $user = new User;
        $user->name = 'Mobile Altitude';
        $user->email = 'mobile-altitude@example.com';
        $user->username = 'mobile-altitude';
        $user->password = bcrypt('secret-test-password');
        $user->must_change_password = false;
        $user->is_active = true;
        $user->role = 'bed_manager';
        $user->workflow_preference = 'rtdc';
        $user->save();

        return $user;
    }
}
