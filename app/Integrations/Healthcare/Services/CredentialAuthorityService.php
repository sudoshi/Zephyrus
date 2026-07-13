<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\Network\IntegrationUrlPolicy;
use App\Security\Secrets\SecretProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CredentialAuthorityService
{
    public function __construct(
        private readonly SecretProviderRegistry $providers,
        private readonly IntegrationUrlPolicy $urlPolicy,
    ) {}

    public function initialize(
        int $credentialId,
        ?int $actorUserId,
        string $reason = 'Initial governed credential reference authority.',
    ): object {
        return DB::transaction(function () use ($credentialId, $actorUserId, $reason): object {
            $credential = $this->credential($credentialId, lock: true);
            if ($credential->current_credential_version_id !== null) {
                return $this->current($credentialId);
            }
            $authority = $this->normalize([
                'credential_type' => $credential->credential_type,
                'secret_ref' => $credential->secret_ref,
                'certificate_ref' => $credential->certificate_ref,
                'jwks_uri' => $credential->jwks_uri,
                'credential_state' => (bool) $credential->is_active ? 'active' : 'disabled',
                'valid_from' => $credential->created_at ?? now(),
                'expires_at' => $credential->expires_at,
                'rotates_at' => $credential->rotates_at,
                'rotation_overlap_ends_at' => $credential->rotation_overlap_ends_at,
            ]);
            $version = $this->insertVersion(
                $credential,
                1,
                null,
                $authority,
                $actorUserId,
                $reason,
                null,
            );
            $this->project($credential, $version, revokedAt: null);

            return $version;
        });
    }

    /** @param array<string, mixed> $updates */
    public function rotate(
        int $credentialId,
        array $updates,
        ?int $actorUserId,
        string $reason,
        ?string $governedChangeUuid,
    ): object {
        return DB::transaction(function () use (
            $credentialId,
            $updates,
            $actorUserId,
            $reason,
            $governedChangeUuid,
        ): object {
            $credential = $this->credential($credentialId, lock: true);
            $current = $credential->current_credential_version_id === null
                ? $this->initialize($credentialId, $actorUserId)
                : $this->current($credentialId, lock: true);
            if (in_array((string) $current->credential_state, ['revoked', 'expired'], true)) {
                throw ValidationException::withMessages([
                    'credential' => 'A revoked or expired credential cannot be rotated back into service.',
                ]);
            }

            $authority = $this->normalize([
                'credential_type' => $updates['credential_type'] ?? $current->credential_type,
                'secret_ref' => array_key_exists('secret_ref', $updates) ? $updates['secret_ref'] : $current->secret_ref,
                'certificate_ref' => array_key_exists('certificate_ref', $updates) ? $updates['certificate_ref'] : $current->certificate_ref,
                'jwks_uri' => array_key_exists('jwks_uri', $updates) ? $updates['jwks_uri'] : $current->jwks_uri,
                'credential_state' => $this->targetState($updates, $current),
                'valid_from' => $updates['valid_from'] ?? now(),
                'expires_at' => array_key_exists('expires_at', $updates) ? $updates['expires_at'] : $current->expires_at,
                'rotates_at' => array_key_exists('rotates_at', $updates) ? $updates['rotates_at'] : $current->rotates_at,
                'rotation_overlap_ends_at' => array_key_exists('rotation_overlap_ends_at', $updates)
                    ? $updates['rotation_overlap_ends_at']
                    : null,
            ]);
            if ($this->hash($authority) === (string) $current->authority_sha256) {
                throw ValidationException::withMessages(['credential' => 'The governed rotation does not change credential authority.']);
            }

            $version = $this->insertVersion(
                $credential,
                (int) $current->version_number + 1,
                (int) $current->source_credential_version_id,
                $authority,
                $actorUserId,
                $reason,
                $governedChangeUuid,
            );
            $this->project($credential, $version, revokedAt: null);

            return $version;
        });
    }

    public function revoke(
        int $credentialId,
        ?int $actorUserId,
        string $reason,
        ?string $governedChangeUuid = null,
    ): object {
        return DB::transaction(function () use ($credentialId, $actorUserId, $reason, $governedChangeUuid): object {
            $credential = $this->credential($credentialId, lock: true);
            $current = $credential->current_credential_version_id === null
                ? $this->initialize($credentialId, $actorUserId)
                : $this->current($credentialId, lock: true);
            if ((string) $current->credential_state === 'revoked') {
                return $current;
            }
            $authority = $this->normalize([
                'credential_type' => $current->credential_type,
                'secret_ref' => $current->secret_ref,
                'certificate_ref' => $current->certificate_ref,
                'jwks_uri' => $current->jwks_uri,
                'credential_state' => 'revoked',
                'valid_from' => $current->valid_from,
                'expires_at' => $current->expires_at,
                'rotates_at' => $current->rotates_at,
                'rotation_overlap_ends_at' => null,
            ]);
            $version = $this->insertVersion(
                $credential,
                (int) $current->version_number + 1,
                (int) $current->source_credential_version_id,
                $authority,
                $actorUserId,
                $reason,
                $governedChangeUuid,
            );
            $this->project($credential, $version, revokedAt: now());

            return $version;
        });
    }

    public function markUsed(int $credentialId): void
    {
        DB::table('integration.source_credentials')
            ->where('source_credential_id', $credentialId)
            ->whereIn('credential_state', ['active', 'rotating'])
            ->update(['last_used_at' => now(), 'updated_at' => now()]);
    }

    public function current(int $credentialId, bool $lock = false): object
    {
        $query = DB::table('integration.source_credential_versions as version')
            ->join('integration.source_credentials as credential', function ($join): void {
                $join->on('credential.current_credential_version_id', '=', 'version.source_credential_version_id')
                    ->on('credential.source_credential_id', '=', 'version.source_credential_id');
            })
            ->where('credential.source_credential_id', $credentialId)
            ->select('version.*');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    public function history(int $sourceId, int $credentialId): array
    {
        $this->credential($credentialId, sourceId: $sourceId);

        return DB::table('integration.source_credential_versions')
            ->where('source_credential_id', $credentialId)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (object $version): array => $this->payload($version))
            ->all();
    }

    /** @return array<string, mixed> */
    public function payload(object $version): array
    {
        return [
            'credentialVersionId' => (int) $version->source_credential_version_id,
            'credentialVersionUuid' => (string) $version->credential_version_uuid,
            'credentialId' => (int) $version->source_credential_id,
            'sourceId' => (int) $version->source_id,
            'versionNumber' => (int) $version->version_number,
            'previousVersionId' => $version->previous_version_id !== null ? (int) $version->previous_version_id : null,
            'credentialType' => (string) $version->credential_type,
            'credentialState' => (string) $version->credential_state,
            'secretReferenceConfigured' => filled($version->secret_ref),
            'secretProviderScheme' => $this->scheme($version->secret_ref),
            'certificateReferenceConfigured' => filled($version->certificate_ref),
            'certificateProviderScheme' => $this->scheme($version->certificate_ref),
            'jwksConfigured' => filled($version->jwks_uri),
            'validFromIso' => $this->iso($version->valid_from),
            'expiresAtIso' => $this->iso($version->expires_at),
            'rotatesAtIso' => $this->iso($version->rotates_at),
            'rotationOverlapEndsAtIso' => $this->iso($version->rotation_overlap_ends_at),
            'authoritySha256' => (string) $version->authority_sha256,
            'changeReason' => (string) $version->change_reason,
            'governedChangeRequestUuid' => $version->governed_change_request_uuid,
            'createdAtIso' => $this->iso($version->created_at),
        ];
    }

    /** @param array<string, mixed> $authority @return array<string, mixed> */
    private function normalize(array $authority): array
    {
        foreach (['secret_ref', 'certificate_ref'] as $key) {
            $authority[$key] = filled($authority[$key] ?? null) ? trim((string) $authority[$key]) : null;
            if ($authority[$key] !== null) {
                $this->providers->validate($authority[$key]);
            }
        }
        $authority['jwks_uri'] = filled($authority['jwks_uri'] ?? null) ? trim((string) $authority['jwks_uri']) : null;
        if ($authority['jwks_uri'] !== null) {
            $this->urlPolicy->assertSafe($authority['jwks_uri']);
        }
        $authority['credential_type'] = (string) $authority['credential_type'];
        $authority['credential_state'] = (string) $authority['credential_state'];
        if ($authority['secret_ref'] === null
            && $authority['certificate_ref'] === null
            && $authority['jwks_uri'] === null
            && ! in_array($authority['credential_state'], ['disabled', 'revoked', 'expired'], true)) {
            throw ValidationException::withMessages([
                'secret_ref' => 'A credential authority version requires a secret, certificate, or JWKS reference.',
            ]);
        }

        foreach (['valid_from', 'expires_at', 'rotates_at', 'rotation_overlap_ends_at'] as $key) {
            $authority[$key] = filled($authority[$key] ?? null)
                ? CarbonImmutable::parse((string) $authority[$key])->utc()->toIso8601String()
                : null;
        }
        if ($authority['valid_from'] !== null
            && $authority['expires_at'] !== null
            && CarbonImmutable::parse($authority['expires_at'])->lessThanOrEqualTo(CarbonImmutable::parse($authority['valid_from']))) {
            throw ValidationException::withMessages(['expires_at' => 'Credential expiry must be after its validity start.']);
        }
        if ($authority['valid_from'] !== null
            && $authority['rotation_overlap_ends_at'] !== null
            && CarbonImmutable::parse($authority['rotation_overlap_ends_at'])->lessThanOrEqualTo(CarbonImmutable::parse($authority['valid_from']))) {
            throw ValidationException::withMessages([
                'rotation_overlap_ends_at' => 'The rotation overlap must end after the new credential becomes valid.',
            ]);
        }

        return $authority;
    }

    /** @param array<string, mixed> $authority */
    private function insertVersion(
        object $credential,
        int $versionNumber,
        ?int $previousVersionId,
        array $authority,
        ?int $actorUserId,
        string $reason,
        ?string $governedChangeUuid,
    ): object {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Credential authority changes require a 10–500 character reason.']);
        }
        $versionId = (int) DB::table('integration.source_credential_versions')->insertGetId([
            'credential_version_uuid' => (string) Str::uuid7(),
            'source_credential_id' => $credential->source_credential_id,
            'source_id' => $credential->source_id,
            'version_number' => $versionNumber,
            'previous_version_id' => $previousVersionId,
            ...$authority,
            'authority_sha256' => $this->hash($authority),
            'change_reason' => $reason,
            'created_by_user_id' => $actorUserId,
            'governed_change_request_uuid' => $governedChangeUuid,
            'created_at' => now(),
        ], 'source_credential_version_id');

        return DB::table('integration.source_credential_versions')
            ->where('source_credential_version_id', $versionId)
            ->firstOrFail();
    }

    private function project(object $credential, object $version, mixed $revokedAt): void
    {
        DB::table('integration.source_credentials')
            ->where('source_credential_id', $credential->source_credential_id)
            ->update([
                'current_credential_version_id' => $version->source_credential_version_id,
                'credential_type' => $version->credential_type,
                'secret_ref' => $version->secret_ref,
                'certificate_ref' => $version->certificate_ref,
                'jwks_uri' => $version->jwks_uri,
                'credential_state' => $version->credential_state,
                'valid_from' => $version->valid_from,
                'expires_at' => $version->expires_at,
                'rotates_at' => $version->rotates_at,
                'rotation_overlap_ends_at' => $version->rotation_overlap_ends_at,
                'revoked_at' => $revokedAt,
                'is_active' => in_array((string) $version->credential_state, ['planned', 'active', 'rotating'], true),
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $updates */
    private function targetState(array $updates, object $current): string
    {
        $active = array_key_exists('is_active', $updates)
            ? (bool) $updates['is_active']
            : (string) $current->credential_state !== 'disabled';
        if (! $active) {
            return 'disabled';
        }
        if (filled($updates['rotation_overlap_ends_at'] ?? null)
            && CarbonImmutable::parse((string) $updates['rotation_overlap_ends_at'])->isFuture()) {
            return 'rotating';
        }

        return 'active';
    }

    private function credential(int $credentialId, bool $lock = false, ?int $sourceId = null): object
    {
        $query = DB::table('integration.source_credentials')->where('source_credential_id', $credentialId);
        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @param array<string, mixed> $authority */
    private function hash(array $authority): string
    {
        ksort($authority);

        return hash('sha256', json_encode($authority, JSON_THROW_ON_ERROR));
    }

    private function scheme(mixed $reference): ?string
    {
        return filled($reference) ? strtolower((string) parse_url((string) $reference, PHP_URL_SCHEME)) : null;
    }

    private function iso(mixed $value): ?string
    {
        return filled($value) ? CarbonImmutable::parse((string) $value)->toIso8601String() : null;
    }
}
