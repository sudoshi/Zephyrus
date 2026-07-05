<?php

/**
 * Canonical enterprise service-line registry (Layer 2 of the Service Line /
 * Location Deployment Taxonomy). This is the authoring source of truth; it is
 * projected into hosp_ref.service_lines by `deployment:seed-registry`
 * (App\Services\Deployment\ServiceLineRegistrar) — config authors, DB projects.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5, Phase 0)
 * Report: docs/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md ("Summary Matrix")
 *
 * Per-line schema (maps 1:1 to hosp_ref.service_lines columns):
 *   name            -> display_name
 *   domain          -> clinical_domain
 *   population       -> adult_or_pediatric        (adult|pediatric|both)
 *   setting          -> care_setting_default      (inpatient|outpatient|procedural|virtual|support)
 *   workflow         -> default_workflow          (rtdc|ed|periop|transport|command|none)
 *   requires[]       -> requires_* booleans, expanded by the registrar via REQUIRES_MAP below
 *   designations[]   -> certification_or_designation  (ACS|TJC|ACOG|AAP|ABA|OPTN|CMS)
 *   location_roles[] -> default_location_roles    (subset of hosp_ref.location_roles)
 *   aliases[]        -> aliases                   (legacy/synonym codes folded onto this canonical code)
 *   sort             -> sort_order
 *
 * requires[] tokens -> boolean columns (the registrar defaults every unlisted flag to false):
 *   '24_7'                -> requires_24_7
 *   'inpatient_beds'      -> requires_inpatient_beds
 *   'procedure_platform'  -> requires_procedure_platform
 *   'imaging'             -> requires_imaging
 *   'lab'                 -> requires_lab
 *   'pharmacy'            -> requires_pharmacy
 *   'transport'           -> requires_transport
 *   'transfer_agreements' -> requires_transfer_agreements
 *
 * ALIAS CROSSWALK (the only reconciliation existing Summit data needs — see plan §2.3):
 *   trauma_surgery -> trauma_acute_care_surgery, medicine -> hospital_medicine, cardiology -> cardiovascular.
 * `deployment:normalize-service-lines` rekeys the three legacy stores before any FK onto
 * service_lines is validated. Only synthetic Summit data is affected.
 */

