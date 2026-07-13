<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SourceReadinessService
{
    /** @var list<string> */
    private const REQUIRED_SLO_KEYS = [
        'availability_percent',
        'freshness_minutes',
        'completeness_percent',
        'latency_ms',
        'error_rate_percent',
        'acknowledgement_seconds',
        'reconciliation_variance_percent',
    ];

    public function __construct(
        private readonly SourceOnboardingService $onboarding,
        private readonly CredentialValidationService $credentials,
        private readonly NetworkRouteService $networkRoutes,
        private readonly ClinicalPayloadStore $payloads,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(
        int $sourceId,
        CarbonImmutable $evaluatedFor,
        ?int $actorUserId = null,
        bool $persist = true,
    ): array {
        $source = $this->source($sourceId);
        $profile = $this->onboarding->latest($sourceId);
        $evidence = collect($this->onboarding->currentEvidenceRows($sourceId))->keyBy('evidence_type');
        $contacts = $this->decodeList($profile->contacts);
        $maintenanceWindows = $this->decodeList($profile->maintenance_windows);
        $slo = $this->decodeMap($profile->slo_definition);
        $activeEndpointRows = DB::table('integration.source_endpoints')
            ->where('source_id', $sourceId)->where('is_active', true)
            ->orderBy('source_endpoint_id')->get();
        $activeCredentialRows = DB::table('integration.source_credentials')
            ->where('source_id', $sourceId)->where('is_active', true)
            ->orderBy('source_credential_id')->get();
        $activeEndpoints = $activeEndpointRows->count();
        $activeCredentials = $activeCredentialRows->count();
        $credentialAssessments = $activeCredentialRows
            ->map(fn (object $credential): array => $this->credentials->evaluate(
                (int) $credential->source_credential_id,
                $evaluatedFor,
                $actorUserId,
                persist: $persist,
            ));
        $networkAssessments = $activeEndpointRows->map(function (object $endpoint) use ($sourceId, $evaluatedFor): array {
            $parts = parse_url((string) $endpoint->url);
            $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
            $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 0;
            $route = DB::table('integration.source_network_routes')
                ->where('source_id', $sourceId)
                ->where('status', '<>', 'retired')
                ->where(function ($query) use ($endpoint, $host, $port): void {
                    $query->where('source_endpoint_id', $endpoint->source_endpoint_id)
                        ->orWhere(function ($address) use ($host, $port): void {
                            $address->where('hostname', $host)->where('port', $port);
                        });
                })
                ->orderByDesc('source_endpoint_id')
                ->first();
            if ($route === null) {
                return [
                    'endpointId' => (int) $endpoint->source_endpoint_id,
                    'networkRouteId' => null,
                    'status' => 'blocked',
                    'errorCode' => 'network_route_required',
                    'policySha256' => null,
                    'addressFingerprints' => [],
                ];
            }
            $inspection = $this->networkRoutes->inspect(
                (int) $route->source_network_route_id,
                persist: false,
                evaluatedFor: $evaluatedFor,
            );

            return [
                'endpointId' => (int) $endpoint->source_endpoint_id,
                'networkRouteId' => (int) $route->source_network_route_id,
                'status' => $inspection['status'],
                'errorCode' => $inspection['errorCode'],
                'policySha256' => $inspection['policySha256'],
                'addressFingerprints' => array_map(
                    fn (string $address): string => hash('sha256', $address),
                    [...$inspection['addresses'], ...$inspection['proxyAddresses']],
                ),
            ];
        });
        $payloadReadiness = $this->payloads->readiness();
        $roles = collect($contacts)->pluck('role')->filter()->unique()->values()->all();
        // INT-LIFECYCLE: the three governed/operator facets are separate authorities
        // from the onboarding profile and lifecycle state; the activation gate reads
        // each independently so no single collapsed status can green-light a source.
        $facetRow = DB::table('integration.source_status_facets')->where('source_id', $sourceId)->first();
        $facetConformance = $facetRow?->conformance_status !== null ? (string) $facetRow->conformance_status : 'not_started';
        $facetContract = $facetRow?->contract_status !== null ? (string) $facetRow->contract_status : 'none';
        $facetIncident = $facetRow?->incident_status !== null ? (string) $facetRow->incident_status : 'none';
        $contractExpiresAt = $facetRow?->contract_expires_at !== null
            ? CarbonImmutable::parse((string) $facetRow->contract_expires_at)
            : null;

        $checks = [];
        $this->check($checks, 'enterprise.organization', 'enterprise', $source->organization_id !== null,
            'A canonical organization is assigned.');
        $this->check($checks, 'enterprise.facility', 'enterprise', $source->facility_id !== null,
            'A canonical facility is assigned.');
        $this->check($checks, 'enterprise.facility_active', 'enterprise', (bool) $source->facility_is_active,
            'The canonical facility is active.');
        $this->check($checks, 'configuration.immutable_version', 'configuration',
            $source->current_configuration_version_id !== null && filled($source->configuration_sha256),
            'An immutable effective source configuration is present.');
        $this->check($checks, 'configuration.production_environment', 'configuration',
            (string) $source->environment === 'production',
            'The source is explicitly classified as production.');
        $this->check($checks, 'configuration.active_endpoint', 'configuration', $activeEndpoints > 0,
            'At least one active endpoint is configured.');
        $this->check($checks, 'configuration.endpoint_authority', 'configuration',
            $activeEndpointRows->isNotEmpty() && $activeEndpointRows->every(fn (object $endpoint): bool => filled($endpoint->url)),
            'Every active endpoint has an exact address authority.');
        $credentialReady = (string) $profile->credential_strategy === 'managed_interface_engine'
            || $activeCredentials > 0;
        $this->check($checks, 'configuration.credential', 'configuration', $credentialReady,
            'An active credential reference or managed interface-engine strategy is configured.');
        $credentialAuthorityReady = (string) $profile->credential_strategy === 'managed_interface_engine'
            || ($credentialAssessments->isNotEmpty() && $credentialAssessments->every(
                fn (array $assessment): bool => $assessment['status'] === 'ready',
            ));
        $this->check($checks, 'configuration.credential_authority', 'configuration', $credentialAuthorityReady,
            'Every credential provider, version, lease, certificate, and rotation deadline remains valid through the evaluated activation time.');
        $this->check(
            $checks,
            'configuration.network_routes',
            'configuration',
            $networkAssessments->isNotEmpty()
                && $networkAssessments->every(fn (array $assessment): bool => $assessment['status'] === 'validated'),
            'Every active endpoint has an exact route whose DNS and egress policy validate at assessment time.',
        );
        $this->check(
            $checks,
            'data_protection.payload_store',
            'data_protection',
            $payloadReadiness['status'] === 'ready',
            'The encrypted clinical-payload authority, storage driver, key provider, cipher, and fail-closed controls are ready.',
        );

        $this->check($checks, 'profile.system_version', 'onboarding', filled($profile->system_version),
            'The sending system version is recorded.');
        $this->check($checks, 'profile.protocol_profile', 'onboarding', filled($profile->protocol_profile),
            'The exact protocol or implementation profile is recorded.');
        $this->check($checks, 'profile.owner', 'onboarding', filled($profile->owner_name),
            'An accountable integration owner is recorded.');
        $this->check($checks, 'profile.steward', 'onboarding', filled($profile->steward_name),
            'A data steward is recorded.');
        $this->check($checks, 'profile.network_route', 'onboarding', filled($profile->network_route_key),
            'An approved network-route reference is recorded.');
        $this->check($checks, 'profile.data_classification', 'onboarding',
            in_array((string) $profile->data_classification, ['public', 'internal', 'confidential', 'restricted_phi'], true),
            'The source data classification is explicit.');
        $this->check($checks, 'profile.permitted_purpose', 'onboarding',
            filled($profile->permitted_purpose) && mb_strlen((string) $profile->permitted_purpose) >= 10,
            'A bounded permitted purpose is recorded.');
        $this->check($checks, 'profile.phi_permission_basis', 'onboarding',
            ! (bool) $source->phi_allowed || filled($profile->phi_permission_basis),
            'The PHI permission basis is recorded when PHI is allowed.');
        $this->check($checks, 'profile.retention', 'onboarding',
            filled($profile->retention_policy_key) && (int) $profile->retention_days > 0,
            'A retention policy and bounded retention period are recorded.');
        $this->check($checks, 'profile.conformance', 'conformance',
            (string) $profile->conformance_status === 'passed',
            'Protocol conformance is currently passed.');
        $this->check($checks, 'facet.conformance', 'conformance',
            in_array($facetConformance, ['passed', 'waived'], true),
            'The governed conformance facet is passed or explicitly waived.');
        $this->check($checks, 'profile.support_entitlement', 'operations',
            (string) $profile->support_entitlement !== 'unknown',
            'The vendor support entitlement is explicit.');
        $this->check($checks, 'profile.maintenance_timezone', 'operations', filled($profile->maintenance_timezone),
            'The maintenance-window timezone is recorded.');
        $this->check($checks, 'profile.contacts', 'operations',
            count(array_intersect(['owner', 'steward', 'escalation'], $roles)) === 3,
            'Owner, steward, and escalation contacts are present.');
        $this->check($checks, 'profile.maintenance_window', 'operations', $maintenanceWindows !== [],
            'At least one maintenance window is recorded.');
        $this->check($checks, 'profile.slo', 'operations', $this->sloComplete($slo),
            'Availability, freshness, completeness, latency, error, acknowledgement, and reconciliation SLOs are complete.');

        $this->check($checks, 'legal.contract_status', 'legal', (string) $source->contract_status === 'executed',
            'The production contract status is executed.');
        $this->check($checks, 'facet.contract', 'legal',
            $facetContract === 'active'
                && ($contractExpiresAt === null || $contractExpiresAt->greaterThan($evaluatedFor)),
            'The governed contract facet is active and unexpired at the evaluated activation time.');
        $this->check($checks, 'facet.incident', 'incident',
            in_array($facetIncident, ['none', 'resolved'], true),
            'No source incident is open or under monitoring.');
        $this->evidenceCheck($checks, $evidence, 'contract', $evaluatedFor, false);
        if ((bool) $source->phi_allowed) {
            $this->check($checks, 'legal.baa_status', 'legal', (string) $source->baa_status === 'executed',
                'The BAA status is executed for a PHI-enabled source.');
            $this->evidenceCheck($checks, $evidence, 'baa', $evaluatedFor, false);
        }
        $this->evidenceCheck($checks, $evidence, 'dua', $evaluatedFor, true);
        $this->evidenceCheck($checks, $evidence, 'conformance_report', $evaluatedFor, false);
        if ((string) $profile->support_entitlement !== 'none') {
            $this->evidenceCheck($checks, $evidence, 'vendor_approval', $evaluatedFor, false);
        }
        $this->evidenceCheck($checks, $evidence, 'customer_uat', $evaluatedFor, false);
        $this->evidenceCheck($checks, $evidence, 'test_results', $evaluatedFor, false);
        $this->evidenceCheck($checks, $evidence, 'security_review', $evaluatedFor, false);
        $this->evidenceCheck($checks, $evidence, 'change_ticket', $evaluatedFor, false);
        $this->evidenceCheck($checks, $evidence, 'cutover_plan', $evaluatedFor, false);
        $this->evidenceCheck($checks, $evidence, 'rollback_plan', $evaluatedFor, false);

        $passed = collect($checks)->where('status', 'passed')->count();
        $total = count($checks);
        $score = $total > 0 ? (int) floor(($passed / $total) * 100) : 0;
        $status = $passed === $total ? 'ready' : 'not_ready';
        $input = [
            'source_id' => $sourceId,
            'configuration_version_id' => (int) $source->current_configuration_version_id,
            'configuration_sha256' => (string) $source->configuration_sha256,
            'onboarding_version_id' => (int) $profile->source_onboarding_version_id,
            'profile_sha256' => (string) $profile->profile_sha256,
            'evidence' => $evidence->map(fn (object $row): array => [
                'id' => (int) $row->source_evidence_record_id,
                'type' => (string) $row->evidence_type,
                'status' => (string) $row->evidence_status,
                'reference_sha256' => (string) $row->reference_sha256,
                'artifact_sha256' => $row->artifact_sha256,
                'expires_at' => $row->expires_at,
            ])->sortKeys()->all(),
            'active_endpoint_count' => $activeEndpoints,
            'active_credential_count' => $activeCredentials,
            'endpoints' => $activeEndpointRows->map(fn (object $endpoint): array => [
                'id' => (int) $endpoint->source_endpoint_id,
                'type' => (string) $endpoint->endpoint_type,
                'url_sha256' => hash('sha256', (string) $endpoint->url),
                'auth_type' => $endpoint->auth_type,
                'tls_mode' => $endpoint->tls_mode,
                'updated_at' => $endpoint->updated_at,
            ])->all(),
            'credentials' => $activeCredentialRows->map(fn (object $credential): array => [
                'id' => (int) $credential->source_credential_id,
                'key' => (string) $credential->credential_key,
                'type' => (string) $credential->credential_type,
                'secret_ref_sha256' => filled($credential->secret_ref)
                    ? hash('sha256', (string) $credential->secret_ref)
                    : null,
                'certificate_ref_sha256' => filled($credential->certificate_ref)
                    ? hash('sha256', (string) $credential->certificate_ref)
                    : null,
                'jwks_uri_sha256' => filled($credential->jwks_uri)
                    ? hash('sha256', (string) $credential->jwks_uri)
                    : null,
                'rotates_at' => $credential->rotates_at,
                'updated_at' => $credential->updated_at,
            ])->all(),
            'credential_validation' => $credentialAssessments->map(fn (array $assessment): array => [
                'credential_id' => $assessment['credentialId'],
                'credential_version_id' => $assessment['credentialVersionId'],
                'status' => $assessment['status'],
                'rotation_state' => $assessment['rotationState'],
                'provider_scheme' => $assessment['providerScheme'],
                'provider_version' => $assessment['providerVersion'],
                'provider_lease_expires_at' => $assessment['providerLeaseExpiresAtIso'],
                'input_sha256' => $assessment['inputSha256'],
            ])->all(),
            'network_routes' => $networkAssessments->all(),
            'status_facets' => [
                'conformance' => $facetConformance,
                'contract' => $facetContract,
                'contract_expires_at' => $contractExpiresAt?->utc()->toIso8601String(),
                'incident' => $facetIncident,
            ],
            'payload_protection' => [
                'status' => $payloadReadiness['status'],
                'error_code' => $payloadReadiness['errorCode'],
                'disk' => $payloadReadiness['disk'] ?? null,
                'driver' => $payloadReadiness['driver'] ?? null,
                'cipher' => $payloadReadiness['cipher'] ?? null,
                'compression' => $payloadReadiness['compression'] ?? null,
                'key_provider_scheme' => $payloadReadiness['keyProviderScheme'] ?? null,
                'key_provider_version' => $payloadReadiness['keyProviderVersion'] ?? null,
                'key_reference_configured' => $payloadReadiness['keyReferenceConfigured'] ?? false,
                'provider_reachable' => $payloadReadiness['providerReachable'] ?? false,
            ],
            'evaluated_for_at' => $evaluatedFor->utc()->toIso8601String(),
            'requirements' => $checks,
        ];
        $inputHash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $assessmentId = null;
        $assessmentUuid = null;
        $evaluatedAt = now();

        if ($persist) {
            $assessmentUuid = (string) Str::uuid7();
            $assessmentId = (int) DB::table('integration.source_readiness_assessments')->insertGetId([
                'assessment_uuid' => $assessmentUuid,
                'source_id' => $sourceId,
                'configuration_version_id' => $source->current_configuration_version_id,
                'onboarding_version_id' => $profile->source_onboarding_version_id,
                'readiness_status' => $status,
                'readiness_score' => $score,
                'requirement_results' => json_encode($checks, JSON_THROW_ON_ERROR),
                'input_sha256' => $inputHash,
                'evaluated_for_at' => $evaluatedFor,
                'evaluated_by_user_id' => $actorUserId,
                'evaluated_at' => $evaluatedAt,
            ], 'source_readiness_assessment_id');
        }

        return [
            'readinessAssessmentId' => $assessmentId,
            'assessmentUuid' => $assessmentUuid,
            'sourceId' => $sourceId,
            'configurationVersionId' => (int) $source->current_configuration_version_id,
            'configurationSha256' => (string) $source->configuration_sha256,
            'onboardingVersionId' => (int) $profile->source_onboarding_version_id,
            'onboardingProfileSha256' => (string) $profile->profile_sha256,
            'status' => $status,
            'score' => $score,
            'passedCount' => $passed,
            'requirementCount' => $total,
            'requirements' => $checks,
            'supportBadges' => $this->supportBadges($source, $checks),
            'inputSha256' => $inputHash,
            'evaluatedForAtIso' => $evaluatedFor->toIso8601String(),
            'evaluatedAtIso' => $evaluatedAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function requireReady(
        int $sourceId,
        CarbonImmutable $evaluatedFor,
        ?int $actorUserId = null,
    ): array {
        $assessment = $this->evaluate($sourceId, $evaluatedFor, $actorUserId, persist: true);
        if ($assessment['status'] !== 'ready') {
            $failed = collect($assessment['requirements'])
                ->where('status', 'failed')
                ->pluck('code')
                ->values()
                ->all();
            throw ValidationException::withMessages([
                'readiness' => 'Source readiness is incomplete: '.implode(', ', $failed).'.',
            ]);
        }

        return $assessment;
    }

    private function source(int $sourceId): object
    {
        return DB::table('integration.sources as source')
            ->leftJoin('hosp_org.facilities as facility', 'facility.facility_id', '=', 'source.facility_id')
            ->leftJoin(
                'integration.source_configuration_versions as version',
                'version.source_configuration_version_id',
                '=',
                'source.current_configuration_version_id',
            )
            ->where('source.source_id', $sourceId)
            ->select([
                'source.*',
                'facility.is_active as facility_is_active',
                'version.configuration_sha256',
            ])->firstOrFail();
    }

    /** @param list<array<string, string>> $checks */
    private function check(array &$checks, string $code, string $category, bool $passed, string $message): void
    {
        $checks[] = [
            'code' => $code,
            'category' => $category,
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
        ];
    }

    /** @param list<array<string, string>> $checks */
    private function evidenceCheck(
        array &$checks,
        \Illuminate\Support\Collection $evidence,
        string $type,
        CarbonImmutable $evaluatedFor,
        bool $allowNotRequired,
    ): void {
        $row = $evidence->get($type);
        $statusAllowed = $row !== null && ((string) $row->evidence_status === 'verified'
            || ($allowNotRequired && (string) $row->evidence_status === 'not_required'));
        $unexpired = $row !== null && ($row->expires_at === null
            || CarbonImmutable::parse((string) $row->expires_at)->greaterThan($evaluatedFor));
        $this->check(
            $checks,
            'evidence.'.$type,
            'evidence',
            $statusAllowed && $unexpired,
            str_replace('_', ' ', ucfirst($type)).' evidence is current and independently referenced.',
        );
    }

    /** @param array<string, mixed> $slo */
    private function sloComplete(array $slo): bool
    {
        foreach (self::REQUIRED_SLO_KEYS as $key) {
            if (! array_key_exists($key, $slo) || ! is_numeric($slo[$key])) {
                return false;
            }
        }

        return (float) $slo['availability_percent'] >= 90
            && (float) $slo['availability_percent'] <= 100
            && (float) $slo['freshness_minutes'] > 0
            && (float) $slo['completeness_percent'] >= 0
            && (float) $slo['completeness_percent'] <= 100
            && (float) $slo['latency_ms'] > 0
            && (float) $slo['error_rate_percent'] >= 0
            && (float) $slo['error_rate_percent'] <= 100
            && (float) $slo['acknowledgement_seconds'] > 0
            && (float) $slo['reconciliation_variance_percent'] >= 0
            && (float) $slo['reconciliation_variance_percent'] <= 100;
    }

    /** @param list<array<string, string>> $checks
     * @return list<string>
     */
    private function supportBadges(object $source, array $checks): array
    {
        $passed = collect($checks)->where('status', 'passed')->pluck('code')->flip();
        $badges = ['template'];
        if ($source->current_configuration_version_id !== null) {
            $badges[] = 'implemented';
        }
        if ($passed->has('profile.conformance') && $passed->has('evidence.conformance_report')) {
            $badges[] = 'conformance-tested';
        }
        if ($passed->has('evidence.test_results') && $passed->has('evidence.vendor_approval')) {
            $badges[] = 'vendor-sandbox-tested';
        }
        if ($passed->has('evidence.customer_uat')) {
            $badges[] = 'customer-UAT';
        }
        if (collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed')) {
            $badges[] = 'production-certified';
        }
        if (in_array((string) $source->lifecycle_state, ['live', 'degraded'], true)) {
            $badges[] = 'live';
        }

        return $badges;
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
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }
}
