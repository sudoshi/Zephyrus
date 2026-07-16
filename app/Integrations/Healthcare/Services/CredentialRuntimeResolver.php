<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CredentialRuntimeResolver
{
    public function __construct(
        private readonly IntegrationSecretReferenceResolver $secrets,
        private readonly CredentialAuthorityService $authority,
    ) {}

    public function resolveSecret(int $sourceId, int $credentialId): ResolvedSecret
    {
        return $this->resolveReference($sourceId, $credentialId, 'secret_ref');
    }

    public function resolveCertificate(int $sourceId, int $credentialId): ResolvedSecret
    {
        return $this->resolveReference($sourceId, $credentialId, 'certificate_ref');
    }

    private function resolveReference(int $sourceId, int $credentialId, string $field): ResolvedSecret
    {
        $current = DB::table('integration.source_credential_versions as version')
            ->join('integration.source_credentials as credential', function ($join): void {
                $join->on('credential.current_credential_version_id', '=', 'version.source_credential_version_id')
                    ->on('credential.source_credential_id', '=', 'version.source_credential_id');
            })
            ->where('credential.source_id', $sourceId)
            ->where('credential.source_credential_id', $credentialId)
            ->select('version.*')
            ->first();
        if ($current === null) {
            throw new IntegrationCredentialException('credential_authority_required');
        }

        $now = CarbonImmutable::now();
        $this->assertUsable($current, $now, enforceRotationDeadline: true);
        try {
            $resolved = $this->resolveVersion($current, $field);
        } catch (IntegrationCredentialException $currentFailure) {
            if ((string) $current->credential_state !== 'rotating'
                || $current->previous_version_id === null
                || $current->rotation_overlap_ends_at === null
                || CarbonImmutable::parse((string) $current->rotation_overlap_ends_at)->lessThanOrEqualTo($now)) {
                throw $currentFailure;
            }
            $previous = DB::table('integration.source_credential_versions')
                ->where('source_credential_version_id', $current->previous_version_id)
                ->where('source_credential_id', $credentialId)
                ->where('source_id', $sourceId)
                ->first();
            if ($previous === null) {
                throw $currentFailure;
            }
            $this->assertUsable($previous, $now, enforceRotationDeadline: false);
            $resolved = $this->resolveVersion($previous, $field);
        }

        $this->authority->markUsed($credentialId);

        return $resolved;
    }

    private function resolveVersion(object $version, string $field): ResolvedSecret
    {
        $reference = $version->{$field} ?? null;
        if (! filled($reference)) {
            throw new IntegrationCredentialException(
                $field === 'certificate_ref'
                    ? 'credential_certificate_reference_required'
                    : 'credential_secret_reference_required',
            );
        }

        return $this->secrets->resolveWithMetadata((string) $reference);
    }

    private function assertUsable(object $version, CarbonImmutable $now, bool $enforceRotationDeadline): void
    {
        if (! in_array((string) $version->credential_state, ['active', 'rotating'], true)) {
            throw new IntegrationCredentialException('credential_authority_not_active');
        }
        if ($version->valid_from !== null
            && CarbonImmutable::parse((string) $version->valid_from)->greaterThan($now)) {
            throw new IntegrationCredentialException('credential_authority_not_yet_valid');
        }
        if ($version->expires_at !== null
            && CarbonImmutable::parse((string) $version->expires_at)->lessThanOrEqualTo($now)) {
            throw new IntegrationCredentialException('credential_authority_expired');
        }
        if ($enforceRotationDeadline
            && $version->rotates_at !== null
            && CarbonImmutable::parse((string) $version->rotates_at)->lessThanOrEqualTo($now)) {
            throw new IntegrationCredentialException('credential_rotation_overdue');
        }
    }
}
