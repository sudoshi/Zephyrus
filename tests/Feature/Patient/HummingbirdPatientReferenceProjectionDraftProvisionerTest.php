<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use App\Services\Patient\Demo\HummingbirdPatientReferenceIdentityProvisioner;
use App\Services\Patient\Demo\HummingbirdPatientReferenceProjectionDraftProvisioner;
use App\Services\Patient\Projection\PatientProjectionDisclosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HummingbirdPatientReferenceProjectionDraftProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hummingbird-patient.hmac_secret' => str_repeat('reference-projection-test-hmac-', 2),
            'hummingbird-patient.reference_provisioning.enabled' => true,
            'hummingbird-patient.reference_provisioning.encryption_key_version' => 'test-app-key-v1',
            'hummingbird-patient.reference_provisioning.challenge_ttl_minutes' => 10,
        ]);
    }

    public function test_command_is_dry_run_first_and_requires_exact_draft_only_confirmation(): void
    {
        $encounter = $this->foundation();
        [$previewExit, $previewOutput] = $this->callCommand([
            '--encounter-id' => $encounter->getKey(),
            '--json' => true,
        ]);

        $this->assertSame(0, $previewExit);
        $preview = $this->decodeJsonOutput($previewOutput);
        $this->assertFalse($preview['committed']);
        $this->assertSame('create_six_owned_reference_drafts', $preview['action']);
        $this->assertSame('pending', $preview['principal']['status']);
        $this->assertFalse($preview['principal']['is_active']);
        $this->assertSame('pending', $preview['access_grant']['status']);
        $this->assertSame('planned_draft', $preview['policy']['status']);
        $this->assertSame(0, $preview['projections']['count']);
        $this->assertFalse($preview['projections']['patient_visible']);
        $this->assertFalse($preview['projections']['content_emitted']);
        $this->assertFalse($preview['projections']['identifiers_emitted']);
        $this->assertDatabaseCount('patient_experience.release_policy_versions', 0);
        $this->assertDatabaseCount('patient_experience.source_projection_cursors', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);

        [$refusedExit, $refusedOutput] = $this->callCommand([
            '--encounter-id' => $encounter->getKey(),
            '--commit' => true,
            '--confirm-draft-only' => 'incorrect',
            '--json' => true,
        ]);
        $this->assertSame(2, $refusedExit);
        $this->assertStringContainsString(
            HummingbirdPatientReferenceProjectionDraftProvisioner::CONFIRMATION,
            $refusedOutput,
        );
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    public function test_commit_creates_six_non_visible_drafts_and_exact_replay_creates_nothing_else(): void
    {
        $encounter = $this->foundation();
        $arguments = [
            '--encounter-id' => $encounter->getKey(),
            '--commit' => true,
            '--confirm-draft-only' => HummingbirdPatientReferenceProjectionDraftProvisioner::CONFIRMATION,
            '--json' => true,
        ];

        [$firstExit, $firstOutput] = $this->callCommand($arguments);
        $this->assertSame(0, $firstExit);
        $first = $this->decodeJsonOutput($firstOutput);
        $this->assertTrue($first['committed']);
        $this->assertSame('created_six_owned_reference_drafts', $first['action']);
        $this->assertSame(6, $first['projections']['count']);
        $this->assertSame(6, $first['projections']['created']);
        $this->assertSame(0, $first['projections']['replayed']);
        $this->assertSame('draft', $first['projections']['release_state']);
        $this->assertFalse($first['projections']['patient_visible']);
        $this->assertFalse($first['projections']['content_emitted']);
        $this->assertFalse($first['projections']['identifiers_emitted']);
        $this->assertStringNotContainsString('Your plan for today', $firstOutput);
        $this->assertStringNotContainsString('Synthetic demonstration content', $firstOutput);

        $this->assertDatabaseCount('patient_experience.release_policy_versions', 1);
        $this->assertDatabaseCount('patient_experience.source_projection_cursors', 6);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 6);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 0);
        $this->assertDatabaseCount('patient_experience.sessions', 0);

        $policy = PatientReleasePolicyVersion::query()->sole();
        $this->assertSame(HummingbirdPatientReferenceProjectionDraftProvisioner::POLICY_VERSION, $policy->version);
        $this->assertSame('draft', $policy->status);
        $this->assertNull($policy->approved_by_actor_ref);
        $this->assertNull($policy->approved_at);
        $this->assertNull($policy->effective_from);
        $this->assertFalse($policy->rules['patient_visible']);
        $this->assertFalse($policy->rules['clinical_use']);

        $expectedKinds = [
            'care_team',
            'discharge_readiness',
            'pathway',
            'pathway_events',
            'rounds_summary',
            'today',
        ];
        $projections = PatientEncounterProjection::query()
            ->orderBy('projection_kind')
            ->get();
        $this->assertSame($expectedKinds, $projections->pluck('projection_kind')->all());
        foreach ($projections as $projection) {
            $this->assertSame('draft', $projection->release_state);
            $this->assertNull($projection->released_at);
            $this->assertSame(
                'draft_synthetic_not_approved',
                $projection->provenance['review_state'],
            );
            $this->assertContains(
                'Synthetic demonstration content for verification only. It is not a medical record or care instruction.',
                $projection->content['notices'],
            );
            $this->assertSame(1, $projection->projection_sequence);
        }

        foreach (PatientProjectionCursor::all() as $cursor) {
            $this->assertSame('projected', $cursor->status);
            $this->assertTrue($cursor->metadata['synthetic']);
            $this->assertTrue($cursor->metadata['draft_only']);
        }

        $principal = PatientPrincipal::query()->sole();
        $grant = PatientEncounterAccessGrant::query()->sole();
        $this->assertStringNotContainsString((string) $principal->principal_uuid, $firstOutput);
        $this->assertStringNotContainsString((string) $grant->grant_uuid, $firstOutput);
        $this->assertStringNotContainsString((string) $grant->encounter_uuid, $firstOutput);
        $this->assertSame('pending', $principal->status);
        $this->assertFalse($principal->is_active);
        $this->assertSame('pending', $grant->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(
            0,
            PatientEncounterProjection::query()->where('release_state', 'released')->count(),
        );

        [$secondExit, $secondOutput] = $this->callCommand($arguments);
        $this->assertSame(0, $secondExit, $secondOutput);
        $second = $this->decodeJsonOutput($secondOutput);
        $this->assertSame('replayed_six_owned_reference_drafts', $second['action']);
        $this->assertSame(0, $second['projections']['created']);
        $this->assertSame(6, $second['projections']['replayed']);
        $this->assertDatabaseCount('patient_experience.release_policy_versions', 1);
        $this->assertDatabaseCount('patient_experience.source_projection_cursors', 6);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 6);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 0);

        // Even after simulating an effective identity/grant and an unrelated
        // active disclosure policy, the disclosure boundary cannot select
        // these rows because they remain draft under their draft policy.
        $principal->forceFill(['status' => 'active', 'is_active' => true])->save();
        $grant->forceFill(['status' => 'active', 'valid_from' => now()->subMinute()])->save();
        $activePolicy = PatientReleasePolicyVersion::query()->create([
            'policy_uuid' => (string) Str::uuid(),
            'version' => 'unrelated-active-policy-v1',
            'status' => 'active',
            'disclosure_matrix_version' => 'patient-disclosure-matrix.v1',
            'content_contract_version' => 'patient-projection.v1',
            'rules' => ['test' => true],
            'approved_by_actor_ref' => 'independent-test-reviewer',
            'approved_at' => now()->subMinute(),
            'effective_from' => now()->subMinute(),
        ]);
        config(['hummingbird-patient.policy_version' => $activePolicy->version]);
        $disclosure = $this->app->make(PatientProjectionDisclosureService::class)->disclose(
            Request::create(
                "/api/patient/v1/encounters/{$grant->encounter_uuid}/today",
                'GET',
            ),
            $principal,
            (string) $grant->encounter_uuid,
            'today',
        );
        $this->assertNull($disclosure);
        $this->assertSame(
            0,
            PatientEncounterProjection::query()->where('release_state', 'released')->count(),
        );
    }

    public function test_provisioner_fails_closed_if_reference_identity_is_active(): void
    {
        $encounter = $this->foundation();
        $principal = PatientPrincipal::query()->sole();
        $principal->forceFill(['status' => 'active', 'is_active' => true])->save();

        try {
            $this->app->make(HummingbirdPatientReferenceProjectionDraftProvisioner::class)
                ->provision(
                    HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
                    (int) $encounter->getKey(),
                );
            $this->fail('Expected active reference identity to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'reference_patient_projection_principal_not_pending',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('patient_experience.release_policy_versions', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    public function test_provisioner_fails_closed_if_reference_principal_has_a_session_or_token(): void
    {
        $encounter = $this->foundation();
        $principal = PatientPrincipal::query()->sole();
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
        $principal->createToken('patient-access:'.$sessionUuid, ['patient:access']);

        try {
            $this->app->make(HummingbirdPatientReferenceProjectionDraftProvisioner::class)
                ->provision(
                    HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
                    (int) $encounter->getKey(),
                );
            $this->fail('Expected a reference principal with access artifacts to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'reference_patient_projection_principal_has_access',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('patient_experience.release_policy_versions', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    public function test_provisioner_fails_closed_if_a_publication_outbox_fact_already_exists(): void
    {
        $encounter = $this->foundation();
        $projectionUuid = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://zephyrus.acumenus.net/reference-patient-draft/projection/today',
        )->toString();
        PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'aggregate_type' => 'patient_encounter_projection',
            'aggregate_uuid' => $projectionUuid,
            'event_type' => 'patient.projection.released',
            'destination' => 'projection',
            'idempotency_key_digest' => hash('sha256', 'unexpected-reference-publication'),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);

        try {
            $this->app->make(HummingbirdPatientReferenceProjectionDraftProvisioner::class)
                ->provision(
                    HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
                    (int) $encounter->getKey(),
                );
            $this->fail('Expected an existing publication fact to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'reference_patient_projection_publication_outbox_exists',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('patient_experience.release_policy_versions', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    public function test_local_runtime_refuses_a_remote_database_before_any_projection_query_or_write(): void
    {
        $this->foundation();
        $originalEnvironment = $this->app['env'];
        $originalHost = config('database.connections.pgsql.host');
        $this->app['env'] = 'local';
        config(['database.connections.pgsql.host' => 'pgsql.acumenus.net']);

        try {
            $this->app->make(HummingbirdPatientReferenceProjectionDraftProvisioner::class)
                ->preview();
            $this->fail('Expected local-to-remote reference draft provisioning to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'reference_patient_projection_drafts_refuse_remote_database_from_local_runtime',
                $exception->getMessage(),
            );
        } finally {
            $this->app['env'] = $originalEnvironment;
            config(['database.connections.pgsql.host' => $originalHost]);
        }

        $this->assertDatabaseCount('patient_experience.release_policy_versions', 0);
        $this->assertDatabaseCount('patient_experience.encounter_projections', 0);
    }

    private function foundation(): Encounter
    {
        $unit = Unit::query()->create([
            'name' => 'Reference Projection Unit',
            'abbreviation' => 'REFPROJ',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
        $this->app->make(HummingbirdReferencePatientProvisioner::class)->provision(
            (int) $unit->getKey(),
        );
        $encounter = Encounter::query()
            ->where('patient_ref', HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF)
            ->sole();
        $this->app->make(HummingbirdPatientReferenceIdentityProvisioner::class)->provision(
            (string) $encounter->patient_ref,
            (int) $encounter->getKey(),
        );

        return $encounter;
    }

    /** @param array<string, mixed> $arguments */
    private function callCommand(array $arguments): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call(
            'hummingbird:draft-reference-patient-projections',
            $arguments,
            $output,
        );

        return [$exit, $output->fetch()];
    }

    /** @return array<string, mixed> */
    private function decodeJsonOutput(string $output): array
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        $this->fail('Command did not emit JSON. Output: '.$output);
    }
}
