<?php

namespace App\Services\Patient\Demo;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEnrollmentChallenge;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use App\Services\Patient\PatientHmac;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HummingbirdPatientReferenceIdentityProvisioner
{
    public const OWNER = 'hummingbird-patient-reference-identity-provisioner-v1';

    public const SOURCE_SYSTEM_KEY = 'zephyrus-prod-encounters';

    /** @var array<int, string> */
    private const PLATFORMS = ['ios', 'android'];

    /** @var array<int, string> */
    private const REQUIRED_TABLES = [
        'patient_experience.principals',
        'patient_experience.identity_links',
        'patient_experience.encounter_access_grants',
        'patient_experience.enrollment_challenges',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Encrypter $encrypter,
        private readonly PatientHmac $hmac,
        private readonly PatientEnrollmentMaterialGenerator $materialGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        string $patientRef = HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
        ?int $encounterId = null,
    ): array {
        $this->assertExecutionSafe();
        $encounter = $this->resolveEncounter($patientRef, $encounterId, false);
        $digests = $this->digests($encounter);
        [$principal, $identityLink, $grant] = $this->resolveExistingFoundation($encounter, $digests, false);

        return $this->result(
            committed: false,
            encounter: $encounter,
            principal: $principal,
            identityLink: $identityLink,
            grant: $grant,
            enrollment: collect(self::PLATFORMS)->mapWithKeys(fn (string $platform): array => [
                $platform => [
                    'action' => 'issue_or_replace_command_owned_challenge',
                    'challenge_uuid' => null,
                    'expires_at' => null,
                    'challenge_token' => null,
                    'verification_code' => null,
                ],
            ])->all(),
            actions: [
                'principal' => $principal ? 'reuse_command_owned_pending_principal' : 'create_pending_principal',
                'identity_link' => $identityLink ? 'reuse_verified_identity_link' : 'create_verified_identity_link',
                'access_grant' => $grant ? 'reuse_pending_or_active_access_grant' : 'create_pending_access_grant',
            ],
        );
    }

