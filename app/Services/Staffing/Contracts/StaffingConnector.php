<?php

namespace App\Services\Staffing\Contracts;

use App\Services\Staffing\Support\ConnectionResult;
use App\Services\Staffing\Support\ConnectorCapabilities;
use App\Services\Staffing\Support\PullWindow;

/**
 * Phase 7: the pluggable staffing-source contract (mirrors the Connector Contract
 * of the transport-operations plan). A connector's only staffing-specific output is
 * a stream of RawStaffRecord value objects the orchestrator stages; transport +
 * secrets are reused from integration.sources / raw.inbound_messages.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§4)
 */
interface StaffingConnector
{
    /**
     * The staffing_sources.source_key this connector is bound to.
     */
    public function key(): string;

    /**
     * Reachability + auth probe (no data pulled).
     */
    public function testConnection(): ConnectionResult;

    /**
     * Source field descriptors for auto-mapping: [{field, samples[]}].
     *
     * @return list<array<string, mixed>>
     */
    public function discoverSchema(): array;

    /**
     * Stream RawStaffRecord value objects for the window.
     *
     * @return iterable<\App\Services\Staffing\Support\RawStaffRecord>
     */
    public function pullStaff(PullWindow $window): iterable;

    public function capabilities(): ConnectorCapabilities;
}
