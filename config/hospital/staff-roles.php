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

        // ── Nursing ───────────────────────────────────────────────────────────
        'nurse_manager' => ['name' => 'Nurse Manager', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 60, 'app_role' => 'ops-leader'],
        'house_supervisor' => ['name' => 'House Supervisor', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 62, 'app_role' => 'ops-leader'],
        'charge_nurse' => ['name' => 'Charge Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 64],
        'staff_nurse' => ['name' => 'Staff Nurse', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 66],
        'clinical_coordinator' => ['name' => 'Clinical Coordinator', 'category' => 'nursing', 'nursing' => true, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 68],

        // ── Allied health ─────────────────────────────────────────────────────
        'respiratory_therapist' => ['name' => 'Respiratory Therapist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 80],
        'pharmacist' => ['name' => 'Pharmacist', 'category' => 'allied_health', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 82],
        'physical_therapist' => ['name' => 'Physical Therapist', 'category' => 'allied_health', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 84],
        'occupational_therapist' => ['name' => 'Occupational Therapist', 'category' => 'allied_health', 'workflow' => 'improvement', 'permissions' => ['flow.view'], 'sort' => 86],
        'dietitian' => ['name' => 'Dietitian', 'category' => 'allied_health', 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 88],

        // ── Ancillary / care management ───────────────────────────────────────
        'case_manager' => ['name' => 'Case Manager', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'rtdc.act'], 'sort' => 100],
        'social_worker' => ['name' => 'Social Worker', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 102],
        'care_coordinator' => ['name' => 'Care Coordinator', 'category' => 'ancillary', 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 104],
        'transport_tech' => ['name' => 'Transport Technician', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view', 'transport.act'], 'sort' => 106],
        'unit_clerk' => ['name' => 'Unit Clerk / HUC', 'category' => 'ancillary', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 108],

        // ── Support / logistics ───────────────────────────────────────────────
        'environmental_services' => ['name' => 'Environmental Services', 'category' => 'support', 'clinical' => false, 'workflow' => 'rtdc', 'permissions' => ['flow.view'], 'sort' => 120],
        'security' => ['name' => 'Security', 'category' => 'support', 'clinical' => false, 'workflow' => 'none', 'permissions' => ['flow.view'], 'sort' => 122],

        // ── Leadership / command ──────────────────────────────────────────────
        'medical_director' => ['name' => 'Medical Director', 'category' => 'leadership', 'provider' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 140, 'app_role' => 'ops-leader'],
        'nursing_director' => ['name' => 'Nursing Director', 'category' => 'leadership', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 142, 'app_role' => 'ops-leader'],
        'bed_manager' => ['name' => 'Bed Manager / Flow Coordinator', 'category' => 'leadership', 'nursing' => true, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act', 'rtdc.act'], 'sort' => 144, 'app_role' => 'ops-leader'],
        'administrator' => ['name' => 'Administrator / Ops Leader', 'category' => 'leadership', 'clinical' => false, 'workflow' => 'command', 'permissions' => ['flow.view', 'command.act'], 'sort' => 146, 'app_role' => 'ops-leader'],

    ],
];
