<?php

namespace App\Integrations\Healthcare\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SourceOnboardingService
{
    public function __construct(private readonly SourceSloDefinitionService $sloDefinitions) {}

    /** @var list<string> */
    private const PROFILE_COLUMNS = [
        'system_version',
        'protocol_profile',
        'owner_name',
        'steward_name',
        'network_route_key',
        'data_classification',
        'permitted_purpose',
        'phi_permission_basis',
        'retention_policy_key',
        'retention_days',
        'credential_strategy',
        'conformance_status',
        'support_entitlement',
        'vendor_support_identifier',
        'maintenance_timezone',
        'contacts',
        'maintenance_windows',
        'slo_definition',
    ];

    /** @var list<string> */
    private const EVIDENCE_TYPES = [
        'contract',
        'baa',
        'dua',
        'conformance_report',
        'vendor_approval',
        'customer_uat',
        'test_results',
        'security_review',
        'change_ticket',
        'cutover_plan',
        'rollback_plan',
    ];

    /** @var list<string> */
    private const REFERENCE_SCHEMES = [
        'https',
        's3',
        'gs',
        'azure',
        'sharepoint',
        'gdrive',
        'ticket',
        'urn',
    ];

    /** @param array<string, mixed> $seed */
    public function initialize(
        int $sourceId,
        array $seed = [],
        ?int $actorUserId = null,
        string $reason = 'Initial incomplete onboarding profile for explicit review.',
    ): object {
        return DB::transaction(function () use ($sourceId, $seed, $actorUserId, $reason): object {
            $this->source($sourceId, lock: true);
            $existing = DB::table('integration.source_onboarding_versions')
                ->where('source_id', $sourceId)
                ->orderByDesc('version_number')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $profile = $this->normalizeProfile([
                'system_version' => null,
                'protocol_profile' => null,
                'owner_name' => null,
                'steward_name' => null,
                'network_route_key' => null,
                'data_classification' => 'unknown',
                'permitted_purpose' => null,
                'phi_permission_basis' => null,
                'retention_policy_key' => null,
                'retention_days' => null,
                'credential_strategy' => null,
                'conformance_status' => 'not_tested',
                'support_entitlement' => 'unknown',
                'vendor_support_identifier' => null,
                'maintenance_timezone' => null,
                'contacts' => [],
                'maintenance_windows' => [],
                'slo_definition' => [],
                ...$seed,
            ]);

            return $this->insertVersion($sourceId, 1, null, $profile, $actorUserId, $reason);
        });
    }