    /** @return array<string, mixed> */
    public function provision(
        string $patientRef = HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
        ?int $encounterId = null,
    ): array {
        $this->assertExecutionSafe();

        return DB::transaction(function () use ($patientRef, $encounterId): array {
            $preLockEncounter = $this->resolveEncounter($patientRef, $encounterId, false);
            $digests = $this->digests($preLockEncounter);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                $this->hmac->digest('reference-provision-lock-v1', $digests['identity']),
            ]);

            $encounter = $this->resolveEncounter($patientRef, $encounterId, true);
            $digests = $this->digests($encounter);
            [$principal, $identityLink, $grant] = $this->resolveExistingFoundation($encounter, $digests, true);
            $actions = [];

            if ($principal === null) {
                $principal = $this->createPrincipal($digests['identity']);
                $actions['principal'] = 'created_pending_principal';
            } else {
                $actions['principal'] = 'reused_command_owned_pending_principal';
            }

            if ($identityLink === null) {
                $identityLink = $this->createIdentityLink($principal, $encounter, $digests['identity']);
                $actions['identity_link'] = 'created_verified_identity_link';
            } else {
                $this->assertIdentityPrincipal($identityLink, $principal);
                $actions['identity_link'] = 'reused_verified_identity_link';
            }

            if ($grant === null) {
                $grant = $this->createGrant($principal, $identityLink, $encounter, $digests['encounter']);
                $actions['access_grant'] = 'created_pending_access_grant';
            } else {
                $this->assertGrantFoundation($grant, $principal, $identityLink);
                $actions['access_grant'] = 'reused_pending_or_active_access_grant';
            }

            $revoked = $this->revokeOwnedIssuedChallenges($grant);
            $enrollment = $this->issuePlatformChallenges(
                $principal,
                $identityLink,
                $grant,
                $encounter,
                $digests['encounter'],
            );
            $actions['challenges'] = $revoked === 0
                ? 'issued_two_platform_challenges'
                : 'revoked_'.$revoked.'_owned_issued_and_replaced_two_platform_challenges';

            return $this->result(
                committed: true,
                encounter: $encounter,
                principal: $principal,
                identityLink: $identityLink,
                grant: $grant,
                enrollment: $enrollment,
                actions: $actions,
            );
        }, 3);
    }

    private function assertExecutionSafe(): void
    {
        if (! (bool) config('hummingbird-patient.reference_provisioning.enabled', false)) {
            throw new RuntimeException('reference_patient_provisioning_disabled');
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('reference_patient_provisioning_requires_postgresql');
        }

        if ($this->app->environment('local') && ! $this->databaseHostIsLocal()) {
            throw new RuntimeException('reference_patient_provisioning_refuses_remote_database_from_local_runtime');
        }

        $this->hmac->assertAvailable();
        $this->assertEncryptionConfiguration();

        if (! Schema::hasTable('prod.encounters')) {
            throw new RuntimeException('reference_patient_operational_encounters_table_missing');
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('reference_patient_identity_schema_missing');
            }
        }
    }

    private function assertEncryptionConfiguration(): void
    {
        $keyVersion = trim((string) config('hummingbird-patient.reference_provisioning.encryption_key_version'));
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}$/', $keyVersion) !== 1) {
            throw new RuntimeException('reference_patient_encryption_key_version_unavailable');
        }

        $configuredKey = trim((string) config('app.key'));
        if ($configuredKey === '') {
            throw new RuntimeException('reference_patient_app_encryption_key_unavailable');
        }

        if (! $this->app->environment('testing') && $this->isPlaceholderKey($configuredKey)) {
            throw new RuntimeException('reference_patient_app_encryption_key_is_placeholder');
        }

        try {
            $probe = 'reference-patient-encryption-key-probe';
            if ($this->encrypter->decryptString($this->encrypter->encryptString($probe)) !== $probe) {
                throw new RuntimeException('reference_patient_app_encryption_key_invalid');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'reference_patient_app_encryption_key_invalid') {
                throw $exception;
            }

            throw new RuntimeException('reference_patient_app_encryption_key_invalid', previous: $exception);
        }
    }

    private function databaseHostIsLocal(): bool
    {
        $connection = (string) config('database.default');
        $host = trim((string) config("database.connections.{$connection}.host"));

        return $host === '' || in_array(Str::lower($host), [
            'localhost',
            '127.0.0.1',
            '::1',
            'zephyrus-test-pg',
        ], true);
    }

    private function isPlaceholderKey(string $configuredKey): bool
    {
        $raw = Str::startsWith($configuredKey, 'base64:')
            ? base64_decode(Str::after($configuredKey, 'base64:'), true)
            : $configuredKey;

        return ! is_string($raw) || $raw === '' || trim($raw, "\0") === '';
    }

    private function resolveEncounter(string $patientRef, ?int $encounterId, bool $forUpdate): Encounter
    {
        $this->assertSyntheticReference($patientRef);

        if ($encounterId !== null && $encounterId < 1) {
            throw new InvalidArgumentException('reference_patient_encounter_id_invalid');
        }

        $query = Encounter::query()->where('patient_ref', $patientRef);
        if ($encounterId !== null) {
            $query->whereKey($encounterId);
        }
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        /** @var EloquentCollection<int, Encounter> $matches */
        $matches = $query->orderBy('encounter_id')->get();
        if ($matches->isEmpty()) {
            throw new RuntimeException('reference_patient_operational_encounter_missing');
        }
        if ($matches->count() !== 1) {
            throw new RuntimeException('reference_patient_operational_encounter_ambiguous');
        }

        $encounter = $matches->sole();
        if ($encounter->created_by !== HummingbirdReferencePatientProvisioner::CREATED_BY) {
            throw new RuntimeException('reference_patient_operational_encounter_foreign_owned');
        }
        if ($encounter->is_deleted || $encounter->status !== 'active' || $encounter->discharged_at !== null) {
            throw new RuntimeException('reference_patient_operational_encounter_not_active');
        }

        return $encounter;
    }

    private function assertSyntheticReference(string $patientRef): void
    {
        if (preg_match('/^(demo|sim)-[a-z0-9][a-z0-9-]{7,95}$/', $patientRef) !== 1) {
            throw new InvalidArgumentException('reference_patient_ref_must_be_synthetic');
        }
    }

    /** @return array{identity: string, encounter: string} */
    private function digests(Encounter $encounter): array
    {
        return [
            'identity' => $this->hmac->digest(
                'reference-identity-subject-v1',
                self::SOURCE_SYSTEM_KEY."\0".(string) $encounter->patient_ref,
            ),
            'encounter' => $this->hmac->digest(
                'reference-encounter-ref-v1',
                self::SOURCE_SYSTEM_KEY."\0prod.encounters/".(string) $encounter->getKey(),
            ),
        ];
    }

    /**
     * @param  array{identity: string, encounter: string}  $digests
     * @return array{?PatientPrincipal, ?PatientIdentityLink, ?PatientEncounterAccessGrant}
     */
    private function resolveExistingFoundation(Encounter $encounter, array $digests, bool $forUpdate): array
    {
        $identityQuery = PatientIdentityLink::query()
            ->where('source_system_key', self::SOURCE_SYSTEM_KEY)
            ->where('source_subject_digest', $digests['identity']);
        $principalQuery = PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,reference_subject_digest}' = ?", [$digests['identity']]);
        $grantQuery = PatientEncounterAccessGrant::query()
            ->where('source_system_key', self::SOURCE_SYSTEM_KEY)
            ->where('source_encounter_ref_digest', $digests['encounter']);

        if ($forUpdate) {
            $identityQuery->lockForUpdate();
            $principalQuery->lockForUpdate();
            $grantQuery->lockForUpdate();
        }

        $identityLinks = $identityQuery->get();
        $principals = $principalQuery->get();
        $grants = $grantQuery->get();
        if ($identityLinks->count() > 1 || $principals->count() > 1 || $grants->count() > 1) {
            throw new RuntimeException('reference_patient_identity_foundation_ambiguous');
        }

        /** @var PatientIdentityLink|null $identityLink */
        $identityLink = $identityLinks->first();
        /** @var PatientPrincipal|null $principal */
        $principal = $principals->first();
        /** @var PatientEncounterAccessGrant|null $grant */
        $grant = $grants->first();

        if ($identityLink !== null) {
            $this->assertOwnedIdentityLink($identityLink, $encounter);
            $linkedPrincipal = PatientPrincipal::query()
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->whereKey($identityLink->principal_id)
                ->first();
            if ($linkedPrincipal === null) {
                throw new RuntimeException('reference_patient_identity_principal_missing');
            }
            if ($principal !== null && $principal->getKey() !== $linkedPrincipal->getKey()) {
                throw new RuntimeException('reference_patient_identity_principal_conflict');
            }
            $principal = $linkedPrincipal;
        }

        if ($principal !== null) {
            $this->assertOwnedPendingPrincipal($principal, $digests['identity']);
        }

        if ($grant !== null) {
            if ($principal === null || $identityLink === null) {
                throw new RuntimeException('reference_patient_access_grant_foundation_incomplete');
            }
            $this->assertOwnedGrant($grant, $encounter);
            if ($principal !== null && (int) $grant->principal_id !== (int) $principal->getKey()) {
                throw new RuntimeException('reference_patient_grant_principal_conflict');
            }
            if ($identityLink !== null && (int) $grant->identity_link_id !== (int) $identityLink->getKey()) {
                throw new RuntimeException('reference_patient_grant_identity_conflict');
            }
        }

        return [$principal, $identityLink, $grant];
    }

    private function createPrincipal(string $identityDigest): PatientPrincipal
    {
        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Hummingbird Reference Patient',
            'status' => 'pending',
            'is_active' => false,
            'preferences' => [
                'synthetic' => true,
                'provisioning' => [
                    'owner' => self::OWNER,
                    'source_system_key' => self::SOURCE_SYSTEM_KEY,
                    'reference_subject_digest' => $identityDigest,
                ],
            ],
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    private function createIdentityLink(
        PatientPrincipal $principal,
        Encounter $encounter,
        string $identityDigest,
    ): PatientIdentityLink {
        $now = now();

        return PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'source_system_key' => self::SOURCE_SYSTEM_KEY,
            'encrypted_source_subject' => (string) $encounter->patient_ref,
            'encryption_key_version' => $this->encryptionKeyVersion(),
            'source_subject_digest' => $identityDigest,
            'digest_algorithm' => 'hmac-sha256',
            'linkage_method' => 'encounter_enrollment',
            'status' => 'verified',
            'assurance_level' => 'synthetic-command-owned',
            'provenance' => [
                'owner' => self::OWNER,
                'synthetic' => true,
                'source_schema' => 'prod',
                'source_table' => 'encounters',
                'source_column' => 'patient_ref',
                'source_encounter_id' => (int) $encounter->getKey(),
                'source_system_key' => self::SOURCE_SYSTEM_KEY,
                'encryption_key_version' => $this->encryptionKeyVersion(),
            ],
            'verified_at' => $now,
        ]);
    }

    private function createGrant(
        PatientPrincipal $principal,
        PatientIdentityLink $identityLink,
        Encounter $encounter,
        string $encounterDigest,
    ): PatientEncounterAccessGrant {
        return PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'identity_link_id' => $identityLink->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_id' => $encounter->getKey(),
            'encrypted_source_encounter_ref' => 'prod.encounters/'.(string) $encounter->getKey(),
            'source_encounter_ref_digest' => $encounterDigest,
            'source_system_key' => self::SOURCE_SYSTEM_KEY,
            'relationship' => 'self',
            'scopes' => ['today:read', 'pathway:read', 'care_team:read'],
            'purpose_of_use' => 'patient_access',
            'status' => 'pending',
            'valid_from' => now()->subSecond(),
            'issued_by_actor_type' => 'system',
            'issued_by_actor_ref' => self::OWNER,
            'grant_reason' => 'Synthetic reference inpatient enrollment verification.',
            'version' => 1,
            'metadata' => [
                'owner' => self::OWNER,
                'synthetic' => true,
                'source_schema' => 'prod',
                'source_table' => 'encounters',
                'source_system_key' => self::SOURCE_SYSTEM_KEY,
                'encryption_key_version' => $this->encryptionKeyVersion(),
            ],
        ]);
    }

    private function revokeOwnedIssuedChallenges(PatientEncounterAccessGrant $grant): int
    {
        $revoked = 0;
        $issued = PatientEnrollmentChallenge::query()
            ->where('access_grant_id', $grant->getKey())
            ->where('status', 'issued')
            ->lockForUpdate()
            ->get();

        foreach ($issued as $challenge) {
            if (($challenge->metadata['owner'] ?? null) !== self::OWNER
                || ! in_array($challenge->metadata['platform'] ?? null, self::PLATFORMS, true)
                || $challenge->purpose !== 'encounter_enrollment') {
                continue;
            }

            $challenge->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();
            $revoked++;
        }

        return $revoked;
    }

    /** @return array<string, array<string, mixed>> */
    private function issuePlatformChallenges(
        PatientPrincipal $principal,
        PatientIdentityLink $identityLink,
        PatientEncounterAccessGrant $grant,
        Encounter $encounter,
        string $encounterDigest,
    ): array {
        $enrollment = [];
        $seenTokens = [];
        $seenCodes = [];
        $expiresAt = now()->addMinutes($this->challengeTtlMinutes());

        foreach (self::PLATFORMS as $platform) {
            $material = $this->materialGenerator->generate($platform);
            $this->assertEnrollmentMaterial($material, $platform, $seenTokens, $seenCodes);

            $challenge = PatientEnrollmentChallenge::query()->create([
                'challenge_uuid' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'identity_link_id' => $identityLink->getKey(),
                'access_grant_id' => $grant->getKey(),
                'challenge_hash' => Hash::make($material['challenge_token']),
                'code_hash' => Hash::make($material['verification_code']),
                'purpose' => 'encounter_enrollment',
                'delivery_method' => 'in_person',
                'status' => 'issued',
                'failed_attempts' => 0,
                'max_attempts' => (int) config('hummingbird-patient.enrollment.max_attempts', 5),
                'expires_at' => $expiresAt,
                'metadata' => [
                    'owner' => self::OWNER,
                    'synthetic' => true,
                    'platform' => $platform,
                    'source_encounter_id' => (int) $encounter->getKey(),
                    'source_encounter_ref_digest' => $encounterDigest,
                    'source_system_key' => self::SOURCE_SYSTEM_KEY,
                    'encryption_key_version' => $this->encryptionKeyVersion(),
                ],
            ]);

            $enrollment[$platform] = [
                'action' => 'issued',
                'challenge_uuid' => (string) $challenge->challenge_uuid,
                'expires_at' => $challenge->expires_at->toISOString(),
                'challenge_token' => $material['challenge_token'],
                'verification_code' => $material['verification_code'],
            ];
            $seenTokens[] = $material['challenge_token'];
            $seenCodes[] = $material['verification_code'];
        }

        return $enrollment;
    }

    /**
     * @param  array{challenge_token?: mixed, verification_code?: mixed}  $material
     * @param  array<int, string>  $seenTokens
     * @param  array<int, string>  $seenCodes
     */
    private function assertEnrollmentMaterial(
        array $material,
        string $platform,
        array $seenTokens,
        array $seenCodes,
    ): void {
        $token = $material['challenge_token'] ?? null;
        $code = $material['verification_code'] ?? null;
        if (! is_string($token) || preg_match('/^[A-Za-z0-9_-]{64}$/', $token) !== 1) {
            throw new RuntimeException("reference_patient_{$platform}_challenge_token_invalid");
        }
        if (! is_string($code) || preg_match('/^[0-9]{8}$/', $code) !== 1) {
            throw new RuntimeException("reference_patient_{$platform}_verification_code_invalid");
        }
        if (in_array($token, $seenTokens, true) || in_array($code, $seenCodes, true)) {
            throw new RuntimeException('reference_patient_platform_enrollment_material_not_isolated');
        }
    }

    private function assertOwnedPendingPrincipal(PatientPrincipal $principal, string $identityDigest): void
    {
        $provisioning = (array) (($principal->preferences ?? [])['provisioning'] ?? []);
        if (($provisioning['owner'] ?? null) !== self::OWNER
            || ($provisioning['reference_subject_digest'] ?? null) !== $identityDigest
            || ($principal->preferences['synthetic'] ?? null) !== true) {
            throw new RuntimeException('reference_patient_principal_foreign_owned');
        }
        if ($principal->principal_type !== 'patient'
            || $principal->status !== 'pending'
            || (bool) $principal->is_active
            || $principal->password !== null
            || $principal->email !== null
            || $principal->phone_e164 !== null
            || ! Str::isUuid((string) $principal->principal_uuid)) {
            throw new RuntimeException('reference_patient_principal_not_safe_pending_synthetic');
        }
    }

    private function assertOwnedIdentityLink(PatientIdentityLink $identityLink, Encounter $encounter): void
    {
        $provenance = (array) $identityLink->provenance;
        if (($provenance['owner'] ?? null) !== self::OWNER || ($provenance['synthetic'] ?? null) !== true) {
            throw new RuntimeException('reference_patient_identity_link_foreign_owned');
        }
        if ($identityLink->status !== 'verified'
            || $identityLink->revoked_at !== null
            || $identityLink->verified_at === null
            || $identityLink->digest_algorithm !== 'hmac-sha256'
            || $identityLink->encryption_key_version !== $this->encryptionKeyVersion()
            || ! Str::isUuid((string) $identityLink->identity_link_uuid)) {
            throw new RuntimeException('reference_patient_identity_link_not_reusable');
        }

        try {
            if ($identityLink->encrypted_source_subject !== (string) $encounter->patient_ref) {
                throw new RuntimeException('reference_patient_identity_link_source_mismatch');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'reference_patient_identity_link_source_mismatch') {
                throw $exception;
            }

            throw new RuntimeException('reference_patient_identity_link_decryption_failed', previous: $exception);
        }
    }

    private function assertOwnedGrant(PatientEncounterAccessGrant $grant, Encounter $encounter): void
    {
        $metadata = (array) $grant->metadata;
        if (($metadata['owner'] ?? null) !== self::OWNER || ($metadata['synthetic'] ?? null) !== true) {
            throw new RuntimeException('reference_patient_access_grant_foreign_owned');
        }
        if (! in_array($grant->status, ['pending', 'active'], true)
            || $grant->revoked_at !== null
            || (int) $grant->source_encounter_id !== (int) $encounter->getKey()
            || $grant->relationship !== 'self'
            || $grant->purpose_of_use !== 'patient_access'
            || ! Str::isUuid((string) $grant->grant_uuid)
            || ! Str::isUuid((string) $grant->encounter_uuid)) {
            throw new RuntimeException('reference_patient_access_grant_not_reusable');
        }

        try {
            if ($grant->encrypted_source_encounter_ref !== 'prod.encounters/'.(string) $encounter->getKey()) {
                throw new RuntimeException('reference_patient_access_grant_source_mismatch');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'reference_patient_access_grant_source_mismatch') {
                throw $exception;
            }

            throw new RuntimeException('reference_patient_access_grant_decryption_failed', previous: $exception);
        }
    }

    private function assertIdentityPrincipal(PatientIdentityLink $identityLink, PatientPrincipal $principal): void
    {
        if ((int) $identityLink->principal_id !== (int) $principal->getKey()) {
            throw new RuntimeException('reference_patient_identity_principal_conflict');
        }
    }

    private function assertGrantFoundation(
        PatientEncounterAccessGrant $grant,
        PatientPrincipal $principal,
        PatientIdentityLink $identityLink,
    ): void {
        if ((int) $grant->principal_id !== (int) $principal->getKey()
            || (int) $grant->identity_link_id !== (int) $identityLink->getKey()) {
            throw new RuntimeException('reference_patient_access_grant_foundation_conflict');
        }
    }

    private function encryptionKeyVersion(): string
    {
        return trim((string) config('hummingbird-patient.reference_provisioning.encryption_key_version'));
    }

    private function challengeTtlMinutes(): int
    {
        return max(5, min(30, (int) config(
            'hummingbird-patient.reference_provisioning.challenge_ttl_minutes',
            10,
        )));
    }

    /**
     * @param  array<string, array<string, mixed>>  $enrollment
     * @param  array<string, string>  $actions
     * @return array<string, mixed>
     */
    private function result(
        bool $committed,
        Encounter $encounter,
        ?PatientPrincipal $principal,
        ?PatientIdentityLink $identityLink,
        ?PatientEncounterAccessGrant $grant,
        array $enrollment,
        array $actions,
    ): array {
        return [
            'committed' => $committed,
            'owner' => self::OWNER,
            'source' => [
                'system' => self::SOURCE_SYSTEM_KEY,
                'encounter_id' => (int) $encounter->getKey(),
                'encounter_status' => (string) $encounter->status,
                'operational_owner' => (string) $encounter->created_by,
                'encryption_key_version' => $this->encryptionKeyVersion(),
            ],
            'principal' => [
                'uuid' => $principal?->principal_uuid,
                'status' => $principal?->status ?? 'planned_pending',
                'password_seeded' => false,
            ],
            'identity_link' => [
                'uuid' => $identityLink?->identity_link_uuid,
                'status' => $identityLink?->status ?? 'planned_verified',
            ],
            'access_grant' => [
                'uuid' => $grant?->grant_uuid,
                'encounter_uuid' => $grant?->encounter_uuid,
                'status' => $grant?->status ?? 'planned_pending',
            ],
            'actions' => $actions,
            'enrollment' => $enrollment,
        ];
    }
}
