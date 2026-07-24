<?php

namespace App\Services\Patient\Demo;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use App\Services\Patient\PatientHmac;
use App\Services\Patient\Projection\PatientProjectionContentGuard;
use App\Services\Patient\Projection\PatientProjectionDisclosureService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Creates reviewable, synthetic, draft-only projections for the one
 * command-owned reference inpatient.
 *
 * This service cannot activate the principal or encounter grant, issue a
 * credential, create a session, approve a release policy, or insert a released
 * projection. The ordinary patient disclosure query selects only released
 * projections under an effective grant and active release policy, so these
 * rows remain unavailable to every patient API request.
 */
final class HummingbirdPatientReferenceProjectionDraftProvisioner
{
    public const OWNER = 'hummingbird-patient-reference-projection-draft-provisioner-v1';

    public const CONFIRMATION = 'synthetic-drafts-not-patient-visible';

    public const POLICY_VERSION = 'patient-reference-synthetic-draft-v1';

    public const PRODUCER_VERSION = 'patient-reference-projection-draft-v1';

    public const SOURCE_SYSTEM_KEY = 'zephyrus-reference-patient-draft';

    private const CONTENT_SEED = 'command-owned-reference-patient-v1';

    private const SYNTHETIC_NOTICE = 'Synthetic demonstration content for verification only. It is not a medical record or care instruction.';

    /** @var list<string> */
    private const KINDS = [
        'today',
        'pathway',
        'pathway_events',
        'discharge_readiness',
        'rounds_summary',
        'care_team',
    ];

    /** @var array<string, string> */
    private const SCHEMA_VERSIONS = [
        'today' => 'patient-today.v1',
        'pathway' => 'patient-pathway.v1',
        'pathway_events' => 'patient-pathway-events.v1',
        'discharge_readiness' => 'patient-discharge-readiness.v1',
        'rounds_summary' => 'patient-rounds-summary.v1',
        'care_team' => 'patient-care-team.v1',
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'prod.encounters',
        'prod.units',
        'patient_experience.principals',
        'patient_experience.identity_links',
        'patient_experience.encounter_access_grants',
        'patient_experience.sessions',
        'patient_experience.release_policy_versions',
        'patient_experience.source_projection_cursors',
        'patient_experience.encounter_projections',
        'patient_experience.notification_outbox',
        'personal_access_tokens',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly PatientHmac $hmac,
        private readonly PatientProjectionContentGuard $contentGuard,
        private readonly SyntheticPatientProjectionProvisioner $referenceContent,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        string $patientRef = HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
        ?int $encounterId = null,
    ): array {
        $this->assertExecutionSafe();
        $foundation = $this->foundation($patientRef, $encounterId, false);
        $policy = $this->existingPolicy(false);
        $existing = $this->existingDrafts(
            $foundation['encounter'],
            $foundation['grant'],
            $policy,
            false,
        );
        $this->assertNoPublicationOutbox();

        return $this->result(
            committed: false,
            foundation: $foundation,
            policy: $policy,
            created: 0,
            replayed: count($existing),
            action: count($existing) === count(self::KINDS)
                ? 'replay_six_owned_reference_drafts'
                : 'create_six_owned_reference_drafts',
        );
    }

    /** @return array<string, mixed> */
    public function provision(
        string $patientRef = HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
        ?int $encounterId = null,
    ): array {
        $this->assertExecutionSafe();

        return DB::transaction(function () use ($patientRef, $encounterId): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                $this->hmac->digest(
                    'reference-patient-projection-draft-lock-v1',
                    $patientRef.'|'.($encounterId ?? 'sole'),
                ),
            ]);

            $foundation = $this->foundation($patientRef, $encounterId, true);
            $policy = $this->existingPolicy(true) ?? $this->createPolicy();
            $existing = $this->existingDrafts(
                $foundation['encounter'],
                $foundation['grant'],
                $policy,
                true,
            );
            $created = 0;
            $replayed = 0;

            foreach (self::KINDS as $kind) {
                if (isset($existing[$kind])) {
                    $replayed++;

                    continue;
                }

                $this->createDraft(
                    encounter: $foundation['encounter'],
                    grant: $foundation['grant'],
                    policy: $policy,
                    kind: $kind,
                );
                $created++;
            }
            $this->assertNoPublicationOutbox();

