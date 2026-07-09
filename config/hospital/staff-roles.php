<?php

/**
 * Staff role taxonomy (Phase 7): the operational roles a resolved staff member can
 * hold, projected into hosp_ref.staff_roles by `deployment:seed-staff-roles`
 * (App\Services\Staffing\StaffRoleRegistrar). Roles are service-line-agnostic — the
 * service line + unit live on the staff_assignment; the role describes the person.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§3, §5)
 *
 * Per-role schema (maps 1:1 to hosp_ref.staff_roles columns):
 *   name         -> display_name
 *   category     -> role_category            (physician|apn_pa|nursing|allied_health|ancillary|support|leadership)
 *   provider     -> is_provider              (bills / privileged clinician)
 *   nursing      -> is_nursing
 *   clinical     -> is_clinical              (default true; false for support/logistics)
 *   regulated    -> is_regulated             (trauma/transplant/burn/neuro/perinatal/neonatal attending —
 *                                             NEVER auto-approved; requires credentialing/EHR-master evidence)
 *   workflow     -> default_workflow         (rtdc|ed|periop|emergency|improvement|command|none)
 *   permissions  -> default_app_permissions  (illustrative operational scopes; stored for the wizard/provisioning)
 *   sort         -> sort_order
 *   app_role     -> metadata.app_role        (optional; the ONLY app role StaffProvisioningService may grant,
 *                                             and only additively onto a default 'user' account — never a downgrade)
 */

