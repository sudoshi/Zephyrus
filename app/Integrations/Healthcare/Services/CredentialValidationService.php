<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Network\IntegrationUrlPolicy;
use App\Security\Secrets\ResolvedSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CredentialValidationService
{
    public function __construct(
        private readonly CredentialAuthorityService $authority,
        private readonly IntegrationSecretReferenceResolver $secrets,
        private readonly CertificateInspector $certificates,
        private readonly IntegrationUrlPolicy $urlPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(
        int $credentialId,
        CarbonImmutable $evaluatedFor,
        ?int $actorUserId = null,
        bool $persist = true,
    ): array {
        $credential = DB::table('integration.source_credentials')
            ->where('source_credential_id', $credentialId)
            ->firstOrFail();
        $version = $credential->current_credential_version_id === null
            ? $this->authority->initialize($credentialId, $actorUserId)
            : $this->authority->current($credentialId);
        $requirements = [];
        $errorCode = null;
        $secretResolution = null;
        $certificateResolution = null;
        $certificateMetadata = [];

        $this->check(
            $requirements,
            'credential.state',
            in_array((string) $version->credential_state, ['active', 'rotating'], true),
            'The credential authority state is active or rotating.',
        );
        $this->check(
            $requirements,
            'credential.valid_from',
            $version->valid_from === null
                || CarbonImmutable::parse((string) $version->valid_from)->lessThanOrEqualTo($evaluatedFor),
            'The credential is valid at the evaluated time.',
        );
        $this->check(
            $requirements,
            'credential.expires_at',
            $version->expires_at === null
                || CarbonImmutable::parse((string) $version->expires_at)->greaterThan($evaluatedFor),
            'Credential expiry extends beyond the evaluated time.',
        );
        $this->check(
            $requirements,
            'credential.rotates_at',
            $version->rotates_at === null
                || CarbonImmutable::parse((string) $version->rotates_at)->greaterThan($evaluatedFor),
            'The scheduled rotation deadline extends beyond the evaluated time.',
        );

        if (filled($version->secret_ref)) {
            try {
                $secretResolution = $this->secrets->resolveWithMetadata((string) $version->secret_ref);
                $this->check($requirements, 'provider.secret_access', true, 'The secret provider returned the referenced version.');
            } catch (IntegrationCredentialException $exception) {
                $errorCode ??= $exception->errorCode;
                $this->check($requirements, 'provider.secret_access', false, 'The secret provider must return the referenced version.');
            }
        }

        if (filled($version->certificate_ref)) {
            try {
                $certificateResolution = $this->secrets->resolveWithMetadata((string) $version->certificate_ref);
                $certificateMetadata = $this->certificates->inspect($certificateResolution->value(), $evaluatedFor);
                $this->check($requirements, 'provider.certificate_access', true, 'The certificate provider returned a valid PEM certificate chain.');
            } catch (IntegrationCredentialException $exception) {
                $errorCode ??= $exception->errorCode;
                $this->check($requirements, 'provider.certificate_access', false, 'The certificate provider must return a currently valid PEM certificate chain.');
            }
        }

        if (filled($version->jwks_uri)) {
            try {
                $this->urlPolicy->assertSafe((string) $version->jwks_uri);
                $this->check($requirements, 'credential.jwks_authority', true, 'The JWKS endpoint remains network-policy compliant.');
            } catch (\Throwable) {
                $errorCode ??= 'credential_jwks_authority_unsafe';
                $this->check($requirements, 'credential.jwks_authority', false, 'The JWKS endpoint must remain network-policy compliant.');
            }
        }

        if ((string) $version->credential_type === 'mtls') {
            $this->check(
                $requirements,
                'credential.mtls_certificate',
                filled($version->certificate_ref) && $certificateMetadata !== [],
                'mTLS credentials require a resolvable, currently valid certificate chain.',
            );
            $this->check(
                $requirements,
                'credential.mtls_private_key',
                filled($version->secret_ref) && $secretResolution instanceof ResolvedSecret,
                'mTLS credentials require a resolvable private-key reference.',
            );
            $this->check(
                $requirements,
                'credential.mtls_key_pair',
                $certificateResolution instanceof ResolvedSecret
                    && $secretResolution instanceof ResolvedSecret
                    && $this->certificates->matchesPrivateKey(
                        $certificateResolution->value(),
                        $secretResolution->value(),
                    ),
                'The mTLS certificate and private key must form the same key pair.',
            );
        }

        foreach (array_filter([
            'secret' => $secretResolution,
            'certificate' => $certificateResolution,
        ]) as $kind => $resolution) {
            if (! $resolution instanceof ResolvedSecret) {
                continue;
            }
            $this->check(
                $requirements,
                'provider.version.'.str_replace('-', '_', $resolution->providerScheme).'.'.$kind,
                filled($resolution->providerVersion),
                'The provider returned an immutable version identifier for the resolved reference.',
            );
            $this->check(
                $requirements,
                'provider.lease.'.str_replace('-', '_', $resolution->providerScheme).'.'.$kind,
                $resolution->leaseExpiresAt === null || $resolution->leaseExpiresAt->greaterThan($evaluatedFor),
                'The provider lease extends beyond the evaluated time.',
            );
            $this->check(
                $requirements,
                'provider.expiry.'.str_replace('-', '_', $resolution->providerScheme).'.'.$kind,
                $resolution->expiresAt === null || $resolution->expiresAt->greaterThan($evaluatedFor),
                'The provider-managed secret expiry extends beyond the evaluated time.',
            );
        }

        $failed = collect($requirements)->where('status', 'failed')->count();
        $status = $failed === 0 ? 'ready' : 'not_ready';
        $rotationState = $this->rotationState($version, $evaluatedFor);
        $input = [
            'source_id' => (int) $credential->source_id,
            'credential_id' => $credentialId,
            'credential_version_id' => (int) $version->source_credential_version_id,
            'authority_sha256' => (string) $version->authority_sha256,
            'references' => $this->referenceFingerprints($version),
            'provider' => [
                'secret' => $secretResolution?->safeMetadata(),
                'certificate' => $certificateResolution?->safeMetadata(),
            ],
            'certificate' => $certificateMetadata,
            'requirements' => $requirements,
            'validated_for_at' => $evaluatedFor->utc()->toIso8601String(),
        ];
        $inputSha = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));
        $observationId = null;
        if ($persist) {
            $primary = $secretResolution ?? $certificateResolution;
            $observationId = (int) DB::table('integration.credential_validation_observations')->insertGetId([
                'observation_uuid' => (string) Str::uuid7(),
                'source_id' => $credential->source_id,
                'source_credential_id' => $credentialId,
                'credential_version_id' => $version->source_credential_version_id,
                'validation_status' => $status,
                'provider_scheme' => $primary?->providerScheme,
                'provider_version' => $primary?->providerVersion,
                'provider_lease_expires_at' => $primary?->leaseExpiresAt,
                'credential_expires_at' => $version->expires_at,
                'rotation_state' => $rotationState,
                'reference_fingerprints' => json_encode((object) $this->referenceFingerprints($version), JSON_THROW_ON_ERROR),
                'certificate_metadata' => json_encode((object) $certificateMetadata, JSON_THROW_ON_ERROR),
                'requirement_results' => json_encode($requirements, JSON_THROW_ON_ERROR),
                'input_sha256' => $inputSha,
                'error_code' => $errorCode,
                'validated_for_at' => $evaluatedFor,
                'validated_by_user_id' => $actorUserId,
                'observed_at' => now(),
            ], 'credential_validation_observation_id');
        }

        return [
            'credentialValidationObservationId' => $observationId,
            'credentialId' => $credentialId,
            'credentialVersionId' => (int) $version->source_credential_version_id,
            'credentialVersionNumber' => (int) $version->version_number,
            'status' => $status,
            'rotationState' => $rotationState,
            'providerScheme' => ($secretResolution ?? $certificateResolution)?->providerScheme,
            'providerVersion' => ($secretResolution ?? $certificateResolution)?->providerVersion,
            'providerLeaseExpiresAtIso' => ($secretResolution ?? $certificateResolution)?->leaseExpiresAt?->toIso8601String(),
            'certificateMetadata' => $certificateMetadata,
            'requirements' => $requirements,
            'inputSha256' => $inputSha,
            'errorCode' => $errorCode,
            'evaluatedForAtIso' => $evaluatedFor->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function referenceFingerprints(object $version): array
    {
        return array_filter([
            'secret' => filled($version->secret_ref) ? hash('sha256', (string) $version->secret_ref) : null,
            'certificate' => filled($version->certificate_ref) ? hash('sha256', (string) $version->certificate_ref) : null,
            'jwks' => filled($version->jwks_uri) ? hash('sha256', (string) $version->jwks_uri) : null,
        ], 'is_string');
    }

    private function rotationState(object $version, CarbonImmutable $evaluatedFor): string
    {
        if ((string) $version->credential_state === 'revoked') {
            return 'revoked';
        }
        if ((string) $version->credential_state === 'disabled') {
            return 'disabled';
        }
        $deadline = collect([$version->rotates_at, $version->expires_at])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value))
            ->sort()
            ->first();
        if (! $deadline instanceof CarbonImmutable) {
            return 'current';
        }
        if ($deadline->lessThanOrEqualTo($evaluatedFor)) {
            return 'expired';
        }
        $days = $evaluatedFor->diffInDays($deadline, false);
        $thresholds = config('integrations.credential_rotation_threshold_days', [90, 60, 30, 14, 7]);
        sort($thresholds, SORT_NUMERIC);
        foreach ($thresholds as $threshold) {
            if ($days <= (int) $threshold) {
                return 'due_'.(int) $threshold;
            }
        }

        return 'current';
    }

    /** @param list<array<string, string>> $requirements */
    private function check(array &$requirements, string $code, bool $passed, string $message): void
    {
        $requirements[] = [
            'code' => $code,
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
        ];
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = array_is_list($item)
                    ? array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->canonicalize($entry) : $entry, $item)
                    : $this->canonicalize($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
