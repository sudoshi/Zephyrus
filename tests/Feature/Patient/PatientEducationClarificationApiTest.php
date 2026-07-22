<?php

namespace Tests\Feature\Patient;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Encounter;
use App\Models\Patient\PatientEducationClarificationRequest;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientEducationClarificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDANCE_VERSION = 'test-urgent-guidance-v1';

    public function test_released_education_can_create_one_encrypted_accountable_clarification_without_attestation(): void
    {
        $fixture = $this->fixture('education-clarification-success');
        $educationItemUuid = (string) $fixture['pathway']->content['education'][0]['item_uuid'];
        $clientMessageUuid = (string) Str::uuid7();
        $idempotencyKey = (string) Str::uuid7();
        $body = 'Could you explain the safe timing in simpler words?';
        $path = "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/education/{$educationItemUuid}/clarifications";

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($fixture['token'])
            ->postJson($path, [
                'message' => $body,
                'client_message_uuid' => $clientMessageUuid,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.topic.code', 'education_clarification')
            ->assertJsonPath('meta.idempotency_replayed', false)
            ->assertJsonMissing([
                'education_item_uuid',
                'pathway_projection_id',
                'completion',
                'comprehension',
                'consent',
                'assessment',
                'care_plan',
            ]);

        $fact = PatientEducationClarificationRequest::query()->firstOrFail();
        $message = PatientMessage::query()->firstOrFail();
        $this->assertSame($educationItemUuid, (string) $fact->education_item_uuid);
        $this->assertSame($message->getKey(), $fact->source_message_id);
        $this->assertSame($message->message_thread_id, $fact->message_thread_id);
        $this->assertStringNotContainsString($body, (string) $message->encrypted_body);
        $this->assertStringNotContainsString(
            $body,
            json_encode($fact->getAttributes(), JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $fixture['principal']->getKey(),
            'access_grant_id' => $fixture['grant']->getKey(),
            'event_type' => 'patient.education.clarification_requested',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($fixture['token'])
            ->postJson($path, [
                'message' => $body,
                'client_message_uuid' => $clientMessageUuid,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertOk()
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertDatabaseCount('patient_experience.education_clarification_requests', 1);
        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertSame(
            1,
            $response->json('data.thread.version'),
        );
    }

    public function test_unreleased_or_fabricated_education_uuid_creates_no_message_or_association(): void
    {
        $fixture = $this->fixture('education-clarification-not-found');
        $path = "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/education/".(string) Str::uuid7().'/clarifications';

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($fixture['token'])
            ->postJson($path, [
                'message' => 'Please explain this item.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissing([
                'education_item_uuid',
                'pathway_projection_id',
                'source_encounter_id',
            ]);

        $this->assertDatabaseCount('patient_experience.education_clarification_requests', 0);
        $this->assertDatabaseCount('patient_experience.message_threads', 0);
        $this->assertDatabaseCount('patient_experience.messages', 0);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $fixture['principal']->getKey(),
            'event_type' => 'patient.education.clarification_denied',
            'reason_code' => 'released_education_not_available',
        ]);
    }

    public function test_reserved_education_topic_cannot_be_composed_on_the_generic_thread_endpoint(): void
    {
        $fixture = $this->fixture('education-clarification-reserved-topic');

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($fixture['token'])
            ->postJson("/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/threads", [
                'topic_code' => 'education_clarification',
                'message' => 'This must be bound to released education instead.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseCount('patient_experience.message_threads', 0);
        $this->assertDatabaseCount('patient_experience.messages', 0);
    }

    /** @return array{principal: PatientPrincipal, grant: \App\Models\Patient\PatientEncounterAccessGrant, pathway: \App\Models\Patient\PatientEncounterProjection, token: string} */
    private function fixture(string $seed): array
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)->provision($seed);
        $unit = Unit::query()->create([
            'name' => 'Education Clarification '.Str::upper(Str::random(6)),
            'abbreviation' => Str::upper(Str::random(6)),
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        $encounter = Encounter::query()->create([
            'patient_ref' => 'education-clarification-'.Str::lower(Str::random(12)),
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
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.features.teach_back' => true,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => 'test-messaging-policy-v1',
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'urgent_guidance_text' => 'Automated-test guidance only: use the approved bedside or emergency route for urgent help.',
                'default_response_window' => 'The test care team usually responds within one test hour.',
                'encryption_key_version' => 'test-encryption-key-v1',
                'handoff_consumer' => EducationClarificationHandoffReadiness::class,
                'topics' => [
                    'education_clarification' => [
                        'label' => 'Question about care information',
                        'description' => 'Ask the care team to explain information shown in your care pathway without recording that you understand it.',
                        'responsibility_pool_key' => 'test.unit.care-team',
                        'composition_mode' => 'released_education_only',
                    ],
                ],
            ],
        ]);

        $token = $this->token($fixture['principal']);

        return [
            'principal' => $fixture['principal'],
            'grant' => $fixture['grant']->fresh(),
            'pathway' => $fixture['projections']['pathway'],
            'token' => $token,
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

class EducationClarificationHandoffReadiness implements PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool
    {
        return $policyVersion === 'test-messaging-policy-v1';
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        \App\Models\Patient\PatientEncounterAccessGrant $grant,
    ): bool {
        return $policyVersion === 'test-messaging-policy-v1'
            && $topicCode === 'education_clarification'
            && $responsibilityPoolKey === 'test.unit.care-team'
            && $grant->permits('messaging:write');
    }
}
