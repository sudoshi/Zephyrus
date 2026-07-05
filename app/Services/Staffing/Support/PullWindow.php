<?php

namespace App\Services\Staffing\Support;

use DateTimeImmutable;

/**
 * Phase 7: the window a connector pulls over. Connectors reporting
 * capabilities()->incremental honor `since`; others do a full pull and let the
 * orchestrator diff.
 */
final class PullWindow
{
    public function __construct(
        public readonly bool $full = true,
        public readonly ?DateTimeImmutable $since = null,
    ) {}

    public static function full(): self
    {
        return new self(full: true);
    }

    public static function since(DateTimeImmutable $since): self
    {
        return new self(full: false, since: $since);
    }
}
