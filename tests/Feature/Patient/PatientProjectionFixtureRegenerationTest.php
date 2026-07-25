<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Captures the six patient-care projection DTO fixtures from the real patient
 * API boundary. The deterministic synthetic projection provisioner is limited
 * to the testing runtime; no real patient, release policy, credential, or
 * remote database is used.
 *
 * On every run this test compares the checked-in fixtures with deterministic
 * patient BFF responses. Set the opt-in environment flag only when the patient
 * projection contract intentionally changes and the reviewed fixtures should be
 * rewritten:
 *
 *   HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1 php artisan test --filter=PatientProjectionFixtureRegenerationTest
 */
class PatientProjectionFixtureRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures/patient';

    /** @var array<string, string> */
    private const ROUTES = [
        'patient-today.json' => 'today',
        'patient-pathway.json' => 'pathway',
        'patient-pathway-events.json' => 'pathway/events',
        'patient-discharge-readiness.json' => 'discharge-readiness',
        'patient-rounds-summary.json' => 'rounds/summary',
        'patient-care-team.json' => 'care-team',
    ];

    public function test_patient_projection_fixtures_match_deterministic_bff_contract(): void
    {
        Carbon::setTestNow('2026-07-25T14:00:00Z');

        try {
            $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('shared-patient-dto-fixture');
            $this->enableProjectionFeatures((string) $fixture['policy']->version);
            $token = $this->patientToken($fixture['principal']);
            $encounterUuid = (string) $fixture['grant']->encounter_uuid;

            foreach (self::ROUTES as $filename => $suffix) {
                $this->app['auth']->forgetGuards();
                $response = $this->withToken($token)
                    ->withHeader('X-Request-ID', $this->requestIdFor($filename))
                    ->getJson("/api/patient/v1/encounters/{$encounterUuid}/{$suffix}")
                    ->assertOk()
                    ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                    ->assertJsonPath('data.encounter_uuid', $encounterUuid)
                    ->assertJsonPath('data.kind', $this->kindFor($filename))
                    ->assertJsonPath('meta.stale', false)
                    ->assertJsonPath('meta.source_freshness.status', 'current');

                $payload = $response->json();
                $this->assertIsArray($payload, "{$filename} must be a patient API envelope.");
                $this->assertArrayHasKey('data', $payload, "{$filename} must contain data.");
                $this->assertArrayHasKey('meta', $payload, "{$filename} must contain meta.");
                $this->assertArrayHasKey('links', $payload, "{$filename} must contain links.");
                $serialized = $this->formatFixture($response->getContent());
                if (env('HUMMINGBIRD_PATIENT_FIXTURE_DUMP')) {
                    $this->writeFixture($filename, $serialized);
                } else {
                    $this->assertSame(
                        file_get_contents(base_path(self::FIXTURE_DIR.'/'.$filename)),
                        $serialized,
                        "{$filename} is stale. Review the deterministic BFF change, then run HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1 to regenerate it.",
                    );
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function enableProjectionFeatures(string $policyVersion): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.policy_version' => $policyVersion,
            'hummingbird-patient.features.today' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.rounds_summary' => true,
            'hummingbird-patient.features.care_team' => true,
        ]);
    }

    private function patientToken(PatientPrincipal $principal): string
    {
        $sessionUuid = '019f5200-0000-7000-8000-000000000001';
        PatientSession::query()->create([
            'session_uuid' => $sessionUuid,
            'principal_id' => $principal->getKey(),
            'auth_method' => 'password',
            'status' => 'active',
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
            'idle_expires_at' => now()->addDay(),
        ]);

        return $principal->createToken(
            'patient-access:'.$sessionUuid,
            ['patient:access'],
        )->plainTextToken;
    }

    private function requestIdFor(string $filename): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://zephyrus.example.test/patient-fixture-request/'.$filename)->toString();
    }

    private function kindFor(string $filename): string
    {
        return match ($filename) {
            'patient-today.json' => 'today',
            'patient-pathway.json' => 'pathway',
            'patient-pathway-events.json' => 'pathway_events',
            'patient-discharge-readiness.json' => 'discharge_readiness',
            'patient-rounds-summary.json' => 'rounds_summary',
            'patient-care-team.json' => 'care_team',
        };
    }

    private function formatFixture(string $responseBody): string
    {
        return json_encode(
            json_decode($responseBody, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function writeFixture(string $filename, string $serialized): void
    {
        $directory = base_path(self::FIXTURE_DIR);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->fail('Unable to create the patient projection fixture directory.');
        }

        file_put_contents(
            $directory.'/'.$filename,
            $serialized,
        );
    }
}