return [

    'staff_roles' => [

        // ── Physicians ────────────────────────────────────────────────────────
        'hospitalist' => ['name' => 'Hospitalist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 10],
        'intensivist' => ['name' => 'Intensivist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 12],
        'emergency_physician' => ['name' => 'Emergency Physician', 'category' => 'physician', 'provider' => true, 'workflow' => 'ed', 'permissions' => ['flow.view', 'ed.act'], 'sort' => 14],
        'anesthesiologist' => ['name' => 'Anesthesiologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 16],
        'surgeon' => ['name' => 'Surgeon', 'category' => 'physician', 'provider' => true, 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 18],
        'cardiologist' => ['name' => 'Cardiologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 20],
        'physician' => ['name' => 'Attending Physician', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 22],
        'resident' => ['name' => 'Resident / Fellow', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 24],
        'pediatrician' => ['name' => 'Pediatrician', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 25],
        'obstetrician_gynecologist' => ['name' => 'Obstetrician / Gynecologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 26],
        'psychiatrist' => ['name' => 'Psychiatrist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 27],
        'radiologist' => ['name' => 'Radiologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 28],
        'pathologist' => ['name' => 'Pathologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 29],
        'nephrologist' => ['name' => 'Nephrologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 30],
        'pulmonologist' => ['name' => 'Pulmonologist', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 31],
        'infectious_disease_physician' => ['name' => 'Infectious Disease Physician', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 32],
        'palliative_care_physician' => ['name' => 'Palliative Care Physician', 'category' => 'physician', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 33],

        // Regulated attendings — never auto-approved; require credentialing/EHR-master evidence.
        'trauma_surgeon' => ['name' => 'Trauma / Acute Care Surgeon', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 30],
        'transplant_surgeon' => ['name' => 'Transplant Surgeon', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 32],
        'burn_surgeon' => ['name' => 'Burn Surgeon', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 34],
        'neurosurgeon' => ['name' => 'Neurosurgeon', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 36],
        'neonatologist' => ['name' => 'Neonatologist', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 38],
        'maternal_fetal_medicine' => ['name' => 'Maternal-Fetal Medicine', 'category' => 'physician', 'provider' => true, 'regulated' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 40],

        // ── Advanced practice ─────────────────────────────────────────────────
        'nurse_practitioner' => ['name' => 'Nurse Practitioner', 'category' => 'apn_pa', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 50],
        'physician_assistant' => ['name' => 'Physician Assistant', 'category' => 'apn_pa', 'provider' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 52],
        'certified_registered_nurse_anesthetist' => ['name' => 'Certified Registered Nurse Anesthetist', 'category' => 'apn_pa', 'provider' => true, 'nursing' => true, 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 54],
        'certified_nurse_midwife' => ['name' => 'Certified Nurse Midwife', 'category' => 'apn_pa', 'provider' => true, 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 56],

        // ── Nursing ───────────────────────────────────────────────────────────
        'nurse_manager' => ['name' => 'Nurse Manager', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 60, 'app_role' => 'ops-leader'],
        'house_supervisor' => ['name' => 'House Supervisor', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 62, 'app_role' => 'ops-leader'],
        'charge_nurse' => ['name' => 'Charge Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 64],
        'staff_nurse' => ['name' => 'Staff Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 66],
        'clinical_coordinator' => ['name' => 'Clinical Coordinator', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 68],
        'critical_care_nurse' => ['name' => 'Critical Care Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 69],
        'emergency_nurse' => ['name' => 'Emergency Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'ed', 'permissions' => ['flow.view', 'ed.act'], 'sort' => 70],
        'perioperative_nurse' => ['name' => 'Perioperative Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 71],
        'pacu_nurse' => ['name' => 'PACU Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 72],
        'neonatal_nurse' => ['name' => 'Neonatal Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 73],
        'pediatric_nurse' => ['name' => 'Pediatric Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 74],
        'behavioral_health_nurse' => ['name' => 'Behavioral Health Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 75],
        'licensed_practical_nurse' => ['name' => 'Licensed Practical Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 76],
        'float_pool_nurse' => ['name' => 'Float Pool Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view'], 'sort' => 77],
        'rapid_response_nurse' => ['name' => 'Rapid Response Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 78],
        'vascular_access_nurse' => ['name' => 'Vascular Access Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 79],
        'nurse_educator' => ['name' => 'Nurse Educator', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 80],

        // ── Allied health ─────────────────────────────────────────────────────
        'respiratory_therapist' => ['name' => 'Respiratory Therapist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 80],
        'pharmacist' => ['name' => 'Pharmacist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 82],
        'physical_therapist' => ['name' => 'Physical Therapist', 'category' => 'allied_health', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 84],
        'occupational_therapist' => ['name' => 'Occupational Therapist', 'category' => 'allied_health', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 86],
        'dietitian' => ['name' => 'Dietitian', 'category' => 'allied_health', 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 88],
        'speech_language_pathologist' => ['name' => 'Speech-Language Pathologist', 'category' => 'allied_health', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 90],
        'radiologic_technologist' => ['name' => 'Radiologic Technologist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 92],
        'ct_technologist' => ['name' => 'CT Technologist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 94],
        'mri_technologist' => ['name' => 'MRI Technologist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 96],
        'sonographer' => ['name' => 'Diagnostic Medical Sonographer', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 98],
        'medical_laboratory_scientist' => ['name' => 'Medical Laboratory Scientist', 'category' => 'allied_health', 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 99],
        'perfusionist' => ['name' => 'Cardiovascular Perfusionist', 'category' => 'allied_health', 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 100],

        // ── Ancillary / care management ───────────────────────────────────────
        'case_manager' => ['name' => 'Case Manager', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 100],
        'social_worker' => ['name' => 'Social Worker', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 102],
        'care_coordinator' => ['name' => 'Care Coordinator', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 104],
        'transport_tech' => ['name' => 'Transport Technician', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'transport.act'], 'sort' => 106],
        'unit_clerk' => ['name' => 'Unit Clerk / HUC', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 108],
        'patient_care_technician' => ['name' => 'Patient Care Technician', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 109],
        'nursing_assistant' => ['name' => 'Certified Nursing Assistant', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 110],
        'behavioral_health_technician' => ['name' => 'Behavioral Health Technician', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 111],
        'emergency_department_technician' => ['name' => 'Emergency Department Technician', 'category' => 'ancillary', 'workflow' => 'ed', 'permissions' => ['flow.view', 'ed.act'], 'sort' => 112],
        'surgical_technologist' => ['name' => 'Surgical Technologist', 'category' => 'ancillary', 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 113],
        'anesthesia_technician' => ['name' => 'Anesthesia Technician', 'category' => 'ancillary', 'workflow' => 'periop', 'permissions' => ['flow.view', 'periop.act'], 'sort' => 114],
        'sterile_processing_technician' => ['name' => 'Sterile Processing Technician', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'periop', 'permissions' => ['flow.view'], 'sort' => 115],
        'pharmacy_technician' => ['name' => 'Pharmacy Technician', 'category' => 'ancillary', 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 116],
        'phlebotomist' => ['name' => 'Phlebotomist', 'category' => 'ancillary', 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 117],
        'patient_sitter' => ['name' => 'Patient Safety Attendant / Sitter', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 118],
        'telemetry_technician' => ['name' => 'Telemetry Technician', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 119],
        'registration_specialist' => ['name' => 'Patient Registration Specialist', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'ed', 'permissions' => ['flow.view'], 'sort' => 120],
        'financial_counselor' => ['name' => 'Financial Counselor', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 121],
        'chaplain' => ['name' => 'Staff Chaplain', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 122],
        'infection_preventionist' => ['name' => 'Infection Preventionist', 'category' => 'ancillary', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 123],

        // ── Support / logistics ───────────────────────────────────────────────
        'environmental_services' => ['name' => 'Environmental Services', 'category' => 'support', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 120],
        'security' => ['name' => 'Security', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 122],
        'food_services' => ['name' => 'Food and Nutrition Services', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 124],
        'supply_chain_technician' => ['name' => 'Supply Chain Technician', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 126],
        'biomedical_equipment_technician' => ['name' => 'Biomedical Equipment Technician', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 128],
        'facilities_technician' => ['name' => 'Facilities Technician', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 130],
        'medical_interpreter' => ['name' => 'Medical Interpreter', 'category' => 'support', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 132],

        // ── Leadership / command ──────────────────────────────────────────────
        'medical_director' => ['name' => 'Medical Director', 'category' => 'leadership', 'provider' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 140, 'app_role' => 'ops-leader'],
        'nursing_director' => ['name' => 'Nursing Director', 'category' => 'leadership', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 142, 'app_role' => 'ops-leader'],
        'bed_manager' => ['name' => 'Bed Manager / Flow Coordinator', 'category' => 'leadership', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act', 'rtdc.act'], 'sort' => 144, 'app_role' => 'ops-leader'],
        'administrator' => ['name' => 'Administrator / Ops Leader', 'category' => 'leadership', 'clinical' => false, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 146, 'app_role' => 'ops-leader'],

    ],
];
