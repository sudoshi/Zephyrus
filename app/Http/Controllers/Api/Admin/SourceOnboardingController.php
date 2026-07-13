<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\SourceActivationWindowService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceReadinessService;
use App\Services\Auth\StepUpAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SourceOnboardingController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly SourceOnboardingService $onboarding,
        private readonly SourceReadinessService $readiness,
        private readonly SourceActivationWindowService $activationWindows,
        private readonly IntegrationConfigurationAuditService $audit,
        private readonly StepUpAuthenticationService $stepUp,
    ) {}

    public function show(int $source): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->onboarding->snapshot($source),
            'readiness' => $this->readiness->evaluate(
                $source,
                CarbonImmutable::now(),
                persist: false,
            ),
            'activationWindows' => $this->activationWindows->windows($source),
        ]]);
    }

    public function storeVersion(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate($this->profileRules());
        $before = $this->onboarding->profilePayload($this->onboarding->latest($source));
        $version = $this->onboarding->revise(
            $source,
            collect($validated)->except(['expected_onboarding_version_id', 'change_reason'])->all(),
            (int) $validated['expected_onboarding_version_id'],
            $request->user()?->getAuthIdentifier(),
            (string) $validated['change_reason'],
        );
        $after = $this->onboarding->profilePayload($version);
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'versioned',
            'source_onboarding_profile',
            (int) $version->source_onboarding_version_id,
            'source:'.$source,
            $this->auditProfile($before),
            $this->auditProfile($after),
            $this->correlationId($request),
        );

        return response()->json(['data' => $after], 201);
    }

    public function storeEvidence(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'evidence_type' => ['required', Rule::in([
                'contract', 'baa', 'dua', 'conformance_report', 'vendor_approval',
                'customer_uat', 'test_results', 'security_review', 'change_ticket',
                'cutover_plan', 'rollback_plan',
            ])],
            'evidence_status' => ['required', Rule::in([
                'pending', 'verified', 'not_required', 'failed', 'expired', 'revoked',
            ])],
            'display_label' => ['required', 'string', 'min:3', 'max:190'],
            'reference_uri' => ['required', 'string', 'max:2048'],
            'artifact_sha256' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'supersedes_evidence_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $record = $this->onboarding->addEvidence(
            $source,
            $validated,
            $request->user()?->getAuthIdentifier(),
        );
        $payload = $this->onboarding->evidencePayload($record);
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'recorded',
            'source_evidence',
            (int) $record->source_evidence_record_id,
            'source:'.$source.':'.$record->evidence_type,
            [],
            collect($payload)->only([
                'evidenceRecordId', 'evidenceType', 'evidenceStatus', 'referenceFingerprint',
                'artifactSha256', 'expiresAtIso', 'supersedesEvidenceId',
            ])->all(),
            $this->correlationId($request),
        );

        return response()->json(['data' => $payload], 201);
    }

    public function assess(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'evaluated_for_at' => ['nullable', 'date', 'after_or_equal:now', 'before_or_equal:+1 year'],
        ]);
        $assessment = $this->readiness->evaluate(
            $source,
            isset($validated['evaluated_for_at'])
                ? CarbonImmutable::parse((string) $validated['evaluated_for_at'])
                : CarbonImmutable::now(),
            $request->user()?->getAuthIdentifier(),
            persist: true,
        );

        return response()->json(['data' => $assessment], 201);
    }

    public function cancelWindow(Request $request, int $source, string $windowUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $this->stepUp->assertSatisfied($request, 'source_activation_window_cancelled');
        $window = $this->activationWindows->cancel(
            $source,
            $windowUuid,
            (int) $request->user()->getAuthIdentifier(),
            (string) $validated['reason'],
        );
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'cancelled',
            'source_activation_window',
            $window['activationWindowId'],
            $windowUuid,
            [],
            collect($window)->only([
                'activationWindowId', 'sourceId', 'configurationVersionId', 'onboardingVersionId',
                'status', 'activateAtIso', 'windowEndsAtIso', 'cancelledAtIso', 'cancelledByUserId',
            ])->all(),
            $this->correlationId($request),
        );

        return response()->json(['data' => $window]);
    }

    /** @return array<string, list<mixed>> */
    private function profileRules(): array
    {
        return [
            'expected_onboarding_version_id' => ['required', 'integer', 'min:1'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
            'system_version' => ['nullable', 'string', 'max:120'],
            'protocol_profile' => ['nullable', 'string', 'max:160'],
            'owner_name' => ['nullable', 'string', 'max:160'],
            'steward_name' => ['nullable', 'string', 'max:160'],
            'network_route_key' => ['nullable', 'string', 'max:160'],
            'data_classification' => ['required', Rule::in([
                'unknown', 'public', 'internal', 'confidential', 'restricted_phi',
            ])],
            'permitted_purpose' => ['nullable', 'string', 'max:500'],
            'phi_permission_basis' => ['nullable', 'string', 'max:160'],
            'retention_policy_key' => ['nullable', 'string', 'max:120'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'credential_strategy' => ['nullable', Rule::in([
                'oauth2', 'smart_backend_services', 'mtls', 'api_key', 'basic_auth',
                'managed_interface_engine', 'other',
            ])],
            'conformance_status' => ['required', Rule::in([
                'not_tested', 'planned', 'testing', 'passed', 'failed', 'expired',
            ])],
            'support_entitlement' => ['required', Rule::in([
                'unknown', 'none', 'standard', 'premium', 'critical',
            ])],
            'vendor_support_identifier' => ['nullable', 'string', 'max:160'],
            'maintenance_timezone' => ['nullable', 'timezone:all'],
            'contacts' => ['array', 'max:20'],
            'contacts.*.role' => ['required', Rule::in([
                'owner', 'steward', 'technical', 'security', 'privacy', 'vendor', 'escalation',
            ])],
            'contacts.*.name' => ['required', 'string', 'max:160'],
            'contacts.*.email' => ['nullable', 'email:rfc', 'max:190'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'maintenance_windows' => ['array', 'max:20'],
            'maintenance_windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'maintenance_windows.*.start_local' => ['required', 'date_format:H:i'],
            'maintenance_windows.*.duration_minutes' => ['required', 'integer', 'between:15,1440'],
            'maintenance_windows.*.purpose' => ['required', 'string', 'max:160'],
            'slo_definition' => ['array'],
            'slo_definition.evaluation_window_minutes' => ['nullable', 'integer', 'between:5,10080'],
            'slo_definition.availability_percent' => ['nullable', 'numeric', 'between:90,100'],
            'slo_definition.freshness_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'slo_definition.completeness_percent' => ['nullable', 'numeric', 'between:0,100'],
            'slo_definition.latency_ms' => ['nullable', 'integer', 'between:1,3600000'],
            'slo_definition.error_rate_percent' => ['nullable', 'numeric', 'between:0,100'],
            'slo_definition.acknowledgement_seconds' => ['nullable', 'integer', 'between:1,86400'],
            'slo_definition.reconciliation_variance_percent' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function auditProfile(array $profile): array
    {
        return collect($profile)->only([
            'onboardingVersionId', 'versionNumber', 'profileSha256', 'dataClassification',
            'conformanceStatus', 'supportEntitlement', 'retentionDays', 'createdAtIso',
        ])->all();
    }
}
