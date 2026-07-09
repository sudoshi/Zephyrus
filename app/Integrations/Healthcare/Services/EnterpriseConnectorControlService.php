<?php

namespace App\Integrations\Healthcare\Services;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Support\Api\JsonMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnterpriseConnectorControlService
{
    /** @return array<string,mixed> */
    public function summary(): array
    {
        return [
            'generatedAtIso' => now()->toIso8601String(),
            'counts' => [
                'interfaceEngines' => (int) DB::table('integration.interface_engines')->count(),
                'fhirConnections' => (int) DB::table('integration.fhir_client_connections')->count(),
                'smartCredentials' => (int) DB::table('integration.smart_backend_credentials')->count(),
                'connectorPlaybooks' => (int) DB::table('integration.connector_playbooks')->count(),
                'coexistenceAdapters' => (int) DB::table('integration.coexistence_adapters')->count(),
                'writebackDrafts' => (int) DB::table('ops.writeback_drafts')->count(),
            ],
            'playbooks' => DB::table('integration.connector_playbooks')->orderBy('vendor_key')->get()->map(fn ($row): array => [
                'vendorKey' => $row->vendor_key,
                'label' => $row->label,
                'systemClass' => $row->system_class,
                'status' => $this->templateSafeStatus((string) $row->status),
                'capabilities' => JsonMap::from(json_decode($row->capability_payload ?? '{}', true) ?: []),
                'implementationSteps' => json_decode($row->implementation_steps ?? '[]', true) ?: [],
            ])->all(),
            'coexistenceAdapters' => DB::table('integration.coexistence_adapters')->orderBy('adapter_key')->get()->map(fn ($row): array => [
                'adapterKey' => $row->adapter_key,
                'label' => $row->label,
                'vendorKey' => $row->vendor_key,
                'status' => $this->templateSafeStatus((string) $row->status),
                'coexistence' => JsonMap::from(json_decode($row->coexistence_payload ?? '{}', true) ?: []),
            ])->all(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function createWritebackDraft(array $payload, ?int $userId): array
    {
        $resourceType = (string) $payload['resource_type'];
        $targetSystem = (string) ($payload['target_system'] ?? 'fhir');
        $draftType = (string) ($payload['draft_type'] ?? 'fhir_writeback');

        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'writeback_draft',
            'scope_type' => 'integration',
            'scope_key' => $targetSystem,
            'title' => "Approve {$resourceType} writeback draft",
            'rationale' => 'External-system writeback must be reviewed before transmission.',
            'confidence' => 0.8000,
            'risk_level' => 'medium',
            'status' => 'draft',
            'expected_impact' => [
                'resource_type' => $resourceType,
                'target_system' => $targetSystem,
            ],
            'evidence' => [
                'source_tables' => ['ops.writeback_drafts', 'ops.actions', 'ops.approvals'],
                'approval_required' => true,
            ],
            'created_by_source' => 'enterprise_connector_control',
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => 'approve_writeback_draft',
            'status' => 'draft',
            'owner_name' => 'Integration governance',
            'payload' => [
                'owner' => 'Integration governance',
                'route' => '/integrations',
                'instruction' => "Review {$resourceType} {$targetSystem} writeback draft before sending.",
                'resourceType' => $resourceType,
                'targetSystem' => $targetSystem,
            ],
            'expires_at' => now()->addHours(12),
        ]);

        $approval = Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $userId,
            'reason' => 'External-system writeback requires explicit human approval.',
            'requested_at' => now(),
        ]);

        $sourceId = null;
        if (! empty($payload['source_key'])) {
            $sourceId = $this->sourceFor((string) $payload['source_key'], (string) ($payload['vendor'] ?? $targetSystem))->source_id;
        }

        $draftId = DB::table('ops.writeback_drafts')->insertGetId([
            'writeback_draft_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'action_id' => $action->action_id,
            'approval_id' => $approval->approval_id,
            'target_system' => $targetSystem,
            'resource_type' => $resourceType,
            'draft_type' => $draftType,
            'status' => 'pending_approval',
            'resource_payload' => json_encode($payload['resource_payload'] ?? []),
            'routing_payload' => json_encode([
                'delivery_mode' => 'draft_only',
                'approval_required' => true,
            ]),
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'writeback_draft_id');

        return [
            'writebackDraftId' => $draftId,
            'resourceType' => $resourceType,
            'targetSystem' => $targetSystem,
            'status' => 'pending_approval',
            'actionId' => $action->action_id,
            'approvalId' => $approval->approval_id,
            'approvalStatus' => $approval->status,
        ];
    }

    private function sourceFor(string $sourceKey, string $vendor): object
    {
        DB::table('integration.sources')->updateOrInsert(
            ['source_key' => $sourceKey],
            [
                'source_uuid' => (string) Str::uuid(),
                'tenant_key' => 'default',
                'facility_key' => 'main',
                'source_name' => "{$vendor} FHIR Sandbox",
                'vendor' => $vendor,
                'system_class' => 'ehr',
                'environment' => 'sandbox',
                'base_url' => "https://example.invalid/{$sourceKey}",
                'interface_type' => 'fhir_r4',
                'active_status' => 'planned',
                'fhir_version' => '4.0.1',
                'smart_supported' => true,
                'bulk_supported' => true,
                'subscriptions_supported' => true,
                'contract_status' => 'planning',
                'baa_status' => 'planning',
                'phi_allowed' => false,
                'go_live_status' => 'not_started',
                'metadata' => json_encode(['managed_by' => 'enterprise_connector_control']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return DB::table('integration.sources')->where('source_key', $sourceKey)->first();
    }

    private function templateSafeStatus(string $status): string
    {
        return $status === 'ready' ? 'template' : $status;
    }
}
