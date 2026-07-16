<?php

namespace App\Http\Controllers\Api\Admin;

use App\Authorization\GovernedAction;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use App\Integrations\Healthcare\Services\SourceActivationWindowService;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Integrations\Healthcare\Services\SourceReadinessService;
use App\Models\Governance\GovernedChangeRequest;
use App\Rules\SafeIntegrationUrl;
use App\Rules\SecretReference;
use App\Security\Network\IntegrationUrlPolicy;
use App\Services\Authorization\AdminScopeService;
use App\Services\Governance\GovernanceViolation;
use App\Services\Governance\GovernedChangeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GovernedIntegrationChangeController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly GovernedChangeService $governance,
        private readonly IntegrationConfigurationService $configuration,
        private readonly SourceConfigurationVersionService $versions,
        private readonly SourceLifecycleService $lifecycle,
        private readonly SourceReadinessService $readiness,
        private readonly SourceActivationWindowService $activationWindows,
        private readonly AdminScopeService $scopes,
    ) {}

    public function requestSourceActivation(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $sourceRow = $this->source($source);
        $this->assertActivationReady($sourceRow);
        $assessment = $this->readiness->requireReady(
            $source,
            CarbonImmutable::now(),
            $request->user()?->getAuthIdentifier(),
        );

        $payloadHash = $this->governance->hashPayload($this->activationContract($sourceRow, [
            'readiness_assessment_id' => $assessment['readinessAssessmentId'],
            'readiness_input_sha256' => $assessment['inputSha256'],
        ]));
        $change = $this->governance->requestChange(
            $request,
            GovernedAction::ActivateProductionSource,
            'integration_source',
            (string) $source,
            (string) $validated['reason'],
            $payloadHash,
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            ['readiness_assessment_id' => $assessment['readinessAssessmentId']],
        );

        return response()->json(['data' => $this->changePayload($change)], 201);
    }

    public function requestScheduledSourceActivation(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'activate_at' => ['required', 'date', 'after:+1 minute', 'before:+1 year'],
            'window_ends_at' => ['required', 'date', 'after:activate_at'],
            'requested_timezone' => ['required', 'timezone:all'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $activateAt = CarbonImmutable::parse((string) $validated['activate_at']);
        $windowEndsAt = CarbonImmutable::parse((string) $validated['window_ends_at']);
        $sourceRow = $this->source($source);
        $this->assertActivationReady($sourceRow);

        return DB::transaction(function () use (
            $request,
            $source,
            $validated,
            $activateAt,
            $windowEndsAt,
        ): JsonResponse {
            $assessment = $this->readiness->requireReady(
                $source,
                $activateAt,
                $request->user()?->getAuthIdentifier(),
            );
            $windowUuid = $this->activationWindows->newUuid();
            $contract = $this->activationWindows->requestContract(
                $source,
                $windowUuid,
                $activateAt,
                $windowEndsAt,
                (string) $validated['requested_timezone'],
                $assessment,
            );
            $change = $this->governance->requestChange(
                $request,
                GovernedAction::ScheduleProductionSourceActivation,
                'source_activation_window',
                $windowUuid,
                (string) $validated['reason'],
                $this->governance->hashPayload($contract),
                $this->scopes->requireSource($request, $source)->authorizationScope(),
                [
                    'activation_window_uuid' => $windowUuid,
                    'source_id' => $source,
                    'activate_at' => $activateAt->utc()->toIso8601String(),
                    'window_ends_at' => $windowEndsAt->utc()->toIso8601String(),
                    'readiness_assessment_id' => $assessment['readinessAssessmentId'],
                ],
            );
            $window = $this->activationWindows->createPending(
                $source,
                $windowUuid,
                (string) $change->getKey(),
                $activateAt,
                $windowEndsAt,
                (string) $validated['requested_timezone'],
                $assessment,
                (int) $request->user()->getAuthIdentifier(),
                (string) $validated['reason'],
            );

            return response()->json(['data' => [
                ...$this->changePayload($change),
                'activationWindow' => $this->activationWindows->payload($window),
            ]], 201);
        });
    }

    public function decide(Request $request, string $changeRequestUuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $decision = $this->governance->decide(
            $request,
            $changeRequestUuid,
            $validated['decision'] === 'approved',
            (string) $validated['reason'],
        );

        return response()->json(['data' => [
            'changeRequestUuid' => $decision->change_request_uuid,
            'decision' => $decision->decision,
            'decidedAt' => $decision->decided_at?->toIso8601String(),
        ]]);
    }

    public function requestSourceConfigurationApplication(
        Request $request,
        int $source,
        int $version,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $sourceRow = $this->source($source);
        $versionRow = $this->versions->record($source, $version);
        $this->assertConfigurationApplicationReady($sourceRow, $versionRow);
        $payloadHash = $this->governance->hashPayload(
            $this->configurationApplicationContract($sourceRow, $versionRow),
        );
        $change = $this->governance->requestChange(
            $request,
            GovernedAction::ApplySourceConfiguration,
            'integration_source_configuration',
            $source.':'.$version,
            (string) $validated['reason'],
            $payloadHash,
            $this->scopes->requireSource($request, $source)->authorizationScope(),
        );

        return response()->json(['data' => $this->changePayload($change)], 201);
    }

    public function requestCredentialRotation(Request $request, int $source, int $credential): JsonResponse
    {
        $validated = $this->validateCredentialRotation($request, includeReason: true);
        $sourceRow = $this->source($source);
        $credentialRow = $this->credential($source, $credential);
        $this->assertCredentialTargetRemainsUsable($credentialRow, $validated);
        $payloadHash = $this->governance->hashPayload(
            $this->credentialRotationContract($sourceRow, $credentialRow, $validated),
        );
        $change = $this->governance->requestChange(
            $request,
            GovernedAction::RotateIntegrationCredential,
            'integration_credential',
            $source.':'.$credential,
            (string) $validated['reason'],
            $payloadHash,
            $this->scopes->requireSource($request, $source)->authorizationScope(),
        );

        return response()->json(['data' => $this->changePayload($change)], 201);
    }

    public function executeCredentialRotation(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $credential,
    ): JsonResponse {
        $validated = $this->validateCredentialRotation($request, includeReason: false);
        $sourceRow = $this->source($source);
        $credentialRow = $this->credential($source, $credential);
        $this->assertCredentialTargetRemainsUsable($credentialRow, $validated);
        $payloadHash = $this->governance->hashPayload(
            $this->credentialRotationContract($sourceRow, $credentialRow, $validated),
        );
        $updates = collect($validated)->except('reason')->all();

        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::RotateIntegrationCredential,
            'integration_credential',
            $source.':'.$credential,
            $payloadHash,
            fn (GovernedChangeRequest $approved): array => $this->configuration->rotateCredential(
                $source,
                $credential,
                $updates,
                $request->user()?->getAuthIdentifier(),
                $this->correlationId($request),
                (string) $approved->reason,
                (string) $approved->getKey(),
            ),
        );

        return response()->json(['data' => $result]);
    }

    public function executeSourceActivation(Request $request, string $changeRequestUuid): JsonResponse
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        $sourceId = ctype_digit((string) $change->subject_id) ? (int) $change->subject_id : 0;
        if ($sourceId < 1) {
            abort(404);
        }

        $source = $this->source($sourceId);
        $this->assertActivationReady($source);
        $approvedAssessment = $this->approvedReadinessAssessment($change, $sourceId);
        $fresh = $this->readiness->evaluate(
            $sourceId,
            CarbonImmutable::parse((string) $approvedAssessment->evaluated_for_at),
            persist: false,
        );
        if ($fresh['status'] !== 'ready'
            || ! hash_equals((string) $approvedAssessment->input_sha256, (string) $fresh['inputSha256'])) {
            throw new GovernanceViolation('approved_payload_mismatch', 'Source readiness evidence changed after approval.');
        }
        $this->readiness->requireReady(
            $sourceId,
            CarbonImmutable::now(),
            $request->user()?->getAuthIdentifier(),
        );
        $payloadHash = $this->governance->hashPayload($this->activationContract($source, [
            'readiness_assessment_id' => (int) $approvedAssessment->source_readiness_assessment_id,
            'readiness_input_sha256' => (string) $approvedAssessment->input_sha256,
        ]));

        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ActivateProductionSource,
            'integration_source',
            (string) $sourceId,
            $payloadHash,
            function (GovernedChangeRequest $approved) use ($request, $sourceId): array {
                $this->lifecycle->transitionToLive(
                    $sourceId,
                    (string) $approved->reason,
                    $request->user()?->getAuthIdentifier(),
                    (string) $approved->getKey(),
                );

                return $this->configuration->source($sourceId);
            },
        );

        return response()->json(['data' => $result]);
    }

    public function executeScheduledSourceActivation(Request $request, string $changeRequestUuid): JsonResponse
    {
        $window = $this->activationWindows->windowForChange($changeRequestUuid);
        $payloadHash = $this->governance->hashPayload($this->activationWindows->contract($window));
        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ScheduleProductionSourceActivation,
            'source_activation_window',
            (string) $window->activation_window_uuid,
            $payloadHash,
            fn (): array => $this->activationWindows->schedule(
                $changeRequestUuid,
                (int) $request->user()->getAuthIdentifier(),
            ),
        );

        return response()->json(['data' => $result]);
    }

    public function executeSourceConfigurationApplication(
        Request $request,
        string $changeRequestUuid,
    ): JsonResponse {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        if (preg_match('/^(\d+):(\d+)$/', (string) $change->subject_id, $matches) !== 1) {
            abort(404);
        }
        $sourceId = (int) $matches[1];
        $versionId = (int) $matches[2];
        $source = $this->source($sourceId);
        $version = $this->versions->record($sourceId, $versionId);
        $this->assertConfigurationApplicationReady($source, $version);
        $payloadHash = $this->governance->hashPayload(
            $this->configurationApplicationContract($source, $version),
        );

        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ApplySourceConfiguration,
            'integration_source_configuration',
            $sourceId.':'.$versionId,
            $payloadHash,
            function (GovernedChangeRequest $approved) use ($request, $sourceId, $versionId): array {
                $this->versions->apply($sourceId, $versionId);
                $this->lifecycle->resetAfterConfigurationChange(
                    $sourceId,
                    (string) $approved->reason,
                    $request->user()?->getAuthIdentifier(),
                    (string) $approved->getKey(),
                );

                return $this->configuration->source($sourceId);
            },
        );

        return response()->json(['data' => $result]);
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
                'version.version_number as configuration_version_number',
                'version.configuration_sha256',
            ])->firstOrFail();
    }

    private function credential(int $sourceId, int $credentialId): object
    {
        return DB::table('integration.source_credentials')
            ->where('source_id', $sourceId)
            ->where('source_credential_id', $credentialId)
            ->firstOrFail();
    }

    private function assertActivationReady(object $source): void
    {
        $errors = [];
        if ((string) $source->environment !== 'production') {
            $errors['environment'] = 'Only a production source uses the production activation workflow.';
        }
        if (! isset($source->organization_id, $source->facility_id) || ! filled($source->facility_key)) {
            $errors['facility_id'] = 'Canonical organization and facility scopes are required before activation.';
        }
        if (isset($source->facility_is_active) && ! (bool) $source->facility_is_active) {
            $errors['facility_id'] = 'The canonical facility must be active before source activation.';
        }
        if (! in_array((string) $source->active_status, ['testing', 'degraded', 'inactive'], true)) {
            $errors['active_status'] = 'The source must be testing, degraded, or inactive before activation.';
        }
        if (! in_array((string) $source->go_live_status, ['testing', 'ready', 'paused'], true)) {
            $errors['go_live_status'] = 'The source must be testing, ready, or paused before activation.';
        }
        if ((string) $source->contract_status !== 'executed') {
            $errors['contract_status'] = 'An executed contract is required before production activation.';
        }
        if ((bool) $source->phi_allowed && (string) $source->baa_status !== 'executed') {
            $errors['baa_status'] = 'An executed BAA is required for a PHI-enabled source.';
        }
        if (! in_array((string) $source->lifecycle_state, ['validating', 'approved', 'scheduled', 'degraded', 'suspended'], true)) {
            $errors['lifecycle_state'] = 'The source must be validating, approved, scheduled, degraded, or suspended before activation.';
        }
        if ($source->current_configuration_version_id === null || ! filled($source->configuration_sha256)) {
            $errors['configuration_version_id'] = 'An immutable effective configuration version is required before activation.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array{readiness_assessment_id: int, readiness_input_sha256: string} $readiness
     * @return array<string, mixed>
     */
    private function activationContract(object $source, array $readiness): array
    {
        return [
            'source_id' => (int) $source->source_id,
            'source_uuid' => (string) $source->source_uuid,
            'source_key' => (string) $source->source_key,
            'organization_id' => (int) $source->organization_id,
            'facility_id' => (int) $source->facility_id,
            'facility_key' => (string) $source->facility_key,
            'environment' => (string) $source->environment,
            'interface_type' => (string) $source->interface_type,
            'current_active_status' => (string) $source->active_status,
            'current_go_live_status' => (string) $source->go_live_status,
            'contract_status' => (string) $source->contract_status,
            'baa_status' => (string) $source->baa_status,
            'phi_allowed' => (bool) $source->phi_allowed,
            'lifecycle_state' => (string) $source->lifecycle_state,
            'configuration_version_id' => (int) $source->current_configuration_version_id,
            'configuration_version_number' => (int) $source->configuration_version_number,
            'configuration_sha256' => (string) $source->configuration_sha256,
            'readiness_assessment_id' => $readiness['readiness_assessment_id'],
            'readiness_input_sha256' => $readiness['readiness_input_sha256'],
            'desired_active_status' => 'active',
            'desired_go_live_status' => 'live',
            'desired_lifecycle_state' => 'live',
        ];
    }

    private function approvedReadinessAssessment(GovernedChangeRequest $change, int $sourceId): object
    {
        $assessmentId = (int) ($change->metadata['readiness_assessment_id'] ?? 0);
        if ($assessmentId < 1) {
            throw new GovernanceViolation('approved_payload_mismatch', 'The activation approval has no readiness authority record.');
        }

        $assessment = DB::table('integration.source_readiness_assessments')
            ->where('source_readiness_assessment_id', $assessmentId)
            ->where('source_id', $sourceId)
            ->where('readiness_status', 'ready')
            ->first();
        if ($assessment === null) {
            throw new GovernanceViolation('approved_payload_mismatch', 'The approved readiness authority record is unavailable.');
        }

        return $assessment;
    }

    private function assertConfigurationApplicationReady(object $source, object $version): void
    {
        $errors = [];
        if ((int) $source->current_configuration_version_id === (int) $version->source_configuration_version_id) {
            $errors['configuration_version_id'] = 'The selected configuration version is already effective.';
        }
        if ((int) $version->previous_version_id !== (int) $source->current_configuration_version_id) {
            $errors['configuration_version_id'] = 'The proposal is not based on the currently effective configuration version.';
        }
        if ((string) $source->lifecycle_state === 'retired') {
            $errors['lifecycle_state'] = 'A retired source cannot accept a new configuration.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function configurationApplicationContract(object $source, object $version): array
    {
        $current = $this->versions->current((int) $source->source_id);

        return [
            'source_id' => (int) $source->source_id,
            'source_uuid' => (string) $source->source_uuid,
            'organization_id' => (int) $source->organization_id,
            'facility_id' => (int) $source->facility_id,
            'current_lifecycle_state' => (string) $source->lifecycle_state,
            'current_configuration_version_id' => (int) $current->source_configuration_version_id,
            'current_configuration_sha256' => (string) $current->configuration_sha256,
            'target_configuration_version_id' => (int) $version->source_configuration_version_id,
            'target_configuration_version_number' => (int) $version->version_number,
            'target_configuration_sha256' => (string) $version->configuration_sha256,
            'desired_lifecycle_state' => 'configured',
        ];
    }

    /** @return array<string, mixed> */
    private function validateCredentialRotation(Request $request, bool $includeReason): array
    {
        $rules = [
            'credential_type' => ['sometimes', Rule::in(['oauth2_client', 'smart_backend_services', 'mtls', 'api_key', 'basic_auth', 'jwks'])],
            'secret_ref' => ['sometimes', 'nullable', 'string', 'max:255', new SecretReference],
            'certificate_ref' => ['sometimes', 'nullable', 'string', 'max:255', new SecretReference],
            'jwks_uri' => ['sometimes', 'nullable', 'url', new SafeIntegrationUrl(app(IntegrationUrlPolicy::class))],
            'rotates_at' => ['sometimes', 'nullable', 'date', 'after:today'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
            'rotation_overlap_ends_at' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'secret' => ['prohibited'],
            'password' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'private_key' => ['prohibited'],
            'certificate' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
        if ($includeReason) {
            $rules['reason'] = ['required', 'string', 'min:10', 'max:500'];
        } else {
            $rules['reason'] = ['prohibited'];
        }

        $validated = $request->validate($rules);
        if (collect($validated)->except('reason')->isEmpty()) {
            throw ValidationException::withMessages([
                'credential' => 'At least one governed credential field must change.',
            ]);
        }

        return $validated;
    }

    /** @param array<string, mixed> $updates */
    private function assertCredentialTargetRemainsUsable(object $current, array $updates): void
    {
        $secretRef = array_key_exists('secret_ref', $updates) ? $updates['secret_ref'] : $current->secret_ref;
        $certificateRef = array_key_exists('certificate_ref', $updates) ? $updates['certificate_ref'] : $current->certificate_ref;
        $jwksUri = array_key_exists('jwks_uri', $updates) ? $updates['jwks_uri'] : $current->jwks_uri;
        if (! filled($secretRef) && ! filled($certificateRef) && ! filled($jwksUri)) {
            throw ValidationException::withMessages([
                'secret_ref' => 'The rotated credential must retain a secret, certificate, or JWKS reference.',
            ]);
        }
    }

    /** @param array<string, mixed> $updates @return array<string, mixed> */
    private function credentialRotationContract(object $source, object $credential, array $updates): array
    {
        return [
            'source_id' => (int) $source->source_id,
            'source_uuid' => (string) $source->source_uuid,
            'organization_id' => (int) $source->organization_id,
            'facility_id' => (int) $source->facility_id,
            'facility_key' => (string) $source->facility_key,
            'credential_id' => (int) $credential->source_credential_id,
            'credential_key' => (string) $credential->credential_key,
            'current_updated_at' => (string) $credential->updated_at,
            'current_credential_version_id' => $credential->current_credential_version_id !== null
                ? (int) $credential->current_credential_version_id
                : null,
            'changed_fields' => collect($updates)->except('reason')->keys()->sort()->values()->all(),
            // Reference identifiers never enter the governance ledger; their
            // exact target values participate only in this one-way contract hash.
            'target' => collect($updates)->except('reason')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function changePayload(GovernedChangeRequest $change): array
    {
        return [
            'changeRequestUuid' => $change->getKey(),
            'action' => $change->action_type->value,
            'subjectType' => $change->subject_type,
            'subjectId' => $change->subject_id,
            'requestedAt' => $change->requested_at?->toIso8601String(),
            'expiresAt' => $change->expires_at?->toIso8601String(),
            'status' => 'pending_approval',
        ];
    }
}