return [

    'requires_map' => [
        '24_7'                => 'requires_24_7',
        'inpatient_beds'      => 'requires_inpatient_beds',
        'procedure_platform'  => 'requires_procedure_platform',
        'imaging'             => 'requires_imaging',
        'lab'                 => 'requires_lab',
        'pharmacy'            => 'requires_pharmacy',
        'transport'           => 'requires_transport',
        'transfer_agreements' => 'requires_transfer_agreements',
    ],

    'service_lines' => [

        'emergency' => [
            'name'           => 'Emergency Medicine',
            'domain'         => 'emergency',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'ed',
            'requires'       => ['24_7', 'imaging', 'lab', 'pharmacy', 'transport'],
            'designations'   => ['CMS'],
            'location_roles' => ['arrival', 'triage', 'treatment', 'observation', 'critical_care'],
            'aliases'        => [],
            'sort'           => 10,
        ],

        'trauma_acute_care_surgery' => [
            'name'           => 'Trauma, Acute Care & Surgical Critical Care',
            'domain'         => 'surgery',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'ed',
            'requires'       => ['24_7', 'inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'pharmacy', 'transport', 'transfer_agreements'],
            'designations'   => ['ACS'],
            'location_roles' => ['arrival', 'treatment', 'procedure', 'critical_care', 'inpatient', 'transfer'],
            'aliases'        => ['trauma_surgery'],
            'sort'           => 20,
        ],

        'critical_care' => [
            'name'           => 'Adult Critical Care',
            'domain'         => 'critical_care',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'lab', 'pharmacy'],
            'designations'   => [],
            'location_roles' => ['critical_care', 'inpatient'],
            'aliases'        => [],
            'sort'           => 30,
        ],

        'hospital_medicine' => [
            'name'           => 'Hospital Medicine & Observation',
            'domain'         => 'medicine',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'lab', 'pharmacy'],
            'designations'   => [],
            'location_roles' => ['inpatient', 'observation'],
            'aliases'        => ['medicine'],
            'sort'           => 40,
        ],

        'adult_med_surg' => [
            'name'           => 'Medical / Surgical Nursing',
            'domain'         => 'medicine',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds'],
            'designations'   => [],
            'location_roles' => ['inpatient'],
            'aliases'        => [],
            'sort'           => 50,
        ],

        'cardiovascular' => [
            'name'           => 'Cardiovascular (Cardiology, Cardiac Surgery, Vascular, EP)',
            'domain'         => 'cardiovascular',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'transport', 'transfer_agreements'],
            'designations'   => ['CMS'],
            'location_roles' => ['diagnostic', 'procedure', 'critical_care', 'inpatient'],
            'aliases'        => ['cardiology'],
            'sort'           => 60,
        ],

        'neurosciences' => [
            'name'           => 'Neurosciences & Stroke',
            'domain'         => 'neurosciences',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'transport', 'transfer_agreements'],
            'designations'   => ['TJC'],
            'location_roles' => ['arrival', 'diagnostic', 'procedure', 'critical_care', 'inpatient'],
            'aliases'        => [],
            'sort'           => 70,
        ],

        'perioperative' => [
            'name'           => 'Perioperative & Procedural Platform',
            'domain'         => 'surgery',
            'population'     => 'both',
            'setting'        => 'procedural',
            'workflow'       => 'periop',
            'requires'       => ['inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'pharmacy', 'transport', 'transfer_agreements'],
            'designations'   => [],
            'location_roles' => ['procedure', 'recovery', 'support'],
            'aliases'        => [],
            'sort'           => 80,
        ],

        'orthopedics_spine' => [
            'name'           => 'Orthopedics, Spine & Sports Medicine',
            'domain'         => 'surgery',
            'population'     => 'both',
            'setting'        => 'procedural',
            'workflow'       => 'periop',
            'requires'       => ['inpatient_beds', 'procedure_platform', 'imaging'],
            'designations'   => [],
            'location_roles' => ['procedure', 'inpatient', 'rehabilitation', 'outpatient'],
            'aliases'        => [],
            'sort'           => 90,
        ],

        'oncology' => [
            'name'           => 'Oncology, Hematology & Infusion',
            'domain'         => 'oncology',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'pharmacy', 'transport'],
            'designations'   => ['ACS'],
            'location_roles' => ['treatment', 'procedure', 'inpatient', 'outpatient'],
            'aliases'        => [],
            'sort'           => 100,
        ],

        'womens_health' => [
            'name'           => "Women's Health, OB & Maternal-Fetal Medicine",
            'domain'         => 'womens_infants',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'transfer_agreements'],
            'designations'   => ['ACOG'],
            'location_roles' => ['triage', 'procedure', 'inpatient', 'outpatient'],
            'aliases'        => [],
            'sort'           => 110,
        ],

        'neonatology' => [
            'name'           => 'Neonatology & Newborn Care',
            'domain'         => 'womens_infants',
            'population'     => 'pediatric',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'lab', 'transport', 'transfer_agreements'],
            'designations'   => ['AAP'],
            'location_roles' => ['critical_care', 'inpatient'],
            'aliases'        => [],
            'sort'           => 120,
        ],

        'pediatrics' => [
            'name'           => 'Pediatrics, PICU & Pediatric ED',
            'domain'         => 'pediatrics',
            'population'     => 'pediatric',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds', 'imaging', 'lab', 'transfer_agreements'],
            'designations'   => [],
            'location_roles' => ['triage', 'critical_care', 'inpatient'],
            'aliases'        => [],
            'sort'           => 130,
        ],

        'behavioral_health' => [
            'name'           => 'Behavioral Health, Psychiatry & Crisis',
            'domain'         => 'behavioral_health',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds'],
            'designations'   => [],
            'location_roles' => ['triage', 'treatment', 'inpatient', 'observation'],
            'aliases'        => [],
            'sort'           => 140,
        ],

        'rehabilitation' => [
            'name'           => 'Rehabilitation (Inpatient & Outpatient)',
            'domain'         => 'rehabilitation',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds'],
            'designations'   => ['CMS'],
            'location_roles' => ['rehabilitation', 'inpatient', 'outpatient'],
            'aliases'        => [],
            'sort'           => 150,
        ],

        'imaging_diagnostics' => [
            'name'           => 'Imaging & Diagnostics',
            'domain'         => 'diagnostics',
            'population'     => 'both',
            'setting'        => 'support',
            'workflow'       => 'none',
            'requires'       => ['24_7', 'imaging', 'transport'],
            'designations'   => [],
            'location_roles' => ['diagnostic', 'support'],
            'aliases'        => [],
            'sort'           => 160,
        ],

        'laboratory_pathology' => [
            'name'           => 'Laboratory, Pathology & Blood Bank',
            'domain'         => 'diagnostics',
            'population'     => 'both',
            'setting'        => 'support',
            'workflow'       => 'none',
            'requires'       => ['24_7', 'lab'],
            'designations'   => [],
            'location_roles' => ['diagnostic', 'support'],
            'aliases'        => [],
            'sort'           => 170,
        ],

        'pharmacy_medication' => [
            'name'           => 'Pharmacy & Medication Management',
            'domain'         => 'pharmacy',
            'population'     => 'both',
            'setting'        => 'support',
            'workflow'       => 'none',
            'requires'       => ['24_7', 'pharmacy'],
            'designations'   => [],
            'location_roles' => ['support', 'logistics'],
            'aliases'        => [],
            'sort'           => 180,
        ],

        'renal_dialysis' => [
            'name'           => 'Nephrology, Dialysis & Apheresis',
            'domain'         => 'renal',
            'population'     => 'both',
            'setting'        => 'procedural',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds', 'lab'],
            'designations'   => [],
            'location_roles' => ['treatment', 'procedure', 'outpatient'],
            'aliases'        => [],
            'sort'           => 190,
        ],

        'gastroenterology' => [
            'name'           => 'Gastroenterology, Endoscopy & Hepatology',
            'domain'         => 'digestive',
            'population'     => 'both',
            'setting'        => 'procedural',
            'workflow'       => 'periop',
            'requires'       => ['procedure_platform', 'imaging'],
            'designations'   => [],
            'location_roles' => ['procedure', 'recovery', 'outpatient'],
            'aliases'        => [],
            'sort'           => 200,
        ],

        'pulmonary_respiratory' => [
            'name'           => 'Pulmonary, Respiratory Therapy & Sleep',
            'domain'         => 'respiratory',
            'population'     => 'both',
            'setting'        => 'procedural',
            'workflow'       => 'rtdc',
            'requires'       => ['procedure_platform', 'imaging'],
            'designations'   => [],
            'location_roles' => ['procedure', 'diagnostic', 'outpatient'],
            'aliases'        => [],
            'sort'           => 210,
        ],

        'infectious_disease_infection_prevention' => [
            'name'           => 'Infectious Disease & Infection Prevention',
            'domain'         => 'infectious_disease',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds', 'lab', 'pharmacy'],
            'designations'   => [],
            'location_roles' => ['inpatient', 'support'],
            'aliases'        => [],
            'sort'           => 220,
        ],

        'transplant' => [
            'name'           => 'Solid Organ Transplant',
            'domain'         => 'transplant',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'periop',
            'requires'       => ['inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'pharmacy', 'transfer_agreements'],
            'designations'   => ['OPTN'],
            'location_roles' => ['procedure', 'critical_care', 'inpatient', 'outpatient'],
            'aliases'        => [],
            'sort'           => 230,
        ],

        'burn' => [
            'name'           => 'Burn Care',
            'domain'         => 'burn',
            'population'     => 'both',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['24_7', 'inpatient_beds', 'procedure_platform', 'imaging', 'lab', 'transfer_agreements'],
            'designations'   => ['ABA'],
            'location_roles' => ['critical_care', 'procedure', 'inpatient', 'rehabilitation'],
            'aliases'        => [],
            'sort'           => 240,
        ],

        'geriatrics_palliative' => [
            'name'           => 'Geriatrics & Palliative Care',
            'domain'         => 'geriatrics',
            'population'     => 'adult',
            'setting'        => 'inpatient',
            'workflow'       => 'rtdc',
            'requires'       => ['inpatient_beds', 'pharmacy'],
            'designations'   => [],
            'location_roles' => ['inpatient', 'outpatient', 'support'],
            'aliases'        => [],
            'sort'           => 250,
        ],

        'primary_ambulatory' => [
            'name'           => 'Primary Care, Urgent Care & Clinics',
            'domain'         => 'ambulatory',
            'population'     => 'both',
            'setting'        => 'outpatient',
            'workflow'       => 'none',
            'requires'       => [],
            'designations'   => [],
            'location_roles' => ['outpatient', 'triage'],
            'aliases'        => [],
            'sort'           => 260,
        ],

        'home_post_acute' => [
            'name'           => 'Home Health, Hospital-at-Home & Post-Acute',
            'domain'         => 'post_acute',
            'population'     => 'both',
            'setting'        => 'virtual',
            'workflow'       => 'command',
            'requires'       => [],
            'designations'   => [],
            'location_roles' => ['outpatient', 'command'],
            'aliases'        => [],
            'sort'           => 270,
        ],

        'logistics_support' => [
            'name'           => 'Logistics & Support Operations',
            'domain'         => 'support',
            'population'     => 'both',
            'setting'        => 'support',
            'workflow'       => 'transport',
            'requires'       => ['24_7', 'transport'],
            'designations'   => [],
            'location_roles' => ['logistics', 'support'],
            'aliases'        => [],
            'sort'           => 280,
        ],

        'quality_research_education' => [
            'name'           => 'Quality, Research & Graduate Medical Education',
            'domain'         => 'academic',
            'population'     => 'both',
            'setting'        => 'support',
            'workflow'       => 'none',
            'requires'       => [],
            'designations'   => [],
            'location_roles' => ['command', 'support'],
            'aliases'        => [],
            'sort'           => 290,
        ],

    ],
];