    /** @param array<string, mixed> $profile */
    public function revise(
        int $sourceId,
        array $profile,
        int $expectedVersionId,
        ?int $actorUserId,
        string $reason,
    ): object {
        return DB::transaction(function () use (
            $sourceId,
            $profile,
            $expectedVersionId,
            $actorUserId,
            $reason,
        ): object {
            $this->source($sourceId, lock: true);
            $current = DB::table('integration.source_onboarding_versions')
                ->where('source_id', $sourceId)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                throw ValidationException::withMessages([
                    'expected_onboarding_version_id' => 'The source has no onboarding authority record.',
                ]);
            }
            if ((int) $current->source_onboarding_version_id !== $expectedVersionId) {
                throw ValidationException::withMessages([
                    'expected_onboarding_version_id' => 'The onboarding profile changed. Refresh before saving a new version.',
                ]);
            }

            return $this->insertVersion(
                $sourceId,
                (int) $current->version_number + 1,
                (int) $current->source_onboarding_version_id,
                $this->normalizeProfile($profile),
                $actorUserId,
                $reason,
            );
        });
    }

    public function latest(int $sourceId): object
    {
        $this->source($sourceId);

        return DB::table('integration.source_onboarding_versions')
            ->where('source_id', $sourceId)
            ->orderByDesc('version_number')
            ->firstOrFail();
    }

    /** @return list<object> */
    public function currentEvidenceRows(int $sourceId): array
    {
        $this->source($sourceId);

        return DB::table('integration.source_evidence_records')
            ->where('source_id', $sourceId)
            ->orderBy('evidence_type')
            ->orderByDesc('source_evidence_record_id')
            ->get()
            ->unique('evidence_type')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public function addEvidence(
        int $sourceId,
        array $payload,
        ?int $actorUserId,
    ): object {
        return DB::transaction(function () use ($sourceId, $payload, $actorUserId): object {
            $this->source($sourceId, lock: true);
            $type = (string) $payload['evidence_type'];
            if (! in_array($type, self::EVIDENCE_TYPES, true)) {
                throw ValidationException::withMessages(['evidence_type' => 'The evidence type is unsupported.']);
            }
            $reference = $this->validatedReference((string) $payload['reference_uri']);
            $current = DB::table('integration.source_evidence_records')
                ->where('source_id', $sourceId)
                ->where('evidence_type', $type)
                ->orderByDesc('source_evidence_record_id')
                ->lockForUpdate()
                ->first();
            $supersedes = isset($payload['supersedes_evidence_id'])
                ? (int) $payload['supersedes_evidence_id']
                : null;
            if ($current === null && $supersedes !== null) {
                throw ValidationException::withMessages([
                    'supersedes_evidence_id' => 'There is no current evidence record to supersede.',
                ]);
            }
            if ($current !== null && $supersedes !== (int) $current->source_evidence_record_id) {
                throw ValidationException::withMessages([
                    'supersedes_evidence_id' => 'The current evidence record must be explicitly superseded.',
                ]);
            }

            $evidenceId = (int) DB::table('integration.source_evidence_records')->insertGetId([
                'evidence_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'evidence_type' => $type,
                'evidence_status' => $payload['evidence_status'],
                'display_label' => $this->clean((string) $payload['display_label'], 190),
                'reference_uri' => $reference,
                'reference_sha256' => hash('sha256', $reference),
                'artifact_sha256' => isset($payload['artifact_sha256'])
                    && filled($payload['artifact_sha256'])
                    ? strtolower((string) $payload['artifact_sha256'])
                    : null,
                'issued_at' => $payload['issued_at'] ?? null,
                'expires_at' => $payload['expires_at'] ?? null,
                'supersedes_evidence_id' => $supersedes,
                'recorded_by_user_id' => $actorUserId,
                'reason' => $this->reason((string) $payload['reason']),
                'metadata' => '{}',
                'created_at' => now(),
            ], 'source_evidence_record_id');

            return DB::table('integration.source_evidence_records')
                ->where('source_evidence_record_id', $evidenceId)
                ->firstOrFail();
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(int $sourceId): array
    {
        $current = $this->latest($sourceId);
        $versions = DB::table('integration.source_onboarding_versions')
            ->where('source_id', $sourceId)
            ->orderByDesc('version_number')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => $this->profilePayload($row))
            ->all();
        $evidence = DB::table('integration.source_evidence_records')
            ->where('source_id', $sourceId)
            ->orderByDesc('source_evidence_record_id')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => $this->evidencePayload($row))
            ->all();

        return [
            'currentProfile' => $this->profilePayload($current),
            'profileVersions' => $versions,
            'evidence' => $evidence,
        ];
    }

    /** @return array<string, mixed> */
    public function profilePayload(object $row): array
    {
        return [
            'onboardingVersionId' => (int) $row->source_onboarding_version_id,
            'onboardingVersionUuid' => (string) $row->onboarding_version_uuid,
            'sourceId' => (int) $row->source_id,
            'versionNumber' => (int) $row->version_number,
            'previousVersionId' => $row->previous_version_id !== null ? (int) $row->previous_version_id : null,
            'systemVersion' => $row->system_version,
            'protocolProfile' => $row->protocol_profile,
            'ownerName' => $row->owner_name,
            'stewardName' => $row->steward_name,
            'networkRouteKey' => $row->network_route_key,
            'dataClassification' => (string) $row->data_classification,
            'permittedPurpose' => $row->permitted_purpose,
            'phiPermissionBasis' => $row->phi_permission_basis,
            'retentionPolicyKey' => $row->retention_policy_key,
            'retentionDays' => $row->retention_days !== null ? (int) $row->retention_days : null,
            'credentialStrategy' => $row->credential_strategy,
            'conformanceStatus' => (string) $row->conformance_status,
            'supportEntitlement' => (string) $row->support_entitlement,
            'vendorSupportIdentifier' => $row->vendor_support_identifier,
            'maintenanceTimezone' => $row->maintenance_timezone,
            'contacts' => $this->decodeList($row->contacts),
            'maintenanceWindows' => $this->decodeList($row->maintenance_windows),
            // Preserve the API's map contract when PostgreSQL contains an
            // empty JSON object; an empty PHP array would serialize as [].
            'sloDefinition' => (object) $this->decodeMap($row->slo_definition),
            'profileSha256' => (string) $row->profile_sha256,
            'changeReason' => (string) $row->change_reason,
            'createdByUserId' => $row->created_by_user_id !== null ? (int) $row->created_by_user_id : null,
            'createdAtIso' => $this->iso($row->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public function evidencePayload(object $row): array
    {
        return [
            'evidenceRecordId' => (int) $row->source_evidence_record_id,
            'evidenceUuid' => (string) $row->evidence_uuid,
            'sourceId' => (int) $row->source_id,
            'evidenceType' => (string) $row->evidence_type,
            'evidenceStatus' => (string) $row->evidence_status,
            'displayLabel' => (string) $row->display_label,
            'referenceConfigured' => true,
            'referenceFingerprint' => substr((string) $row->reference_sha256, 0, 16),
            'artifactSha256' => $row->artifact_sha256,
            'issuedAtIso' => $this->iso($row->issued_at),
            'expiresAtIso' => $this->iso($row->expires_at),
            'supersedesEvidenceId' => $row->supersedes_evidence_id !== null
                ? (int) $row->supersedes_evidence_id
                : null,
            'recordedByUserId' => $row->recorded_by_user_id !== null ? (int) $row->recorded_by_user_id : null,
            'reason' => (string) $row->reason,
            'createdAtIso' => $this->iso($row->created_at),
        ];
    }

    /** @param array<string, mixed> $profile */
    private function insertVersion(
        int $sourceId,
        int $versionNumber,
        ?int $previousVersionId,
        array $profile,
        ?int $actorUserId,
        string $reason,
    ): object {
        $versionId = (int) DB::table('integration.source_onboarding_versions')->insertGetId([
            'onboarding_version_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            'version_number' => $versionNumber,
            'previous_version_id' => $previousVersionId,
            ...collect($profile)->except(['contacts', 'maintenance_windows', 'slo_definition'])->all(),
            'contacts' => json_encode($profile['contacts'], JSON_THROW_ON_ERROR),
            'maintenance_windows' => json_encode($profile['maintenance_windows'], JSON_THROW_ON_ERROR),
            'slo_definition' => json_encode((object) $profile['slo_definition'], JSON_THROW_ON_ERROR),
            'profile_sha256' => $this->hash($profile),
            'change_reason' => $this->reason($reason),
            'created_by_user_id' => $actorUserId,
            'created_at' => now(),
        ], 'source_onboarding_version_id');

        $onboarding = DB::table('integration.source_onboarding_versions')
            ->where('source_onboarding_version_id', $versionId)
            ->firstOrFail();

        $this->sloDefinitions->recordForOnboarding($onboarding);

        return $onboarding;
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function normalizeProfile(array $profile): array
    {
        $normalized = [];
        foreach (self::PROFILE_COLUMNS as $column) {
            $normalized[$column] = $profile[$column] ?? match ($column) {
                'contacts', 'maintenance_windows', 'slo_definition' => [],
                'data_classification' => 'unknown',
                'conformance_status' => 'not_tested',
                'support_entitlement' => 'unknown',
                default => null,
            };
        }

        foreach ($normalized as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = $this->nullableClean($value, $key === 'permitted_purpose' ? 500 : 160);
            }
        }

        $normalized['retention_days'] = isset($normalized['retention_days'])
            ? (int) $normalized['retention_days']
            : null;
        $normalized['contacts'] = is_array($normalized['contacts']) ? array_values($normalized['contacts']) : [];
        $normalized['contacts'] = array_map(function (mixed $contact): array {
            $contact = is_array($contact) ? $contact : [];

            return [
                'role' => $this->nullableClean((string) ($contact['role'] ?? ''), 40),
                'name' => $this->nullableClean((string) ($contact['name'] ?? ''), 160),
                'email' => $this->nullableClean((string) ($contact['email'] ?? ''), 190),
                'phone' => $this->nullableClean((string) ($contact['phone'] ?? ''), 40),
            ];
        }, $normalized['contacts']);
        $normalized['maintenance_windows'] = is_array($normalized['maintenance_windows'])
            ? array_values($normalized['maintenance_windows'])
            : [];
        $normalized['maintenance_windows'] = array_map(function (mixed $window): array {
            $window = is_array($window) ? $window : [];

            return [
                'weekday' => isset($window['weekday']) ? (int) $window['weekday'] : null,
                'start_local' => $this->nullableClean((string) ($window['start_local'] ?? ''), 8),
                'duration_minutes' => isset($window['duration_minutes']) ? (int) $window['duration_minutes'] : null,
                'purpose' => $this->nullableClean((string) ($window['purpose'] ?? ''), 160),
            ];
        }, $normalized['maintenance_windows']);
        $normalized['slo_definition'] = is_array($normalized['slo_definition'])
            ? $normalized['slo_definition']
            : [];

        return $normalized;
    }

    private function source(int $sourceId, bool $lock = false): object
    {
        $query = DB::table('integration.sources')->where('source_id', $sourceId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function validatedReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 2048 || preg_match('/[\x00-\x1F\x7F]/u', $reference)) {
            throw ValidationException::withMessages(['reference_uri' => 'A bounded evidence reference is required.']);
        }
        $parts = parse_url($reference);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! in_array($scheme, self::REFERENCE_SCHEMES, true)) {
            throw ValidationException::withMessages(['reference_uri' => 'Use an approved evidence reference scheme.']);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'reference_uri' => 'Evidence references cannot contain credentials, query strings, or fragments.',
            ]);
        }
        if ($scheme === 'https' && ! isset($parts['host'])) {
            throw ValidationException::withMessages(['reference_uri' => 'HTTPS evidence references require a host.']);
        }

        return $reference;
    }

    private function reason(string $value): string
    {
        $value = $this->clean($value, 500);
        if (mb_strlen($value) < 10) {
            throw ValidationException::withMessages(['reason' => 'A 10-500 character reason is required.']);
        }

        return $value;
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? ''), 0, $max);
    }

    private function nullableClean(string $value, int $max): ?string
    {
        $value = $this->clean($value, $max);

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = $this->decode($value);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        $decoded = $this->decode($value);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return json_decode($value, true);
    }

    private function iso(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse((string) $value)->toIso8601String() : null;
    }
}
