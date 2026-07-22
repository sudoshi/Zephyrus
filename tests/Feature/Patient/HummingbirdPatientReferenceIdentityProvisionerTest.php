<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEnrollmentChallenge;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Models\Unit;
use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use App\Services\Patient\Demo\HummingbirdPatientReferenceIdentityProvisioner;
use App\Services\Patient\Demo\PatientEnrollmentMaterialGenerator;
use App\Services\Patient\PatientHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HummingbirdPatientReferenceIdentityProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hummingbird-patient.hmac_secret' => str_repeat('patient-reference-test-hmac-', 2),
            'hummingbird-patient.reference_provisioning.enabled' => true,
            'hummingbird-patient.reference_provisioning.encryption_key_version' => 'test-app-key-v1',
            'hummingbird-patient.reference_provisioning.challenge_ttl_minutes' => 10,
            'hummingbird-patient.enrollment.max_attempts' => 5,
        ]);
    }

    public function test_command_is_dry_run_first_and_requires_explicit_secret_emission(): void
    {
        $encounter = $this->operationalEncounter();

        $this->artisan('hummingbird:provision-reference-patient-identity', [
            '--encounter-id' => $encounter->getKey(),
            '--json' => true,
        ])->expectsOutputToContain('"committed":false')
            ->assertSuccessful();
        $this->assertDatabaseCount('patient_experience.principals', 0);
        $this->assertDatabaseCount('patient_experience.identity_links', 0);
        $this->assertDatabaseCount('patient_experience.encounter_access_grants', 0);
        $this->assertDatabaseCount('patient_experience.enrollment_challenges', 0);

        $this->artisan('hummingbird:provision-reference-patient-identity', [
            '--encounter-id' => $encounter->getKey(),
            '--show-secrets' => true,
            '--json' => true,
        ])->expectsOutputToContain('--show-secrets requires --commit')
            ->assertExitCode(2);
        $this->assertDatabaseCount('patient_experience.principals', 0);
    }

    public function test_commit_is_idempotent_and_issues_isolated_hash_only_material_for_both_platforms(): void
    {
        $encounter = $this->operationalEncounter();
        $firstGenerator = $this->materials([
            'ios' => [$this->material('I', '12345678')],
            'android' => [$this->material('A', '87654321')],
        ]);
        $first = $this->provisioner($firstGenerator)->provision(
            (string) $encounter->patient_ref,
            (int) $encounter->getKey(),
        );

        $this->assertTrue($first['committed']);
        $this->assertFalse($first['principal']['password_seeded']);
        $this->assertDatabaseCount('patient_experience.principals', 1);
        $this->assertDatabaseCount('patient_experience.identity_links', 1);
        $this->assertDatabaseCount('patient_experience.encounter_access_grants', 1);
        $this->assertDatabaseCount('patient_experience.enrollment_challenges', 2);

        $principal = PatientPrincipal::query()->sole();
        $identity = PatientIdentityLink::query()->sole();
        $grant = PatientEncounterAccessGrant::query()->sole();
        $ios = PatientEnrollmentChallenge::query()->where('metadata->platform', 'ios')->sole();
        $android = PatientEnrollmentChallenge::query()->where('metadata->platform', 'android')->sole();

        $this->assertSame('pending', $principal->status);
        $this->assertFalse($principal->is_active);
        $this->assertNull($principal->password);
        $this->assertNull($principal->email);
        $this->assertNull($principal->phone_e164);
        $this->assertSame('verified', $identity->status);
        $this->assertSame('pending', $grant->status);
        $this->assertTrue(Str::isUuid((string) $principal->principal_uuid));
        $this->assertTrue(Str::isUuid((string) $identity->identity_link_uuid));
        $this->assertTrue(Str::isUuid((string) $grant->grant_uuid));
        $this->assertTrue(Str::isUuid((string) $grant->encounter_uuid));
        $this->assertNotSame($ios->challenge_uuid, $android->challenge_uuid);

        $this->assertTrue($ios->matchesChallengeToken(str_repeat('I', 64)));
        $this->assertTrue($ios->matchesVerificationCode('12345678'));
        $this->assertFalse($ios->matchesChallengeToken(str_repeat('A', 64)));
        $this->assertFalse($ios->matchesVerificationCode('87654321'));
        $this->assertTrue($android->matchesChallengeToken(str_repeat('A', 64)));
        $this->assertTrue($android->matchesVerificationCode('87654321'));
        $this->assertFalse($android->matchesChallengeToken(str_repeat('I', 64)));
        $this->assertFalse($android->matchesVerificationCode('12345678'));

        $hmac = $this->app->make(PatientHmac::class);
        $this->assertSame(
            $hmac->digest(
                'reference-identity-subject-v1',
                HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY."\0".$encounter->patient_ref,
            ),
            $identity->source_subject_digest,
        );
        $this->assertSame(
            $hmac->digest(
                'reference-encounter-ref-v1',
                HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY."\0prod.encounters/".$encounter->getKey(),
            ),
            $grant->source_encounter_ref_digest,
        );

        $databasePayload = json_encode([
            DB::table('patient_experience.identity_links')->get()->all(),
            DB::table('patient_experience.encounter_access_grants')->get()->all(),
            DB::table('patient_experience.enrollment_challenges')->get()->all(),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            (string) $encounter->patient_ref,
            str_repeat('I', 64),
            '12345678',
            str_repeat('A', 64),
            '87654321',
        ] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $databasePayload);
        }

        $foundation = [
            'principal' => $principal->getKey(),
            'identity' => $identity->getKey(),
            'grant' => $grant->getKey(),
            'principal_uuid' => $principal->principal_uuid,
            'identity_uuid' => $identity->identity_link_uuid,
            'grant_uuid' => $grant->grant_uuid,
            'encounter_uuid' => $grant->encounter_uuid,
        ];
        $second = $this->provisioner($this->materials([
            'ios' => [$this->material('J', '23456789')],
            'android' => [$this->material('B', '76543210')],
        ]))->provision((string) $encounter->patient_ref, (int) $encounter->getKey());

        $this->assertSame('reused_command_owned_pending_principal', $second['actions']['principal']);
        $this->assertSame('reused_verified_identity_link', $second['actions']['identity_link']);
        $this->assertSame('reused_pending_or_active_access_grant', $second['actions']['access_grant']);
        $this->assertSame($foundation['principal'], PatientPrincipal::query()->sole()->getKey());
        $this->assertSame($foundation['identity'], PatientIdentityLink::query()->sole()->getKey());
        $this->assertSame($foundation['grant'], PatientEncounterAccessGrant::query()->sole()->getKey());
        $this->assertSame($foundation['principal_uuid'], PatientPrincipal::query()->sole()->principal_uuid);
        $this->assertSame($foundation['identity_uuid'], PatientIdentityLink::query()->sole()->identity_link_uuid);
        $this->assertSame($foundation['grant_uuid'], PatientEncounterAccessGrant::query()->sole()->grant_uuid);
        $this->assertSame($foundation['encounter_uuid'], PatientEncounterAccessGrant::query()->sole()->encounter_uuid);
        $this->assertSame(2, PatientEnrollmentChallenge::query()->where('status', 'revoked')->count());
        $this->assertSame(2, PatientEnrollmentChallenge::query()->where('status', 'issued')->count());
        $this->assertSame(4, PatientEnrollmentChallenge::query()->count());
    }

    public function test_reprovision_revokes_only_owned_issued_challenges_and_preserves_consumed_and_foreign_rows(): void
    {
        $encounter = $this->operationalEncounter();
        $this->provisioner($this->materials([
            'ios' => [$this->material('I', '12345678')],
            'android' => [$this->material('A', '87654321')],
        ]))->provision((string) $encounter->patient_ref, (int) $encounter->getKey());

        $grant = PatientEncounterAccessGrant::query()->sole();
        $principal = PatientPrincipal::query()->sole();
        $identity = PatientIdentityLink::query()->sole();
        $consumed = PatientEnrollmentChallenge::query()->where('metadata->platform', 'ios')->sole();
        $consumed->update(['status' => 'consumed', 'consumed_at' => now()]);
        $ownedIssued = PatientEnrollmentChallenge::query()->where('metadata->platform', 'android')->sole();
        $foreign = PatientEnrollmentChallenge::query()->create([
            'challenge_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'identity_link_id' => $identity->getKey(),
            'access_grant_id' => $grant->getKey(),
            'challenge_hash' => Hash::make(str_repeat('F', 64)),
            'code_hash' => Hash::make('11223344'),
            'purpose' => 'encounter_enrollment',
            'delivery_method' => 'in_person',
            'status' => 'issued',
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['owner' => 'foreign-enrollment-workflow', 'platform' => 'ios'],
        ]);

        $this->provisioner($this->materials([
            'ios' => [$this->material('J', '23456789')],
            'android' => [$this->material('B', '76543210')],
        ]))->provision((string) $encounter->patient_ref, (int) $encounter->getKey());

        $consumed->refresh();
        $ownedIssued->refresh();
        $foreign->refresh();
        $this->assertSame('consumed', $consumed->status);
        $this->assertNotNull($consumed->consumed_at);
        $this->assertNull($consumed->revoked_at);
        $this->assertSame('revoked', $ownedIssued->status);
        $this->assertNotNull($ownedIssued->revoked_at);
        $this->assertSame('issued', $foreign->status);
        $this->assertNull($foreign->revoked_at);
        $this->assertSame(2, PatientEnrollmentChallenge::query()
            ->where('status', 'issued')
            ->where('metadata->owner', HummingbirdPatientReferenceIdentityProvisioner::OWNER)
            ->count());
    }

    public function test_refuses_non_synthetic_missing_foreign_inactive_deleted_and_ambiguous_operational_encounters(): void
    {
        $service = $this->provisioner($this->materials([]));

        try {
            $service->preview('patient-real-123');
            $this->fail('Expected a real-looking patient reference to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('reference_patient_ref_must_be_synthetic', $exception->getMessage());
        }

        foreach ([
            'demo-missing-reference' => null,
            'demo-foreign-reference' => ['status' => 'active', 'is_deleted' => false, 'created_by' => 'external-ehr'],
            'demo-inactive-reference' => ['status' => 'discharged', 'is_deleted' => false, 'created_by' => HummingbirdReferencePatientProvisioner::CREATED_BY],
            'demo-deleted-reference' => ['status' => 'active', 'is_deleted' => true, 'created_by' => HummingbirdReferencePatientProvisioner::CREATED_BY],
        ] as $patientRef => $attributes) {
            if ($attributes !== null) {
                $this->rawEncounter($patientRef, $attributes);
            }

            try {
                $service->preview($patientRef);
                $this->fail("Expected {$patientRef} to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'reference_patient_operational_encounter_missing',
                    'reference_patient_operational_encounter_foreign_owned',
                    'reference_patient_operational_encounter_not_active',
                ]);
            }
        }

        $this->rawEncounter('demo-ambiguous-reference');
        $this->rawEncounter('demo-ambiguous-reference');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reference_patient_operational_encounter_ambiguous');
        $service->preview('demo-ambiguous-reference');
    }

    public function test_refuses_disabled_or_missing_schema_and_foreign_owned_identity_foundation(): void
    {
        $encounter = $this->operationalEncounter();

        config(['hummingbird-patient.reference_provisioning.enabled' => false]);
        try {
            $this->provisioner($this->materials([]))->preview((string) $encounter->patient_ref);
            $this->fail('Expected disabled provisioning to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reference_patient_provisioning_disabled', $exception->getMessage());
        }
        config(['hummingbird-patient.reference_provisioning.enabled' => true]);

        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => $table !== 'patient_experience.identity_links');
        try {
            $this->provisioner($this->materials([]))->preview((string) $encounter->patient_ref);
            $this->fail('Expected a missing identity table to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reference_patient_identity_schema_missing', $exception->getMessage());
        }
        Schema::swap(DB::connection()->getSchemaBuilder());

        $hmac = $this->app->make(PatientHmac::class);
        $digest = $hmac->digest(
            'reference-identity-subject-v1',
            HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY."\0".$encounter->patient_ref,
        );
        $foreignPrincipal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'status' => 'pending',
            'is_active' => false,
        ]);
        PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $foreignPrincipal->getKey(),
            'source_system_key' => HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY,
            'encrypted_source_subject' => (string) $encounter->patient_ref,
            'encryption_key_version' => 'test-app-key-v1',
            'source_subject_digest' => $digest,
            'linkage_method' => 'encounter_enrollment',
            'status' => 'verified',
            'assurance_level' => 'manual',
            'provenance' => ['owner' => 'foreign-identity-workflow', 'synthetic' => false],
            'verified_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reference_patient_identity_link_foreign_owned');
        $this->provisioner($this->materials([]))->provision(
            (string) $encounter->patient_ref,
            (int) $encounter->getKey(),
        );
    }

    public function test_local_runtime_refuses_a_non_local_database_even_when_provisioning_is_enabled(): void
    {
        $encounter = $this->operationalEncounter();
        $originalEnvironment = $this->app['env'];
        $connection = (string) config('database.default');
        $originalHost = config("database.connections.{$connection}.host");

        try {
            $this->app['env'] = 'local';
            config(["database.connections.{$connection}.host" => 'pgsql.remote.example.test']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('reference_patient_provisioning_refuses_remote_database_from_local_runtime');
            $this->provisioner($this->materials([]))->preview(
                (string) $encounter->patient_ref,
                (int) $encounter->getKey(),
            );
        } finally {
            $this->app['env'] = $originalEnvironment;
            config(["database.connections.{$connection}.host" => $originalHost]);
        }
    }

    public function test_foreign_owned_principal_link_and_grant_are_refused_without_mutation(): void
    {
        $hmac = $this->app->make(PatientHmac::class);

        $principalEncounter = $this->operationalEncounter('demo-foreign-principal-foundation');
        $principalDigest = $hmac->digest(
            'reference-identity-subject-v1',
            HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY."\0".$principalEncounter->patient_ref,
        );
        $foreignPrincipal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'status' => 'pending',
            'is_active' => false,
            'preferences' => [
                'synthetic' => true,
                'provisioning' => [
                    'owner' => 'foreign-principal-workflow',
                    'reference_subject_digest' => $principalDigest,
                ],
            ],
        ]);
        $foreignPrincipalBefore = DB::table('patient_experience.principals')
            ->where('principal_id', $foreignPrincipal->getKey())
            ->first();
        try {
            $this->provisioner($this->materials([]))->preview(
                (string) $principalEncounter->patient_ref,
                (int) $principalEncounter->getKey(),
            );
            $this->fail('Expected a foreign-owned principal to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reference_patient_principal_foreign_owned', $exception->getMessage());
        }
        $this->assertEquals($foreignPrincipalBefore, DB::table('patient_experience.principals')
            ->where('principal_id', $foreignPrincipal->getKey())
            ->first());

        $linkEncounter = $this->operationalEncounter('demo-foreign-link-foundation');
        $linkDigest = $hmac->digest(
            'reference-identity-subject-v1',
            HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY."\0".$linkEncounter->patient_ref,
        );
        $linkPrincipal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'status' => 'pending',
            'is_active' => false,
        ]);
        $foreignLink = PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $linkPrincipal->getKey(),
            'source_system_key' => HummingbirdPatientReferenceIdentityProvisioner::SOURCE_SYSTEM_KEY,
            'encrypted_source_subject' => (string) $linkEncounter->patient_ref,
            'encryption_key_version' => 'test-app-key-v1',
            'source_subject_digest' => $linkDigest,
            'linkage_method' => 'encounter_enrollment',
            'status' => 'verified',
            'assurance_level' => 'manual',
            'provenance' => ['owner' => 'foreign-link-workflow', 'synthetic' => false],
            'verified_at' => now(),
        ]);
        $foreignLinkBefore = DB::table('patient_experience.identity_links')
            ->where('identity_link_id', $foreignLink->getKey())
            ->first();
        try {
            $this->provisioner($this->materials([]))->provision(
                (string) $linkEncounter->patient_ref,
                (int) $linkEncounter->getKey(),
            );
            $this->fail('Expected a foreign-owned identity link to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reference_patient_identity_link_foreign_owned', $exception->getMessage());
        }
        $this->assertEquals($foreignLinkBefore, DB::table('patient_experience.identity_links')
            ->where('identity_link_id', $foreignLink->getKey())
            ->first());

        $grantEncounter = $this->operationalEncounter('demo-foreign-grant-foundation');
        $this->provisioner($this->materials([
            'ios' => [$this->material('I', '12345678')],
            'android' => [$this->material('A', '87654321')],
        ]))->provision((string) $grantEncounter->patient_ref, (int) $grantEncounter->getKey());
        $foreignGrant = PatientEncounterAccessGrant::query()
            ->where('source_encounter_id', $grantEncounter->getKey())
            ->sole();
        $foreignGrant->update([
            'metadata' => array_merge((array) $foreignGrant->metadata, [
                'owner' => 'foreign-grant-workflow',
            ]),
        ]);
        $foreignGrantBefore = DB::table('patient_experience.encounter_access_grants')
            ->where('access_grant_id', $foreignGrant->getKey())
            ->first();
        $challengesBefore = DB::table('patient_experience.enrollment_challenges')
            ->where('access_grant_id', $foreignGrant->getKey())
            ->orderBy('enrollment_challenge_id')
            ->get()
            ->all();
        try {
            $this->provisioner($this->materials([
                'ios' => [$this->material('J', '23456789')],
                'android' => [$this->material('B', '76543210')],
            ]))->provision((string) $grantEncounter->patient_ref, (int) $grantEncounter->getKey());
            $this->fail('Expected a foreign-owned access grant to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reference_patient_access_grant_foreign_owned', $exception->getMessage());
        }
        $this->assertEquals($foreignGrantBefore, DB::table('patient_experience.encounter_access_grants')
            ->where('access_grant_id', $foreignGrant->getKey())
            ->first());
        $this->assertEquals($challengesBefore, DB::table('patient_experience.enrollment_challenges')
            ->where('access_grant_id', $foreignGrant->getKey())
            ->orderBy('enrollment_challenge_id')
            ->get()
            ->all());
    }

    public function test_default_output_never_emits_plaintext_but_explicit_commit_show_secrets_emits_once_with_warning(): void
    {
        $encounter = $this->operationalEncounter();
        $defaultMaterial = [
            'ios' => [$this->material('I', '12345678')],
            'android' => [$this->material('A', '87654321')],
        ];
        $this->app->instance(PatientEnrollmentMaterialGenerator::class, $this->materials($defaultMaterial));

        [$exit, $defaultOutput] = $this->callCommand([
            '--encounter-id' => $encounter->getKey(),
            '--commit' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $defaultOutput = trim($defaultOutput);
        $defaultJson = $this->decodeJsonOutput($defaultOutput);
        $this->assertFalse($defaultJson['secrets_emitted']);
        $this->assertStringContainsString('[REDACTED]', $defaultOutput);
        $this->assertStringNotContainsString(str_repeat('I', 64), $defaultOutput);
        $this->assertStringNotContainsString('12345678', $defaultOutput);
        $this->assertStringNotContainsString(str_repeat('A', 64), $defaultOutput);
        $this->assertStringNotContainsString('87654321', $defaultOutput);

        $this->app->instance(PatientEnrollmentMaterialGenerator::class, $this->materials([
            'ios' => [$this->material('J', '23456789')],
            'android' => [$this->material('B', '76543210')],
        ]));
        [$exit, $tableOutput] = $this->callCommand([
            '--encounter-id' => $encounter->getKey(),
            '--commit' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[REDACTED]', $tableOutput);
        $this->assertStringNotContainsString(str_repeat('J', 64), $tableOutput);
        $this->assertStringNotContainsString('23456789', $tableOutput);
        $this->assertStringNotContainsString(str_repeat('B', 64), $tableOutput);
        $this->assertStringNotContainsString('76543210', $tableOutput);

        $this->app->instance(PatientEnrollmentMaterialGenerator::class, $this->materials([
            'ios' => [$this->material('K', '34567890')],
            'android' => [$this->material('C', '65432109')],
        ]));
        [$exit, $explicitOutput] = $this->callCommand([
            '--encounter-id' => $encounter->getKey(),
            '--commit' => true,
            '--show-secrets' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $explicitOutput = $this->decodeJsonOutput($explicitOutput);
        $this->assertTrue($explicitOutput['secrets_emitted']);
        $this->assertSame(str_repeat('K', 64), $explicitOutput['enrollment']['ios']['challenge_token']);
        $this->assertSame('34567890', $explicitOutput['enrollment']['ios']['verification_code']);
        $this->assertSame(str_repeat('C', 64), $explicitOutput['enrollment']['android']['challenge_token']);
        $this->assertSame('65432109', $explicitOutput['enrollment']['android']['verification_code']);
        $this->assertStringContainsString('deliver through an approved secure channel', $explicitOutput['security_warning']);
    }

    public function test_transaction_rolls_back_revocation_and_partial_challenge_creation_on_failure(): void
    {
        $encounter = $this->operationalEncounter();
        $this->provisioner($this->materials([
            'ios' => [$this->material('I', '12345678')],
            'android' => [$this->material('A', '87654321')],
        ]))->provision((string) $encounter->patient_ref, (int) $encounter->getKey());
        $before = PatientEnrollmentChallenge::query()
            ->orderBy('enrollment_challenge_id')
            ->get()
            ->map(fn (PatientEnrollmentChallenge $challenge): array => [
                $challenge->getKey(),
                $challenge->status,
                $challenge->revoked_at?->toISOString(),
            ])->all();

        $throwing = new class extends PatientEnrollmentMaterialGenerator
        {
            public function generate(string $platform): array
            {
                if ($platform === 'android') {
                    throw new RuntimeException('simulated_android_material_failure');
                }

                return [
                    'challenge_token' => str_repeat('J', 64),
                    'verification_code' => '23456789',
                ];
            }
        };

        try {
            $this->provisioner($throwing)->provision(
                (string) $encounter->patient_ref,
                (int) $encounter->getKey(),
            );
            $this->fail('Expected the simulated second-platform failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated_android_material_failure', $exception->getMessage());
        }

        $after = PatientEnrollmentChallenge::query()
            ->orderBy('enrollment_challenge_id')
            ->get()
            ->map(fn (PatientEnrollmentChallenge $challenge): array => [
                $challenge->getKey(),
                $challenge->status,
                $challenge->revoked_at?->toISOString(),
            ])->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('patient_experience.principals', 1);
        $this->assertDatabaseCount('patient_experience.identity_links', 1);
        $this->assertDatabaseCount('patient_experience.encounter_access_grants', 1);
        $this->assertDatabaseCount('patient_experience.enrollment_challenges', 2);
    }

    private function operationalEncounter(
        string $patientRef = HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
    ): Encounter {
        $unit = $this->unit('Reference Patient Unit');
        $this->app->make(HummingbirdReferencePatientProvisioner::class)->provision(
            (int) $unit->getKey(),
            $patientRef,
        );

        return Encounter::query()->where('patient_ref', $patientRef)->sole();
    }

    /** @param array<string, mixed> $attributes */
    private function rawEncounter(string $patientRef, array $attributes = []): Encounter
    {
        $unit = Unit::query()->first() ?? $this->unit('Refusal Unit');

        return Encounter::query()->create(array_merge([
            'patient_ref' => $patientRef,
            'unit_id' => $unit->getKey(),
            'bed_id' => null,
            'admitted_at' => now(),
            'expected_discharge_date' => now()->addDays(2)->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_by' => HummingbirdReferencePatientProvisioner::CREATED_BY,
            'modified_by' => HummingbirdReferencePatientProvisioner::CREATED_BY,
            'is_deleted' => false,
        ], $attributes));
    }

    private function unit(string $name): Unit
    {
        return Unit::query()->create([
            'name' => $name,
            'abbreviation' => str($name)->upper()->replace(' ', '')->limit(8, '')->toString(),
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
    }

    private function provisioner(PatientEnrollmentMaterialGenerator $generator): HummingbirdPatientReferenceIdentityProvisioner
    {
        return new HummingbirdPatientReferenceIdentityProvisioner(
            $this->app,
            $this->app->make('encrypter'),
            $this->app->make(PatientHmac::class),
            $generator,
        );
    }

    /** @return array<string, mixed> */
    private function callCommand(array $arguments): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call(
            'hummingbird:provision-reference-patient-identity',
            $arguments,
            $output,
        );

        return [$exit, $output->fetch()];
    }

    /** @return array<string, mixed> */
    private function decodeJsonOutput(string $output): array
    {
        $output = trim($output);
        $lines = array_reverse(preg_split('/\R/', $output) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        $this->fail('Command did not emit JSON. Output: '.$output);
    }

    /** @return array{challenge_token: string, verification_code: string} */
    private function material(string $tokenCharacter, string $code): array
    {
        return [
            'challenge_token' => str_repeat($tokenCharacter, 64),
            'verification_code' => $code,
        ];
    }

    /** @param array<string, array<int, array{challenge_token: string, verification_code: string}>> $materials */
    private function materials(array $materials): PatientEnrollmentMaterialGenerator
    {
        return new class($materials) extends PatientEnrollmentMaterialGenerator
        {
            /** @param array<string, array<int, array{challenge_token: string, verification_code: string}>> $materials */
            public function __construct(private array $materials) {}

            public function generate(string $platform): array
            {
                if (! isset($this->materials[$platform][0])) {
                    throw new RuntimeException("missing_test_material_for_{$platform}");
                }

                return array_shift($this->materials[$platform]);
            }
        };
    }
}
