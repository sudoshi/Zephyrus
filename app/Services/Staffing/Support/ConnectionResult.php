<?php

namespace App\Services\Staffing\Support;

/**
 * Phase 7: the result of a connector reachability/auth probe (no data pulled).
 */
final class ConnectionResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function ok(string $message = 'Connection OK', array $details = []): self
    {
        return new self(true, $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function fail(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
