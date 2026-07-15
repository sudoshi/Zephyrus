<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class BulkBackfillResult
{
    /** @param list<array{index: int, reasonCode: string}> $failures */
    public function __construct(
        public string $sourceKey,
        public string $scopeKey,
        public ?string $cursorBefore,
        public ?string $cursorAfter,
        public int $received,
        public int $succeeded,
        public int $failed,
        public bool $checkpointAdvanced,
        public array $failures = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'scopeKey' => $this->scopeKey,
            'cursorBefore' => $this->cursorBefore,
            'cursorAfter' => $this->cursorAfter,
            'received' => $this->received,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'checkpointAdvanced' => $this->checkpointAdvanced,
            'failures' => $this->failures,
        ];
    }
}
