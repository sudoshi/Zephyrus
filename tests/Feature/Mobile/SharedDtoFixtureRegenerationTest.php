<?php

namespace Tests\Feature\Mobile;

use App\Models\BedRequest;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\OperationalActivityLedger;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalizesMobileFixtureValues;
use Tests\TestCase;

/**
 * Captures the non-Flow shared DTO fixtures from real, factory-seeded mobile
 * BFF responses. The artifacts are intentionally not hand-authored so PHP,
 * Swift, and Kotlin exercise the exact Laravel envelopes.
 *
 * Run with FlowFixtureRegenerationTest when a shared DTO changes:
 *
 *   HUMMINGBIRD_FIXTURE_DUMP=1 php artisan test --filter='(FlowFixtureRegenerationTest|SharedDtoFixtureRegenerationTest)'
 *
 * Normal CI compares the checked-in artifacts with deterministic BFF responses
 * without writing. The environment flag above is the explicit reviewed writer.
 */
class SharedDtoFixtureRegenerationTest extends TestCase
{
    use CanonicalizesMobileFixtureValues;
    use RefreshDatabase;

    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures';

    public function test_patient_context_bff_serializes_isolation_requirement_as_boolean(): void
    {
        $bedManager = $this->staffUser('bed_manager', 'isolation-contract-bed-manager');
        $patientRef = 'SYNTHETIC-ISOLATION-CONTRACT-PATIENT';
        $bedRequest = BedRequest::query()->create([
            'patient_ref' => $patientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
            'isolation_required' => 'none',
            'is_deleted' => false,
        ]);

        Sanctum::actingAs($bedManager, ['mobile:read']);
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);
        $this->assertNotNull($contextRef);

        $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.header.isolation_required', false);

        $bedRequest->update(['isolation_required' => 'airborne']);

        $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.header.isolation_required', true);
    }

    public function test_factory_backed_shared_dto_fixtures_match_deterministic_bff_contract(): void
    {
        Carbon::setTestNow('2026-07-24T12:00:00Z');

        try {
            $this->seed(RtdcSeeder::class);

            $bedManager = $this->staffUser('bed_manager', 'fixture-bed-manager');
            $patientRef = 'SYNTHETIC-SHARED-DTO-PATIENT';
            $bedRequest = BedRequest::query()->create([
                'patient_ref' => $patientRef,
                'source' => 'ed',
                'service' => 'Medicine',
                'acuity_tier' => 1,
                'required_unit_type' => 'med_surg',
                'status' => 'pending',
                'is_deleted' => false,
            ]);
            TransportRequest::query()->create([
                'request_uuid' => '019f0000-0000-7000-8000-000000000002',
                'request_type' => 'inpatient',
                'priority' => 'stat',
                'status' => 'requested',
                'patient_ref' => $patientRef,
                'encounter_ref' => 'SYNTHETIC-SHARED-DTO-ENCOUNTER',
                'origin' => 'ED',
                'destination' => '4 West',
                'transport_mode' => 'stretcher',
                'requested_at' => now()->subMinutes(5),
                'needed_at' => now()->addMinutes(10),
                'is_deleted' => false,
            ]);

            app(OperationalActivityLedger::class)->record('bed_request.created', [
                'event_uuid' => '019f0000-0000-7000-8000-000000000001',
                'occurred_at' => now(),
                'actor_user_id' => $bedManager->getKey(),
                'actor_role' => 'bed_manager',
                'domain' => 'rtdc',
                'scope' => [
                    'bed_request_id' => $bedRequest->getKey(),
                    'patient_ref' => $patientRef,
                ],
                'status' => ['previous' => 'none', 'current' => 'pending', 'severity' => 'warning'],
                'entities' => [[
                    'entity_type' => 'bed_request',
                    'entity_ref' => (string) $bedRequest->getKey(),
                    'patient_ref' => $patientRef,
                ]],
            ]);

            Sanctum::actingAs($bedManager, ['mobile:read']);
            $contextRef = app(MobilePatientContextService::class)->contextRefFor($patientRef);
            $this->assertNotNull($contextRef);

            $fixtures = [
                'mobile-altitude-home.json' => $this->getJson('/api/mobile/v1/altitude/home?persona=bed_manager')
                    ->assertOk(),
                'mobile-for-you.json' => $this->getJson('/api/mobile/v1/for-you?persona=bed_manager')
                    ->assertOk(),
                'mobile-activity-feed.json' => $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
                    ->assertOk(),
                'mobile-patient-operational-context.json' => $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona=bed_manager")
                    ->assertOk(),
            ];

            $transportUser = $this->staffUser('transport', 'fixture-transporter');
            Sanctum::actingAs($transportUser, ['mobile:read']);
            $fixtures['mobile-transport-queue.json'] = $this->getJson('/api/mobile/v1/transport/queue?persona=transport')
                ->assertOk();

            foreach ($fixtures as $name => $response) {
                $payload = $response->json();
                $this->assertArrayHasKey('data', $payload, "{$name} must be a BFF envelope.");
                $this->assertArrayHasKey('meta', $payload, "{$name} must be a BFF envelope.");
                $this->assertArrayHasKey('links', $payload, "{$name} must be a BFF envelope.");
                $serialized = $this->formatFixture($response->getContent());

                if (env('HUMMINGBIRD_FIXTURE_DUMP')) {
                    $this->writeFixture($name, $serialized);
                } else {
                    $this->assertSame(
                        $this->canonicalizeMobileFixtureValue(json_decode(
                            file_get_contents(base_path(self::FIXTURE_DIR.'/'.$name)),
                            true,
                            flags: JSON_THROW_ON_ERROR,
                        )),
                        $this->canonicalizeMobileFixtureValue($payload),
                        "{$name} is stale. Review the deterministic BFF change, then run HUMMINGBIRD_FIXTURE_DUMP=1 to regenerate it.",
                    );
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function staffUser(string $role, string $username): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Fixture '.Str::headline($role),
            'email' => "{$username}@example.test",
            'username' => $username,
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function formatFixture(string $responseBody): string
    {
        return json_encode(
            json_decode($responseBody, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function writeFixture(string $name, string $serialized): void
    {
        file_put_contents(
            base_path(self::FIXTURE_DIR.'/'.$name),
            $serialized,
        );
    }
}
