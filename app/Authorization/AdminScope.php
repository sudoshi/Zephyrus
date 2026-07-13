<?php

namespace App\Authorization;

use Carbon\CarbonImmutable;

/**
 * A revalidated, explicitly selected enterprise boundary for administrative
 * mutations. This object contains identifiers and display-safe labels only.
 */
final readonly class AdminScope
{
    public function __construct(
        public int $userId,
        public int $organizationId,
        public string $organizationKey,
        public string $organizationName,
        public ?int $facilityId,
        public ?string $facilityKey,
        public ?string $facilityName,
        public ?int $sourceId,
        public ?string $sourceKey,
        public ?string $sourceName,
        public string $revision,
        public CarbonImmutable $selectedAt,
    ) {}

    public function authorizationScope(): AuthorizationScope
    {
        return $this->facilityId !== null
            ? AuthorizationScope::facility($this->facilityId)
            : AuthorizationScope::organization($this->organizationId);
    }

    /** @return array<string, int|string|null> */
    public function toSession(): array
    {
        return [
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $this->sourceId,
            'revision' => $this->revision,
            'selected_at' => $this->selectedAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function toClient(): array
    {
        return [
            'organization' => [
                'id' => $this->organizationId,
                'key' => $this->organizationKey,
                'name' => $this->organizationName,
            ],
            'facility' => $this->facilityId === null ? null : [
                'id' => $this->facilityId,
                'key' => $this->facilityKey,
                'name' => $this->facilityName,
            ],
            'source' => $this->sourceId === null ? null : [
                'id' => $this->sourceId,
                'key' => $this->sourceKey,
                'name' => $this->sourceName,
            ],
            'revision' => $this->revision,
            'selectedAt' => $this->selectedAt->toIso8601String(),
        ];
    }

    /** @return array<string, int> */
    public function query(): array
    {
        return array_filter([
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $this->sourceId,
        ], fn (?int $value): bool => $value !== null);
    }
}
