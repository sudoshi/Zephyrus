<?php

namespace App\Data\Ancillary;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FreshnessEnvelope
{
    public const STATUSES = ['fresh', 'stale', 'unknown', 'batch'];

    public function __construct(
        public string $status,
        public DateTimeImmutable $asOf,
        public ?DateTimeImmutable $sourceCutoffAt,
        public ?int $lagMinutes,
        public string $sourceLabel,
        public ?string $explanation = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported freshness status: {$status}");
        }

        if ($lagMinutes !== null && $lagMinutes < 0) {
            throw new InvalidArgumentException('Freshness lag cannot be negative.');
        }

        if ($status === 'unknown' && $sourceCutoffAt !== null) {
            throw new InvalidArgumentException('Unknown freshness cannot claim a source cutoff.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'asOf' => $this->asOf->format(DATE_ATOM),
            'sourceCutoffAt' => $this->sourceCutoffAt?->format(DATE_ATOM),
            'lagMinutes' => $this->lagMinutes,
            'sourceLabel' => $this->sourceLabel,
            'explanation' => $this->explanation,
        ];
    }
}
