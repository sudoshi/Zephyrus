<?php

namespace App\Services\Staffing\Support;

/**
 * Phase 7: what a connector can do — drives incremental pulls, on-call/coverage
 * derivation, credential-backed regulated-role verification, and push lifecycle.
 */
final class ConnectorCapabilities
{
    public function __construct(
        public readonly bool $incremental = false,
        public readonly bool $onCall = false,
        public readonly bool $credentials = false,
        public readonly bool $push = false,
    ) {}
}
