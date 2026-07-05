<?php

namespace App\Services\Deployment;

use App\Services\Deployment\Concerns\UpsertsPgRows;
use Illuminate\Support\Facades\DB;

/**
 * Imports a facility capability roster (Layer 3 facility_service_capabilities) plus
 * interfacility transfer_relationships into hosp_org. Service-line codes are
 * normalized to canonical via ServiceLineNormalizer before write.
 *
 * Idempotent: capabilities upsert on (facility_id, service_line_code). Transfers
 * have no DB unique constraint (a facility pair can have many per service line), so
 * they are matched on their natural signature (source/dest/service_line/direction)
 * and updated in place rather than duplicated.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class DeploymentCapabilityImporter
{
    use UpsertsPgRows;

    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * @return array{capabilities:int, transfers:int, skipped:list<string>}
     */
    public function importFile(string $path): array
    {
        return $this->importData($this->readJsonFile($path));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{capabilities:int, transfers:int, skipped:list<string>}
     */
    public function importData(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $facilityIds = DB::table('hosp_org.facilities')
                ->pluck('facility_id', 'facility_key')
                ->all();

            $skipped = [];
            $capabilityCount = $this->importCapabilities($data['capabilities'] ?? [], $facilityIds, $skipped);
            $transferCount = $this->importTransfers($data['transfers'] ?? [], $facilityIds, $skipped);

            return [
                'capabilities' => $capabilityCount,
                'transfers' => $transferCount,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $capabilities
     * @param  array<string, int>  $facilityIds
     * @param  list<string>  $skipped
     */
    private function importCapabilities(array $capabilities, array $facilityIds, array &$skipped): int
    {
        $count = 0;

        foreach ($capabilities as $capability) {
            $facilityKey = $capability['facility_key'] ?? null;
            $facilityId = $facilityKey ? ($facilityIds[$facilityKey] ?? null) : null;

            if ($facilityId === null) {
                $skipped[] = "capability: unknown facility_key '".($facilityKey ?? 'null')."'";

                continue;
            }

            $serviceLine = $this->normalizer->canonical(
                (string) ($capability['service_line'] ?? $capability['service_line_code'] ?? '')
            );

            if ($serviceLine === '') {
                $skipped[] = "capability: missing service_line for '{$facilityKey}'";

                continue;
            }

            $this->upsertRow('hosp_org.facility_service_capabilities', [
                'facility_id' => $facilityId,
                'facility_key' => $facilityKey,
                'service_line_code' => $serviceLine,
                'capability_level' => $capability['capability_level'] ?? 'none',
                'programs_present' => $capability['programs_present'] ?? [],
                'departments_present' => $capability['departments_present'] ?? [],
                'coverage_model' => $capability['coverage_model'] ?? null,
                'hours' => $capability['hours'] ?? null,
                'telehealth_support' => $capability['telehealth_support'] ?? false,
                'transfer_out_targets' => $capability['transfer_out_targets'] ?? [],
                'transfer_in_sources' => $capability['transfer_in_sources'] ?? [],
                'source_evidence_url' => $capability['source_evidence_url'] ?? null,
                'source_evidence_type' => $capability['source_evidence_type'] ?? null,
                'review_status' => $capability['review_status'] ?? 'assumed',
                'notes' => $capability['notes'] ?? null,
                'metadata' => $capability['metadata'] ?? [],
            ], ['facility_id', 'service_line_code'],
                ['programs_present', 'departments_present', 'transfer_out_targets', 'transfer_in_sources'],
                ['metadata']);

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, array<string, mixed>>  $transfers
     * @param  array<string, int>  $facilityIds
     * @param  list<string>  $skipped
     */
    private function importTransfers(array $transfers, array $facilityIds, array &$skipped): int
    {
        $count = 0;

        foreach ($transfers as $transfer) {
            $sourceKey = $transfer['source_facility_key'] ?? null;
            $destKey = $transfer['destination_facility_key'] ?? null;
            $externalName = $transfer['destination_external_name'] ?? null;

            $sourceId = $sourceKey ? ($facilityIds[$sourceKey] ?? null) : null;
            $destId = $destKey ? ($facilityIds[$destKey] ?? null) : null;

            if ($sourceId === null && $destId === null && $externalName === null) {
                $skipped[] = 'transfer: no resolvable endpoint';

                continue;
            }

            $serviceLine = (isset($transfer['service_line']) || isset($transfer['service_line_code']))
                ? $this->normalizer->canonical((string) ($transfer['service_line'] ?? $transfer['service_line_code']))
                : null;
            $direction = $transfer['direction'] ?? 'out';

            $row = [
                'source_facility_id' => $sourceId,
                'source_facility_key' => $sourceKey,
                'destination_facility_id' => $destId,
                'destination_facility_key' => $destKey,
                'destination_external_name' => $externalName,
                'service_line_code' => $serviceLine,
                'program_code' => $transfer['program_code'] ?? null,
                'transfer_reason' => $transfer['transfer_reason'] ?? null,
                'transport_mode' => $transfer['transport_mode'] ?? null,
                'typical_minutes' => $transfer['typical_minutes'] ?? null,
                'typical_miles' => $transfer['typical_miles'] ?? null,
                'direction' => $direction,
                'acceptance_constraints' => $transfer['acceptance_constraints'] ?? null,
                'escalation_contact' => $transfer['escalation_contact'] ?? null,
                'is_external_partner' => $transfer['is_external_partner'] ?? ($externalName !== null),
                'review_status' => $transfer['review_status'] ?? 'assumed',
                'source_evidence' => $transfer['source_evidence'] ?? [],
                'is_active' => $transfer['is_active'] ?? true,
                'metadata' => $transfer['metadata'] ?? [],
            ];

            $existingId = DB::table('hosp_org.transfer_relationships')
                ->where('source_facility_key', $sourceKey)
                ->when($destKey !== null, fn ($q) => $q->where('destination_facility_key', $destKey))
                ->when($destKey === null, fn ($q) => $q->whereNull('destination_facility_key'))
                ->when($externalName !== null, fn ($q) => $q->where('destination_external_name', $externalName))
                ->where('service_line_code', $serviceLine)
                ->where('direction', $direction)
                ->value('transfer_relationship_id');

            $this->writeTransfer($row, $existingId !== null ? (int) $existingId : null);
            $count++;
        }

        return $count;
    }

    /**
     * Raw insert/update of a transfer row with `?::jsonb` casts for the two jsonb columns.
     *
     * @param  array<string, mixed>  $row
     */
    private function writeTransfer(array $row, ?int $existingId): void
    {
        $jsonColumns = ['source_evidence', 'metadata'];

        if ($existingId === null) {
            $columns = array_keys($row);
            $placeholders = array_map(
                static fn (string $col): string => in_array($col, ['source_evidence', 'metadata'], true) ? '?::jsonb' : '?',
                $columns
            );

            $sql = sprintf(
                'INSERT INTO hosp_org.transfer_relationships (%s, updated_at) VALUES (%s, now())',
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            DB::statement($sql, $this->bindingsFor($row, $columns, [], $jsonColumns));

            return;
        }

        $columns = array_keys($row);
        $setClauses = array_map(
            static fn (string $col): string => in_array($col, ['source_evidence', 'metadata'], true)
                ? "{$col} = ?::jsonb"
                : "{$col} = ?",
            $columns
        );

        $sql = sprintf(
            'UPDATE hosp_org.transfer_relationships SET %s, updated_at = now() WHERE transfer_relationship_id = ?',
            implode(', ', $setClauses)
        );

        $bindings = $this->bindingsFor($row, $columns, [], $jsonColumns);
        $bindings[] = $existingId;

        DB::statement($sql, $bindings);
    }
}
