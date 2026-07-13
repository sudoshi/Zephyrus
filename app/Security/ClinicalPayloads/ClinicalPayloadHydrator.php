<?php

namespace App\Security\ClinicalPayloads;

final class ClinicalPayloadHydrator
{
    public function __construct(private readonly ClinicalPayloadStore $store) {}

    /** @return array<string, mixed>|list<mixed>|null */
    public function optional(
        ?int $payloadObjectId,
        int $sourceId,
        string $payloadKind,
        mixed $legacyValue,
    ): ?array {
        if ($payloadObjectId !== null) {
            return $this->store->readJson($payloadObjectId, $sourceId, $payloadKind);
        }
        if ($legacyValue === null) {
            return null;
        }
        if (! (bool) config('clinical-payloads.legacy_read_enabled', true)) {
            throw new ClinicalPayloadException('clinical_payload_legacy_read_disabled');
        }
        $decoded = is_string($legacyValue) ? json_decode($legacyValue, true) : $legacyValue;
        if (! is_array($decoded)) {
            throw new ClinicalPayloadException('clinical_payload_legacy_json_invalid');
        }

        return $decoded;
    }

    /** @return array<string, mixed>|list<mixed> */
    public function required(
        ?int $payloadObjectId,
        int $sourceId,
        string $payloadKind,
        mixed $legacyValue,
    ): array {
        $payload = $this->optional($payloadObjectId, $sourceId, $payloadKind, $legacyValue);
        if ($payload === null) {
            throw new ClinicalPayloadException('clinical_payload_missing');
        }

        return $payload;
    }
}
