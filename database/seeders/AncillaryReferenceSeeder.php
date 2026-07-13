<?php

namespace Database\Seeders;

use App\Models\Ancillary\AncillarySlaDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class AncillaryReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRadiologyCatalogs();
        $this->seedLabTestCatalog();
        $this->seedRxFormulary();
        $this->seedMilestones();
        $this->seedBarrierReasons();
        $this->seedSlaDefinitions();
    }

    private function seedLabTestCatalog(): void
    {
        if (! Schema::hasTable('hosp_ref.lab_test_catalog')) {
            return;
        }

        foreach ($this->labTestCatalog() as $test) {
            $catalogKey = $test['catalog_key'];
            DB::table('hosp_ref.lab_test_catalog')->updateOrInsert(
                ['catalog_key' => $catalogKey],
                [
                    'catalog_uuid' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "zephyrus:lab-test-catalog:{$catalogKey}"),
                    ...$test,
                    'effective_from' => '2026-01-01T00:00:00Z',
                    'effective_to' => null,
                    'is_active' => true,
                    'metadata' => json_encode([
                        'data_origin' => 'governed_reference',
                        'result_value_storage' => 'excluded_from_operational_contract',
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function labTestCatalog(): array
    {
        return [
            ['catalog_key' => 'lab.bmp', 'local_code' => 'BMP', 'loinc_code' => '24321-2', 'label' => 'Basic metabolic panel', 'department' => 'chemistry', 'test_family' => 'metabolic_panel', 'expected_tat_class' => 'routine', 'decision_class' => 'discharge_gate', 'specimen_type' => 'serum'],
            ['catalog_key' => 'lab.troponin_i', 'local_code' => 'TROPONIN_I', 'loinc_code' => '10839-9', 'label' => 'Troponin I', 'department' => 'chemistry', 'test_family' => 'troponin', 'expected_tat_class' => 'stat', 'decision_class' => 'ed_disposition', 'specimen_type' => 'plasma'],
            ['catalog_key' => 'lab.cbc', 'local_code' => 'CBC', 'loinc_code' => '57021-8', 'label' => 'Complete blood count', 'department' => 'hematology', 'test_family' => 'blood_count', 'expected_tat_class' => 'routine', 'decision_class' => 'none', 'specimen_type' => 'whole_blood'],
            ['catalog_key' => 'lab.pt_inr', 'local_code' => 'PT_INR', 'loinc_code' => '5902-2', 'label' => 'Prothrombin time and INR', 'department' => 'coagulation', 'test_family' => 'coagulation', 'expected_tat_class' => 'stat', 'decision_class' => 'or_gate', 'specimen_type' => 'citrated_plasma'],
            ['catalog_key' => 'lab.blood_culture', 'local_code' => 'BLOOD_CULTURE', 'loinc_code' => null, 'label' => 'Blood culture', 'department' => 'microbiology', 'test_family' => 'culture', 'expected_tat_class' => 'extended', 'decision_class' => 'none', 'specimen_type' => 'blood_culture_set'],
            ['catalog_key' => 'ap.surgical_pathology', 'local_code' => 'SURG_PATH', 'loinc_code' => null, 'label' => 'Surgical pathology', 'department' => 'pathology', 'test_family' => 'surgical_pathology', 'expected_tat_class' => 'extended', 'decision_class' => 'none', 'specimen_type' => 'tissue'],
            ['catalog_key' => 'ap.frozen_section', 'local_code' => 'FROZEN_SECTION', 'loinc_code' => null, 'label' => 'Frozen section', 'department' => 'pathology', 'test_family' => 'frozen_section', 'expected_tat_class' => 'stat', 'decision_class' => 'or_gate', 'specimen_type' => 'fresh_tissue'],
            ['catalog_key' => 'bb.type_screen', 'local_code' => 'TYPE_SCREEN', 'loinc_code' => null, 'label' => 'Type and screen', 'department' => 'blood_bank', 'test_family' => 'compatibility', 'expected_tat_class' => 'stat', 'decision_class' => 'or_gate', 'specimen_type' => 'whole_blood'],
            ['catalog_key' => 'bb.crossmatch', 'local_code' => 'CROSSMATCH', 'loinc_code' => null, 'label' => 'Crossmatch', 'department' => 'blood_bank', 'test_family' => 'compatibility', 'expected_tat_class' => 'stat', 'decision_class' => 'or_gate', 'specimen_type' => 'whole_blood'],
        ];
    }

    private function seedRxFormulary(): void
    {
        if (! Schema::hasTable('hosp_ref.rx_formulary')) {
            return;
        }

        foreach ($this->rxFormulary() as $item) {
            $formularyKey = $item['formulary_key'];
            DB::table('hosp_ref.rx_formulary')->updateOrInsert(
                ['formulary_key' => $formularyKey],
                [
                    'formulary_uuid' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "zephyrus:rx-formulary:{$formularyKey}"),
                    ...$item,
                    'effective_from' => '2026-01-01T00:00:00Z',
                    'effective_to' => null,
                    'is_active' => true,
                    'metadata' => json_encode([
                        'data_origin' => 'governed_reference',
                        'individual_risk_scoring' => 'prohibited',
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function rxFormulary(): array
    {
        return [
            ['formulary_key' => 'rx.ceftriaxone_iv', 'local_code' => 'CEFTRIAXONE_1G_IV', 'rxnorm_cui' => '2193', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Ceftriaxone 1 g intravenous', 'therapeutic_class' => 'antibiotic', 'dosage_form' => 'injection', 'default_route' => 'intravenous', 'default_prep_branch' => 'adc', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => false],
            ['formulary_key' => 'rx.vancomycin_iv', 'local_code' => 'VANCOMYCIN_IV', 'rxnorm_cui' => '11124', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Vancomycin intravenous infusion', 'therapeutic_class' => 'antibiotic', 'dosage_form' => 'infusion', 'default_route' => 'intravenous', 'default_prep_branch' => 'iv_room', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => false],
            ['formulary_key' => 'rx.acetaminophen_tab', 'local_code' => 'ACETAMINOPHEN_500_TAB', 'rxnorm_cui' => '161', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Acetaminophen 500 mg tablet', 'therapeutic_class' => 'analgesic', 'dosage_form' => 'tablet', 'default_route' => 'oral', 'default_prep_branch' => 'adc', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => false],
            ['formulary_key' => 'rx.ondansetron_inj', 'local_code' => 'ONDANSETRON_INJ', 'rxnorm_cui' => '26225', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Ondansetron injection', 'therapeutic_class' => 'antiemetic', 'dosage_form' => 'injection', 'default_route' => 'intravenous', 'default_prep_branch' => 'adc', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => false],
            ['formulary_key' => 'rx.morphine_inj', 'local_code' => 'MORPHINE_INJ', 'rxnorm_cui' => '7052', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Morphine injection', 'therapeutic_class' => 'opioid_analgesic', 'dosage_form' => 'injection', 'default_route' => 'intravenous', 'default_prep_branch' => 'adc', 'is_controlled' => true, 'controlled_schedule' => 'II', 'is_hazardous' => false, 'is_high_alert' => true],
            ['formulary_key' => 'rx.cyclophosphamide_iv', 'local_code' => 'CYCLOPHOSPHAMIDE_IV', 'rxnorm_cui' => '3002', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Cyclophosphamide intravenous infusion', 'therapeutic_class' => 'antineoplastic', 'dosage_form' => 'infusion', 'default_route' => 'intravenous', 'default_prep_branch' => 'iv_room', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => true, 'is_high_alert' => true],
            ['formulary_key' => 'rx.tpn_adult', 'local_code' => 'TPN_ADULT', 'rxnorm_cui' => null, 'ndc_code' => null, 'terminology_status' => 'unmapped_local', 'label' => 'Total parenteral nutrition, adult admixture', 'therapeutic_class' => 'parenteral_nutrition', 'dosage_form' => 'infusion', 'default_route' => 'intravenous', 'default_prep_branch' => 'iv_room', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => true],
            ['formulary_key' => 'rx.warfarin_tab', 'local_code' => 'WARFARIN_5_TAB', 'rxnorm_cui' => '11289', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Warfarin 5 mg tablet', 'therapeutic_class' => 'anticoagulant', 'dosage_form' => 'tablet', 'default_route' => 'oral', 'default_prep_branch' => 'central', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => true],
            ['formulary_key' => 'rx.heparin_infusion', 'local_code' => 'HEPARIN_INFUSION', 'rxnorm_cui' => '5224', 'ndc_code' => null, 'terminology_status' => 'mapped', 'label' => 'Heparin continuous infusion', 'therapeutic_class' => 'anticoagulant', 'dosage_form' => 'infusion', 'default_route' => 'intravenous', 'default_prep_branch' => 'iv_room', 'is_controlled' => false, 'controlled_schedule' => null, 'is_hazardous' => false, 'is_high_alert' => true],
        ];
    }

    private function seedRadiologyCatalogs(): void
    {
        foreach ($this->radiologyModalities() as $modality) {
            DB::table('hosp_ref.rad_modalities')->updateOrInsert(
                ['code' => $modality['code']],
                [...$modality, 'is_active' => true, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        foreach ($this->radiologySubspecialties() as $subspecialty) {
            DB::table('hosp_ref.rad_subspecialties')->updateOrInsert(
                ['code' => $subspecialty['code']],
                [...$subspecialty, 'is_active' => true, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function radiologyModalities(): array
    {
        return [
            ['code' => 'XR', 'label' => 'Radiography', 'modality_group' => 'projection', 'is_cross_sectional' => false, 'supports_portable' => true, 'contrast_screening_applicable' => false],
            ['code' => 'CT', 'label' => 'Computed Tomography', 'modality_group' => 'cross_sectional', 'is_cross_sectional' => true, 'supports_portable' => false, 'contrast_screening_applicable' => true],
            ['code' => 'MRI', 'label' => 'Magnetic Resonance Imaging', 'modality_group' => 'cross_sectional', 'is_cross_sectional' => true, 'supports_portable' => false, 'contrast_screening_applicable' => true],
            ['code' => 'US', 'label' => 'Ultrasound', 'modality_group' => 'sonography', 'is_cross_sectional' => false, 'supports_portable' => true, 'contrast_screening_applicable' => false],
            ['code' => 'NM', 'label' => 'Nuclear Medicine', 'modality_group' => 'molecular', 'is_cross_sectional' => false, 'supports_portable' => false, 'contrast_screening_applicable' => false],
            ['code' => 'IR', 'label' => 'Interventional Radiology', 'modality_group' => 'interventional', 'is_cross_sectional' => false, 'supports_portable' => false, 'contrast_screening_applicable' => true],
        ];
    }

    /** @return list<array{code:string,label:string}> */
    private function radiologySubspecialties(): array
    {
        return [
            ['code' => 'general', 'label' => 'General Radiology'],
            ['code' => 'neuro', 'label' => 'Neuroradiology'],
            ['code' => 'body', 'label' => 'Body Imaging'],
            ['code' => 'chest', 'label' => 'Cardiothoracic Imaging'],
            ['code' => 'msk', 'label' => 'Musculoskeletal Imaging'],
            ['code' => 'breast', 'label' => 'Breast Imaging'],
            ['code' => 'pediatric', 'label' => 'Pediatric Radiology'],
            ['code' => 'nuclear', 'label' => 'Nuclear Medicine'],
            ['code' => 'ir', 'label' => 'Interventional Radiology'],
        ];
    }

    private function seedMilestones(): void
    {
        foreach ($this->milestones() as $milestone) {
            DB::table('hosp_ref.ancillary_milestone_types')->updateOrInsert(
                ['code' => $milestone['code']],
                [
                    ...$milestone,
                    'source_precedence' => json_encode($milestone['source_precedence'], JSON_THROW_ON_ERROR),
                    'process_ids' => json_encode($milestone['process_ids'], JSON_THROW_ON_ERROR),
                    'display_metadata' => json_encode($milestone['display_metadata'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedBarrierReasons(): void
    {
        foreach ($this->barrierReasons() as $reason) {
            DB::table('hosp_ref.ancillary_barrier_reasons')->updateOrInsert(
                ['reason_code' => $reason['reason_code']],
                [
                    ...$reason,
                    'is_active' => true,
                    'metadata' => json_encode(['owner' => 'ancillary_operations'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedSlaDefinitions(): void
    {
        foreach ($this->slaDefinitions() as $definition) {
            $existing = AncillarySlaDefinition::query()
                ->where('metric_key', $definition['metric_key'])
                ->where('version', $definition['version'])
                ->first();

            if ($existing !== null) {
                // A seeded row becomes governed policy as soon as an operator
                // tunes or approves it. Never overwrite that version in place.
                continue;
            }

            AncillarySlaDefinition::query()->create([
                'definition_uuid' => (string) Str::uuid(),
                ...$definition,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function milestones(): array
    {
        return [
            // Radiology — D5 order-to-final, D6 critical communication.
            $this->milestone('rad', 'RAD_ORDERED', 'Order placed', 'order', 10, true, false, 'ehr_cpoe', ['ehr_cpoe', 'ris'], 'imaging-ordered', ['D5']),
            $this->milestone('rad', 'RAD_PROTOCOLLED', 'Protocol assigned', 'preparation', 20, false, false, 'ris', ['ris', 'ehr_cpoe'], 'protocol-completed', ['D5']),
            $this->milestone('rad', 'RAD_SCHEDULED', 'Scheduled or queued', 'preparation', 30, false, false, 'ris', ['ris', 'ehr_scheduling'], 'study-scheduled', ['D5']),
            $this->milestone('rad', 'RAD_PREP_COMPLETE', 'Preparation complete', 'preparation', 40, false, false, 'ris', ['ris', 'ehr_workflow'], 'prerequisites-completed', ['D5', 'C11']),
            $this->milestone('rad', 'RAD_TRANSPORT_REQUESTED', 'Transport requested', 'transport', 50, false, false, 'zephyrus_transport', ['zephyrus_transport', 'transport_vendor'], 'transport-requested', ['D5', 'E1']),
            $this->milestone('rad', 'RAD_TRANSPORT_COMPLETE', 'Patient arrived', 'transport', 60, false, false, 'zephyrus_transport', ['zephyrus_transport', 'transport_vendor'], 'handoff-completed', ['D5', 'E1']),
            $this->milestone('rad', 'RAD_EXAM_START', 'Exam started', 'acquisition', 70, false, false, 'mpps', ['mpps', 'ris'], 'acquisition-started', ['D5']),
            $this->milestone('rad', 'RAD_EXAM_END', 'Exam completed', 'acquisition', 80, true, false, 'mpps', ['mpps', 'ris'], 'images-acquired', ['D5']),
            $this->milestone('rad', 'RAD_IMAGES_AVAILABLE', 'Images available in PACS', 'interpretation', 90, false, false, 'pacs', ['pacs', 'fhir_imaging_study'], 'images-available', ['D5']),
            $this->milestone('rad', 'RAD_PRELIM', 'Preliminary report', 'interpretation', 100, false, false, 'reporting', ['reporting', 'ris'], 'study-interpreted', ['D5']),
            $this->milestone('rad', 'RAD_FINAL', 'Final report', 'interpretation', 110, true, false, 'reporting', ['reporting', 'ris', 'fhir_diagnostic_report'], 'report-finalized', ['D5']),
            $this->milestone('rad', 'RAD_CRITICAL_NOTIFIED', 'Critical result communicated', 'communication', 120, false, false, 'ctrm', ['ctrm', 'reporting'], 'responsible-team-contacted', ['D6']),
            $this->milestone('rad', 'RAD_CRITICAL_ACKED', 'Critical result acknowledged', 'communication', 130, false, false, 'ctrm', ['ctrm'], 'finding-acknowledged', ['D6']),
            $this->milestone('rad', 'RAD_FOLLOWUP_TRACKED', 'Follow-up recommendation tracked', 'follow_up', 140, false, false, 'followup_system', ['followup_system', 'reporting'], 'followup-action-recorded', ['D6']),
            $this->milestone('rad', 'RAD_CANCELLED', 'Order cancelled', 'terminal', 999, false, true, 'ehr_cpoe', ['ehr_cpoe', 'ris'], 'imaging-order-cancelled', ['D5']),

            // Clinical lab — D1 fulfillment, D2 rework, D3 communication.
            $this->milestone('lab', 'LAB_ORDERED', 'Ordered', 'order', 10, true, false, 'ehr_cpoe', ['ehr_cpoe', 'lis'], 'test-ordered', ['D1']),
            $this->milestone('lab', 'LAB_COLLECTED', 'Collected', 'pre_analytic', 20, false, false, 'bedside_collection', ['bedside_collection', 'lis'], 'specimen-collected', ['D1', 'D2']),
            $this->milestone('lab', 'LAB_IN_TRANSIT', 'In transit', 'pre_analytic', 30, false, false, 'tube_courier', ['tube_courier', 'lis'], 'specimen-in-transit', ['D1']),
            $this->milestone('lab', 'LAB_RECEIVED', 'Received or accessioned', 'pre_analytic', 40, true, false, 'lis', ['lis'], 'specimen-accessioned', ['D1']),
            $this->milestone('lab', 'LAB_ANALYSIS_STARTED', 'Analysis started', 'analytic', 50, false, false, 'middleware', ['middleware', 'lis'], 'analysis-started', ['D1']),
            $this->milestone('lab', 'LAB_PRELIM', 'Preliminary result', 'post_analytic', 60, false, false, 'lis', ['lis'], 'preliminary-result-recorded', ['D1']),
            $this->milestone('lab', 'LAB_RESULTED', 'Resulted', 'post_analytic', 70, true, false, 'lis', ['lis', 'fhir_observation'], 'analysis-completed', ['D1']),
            $this->milestone('lab', 'LAB_VERIFIED', 'Verified or released', 'post_analytic', 80, false, false, 'lis', ['lis', 'middleware'], 'result-validated', ['D1']),
            $this->milestone('lab', 'LAB_CRITICAL_NOTIFIED', 'Critical callback initiated', 'communication', 90, false, false, 'lis_callback', ['lis_callback', 'lis'], 'responsible-team-contacted', ['D3']),
            $this->milestone('lab', 'LAB_CRITICAL_ACKED', 'Critical value read-back complete', 'communication', 100, false, false, 'lis_callback', ['lis_callback'], 'result-acknowledged', ['D3']),
            $this->milestone('lab', 'LAB_REJECTED', 'Specimen rejected', 'exception', 110, false, false, 'lis', ['lis'], 'quality-exception-detected', ['D2']),
            $this->milestone('lab', 'LAB_RECOLLECT_ORDERED', 'Recollect requested', 'rework', 120, false, false, 'ehr_cpoe', ['ehr_cpoe', 'lis'], 'recollection-requested', ['D2']),
            $this->milestone('lab', 'LAB_CORRECTED', 'Corrected result', 'correction', 130, false, false, 'lis', ['lis'], 'result-corrected', ['D1', 'D2']),
            $this->milestone('lab', 'LAB_CANCELLED', 'Cancelled', 'terminal', 999, false, true, 'ehr_cpoe', ['ehr_cpoe', 'lis'], 'test-cancelled', ['D1']),

            // Anatomic pathology — D7.
            $this->milestone('pathology', 'AP_SPECIMEN_OUT', 'Specimen out of procedure', 'pre_analytic', 10, false, false, 'or_workflow', ['or_workflow', 'ap_lis'], 'specimen-dispatched', ['D7']),
            $this->milestone('pathology', 'AP_RECEIVED', 'Specimen received', 'pre_analytic', 20, true, false, 'ap_lis', ['ap_lis'], 'specimen-received', ['D7']),
            $this->milestone('pathology', 'AP_GROSSED', 'Gross examination complete', 'processing', 30, false, false, 'ap_lis', ['ap_lis'], 'blocks-prepared', ['D7']),
            $this->milestone('pathology', 'AP_PROCESSING_BATCH', 'Histology processing batch entered', 'processing', 40, false, false, 'ap_lis', ['ap_lis'], 'processing-batch-entered', ['D7']),
            $this->milestone('pathology', 'AP_SLIDES_READY', 'Slides ready', 'review', 50, false, false, 'ap_lis', ['ap_lis'], 'slides-ready', ['D7']),
            $this->milestone('pathology', 'AP_DIAGNOSED', 'Diagnosis recorded', 'review', 60, false, false, 'ap_lis', ['ap_lis'], 'diagnosis-recorded', ['D7']),
            $this->milestone('pathology', 'AP_SIGNED_OUT', 'Final report signed out', 'reporting', 70, true, false, 'ap_lis', ['ap_lis', 'fhir_diagnostic_report'], 'report-finalized', ['D7']),
            $this->milestone('pathology', 'AP_FROZEN_STARTED', 'Frozen section timer started', 'frozen', 80, false, false, 'ap_lis', ['ap_lis', 'or_workflow'], 'frozen-section-started', ['D7', 'C11']),
            $this->milestone('pathology', 'AP_FROZEN_RESULTED', 'Frozen section result communicated', 'frozen', 90, false, false, 'ap_lis', ['ap_lis'], 'frozen-section-resulted', ['D7', 'C11']),

            // Blood bank — D4.
            $this->milestone('blood_bank', 'BB_ORDERED', 'Blood-bank request ordered', 'order', 10, true, false, 'ehr_cpoe', ['ehr_cpoe', 'blood_bank'], 'blood-product-ordered', ['D4']),
            $this->milestone('blood_bank', 'BB_TNS_READY', 'Type and screen ready', 'testing', 20, false, false, 'blood_bank', ['blood_bank'], 'type-screen-ready', ['D4']),
            $this->milestone('blood_bank', 'BB_CROSSMATCH_READY', 'Crossmatch ready', 'testing', 30, false, false, 'blood_bank', ['blood_bank'], 'unit-allocated', ['D4']),
            $this->milestone('blood_bank', 'BB_UNIT_ISSUED', 'Unit issued', 'issue', 40, false, false, 'blood_bank', ['blood_bank'], 'unit-issued', ['D4']),
            $this->milestone('blood_bank', 'BB_MTP_ACTIVATED', 'Massive transfusion protocol activated', 'mtp', 50, false, false, 'blood_bank', ['blood_bank', 'ehr_cpoe'], 'mtp-activated', ['D4']),
            $this->milestone('blood_bank', 'BB_MTP_CLOSED', 'Massive transfusion protocol closed', 'mtp', 60, false, true, 'blood_bank', ['blood_bank'], 'mtp-closed', ['D4']),

            // Pharmacy — D11 administration, D12 discharge, E9 logistics, F8 safety.
            $this->milestone('rx', 'RX_ORDERED', 'Medication ordered', 'order', 10, true, false, 'ehr_cpoe', ['ehr_cpoe', 'pharmacy'], 'medication-ordered', ['D11', 'D12', 'F8']),
            $this->milestone('rx', 'RX_QUEUE_IN', 'Verification queue entered', 'verification', 20, false, false, 'pharmacy_queue', ['pharmacy_queue', 'ehr_cpoe'], 'verification-queued', ['D11']),
            $this->milestone('rx', 'RX_VERIFIED', 'Pharmacist verified', 'verification', 30, false, false, 'pharmacy_queue', ['pharmacy_queue', 'pharmacy'], 'order-verified', ['D11', 'D12', 'F8']),
            $this->milestone('rx', 'RX_PREP_STARTED', 'Preparation started', 'preparation', 40, false, false, 'ivwms', ['ivwms', 'pharmacy'], 'dose-preparation-started', ['D11']),
            $this->milestone('rx', 'RX_PREP_COMPLETE', 'Preparation complete', 'preparation', 50, false, false, 'ivwms', ['ivwms', 'pharmacy'], 'dose-prepared', ['D11', 'D12', 'F8']),
            $this->milestone('rx', 'RX_CHECKED', 'Final preparation check', 'preparation', 60, false, false, 'ivwms', ['ivwms'], 'dose-checked', ['D11']),
            $this->milestone('rx', 'RX_DISPENSED', 'Dispensed or vended', 'dispense', 70, true, false, 'pharmacy', ['pharmacy', 'adc'], 'dose-dispensed', ['D11', 'E9']),
            $this->milestone('rx', 'RX_DELIVERED', 'Delivered to care area', 'delivery', 80, false, false, 'dose_tracking', ['dose_tracking', 'adc', 'pharmacy'], 'dose-delivered', ['D11', 'D12', 'E9']),
            $this->milestone('rx', 'RX_ADMINISTERED', 'Administered', 'administration', 90, false, false, 'bcma_warehouse', ['bcma_realtime', 'bcma_warehouse'], 'dose-administered', ['D11', 'F8']),
            $this->milestone('rx', 'RX_MISSING_DOSE', 'Missing dose or re-request', 'exception', 100, false, false, 'ehr_workflow', ['ehr_workflow', 'pharmacy'], 'missing-dose-requested', ['D11', 'E9']),
            $this->milestone('rx', 'RX_RETURNED', 'Returned', 'reverse_logistics', 110, false, false, 'adc', ['adc', 'pharmacy'], 'dose-returned', ['E9', 'F8']),
            $this->milestone('rx', 'RX_WASTED', 'Wasted', 'reverse_logistics', 120, false, false, 'adc', ['adc'], 'dose-wasted', ['F8']),
            $this->milestone('rx', 'RX_OVERRIDE', 'Override transaction', 'exception', 130, false, false, 'adc', ['adc'], 'override-recorded', ['F8']),
            $this->milestone('rx', 'RX_DISCREPANCY_OPEN', 'Controlled discrepancy opened', 'exception', 140, false, false, 'adc', ['adc'], 'discrepancy-opened', ['F8']),
            $this->milestone('rx', 'RX_DISCREPANCY_RESOLVED', 'Controlled discrepancy resolved', 'exception', 150, false, false, 'adc', ['adc'], 'discrepancy-resolved', ['F8']),
            $this->milestone('rx', 'RX_DISCONTINUED', 'Discontinued', 'terminal', 999, false, true, 'ehr_cpoe', ['ehr_cpoe', 'pharmacy'], 'medication-discontinued', ['D11']),
        ];
    }

    /** @return list<array{department:string,reason_code:string,category:string,label:string}> */
    private function barrierReasons(): array
    {
        return [
            ['department' => 'rad', 'reason_code' => 'RAD_PREP_INCOMPLETE', 'category' => 'medical', 'label' => 'Imaging preparation incomplete'],
            ['department' => 'rad', 'reason_code' => 'RAD_TRANSPORT_DELAY', 'category' => 'logistical', 'label' => 'Imaging transport delayed'],
            ['department' => 'rad', 'reason_code' => 'RAD_SCANNER_UNAVAILABLE', 'category' => 'logistical', 'label' => 'Scanner unavailable'],
            ['department' => 'rad', 'reason_code' => 'RAD_READ_QUEUE', 'category' => 'medical', 'label' => 'Interpretation queue delay'],
            ['department' => 'lab', 'reason_code' => 'LAB_COLLECTION_DELAY', 'category' => 'medical', 'label' => 'Specimen collection delayed'],
            ['department' => 'lab', 'reason_code' => 'LAB_TRANSPORT_DELAY', 'category' => 'logistical', 'label' => 'Specimen transport delayed'],
            ['department' => 'lab', 'reason_code' => 'LAB_ANALYZER_UNAVAILABLE', 'category' => 'logistical', 'label' => 'Analyzer unavailable'],
            ['department' => 'lab', 'reason_code' => 'LAB_RECOLLECT_REQUIRED', 'category' => 'medical', 'label' => 'Specimen recollection required'],
            ['department' => 'pathology', 'reason_code' => 'AP_PROCESSING_DELAY', 'category' => 'medical', 'label' => 'Pathology processing delayed'],
            ['department' => 'pathology', 'reason_code' => 'AP_REFERRAL_DELAY', 'category' => 'logistical', 'label' => 'Outside pathology referral delayed'],
            ['department' => 'blood_bank', 'reason_code' => 'BB_COMPATIBILITY_DELAY', 'category' => 'medical', 'label' => 'Compatibility testing delayed'],
            ['department' => 'blood_bank', 'reason_code' => 'BB_INVENTORY_CONSTRAINT', 'category' => 'logistical', 'label' => 'Blood product inventory constrained'],
            ['department' => 'rx', 'reason_code' => 'RX_VERIFICATION_DELAY', 'category' => 'medical', 'label' => 'Pharmacist verification delayed'],
            ['department' => 'rx', 'reason_code' => 'RX_PREPARATION_DELAY', 'category' => 'logistical', 'label' => 'Medication preparation delayed'],
            ['department' => 'rx', 'reason_code' => 'RX_DELIVERY_DELAY', 'category' => 'logistical', 'label' => 'Medication delivery delayed'],
            ['department' => 'rx', 'reason_code' => 'RX_STOCKOUT', 'category' => 'logistical', 'label' => 'Medication stockout'],
            ['department' => 'rx', 'reason_code' => 'RX_DISCHARGE_COORDINATION', 'category' => 'logistical', 'label' => 'Discharge medication coordination delay'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function slaDefinitions(): array
    {
        return [
            $this->sla('rad', 'rad.stat_order_final', 'STAT imaging order to final', 'RAD_ORDERED', 'RAD_FINAL', 90, 120, 'item_clock', null, 'stat', null, true, 'demo_local_policy'),
            $this->sla('rad', 'rad.ed_image_final', 'ED images available to final', 'RAD_IMAGES_AVAILABLE', 'RAD_FINAL', 30, 60, 'item_clock', null, null, 'emergency', true, 'demo_local_policy'),
            $this->sla('rad', 'rad.ed_ct_order_complete', 'ED CT order to acquisition complete', 'RAD_ORDERED', 'RAD_EXAM_END', null, null, 'median', null, null, 'emergency', false, 'reference_only_not_policy', ['modality' => 'CT']),
            $this->sla('rad', 'rad.transport', 'Imaging transport request to arrival', 'RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE', null, null, 'item_clock', null, null, 'inpatient', false, 'site_policy_required'),
            $this->sla('rad', 'rad.study.order_exam_start', 'Order to exam start', 'RAD_ORDERED', 'RAD_EXAM_START', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 10]),
            $this->sla('rad', 'rad.study.exam_duration', 'Exam start to acquisition complete', 'RAD_EXAM_START', 'RAD_EXAM_END', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 20]),
            $this->sla('rad', 'rad.study.acquisition_pacs', 'Acquisition complete to images available', 'RAD_EXAM_END', 'RAD_IMAGES_AVAILABLE', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 30]),
            $this->sla('rad', 'rad.study.images_preliminary', 'Images available to preliminary report', 'RAD_IMAGES_AVAILABLE', 'RAD_PRELIM', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 40]),
            $this->sla('rad', 'rad.study.images_final', 'Images available to final report', 'RAD_IMAGES_AVAILABLE', 'RAD_FINAL', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 50]),
            $this->sla('rad', 'rad.study.order_final', 'Order to final report', 'RAD_ORDERED', 'RAD_FINAL', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'sequence' => 60, 'primary_trend' => true]),
            $this->sla('rad', 'rad.critical_ack', 'Imaging critical finding acknowledgment', 'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED', null, null, 'compliance_rate', 99, null, null, false, 'site_policy_required'),
            $this->sla('lab', 'lab.troponin_order_result', 'ED troponin order to verified result', 'LAB_ORDERED', 'LAB_VERIFIED', 45, 60, 'item_clock', null, 'stat', 'emergency', true, 'demo_local_policy', ['test_family' => 'troponin']),
            $this->sla('lab', 'lab.stat_tat', 'STAT lab order to verified result', 'LAB_ORDERED', 'LAB_VERIFIED', 45, 60, 'item_clock', 90, 'stat', null, true, 'demo_local_policy'),
            $this->sla('lab', 'lab.collect_receive', 'Specimen collection to receipt', 'LAB_COLLECTED', 'LAB_RECEIVED', null, null, 'median', null, null, null, false, 'reference_only_not_policy'),
            $this->sla('lab', 'lab.critical_notify', 'Critical result to acknowledgment', 'LAB_VERIFIED', 'LAB_CRITICAL_ACKED', null, null, 'median', null, null, null, false, 'site_policy_required'),
            $this->sla('lab', 'lab.study.order_collect', 'Order to specimen collection', 'LAB_ORDERED', 'LAB_COLLECTED', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'phase' => 'collection', 'sequence' => 10]),
            $this->sla('lab', 'lab.study.collect_receive', 'Specimen collection to receipt', 'LAB_COLLECTED', 'LAB_RECEIVED', null, null, 'median', null, null, null, false, 'reference_only_not_policy', ['study_segment' => true, 'phase' => 'transport', 'sequence' => 20]),
            $this->sla('lab', 'lab.study.receive_result', 'Specimen receipt to result', 'LAB_RECEIVED', 'LAB_RESULTED', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'phase' => 'analytic', 'sequence' => 30]),
            $this->sla('lab', 'lab.study.result_verify', 'Result to verification', 'LAB_RESULTED', 'LAB_VERIFIED', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'phase' => 'post_analytic', 'sequence' => 40]),
            $this->sla('lab', 'lab.study.order_verify', 'Order to verified result', 'LAB_ORDERED', 'LAB_VERIFIED', null, null, 'median', null, null, null, false, 'study_clock_no_numeric_benchmark', ['study_segment' => true, 'phase' => 'end_to_end', 'sequence' => 50, 'primary_trend' => true]),
            $this->sla('pathology', 'lab.ap_routine', 'Routine AP receipt to sign-out', 'AP_RECEIVED', 'AP_SIGNED_OUT', 2160, 2880, 'item_clock', 90, 'routine', null, true, 'demo_local_policy'),
            $this->sla('pathology', 'lab.frozen', 'Frozen section result communication', 'AP_FROZEN_STARTED', 'AP_FROZEN_RESULTED', 15, 20, 'item_clock', null, 'stat', 'perioperative', true, 'demo_local_policy'),
            $this->sla('rx', 'rx.stat_dispense', 'STAT medication order to dispense', 'RX_ORDERED', 'RX_DISPENSED', 10, 15, 'item_clock', null, 'stat', null, true, 'demo_local_policy'),
            $this->sla('rx', 'rx.first_dose_admin', 'First dose order to administration', 'RX_ORDERED', 'RX_ADMINISTERED', 45, 60, 'item_clock', null, 'first_dose', null, true, 'demo_local_policy'),
            $this->sla('rx', 'rx.sepsis_abx', 'Sepsis antibiotic order to administration', 'RX_ORDERED', 'RX_ADMINISTERED', 150, 180, 'item_clock', null, 'sepsis', null, true, 'demo_local_policy'),
            $this->sla('rx', 'rx.discharge_ready', 'Discharge medication queue to delivery', 'RX_QUEUE_IN', 'RX_DELIVERED', null, null, 'item_clock', null, 'discharge', null, false, 'planned_discharge_policy_required'),
            $this->sla('rx', 'rx.discrepancy_age', 'Controlled discrepancy age', 'RX_DISCREPANCY_OPEN', 'RX_DISCREPANCY_RESOLVED', null, null, 'item_clock', null, null, null, false, 'shift_end_policy_required'),
        ];
    }

    /** @return array<string, mixed> */
    private function milestone(
        string $department,
        string $code,
        string $label,
        string $phase,
        int $ordinal,
        bool $minimum,
        bool $terminal,
        string $sourceClass,
        array $precedence,
        string $ocelEventType,
        array $processIds,
    ): array {
        return [
            'department' => $department,
            'code' => $code,
            'label' => $label,
            'phase' => $phase,
            'ordinal' => $ordinal,
            'is_terminal' => $terminal,
            'is_optional' => ! $minimum && ! $terminal,
            'is_minimum_feed' => $minimum,
            'expected_source_class' => $sourceClass,
            'source_precedence' => $precedence,
            'ocel_event_type' => $ocelEventType,
            'process_ids' => $processIds,
            'display_metadata' => ['state_group' => $phase],
        ];
    }

    /** @return array<string, mixed> */
    private function sla(
        string $department,
        string $metricKey,
        string $label,
        string $startCode,
        string $stopCode,
        ?int $warningMinutes,
        ?int $breachMinutes,
        string $statistic,
        ?float $targetValue,
        ?string $priority,
        ?string $patientClass,
        bool $active,
        string $sourceReference,
        array $scope = [],
    ): array {
        return [
            'department' => $department,
            'metric_key' => $metricKey,
            'label' => $label,
            'start_milestone_code' => $startCode,
            'stop_milestone_code' => $stopCode,
            'priority' => $priority,
            'patient_class' => $patientClass,
            'scope' => $scope,
            'statistic' => $statistic,
            'warning_minutes' => $warningMinutes,
            'breach_minutes' => $breachMinutes,
            'target_value' => $targetValue,
            'direction' => $statistic === 'compliance_rate' ? 'higher_is_better' : 'lower_is_better',
            'unit' => $statistic === 'compliance_rate' ? 'percent' : 'minutes',
            'effective_from' => now()->startOfYear(),
            'effective_to' => null,
            'version' => 1,
            'active' => $active,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'definition_text' => "{$startCode} to {$stopCode}; population and exclusions are defined by department, priority, patient class, and scope.",
            'source_reference_id' => $sourceReference,
        ];
    }
}
