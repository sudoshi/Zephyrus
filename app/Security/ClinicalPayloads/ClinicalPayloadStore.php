<?php

namespace App\Security\ClinicalPayloads;

use Carbon\CarbonImmutable;

interface ClinicalPayloadStore
{
    /** @param array<string, mixed>|list<mixed> $payload */
    public function storeJson(
        int $sourceId,
        string $payloadKind,
        array $payload,
        ?string $dataClassification = null,
        ?string $contentType = 'application/json',
        ?string $retentionPolicyKey = null,
        ?CarbonImmutable $retainUntil = null,
        ?int $actorUserId = null,
    ): StoredClinicalPayload;

    /** @return array<string, mixed>|list<mixed> */
    public function readJson(int $payloadObjectId, int $sourceId, string $payloadKind): array;

    /** @return array{payloadObjectId: int, payloadUuid: string, status: string, verifiedAtIso: string} */
    public function verify(int $payloadObjectId, int $sourceId, ?int $actorUserId = null): array;

    public function discard(int $payloadObjectId, int $sourceId, string $reasonCode, string $reason, ?int $actorUserId = null, ?string $governedChangeUuid = null): void;

    public function markRetentionPending(int $payloadObjectId, int $sourceId, ?int $actorUserId = null): void;

    public function markPurgePending(int $payloadObjectId, int $sourceId, ?int $actorUserId, string $governedChangeUuid): void;

    public function applyLegalHold(int $payloadObjectId, int $sourceId, string $reasonCode, int $actorUserId, string $governedChangeUuid): void;

    public function releaseLegalHold(int $payloadObjectId, int $sourceId, string $reasonCode, int $actorUserId, string $governedChangeUuid): void;

    /** @return array{payloadObjectId: int, payloadUuid: string, status: string, verifiedAtIso: string} */
    public function recoverIntegrity(int $payloadObjectId, int $sourceId, int $actorUserId, string $governedChangeUuid): array;

    /** @return array<string, mixed> */
    public function readiness(): array;
}
