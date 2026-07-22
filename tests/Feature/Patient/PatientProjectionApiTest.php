<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientContentAction;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\Patient\PatientSession;
use App\Services\Patient\PatientHmac;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PatientProjectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_read_only_projection_surfaces_return_governed_content_and_exact_metadata(): void
    {
        $fixture = $this->fixture('projection-success');
        $token = $this->token($fixture['principal']);

        $routes = [
            'today' => 'today',
            'pathway' => 'pathway',
            'pathway_events' => 'pathway/events',
            'discharge_readiness' => 'discharge-readiness',
            'rounds_summary' => 'rounds/summary',
            'care_team' => 'care-team',
        ];

        foreach ($routes as $kind => $path) {
            $response = $this->getAsPatient(
                $token,
                "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/{$path}",
            );

            $response->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertJsonPath('data.encounter_uuid', (string) $fixture['grant']->encounter_uuid)
                ->assertJsonPath('data.projection_uuid', (string) $fixture['projections'][$kind]->projection_uuid)
                ->assertJsonPath('data.kind', $kind)
                ->assertJsonPath('meta.source_freshness.status', 'current')
                ->assertJsonPath(
                    'meta.source_freshness.observed_at',
                    $fixture['projections'][$kind]->source_observed_at->toISOString(),
                )
                ->assertJsonPath('meta.policy_version', 'patient-disclosure-v1-test')
                ->assertJsonPath('meta.version', 1)
                ->assertJsonPath('meta.stale', false)
                ->assertJsonStructure([
                    'data' => [
                        'projection_uuid', 'encounter_uuid', 'kind', 'content',
                        'uncertainty', 'provenance', 'observed_at', 'generated_at', 'released_at',
                    ],
                    'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                    'links',
                ]);

            $serialized = strtolower($response->getContent());
            foreach ([
                'synthetic-test-only', 'source_system_key', 'source_encounter',
                'cursor_digest', 'access_grant_id', 'staff_id', 'provider_id',
                'raw_fhir', 'practitioner/', 'encounter/', 'patient/',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $serialized);
            }
        }

        $this->assertDatabaseCount('patient_experience.access_audit_events', 6);
        $this->assertSame(6, PatientAccessAuditEvent::query()
            ->where('principal_id', $fixture['principal']->getKey())
            ->where('event_type', 'patient.projection.disclosed')
            ->where('outcome', 'allowed')
            ->count());
    }

    public function test_discharge_readiness_projection_returns_a_governed_plain_language_summary(): void
    {
        $fixture = $this->fixture('discharge-readiness');

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/discharge-readiness",
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.kind', 'discharge_readiness')
            ->assertJsonPath('data.content.criteria.0.status', 'met')
            ->assertJsonPath('data.content.criteria.1.status', 'pending')
            ->assertJsonStructure([
                'data' => [
                    'content' => [
                        'headline',
                        'summary',
                        'estimated_range',
                        'criteria' => [['item_uuid', 'label', 'status']],
                        'medications' => [['item_uuid', 'name']],
                        'follow_up' => [['item_uuid', 'label', 'when']],
                        'contacts' => [['item_uuid', 'label', 'route']],
                    ],
                ],
            ]);

        // Discharge summary is plain-language and PHI-safe: no raw source refs,
        // no exact ETA, no unreleased result.
        $serialized = strtolower($response->getContent());
        foreach (['unreleased_result', 'exact_time', 'mrn', 'patient_ref', 'staff_note'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_pathway_projection_returns_a_structured_admission_to_discharge_journey(): void
    {
        $fixture = $this->fixture('pathway-journey');

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/pathway",
        );

        $response->assertOk()
            ->assertJsonPath('data.kind', 'pathway')
            ->assertJsonPath('data.content.current_stage', 'Monitoring and treatment')
            // Stages span admission -> discharge with labelled, non-color status.
            ->assertJsonPath('data.content.stages.0.status', 'completed')
            ->assertJsonPath('data.content.stages.2.status', 'current')
            ->assertJsonPath('data.content.stages.3.status', 'planned')
            // Milestones are structured objects carrying completed/current/planned
            // status, not opaque strings.
            ->assertJsonPath('data.content.milestones.0.status', 'completed')
            ->assertJsonPath('data.content.milestones.1.status', 'current')
            ->assertJsonPath('data.content.milestones.2.status', 'planned')
            // Goals separate patient-authored from care-team-authored content.
            ->assertJsonPath('data.content.goals.0.author_type', 'care_team')
            ->assertJsonPath('data.content.goals.1.author_type', 'patient')
            ->assertJsonStructure([
                'data' => [
                    'content' => [
                        'current_stage',
                        'stages' => [['stage_uuid', 'title', 'status', 'summary', 'can_change']],
                        'milestones' => [['milestone_uuid', 'title', 'status']],
                        'goals' => [['goal_uuid', 'author_type', 'label', 'status']],
                        'education' => [['item_uuid', 'title', 'summary']],
                    ],
                ],
            ]);

        $content = $response->json('data.content');
        $this->assertCount(4, $content['stages']);
        $this->assertCount(3, $content['milestones']);
        $this->assertCount(2, $content['goals']);
        $this->assertCount(1, $content['education']);
        // Each milestone reached the wire as an object with an opaque uuid, proving
        // the string -> structured-status enrichment end to end.
        foreach ($content['milestones'] as $milestone) {
            $this->assertIsArray($milestone);
            $this->assertArrayHasKey('milestone_uuid', $milestone);
        }
    }

    public function test_pathway_events_projection_returns_a_governed_plain_language_timeline(): void
    {
        $fixture = $this->fixture('pathway-events-timeline');

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/pathway/events",
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.kind', 'pathway_events')
            ->assertJsonPath('data.content.events.0.category', 'other')
            ->assertJsonPath('data.content.events.1.category', 'test')
            ->assertJsonPath('data.content.events.2.category', 'procedure')
            ->assertJsonPath('data.content.events.3.category', 'transport')
            ->assertJsonPath('data.content.events.0.status', 'completed')
            ->assertJsonPath('data.content.events.2.status', 'current')
            ->assertJsonPath('data.content.events.3.status', 'planned')
            ->assertJsonStructure([
                'data' => [
                    'content' => [
                        'headline',
                        'summary',
                        'events' => [['event_uuid', 'title', 'when', 'status']],
                    ],
                ],
            ]);

        $events = $response->json('data.content.events');
        $this->assertCount(4, $events);
        // Timeline uses plain-language timing only; no exact clock time leaks in.
        foreach ($events as $event) {
            $this->assertArrayHasKey('when', $event);
            $this->assertArrayNotHasKey('occurred_at', $event);
        }
    }

    public function test_rounds_summary_is_released_patient_language_not_staff_rounds_content(): void
    {
        $fixture = $this->fixture('rounds-summary');

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/rounds/summary",
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.kind', 'rounds_summary')
            ->assertJsonPath('data.content.round_window', 'Earlier today')
            ->assertJsonPath('data.content.topics.0.status', 'current')
            ->assertJsonPath('data.content.topics.1.status', 'planned')
            ->assertJsonStructure([
                'data' => [
                    'content' => [
                        'headline',
                        'summary',
                        'topics' => [['topic_uuid', 'title', 'summary', 'status']],
                        'next_steps',
                    ],
                ],
            ]);

        $serialized = strtolower($response->getContent());
        foreach (['staff_note', 'private_comment', 'assignment', 'source_system_key', 'exact_time'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_cross_principal_and_unknown_encounters_share_generic_not_found_without_idor_disclosure(): void
    {
        $owned = $this->fixture('projection-owner');
        $attacker = $this->fixture('projection-other-principal');
        $token = $this->token($attacker['principal']);

        foreach ([(string) $owned['grant']->encounter_uuid, (string) Str::uuid()] as $encounterUuid) {
            $response = $this->getAsPatient(
                $token,
                "/api/patient/v1/encounters/{$encounterUuid}/today",
            );

            $this->assertGenericNotFound($response);
            $this->assertStringNotContainsString($encounterUuid, $response->getContent());
        }

        $events = PatientAccessAuditEvent::query()
            ->where('principal_id', $attacker['principal']->getKey())
            ->where('event_type', 'patient.projection.disclosure_denied')
            ->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (PatientAccessAuditEvent $event): bool => $event->access_grant_id === null));
        $this->assertTrue($events->every(fn (PatientAccessAuditEvent $event): bool => $event->reason_code === 'projection_not_available'));
    }

    public function test_suspended_revoked_and_expired_grants_all_fail_closed_and_are_audited(): void
    {
        foreach (['suspended', 'revoked', 'expired'] as $status) {
            $fixture = $this->fixture('projection-grant-'.$status);
            $grant = $fixture['grant'];

            $attributes = ['status' => $status];
            if ($status === 'revoked') {
                $attributes += [
                    'revoked_at' => now()->subMinute(),
                    'revocation_reason' => 'Automated projection authorization test.',
                ];
            }
            if ($status === 'expired') {
                $attributes['expires_at'] = now()->subMinute();
            }
            $grant->forceFill($attributes)->save();

            $response = $this->getAsPatient(
                $this->token($fixture['principal']),
                "/api/patient/v1/encounters/{$grant->encounter_uuid}/today",
            );

            $this->assertGenericNotFound($response);
            $this->assertDatabaseHas('patient_experience.access_audit_events', [
                'principal_id' => $fixture['principal']->getKey(),
                'access_grant_id' => $grant->getKey(),
                'event_type' => 'patient.projection.disclosure_denied',
                'outcome' => 'denied',
            ]);
        }
    }

    public function test_missing_scope_and_wrong_relationship_are_indistinguishable_denials(): void
    {
        $missingScope = $this->fixture('projection-missing-scope');
        $missingScope['grant']->forceFill([
            'scopes' => ['pathway:read', 'care_team:read'],
        ])->save();
        $this->assertGenericNotFound($this->getAsPatient(
            $this->token($missingScope['principal']),
            "/api/patient/v1/encounters/{$missingScope['grant']->encounter_uuid}/today",
        ));

        $wrongRelationship = $this->fixture('projection-wrong-relationship');
        $wrongRelationship['grant']->forceFill(['relationship' => 'proxy'])->save();
        $this->assertGenericNotFound($this->getAsPatient(
            $this->token($wrongRelationship['principal']),
            "/api/patient/v1/encounters/{$wrongRelationship['grant']->encounter_uuid}/today",
        ));

        $this->assertSame(2, PatientAccessAuditEvent::query()
            ->where('event_type', 'patient.projection.disclosure_denied')
            ->where('reason_code', 'projection_not_available')
            ->count());
    }

    public function test_unknown_unreleased_and_retracted_content_share_generic_not_found(): void
    {
        $fixture = $this->fixture('projection-release-state');
        $principal = $fixture['principal'];
        $policy = $fixture['policy'];
        $token = $this->token($principal);

        $unknownGrant = $this->grant($principal, ['today:read']);
        $this->assertGenericNotFound($this->getAsPatient(
            $token,
            "/api/patient/v1/encounters/{$unknownGrant->encounter_uuid}/today",
        ));

        $draftGrant = $this->grant($principal, ['today:read']);
        $this->projection($draftGrant, $policy, 'today', 1, 'draft');
        $this->assertGenericNotFound($this->getAsPatient(
            $token,
            "/api/patient/v1/encounters/{$draftGrant->encounter_uuid}/today",
        ));

        $latest = $this->projection(
            $fixture['grant'],
            $policy,
            'today',
            2,
            'released',
            content: [
                'headline' => 'Latest released plan',
                'summary' => 'This release is subsequently retracted.',
                'notices' => ['Plans can change.'],
            ],
        );
        PatientContentAction::query()->create([
            'action_uuid' => (string) Str::uuid(),
            'target_projection_id' => $latest->getKey(),
            'release_policy_version_id' => $policy->getKey(),
            'action_type' => 'retraction',
            'reason_code' => 'entered_in_error',
            'actor_type' => 'clinical_review',
            'actor_ref' => 'opaque-review-actor',
            'effective_at' => now()->subSecond(),
        ]);
        $this->assertGenericNotFound($this->getAsPatient(
            $token,
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/today",
        ));

        $this->assertSame(3, PatientAccessAuditEvent::query()
            ->where('event_type', 'patient.projection.disclosure_denied')
            ->count());
    }

    public function test_correction_hides_the_original_and_releases_only_the_replacement(): void
    {
        $fixture = $this->fixture('projection-correction');
        $original = $fixture['projections']['pathway'];
        $replacement = $this->projection(
            $fixture['grant'],
            $fixture['policy'],
            'pathway',
            2,
            'released',
            freshness: 'current',
            supersedes: $original,
            content: [
                'headline' => 'My corrected path',
                'summary' => 'This corrected pathway reflects the latest released review.',
                'current_stage' => 'Preparing for the next step',
                'stages' => [[
                    'stage_uuid' => (string) Str::uuid(),
                    'title' => 'Preparing for the next step',
                    'status' => 'current',
                    'summary' => 'Your team is preparing the next released step.',
                    'can_change' => true,
                ]],
                'notices' => ['Timing can change as your care needs change.'],
            ],
        );
        PatientContentAction::query()->create([
            'action_uuid' => (string) Str::uuid(),
            'target_projection_id' => $original->getKey(),
            'replacement_projection_id' => $replacement->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'action_type' => 'correction',
            'reason_code' => 'released_content_corrected',
            'actor_type' => 'clinical_review',
            'actor_ref' => 'opaque-review-actor',
            'effective_at' => now()->subSecond(),
        ]);

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/pathway",
        );
        $response->assertOk()
            ->assertJsonPath('data.projection_uuid', (string) $replacement->projection_uuid)
            ->assertJsonPath('data.content.headline', 'My corrected path')
            ->assertJsonPath('data.revision_notice.kind', 'correction')
            ->assertJsonPath(
                'data.revision_notice.message',
                'Your care team updated this information. Please use the details shown here.',
            )
            ->assertJsonPath('meta.version', 2);
        foreach ([
            (string) $original->projection_uuid,
            'released_content_corrected',
            'opaque-review-actor',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_superseding_release_without_an_effective_correction_action_has_no_revision_notice(): void
    {
        $fixture = $this->fixture('projection-revision-notice-action-required');
        $original = $fixture['projections']['pathway'];
        $replacement = $this->projection(
            $fixture['grant'],
            $fixture['policy'],
            'pathway',
            2,
            'released',
            supersedes: $original,
        );

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/pathway",
        );

        $response->assertOk()
            ->assertJsonPath('data.projection_uuid', (string) $replacement->projection_uuid)
            ->assertJsonMissingPath('data.revision_notice');
    }

    public function test_stale_projection_is_disclosed_with_real_stale_metadata_and_uncertainty(): void
    {
        $fixture = $this->fixture('projection-stale');
        $stale = $this->projection(
            $fixture['grant'],
            $fixture['policy'],
            'today',
            2,
            'released',
            freshness: 'stale',
            content: [
                'headline' => 'Your plan is being refreshed',
                'summary' => 'The last released plan may be out of date.',
                'notices' => ['Ask bedside staff for the most current plan.'],
            ],
        );

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/today",
        );
        $response->assertOk()
            ->assertJsonPath('data.projection_uuid', (string) $stale->projection_uuid)
            ->assertJsonPath('meta.source_freshness.status', 'stale')
            ->assertJsonPath('meta.source_freshness.observed_at', $stale->source_observed_at->toISOString())
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('data.uncertainty.can_change', true);
    }

    public function test_policy_version_mismatch_fails_closed_and_is_audited(): void
    {
        $fixture = $this->fixture('projection-policy-mismatch');
        config(['hummingbird-patient.policy_version' => 'unapproved-policy-version']);

        $response = $this->getAsPatient(
            $this->token($fixture['principal']),
            "/api/patient/v1/encounters/{$fixture['grant']->encounter_uuid}/care-team",
        );
        $this->assertGenericNotFound($response);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $fixture['principal']->getKey(),
            'access_grant_id' => $fixture['grant']->getKey(),
            'event_type' => 'patient.projection.disclosure_denied',
            'reason_code' => 'projection_not_available',
        ]);
    }

    /** @return array{principal: PatientPrincipal, grant: PatientEncounterAccessGrant, policy: PatientReleasePolicyVersion, projections: array<string, PatientEncounterProjection>} */
    private function fixture(string $seed): array
    {
        $fixture = $this->app->make(SyntheticPatientProjectionProvisioner::class)->provision($seed);
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.policy_version' => (string) $fixture['policy']->version,
            'hummingbird-patient.features.today' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.rounds_summary' => true,
            'hummingbird-patient.features.care_team' => true,
        ]);

        return $fixture;
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

        return $principal->createToken(
            'patient-access:'.$sessionUuid,
            ['patient:access'],
        )->plainTextToken;
    }

    private function getAsPatient(string $token, string $path): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson($path);
    }

    private function assertGenericNotFound(TestResponse $response): void
    {
        $response->assertNotFound()
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertJsonStructure([
                'data',
                'error' => ['code', 'message'],
                'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                'links',
            ]);
    }

    /** @param  list<string>  $scopes */
    private function grant(PatientPrincipal $principal, array $scopes): PatientEncounterAccessGrant
    {
        return PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid(),
            'principal_id' => $principal->getKey(),
            'encounter_uuid' => (string) Str::uuid(),
            'source_encounter_ref_digest' => $this->app->make(PatientHmac::class)
                ->digest('projection-test-encounter', (string) Str::uuid()),
            'source_system_key' => 'synthetic-test-only',
            'relationship' => 'self',
            'scopes' => $scopes,
            'purpose_of_use' => 'patient_access',
            'status' => 'active',
            'valid_from' => now()->subHour(),
            'grant_reason' => 'Automated patient projection API test.',
            'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $content
     */
    private function projection(
        PatientEncounterAccessGrant $grant,
        PatientReleasePolicyVersion $policy,
        string $kind,
        int $sequence,
        string $state,
        string $freshness = 'current',
        ?PatientEncounterProjection $supersedes = null,
        ?array $content = null,
    ): PatientEncounterProjection {
        $observedAt = now()->subMinutes(10);

        return PatientEncounterProjection::query()->create([
            'projection_uuid' => (string) Str::uuid(),
            'access_grant_id' => $grant->getKey(),
            'release_policy_version_id' => $policy->getKey(),
            'supersedes_projection_id' => $supersedes?->getKey(),
            'projection_kind' => $kind,
            'projection_sequence' => $sequence,
            'content' => $content ?? [
                'headline' => 'Synthetic released content',
                'summary' => 'This is patient-safe automated test content.',
                'notices' => ['Timing and plans can change.'],
            ],
            'content_schema_version' => 'patient-'.$kind.'.v1',
            'source_version' => 'synthetic-v'.$sequence,
            'provenance' => [
                'projection_method' => 'automated_test',
                'source_class' => 'synthetic_clinical_record',
                'input_classes' => ['synthetic_care_plan'],
                'review_state' => 'automated_test_only',
                'producer_version' => 'test-v1',
                'trace_digest' => $this->app->make(PatientHmac::class)
                    ->digest('projection-test-trace', $kind.'|'.$sequence.'|'.$grant->grant_uuid),
            ],
            'source_observed_at' => $observedAt,
            'generated_at' => $observedAt->addMinute(),
            'released_at' => $state === 'released' ? now()->subMinute() : null,
            'freshness_class' => $freshness,
            'uncertainty' => [
                'level' => $freshness === 'stale' ? 'high' : 'low',
                'explanation' => 'Plans can change as your care needs change.',
                'can_change' => true,
                'reviewed_at' => $observedAt->toISOString(),
            ],
            'required_scope' => [
                'today' => 'today:read',
                'pathway' => 'pathway:read',
                'pathway_events' => 'pathway:read',
                'discharge_readiness' => 'pathway:read',
                'rounds_summary' => 'pathway:read',
                'care_team' => 'care_team:read',
            ][$kind],
            'permitted_relationships' => ['self'],
            'release_state' => $state,
        ]);
    }
}
