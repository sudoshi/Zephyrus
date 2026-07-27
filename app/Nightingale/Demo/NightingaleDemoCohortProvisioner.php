<?php

namespace App\Nightingale\Demo;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\Patient\PatientSession;
use App\Models\Unit;
use App\Services\Patient\PatientHmac;
use App\Services\Patient\Projection\PatientProjectionContentGuard;
use App\Services\Patient\Projection\PatientProjectionDisclosureService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Atomically provisions only the five code-owned, synthetic Nightingale
 * investor-demo accounts.
 *
 * It never reads raw clinical content. Catalog rows are immutable binding
 * evidence; patient-visible payloads are separately generated synthetic copy.
 */
final class NightingaleDemoCohortProvisioner
{
    private const KINDS = [
        'today',
        'pathway',
        'pathway_events',
        'discharge_readiness',
        'rounds_summary',
        'care_team',
    ];

    private const SCHEMA_VERSIONS = [
        'today' => 'patient-today.v1',
        'pathway' => 'patient-pathway.v1',
        'pathway_events' => 'patient-pathway-events.v1',
        'discharge_readiness' => 'patient-discharge-readiness.v1',
        'rounds_summary' => 'patient-rounds-summary.v1',
        'care_team' => 'patient-care-team.v1',
    ];

    private const REQUIRED_TABLES = [
        'prod.units',
        'prod.encounters',
        'care_pathways.catalog_releases',
        'care_pathways.definitions',
        'care_pathways.versions',
        'care_pathways.drg_codebook_entries',
        'care_pathways.drg_mappings',
        'care_pathways.stage_definitions',
        'care_pathways.milestone_definitions',
        'patient_experience.principals',
        'patient_experience.identity_links',
        'patient_experience.encounter_access_grants',
        'patient_experience.enrollment_challenges',
        'patient_experience.sessions',
        'patient_experience.access_audit_events',
        'patient_experience.notification_devices',
        'patient_experience.release_policy_versions',
        'patient_experience.source_projection_cursors',
        'patient_experience.encounter_projections',
        'patient_experience.notification_outbox',
        'personal_access_tokens',
    ];

    private const DEMO_NOTICE = 'DEMO — NOT FOR CLINICAL USE. This is synthetic information for a product demonstration. It is not a medical record, care instruction, diagnosis, order, or promise. For urgent help, use the bedside call button or speak with staff.';

    public function __construct(
        private readonly Application $app,
        private readonly PatientHmac $hmac,
        private readonly PatientProjectionContentGuard $contentGuard,
        private readonly SyntheticPatientProjectionProvisioner $syntheticContent,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $unitId): array
    {
        $this->assertFoundationAvailable(requireMutationPermission: false);
        $unit = $this->resolveUnit($unitId, false);
        $catalog = $this->catalogBindings(false);
        $referenceSampleState = $this->referenceSampleState($unit, false);
        $existing = $this->existingCounts();

        return $this->result(
            committed: false,
            action: 'preview',
            unit: $unit,
            catalog: $catalog,
            existing: $existing,
            referenceSampleState: $referenceSampleState,
        );
    }

