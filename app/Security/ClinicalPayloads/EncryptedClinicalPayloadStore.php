<?php

namespace App\Security\ClinicalPayloads;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class EncryptedClinicalPayloadStore implements ClinicalPayloadStore
{
    private const MAGIC = 'ZCP1';

    private const CIPHER = 'xchacha20-poly1305-ietf';

    private const PAYLOAD_KINDS = [
        'raw_message', 'normalized_message', 'fhir_resource', 'canonical_event', 'writeback_draft', 'quarantine_artifact',
    ];

    public function __construct(private readonly ClinicalPayloadKeyResolver $keys) {}

    public function storeJson(
        int $sourceId,
        string $payloadKind,
        array $payload,
        ?string $dataClassification = null,
        ?string $contentType = 'application/json',
        ?string $retentionPolicyKey = null,
        ?CarbonImmutable $retainUntil = null,
        ?int $actorUserId = null,
    ): StoredClinicalPayload {
        $readiness = $this->readiness();
        if ($readiness['status'] !== 'ready') {
            throw new ClinicalPayloadException((string) $readiness['errorCode']);
        }
        if (! in_array($payloadKind, self::PAYLOAD_KINDS, true)) {
            throw new ClinicalPayloadException('clinical_payload_kind_invalid');
        }
        $source = DB::table('integration.sources')->where('source_id', $sourceId)->first();
        if ($source === null) {
            throw new ClinicalPayloadException('clinical_payload_source_missing');
        }
        $plaintext = $this->encode($payload);
        $maxBytes = (int) config('clinical-payloads.max_plaintext_bytes', 10_485_760);
        if (strlen($plaintext) > $maxBytes) {
            throw new ClinicalPayloadException('clinical_payload_too_large');
        }
        $policy = $this->policy($sourceId, (bool) $source->phi_allowed, $dataClassification, $retentionPolicyKey, $retainUntil);
        $key = $this->keys->current();
        $payloadUuid = (string) Str::uuid7();
        $aad = $this->aad($payloadUuid, $sourceId, $payloadKind);
        $compressed = $this->compress($plaintext);
        $dataKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $payloadNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($compressed, $aad, $payloadNonce, $dataKey);
        $object = self::MAGIC.$payloadNonce.$ciphertext;
        $wrapNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrappedKey = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, $aad.'|data-key', $wrapNonce, $key->value());
        sodium_memzero($dataKey);
        $diskName = (string) config('clinical-payloads.disk', 'clinical-payloads');
        $objectKey = $this->objectKey($payloadUuid, (string) $source->environment, $payloadKind);
        $disk = $this->disk($diskName);