            return $this->result(
                committed: true,
                foundation: $foundation,
                policy: $policy,
                created: $created,
                replayed: $replayed,
                action: $created === 0
                    ? 'replayed_six_owned_reference_drafts'
                    : 'created_six_owned_reference_drafts',
            );
        }, 3);
    }

    private function assertExecutionSafe(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('reference_patient_projection_drafts_require_postgresql');
        }

        if ($this->app->environment('local') && ! $this->databaseHostIsLocal()) {
            throw new RuntimeException('reference_patient_projection_drafts_refuse_remote_database_from_local_runtime');
        }

        $this->hmac->assertAvailable();

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('reference_patient_projection_draft_schema_missing');
            }
        }
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   principal: PatientPrincipal,
     *   identity_link: PatientIdentityLink,
     *   grant: PatientEncounterAccessGrant
     * }
     */
    private function foundation(string $patientRef, ?int $encounterId, bool $forUpdate): array
    {
        if (preg_match('/^(demo|sim)-[a-z0-9][a-z0-9-]{7,95}$/', $patientRef) !== 1) {
            throw new RuntimeException('reference_patient_projection_ref_must_be_synthetic');
        }
        if ($encounterId !== null && $encounterId < 1) {
            throw new RuntimeException('reference_patient_projection_encounter_id_invalid');
        }

        $encounterQuery = Encounter::query()->where('patient_ref', $patientRef);
        if ($encounterId !== null) {
            $encounterQuery->whereKey($encounterId);
        }
        if ($forUpdate) {
            $encounterQuery->lockForUpdate();
        }

        /** @var EloquentCollection<int, Encounter> $encounters */
        $encounters = $encounterQuery->orderBy('encounter_id')->get();
        if ($encounters->count() !== 1) {
            throw new RuntimeException('reference_patient_projection_operational_encounter_not_exact');
        }

        $encounter = $encounters->sole();
        if ($encounter->created_by !== HummingbirdReferencePatientProvisioner::CREATED_BY
            || $encounter->is_deleted
            || $encounter->status !== 'active'
            || $encounter->discharged_at !== null
            || $encounter->admitted_at === null
            || $encounter->admitted_at->isFuture()) {
            throw new RuntimeException('reference_patient_projection_operational_encounter_not_safe');
        }
        if (! Unit::query()
            ->whereKey($encounter->unit_id)
            ->where('is_deleted', false)
            ->exists()) {
            throw new RuntimeException('reference_patient_projection_operational_unit_not_active');
        }

        $principalQuery = PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [
                HummingbirdPatientReferenceIdentityProvisioner::OWNER,
            ]);
        if ($forUpdate) {
            $principalQuery->lockForUpdate();
        }
        $principals = $principalQuery->get();
        if ($principals->count() !== 1) {
            throw new RuntimeException('reference_patient_projection_principal_not_exact');
        }
        $principal = $principals->sole();
        if ($principal->principal_type !== 'patient'
            || $principal->status !== 'pending'
            || (bool) $principal->is_active
            || $principal->password !== null
            || $principal->email !== null
            || $principal->phone_e164 !== null) {
            throw new RuntimeException('reference_patient_projection_principal_not_pending');
        }

        $grantQuery = PatientEncounterAccessGrant::query()
            ->where('source_encounter_id', $encounter->getKey())
            ->whereRaw("metadata->>'owner' = ?", [
                HummingbirdPatientReferenceIdentityProvisioner::OWNER,
            ]);
        if ($forUpdate) {
            $grantQuery->lockForUpdate();
        }
        $grants = $grantQuery->get();
        if ($grants->count() !== 1) {
            throw new RuntimeException('reference_patient_projection_grant_not_exact');
        }
        $grant = $grants->sole();
        $requiredScopes = ['today:read', 'pathway:read', 'care_team:read'];
        if ((int) $grant->principal_id !== (int) $principal->getKey()
            || $grant->status !== 'pending'
            || $grant->revoked_at !== null
            || $grant->relationship !== 'self'
            || $grant->purpose_of_use !== 'patient_access'
            || array_diff($requiredScopes, (array) $grant->scopes) !== []) {
            throw new RuntimeException('reference_patient_projection_grant_not_pending');
        }

        $identityQuery = PatientIdentityLink::query()
            ->whereKey($grant->identity_link_id);
        if ($forUpdate) {
            $identityQuery->lockForUpdate();
        }
        $identity = $identityQuery->first();
        if (! $identity instanceof PatientIdentityLink
            || (int) $identity->principal_id !== (int) $principal->getKey()
            || $identity->status !== 'verified'
            || $identity->revoked_at !== null
            || ($identity->provenance['owner'] ?? null)
                !== HummingbirdPatientReferenceIdentityProvisioner::OWNER
            || ($identity->provenance['synthetic'] ?? null) !== true) {
            throw new RuntimeException('reference_patient_projection_identity_not_safe');
        }

        if (PatientSession::query()->where('principal_id', $principal->getKey())->exists()
            || PersonalAccessToken::query()
                ->where('tokenable_type', PatientPrincipal::class)
                ->where('tokenable_id', $principal->getKey())
                ->exists()) {
            throw new RuntimeException('reference_patient_projection_principal_has_access');
        }

        return [
            'encounter' => $encounter,
            'principal' => $principal,
            'identity_link' => $identity,
            'grant' => $grant,
        ];
    }

    private function existingPolicy(bool $forUpdate): ?PatientReleasePolicyVersion
    {
        $query = PatientReleasePolicyVersion::query()->where('version', self::POLICY_VERSION);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $policy = $query->first();
        if (! $policy instanceof PatientReleasePolicyVersion) {
            return null;
        }

        if ($policy->status !== 'draft'
            || $policy->approved_by_actor_ref !== null
            || $policy->approved_at !== null
            || $policy->effective_from !== null
            || $policy->effective_to !== null
            || $policy->disclosure_matrix_version !== 'patient-disclosure-matrix.v1'
            || $policy->content_contract_version !== 'patient-projection.v1'
            || (array) $policy->rules != $this->policyRules()) {
            throw new RuntimeException('reference_patient_projection_policy_not_safe_draft');
        }

        return $policy;
    }

    private function createPolicy(): PatientReleasePolicyVersion
    {
        return PatientReleasePolicyVersion::query()->create([
            'policy_uuid' => $this->uuid('policy'),
            'version' => self::POLICY_VERSION,
            'status' => 'draft',
            'disclosure_matrix_version' => 'patient-disclosure-matrix.v1',
            'content_contract_version' => 'patient-projection.v1',
            'rules' => $this->policyRules(),
        ]);
    }

    /**
     * @return array<string, PatientEncounterProjection>
     */
    private function existingDrafts(
        Encounter $encounter,
        PatientEncounterAccessGrant $grant,
        ?PatientReleasePolicyVersion $policy,
        bool $forUpdate,
    ): array {
        $query = PatientEncounterProjection::query()
            ->where('access_grant_id', $grant->getKey());
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            return [];
        }
        if (! $policy instanceof PatientReleasePolicyVersion || $rows->count() !== count(self::KINDS)) {
            throw new RuntimeException('reference_patient_projection_existing_rows_ambiguous');
        }

        $expected = [];
        foreach (self::KINDS as $kind) {
            $expected[$this->uuid('projection/'.$kind)] = $kind;
        }
        $result = [];
        $sourceObservedAt = Carbon::instance($encounter->admitted_at)->toImmutable();

        foreach ($rows as $projection) {
            $kind = $expected[(string) $projection->projection_uuid] ?? null;
            $expectedScope = PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind] ?? null;
            if ($kind === null
                || isset($result[$kind])
                || $projection->projection_kind !== $kind
                || (int) $projection->release_policy_version_id !== (int) $policy->getKey()
                || $projection->supersedes_projection_id !== null
                || (int) $projection->projection_sequence !== 1
                || $projection->content_schema_version !== self::SCHEMA_VERSIONS[$kind]
                || $projection->release_state !== 'draft'
                || $projection->released_at !== null
                || $projection->source_version !== self::PRODUCER_VERSION
                || $projection->freshness_class !== 'unknown'
                || $projection->required_scope !== $expectedScope
                || array_values((array) $projection->permitted_relationships) !== ['self']
                || ! $projection->source_observed_at?->equalTo($sourceObservedAt)
                || $projection->generated_at === null) {
                throw new RuntimeException('reference_patient_projection_existing_row_not_safe');
            }

            $content = $this->content($kind);
            $expectedDigest = $this->contentGuard->digest(
                $kind,
                self::SCHEMA_VERSIONS[$kind],
                $content,
            );
            $storedContentDigest = $this->contentGuard->digest(
                $kind,
                self::SCHEMA_VERSIONS[$kind],
                (array) $projection->content,
            );
            $expectedTraceDigest = $this->hmac->digest(
                'reference-patient-projection-draft-trace-v1',
                (string) $grant->grant_uuid.'|'.$kind.'|'.$expectedDigest,
            );
            $expectedProvenance = [
                'projection_method' => 'command_owned_synthetic_reference_draft',
                'source_class' => 'synthetic_reference_fixture',
                'input_classes' => ['synthetic_command_owned_encounter'],
                'review_state' => 'draft_synthetic_not_approved',
                'producer_version' => self::PRODUCER_VERSION,
                'trace_digest' => $expectedTraceDigest,
            ];
            if (! hash_equals($expectedDigest, (string) $projection->content_digest)
                || ! hash_equals($expectedDigest, $storedContentDigest)) {
                throw new RuntimeException('reference_patient_projection_existing_content_changed');
            }
            if ((array) $projection->provenance != $expectedProvenance) {
                throw new RuntimeException('reference_patient_projection_existing_provenance_changed');
            }
            if (! $this->uncertaintyMatches($projection)) {
                throw new RuntimeException('reference_patient_projection_existing_uncertainty_changed');
            }

            $cursor = PatientProjectionCursor::query()->find($projection->projection_cursor_id);
            $expectedCursorDigest = $this->hmac->digest(
                'reference-patient-projection-draft-cursor-v1',
                (string) $grant->grant_uuid.'|'.$kind.'|'.$expectedDigest,
            );
            $expectedCursorMetadata = [
                'owner' => self::OWNER,
                'synthetic' => true,
                'draft_only' => true,
                'schema_version' => 1,
            ];
            if (! $cursor instanceof PatientProjectionCursor
                || (string) $cursor->cursor_uuid !== $this->uuid('cursor/'.$kind)
                || $cursor->source_system_key !== self::SOURCE_SYSTEM_KEY
                || $cursor->projection_kind !== $kind
                || $cursor->source_version !== self::PRODUCER_VERSION
                || $cursor->status !== 'projected'
                || ! hash_equals($expectedCursorDigest, (string) $cursor->cursor_digest)
                || (array) $cursor->metadata != $expectedCursorMetadata
                || ! $cursor->source_observed_at?->equalTo($sourceObservedAt)
                || ! $cursor->projected_at?->equalTo($projection->generated_at)) {
                throw new RuntimeException('reference_patient_projection_existing_cursor_not_safe');
            }

            $result[$kind] = $projection;
        }

        if (array_diff(self::KINDS, array_keys($result)) !== []) {
            throw new RuntimeException('reference_patient_projection_existing_kind_missing');
        }

        return $result;
    }

    /** @return array<string, bool|string> */
    private function policyRules(): array
    {
        return [
            'owner' => self::OWNER,
            'synthetic' => true,
            'draft_only' => true,
            'patient_visible' => false,
            'clinical_use' => false,
        ];
    }

    private function uncertaintyMatches(PatientEncounterProjection $projection): bool
    {
        $uncertainty = (array) $projection->uncertainty;
        if (count($uncertainty) !== 4
            || ($uncertainty['level'] ?? null) !== 'unknown'
            || ($uncertainty['explanation'] ?? null)
                !== 'This is synthetic demonstration content and is not clinical information.'
            || ($uncertainty['can_change'] ?? null) !== true
            || ! is_string($uncertainty['reviewed_at'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($uncertainty['reviewed_at'])
                ->equalTo($projection->generated_at);
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertNoPublicationOutbox(): void
    {
        $projectionUuids = array_map(
            fn (string $kind): string => $this->uuid('projection/'.$kind),
            self::KINDS,
        );

        if (PatientNotificationOutbox::query()
            ->whereIn('aggregate_uuid', $projectionUuids)
            ->where('aggregate_type', 'patient_encounter_projection')
            ->where('event_type', 'patient.projection.released')
            ->where('destination', 'projection')
            ->exists()) {
            throw new RuntimeException('reference_patient_projection_publication_outbox_exists');
        }
    }

    private function createDraft(
        Encounter $encounter,
        PatientEncounterAccessGrant $grant,
        PatientReleasePolicyVersion $policy,
        string $kind,
    ): PatientEncounterProjection {
        // The projection schema stores this timestamp at second precision.
        // Normalize before also embedding it in JSON so replay can compare the
        // two representations exactly rather than tolerating truncation.
        $generatedAt = now()->startOfSecond();
        $sourceObservedAt = Carbon::instance($encounter->admitted_at)->toImmutable();
        $content = $this->content($kind);
        $contentDigest = $this->contentGuard->digest(
            $kind,
            self::SCHEMA_VERSIONS[$kind],
            $content,
        );
        $cursor = PatientProjectionCursor::query()->create([
            'cursor_uuid' => $this->uuid('cursor/'.$kind),
            'source_system_key' => self::SOURCE_SYSTEM_KEY,
            'projection_kind' => $kind,
            'cursor_digest' => $this->hmac->digest(
                'reference-patient-projection-draft-cursor-v1',
                (string) $grant->grant_uuid.'|'.$kind.'|'.$contentDigest,
            ),
            'source_version' => self::PRODUCER_VERSION,
            'status' => 'projected',
            'source_observed_at' => $sourceObservedAt,
            'projected_at' => $generatedAt,
            'metadata' => [
                'owner' => self::OWNER,
                'synthetic' => true,
                'draft_only' => true,
                'schema_version' => 1,
            ],
        ]);

        return PatientEncounterProjection::query()->create([
            'projection_uuid' => $this->uuid('projection/'.$kind),
            'access_grant_id' => $grant->getKey(),
            'release_policy_version_id' => $policy->getKey(),
            'projection_cursor_id' => $cursor->getKey(),
            'projection_kind' => $kind,
            'projection_sequence' => 1,
            'content' => $content,
            'content_schema_version' => self::SCHEMA_VERSIONS[$kind],
            'content_digest' => $contentDigest,
            'source_version' => self::PRODUCER_VERSION,
            'provenance' => [
                'projection_method' => 'command_owned_synthetic_reference_draft',
                'source_class' => 'synthetic_reference_fixture',
                'input_classes' => ['synthetic_command_owned_encounter'],
                'review_state' => 'draft_synthetic_not_approved',
                'producer_version' => self::PRODUCER_VERSION,
                'trace_digest' => $this->hmac->digest(
                    'reference-patient-projection-draft-trace-v1',
                    (string) $grant->grant_uuid.'|'.$kind.'|'.$contentDigest,
                ),
            ],
            'source_observed_at' => $sourceObservedAt,
            'generated_at' => $generatedAt,
            'freshness_class' => 'unknown',
            'uncertainty' => [
                'level' => 'unknown',
                'explanation' => 'This is synthetic demonstration content and is not clinical information.',
                'can_change' => true,
                'reviewed_at' => $generatedAt->toISOString(),
            ],
            'required_scope' => PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind],
            'permitted_relationships' => ['self'],
            'release_state' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function content(string $kind): array
    {
        $content = $this->referenceContent->contentFor(self::CONTENT_SEED, $kind);
        $content['notices'] = array_values(array_unique([
            self::SYNTHETIC_NOTICE,
            ...((array) ($content['notices'] ?? [])),
        ]));

        return $content;
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

    private function uuid(string $name): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://zephyrus.acumenus.net/reference-patient-draft/'.$name,
        )->toString();
    }

    /**
     * @param  array{
     *   encounter: Encounter,
     *   principal: PatientPrincipal,
     *   identity_link: PatientIdentityLink,
     *   grant: PatientEncounterAccessGrant
     * }  $foundation
     * @return array<string, mixed>
     */
    private function result(
        bool $committed,
        array $foundation,
        ?PatientReleasePolicyVersion $policy,
        int $created,
        int $replayed,
        string $action,
    ): array {
        return [
            'committed' => $committed,
            'action' => $action,
            'owner' => self::OWNER,
            'source' => [
                'encounter_id' => (int) $foundation['encounter']->getKey(),
                'encounter_status' => (string) $foundation['encounter']->status,
                'operational_owner' => (string) $foundation['encounter']->created_by,
            ],
            'principal' => [
                'status' => (string) $foundation['principal']->status,
                'is_active' => (bool) $foundation['principal']->is_active,
                'session_count' => 0,
                'token_count' => 0,
            ],
            'access_grant' => [
                'status' => (string) $foundation['grant']->status,
            ],
            'policy' => [
                'version' => self::POLICY_VERSION,
                'status' => $policy?->status ?? 'planned_draft',
            ],
            'projections' => [
                'kinds' => self::KINDS,
                'count' => $created + $replayed,
                'created' => $created,
                'replayed' => $replayed,
                'release_state' => 'draft',
                'patient_visible' => false,
                'content_emitted' => false,
                'identifiers_emitted' => false,
            ],
        ];
    }
}
