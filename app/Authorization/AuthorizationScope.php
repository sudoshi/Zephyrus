<?php

namespace App\Authorization;

use InvalidArgumentException;

/**
 * A resource boundary for an authorization decision. A null scope means only
 * "does this principal have the capability?"; it never implies every tenant.
 */
final readonly class AuthorizationScope
{
    private function __construct(
        public ?int $organizationId,
        public ?int $facilityId,
        public ?string $facilityKey,
    ) {
        if ($organizationId === null && $facilityId === null && $facilityKey === null) {
            throw new InvalidArgumentException('An authorization scope must identify an organization or facility.');
        }

        if ($facilityId !== null && $facilityKey !== null) {
            throw new InvalidArgumentException('Identify a facility by id or key, not both.');
        }
    }

    public static function organization(int $organizationId): self
    {
        return new self($organizationId, null, null);
    }

    public static function facility(int $facilityId, ?int $organizationId = null): self
    {
        return new self($organizationId, $facilityId, null);
    }

    public static function facilityKey(string $facilityKey, ?int $organizationId = null): self
    {
        $facilityKey = trim($facilityKey);
        if ($facilityKey === '') {
            throw new InvalidArgumentException('Facility key cannot be empty.');
        }

        return new self($organizationId, null, $facilityKey);
    }

    /** @return array{organization_id: int|null, facility_id: int|null, facility_key: string|null} */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'facility_key' => $this->facilityKey,
        ];
    }
}
