<?php

namespace Tests\Feature\Patient;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Encounter;
use App\Models\Patient\PatientCarePreference;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientCarePreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDANCE_VERSION = 'test-preference-guidance-v1';

    public function test_patient_care_preference_creates_one_content_free_nonclinical_association_when_enabled(): void
    {
        $fixture = $this->fixture('care-preference-success', enabled: true);
        $idempotencyKey = (string) Str::uuid7();
        $clientMessageUuid = (string) Str::uuid7();
        $body = 'Quiet time after breakfast helps me prepare for therapy.';

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($fixture['token'])
            ->postJson("/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/threads", [
                'topic_code' => 'care_preference',
                'message' => $body,
                'client_message_uuid' => $clientMessageUuid,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.topic.code', 'care_preference')
            ->assertJsonPath('meta.idempotency_replayed', false)
            ->assertJsonMissing([
                'preference_uuid',
                'care_plan',
                'clinical_order',
                'consent',
                'assessment',
            ]);

        $preference = PatientCarePreference::query()->firstOrFail();
        $message = PatientMessage::query()->firstOrFail();
        $this->assertSame($fixture['principal']->getKey(), $preference->principal_id);
        $this->assertSame($fixture['grant']->getKey(), $preference->access_grant_id);
        $this->assertSame($message->getKey(), $preference->source_message_id);
        $this->assertSame($message->message_thread_id, $preference->message_thread_id);
        $this->assertStringNotContainsString($body, (string) $message->encrypted_body);
        $this->assertStringNotContainsString(
            $body,
            json_encode($preference->getAttributes(), JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $fixture['principal']->getKey(),
            'access_grant_id' => $fixture['grant']->getKey(),
            'event_type' => 'patient.care_preference.submitted',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($fixture['token'])
            ->postJson("/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/threads", [
                'topic_code' => 'care_preference',
                'message' => $body,
                'client_message_uuid' => $clientMessageUuid,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertOk()
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertDatabaseCount('patient_experience.care_preferences', 1);
        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertSame(1, $response->json('data.thread.version'));
    }

    public function test_disabling_persistent_preference_fact_preserves_existing_encrypted_message_behavior(): void
    {
        $fixture = $this->fixture('care-preference-legacy-message', enabled: false);

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($fixture['token'])
            ->postJson("/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/threads", [
                'topic_code' => 'care_preference',
                'message' => 'Please consider my preference for a quieter room in the evening.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.topic.code', 'care_preference');

        $this->assertDatabaseCount('patient_experience.care_preferences', 0);
        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    public function test_other_message_topics_never_create_care_preference_associations(): void
    {
        $fixture = $this->fixture('care-preference-topic-isolation', enabled: true);

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($fixture['token'])
            ->postJson("/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/threads", [
                'topic_code' => 'care_plan_question',
                'message' => 'Could you explain when therapy may visit?',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.topic.code', 'care_plan_question');

        $this->assertDatabaseCount('patient_experience.care_preferences', 0);
        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    /** @return array{principal: PatientPrincipal, grant: \App\Models\Patient\PatientEncounterAccessGrant, token: string} */
    private function fixture(string $seed, bool $enabled): array
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)->provision($seed);
        $unit = Unit::query()->create([
            'name' => 'Care Preference '.Str::upper(Str::random(6)),
            'abbreviation' => Str::upper(Str::random(6)),
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        $encounter = Encounter::query()->create([
            'patient_ref' => 'care-preference-'.Str::lower(Str::random(12)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $fixture['grant']->update([
            'source_encounter_id' => $encounter->getKey(),
            'scopes' => ['today:read', 'pathway:read', 'care_team:read', 'messaging:read', 'messaging:write'],
        ]);

        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.policy_version' => (string) $fixture['policy']->version,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.features.care_preferences' => $enabled,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => 'test-care-preference-policy-v1',
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'urgent_guidance_text' => 'Automated-test guidance only: use the approved bedside or emergency route for urgent help.',
                'default_response_window' => 'The test care team usually responds within one test hour.',
                'encryption_key_version' => 'test-encryption-key-v1',
                'handoff_consumer' => CarePreferenceHandoffReadiness::class,
                'topics' => [
                    'care_preference' => [
                        'label' => 'What matters to you',
                        'description' => 'Share a non-urgent preference. This is not a clinical care-plan change or order.',
                        'responsibility_pool_key' => 'test.unit.care-team',
                    ],
                    'care_plan_question' => [
                        'label' => 'Question about my care plan',
                        'description' => 'Ask a non-urgent question about a released care step.',
                        'responsibility_pool_key' => 'test.unit.care-team',
                    ],
                ],
            ],
        ]);

        return [
            'principal' => $fixture['principal'],
            'grant' => $fixture['grant']->fresh(),
            'token' => $this->token($fixture['principal']),
        ];
    }

    private function token(PatientPrincipal $principal): string
    {
        $sessionUuid = (string) Str::uuid7();
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

        return $principal->createToken('patient-access:'.$sessionUuid, ['patient:access'])->plainTextToken;
    }
}

class CarePreferenceHandoffReadiness implements PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool
    {
        return $policyVersion === 'test-care-preference-policy-v1';
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        \App\Models\Patient\PatientEncounterAccessGrant $grant,
    ): bool {
        return $policyVersion === 'test-care-preference-policy-v1'
            && in_array($topicCode, ['care_preference', 'care_plan_question'], true)
            && $responsibilityPoolKey === 'test.unit.care-team'
            && $grant->permits('messaging:write');
    }
}
