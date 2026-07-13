<?php

namespace App\Security\ClinicalPayloads;

final readonly class StoredClinicalPayload
{
    public function __construct(
        public int $payloadObjectId,
        public string $payloadUuid,
        public int $sourceId,
        public string $payloadKind,
        public string $plaintextSha256,
        public string $keyProviderScheme,
        public string $keyProviderVersion,
        public string $retainUntilIso,
    ) {}
}