        try {
            if (! $disk->put($objectKey, $object, ['visibility' => 'private'])) {
                throw new ClinicalPayloadException('clinical_payload_storage_write_failed');
            }
            $persisted = $disk->get($objectKey);
            if (! hash_equals(hash('sha256', $object), hash('sha256', $persisted))) {
                throw new ClinicalPayloadException('clinical_payload_storage_write_verification_failed');
            }
            $payloadObjectId = DB::transaction(function () use (
                $payloadUuid,
                $source,
                $payloadKind,
                $policy,
                $contentType,
                $diskName,
                $objectKey,
                $plaintext,
                $object,
                $key,
                $wrappedKey,
                $wrapNonce,
                $actorUserId,
            ): int {
                $id = (int) DB::table('raw.payload_objects')->insertGetId([
                    'payload_uuid' => $payloadUuid,
                    'source_id' => $source->source_id,
                    'organization_id' => $source->organization_id,
                    'facility_id' => $source->facility_id,
                    'environment' => $source->environment,
                    'payload_kind' => $payloadKind,
                    'data_classification' => $policy['classification'],
                    'content_type' => $contentType ?: 'application/json',
                    'compression' => (string) config('clinical-payloads.compression', 'gzip'),
                    'cipher' => self::CIPHER,
                    'storage_disk' => $diskName,
                    'object_key' => $objectKey,
                    'plaintext_sha256' => hash('sha256', $plaintext),
                    'ciphertext_sha256' => hash('sha256', $object),
                    'plaintext_bytes' => strlen($plaintext),
                    'ciphertext_bytes' => strlen($object),
                    'key_reference' => $key->reference,
                    'key_reference_sha256' => hash('sha256', $key->reference),
                    'key_provider_scheme' => $key->providerScheme,
                    'key_provider_version' => $key->providerVersion,
                    'wrapped_data_key' => base64_encode($wrappedKey),
                    'key_wrap_nonce' => base64_encode($wrapNonce),
                    'retention_policy_key' => $policy['retentionPolicyKey'],
                    'retain_until' => $policy['retainUntil'],
                    'legal_hold' => false,
                    'status' => 'ready',
                    'created_by_user_id' => $actorUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'payload_object_id');
                $this->event(
                    $id,
                    (int) $source->source_id,
                    'stored',
                    'ready',
                    'ready',
                    false,
                    'payload_encrypted_and_stored',
                    'Clinical payload was encrypted and persisted through the configured authority.',
                    hash('sha256', $object),
                    $actorUserId,
                );

                return $id;
            });

            return new StoredClinicalPayload(
                $payloadObjectId,
                $payloadUuid,
                $sourceId,
                $payloadKind,
                hash('sha256', $plaintext),
                $key->providerScheme,
                $key->providerVersion,
                $policy['retainUntil']->toIso8601String(),
            );
        } catch (Throwable $exception) {
            try {
                $disk->delete($objectKey);
            } catch (Throwable) {
                // An encrypted orphan is reconciled by object inventory; never mask the causal failure.
            }
            if ($exception instanceof ClinicalPayloadException) {
                throw $exception;
            }
            throw new ClinicalPayloadException('clinical_payload_store_failed');
        }
    }

    public function readJson(int $payloadObjectId, int $sourceId, string $payloadKind): array
    {
        $row = $this->authority($payloadObjectId, $sourceId, $payloadKind);
        if (! in_array((string) $row->status, ['ready', 'retention_pending'], true)) {
            throw new ClinicalPayloadException('clinical_payload_not_readable');
        }

        return $this->decryptJson($row, $sourceId, $payloadKind, true);
    }

