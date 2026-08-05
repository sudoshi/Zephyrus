<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Nightingale\Demo\NightingaleDemoCohort;
use App\Nightingale\Demo\NightingaleDemoCohortProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\TestCase;

class NightingaleDemoCohortProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Encounter $referenceEncounter;

    private PatientPrincipal $referencePrincipal;

    private string $catalogReleaseUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = Unit::query()->create([
            'name' => 'Synthetic Nightingale Demo Unit',
            'abbreviation' => 'NG-DEMO',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
        $this->seedReferenceSample();
        $this->seedExactCatalog();
    }

    public function test_preview_reconciles_exact_semantic_catalog_identity_and_returns_its_environment_uuid_without_writes(): void
    {
        $before = $this->cohortCounts();
        $result = $this->provisioner()->preview($this->unit->getKey());

        $this->assertFalse($result['committed']);
        $this->assertSame('preview', $result['action']);
        $this->assertSame(NightingaleDemoCohort::loginAliases(), $result['login_handles']);
        $this->assertSame($this->catalogReleaseUuid, $result['catalog_release_uuid']);
        $this->assertSame('inactive', $result['catalog_state']);
        $this->assertFalse($result['catalog_clinical_signoff_complete']);
        $this->assertFalse($result['credential_material_emitted']);
        $this->assertFalse($result['clinical_use_permitted']);
        $this->assertSame('ready_for_demo1_adoption', $result['demo1_reference_sample']);
        $this->assertSame($before, $this->cohortCounts());
    }

    public function test_apply_atomically_creates_five_isolated_accounts_and_six_projections_each(): void
    {
        $password = 'Synthetic-Provisioner-Test-Only-51!';
        $result = $this->provisioner()->apply($this->unit->getKey(), $password);

        $this->assertTrue($result['committed']);
        $this->assertSame('applied', $result['action']);
        $this->assertSame('adopted_into_demo1', $result['demo1_reference_sample']);
        $this->assertSame(5, PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [NightingaleDemoCohort::OWNER])
            ->count());
        $this->assertSame(4, Encounter::query()
            ->where('created_by', NightingaleDemoCohort::OWNER)
            ->count());
        $this->assertSame(1, Encounter::query()
            ->where('created_by', NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER)
            ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF)
            ->count());
        $this->assertSame(5, PatientIdentityLink::query()
            ->whereRaw("provenance->>'owner' = ?", [NightingaleDemoCohort::OWNER])
            ->count());
        $this->assertSame(5, PatientEncounterAccessGrant::query()
            ->whereRaw("metadata->>'owner' = ?", [NightingaleDemoCohort::OWNER])
            ->count());
        $this->assertSame(30, PatientEncounterProjection::query()
            ->where('source_version', NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION)
            ->count());
        $this->assertSame(1, PatientReleasePolicyVersion::query()
            ->where('version', NightingaleDemoCohort::RELEASE_POLICY_VERSION)
            ->count());
        $this->assertSame($this->referencePrincipal->getKey(), $this->principal('demo1')->getKey());
        $this->assertSame(
            (string) $this->referencePrincipal->principal_uuid,
            (string) $this->principal('demo1')->principal_uuid,
        );
        $this->assertSame($this->referenceEncounter->getKey(), Encounter::query()
            ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF)
            ->sole()
            ->getKey());
        $this->assertSame(
            NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
            Encounter::query()
                ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF)
                ->sole()
                ->created_by,
        );
        $this->assertSame(
            NightingaleDemoCohort::OWNER,
            Encounter::query()
                ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF)
                ->sole()
                ->modified_by,
        );
        $this->assertSame(
            NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT,
            $this->principal('demo1')
                ->preferences['provisioning']['reference_sample_adoption']['source_template_product'],
        );

        foreach (NightingaleDemoCohort::MEMBERS as $alias => $scenario) {
            $principal = $this->principal($alias);
            $this->assertTrue(Hash::check($password, (string) $principal->password));
            $this->assertSame(
                $this->catalogReleaseUuid,
                $principal->preferences['provisioning']['catalog_binding']['catalog_release_uuid'],
            );
            $this->assertSame($scenario['pathway_key'], $principal->preferences['provisioning']['catalog_binding']['pathway_key']);
            $this->assertSame($scenario['ms_drg'], $principal->preferences['provisioning']['catalog_binding']['ms_drg']);
            $this->assertFalse($principal->preferences['provisioning']['clinical_use_permitted']);
            $grant = PatientEncounterAccessGrant::query()
                ->where('principal_id', $principal->getKey())
                ->sole();
            $this->assertSame(6, PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->where('release_state', 'released')
                ->count());
            $pathway = PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->where('projection_kind', 'pathway')
                ->sole();
            $this->assertCount($scenario['stage_count'], $pathway->content['stages']);
            $this->assertCount($scenario['milestone_count'], $pathway->content['milestones']);
            $this->assertContains(
                'DEMO — NOT FOR CLINICAL USE. This is synthetic information for a product demonstration. It is not a medical record, care instruction, diagnosis, order, or promise. For urgent help, use the bedside call button or speak with staff.',
                $pathway->content['notices'],
            );
        }

        $this->assertSame(5, PatientEncounterAccessGrant::query()
            ->whereRaw("metadata->>'owner' = ?", [NightingaleDemoCohort::OWNER])
            ->distinct('encounter_uuid')
            ->count('encounter_uuid'));
        $this->assertSame(0, PatientSession::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_replay_rotates_password_revokes_sessions_and_creates_no_duplicates(): void
    {
        $firstPassword = 'Synthetic-First-Provisioner-Test-61!';
        $secondPassword = 'Synthetic-Second-Provisioner-Test-72!';
        $service = $this->provisioner();
        $service->apply($this->unit->getKey(), $firstPassword);

        config([
            'nightingale.enabled' => true,
            'nightingale.features.token_exchange' => true,
            'nightingale.features.profile' => true,
        ]);
        $response = $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1',
            'password' => $firstPassword,
        ])->assertOk();
        $accessToken = (string) $response->json('data.access_token');
        $sessionUuid = (string) $response->json('data.session_uuid');

        $firstCounts = $this->cohortCounts();
        $service->apply($this->unit->getKey(), $secondPassword);
        $afterCounts = $this->cohortCounts();
        $this->assertSame($firstCounts['principals'], $afterCounts['principals']);
        $this->assertSame($firstCounts['identity_links'], $afterCounts['identity_links']);
        $this->assertSame($firstCounts['grants'], $afterCounts['grants']);
        $this->assertSame($firstCounts['encounters'], $afterCounts['encounters']);
        $this->assertSame($firstCounts['projections'], $afterCounts['projections']);
        $this->assertSame($firstCounts['sessions'], $afterCounts['sessions']);
        $this->assertSame(0, $afterCounts['tokens']);
        $principal = $this->principal('demo1');
        $this->assertFalse(Hash::check($firstPassword, (string) $principal->password));
        $this->assertTrue(Hash::check($secondPassword, (string) $principal->password));
        $session = PatientSession::query()->where('session_uuid', $sessionUuid)->sole();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('nightingale_demo_credential_rotation', $session->revocation_reason);
        $this->assertSame(0, PersonalAccessToken::query()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($accessToken)
            ->getJson('/api/patient/v1/me')
            ->assertUnauthorized();
    }

    public function test_verify_and_disclosure_are_scoped_to_exact_demo_policy(): void
    {
        $this->provisioner()->apply(
            $this->unit->getKey(),
            'Synthetic-Disclosure-Test-Only-83!',
        );
        $verification = $this->provisioner()->verify();
        $this->assertSame('verified', $verification['action']);
        $this->assertSame(NightingaleDemoCohort::loginAliases(), array_keys($verification['accounts']));

        config([
            'nightingale.enabled' => true,
            'nightingale.features.token_exchange' => true,
            'nightingale.features.pathway' => true,
        ]);
        $token = $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo4',
            'password' => 'Synthetic-Disclosure-Test-Only-83!',
        ])->assertOk()->json('data.access_token');
        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $this->principal('demo4')->getKey())
            ->sole();

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $token)
            ->getJson('/api/patient/v1/encounters/'.$grant->encounter_uuid.'/pathway')
            ->assertOk()
            ->assertJsonPath('data.kind', 'pathway')
            ->assertJsonPath(
                'data.content.summary',
                fn (string $summary): bool => str_contains($summary, 'MS-DRG 399')
                    && str_contains($summary, 'not institutionally approved'),
            )
            ->assertJsonCount(5, 'data.content.stages')
            ->assertJsonCount(36, 'data.content.milestones');
    }

    public function test_verify_fails_closed_when_an_owned_principal_has_an_extra_identity(): void
    {
        $service = $this->provisioner();
        $service->apply(
            $this->unit->getKey(),
            'Synthetic-Verify-Identity-Guard-85!',
        );
        PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $this->principal('demo2')->getKey(),
            'source_system_key' => 'unexpected-secondary-source',
            'encrypted_source_subject' => 'unexpected-secondary-subject',
            'encryption_key_version' => 'test-only-v1',
            'source_subject_digest' => hash('sha256', 'unexpected-secondary-subject'),
            'linkage_method' => 'manual_review',
            'status' => 'pending',
            'provenance' => [
                'owner' => 'unexpected-secondary-source',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nightingale_demo_demo2_authorization_cardinality_invalid');
        $service->verify();
    }

    public function test_verify_rejects_an_unexpected_append_only_superseding_projection(): void
    {
        $service = $this->provisioner();
        $service->apply(
            $this->unit->getKey(),
            'Synthetic-Verify-Projection-Guard-96!',
        );
        $projection = PatientEncounterProjection::query()
            ->where('access_grant_id', PatientEncounterAccessGrant::query()
                ->where('principal_id', $this->principal('demo1')->getKey())
                ->value('access_grant_id'))
            ->where('projection_kind', 'today')
            ->sole();
        $supersedingProjection = $projection->replicate();
        $supersedingProjection->projection_uuid = (string) Str::uuid7();
        $supersedingProjection->projection_sequence = 2;
        $supersedingProjection->supersedes_projection_id = $projection->getKey();
        $supersedingProjection->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nightingale_demo_demo1_projection_cardinality_invalid');
        $service->verify();
    }

    public function test_all_five_accounts_disclose_six_own_projections_and_deny_twenty_cross_account_substitutions(): void
    {
        $password = 'Synthetic-Five-Account-Isolation-Test-84!';
        $this->provisioner()->apply($this->unit->getKey(), $password);
        config([
            'nightingale.enabled' => true,
            'nightingale.features.token_exchange' => true,
            'nightingale.features.encounters' => true,
            'nightingale.features.today' => true,
            'nightingale.features.pathway' => true,
            'nightingale.features.rounds_summary' => true,
            'nightingale.features.care_team' => true,
        ]);

        $accounts = [];
        foreach (NightingaleDemoCohort::loginAliases() as $alias) {
            $token = (string) $this->postJson('/api/patient/v1/auth/token', [
                'email' => $alias,
                'password' => $password,
            ])->assertOk()->json('data.access_token');

            $this->app['auth']->forgetGuards();
            $encounters = $this->withToken($token)
                ->getJson('/api/patient/v1/encounters')
                ->assertOk()
                ->assertJsonCount(1, 'data.encounters');
            $accounts[$alias] = [
                'token' => $token,
                'encounter_uuid' => (string) $encounters->json('data.encounters.0.encounter_uuid'),
            ];
        }

        $projectionRoutes = [
            'today' => 'today',
            'pathway' => 'pathway',
            'pathway/events' => 'pathway_events',
            'discharge-readiness' => 'discharge_readiness',
            'rounds/summary' => 'rounds_summary',
            'care-team' => 'care_team',
        ];
        foreach ($accounts as $account) {
            foreach ($projectionRoutes as $suffix => $kind) {
                $this->app['auth']->forgetGuards();
                $this->withToken($account['token'])
                    ->getJson("/api/patient/v1/encounters/{$account['encounter_uuid']}/{$suffix}")
                    ->assertOk()
                    ->assertJsonPath('data.kind', $kind)
                    ->assertJsonPath(
                        'data.content.notices.0',
                        'DEMO — NOT FOR CLINICAL USE. This is synthetic information for a product demonstration. It is not a medical record, care instruction, diagnosis, order, or promise. For urgent help, use the bedside call button or speak with staff.',
                    );
            }
        }

        $crossAccountAttempts = 0;
        foreach ($accounts as $attackerAlias => $attacker) {
            foreach ($accounts as $ownerAlias => $owner) {
                if ($attackerAlias === $ownerAlias) {
                    continue;
                }

                $crossAccountAttempts++;
                $this->app['auth']->forgetGuards();
                $response = $this->withToken($attacker['token'])
                    ->getJson("/api/patient/v1/encounters/{$owner['encounter_uuid']}/pathway");
                $response->assertNotFound()
                    ->assertJsonPath('data', null)
                    ->assertJsonPath('error.code', 'not_found')
                    ->assertJsonPath('error.message', 'The requested resource was not found.');
                $this->assertStringNotContainsString($owner['encounter_uuid'], $response->getContent());
                $this->assertStringNotContainsString($ownerAlias, $response->getContent());
            }
        }

        $this->assertSame(20, $crossAccountAttempts);
        $this->assertSame(20, PatientAccessAuditEvent::query()
            ->where('event_type', 'patient.projection.disclosure_denied')
            ->where('reason_code', 'projection_not_available')
            ->whereNull('access_grant_id')
            ->count());
    }

    public function test_catalog_drift_fails_before_any_cohort_write(): void
    {
        $versionId = DB::table('care_pathways.definitions as definitions')
            ->join('care_pathways.versions as versions', 'versions.pathway_definition_id', '=', 'definitions.pathway_definition_id')
            ->where('definitions.pathway_key', NightingaleDemoCohort::MEMBERS['demo1']['pathway_key'])
            ->value('versions.pathway_version_id');
        DB::table('care_pathways.stage_definitions')->insert([
            'stage_uuid' => (string) Str::uuid7(),
            'pathway_version_id' => $versionId,
            'stable_key' => 'unexpected_extra_stage',
            'display_order' => 99,
            'approved_label' => 'Unexpected extra draft stage',
            'expected_range' => '{}',
            'review_state' => 'draft',
            'content_digest' => hash('sha256', 'unexpected-extra-stage'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nightingale_demo_catalog_demo1_binding_changed');
        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Catalog-Guard-Test-94!',
            );
        } finally {
            $this->assertSame([
                'principals' => 0,
                'identity_links' => 0,
                'grants' => 0,
                'encounters' => 0,
                'projections' => 0,
                'sessions' => 0,
                'tokens' => 0,
            ], $this->cohortCounts());
        }
    }

    public function test_catalog_release_state_drift_fails_before_any_cohort_write(): void
    {
        DB::table('care_pathways.catalog_releases')
            ->where('catalog_release_uuid', $this->catalogReleaseUuid)
            ->update(['state' => 'under_review']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nightingale_demo_catalog_release_state_changed');
        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Catalog-State-Guard-Test-83!',
            );
        } finally {
            $this->assertSame([
                'principals' => 0,
                'identity_links' => 0,
                'grants' => 0,
                'encounters' => 0,
                'projections' => 0,
                'sessions' => 0,
                'tokens' => 0,
            ], $this->cohortCounts());
        }
    }

    public function test_catalog_evidence_fingerprint_mismatch_fails_before_any_cohort_write(): void
    {
        DB::statement('TRUNCATE TABLE care_pathways.definitions, care_pathways.catalog_releases CASCADE');
        $this->seedExactCatalog(hash('sha256', 'intentionally-wrong-source-fingerprint'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nightingale_demo_catalog_release_not_exact');
        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Catalog-Fingerprint-Guard-61!',
            );
        } finally {
            $this->assertSame([
                'principals' => 0,
                'identity_links' => 0,
                'grants' => 0,
                'encounters' => 0,
                'projections' => 0,
                'sessions' => 0,
                'tokens' => 0,
            ], $this->cohortCounts());
        }
    }

    public function test_foreign_owned_collision_rolls_back_all_five_members(): void
    {
        Encounter::query()->create([
            'patient_ref' => NightingaleDemoCohort::MEMBERS['demo5']['patient_ref'],
            'unit_id' => $this->unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_by' => 'foreign-test-source',
            'is_deleted' => false,
        ]);

        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Atomicity-Guard-Test-15!',
            );
            $this->fail('Foreign member five unexpectedly permitted cohort provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertSame('nightingale_demo_demo5_encounter_foreign_owned', $exception->getMessage());
        }

        $this->assertSame(0, PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [NightingaleDemoCohort::OWNER])
            ->count());
        $this->assertSame(2, Encounter::query()->count());
        $this->assertDatabaseHas('prod.encounters', [
            'patient_ref' => NightingaleDemoCohort::MEMBERS['demo5']['patient_ref'],
            'created_by' => 'foreign-test-source',
        ]);
    }

    public function test_reference_principal_with_existing_identity_or_contact_data_fails_before_cohort_write(): void
    {
        $this->referencePrincipal->forceFill([
            'email' => 'preexisting-reference@example.invalid',
        ])->save();
        PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $this->referencePrincipal->getKey(),
            'source_system_key' => 'preexisting-reference-source',
            'encrypted_source_subject' => 'preexisting-reference-subject',
            'encryption_key_version' => 'test-only-v1',
            'source_subject_digest' => hash('sha256', 'preexisting-reference-subject'),
            'linkage_method' => 'manual_review',
            'status' => 'pending',
            'provenance' => [
                'owner' => 'preexisting-reference-source',
            ],
        ]);

        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Reference-Principal-Guard-48!',
            );
            $this->fail('A non-pristine reference principal was unexpectedly adopted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'nightingale_demo_reference_sample_principal_changed',
                $exception->getMessage(),
            );
        }

        $this->assertSame([
            'principals' => 0,
            'identity_links' => 0,
            'grants' => 0,
            'encounters' => 0,
            'projections' => 0,
            'sessions' => 0,
            'tokens' => 0,
        ], $this->cohortCounts());
        $this->assertSame('preexisting-reference@example.invalid', $this->referencePrincipal->fresh()->email);
        $this->assertSame(1, $this->referencePrincipal->identityLinks()->count());
    }

    public function test_reference_encounter_with_changed_unit_or_lifecycle_fails_before_cohort_write(): void
    {
        $otherUnit = Unit::query()->create([
            'name' => 'Synthetic Foreign Unit',
            'abbreviation' => 'NG-OTHER',
            'type' => 'med_surg',
            'staffed_bed_count' => 4,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
        $this->referenceEncounter->forceFill([
            'unit_id' => $otherUnit->getKey(),
            'status' => 'discharged',
            'discharged_at' => now(),
        ])->save();

        try {
            $this->provisioner()->apply(
                $this->unit->getKey(),
                'Synthetic-Reference-Encounter-Guard-59!',
            );
            $this->fail('A changed reference encounter was unexpectedly adopted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'nightingale_demo_reference_sample_encounter_changed',
                $exception->getMessage(),
            );
        }

        $this->assertSame([
            'principals' => 0,
            'identity_links' => 0,
            'grants' => 0,
            'encounters' => 0,
            'projections' => 0,
            'sessions' => 0,
            'tokens' => 0,
        ], $this->cohortCounts());
        $this->assertSame($otherUnit->getKey(), $this->referenceEncounter->fresh()->unit_id);
        $this->assertSame('discharged', $this->referenceEncounter->fresh()->status);
    }

    public function test_changed_owned_encounter_fails_closed_and_rolls_back_earlier_password_rotations(): void
    {
        $service = $this->provisioner();
        $firstPassword = 'Synthetic-Owned-Encounter-First-60!';
        $secondPassword = 'Synthetic-Owned-Encounter-Second-71!';
        $service->apply($this->unit->getKey(), $firstPassword);
        Encounter::query()
            ->where('patient_ref', NightingaleDemoCohort::MEMBERS['demo4']['patient_ref'])
            ->sole()
            ->forceFill([
                'status' => 'discharged',
                'discharged_at' => now(),
            ])->save();
        $before = $this->cohortCounts();

        try {
            $service->apply($this->unit->getKey(), $secondPassword);
            $this->fail('A changed command-owned encounter was unexpectedly converged.');
        } catch (RuntimeException $exception) {
            $this->assertSame('nightingale_demo_demo4_encounter_changed', $exception->getMessage());
        }

        $this->assertSame($before, $this->cohortCounts());
        foreach (NightingaleDemoCohort::loginAliases() as $alias) {
            $principal = $this->principal($alias)->fresh();
            $this->assertTrue(Hash::check($firstPassword, (string) $principal->password));
            $this->assertFalse(Hash::check($secondPassword, (string) $principal->password));
        }
        $this->assertSame(
            'discharged',
            Encounter::query()
                ->where('patient_ref', NightingaleDemoCohort::MEMBERS['demo4']['patient_ref'])
                ->sole()
                ->status,
        );
    }

    public function test_suspend_removes_credentials_and_effective_access_then_reapply_recovers(): void
    {
        $service = $this->provisioner();
        $service->apply($this->unit->getKey(), 'Synthetic-Suspend-Test-Only-26!');
        $result = $service->suspend();
        $this->assertSame(5, $result['accounts_suspended']);
        $this->assertSame(5, PatientPrincipal::query()->where('status', 'suspended')->count());
        $this->assertSame(5, PatientEncounterAccessGrant::query()->where('status', 'suspended')->count());
        $this->assertSame(0, PatientPrincipal::query()->whereNotNull('password')->count());

        config([
            'nightingale.enabled' => true,
            'nightingale.features.token_exchange' => true,
        ]);
        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1',
            'password' => 'Synthetic-Suspend-Test-Only-26!',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');

        $service->apply($this->unit->getKey(), 'Synthetic-Reapply-Test-Only-37!');
        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1',
            'password' => 'Synthetic-Reapply-Test-Only-37!',
        ])->assertOk();
        $this->assertSame(30, PatientEncounterProjection::query()
            ->where('source_version', NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION)
            ->count());
    }

    private function provisioner(): NightingaleDemoCohortProvisioner
    {
        return $this->app->make(NightingaleDemoCohortProvisioner::class);
    }

    private function principal(string $alias): PatientPrincipal
    {
        return PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,demo_username}' = ?", [$alias])
            ->sole();
    }

    private function seedReferenceSample(): void
    {
        $this->referenceEncounter = Encounter::query()->create([
            'patient_ref' => NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF,
            'unit_id' => $this->unit->getKey(),
            'bed_id' => null,
            'admitted_at' => now()->subDays(2),
            'expected_discharge_date' => now()->addDays(2)->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_by' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
            'modified_by' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
            'is_deleted' => false,
        ]);
        $this->referencePrincipal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => NightingaleDemoCohort::REFERENCE_SAMPLE_DISPLAY_NAME,
            'email' => null,
            'phone_e164' => null,
            'password' => null,
            'status' => 'pending',
            'is_active' => false,
            'preferences' => [
                'synthetic' => true,
                'product' => NightingaleDemoCohort::PRODUCT,
                'provisioning' => [
                    'owner' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
                    'mode' => NightingaleDemoCohort::REFERENCE_SAMPLE_MODE,
                    'source_template_product' => NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT,
                    'source_template_owner' => NightingaleDemoCohort::REFERENCE_SOURCE_OWNER,
                ],
            ],
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    /** @return array<string, int> */
    private function cohortCounts(): array
    {
        $principalIds = PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [NightingaleDemoCohort::OWNER])
            ->pluck('principal_id');
        $grantIds = PatientEncounterAccessGrant::query()
            ->whereIn('principal_id', $principalIds)
            ->pluck('access_grant_id');

        return [
            'principals' => $principalIds->count(),
            'identity_links' => PatientIdentityLink::query()->whereIn('principal_id', $principalIds)->count(),
            'grants' => $grantIds->count(),
            'encounters' => Encounter::query()->where('created_by', NightingaleDemoCohort::OWNER)->count(),
            'projections' => PatientEncounterProjection::query()->whereIn('access_grant_id', $grantIds)->count(),
            'sessions' => PatientSession::query()->whereIn('principal_id', $principalIds)->count(),
            'tokens' => PersonalAccessToken::query()
                ->where('tokenable_type', PatientPrincipal::class)
                ->whereIn('tokenable_id', $principalIds)
                ->count(),
        ];
    }

    private function seedExactCatalog(?string $sourceCsvSha256 = null): void
    {
        $this->catalogReleaseUuid = (string) Str::uuid7();
        $releaseId = DB::table('care_pathways.catalog_releases')->insertGetId([
            'catalog_release_uuid' => $this->catalogReleaseUuid,
            'dataset_key' => NightingaleDemoCohort::CATALOG_DATASET_KEY,
            'source_csv_sha256' => $sourceCsvSha256 ?? NightingaleDemoCohort::CATALOG_SOURCE_CSV_SHA256,
            'verification_workbook_sha256' => NightingaleDemoCohort::CATALOG_VERIFICATION_WORKBOOK_SHA256,
            'declared_baseline_sha256' => NightingaleDemoCohort::CATALOG_DECLARED_BASELINE_SHA256,
            'grouper_version' => NightingaleDemoCohort::CATALOG_GROUPER_VERSION,
            'grouper_effective_start' => '2026-04-01',
            'grouper_effective_end' => '2026-09-30',
            'pathway_count' => NightingaleDemoCohort::CATALOG_PATHWAY_COUNT,
            'pathway_drg_association_count' => NightingaleDemoCohort::CATALOG_PATHWAY_DRG_ASSOCIATION_COUNT,
            'unique_drg_code_count' => NightingaleDemoCohort::CATALOG_UNIQUE_DRG_CODE_COUNT,
            'claim_count' => NightingaleDemoCohort::CATALOG_CLAIM_COUNT,
            'source_count' => NightingaleDemoCohort::CATALOG_SOURCE_COUNT,
            'change_count' => NightingaleDemoCohort::CATALOG_CHANGE_COUNT,
            'evidence_verified_count' => NightingaleDemoCohort::CATALOG_EVIDENCE_VERIFIED_COUNT,
            'evidence_limitations_count' => NightingaleDemoCohort::CATALOG_EVIDENCE_LIMITATIONS_COUNT,
            'signoff_queue_count' => NightingaleDemoCohort::CATALOG_SIGNOFF_QUEUE_COUNT,
            'specialist_review_count' => NightingaleDemoCohort::CATALOG_SPECIALIST_REVIEW_COUNT,
            'redesign_count' => NightingaleDemoCohort::CATALOG_REDESIGN_COUNT,
            'clinical_signoff_count' => 0,
            'volume_control_total' => NightingaleDemoCohort::CATALOG_VOLUME_CONTROL_TOTAL,
            'coverage_control_percent' => NightingaleDemoCohort::CATALOG_COVERAGE_CONTROL_PERCENT,
            'state' => 'inactive',
            'clinical_signoff_complete' => false,
            'source_controls' => '{}',
            'adopted_by' => 'synthetic-provisioner-test',
            'adopted_at' => now(),
        ], 'catalog_release_id');

        foreach (NightingaleDemoCohort::MEMBERS as $index => $scenario) {
            $ordinal = ((int) substr($index, -1));
            $definitionId = DB::table('care_pathways.definitions')->insertGetId([
                'pathway_uuid' => (string) Str::uuid7(),
                'pathway_key' => $scenario['pathway_key'],
                'canonical_name' => 'Synthetic canonical '.$scenario['ms_drg'],
                'mdc_label' => 'Synthetic',
                'care_type' => 'Medical',
                'source_service_line' => 'Synthetic demo',
                'lifecycle_state' => 'candidate',
            ], 'pathway_definition_id');
            $versionId = DB::table('care_pathways.versions')->insertGetId([
                'pathway_version_uuid' => (string) Str::uuid7(),
                'pathway_definition_id' => $definitionId,
                'catalog_release_id' => $releaseId,
                'semantic_version' => '43.1-test-'.$ordinal,
                'source_rank' => $ordinal,
                'evidence_status' => 'Evidence verified — automated independent review complete',
                'verification_confidence' => 'High',
                'source_specificity' => 'High',
                'unresolved_flags' => '[]',
                'release_disposition' => 'Ready for institutional clinician signoff',
                'clinical_signoff_status' => 'Not clinically approved — institutional SME signoff required',
                'institutional_approval_status' => 'not_reviewed',
                'activation_status' => 'inactive',
                'source_digest' => hash('sha256', 'source-'.$index),
                'content_digest' => hash('sha256', 'content-'.$index),
                'raw_snapshot' => '{}',
            ], 'pathway_version_id');
            $entryId = DB::table('care_pathways.drg_codebook_entries')->insertGetId([
                'entry_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'ms_drg' => $scenario['ms_drg'],
                'title' => $scenario['drg_title'],
                'mdc' => '00',
                'type_code' => 'M',
                'type_label' => 'Synthetic test',
                'entry_digest' => hash('sha256', 'drg-'.$index),
            ], 'drg_codebook_entry_id');
            DB::table('care_pathways.drg_mappings')->insert([
                'pathway_version_id' => $versionId,
                'drg_codebook_entry_id' => $entryId,
                'mapping_role' => 'candidate',
            ]);

            for ($stage = 1; $stage <= $scenario['stage_count']; $stage++) {
                DB::table('care_pathways.stage_definitions')->insert([
                    'stage_uuid' => (string) Str::uuid7(),
                    'pathway_version_id' => $versionId,
                    'stable_key' => 'demo_stage_'.$stage,
                    'display_order' => $stage,
                    'approved_label' => 'Unreleased synthetic test stage '.$stage,
                    'expected_range' => '{}',
                    'review_state' => 'draft',
                    'content_digest' => hash('sha256', $index.'-stage-'.$stage),
                ]);
            }
            for ($milestone = 1; $milestone <= $scenario['milestone_count']; $milestone++) {
                DB::table('care_pathways.milestone_definitions')->insert([
                    'milestone_uuid' => (string) Str::uuid7(),
                    'pathway_version_id' => $versionId,
                    'stable_key' => 'demo_milestone_'.$milestone,
                    'title' => 'Unreleased synthetic test milestone '.$milestone,
                    'phase' => 'synthetic',
                    'sequence' => $milestone,
                    'predecessor_keys' => '[]',
                    'expected_range' => '{}',
                    'review_state' => 'draft',
                ]);
            }
        }
    }
}
