<?php

/**
 * Fixed taxonomy vocabulary (report definitions): capability levels, IDN geography
 * roles, location roles, and evidence classes. These are the FK targets for the
 * hosp_org / hosp_space layers and the FE dropdown sources.
 *
 * Authoring source of truth. Migration 2026_07_04_000110 also seeds these inline
 * (a bootstrap so FKs are valid immediately after migrate); `deployment:seed-registry`
 * re-projects from this config idempotently. Keep the two in sync — values are the
 * fixed report enums and are not expected to change per deployment.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.1, §5)
 * Report: docs/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md
 *   ("Capability Level", "Location Role", "IDN Geography Role", §6 evidence classes)
 */

return [

    // rank ascends with capability
    'capability_levels' => [
        ['code' => 'none',       'name' => 'None',       'rank' => 0],
        ['code' => 'screen',     'name' => 'Screen',     'rank' => 1],
        ['code' => 'stabilize',  'name' => 'Stabilize',  'rank' => 2],
        ['code' => 'routine',    'name' => 'Routine',    'rank' => 3],
        ['code' => 'advanced',   'name' => 'Advanced',   'rank' => 4],
        ['code' => 'definitive', 'name' => 'Definitive', 'rank' => 5],
        ['code' => 'quaternary', 'name' => 'Quaternary', 'rank' => 6],
    ],

    // how a facility participates in an IDN (14 roles)
    'idn_roles' => [
        ['code' => 'flagship_quaternary_hub',           'name' => 'Flagship / Quaternary Hub',        'sort' => 10],
        ['code' => 'academic_tertiary_hub',             'name' => 'Academic / Tertiary Hub',          'sort' => 20],
        ['code' => 'regional_referral_hub',             'name' => 'Regional Referral Hub',            'sort' => 30],
        ['code' => 'community_hospital',                'name' => 'Community Hospital',               'sort' => 40],
        ['code' => 'critical_access_or_rural_hospital', 'name' => 'Critical Access / Rural Hospital', 'sort' => 50],
        ['code' => 'specialty_hospital',                'name' => 'Specialty Hospital',              'sort' => 60],
        ['code' => 'satellite_ed',                      'name' => 'Satellite Emergency Department',   'sort' => 70],
        ['code' => 'ambulatory_campus',                 'name' => 'Ambulatory Campus',               'sort' => 80],
        ['code' => 'ambulatory_surgery_center',         'name' => 'Ambulatory Surgery Center',       'sort' => 90],
        ['code' => 'urgent_care',                       'name' => 'Urgent Care',                     'sort' => 100],
        ['code' => 'home_hospital',                     'name' => 'Home Hospital',                   'sort' => 110],
        ['code' => 'post_acute',                        'name' => 'Post-Acute',                      'sort' => 120],
        ['code' => 'behavioral_health_facility',        'name' => 'Behavioral Health Facility',      'sort' => 130],
        ['code' => 'virtual_command_center',            'name' => 'Virtual Command Center',          'sort' => 140],
    ],

    // how a physical space participates in a service line (16 roles)
    'location_roles' => [
        ['code' => 'arrival',        'name' => 'Arrival',        'sort' => 10],
        ['code' => 'triage',         'name' => 'Triage',         'sort' => 20],
        ['code' => 'diagnostic',     'name' => 'Diagnostic',     'sort' => 30],
        ['code' => 'treatment',      'name' => 'Treatment',      'sort' => 40],
        ['code' => 'procedure',      'name' => 'Procedure',      'sort' => 50],
        ['code' => 'recovery',       'name' => 'Recovery',       'sort' => 60],
        ['code' => 'inpatient',      'name' => 'Inpatient',      'sort' => 70],
        ['code' => 'critical_care',  'name' => 'Critical Care',  'sort' => 80],
        ['code' => 'observation',    'name' => 'Observation',    'sort' => 90],
        ['code' => 'rehabilitation', 'name' => 'Rehabilitation', 'sort' => 100],
        ['code' => 'outpatient',     'name' => 'Outpatient',     'sort' => 110],
        ['code' => 'support',        'name' => 'Support',        'sort' => 120],
        ['code' => 'logistics',      'name' => 'Logistics',      'sort' => 130],
        ['code' => 'command',        'name' => 'Command',        'sort' => 140],
        ['code' => 'surge',          'name' => 'Surge',          'sort' => 150],
        ['code' => 'transfer',       'name' => 'Transfer',       'sort' => 160],
    ],

    // evidence provenance (report §6); state/accreditation are regulated-grade
    'evidence_classes' => [
        ['code' => 'state_designation',           'name' => 'State Designation',           'regulated' => true],
        ['code' => 'accreditation_body',          'name' => 'Accreditation Body',          'regulated' => true],
        ['code' => 'official_health_system_page', 'name' => 'Official Health-System Page',  'regulated' => false],
        ['code' => 'public_location_page',        'name' => 'Public Location Page',        'regulated' => false],
        ['code' => 'client_roster',               'name' => 'Client Roster',               'regulated' => false],
        ['code' => 'EHR_location_master',         'name' => 'EHR Location Master',         'regulated' => false],
        ['code' => 'facility_map',                'name' => 'Facility Map',                'regulated' => false],
        ['code' => 'interview',                   'name' => 'Interview',                   'regulated' => false],
        ['code' => 'assumption',                  'name' => 'Assumption',                  'regulated' => false],
    ],
];
