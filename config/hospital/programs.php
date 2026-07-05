<?php

/**
 * Program catalog (Layer 2): clinically distinct capabilities within a service
 * line, each tied to a canonical service_line_code (config/hospital/service-lines.php)
 * and a designation body. Projected into hosp_ref.programs by
 * `deployment:seed-registry` (App\Services\Deployment\ServiceLineRegistrar).
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5)
 * Report: docs/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md (§§1-23)
 *
 * Per-program schema (maps 1:1 to hosp_ref.programs columns):
 *   service_line  -> service_line_code (FK)
 *   name          -> display_name
 *   type          -> designation_type          (state_designation|accreditation_body|internal)
 *   body          -> designation_body          (ACS|TJC|ACOG|AAP|ABA|OPTN|FACT|ACC|CMS|state|internal)
 *   implies       -> capability_level_implied  (a hosp_ref.capability_levels code)
 *   population    -> adult_or_pediatric        (adult|pediatric|both)
 */

return [

    'programs' => [

        // Trauma / acute care surgery
        'adult_level_i_trauma' => ['service_line' => 'trauma_acute_care_surgery', 'name' => 'Adult Level I Trauma Center', 'type' => 'accreditation_body', 'body' => 'ACS', 'implies' => 'quaternary', 'population' => 'adult'],
        'adult_level_ii_trauma' => ['service_line' => 'trauma_acute_care_surgery', 'name' => 'Adult Level II Trauma Center', 'type' => 'accreditation_body', 'body' => 'ACS', 'implies' => 'definitive', 'population' => 'adult'],
        'pediatric_level_i_trauma' => ['service_line' => 'trauma_acute_care_surgery', 'name' => 'Pediatric Level I Trauma Center', 'type' => 'accreditation_body', 'body' => 'ACS', 'implies' => 'quaternary', 'population' => 'pediatric'],

        // Neurosciences / stroke
        'comprehensive_stroke' => ['service_line' => 'neurosciences', 'name' => 'Comprehensive Stroke Center', 'type' => 'accreditation_body', 'body' => 'TJC', 'implies' => 'definitive', 'population' => 'adult'],
        'thrombectomy_capable' => ['service_line' => 'neurosciences', 'name' => 'Thrombectomy-Capable Stroke Center', 'type' => 'accreditation_body', 'body' => 'TJC', 'implies' => 'advanced', 'population' => 'adult'],
        'primary_stroke' => ['service_line' => 'neurosciences', 'name' => 'Primary Stroke Center', 'type' => 'accreditation_body', 'body' => 'TJC', 'implies' => 'routine', 'population' => 'adult'],

        // Cardiovascular
        'stemi_pci' => ['service_line' => 'cardiovascular', 'name' => 'STEMI / Primary PCI Center', 'type' => 'accreditation_body', 'body' => 'ACC', 'implies' => 'advanced', 'population' => 'adult'],
        'cardiac_surgery' => ['service_line' => 'cardiovascular', 'name' => 'Cardiac Surgery', 'type' => 'internal', 'body' => 'internal', 'implies' => 'definitive', 'population' => 'adult'],
        'electrophysiology' => ['service_line' => 'cardiovascular', 'name' => 'Electrophysiology', 'type' => 'internal', 'body' => 'internal', 'implies' => 'advanced', 'population' => 'adult'],
        'advanced_heart_failure' => ['service_line' => 'cardiovascular', 'name' => 'Advanced Heart Failure / MCS', 'type' => 'internal', 'body' => 'internal', 'implies' => 'quaternary', 'population' => 'adult'],

        // Women's health / perinatal
        'regional_perinatal_center' => ['service_line' => 'womens_health', 'name' => 'Regional Perinatal Center', 'type' => 'state_designation', 'body' => 'state', 'implies' => 'definitive', 'population' => 'adult'],
        'mfm' => ['service_line' => 'womens_health', 'name' => 'Maternal-Fetal Medicine', 'type' => 'accreditation_body', 'body' => 'ACOG', 'implies' => 'advanced', 'population' => 'adult'],

        // Neonatology
        'nicu_level_iii' => ['service_line' => 'neonatology', 'name' => 'NICU Level III', 'type' => 'accreditation_body', 'body' => 'AAP', 'implies' => 'advanced', 'population' => 'pediatric'],
        'nicu_level_iv' => ['service_line' => 'neonatology', 'name' => 'NICU Level IV', 'type' => 'accreditation_body', 'body' => 'AAP', 'implies' => 'definitive', 'population' => 'pediatric'],

        // Oncology / cellular therapy
        'bmt' => ['service_line' => 'oncology', 'name' => 'Blood & Marrow Transplant', 'type' => 'accreditation_body', 'body' => 'FACT', 'implies' => 'definitive', 'population' => 'both'],
        'cellular_therapy' => ['service_line' => 'oncology', 'name' => 'Cellular Therapy (CAR-T)', 'type' => 'accreditation_body', 'body' => 'FACT', 'implies' => 'advanced', 'population' => 'both'],

        // Transplant (OPTN)
        'kidney' => ['service_line' => 'transplant', 'name' => 'Kidney Transplant', 'type' => 'accreditation_body', 'body' => 'OPTN', 'implies' => 'definitive', 'population' => 'both'],
        'liver' => ['service_line' => 'transplant', 'name' => 'Liver Transplant', 'type' => 'accreditation_body', 'body' => 'OPTN', 'implies' => 'definitive', 'population' => 'both'],
        'pancreas' => ['service_line' => 'transplant', 'name' => 'Pancreas Transplant', 'type' => 'accreditation_body', 'body' => 'OPTN', 'implies' => 'definitive', 'population' => 'adult'],
        'heart' => ['service_line' => 'transplant', 'name' => 'Heart Transplant', 'type' => 'accreditation_body', 'body' => 'OPTN', 'implies' => 'quaternary', 'population' => 'both'],
        'lung' => ['service_line' => 'transplant', 'name' => 'Lung Transplant', 'type' => 'accreditation_body', 'body' => 'OPTN', 'implies' => 'quaternary', 'population' => 'both'],

        // Burn
        'burn_center' => ['service_line' => 'burn', 'name' => 'Verified Burn Center', 'type' => 'accreditation_body', 'body' => 'ABA', 'implies' => 'definitive', 'population' => 'both'],

        // Rehabilitation
        'acute_inpatient_rehab' => ['service_line' => 'rehabilitation', 'name' => 'Acute Inpatient Rehabilitation', 'type' => 'accreditation_body', 'body' => 'CMS', 'implies' => 'definitive', 'population' => 'both'],

        // Pediatrics
        'picu' => ['service_line' => 'pediatrics', 'name' => 'Pediatric Intensive Care Unit', 'type' => 'internal', 'body' => 'internal', 'implies' => 'advanced', 'population' => 'pediatric'],
        'pediatric_ed' => ['service_line' => 'pediatrics', 'name' => 'Pediatric Emergency Department', 'type' => 'internal', 'body' => 'internal', 'implies' => 'routine', 'population' => 'pediatric'],

        // Behavioral health
        'adult_inpatient_psych' => ['service_line' => 'behavioral_health', 'name' => 'Adult Inpatient Psychiatry', 'type' => 'state_designation', 'body' => 'state', 'implies' => 'definitive', 'population' => 'adult'],
        'ed_crisis' => ['service_line' => 'behavioral_health', 'name' => 'Emergency Psychiatric Crisis', 'type' => 'internal', 'body' => 'internal', 'implies' => 'stabilize', 'population' => 'both'],

    ],
];