    /** @return array<string, mixed> */
    public function apply(int $unitId, string $password): array
    {
        $this->assertFoundationAvailable(requireMutationPermission: true);
        $this->assertPassword($password);

        return DB::transaction(function () use ($unitId, $password): array {
            if (! $this->app->environment('testing')) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                NightingaleDemoCohort::VERSION,
            ]);

            $unit = $this->resolveUnit($unitId, true);
            $catalog = $this->catalogBindings(true);
            $this->referenceSampleState($unit, true);
            $policy = $this->resolvePolicy();

            foreach (NightingaleDemoCohort::MEMBERS as $alias => $scenario) {
                $binding = $catalog['members'][$alias];
                $encounter = $this->resolveEncounter($unit, $alias, $scenario);
                $principal = $this->resolvePrincipal($alias, $scenario, $binding);
                $identity = $this->resolveIdentity($principal, $encounter, $alias);
                $grant = $this->resolveGrant($principal, $identity, $encounter, $alias);
                $this->revokeSessionsForCredentialRotation($principal);
                $principal->password = $password;
                $principal->last_authenticated_at = null;
                $principal->save();

                foreach (self::KINDS as $kind) {
                    $this->resolveProjection(
                        principal: $principal,
                        grant: $grant,
                        encounter: $encounter,
                        policy: $policy,
                        alias: $alias,
                        scenario: $scenario,
                        binding: $binding,
                        kind: $kind,
                    );
                }
            }

            $ownedPrincipalIds = $this->ownedPrincipals(forUpdate: false)->modelKeys();
            if (PatientSession::query()
                ->whereIn('principal_id', $ownedPrincipalIds)
                ->where('status', 'active')
                ->exists()
                || PersonalAccessToken::query()
                    ->where('tokenable_type', PatientPrincipal::class)
                    ->whereIn('tokenable_id', $ownedPrincipalIds)
                    ->exists()) {
                throw new RuntimeException('nightingale_demo_credential_rotation_incomplete');
            }

            $verification = $this->verifyWithinTransaction($catalog);

            return $this->result(
                committed: true,
                action: 'applied',
                unit: $unit,
                catalog: $catalog,
                existing: $verification,
                referenceSampleState: 'adopted_into_demo1',
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function verify(): array
    {
        $this->assertFoundationAvailable(requireMutationPermission: false);
        $catalog = $this->catalogBindings(false);
        $verification = DB::transaction(
            fn (): array => $this->verifyWithinTransaction($catalog),
            3,
        );

        return [
            'committed' => false,
            'action' => 'verified',
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'catalog_release_uuid' => $catalog['release']['catalog_release_uuid'],
            'accounts' => $verification,
            'demo1_reference_sample' => 'adopted_into_demo1',
            'credential_material_emitted' => false,
            'clinical_use_permitted' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function suspend(): array
    {
        $this->assertFoundationAvailable(requireMutationPermission: true);

        return DB::transaction(function (): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                NightingaleDemoCohort::VERSION,
            ]);

            $principals = $this->ownedPrincipals(forUpdate: true);
            if ($principals->count() !== count(NightingaleDemoCohort::MEMBERS)) {
                throw new RuntimeException('nightingale_demo_cohort_principal_cardinality_invalid');
            }

            foreach ($principals as $principal) {
                $this->revokeSessionsForCredentialRotation($principal);
                PatientEncounterAccessGrant::query()
                    ->where('principal_id', $principal->getKey())
                    ->whereRaw("metadata->>'owner' = ?", [NightingaleDemoCohort::OWNER])
                    ->lockForUpdate()
                    ->update(['status' => 'suspended']);
                $principal->forceFill([
                    'status' => 'suspended',
                    'is_active' => false,
                    'password' => null,
                    'last_authenticated_at' => null,
                ])->save();
            }

            return [
                'committed' => true,
                'action' => 'suspended',
                'cohort_version' => NightingaleDemoCohort::VERSION,
                'accounts_suspended' => $principals->count(),
                'credential_material_emitted' => false,
                'clinical_use_permitted' => false,
            ];
        }, 3);
    }

    private function assertFoundationAvailable(bool $requireMutationPermission): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('nightingale_demo_cohort_requires_postgresql');
        }
        if ($requireMutationPermission
            && ! $this->app->environment('testing')
            && ! (bool) config('nightingale.demo_cohort.provisioning_enabled', false)) {
            throw new RuntimeException('nightingale_demo_cohort_provisioning_disabled');
        }
        if ($this->app->environment('local') && ! $this->databaseHostIsLocal()) {
            throw new RuntimeException('nightingale_demo_cohort_refuses_remote_database_from_local_runtime');
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('nightingale_demo_cohort_schema_missing');
            }
        }

        $this->hmac->assertAvailable();
        if (trim((string) config('app.key')) === '') {
            throw new RuntimeException('nightingale_demo_cohort_encryption_key_unavailable');
        }
    }

    private function databaseHostIsLocal(): bool
    {
        $connection = (string) config('database.default');
        $host = Str::lower(trim((string) config("database.connections.{$connection}.host")));

        return $host === '' || in_array($host, [
            'localhost',
            '127.0.0.1',
            '::1',
            'zephyrus-test-pg',
        ], true);
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 8
            || strlen($password) > 128
            || trim($password) !== $password
            || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new RuntimeException('nightingale_demo_cohort_password_invalid');
        }
    }

    private function resolveUnit(int $unitId, bool $forUpdate): Unit
    {
        if ($unitId < 1) {
            throw new RuntimeException('nightingale_demo_cohort_unit_invalid');
        }

        $query = Unit::query()->whereKey($unitId)->where('is_deleted', false);

        return ($forUpdate ? $query->lockForUpdate() : $query)->first()
            ?? throw new RuntimeException('nightingale_demo_cohort_unit_unavailable');
    }

    private function referenceSampleState(Unit $unit, bool $forUpdate): string
    {
        $encounterQuery = Encounter::query()
            ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF);
        if ($forUpdate) {
            $encounterQuery->lockForUpdate();
        }
        $encounters = $encounterQuery->get();
        if ($encounters->count() !== 1) {
            throw new RuntimeException('nightingale_demo_reference_sample_encounter_not_exact');
        }
        $encounter = $encounters->sole();
        if ($encounter->created_by !== NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER
            || (int) $encounter->unit_id !== (int) $unit->getKey()
            || $encounter->bed_id !== null
            || $encounter->admitted_at === null
            || (int) $encounter->acuity_tier !== 2
            || $encounter->status !== 'active'
            || $encounter->discharged_at !== null
            || $encounter->is_deleted) {
            throw new RuntimeException('nightingale_demo_reference_sample_encounter_changed');
        }

        $principalQuery = PatientPrincipal::query()
            ->where(function ($query): void {
                $query
                    ->whereRaw(
                        "preferences #>> '{provisioning,owner}' = ?",
                        [NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER],
                    )
                    ->orWhere(function ($owned): void {
                        $owned
                            ->whereRaw(
                                "preferences #>> '{provisioning,product}' = ?",
                                [NightingaleDemoCohort::PRODUCT],
                            )
                            ->whereRaw(
                                "preferences #>> '{provisioning,owner}' = ?",
                                [NightingaleDemoCohort::OWNER],
                            )
                            ->whereRaw(
                                "preferences #>> '{provisioning,cohort_version}' = ?",
                                [NightingaleDemoCohort::VERSION],
                            )
                            ->whereRaw(
                                "preferences #>> '{provisioning,demo_username}' = ?",
                                ['demo1'],
                            );
                    });
            });
        if ($forUpdate) {
            $principalQuery->lockForUpdate();
        }
        $principals = $principalQuery->get();
        if ($principals->count() !== 1) {
            throw new RuntimeException('nightingale_demo_reference_sample_principal_not_exact');
        }
        $principal = $principals->sole();
        $state = 'ready_for_demo1_adoption';
        if (NightingaleDemoCohort::preferencesAreOwned((array) $principal->preferences, 'demo1')) {
            if ($principal->display_name !== NightingaleDemoCohort::MEMBERS['demo1']['display_name']
                || $principal->email !== 'demo1@nightingale.demo.invalid') {
                throw new RuntimeException('nightingale_demo_reference_sample_adoption_changed');
            }
            $state = 'adopted_into_demo1';
        } else {
            $this->assertPristineReferencePrincipal($principal);
        }

        $expectedModifiedBy = $state === 'adopted_into_demo1'
            ? NightingaleDemoCohort::OWNER
            : NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER;
        if ($encounter->modified_by !== $expectedModifiedBy) {
            throw new RuntimeException('nightingale_demo_reference_sample_encounter_changed');
        }

        return $state;
    }

    private function assertPristineReferencePrincipal(PatientPrincipal $principal): void
    {
        $preferences = (array) $principal->preferences;
        $provisioning = $preferences['provisioning'] ?? null;
        if ($principal->principal_type !== 'patient'
            || $principal->display_name !== NightingaleDemoCohort::REFERENCE_SAMPLE_DISPLAY_NAME
            || $principal->status !== 'pending'
            || $principal->is_active
            || $principal->email !== null
            || $principal->phone_e164 !== null
            || $principal->password !== null
            || $principal->email_verified_at !== null
            || $principal->phone_verified_at !== null
            || $principal->last_authenticated_at !== null
            || $principal->locked_at !== null
            || $principal->closed_at !== null
            || ($preferences['synthetic'] ?? null) !== true
            || ($preferences['product'] ?? null) !== NightingaleDemoCohort::PRODUCT
            || ! is_array($provisioning)
            || ($provisioning['owner'] ?? null) !== NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER
            || ($provisioning['mode'] ?? null) !== NightingaleDemoCohort::REFERENCE_SAMPLE_MODE
            || ($provisioning['source_template_product'] ?? null) !== NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT
            || ($provisioning['source_template_owner'] ?? null) !== NightingaleDemoCohort::REFERENCE_SOURCE_OWNER
            || $principal->identityLinks()->exists()
            || $principal->encounterAccessGrants()->exists()
            || $principal->enrollmentChallenges()->exists()
            || $principal->patientSessions()->exists()
            || $principal->accessAuditEvents()->exists()
            || $principal->notificationDevices()->exists()
            || $principal->notificationOutboxMessages()->exists()
            || $principal->tokens()->exists()) {
            throw new RuntimeException('nightingale_demo_reference_sample_principal_changed');
        }
    }

    /**
     * @return array{
     *   release: array<string, mixed>,
     *   members: array<string, array<string, mixed>>
     * }
     */
    private function catalogBindings(bool $forUpdate): array
    {
        $releaseQuery = DB::table('care_pathways.catalog_releases')
            ->where('catalog_release_uuid', NightingaleDemoCohort::CATALOG_RELEASE_UUID)
            ->where('dataset_key', NightingaleDemoCohort::CATALOG_DATASET_KEY);
        if ($forUpdate) {
            $releaseQuery->lockForUpdate();
        }
        $releases = $releaseQuery->get();
        if ($releases->count() !== 1) {
            throw new RuntimeException('nightingale_demo_catalog_release_not_exact');
        }
        $release = $releases->sole();
        if ($release->grouper_version !== NightingaleDemoCohort::CATALOG_GROUPER_VERSION
            || (int) $release->pathway_count !== NightingaleDemoCohort::CATALOG_PATHWAY_COUNT
            || $release->state !== 'inactive'
            || (bool) $release->clinical_signoff_complete
            || (int) $release->clinical_signoff_count !== 0
            || $release->activated_at !== null
            || $release->withdrawn_at !== null) {
            throw new RuntimeException('nightingale_demo_catalog_release_state_changed');
        }

        $members = [];
        foreach (NightingaleDemoCohort::MEMBERS as $alias => $scenario) {
            $query = DB::table('care_pathways.definitions as definitions')
                ->join('care_pathways.versions as versions', 'versions.pathway_definition_id', '=', 'definitions.pathway_definition_id')
                ->join('care_pathways.drg_mappings as mappings', 'mappings.pathway_version_id', '=', 'versions.pathway_version_id')
                ->join('care_pathways.drg_codebook_entries as codebook', 'codebook.drg_codebook_entry_id', '=', 'mappings.drg_codebook_entry_id')
                ->where('definitions.pathway_key', $scenario['pathway_key'])
                ->where('versions.catalog_release_id', $release->catalog_release_id)
                ->where('codebook.catalog_release_id', $release->catalog_release_id)
                ->where('codebook.ms_drg', $scenario['ms_drg'])
                ->select([
                    'definitions.pathway_key',
                    'definitions.canonical_name',
                    'versions.pathway_version_id',
                    'versions.pathway_version_uuid',
                    'versions.source_digest',
                    'versions.content_digest',
                    'versions.release_disposition',
                    'versions.clinical_signoff_status',
                    'versions.institutional_approval_status',
                    'versions.activation_status',
                    'mappings.mapping_role',
                    'codebook.ms_drg',
                    'codebook.title as drg_title',
                    'codebook.entry_digest as drg_entry_digest',
                ]);
            if ($forUpdate) {
                $query->lockForUpdate();
            }
            $rows = $query->get();
            if ($rows->count() !== 1) {
                throw new RuntimeException("nightingale_demo_catalog_{$alias}_binding_not_exact");
            }
            $row = $rows->sole();
            $stageCount = DB::table('care_pathways.stage_definitions')
                ->where('pathway_version_id', $row->pathway_version_id)
                ->where('review_state', 'draft')
                ->count();
            $milestoneCount = DB::table('care_pathways.milestone_definitions')
                ->where('pathway_version_id', $row->pathway_version_id)
                ->where('review_state', 'draft')
                ->count();

            if ($row->drg_title !== $scenario['drg_title']
                || $row->release_disposition !== 'Ready for institutional clinician signoff'
                || $row->clinical_signoff_status !== 'Not clinically approved — institutional SME signoff required'
                || $row->institutional_approval_status !== 'not_reviewed'
                || $row->activation_status !== 'inactive'
                || $row->mapping_role === 'excluded'
                || $stageCount !== $scenario['stage_count']
                || $milestoneCount !== $scenario['milestone_count']) {
                throw new RuntimeException("nightingale_demo_catalog_{$alias}_binding_changed");
            }

            $members[$alias] = [
                'pathway_version_id' => (int) $row->pathway_version_id,
                'pathway_version_uuid' => (string) $row->pathway_version_uuid,
                'pathway_key' => (string) $row->pathway_key,
                'canonical_name' => (string) $row->canonical_name,
                'source_digest' => (string) $row->source_digest,
                'content_digest' => (string) $row->content_digest,
                'ms_drg' => (string) $row->ms_drg,
                'drg_title' => (string) $row->drg_title,
                'drg_entry_digest' => (string) $row->drg_entry_digest,
                'stage_count' => $stageCount,
                'milestone_count' => $milestoneCount,
                'clinical_approval' => 'not_approved_demo_binding_only',
            ];
        }

        return [
            'release' => [
                'catalog_release_id' => (int) $release->catalog_release_id,
                'catalog_release_uuid' => (string) $release->catalog_release_uuid,
                'dataset_key' => (string) $release->dataset_key,
                'state' => (string) $release->state,
                'clinical_signoff_complete' => false,
            ],
            'members' => $members,
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function resolveEncounter(
        Unit $unit,
        string $alias,
        array $scenario,
    ): Encounter {
        $matches = Encounter::query()
            ->where('patient_ref', $scenario['patient_ref'])
            ->lockForUpdate()
            ->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("nightingale_demo_{$alias}_encounter_ambiguous");
        }
        $encounter = $matches->first();
        $isReferenceSample = $alias === 'demo1'
            && $encounter instanceof Encounter
            && $encounter->created_by === NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER;
        if ($encounter instanceof Encounter
            && $encounter->created_by !== NightingaleDemoCohort::OWNER
            && ! $isReferenceSample) {
            throw new RuntimeException("nightingale_demo_{$alias}_encounter_foreign_owned");
        }

        if (! $encounter instanceof Encounter) {
            if ($alias === 'demo1') {
                throw new RuntimeException('nightingale_demo_reference_sample_encounter_missing');
            }

            return Encounter::query()->create([
                'patient_ref' => $scenario['patient_ref'],
                'unit_id' => $unit->getKey(),
                'bed_id' => null,
                'admitted_at' => now()->subDays(2),
                'expected_discharge_date' => now()->addDays(2)->toDateString(),
                'acuity_tier' => 2,
                'status' => 'active',
                'created_by' => NightingaleDemoCohort::OWNER,
                'modified_by' => NightingaleDemoCohort::OWNER,
                'is_deleted' => false,
            ]);
        }

        if ((int) $encounter->unit_id !== (int) $unit->getKey()
            || $encounter->bed_id !== null
            || $encounter->admitted_at === null
            || (int) $encounter->acuity_tier !== 2
            || $encounter->status !== 'active'
            || $encounter->discharged_at !== null
            || $encounter->is_deleted
            || ($isReferenceSample
                ? ! in_array($encounter->modified_by, [
                    NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
                    NightingaleDemoCohort::OWNER,
                ], true)
                : $encounter->modified_by !== NightingaleDemoCohort::OWNER)) {
            throw new RuntimeException("nightingale_demo_{$alias}_encounter_changed");
        }

        if ($isReferenceSample
            && $encounter->modified_by === NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER) {
            $encounter->forceFill([
                'modified_by' => NightingaleDemoCohort::OWNER,
            ])->save();
        }

        return $encounter->fresh();
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $binding
     */
    private function resolvePrincipal(
        string $alias,
        array $scenario,
        array $binding,
    ): PatientPrincipal {
        $principalUuid = $this->uuid($alias.'/principal');
        $matches = PatientPrincipal::query()
            ->where(function ($query) use ($principalUuid, $alias): void {
                $query
                    ->where('principal_uuid', $principalUuid)
                    ->orWhereRaw("preferences #>> '{provisioning,demo_username}' = ?", [$alias]);
                if ($alias === 'demo1') {
                    $query->orWhereRaw(
                        "preferences #>> '{provisioning,owner}' = ?",
                        [NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER],
                    );
                }
            })
            ->lockForUpdate()
            ->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("nightingale_demo_{$alias}_principal_ambiguous");
        }

        $preferences = $this->principalPreferences($alias, $binding);
        $principal = $matches->first();
        if (! $principal instanceof PatientPrincipal) {
            if ($alias === 'demo1') {
                throw new RuntimeException('nightingale_demo_reference_sample_principal_missing');
            }

            return PatientPrincipal::query()->create([
                'principal_uuid' => $principalUuid,
                'principal_type' => 'patient',
                'display_name' => $scenario['display_name'],
                'email' => $alias.'@nightingale.demo.invalid',
                'status' => 'active',
                'is_active' => true,
                'preferences' => $preferences,
                'locale' => 'en-US',
                'timezone' => 'America/New_York',
            ]);
        }

        $isPristineReference = $alias === 'demo1'
            && (($principal->preferences['provisioning']['owner'] ?? null)
                === NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER);
        if ($isPristineReference) {
            $this->assertPristineReferencePrincipal($principal);
            $principal->forceFill([
                'display_name' => $scenario['display_name'],
                'email' => $alias.'@nightingale.demo.invalid',
                'status' => 'active',
                'is_active' => true,
                'preferences' => $preferences,
                'locked_at' => null,
                'closed_at' => null,
            ])->save();

            return $principal;
        }

        $principalUuidMatches = $alias === 'demo1'
            && NightingaleDemoCohort::referenceSampleLineageIsExact(
                (array) ($principal->preferences['provisioning'] ?? []),
            )
            ? true
            : (string) $principal->principal_uuid === $principalUuid;
        if (! $principalUuidMatches
            || $principal->principal_type !== 'patient'
            || $principal->display_name !== $scenario['display_name']
            || $principal->email !== $alias.'@nightingale.demo.invalid'
            || ! NightingaleDemoCohort::preferencesAreOwned((array) $principal->preferences, $alias)
            || $this->canonicalJson((array) $principal->preferences) !== $this->canonicalJson($preferences)) {
            throw new RuntimeException("nightingale_demo_{$alias}_principal_changed");
        }

        $principal->forceFill([
            'status' => 'active',
            'is_active' => true,
            'locked_at' => null,
            'closed_at' => null,
        ])->save();

        return $principal;
    }

    /** @param array<string, mixed> $binding */
    private function principalPreferences(string $alias, array $binding): array
    {
        return [
            'provisioning' => [
                'product' => NightingaleDemoCohort::PRODUCT,
                'environment_class' => NightingaleDemoCohort::ENVIRONMENT_CLASS,
                'owner' => NightingaleDemoCohort::OWNER,
                'cohort_version' => NightingaleDemoCohort::VERSION,
                'demo_username' => $alias,
                'synthetic' => true,
                'clinical_use_permitted' => false,
                'automatic_enrollment' => false,
                'catalog_binding' => [
                    'catalog_release_uuid' => NightingaleDemoCohort::CATALOG_RELEASE_UUID,
                    'pathway_version_uuid' => $binding['pathway_version_uuid'],
                    'pathway_key' => $binding['pathway_key'],
                    'ms_drg' => $binding['ms_drg'],
                    'drg_title' => $binding['drg_title'],
                    'stage_count' => $binding['stage_count'],
                    'milestone_count' => $binding['milestone_count'],
                    'clinical_approval' => 'not_approved_demo_binding_only',
                ],
                ...($alias === 'demo1' ? [
                    'reference_sample_adoption' => [
                        'adopted_from_owner' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
                        'adopted_patient_ref' => NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF,
                        'source_template_product' => NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT,
                        'source_template_owner' => NightingaleDemoCohort::REFERENCE_SOURCE_OWNER,
                        'source_mode' => NightingaleDemoCohort::REFERENCE_SAMPLE_MODE,
                    ],
                ] : []),
            ],
            'presentation' => [
                'demo_notice' => NightingaleDemoCohort::DEMO_NOTICE,
            ],
        ];
    }

    private function resolveIdentity(
        PatientPrincipal $principal,
        Encounter $encounter,
        string $alias,
    ): PatientIdentityLink {
        $uuid = $this->uuid($alias.'/identity-link');
        $digest = $this->hmac->digest(
            'nightingale-demo-identity-v1',
            NightingaleDemoCohort::SOURCE_SYSTEM_KEY."\0".$encounter->patient_ref,
        );
        $matches = PatientIdentityLink::query()
            ->where(function ($query) use ($uuid, $digest): void {
                $query->where('identity_link_uuid', $uuid)->orWhere('source_subject_digest', $digest);
            })
            ->lockForUpdate()
            ->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("nightingale_demo_{$alias}_identity_ambiguous");
        }
        $identity = $matches->first();
        if (! $identity instanceof PatientIdentityLink) {
            return PatientIdentityLink::query()->create([
                'identity_link_uuid' => $uuid,
                'principal_id' => $principal->getKey(),
                'source_system_key' => NightingaleDemoCohort::SOURCE_SYSTEM_KEY,
                'encrypted_source_subject' => (string) $encounter->patient_ref,
                'encryption_key_version' => NightingaleDemoCohort::ENCRYPTION_KEY_VERSION,
                'source_subject_digest' => $digest,
                'digest_algorithm' => 'hmac-sha256',
                'linkage_method' => 'encounter_enrollment',
                'status' => 'verified',
                'assurance_level' => 'synthetic-demo-code-owned',
                'provenance' => [
                    'owner' => NightingaleDemoCohort::OWNER,
                    'cohort_version' => NightingaleDemoCohort::VERSION,
                    'synthetic' => true,
                    'clinical_use_permitted' => false,
                ],
                'verified_at' => now(),
            ]);
        }

        if ((int) $identity->principal_id !== (int) $principal->getKey()
            || (string) $identity->identity_link_uuid !== $uuid
            || $identity->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
            || $identity->source_subject_digest !== $digest
            || $identity->status !== 'verified'
            || $identity->revoked_at !== null
            || ($identity->provenance['owner'] ?? null) !== NightingaleDemoCohort::OWNER
            || ($identity->provenance['synthetic'] ?? null) !== true
            || $identity->encrypted_source_subject !== (string) $encounter->patient_ref) {
            throw new RuntimeException("nightingale_demo_{$alias}_identity_changed");
        }

        return $identity;
    }

    private function resolveGrant(
        PatientPrincipal $principal,
        PatientIdentityLink $identity,
        Encounter $encounter,
        string $alias,
    ): PatientEncounterAccessGrant {
        $uuid = $this->uuid($alias.'/grant');
        $encounterUuid = $this->uuid($alias.'/encounter');
        $digest = $this->hmac->digest(
            'nightingale-demo-encounter-v1',
            NightingaleDemoCohort::SOURCE_SYSTEM_KEY."\0prod.encounters/".$encounter->getKey(),
        );
        $matches = PatientEncounterAccessGrant::query()
            ->where(function ($query) use ($uuid, $encounterUuid, $digest): void {
                $query
                    ->where('grant_uuid', $uuid)
                    ->orWhere('encounter_uuid', $encounterUuid)
                    ->orWhere('source_encounter_ref_digest', $digest);
            })
            ->lockForUpdate()
            ->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("nightingale_demo_{$alias}_grant_ambiguous");
        }
        $metadata = [
            'owner' => NightingaleDemoCohort::OWNER,
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'product' => NightingaleDemoCohort::PRODUCT,
            'environment_class' => NightingaleDemoCohort::ENVIRONMENT_CLASS,
            'demo_username' => $alias,
            'synthetic' => true,
            'clinical_use_permitted' => false,
        ];
        $grant = $matches->first();
        if (! $grant instanceof PatientEncounterAccessGrant) {
            return PatientEncounterAccessGrant::query()->create([
                'grant_uuid' => $uuid,
                'principal_id' => $principal->getKey(),
                'identity_link_id' => $identity->getKey(),
                'encounter_uuid' => $encounterUuid,
                'source_encounter_id' => $encounter->getKey(),
                'encrypted_source_encounter_ref' => 'prod.encounters/'.$encounter->getKey(),
                'source_encounter_ref_digest' => $digest,
                'source_system_key' => NightingaleDemoCohort::SOURCE_SYSTEM_KEY,
                'relationship' => 'self',
                'scopes' => ['today:read', 'pathway:read', 'care_team:read'],
                'purpose_of_use' => 'patient_access',
                'status' => 'active',
                'valid_from' => now()->subSecond(),
                'issued_by_actor_type' => 'system',
                'issued_by_actor_ref' => NightingaleDemoCohort::OWNER,
                'grant_reason' => 'Synthetic Nightingale investor demonstration only; not for clinical use.',
                'version' => 1,
                'metadata' => $metadata,
            ]);
        }

        if ((int) $grant->principal_id !== (int) $principal->getKey()
            || (int) $grant->identity_link_id !== (int) $identity->getKey()
            || (int) $grant->source_encounter_id !== (int) $encounter->getKey()
            || (string) $grant->grant_uuid !== $uuid
            || (string) $grant->encounter_uuid !== $encounterUuid
            || $grant->source_encounter_ref_digest !== $digest
            || $grant->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
            || $grant->relationship !== 'self'
            || $grant->purpose_of_use !== 'patient_access'
            || $this->canonicalJson((array) $grant->metadata) !== $this->canonicalJson($metadata)
            || $grant->encrypted_source_encounter_ref !== 'prod.encounters/'.$encounter->getKey()) {
            throw new RuntimeException("nightingale_demo_{$alias}_grant_changed");
        }

        $grant->forceFill([
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by_actor_type' => null,
            'revoked_by_actor_ref' => null,
            'revocation_reason' => null,
        ])->save();

        return $grant;
    }

    private function resolvePolicy(): PatientReleasePolicyVersion
    {
        $uuid = $this->uuid('shared/release-policy');
        $rules = [
            'owner' => NightingaleDemoCohort::OWNER,
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'synthetic_only' => true,
            'clinical_use_permitted' => false,
            'permitted_relationships' => ['self'],
            'required_notice' => NightingaleDemoCohort::DEMO_NOTICE,
        ];
        $matches = PatientReleasePolicyVersion::query()
            ->where('version', NightingaleDemoCohort::RELEASE_POLICY_VERSION)
            ->lockForUpdate()
            ->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('nightingale_demo_release_policy_ambiguous');
        }
        $policy = $matches->first();
        if (! $policy instanceof PatientReleasePolicyVersion) {
            return PatientReleasePolicyVersion::query()->create([
                'policy_uuid' => $uuid,
                'version' => NightingaleDemoCohort::RELEASE_POLICY_VERSION,
                'status' => 'active',
                'disclosure_matrix_version' => 'nightingale-synthetic-demo-disclosure.v1',
                'content_contract_version' => 'patient-projection.v1',
                'rules' => $rules,
                'approved_by_actor_ref' => NightingaleDemoCohort::OWNER,
                'approved_at' => Carbon::parse('2026-07-27T00:00:00Z'),
                'effective_from' => Carbon::parse('2026-07-27T00:00:00Z'),
            ]);
        }

        if ((string) $policy->policy_uuid !== $uuid
            || $policy->status !== 'active'
            || $policy->disclosure_matrix_version !== 'nightingale-synthetic-demo-disclosure.v1'
            || $policy->content_contract_version !== 'patient-projection.v1'
            || $policy->approved_by_actor_ref !== NightingaleDemoCohort::OWNER
            || $policy->approved_at === null
            || $policy->effective_from === null
            || $policy->effective_to !== null
            || $this->canonicalJson((array) $policy->rules) !== $this->canonicalJson($rules)) {
            throw new RuntimeException('nightingale_demo_release_policy_changed');
        }

        return $policy;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $binding
     */
    private function resolveProjection(
        PatientPrincipal $principal,
        PatientEncounterAccessGrant $grant,
        Encounter $encounter,
        PatientReleasePolicyVersion $policy,
        string $alias,
        array $scenario,
        array $binding,
        string $kind,
    ): PatientEncounterProjection {
        $content = $this->content($alias, $scenario, $kind);
        $contentDigest = $this->contentGuard->digest($kind, self::SCHEMA_VERSIONS[$kind], $content);
        $sourceObservedAt = Carbon::instance($encounter->admitted_at);
        $provenance = [
            'projection_method' => 'code_owned_synthetic_investor_demo',
            'source_class' => 'synthetic_scenario_with_catalog_binding',
            'input_classes' => ['synthetic_demo_scenario', 'inactive_catalog_binding'],
            'review_state' => 'released_for_synthetic_demo_only',
            'producer_version' => NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION,
            'trace_digest' => $this->hmac->digest(
                'nightingale-demo-projection-trace-v1',
                $alias.'|'.$kind.'|'.$binding['pathway_version_uuid'].'|'.$contentDigest,
            ),
        ];
        $uncertainty = [
            'level' => 'unknown',
            'explanation' => 'This is synthetic demonstration content. Real care plans can change and require release by a care team.',
            'can_change' => true,
            'reviewed_at' => '2026-07-27T00:00:00+00:00',
        ];
        $this->contentGuard->assertSafe($kind, $content, $provenance, $uncertainty, ['self']);

        $cursorUuid = $this->uuid($alias.'/cursor/'.$kind);
        $cursorDigest = $this->hmac->digest(
            'nightingale-demo-projection-cursor-v1',
            $alias.'|'.$kind.'|'.$binding['pathway_version_uuid'].'|'.$contentDigest,
        );
        $cursor = PatientProjectionCursor::query()
            ->where('cursor_uuid', $cursorUuid)
            ->lockForUpdate()
            ->first();
        if (! $cursor instanceof PatientProjectionCursor) {
            $cursor = PatientProjectionCursor::query()->create([
                'cursor_uuid' => $cursorUuid,
                'source_system_key' => NightingaleDemoCohort::SOURCE_SYSTEM_KEY,
                'projection_kind' => $kind,
                'cursor_digest' => $cursorDigest,
                'source_version' => NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION,
                'status' => 'projected',
                'source_observed_at' => $sourceObservedAt,
                'projected_at' => now(),
                'metadata' => [
                    'owner' => NightingaleDemoCohort::OWNER,
                    'cohort_version' => NightingaleDemoCohort::VERSION,
                    'demo_username' => $alias,
                    'synthetic' => true,
                    'clinical_use_permitted' => false,
                    'catalog_binding_digest' => hash('sha256', $this->canonicalJson($binding)),
                ],
            ]);
        } elseif ($cursor->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
            || $cursor->projection_kind !== $kind
            || $cursor->cursor_digest !== $cursorDigest
            || $cursor->source_version !== NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION
            || $cursor->status !== 'projected'
            || ($cursor->metadata['owner'] ?? null) !== NightingaleDemoCohort::OWNER) {
            throw new RuntimeException("nightingale_demo_{$alias}_{$kind}_cursor_changed");
        }

        $projectionUuid = $this->uuid($alias.'/projection/'.$kind);
        $projection = PatientEncounterProjection::query()
            ->where('projection_uuid', $projectionUuid)
            ->lockForUpdate()
            ->first();
        if (! $projection instanceof PatientEncounterProjection) {
            return PatientEncounterProjection::query()->create([
                'projection_uuid' => $projectionUuid,
                'access_grant_id' => $grant->getKey(),
                'release_policy_version_id' => $policy->getKey(),
                'projection_cursor_id' => $cursor->getKey(),
                'projection_kind' => $kind,
                'projection_sequence' => 1,
                'content' => $content,
                'content_schema_version' => self::SCHEMA_VERSIONS[$kind],
                'content_digest' => $contentDigest,
                'source_version' => NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION,
                'provenance' => $provenance,
                'source_observed_at' => $sourceObservedAt,
                'generated_at' => now(),
                'released_at' => now(),
                'freshness_class' => 'unknown',
                'uncertainty' => $uncertainty,
                'required_scope' => PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind],
                'permitted_relationships' => ['self'],
                'release_state' => 'released',
            ]);
        }

        if ((int) $projection->access_grant_id !== (int) $grant->getKey()
            || (int) $projection->release_policy_version_id !== (int) $policy->getKey()
            || (int) $projection->projection_cursor_id !== (int) $cursor->getKey()
            || $projection->projection_kind !== $kind
            || (int) $projection->projection_sequence !== 1
            || $projection->content_schema_version !== self::SCHEMA_VERSIONS[$kind]
            || ! hash_equals($contentDigest, (string) $projection->content_digest)
            || $this->canonicalJson((array) $projection->content) !== $this->canonicalJson($content)
            || $this->canonicalJson((array) $projection->provenance) !== $this->canonicalJson($provenance)
            || $this->canonicalJson((array) $projection->uncertainty) !== $this->canonicalJson($uncertainty)
            || $projection->release_state !== 'released'
            || $projection->released_at === null
            || $projection->required_scope !== PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind]
            || array_values((array) $projection->permitted_relationships) !== ['self']) {
            throw new RuntimeException("nightingale_demo_{$alias}_{$kind}_projection_changed");
        }

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function content(string $alias, array $scenario, string $kind): array
    {
        $seed = NightingaleDemoCohort::VERSION.'/'.$alias;
        $content = $this->syntheticContent->contentFor($seed, $kind);
        $content['notices'] = array_values(array_unique([
            self::DEMO_NOTICE,
            ...((array) ($content['notices'] ?? [])),
        ]));
        $content['summary'] = 'Synthetic demo scenario: MS-DRG '
            .$scenario['ms_drg'].' — '.$scenario['drg_title'].'. '
            .'The linked catalog pathway is inactive and not institutionally approved. '
            .$content['summary'];

        if ($kind === 'pathway') {
            $content['current_stage'] = 'Synthetic demonstration stage 2 of 5';
            $content['stages'] = [];
            for ($index = 1; $index <= $scenario['stage_count']; $index++) {
                $status = $index === 1 ? 'completed' : ($index === 2 ? 'current' : 'planned');
                $content['stages'][] = [
                    'stage_uuid' => $this->uuid($alias.'/content/pathway/stage/'.$index),
                    'title' => "Synthetic demonstration stage {$index} of {$scenario['stage_count']}",
                    'status' => $status,
                    'summary' => 'A patient-safe demonstration checkpoint. A real care team would review and release current details.',
                    'expected_range' => $index <= 2 ? 'Demonstration: current stay' : 'Demonstration: timing not set',
                    'timing_confidence' => 'unknown',
                    'can_change' => $status !== 'completed',
                ];
            }
            $content['milestones'] = [];
            for ($index = 1; $index <= $scenario['milestone_count']; $index++) {
                $status = $index === 1 ? 'completed' : ($index === 2 ? 'current' : 'planned');
                $content['milestones'][] = [
                    'milestone_uuid' => $this->uuid($alias.'/content/pathway/milestone/'.$index),
                    'title' => "Synthetic pathway checkpoint {$index} of {$scenario['milestone_count']}",
                    'status' => $status,
                    'detail' => 'Demonstration content only. This checkpoint does not describe or direct real care.',
                    'timing' => 'Timing is intentionally unspecified for this demonstration.',
                    'timing_confidence' => 'unknown',
                    'can_change' => $status !== 'completed',
                ];
            }
        }

        return $content;
    }

    private function revokeSessionsForCredentialRotation(PatientPrincipal $principal): void
    {
        $sessions = PatientSession::query()
            ->where('principal_id', $principal->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();
        foreach ($sessions as $session) {
            $principal->tokens()
                ->whereIn('name', [
                    'patient-access:'.$session->session_uuid,
                    'patient-refresh:'.$session->session_uuid,
                ])
                ->delete();
            $session->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revocation_reason' => 'nightingale_demo_credential_rotation',
                'refresh_token_id' => null,
            ])->save();
        }
        PersonalAccessToken::query()
            ->where('tokenable_type', PatientPrincipal::class)
            ->where('tokenable_id', $principal->getKey())
            ->delete();
    }

    /**
     * @param  array{release: array<string, mixed>, members: array<string, array<string, mixed>>}  $catalog
     * @return array<string, array<string, mixed>>
     */
    private function verifyWithinTransaction(array $catalog): array
    {
        $policies = PatientReleasePolicyVersion::query()
            ->where('version', NightingaleDemoCohort::RELEASE_POLICY_VERSION)
            ->get();
        $expectedPolicyRules = [
            'owner' => NightingaleDemoCohort::OWNER,
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'synthetic_only' => true,
            'clinical_use_permitted' => false,
            'permitted_relationships' => ['self'],
            'required_notice' => NightingaleDemoCohort::DEMO_NOTICE,
        ];
        if ($policies->count() !== 1) {
            throw new RuntimeException('nightingale_demo_release_policy_cardinality_invalid');
        }
        $policy = $policies->sole();
        if ((string) $policy->policy_uuid !== $this->uuid('shared/release-policy')
            || $policy->status !== 'active'
            || $policy->disclosure_matrix_version !== 'nightingale-synthetic-demo-disclosure.v1'
            || $policy->content_contract_version !== 'patient-projection.v1'
            || $policy->approved_by_actor_ref !== NightingaleDemoCohort::OWNER
            || $policy->approved_at === null
            || $policy->effective_from === null
            || $policy->effective_to !== null
            || $this->canonicalJson((array) $policy->rules) !== $this->canonicalJson($expectedPolicyRules)) {
            throw new RuntimeException('nightingale_demo_release_policy_not_ready');
        }

        $principals = $this->ownedPrincipals(forUpdate: false);
        if ($principals->count() !== count(NightingaleDemoCohort::MEMBERS)) {
            throw new RuntimeException('nightingale_demo_cohort_principal_cardinality_invalid');
        }
        $results = [];
        $unitIds = [];
        foreach (NightingaleDemoCohort::MEMBERS as $alias => $scenario) {
            $principalMatches = $principals->filter(
                fn (PatientPrincipal $principal): bool => (($principal->preferences['provisioning']['demo_username'] ?? null) === $alias),
            );
            if ($principalMatches->count() !== 1) {
                throw new RuntimeException("nightingale_demo_{$alias}_principal_cardinality_invalid");
            }
            $principal = $principalMatches->sole();
            if ($principal->status !== 'active'
                || ! $principal->is_active
                || ! is_string($principal->password)
                || strlen($principal->password) < 40
                || Hash::needsRehash($principal->password)
                || ! NightingaleDemoCohort::preferencesAreOwned((array) $principal->preferences, $alias)) {
                throw new RuntimeException("nightingale_demo_{$alias}_principal_not_ready");
            }

            $identities = PatientIdentityLink::query()
                ->where('principal_id', $principal->getKey())
                ->get();
            $grants = PatientEncounterAccessGrant::query()
                ->where('principal_id', $principal->getKey())
                ->get();
            if ($identities->count() !== 1 || $grants->count() !== 1) {
                throw new RuntimeException("nightingale_demo_{$alias}_authorization_cardinality_invalid");
            }
            $identity = $identities->sole();
            $expectedIdentityUuid = $this->uuid($alias.'/identity-link');
            $expectedIdentityDigest = $this->hmac->digest(
                'nightingale-demo-identity-v1',
                NightingaleDemoCohort::SOURCE_SYSTEM_KEY."\0".$scenario['patient_ref'],
            );
            if ((string) $identity->identity_link_uuid !== $expectedIdentityUuid
                || $identity->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
                || $identity->source_subject_digest !== $expectedIdentityDigest
                || $identity->encrypted_source_subject !== $scenario['patient_ref']
                || $identity->status !== 'verified'
                || $identity->verified_at === null
                || $identity->revoked_at !== null
                || ($identity->provenance['owner'] ?? null) !== NightingaleDemoCohort::OWNER
                || ($identity->provenance['cohort_version'] ?? null) !== NightingaleDemoCohort::VERSION
                || ($identity->provenance['synthetic'] ?? null) !== true
                || ($identity->provenance['clinical_use_permitted'] ?? null) !== false) {
                throw new RuntimeException("nightingale_demo_{$alias}_identity_not_ready");
            }
            $grant = $grants->sole();
            $expectedGrantUuid = $this->uuid($alias.'/grant');
            $expectedEncounterUuid = $this->uuid($alias.'/encounter');
            $expectedGrantDigest = $this->hmac->digest(
                'nightingale-demo-encounter-v1',
                NightingaleDemoCohort::SOURCE_SYSTEM_KEY."\0prod.encounters/".$grant->source_encounter_id,
            );
            if ($grant->status !== 'active'
                || (int) $grant->identity_link_id !== (int) $identity->getKey()
                || (int) $grant->source_encounter_id < 1
                || (string) $grant->grant_uuid !== $expectedGrantUuid
                || (string) $grant->encounter_uuid !== $expectedEncounterUuid
                || $grant->source_encounter_ref_digest !== $expectedGrantDigest
                || $grant->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
                || $grant->relationship !== 'self'
                || $grant->purpose_of_use !== 'patient_access'
                || $grant->revoked_at !== null
                || ($grant->metadata['owner'] ?? null) !== NightingaleDemoCohort::OWNER
                || ($grant->metadata['cohort_version'] ?? null) !== NightingaleDemoCohort::VERSION
                || ($grant->metadata['demo_username'] ?? null) !== $alias
                || ($grant->metadata['synthetic'] ?? null) !== true
                || ($grant->metadata['clinical_use_permitted'] ?? null) !== false) {
                throw new RuntimeException("nightingale_demo_{$alias}_grant_not_ready");
            }
            $encounters = Encounter::query()
                ->whereKey($grant->source_encounter_id)
                ->where('patient_ref', $scenario['patient_ref'])
                ->where('status', 'active')
                ->where('is_deleted', false)
                ->get();
            $encounter = $encounters->count() === 1 ? $encounters->sole() : null;
            $expectedEncounterOwner = $alias === 'demo1'
                ? NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER
                : NightingaleDemoCohort::OWNER;
            if (! $encounter instanceof Encounter
                || $encounter->created_by !== $expectedEncounterOwner
                || $encounter->modified_by !== NightingaleDemoCohort::OWNER
                || $encounter->bed_id !== null
                || $encounter->admitted_at === null
                || (int) $encounter->acuity_tier !== 2
                || $encounter->discharged_at !== null
                || ($alias === 'demo1'
                    && ! NightingaleDemoCohort::referenceSampleLineageIsExact(
                        (array) ($principal->preferences['provisioning'] ?? []),
                    ))) {
                throw new RuntimeException("nightingale_demo_{$alias}_encounter_not_ready");
            }
            $unitIds[] = (int) $encounter->unit_id;
            $binding = $catalog['members'][$alias];
            $projections = PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->get();
            if ($projections->count() !== count(self::KINDS)
                || $projections->pluck('projection_kind')->sort()->values()->all()
                    !== collect(self::KINDS)->sort()->values()->all()) {
                throw new RuntimeException("nightingale_demo_{$alias}_projection_cardinality_invalid");
            }
            foreach ($projections as $projection) {
                $kind = (string) $projection->projection_kind;
                $expectedContent = $this->content($alias, $scenario, $kind);
                $expectedContentDigest = $this->contentGuard->digest(
                    $kind,
                    self::SCHEMA_VERSIONS[$kind],
                    $expectedContent,
                );
                $expectedProvenance = [
                    'projection_method' => 'code_owned_synthetic_investor_demo',
                    'source_class' => 'synthetic_scenario_with_catalog_binding',
                    'input_classes' => ['synthetic_demo_scenario', 'inactive_catalog_binding'],
                    'review_state' => 'released_for_synthetic_demo_only',
                    'producer_version' => NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION,
                    'trace_digest' => $this->hmac->digest(
                        'nightingale-demo-projection-trace-v1',
                        $alias.'|'.$kind.'|'.$binding['pathway_version_uuid'].'|'.$expectedContentDigest,
                    ),
                ];
                $expectedUncertainty = [
                    'level' => 'unknown',
                    'explanation' => 'This is synthetic demonstration content. Real care plans can change and require release by a care team.',
                    'can_change' => true,
                    'reviewed_at' => '2026-07-27T00:00:00+00:00',
                ];
                $projectionPolicy = $projection->releasePolicyVersion;
                $cursor = $projection->cursor;
                $expectedCursorDigest = $this->hmac->digest(
                    'nightingale-demo-projection-cursor-v1',
                    $alias.'|'.$kind.'|'.$binding['pathway_version_uuid'].'|'.$expectedContentDigest,
                );
                if ((string) $projection->projection_uuid !== $this->uuid($alias.'/projection/'.$kind)
                    || $projection->release_state !== 'released'
                    || $projection->released_at === null
                    || $projection->supersedes_projection_id !== null
                    || (int) $projection->projection_sequence !== 1
                    || $projection->content_schema_version !== self::SCHEMA_VERSIONS[$kind]
                    || $projection->source_version !== NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION
                    || $projection->required_scope !== PatientProjectionDisclosureService::REQUIRED_SCOPES[$kind]
                    || array_values((array) $projection->permitted_relationships) !== ['self']
                    || ! hash_equals($expectedContentDigest, (string) $projection->content_digest)
                    || $this->canonicalJson((array) $projection->content) !== $this->canonicalJson($expectedContent)
                    || $this->canonicalJson((array) $projection->provenance) !== $this->canonicalJson($expectedProvenance)
                    || $this->canonicalJson((array) $projection->uncertainty) !== $this->canonicalJson($expectedUncertainty)
                    || ! $projectionPolicy instanceof PatientReleasePolicyVersion
                    || (int) $projectionPolicy->getKey() !== (int) $policy->getKey()
                    || ! $cursor instanceof PatientProjectionCursor
                    || (string) $cursor->cursor_uuid !== $this->uuid($alias.'/cursor/'.$kind)
                    || $cursor->source_system_key !== NightingaleDemoCohort::SOURCE_SYSTEM_KEY
                    || $cursor->projection_kind !== $kind
                    || $cursor->cursor_digest !== $expectedCursorDigest
                    || $cursor->source_version !== NightingaleDemoCohort::PROJECTION_PRODUCER_VERSION
                    || $cursor->status !== 'projected'
                    || ($cursor->metadata['owner'] ?? null) !== NightingaleDemoCohort::OWNER
                    || ($cursor->metadata['cohort_version'] ?? null) !== NightingaleDemoCohort::VERSION
                    || ($cursor->metadata['demo_username'] ?? null) !== $alias
                    || ($cursor->metadata['synthetic'] ?? null) !== true
                    || ($cursor->metadata['clinical_use_permitted'] ?? null) !== false
                    || ($cursor->metadata['catalog_binding_digest'] ?? null)
                        !== hash('sha256', $this->canonicalJson($binding))) {
                    throw new RuntimeException("nightingale_demo_{$alias}_{$kind}_projection_not_ready");
                }
                $this->contentGuard->assertSafe(
                    $kind,
                    (array) $projection->content,
                    (array) $projection->provenance,
                    (array) $projection->uncertainty,
                    array_values((array) $projection->permitted_relationships),
                );
            }

            $results[$alias] = [
                'principal_state' => 'active_synthetic_demo',
                'identity_links' => 1,
                'encounter_grants' => 1,
                'operational_encounters' => 1,
                'released_synthetic_projections' => count(self::KINDS),
                'ms_drg' => $binding['ms_drg'],
                'drg_title' => $binding['drg_title'],
                'catalog_stage_count' => $binding['stage_count'],
                'catalog_milestone_count' => $binding['milestone_count'],
                'clinical_use_permitted' => false,
                'credential_hash_present' => true,
                'credential_material_emitted' => false,
            ];
        }

        $principalIds = $principals->modelKeys();
        $grantIds = PatientEncounterAccessGrant::query()
            ->whereIn('principal_id', $principalIds)
            ->pluck('access_grant_id');
        if (count(array_unique($principals->pluck('principal_uuid')->all())) !== 5
            || PatientIdentityLink::query()
                ->whereIn('principal_id', $principalIds)
                ->distinct('source_subject_digest')
                ->count('source_subject_digest') !== 5
            || PatientEncounterAccessGrant::query()
                ->whereIn('principal_id', $principalIds)
                ->distinct('encounter_uuid')
                ->count('encounter_uuid') !== 5
            || PatientEncounterAccessGrant::query()
                ->whereIn('principal_id', $principalIds)
                ->distinct('source_encounter_id')
                ->count('source_encounter_id') !== 5
            || PatientEncounterProjection::query()
                ->whereIn('access_grant_id', $grantIds)
                ->count() !== 30
            || PatientProjectionCursor::query()
                ->whereRaw("metadata->>'owner' = ?", [NightingaleDemoCohort::OWNER])
                ->whereRaw("metadata->>'cohort_version' = ?", [NightingaleDemoCohort::VERSION])
                ->count() !== 30
            || PatientPrincipal::query()
                ->whereRaw(
                    "preferences #>> '{provisioning,owner}' = ?",
                    [NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER],
                )
                ->count() !== 0
            || Encounter::query()
                ->where('patient_ref', NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF)
                ->count() !== 1
            || count(array_unique($unitIds)) !== 1
            || ($unitIds[0] ?? 0) < 1) {
            throw new RuntimeException('nightingale_demo_cohort_cross_account_collision');
        }

        return $results;
    }

    private function ownedPrincipals(bool $forUpdate)
    {
        $query = PatientPrincipal::query()
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [NightingaleDemoCohort::OWNER])
            ->whereRaw("preferences #>> '{provisioning,cohort_version}' = ?", [NightingaleDemoCohort::VERSION]);

        return ($forUpdate ? $query->lockForUpdate() : $query)->get();
    }

    /** @return array<string, int> */
    private function existingCounts(): array
    {
        $principalIds = $this->ownedPrincipals(forUpdate: false)->modelKeys();

        return [
            'principals' => count($principalIds),
            'identity_links' => $principalIds === [] ? 0 : PatientIdentityLink::query()->whereIn('principal_id', $principalIds)->count(),
            'encounter_grants' => $principalIds === [] ? 0 : PatientEncounterAccessGrant::query()->whereIn('principal_id', $principalIds)->count(),
            'sessions' => $principalIds === [] ? 0 : PatientSession::query()->whereIn('principal_id', $principalIds)->count(),
            'tokens' => $principalIds === [] ? 0 : PersonalAccessToken::query()
                ->where('tokenable_type', PatientPrincipal::class)
                ->whereIn('tokenable_id', $principalIds)
                ->count(),
            'projections' => $principalIds === [] ? 0 : PatientEncounterProjection::query()
                ->whereIn(
                    'access_grant_id',
                    PatientEncounterAccessGrant::query()->whereIn('principal_id', $principalIds)->select('access_grant_id'),
                )
                ->count(),
        ];
    }

    /**
     * @param  array{release: array<string, mixed>, members: array<string, array<string, mixed>>}  $catalog
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function result(
        bool $committed,
        string $action,
        Unit $unit,
        array $catalog,
        array $existing,
        string $referenceSampleState,
    ): array {
        return [
            'committed' => $committed,
            'action' => $action,
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'member_count' => count(NightingaleDemoCohort::MEMBERS),
            'login_handles' => NightingaleDemoCohort::loginAliases(),
            'unit_id' => (int) $unit->getKey(),
            'catalog_release_uuid' => $catalog['release']['catalog_release_uuid'],
            'catalog_state' => $catalog['release']['state'],
            'catalog_clinical_signoff_complete' => false,
            'accounts' => $existing,
            'demo1_reference_sample' => $referenceSampleState,
            'credential_material_emitted' => false,
            'clinical_use_permitted' => false,
        ];
    }

    private function uuid(string $name): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://zephyrus.acumenus.net/nightingale/demo-cohort/v1/'.$name,
        )->toString();
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