    /** @return array<string, mixed>|list<mixed> */
    private function decryptJson(object $row, int $sourceId, string $payloadKind, bool $recordIntegrityFailure): array
    {
        try {
            $object = $this->disk((string) $row->storage_disk)->get((string) $row->object_key);
            if (! hash_equals((string) $row->ciphertext_sha256, hash('sha256', $object))) {
                throw new ClinicalPayloadException('clinical_payload_ciphertext_hash_mismatch');
            }
            $minimum = strlen(self::MAGIC) + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
                + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
            if (strlen($object) < $minimum || substr($object, 0, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new ClinicalPayloadException('clinical_payload_envelope_invalid');
            }
            $payloadNonce = substr($object, strlen(self::MAGIC), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = substr($object, strlen(self::MAGIC) + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $key = $this->keys->resolve((string) $row->key_reference, (string) $row->key_provider_version);
            if (! hash_equals((string) $row->key_provider_scheme, $key->providerScheme)) {
                throw new ClinicalPayloadException('clinical_payload_key_provider_mismatch');
            }
            $wrappedKey = base64_decode((string) $row->wrapped_data_key, true);
            $wrapNonce = base64_decode((string) $row->key_wrap_nonce, true);
            if (! is_string($wrappedKey) || ! is_string($wrapNonce)
                || strlen($wrapNonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw new ClinicalPayloadException('clinical_payload_wrapped_key_invalid');
            }
            $aad = $this->aad((string) $row->payload_uuid, $sourceId, $payloadKind);
            $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($wrappedKey, $aad.'|data-key', $wrapNonce, $key->value());
            if (! is_string($dataKey)) {
                throw new ClinicalPayloadException('clinical_payload_data_key_unwrap_failed');
            }
            $compressed = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $payloadNonce, $dataKey);
            sodium_memzero($dataKey);
            if (! is_string($compressed)) {
                throw new ClinicalPayloadException('clinical_payload_decryption_failed');
            }
            $plaintext = $this->decompress($compressed, (string) $row->compression);
            if (strlen($plaintext) !== (int) $row->plaintext_bytes
                || ! hash_equals((string) $row->plaintext_sha256, hash('sha256', $plaintext))) {
                throw new ClinicalPayloadException('clinical_payload_plaintext_hash_mismatch');
            }
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new ClinicalPayloadException('clinical_payload_json_invalid');
            }

            return $decoded;
        } catch (ClinicalPayloadException $exception) {
            if ($recordIntegrityFailure && ! str_starts_with($exception->errorCode, 'clinical_payload_key_')) {
                $this->markIntegrityFailed($row, $exception->errorCode);
            }
            throw $exception;
        } catch (JsonException) {
            if ($recordIntegrityFailure) {
                $this->markIntegrityFailed($row, 'clinical_payload_json_invalid');
            }
            throw new ClinicalPayloadException('clinical_payload_json_invalid');
        } catch (Throwable) {
            throw new ClinicalPayloadException('clinical_payload_read_failed');
        }
    }

    public function verify(int $payloadObjectId, int $sourceId, ?int $actorUserId = null): array
    {
        $row = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->first();
        if ($row === null || (int) $row->source_id !== $sourceId) {
            throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
        }
        $this->readJson($payloadObjectId, $sourceId, (string) $row->payload_kind);
        $now = CarbonImmutable::now();
        $this->event(
            $payloadObjectId,
            $sourceId,
            'verified',
            (string) $row->status,
            (string) $row->status,
            (bool) $row->legal_hold,
            'payload_integrity_verified',
            'Clinical payload ciphertext, key authority, plaintext hash, and JSON structure were verified.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
            $now,
        );

        return [
            'payloadObjectId' => $payloadObjectId,
            'payloadUuid' => (string) $row->payload_uuid,
            'status' => (string) $row->status,
            'verifiedAtIso' => $now->toIso8601String(),
        ];
    }

    public function discard(
        int $payloadObjectId,
        int $sourceId,
        string $reasonCode,
        string $reason,
        ?int $actorUserId = null,
        ?string $governedChangeUuid = null,
    ): void {
        $governedChangeUuid = $this->governedReference($governedChangeUuid);
        $row = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->first();
        if ($row === null || (int) $row->source_id !== $sourceId) {
            return;
        }
        if ((bool) $row->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ((string) $row->status === 'deleted') {
            return;
        }
        if ((string) $row->status === 'ready') {
            if ($governedChangeUuid !== null || $this->hasAuthoritativeReference($payloadObjectId)) {
                throw new ClinicalPayloadException('clinical_payload_deletion_blocked');
            }
            $this->event(
                $payloadObjectId,
                $sourceId,
                'retention_marked',
                'ready',
                'retention_pending',
                false,
                $reasonCode.'_pending',
                'Unlinked encrypted clinical payload was marked for immediate dependency-safe disposal.',
                (string) $row->ciphertext_sha256,
                $actorUserId,
            );
            $row = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->firstOrFail();
        }
        if (! in_array((string) $row->status, ['retention_pending', 'deletion_pending'], true)) {
            throw new ClinicalPayloadException('clinical_payload_deletion_state_invalid');
        }
        try {
            $disk = $this->disk((string) $row->storage_disk);
            if (! $disk->delete((string) $row->object_key) && $disk->exists((string) $row->object_key)) {
                throw new ClinicalPayloadException('clinical_payload_storage_delete_failed');
            }
        } catch (Throwable) {
            try {
                $this->event(
                    $payloadObjectId,
                    $sourceId,
                    'deletion_failed',
                    (string) $row->status,
                    (string) $row->status,
                    (bool) $row->legal_hold,
                    'clinical_payload_storage_delete_failed',
                    'Encrypted clinical payload deletion failed and remains pending for governed operational repair.',
                    (string) $row->ciphertext_sha256,
                    $actorUserId,
                    governedChangeUuid: $governedChangeUuid,
                );
            } catch (Throwable) {
                // Preserve the stable deletion failure even when evidence persistence is unavailable.
            }
            throw new ClinicalPayloadException('clinical_payload_storage_delete_failed');
        }
        $this->event(
            $payloadObjectId,
            $sourceId,
            'deleted',
            (string) $row->status,
            'deleted',
            false,
            $reasonCode,
            $reason,
            (string) $row->ciphertext_sha256,
            $actorUserId,
            governedChangeUuid: $governedChangeUuid,
        );
    }

    private function hasAuthoritativeReference(int $payloadObjectId): bool
    {
        return DB::table('raw.inbound_messages')
            ->where('payload_object_id', $payloadObjectId)
            ->orWhere('normalized_payload_object_id', $payloadObjectId)
            ->exists()
            || DB::table('fhir.resource_versions')->where('payload_object_id', $payloadObjectId)->exists()
            || DB::table('integration.canonical_events')->where('payload_object_id', $payloadObjectId)->exists()
            || DB::table('ops.writeback_drafts')->where('payload_object_id', $payloadObjectId)->exists()
            || DB::table('raw.payload_quarantines')->where('payload_object_id', $payloadObjectId)->exists()
            || DB::table('raw.payload_backfill_items')->where('payload_object_id', $payloadObjectId)->exists();
    }

    public function markRetentionPending(int $payloadObjectId, int $sourceId, ?int $actorUserId = null): void
    {
        $row = $this->lifecycleAuthority($payloadObjectId, $sourceId);
        if ((bool) $row->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ((string) $row->status === 'retention_pending') {
            return;
        }
        if ((string) $row->status !== 'ready') {
            throw new ClinicalPayloadException('clinical_payload_retention_state_invalid');
        }
        $this->event(
            $payloadObjectId,
            $sourceId,
            'retention_marked',
            'ready',
            'retention_pending',
            false,
            'retention_policy_expired',
            'Clinical payload reached its governed retention boundary and is pending dependency-safe deletion.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
        );
    }

    public function markPurgePending(
        int $payloadObjectId,
        int $sourceId,
        ?int $actorUserId,
        string $governedChangeUuid,
    ): void {
        $governedChangeUuid = $this->governedReference($governedChangeUuid)
            ?? throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        $row = $this->lifecycleAuthority($payloadObjectId, $sourceId);
        if ((bool) $row->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ((string) $row->status === 'deletion_pending') {
            return;
        }
        if ((string) $row->status === 'deleted') {
            throw new ClinicalPayloadException('clinical_payload_deleted');
        }
        $this->event(
            $payloadObjectId,
            $sourceId,
            'purge_marked',
            (string) $row->status,
            'deletion_pending',
            false,
            'governed_exceptional_purge',
            'An independently approved exceptional purge entered fail-closed deletion processing.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
            governedChangeUuid: $governedChangeUuid,
        );
    }

    public function applyLegalHold(
        int $payloadObjectId,
        int $sourceId,
        string $reasonCode,
        int $actorUserId,
        string $governedChangeUuid,
    ): void {
        $governedChangeUuid = $this->governedReference($governedChangeUuid)
            ?? throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        $row = $this->lifecycleAuthority($payloadObjectId, $sourceId);
        if ((bool) $row->legal_hold) {
            return;
        }
        if (in_array((string) $row->status, ['deletion_pending', 'deleted'], true)) {
            throw new ClinicalPayloadException('clinical_payload_hold_state_invalid');
        }
        $this->event(
            $payloadObjectId,
            $sourceId,
            'hold_applied',
            (string) $row->status,
            (string) $row->status,
            true,
            $reasonCode,
            'A governed legal or investigation hold was applied; retention deletion is blocked.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
            governedChangeUuid: $governedChangeUuid,
        );
    }

    public function releaseLegalHold(
        int $payloadObjectId,
        int $sourceId,
        string $reasonCode,
        int $actorUserId,
        string $governedChangeUuid,
    ): void {
        $governedChangeUuid = $this->governedReference($governedChangeUuid)
            ?? throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        $row = $this->lifecycleAuthority($payloadObjectId, $sourceId);
        if (! (bool) $row->legal_hold) {
            return;
        }
        $this->event(
            $payloadObjectId,
            $sourceId,
            'hold_released',
            (string) $row->status,
            (string) $row->status,
            false,
            $reasonCode,
            'An authorized legal or investigation hold release was recorded; retention policy resumes.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
            governedChangeUuid: $governedChangeUuid,
        );
    }

    public function recoverIntegrity(
        int $payloadObjectId,
        int $sourceId,
        int $actorUserId,
        string $governedChangeUuid,
    ): array {
        $governedChangeUuid = $this->governedReference($governedChangeUuid)
            ?? throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        $row = $this->lifecycleAuthority($payloadObjectId, $sourceId);
        if ((string) $row->status !== 'integrity_failed') {
            throw new ClinicalPayloadException('clinical_payload_integrity_recovery_state_invalid');
        }
        $this->decryptJson($row, $sourceId, (string) $row->payload_kind, false);
        $now = CarbonImmutable::now();
        $this->event(
            $payloadObjectId,
            $sourceId,
            'integrity_recovered',
            'integrity_failed',
            'ready',
            (bool) $row->legal_hold,
            'governed_integrity_recovery_verified',
            'The exact immutable ciphertext and key authority passed governed recovery verification.',
            (string) $row->ciphertext_sha256,
            $actorUserId,
            $now,
            $governedChangeUuid,
        );

        return [
            'payloadObjectId' => $payloadObjectId,
            'payloadUuid' => (string) $row->payload_uuid,
            'status' => 'ready',
            'verifiedAtIso' => $now->toIso8601String(),
        ];
    }

    public function readiness(): array
    {
        if (! (bool) config('clinical-payloads.enabled', false)) {
            return $this->notReady('clinical_payload_store_disabled');
        }
        if (! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            return $this->notReady('clinical_payload_sodium_unavailable');
        }
        $compression = (string) config('clinical-payloads.compression', 'gzip');
        if (! in_array($compression, ['none', 'gzip'], true)) {
            return $this->notReady('clinical_payload_compression_invalid');
        }
        $diskName = (string) config('clinical-payloads.disk', 'clinical-payloads');
        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! is_array($diskConfig) || ($diskConfig['throw'] ?? false) !== true) {
            return $this->notReady('clinical_payload_disk_not_fail_closed');
        }
        if (app()->environment('production')
            && ($diskConfig['driver'] ?? null) === 'local'
            && ! (bool) config('clinical-payloads.allow_local_in_production', false)) {
            return $this->notReady('clinical_payload_local_production_not_approved');
        }
        try {
            $key = $this->keys->current();
        } catch (ClinicalPayloadException $exception) {
            return $this->notReady($exception->errorCode);
        }
        if ((bool) config('clinical-payloads.readiness_probe_enabled', true)) {
            try {
                $this->readinessProbe($diskName, $diskConfig);
            } catch (ClinicalPayloadException $exception) {
                return $this->notReady($exception->errorCode);
            }
        }

        return [
            'status' => 'ready',
            'errorCode' => null,
            'disk' => $diskName,
            'driver' => (string) ($diskConfig['driver'] ?? 'unknown'),
            'cipher' => self::CIPHER,
            'compression' => $compression,
            'keyProviderScheme' => $key->providerScheme,
            'keyProviderVersion' => $key->providerVersion,
            'keyReferenceConfigured' => true,
            'providerReachable' => true,
        ];
    }

    /** @return array{classification: string, retentionPolicyKey: string, retainUntil: CarbonImmutable} */
    private function policy(
        int $sourceId,
        bool $phiAllowed,
        ?string $classification,
        ?string $retentionPolicyKey,
        ?CarbonImmutable $retainUntil,
    ): array {
        $profile = DB::table('integration.source_onboarding_versions')
            ->where('source_id', $sourceId)
            ->orderByDesc('version_number')
            ->first();
        $classification ??= $profile?->data_classification;
        $classification = in_array($classification, ['internal', 'confidential', 'restricted_phi'], true)
            ? $classification
            : ($phiAllowed ? 'restricted_phi' : 'internal');
        if ($phiAllowed && $classification !== 'restricted_phi') {
            $classification = 'restricted_phi';
        }
        $retentionPolicyKey = trim((string) ($retentionPolicyKey
            ?? $profile?->retention_policy_key
            ?? config('clinical-payloads.default_retention_policy_key', 'clinical-payload-default')));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $retentionPolicyKey) !== 1) {
            throw new ClinicalPayloadException('clinical_payload_retention_policy_invalid');
        }
        $days = $profile?->retention_days !== null
            ? (int) $profile->retention_days
            : (int) config('clinical-payloads.default_retention_days', 2555);
        $retainUntil ??= CarbonImmutable::now()->addDays($days);
        if ($retainUntil->isPast()) {
            throw new ClinicalPayloadException('clinical_payload_retention_expired');
        }

        return [
            'classification' => $classification,
            'retentionPolicyKey' => $retentionPolicyKey,
            'retainUntil' => $retainUntil,
        ];
    }

    private function compress(string $plaintext): string
    {
        if ((string) config('clinical-payloads.compression', 'gzip') === 'none') {
            return $plaintext;
        }
        $compressed = gzencode($plaintext, (int) config('clinical-payloads.compression_level', 6), ZLIB_ENCODING_GZIP);
        if (! is_string($compressed)) {
            throw new ClinicalPayloadException('clinical_payload_compression_failed');
        }

        return $compressed;
    }

    private function decompress(string $payload, string $compression): string
    {
        if ($compression === 'none') {
            return $payload;
        }
        if ($compression !== 'gzip') {
            throw new ClinicalPayloadException('clinical_payload_compression_invalid');
        }
        $plaintext = gzdecode($payload);
        if (! is_string($plaintext)) {
            throw new ClinicalPayloadException('clinical_payload_decompression_failed');
        }

        return $plaintext;
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new ClinicalPayloadException('clinical_payload_json_encode_failed');
        }
    }

    private function authority(int $payloadObjectId, int $sourceId, string $payloadKind): object
    {
        $row = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->first();
        if ($row === null || (int) $row->source_id !== $sourceId || (string) $row->payload_kind !== $payloadKind) {
            throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
        }

        return $row;
    }

    private function lifecycleAuthority(int $payloadObjectId, int $sourceId): object
    {
        $row = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->first();
        if ($row === null || (int) $row->source_id !== $sourceId) {
            throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
        }

        return $row;
    }

    private function markIntegrityFailed(object $row, string $reasonCode): void
    {
        try {
            $fresh = DB::table('raw.payload_objects')->where('payload_object_id', $row->payload_object_id)->first();
            if ($fresh === null || in_array((string) $fresh->status, ['integrity_failed', 'deleted'], true)) {
                return;
            }
            $this->event(
                (int) $fresh->payload_object_id,
                (int) $fresh->source_id,
                'integrity_failed',
                (string) $fresh->status,
                'integrity_failed',
                (bool) $fresh->legal_hold,
                $reasonCode,
                'Clinical payload integrity validation failed; content access is blocked pending governed investigation.',
                (string) $fresh->ciphertext_sha256,
                null,
            );
        } catch (Throwable) {
            // The original stable failure remains authoritative to the caller.
        }
    }

    private function event(
        int $payloadObjectId,
        int $sourceId,
        string $eventType,
        string $fromStatus,
        string $toStatus,
        bool $legalHold,
        string $reasonCode,
        string $reason,
        ?string $evidenceSha256,
        ?int $actorUserId,
        ?CarbonImmutable $occurredAt = null,
        ?string $governedChangeUuid = null,
    ): void {
        DB::table('raw.payload_object_events')->insert([
            'event_uuid' => (string) Str::uuid7(),
            'payload_object_id' => $payloadObjectId,
            'source_id' => $sourceId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'legal_hold' => $legalHold,
            'reason_code' => Str::limit($reasonCode, 120, ''),
            'reason' => Str::limit($reason, 500, ''),
            'evidence_sha256' => $evidenceSha256,
            'actor_user_id' => $actorUserId,
            'governed_change_request_uuid' => $governedChangeUuid,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function governedReference(?string $governedChangeUuid): ?string
    {
        if ($governedChangeUuid === null) {
            return null;
        }
        if (! Str::isUuid($governedChangeUuid)) {
            throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        }

        return $governedChangeUuid;
    }

    private function disk(string $name): Filesystem
    {
        try {
            return Storage::disk($name);
        } catch (Throwable) {
            throw new ClinicalPayloadException('clinical_payload_disk_unavailable');
        }
    }

    /** @param array<string, mixed> $diskConfig */
    private function readinessProbe(string $diskName, array $diskConfig): void
    {
        $fingerprint = hash('sha256', json_encode([
            'disk' => $diskName,
            'driver' => $diskConfig['driver'] ?? null,
            'endpoint' => $diskConfig['endpoint'] ?? null,
            'bucket' => $diskConfig['bucket'] ?? null,
            'credential_sha256' => hash('sha256', (string) ($diskConfig['key'] ?? '')."\0".(string) ($diskConfig['secret'] ?? '')),
        ], JSON_THROW_ON_ERROR));
        $probe = function () use ($diskName): bool {
            $disk = $this->disk($diskName);
            $key = 'v1/readiness/'.Str::uuid7().'.probe';
            $expected = random_bytes(32);
            try {
                if (! $disk->put($key, $expected, ['visibility' => 'private'])) {
                    throw new ClinicalPayloadException('clinical_payload_provider_probe_write_failed');
                }
                $actual = $disk->get($key);
                if (! hash_equals($expected, $actual)) {
                    throw new ClinicalPayloadException('clinical_payload_provider_probe_read_failed');
                }
                if (! $disk->delete($key) || $disk->exists($key)) {
                    throw new ClinicalPayloadException('clinical_payload_provider_probe_delete_failed');
                }

                return true;
            } catch (ClinicalPayloadException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new ClinicalPayloadException('clinical_payload_provider_probe_failed');
            } finally {
                try {
                    $disk->delete($key);
                } catch (Throwable) {
                    // The stable probe failure is authoritative; cleanup is retried by storage lifecycle tooling.
                }
            }
        };

        try {
            Cache::remember(
                'clinical-payload-readiness:'.$fingerprint,
                (int) config('clinical-payloads.readiness_probe_ttl_seconds', 60),
                $probe,
            );
        } catch (ClinicalPayloadException $exception) {
            throw $exception;
        } catch (Throwable) {
            $probe();
        }
    }

    private function objectKey(string $uuid, string $environment, string $payloadKind): string
    {
        $compact = str_replace('-', '', $uuid);

        return "v1/{$environment}/{$payloadKind}/".substr($compact, 0, 2).'/'.$uuid.'.zcp';
    }

    private function aad(string $payloadUuid, int $sourceId, string $payloadKind): string
    {
        return "zephyrus:clinical-payload:v1:{$payloadUuid}:{$sourceId}:{$payloadKind}";
    }

    /** @return array<string, mixed> */
    private function notReady(string $errorCode): array
    {
        return [
            'status' => 'not_ready',
            'errorCode' => $errorCode,
            'disk' => (string) config('clinical-payloads.disk', 'clinical-payloads'),
            'driver' => null,
            'cipher' => self::CIPHER,
            'compression' => (string) config('clinical-payloads.compression', 'gzip'),
            'keyProviderScheme' => null,
            'keyProviderVersion' => null,
            'keyReferenceConfigured' => filled(config('clinical-payloads.key_reference')),
            'providerReachable' => false,
        ];
    }
}
