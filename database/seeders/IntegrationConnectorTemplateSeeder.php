<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationConnectorTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('integration.interface_engines')->updateOrInsert(
            ['engine_key' => 'interface-engine-boundary'],
            [
                'interface_engine_uuid' => $this->stableUuid(
                    'integration.interface_engines',
                    'engine_key',
                    'interface-engine-boundary',
                    'interface_engine_uuid',
                ),
                'label' => 'Interface Engine Boundary',
                'engine_type' => 'hl7v2_mllp_gateway',
                'environment' => 'sandbox',
                'status' => 'template',
                'boundary_payload' => json_encode([
                    'ingress' => 'hl7v2',
                    'egress' => 'canonical_events',
                    'ack_mode' => 'application_ack',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        foreach ($this->playbooks() as $playbook) {
            DB::table('integration.connector_playbooks')->updateOrInsert(
                ['vendor_key' => $playbook['vendor_key']],
                [
                    'playbook_uuid' => $this->stableUuid(
                        'integration.connector_playbooks',
                        'vendor_key',
                        $playbook['vendor_key'],
                        'playbook_uuid',
                    ),
                    'label' => $playbook['label'],
                    'system_class' => $playbook['system_class'],
                    'status' => 'template',
                    'capability_payload' => json_encode($playbook['capabilities']),
                    'implementation_steps' => json_encode($playbook['steps']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach ($this->adapters() as $adapter) {
            DB::table('integration.coexistence_adapters')->updateOrInsert(
                ['adapter_key' => $adapter['adapter_key']],
                [
                    'adapter_uuid' => $this->stableUuid(
                        'integration.coexistence_adapters',
                        'adapter_key',
                        $adapter['adapter_key'],
                        'adapter_uuid',
                    ),
                    'label' => $adapter['label'],
                    'vendor_key' => $adapter['vendor_key'],
                    'status' => 'template',
                    'coexistence_payload' => json_encode($adapter['coexistence']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function playbooks(): array
    {
        return [
            ['vendor_key' => 'epic', 'label' => 'Epic Connector Template', 'system_class' => 'ehr', 'capabilities' => ['hl7v2' => true, 'fhir_r4' => true, 'smart_backend' => true], 'steps' => ['Confirm BAA and interface scope', 'Register a backend-services client', 'Validate ADT and FHIR read scopes', 'Stage approval-gated writeback']],
            ['vendor_key' => 'oracle_health', 'label' => 'Oracle Health Connector Template', 'system_class' => 'ehr', 'capabilities' => ['hl7v2' => true, 'fhir_r4' => true, 'smart_backend' => true], 'steps' => ['Confirm Millennium environment', 'Discover the FHIR capability statement', 'Configure interface-engine ADT feed', 'Stage approval-gated writeback']],
            ['vendor_key' => 'meditech', 'label' => 'MEDITECH Connector Template', 'system_class' => 'ehr', 'capabilities' => ['hl7v2' => true, 'fhir_r4' => 'site_dependent'], 'steps' => ['Confirm Expanse integration path', 'Map ADT and location codes', 'Validate backfill windows', 'Keep writeback draft-only until certified']],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function adapters(): array
    {
        return [
            ['adapter_key' => 'teletracking_coexistence', 'label' => 'TeleTracking Coexistence Template', 'vendor_key' => 'teletracking', 'coexistence' => ['mode' => 'read_and_reconcile', 'events' => ['bed_status', 'transport', 'placement']]],
            ['adapter_key' => 'qventus_coexistence', 'label' => 'Qventus Coexistence Template', 'vendor_key' => 'qventus', 'coexistence' => ['mode' => 'recommendation_context', 'events' => ['discharge_prediction', 'capacity_action']]],
            ['adapter_key' => 'leantaas_coexistence', 'label' => 'LeanTaaS Coexistence Template', 'vendor_key' => 'leantaas', 'coexistence' => ['mode' => 'schedule_and_capacity_context', 'events' => ['or_block', 'infusion_capacity', 'inpatient_flow']]],
        ];
    }

    private function stableUuid(string $table, string $keyColumn, string $key, string $uuidColumn): string
    {
        return (string) (DB::table($table)->where($keyColumn, $key)->value($uuidColumn) ?? Str::uuid());
    }
}
