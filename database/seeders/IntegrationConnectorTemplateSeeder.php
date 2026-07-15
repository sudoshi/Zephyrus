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
            ...$this->ancillaryPlaybooks(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ancillaryPlaybooks(): array
    {
        $definitions = [
            ['rad_orders', 'Radiology ORM/OMI Orders', 'radiology', ['ORM', 'OMI'], 'ingest_radiology_orders'],
            ['rad_reports', 'Radiology ORU Reports', 'radiology_reporting', ['ORU'], 'ingest_radiology_reports'],
            ['rad_mpps', 'Radiology MPPS Relay', 'pacs', ['MPPS'], 'ingest_forwarded_mpps'],
            ['rad_scheduling', 'Radiology SIU Scheduling', 'ris', ['SIU'], 'ingest_radiology_scheduling'],
            ['lab_orders', 'Laboratory OML/ORM Orders', 'lis', ['OML', 'ORM'], 'ingest_laboratory_orders'],
            ['lab_results', 'Laboratory ORU Results', 'lis', ['ORU'], 'ingest_laboratory_results'],
            ['lab_middleware', 'Laboratory Middleware Events', 'lab_middleware', ['ANALYZER', 'AUTOVERIFICATION'], 'ingest_laboratory_middleware'],
            ['pathology_blood_bank', 'Pathology and Blood Bank Events', 'ap_lis_blood_bank', ['ORU', 'BARCODE', 'WORKFLOW'], 'ingest_pathology_blood_bank'],
            ['rx_orders_dispense', 'Pharmacy RDE/RDS Orders and Dispense', 'pharmacy', ['RDE', 'RDS'], 'ingest_pharmacy_orders_dispense'],
            ['rx_verification', 'Pharmacy Verification Queue', 'pharmacy', ['QUEUE'], 'ingest_pharmacy_verification'],
            ['rx_adc', 'ADC Transactions', 'adc', ['VEND', 'RETURN', 'WASTE', 'OVERRIDE'], 'ingest_adc_transactions'],
            ['rx_administration', 'BCMA/eMAR Administration Warehouse', 'clinical_warehouse', ['BATCH'], 'ingest_administration_batch'],
            ['shared_adt_linkage', 'Shared ADT Encounter Linkage', 'ehr', ['ADT'], 'ingest_ancillary_adt_linkage'],
        ];

        return array_map(function (array $definition): array {
            [$key, $label, $systemClass, $messageFamilies, $ability] = $definition;

            return [
                'vendor_key' => 'ancillary_'.$key,
                'label' => $label.' Template',
                'system_class' => $systemClass,
                'capabilities' => [
                    'direction' => 'read_only_ingest',
                    'message_families' => $messageFamilies,
                    'https_boundary' => true,
                    'mllp_terminated_upstream' => true,
                    'required_machine_ability' => 'integration:ancillary:'.$ability,
                    'pipeline' => ['raw', 'canonical', 'ancillary_projection', 'provenance', 'dead_letter'],
                    'source_defaults' => [
                        'active_status' => 'inactive',
                        'phi_allowed' => false,
                        'go_live_status' => 'not_started',
                    ],
                    'writeback' => false,
                ],
                'steps' => [
                    'Confirm contract, BAA, PHI approval, source identity, and network allowlist',
                    'Bind one governed source and endpoint to the declared message family',
                    'Validate non-production golden messages, replay, staleness, and sanitized dead letters',
                    'Activate only after source-specific governance evidence is recorded',
                ],
            ];
        }, $definitions);
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
