<?php

namespace App\Data\Ancillary;

use InvalidArgumentException;

final readonly class ReadinessAxis
{
    public const STATUSES = ['ready', 'pending', 'blocked', 'unknown', 'not_applicable'];

    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public int $pendingCount,
        public ?int $oldestAgeMinutes,
        public bool $blocking,
        public FreshnessEnvelope $freshness,
        public ?string $drillTarget,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported readiness status: {$status}");
        }

        if ($pendingCount < 0 || ($oldestAgeMinutes !== null && $oldestAgeMinutes < 0)) {
            throw new InvalidArgumentException('Readiness counts and ages cannot be negative.');
        }

        if ($blocking && $status === 'ready') {
            throw new InvalidArgumentException('A ready axis cannot be blocking.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'pendingCount' => $this->pendingCount,
            'oldestAgeMinutes' => $this->oldestAgeMinutes,
            'blocking' => $this->blocking,
            'freshness' => $this->freshness->toArray(),
            'drillTarget' => $this->drillTarget,
        ];
    }
}
